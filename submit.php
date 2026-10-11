<?php
/**
 * 前台 · 整合包发布 / 编辑页
 * 免审（管理员 / 后台设为免审的用户）直接发布；其余用户（含作者）提交后进入待审核
 */
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';
ensure_session();
if (!is_logged()) { redirect('/login'); }
$u = current_user();
if (!$u) { redirect('/login'); }
$uid = (int)$u['id'];

// 免审：管理员或已被后台设为免审的用户，发布直接上线；其余（含作者）待审核
$autoPass = is_admin($u) || (int)($u['no_review'] ?? 0) === 1;

// 禁言用户不能发布 / 编辑
if (is_muted($u)) {
    $pageTitle = '发布整合包';
    include __DIR__ . '/includes/site_head.php';
    echo '<main class="wrap"><div class="msg-box err" style="margin-top:40px"><i class="fa-solid fa-ban"></i> 你已被禁言，暂时不能发布或编辑整合包。如有疑问请联系管理员。</div></main>';
    echo '</body></html>';
    exit;
}

$id   = (int)($_GET['edit'] ?? 0);
$edit = false;
$mp   = ['title' => '', 'tagline' => '', 'content' => '', 'cover' => '', 'mc_version' => '', 'mod_loader' => '', 'author' => '', 'author_links' => '', 'download_url' => '', 'category_id' => 0, 'slug' => '', 'status' => 0, 'source_type' => 'original', 'source_name' => ''];
$downloads = [];
$selectedTags = [];

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM modpacks WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row && (is_admin($u) || (int)$row['user_id'] === $uid)) {
        $edit = true;
        $mp = $row;
        $ts = db()->prepare('SELECT tag_id FROM modpack_tags WHERE modpack_id = ?');
        $ts->execute([$id]);
        $selectedTags = array_column($ts->fetchAll(), 'tag_id');
        $downloads = get_modpack_downloads($id);
    } else {
        $id = 0;
        $edit = false;
    }
}

$categories = db()->query('SELECT * FROM categories ORDER BY sort ASC, id ASC')->fetchAll();
$allTags    = db()->query('SELECT * FROM tags ORDER BY id ASC')->fetchAll();
$loaders    = ['forge' => 'Forge', 'fabric' => 'Fabric', 'neoforge' => 'NeoForge', 'quilt' => 'Quilt', 'datapack' => '数据包', 'vanilla' => '原版', 'other' => '其他'];
$authorLinks = parse_author_links($mp['author_links'] ?? '');

$errors = [];
$ok = '';
$pending = false;
$mpId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mp['title']        = trim($_POST['title'] ?? '');
    $mp['tagline']      = trim($_POST['tagline'] ?? '');
    $mp['content']      = trim($_POST['content'] ?? '');
    $mp['mc_version']   = trim($_POST['mc_version'] ?? '');
    $mp['mod_loader']   = trim($_POST['mod_loader'] ?? '');
    $mp['author']       = trim($_POST['author'] ?? '');
    $mp['download_url'] = trim($_POST['download_url'] ?? '');
    $mp['source_type']  = ($_POST['source_type'] ?? 'original') === 'repost' ? 'repost' : 'original';
    $mp['source_name']  = trim($_POST['source_name'] ?? '');
    $mp['category_id']  = (int)($_POST['category_id'] ?? 0);
    // 收集多下载链接
    $dlLabels = array_map('trim', (array)($_POST['dl_label'] ?? []));
    $dlUrls   = array_map('trim', (array)($_POST['dl_url'] ?? []));
    $dlItems  = [];
    foreach ($dlUrls as $i => $url) {
        if ($url === '') continue;
        $dlItems[] = ['label' => $dlLabels[$i] ?? '', 'url' => $url];
    }
    $postedTags         = array_map('intval', (array)($_POST['tags'] ?? []));
    $mp['author_links'] = json_encode([
        'github'   => trim($_POST['author_github'] ?? ''),
        'bilibili' => trim($_POST['author_bilibili'] ?? ''),
        'douyin'   => trim($_POST['author_douyin'] ?? ''),
        'website'  => trim($_POST['author_website'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($mp['title'] === '') $errors[] = '请填写整合包标题';
    if (mb_strlen($mp['title']) > 150) $errors[] = '标题过长（最多 150 字）';
    if ($mp['download_url'] !== '' && !preg_match('#^https?://#i', $mp['download_url'])) {
        $errors[] = '下载地址需以 http:// 或 https:// 开头';
    }
    if ($mp['category_id'] > 0) {
        $cs = db()->prepare('SELECT id FROM categories WHERE id = ?');
        $cs->execute([$mp['category_id']]);
        if (!$cs->fetch()) $mp['category_id'] = 0;
    }
    if ($mp['category_id'] == 0) $mp['category_id'] = null;

    if (!$errors) {
        if ($edit) {
            $slug = $mp['slug'];
        } else {
            $base = slugify($mp['title']);
            $slug = $base;
            $n = 1;
            $chk = db()->prepare('SELECT id FROM modpacks WHERE slug = ?');
            while (true) {
                $chk->execute([$slug]);
                if (!$chk->fetch()) break;
                $slug = $base . '-' . (++$n);
            }
        }

        if (!empty($_FILES['cover']['name'])) {
            $cover = upload_cover($_FILES['cover'], defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads');
            if ($cover === null) {
                $errors[] = '封面上传失败：仅支持 jpg/png/webp/gif，且不超过 8MB';
            } else {
                $mp['cover'] = $cover;
            }
        }
        $coverUrl = trim($_POST['cover_url'] ?? '');
        if ($coverUrl !== '' && !$errors) $mp['cover'] = $coverUrl;
        if (isset($_POST['remove_cover']) && !$errors) $mp['cover'] = '';

        if (!$errors) {
            if ($edit) {
                // 免审（管理员 / 免审用户）：直接发布；其余编辑待审内容：保持原状态
                $newStatus = $autoPass ? 1 : (int)($mp['status'] ?? 0);
                $up = db()->prepare(
                    'UPDATE modpacks SET title=?, tagline=?, content=?, cover=?, mc_version=?, mod_loader=?, author=?, author_links=?, download_url=?, source_type=?, source_name=?, category_id=?, status=? WHERE id=?'
                );
                $up->execute([
                    $mp['title'], $mp['tagline'], $mp['content'], $mp['cover'], $mp['mc_version'],
                    $mp['mod_loader'], $mp['author'], $mp['author_links'], $mp['download_url'],
                    $mp['source_type'], $mp['source_name'], $mp['category_id'], $newStatus, $id,
                ]);
                $mpId = $id;
                $mp['status'] = $newStatus;
                $ok = $autoPass ? '保存成功' : '已保存（等待管理员审核）';
                // 通知收藏者：整合包已更新
                $fl = db()->prepare('SELECT DISTINCT user_id FROM favorites WHERE modpack_id = ?');
                $fl->execute([$mpId]);
                foreach ($fl->fetchAll() as $f) {
                    if ((int)$f['user_id'] !== $uid) {
                        notify((int)$f['user_id'], $uid, 'update', '你收藏的整合包已更新', $mp['title'] . ' 已更新，快去看看新内容', '/modpack/' . $mp['slug']);
                    }
                }
            } else {
                $newStatus = $autoPass ? 1 : 0;
                $ins = db()->prepare(
                    'INSERT INTO modpacks (slug, title, tagline, content, cover, mc_version, mod_loader, author, author_links, download_url, source_type, source_name, category_id, user_id, status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $ins->execute([
                    $slug, $mp['title'], $mp['tagline'], $mp['content'], $mp['cover'], $mp['mc_version'],
                    $mp['mod_loader'], $mp['author'], $mp['author_links'], $mp['download_url'],
                    $mp['source_type'], $mp['source_name'], $mp['category_id'], $uid, $newStatus,
                ]);
                $mpId = (int)db()->lastInsertId();
                $mp['slug'] = $slug;
                $pending = $newStatus === 0;
                $ok = $pending ? '已提交，等待管理员审核通过后即可展示' : '发布成功';
            }

            // 重写下载链接
            db()->prepare('DELETE FROM modpack_downloads WHERE modpack_id = ?')->execute([$mpId]);
            $insDl = db()->prepare('INSERT INTO modpack_downloads (modpack_id, label, url, sort) VALUES (?,?,?,?)');
            foreach ($dlItems as $i => $item) {
                $insDl->execute([$mpId, $item['label'], $item['url'], $i]);
            }

            // 重写标签关联
            db()->prepare('DELETE FROM modpack_tags WHERE modpack_id = ?')->execute([$mpId]);
            $rel = db()->prepare('INSERT IGNORE INTO modpack_tags (modpack_id, tag_id) VALUES (?,?)');
            foreach ($postedTags as $tid) {
                $ck = db()->prepare('SELECT id FROM tags WHERE id = ?');
                $ck->execute([$tid]);
                if ($ck->fetch()) $rel->execute([$mpId, $tid]);
            }

            $id = $mpId;
            $edit = true;
            $st = db()->prepare('SELECT * FROM modpacks WHERE id = ?');
            $st->execute([$id]);
            $mp = $st->fetch();
            $ts = db()->prepare('SELECT tag_id FROM modpack_tags WHERE modpack_id = ?');
            $ts->execute([$id]);
            $selectedTags = array_column($ts->fetchAll(), 'tag_id');
        }
    }
}

$pageTitle = $edit ? '编辑整合包' : '发布整合包';
include __DIR__ . '/includes/site_head.php';
?>
<main class="wrap">
  <div class="publish-page">
    <div class="publish-topbar">
      <h1><?= e($pageTitle) ?></h1>
      <a class="btn ghost" href="/my-modpacks"><i class="fa-solid fa-list"></i> 我的发布</a>
    </div>

    <?php if ($ok): ?><div class="msg-box ok"><?= e($ok) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="msg-box err"><?= e(implode('；', $errors)) ?></div><?php endif; ?>
    <?php if (!$edit && !$autoPass): ?>
      <div class="msg-box" style="margin-bottom:16px"><i class="fa-solid fa-clock"></i> 你的发布将进入审核，管理员通过后才会展示。如需免审请联系管理员。</div>
    <?php endif; ?>

    <form method="post" action="/submit<?= $edit ? '?edit=' . (int)$id : '' ?>" enctype="multipart/form-data" class="form">
      <div class="form-grid">
        <div class="form-main">
          <div class="field">
            <label>整合包标题 <span class="req">*</span></label>
            <input type="text" name="title" value="<?= e($mp['title']) ?>" required maxlength="150">
          </div>
          <div class="field">
            <label>一句话简介（显示在卡片上）</label>
            <input type="text" name="tagline" value="<?= e($mp['tagline']) ?>" maxlength="255">
          </div>
          <div class="field">
            <label>详细介绍</label>
            <textarea name="content" rows="10" placeholder="整合包玩法、特色、注意事项…支持换行"><?= e($mp['content']) ?></textarea>
          </div>
          <div class="field">
            <label>内容类型</label>
            <div style="display:flex;gap:18px;align-items:center">
              <label class="inline-check"><input type="radio" name="source_type" value="original" <?= ($mp['source_type'] ?? 'original') !== 'repost' ? 'checked' : '' ?>> 原创</label>
              <label class="inline-check"><input type="radio" name="source_type" value="repost" <?= ($mp['source_type'] ?? '') === 'repost' ? 'checked' : '' ?>> 转载</label>
            </div>
            <div class="sub-field" style="margin-top:8px"><span class="sub-label">转载来源</span>
              <input type="text" name="source_name" value="<?= e($mp['source_name'] ?? '') ?>" placeholder="如：CurseForge / Modrinth / 原作者主页"></div>
          </div>
          <div class="field">
            <label>下载链接（可添加多个）</label>
            <div id="dlRows">
              <?php foreach ($downloads as $i => $d): ?>
                <div class="dl-row" style="display:flex;gap:8px;margin-bottom:8px">
                  <input type="text" name="dl_label[]" value="<?= e($d['label']) ?>" placeholder="说明（如：百度网盘 / 直链）" style="width:38%">
                  <input type="text" name="dl_url[]" value="<?= e($d['url']) ?>" placeholder="https://…" style="flex:1">
                  <button type="button" class="btn ghost" onclick="this.parentNode.remove()">删除</button>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn ghost" onclick="addDlRow()"><i class="fa-solid fa-plus"></i> 添加下载链接</button>
          </div>
        </div>

        <div class="form-side">
          <div class="field">
            <label>封面图</label>
            <?php if ($mp['cover']): ?>
              <div class="cover-preview"><img src="<?= e(cover_url($mp['cover'])) ?>" alt="当前封面"></div>
              <label class="inline-check"><input type="checkbox" name="remove_cover" value="1"> 移除当前封面</label>
            <?php else: ?>
              <p class="muted">暂无封面</p>
            <?php endif; ?>
            <label class="file-btn"><i class="fa-solid fa-folder-open"></i> 选择封面图片<input type="file" name="cover" accept="image/jpeg,image/png,image/webp,image/gif" onchange="this.parentNode.nextElementSibling.textContent=this.files[0]?this.files[0].name:''"></label><span class="file-name"></span>
            <p class="hint">支持 jpg/png/webp/gif，≤ 8MB。</p>
          </div>
          <div class="field">
            <label>分类</label>
            <select name="category_id">
              <option value="0">未分类</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$mp['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>MC 版本</label>
            <input type="text" name="mc_version" value="<?= e($mp['mc_version']) ?>" placeholder="如 1.20.1">
          </div>
          <div class="field">
            <label>模组加载器</label>
            <select name="mod_loader">
              <option value="">未选择</option>
              <?php foreach ($loaders as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= strtolower($mp['mod_loader'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>作者</label>
            <input type="text" name="author" value="<?= e($mp['author']) ?>" placeholder="你的署名">
          </div>
          <div class="field">
            <label>作者社交链接（可留空）</label>
            <div class="sub-field"><span class="sub-label">GitHub</span>
              <input type="text" name="author_github" value="<?= e($authorLinks['github']) ?>" placeholder="https://github.com/xxx"></div>
            <div class="sub-field"><span class="sub-label">Bilibili</span>
              <input type="text" name="author_bilibili" value="<?= e($authorLinks['bilibili']) ?>" placeholder="https://space.bilibili.com/xxx"></div>
            <div class="sub-field"><span class="sub-label">抖音</span>
              <input type="text" name="author_douyin" value="<?= e($authorLinks['douyin']) ?>" placeholder="抖音主页链接"></div>
            <div class="sub-field"><span class="sub-label">个人网站</span>
              <input type="text" name="author_website" value="<?= e($authorLinks['website']) ?>" placeholder="https://xxx.com"></div>
          </div>
          <div class="field">
            <label>标签</label>
            <div class="tag-picker">
              <?php foreach ($allTags as $t): ?>
                <label class="tag-opt"><input type="checkbox" name="tags[]" value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $selectedTags, true) ? 'checked' : '' ?>> <?= e($t['name']) ?></label>
              <?php endforeach; ?>
              <?php if (!$allTags): ?><p class="muted">暂无可用标签，可先由管理员在后台添加。</p><?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="form-actions">
        <button class="btn" type="submit"><?= $edit ? '保存修改' : '发布整合包' ?></button>
        <a class="btn ghost" href="/my-modpacks">取消</a>
      </div>
    </form>
  </div>
</main>
<script>
function addDlRow() {
  var box = document.getElementById('dlRows');
  var row = document.createElement('div');
  row.className = 'dl-row';
  row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px';
  row.innerHTML = '<input type="text" name="dl_label[]" placeholder="说明（如：百度网盘 / 直链）" style="width:38%">' +
    '<input type="text" name="dl_url[]" placeholder="https://…" style="flex:1">' +
    '<button type="button" class="btn ghost" onclick="this.parentNode.remove()">删除</button>';
  box.appendChild(row);
}
</script>
<script src="/assets/js/app.js"></script>
</body>
</html>
