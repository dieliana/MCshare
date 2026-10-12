<?php
/**
 * 后台 · 新增 / 编辑整合包
 */
require __DIR__ . '/_init.php';

$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$edit = false;
$mp   = [
    'title' => '', 'slug' => '', 'tagline' => '', 'content' => '', 'cover' => '',
    'mc_version' => '', 'mod_loader' => '', 'author' => '', 'author_links' => '',
    'download_url' => '', 'source_type' => 'original', 'source_name' => '', 'category_id' => 0, 'status' => 1,
];
$downloads = [];
$selectedTags = [];

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM modpacks WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) {
        $edit = true;
        $mp = $row;
        $ts = db()->prepare('SELECT tag_id FROM modpack_tags WHERE modpack_id = ?');
        $ts->execute([$id]);
        $selectedTags = array_column($ts->fetchAll(), 'tag_id');
        $downloads = get_modpack_downloads($id);
    } else {
        $id = 0;
    }
}

$categories = db()->query('SELECT * FROM categories ORDER BY sort ASC, id ASC')->fetchAll();
$allTags    = db()->query('SELECT * FROM tags ORDER BY id ASC')->fetchAll();

$loaders = ['forge' => 'Forge', 'fabric' => 'Fabric', 'neoforge' => 'NeoForge', 'quilt' => 'Quilt', 'datapack' => '数据包', 'vanilla' => '原版', 'other' => '其他'];
$authorLinks = parse_author_links($mp['author_links'] ?? '');

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $errors[] = '会话已过期，请刷新页面重试';
    } else {
        $mp['title']         = trim($_POST['title'] ?? '');
        $mp['tagline']       = trim($_POST['tagline'] ?? '');
        $mp['content']       = rich_html(trim($_POST['content'] ?? ''));
        $mp['mc_version']    = trim($_POST['mc_version'] ?? '');
        $mp['mod_loader']    = trim($_POST['mod_loader'] ?? '');
        $mp['author']        = trim($_POST['author'] ?? '');
        $mp['download_url']  = trim($_POST['download_url'] ?? '');
        $mp['source_type']   = ($_POST['source_type'] ?? 'original') === 'repost' ? 'repost' : 'original';
        $mp['source_name']   = trim($_POST['source_name'] ?? '');
        $mp['category_id']   = (int)($_POST['category_id'] ?? 0);
        // 收集多下载项（外链 + 上传文件）
        $dlLabels = array_map('trim', (array)($_POST['dl_label'] ?? []));
        $dlUrls   = array_map('trim', (array)($_POST['dl_url'] ?? []));
        $dlFiles  = array_values((array)($_FILES['dl_file'] ?? []));
        $dlItems  = [];
        foreach ($dlUrls as $i => $url) {
            $label = $dlLabels[$i] ?? '';
            if ($url === '' && empty($dlFiles[$i]['name'])) continue;
            $dlItems[] = ['label' => $label, 'url' => $url, 'file' => $dlFiles[$i] ?? null];
        }
        $mp['status']        = isset($_POST['status']) ? 1 : 0;
        $mp['id']            = $id;
        $postedTags          = array_map('intval', (array)($_POST['tags'] ?? []));
        $mp['author_links']  = json_encode([
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
        // 未分类 → 存 NULL（外键要求引用值存在或为 NULL，不能用 0）
        if ($mp['category_id'] == 0) {
            $mp['category_id'] = null;
        }

        if (!$errors) {
            // slug：编辑时保留，新建时生成并保证唯一
            if ($edit) {
                $slug = $mp['slug'];
            } else {
                $base = slugify($mp['title']);
                $slug = $base;
                $u = 1;
                $chk = db()->prepare('SELECT id FROM modpacks WHERE slug = ?');
                while (true) {
                    $chk->execute([$slug]);
                    if (!$chk->fetch()) break;
                    $slug = $base . '-' . (++$u);
                }
            }

            // 处理封面上传
            if (!empty($_FILES['cover']['name'])) {
                $cover = upload_cover($_FILES['cover'], UPLOAD_DIR);
                if ($cover === null) {
                    $errors[] = '封面上传失败：仅支持 jpg/png/webp/gif，且不超过 8MB';
                } else {
                    $mp['cover'] = $cover;
                }
            }
            // 图库选择的封面 URL（优先于上传）
            $coverUrl = trim($_POST['cover_url'] ?? '');
            if ($coverUrl !== '' && !$errors) {
                $mp['cover'] = $coverUrl;
            }
            if (isset($_POST['remove_cover']) && !$errors) {
                $mp['cover'] = '';
            }

            if (!$errors) {
                if ($edit) {
                    $up = db()->prepare(
                        'UPDATE modpacks SET title=?, tagline=?, content=?, cover=?, mc_version=?, mod_loader=?, author=?, author_links=?, download_url=?, source_type=?, source_name=?, category_id=?, status=? WHERE id=?'
                    );
                    $up->execute([
                        $mp['title'], $mp['tagline'], $mp['content'], $mp['cover'], $mp['mc_version'],
                        $mp['mod_loader'], $mp['author'], $mp['author_links'], $mp['download_url'],
                        $mp['source_type'], $mp['source_name'], $mp['category_id'], $mp['status'], $id,
                    ]);
                    $mpId = $id;
                } else {
                    $ins = db()->prepare(
                        'INSERT INTO modpacks (slug, title, tagline, content, cover, mc_version, mod_loader, author, author_links, download_url, source_type, source_name, category_id, status)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    );
                    $ins->execute([
                        $slug, $mp['title'], $mp['tagline'], $mp['content'], $mp['cover'], $mp['mc_version'],
                        $mp['mod_loader'], $mp['author'], $mp['author_links'], $mp['download_url'],
                        $mp['source_type'], $mp['source_name'], $mp['category_id'], $mp['status'],
                    ]);
                    $mpId = (int)db()->lastInsertId();
                }

                // 重写下载项（外链 / 上传文件到服务器）
                db()->prepare('DELETE FROM modpack_downloads WHERE modpack_id = ?')->execute([$mpId]);
                $insDl = db()->prepare('INSERT INTO modpack_downloads (modpack_id, label, url, file_path, sort) VALUES (?,?,?,?,?)');
                foreach ($dlItems as $i => $item) {
                    $fp = '';
                    if (!empty($item['file']['name'])) {
                        $fp = upload_modpack_file($item['file']);
                        if ($fp === null) $fp = '';
                    }
                    $insDl->execute([$mpId, $item['label'], $item['url'], $fp, $i]);
                }

                // 重写标签关联
                db()->prepare('DELETE FROM modpack_tags WHERE modpack_id = ?')->execute([$mpId]);
                $rel = db()->prepare('INSERT IGNORE INTO modpack_tags (modpack_id, tag_id) VALUES (?,?)');
                foreach ($postedTags as $tid) {
                    $ck = db()->prepare('SELECT id FROM tags WHERE id = ?');
                    $ck->execute([$tid]);
                    if ($ck->fetch()) $rel->execute([$mpId, $tid]);
                }

                $success = true;
                $id = $mpId;
                // 重新加载以展示保存后的数据
                $st = db()->prepare('SELECT * FROM modpacks WHERE id = ?');
                $st->execute([$id]);
                $mp = $st->fetch();
                $ts = db()->prepare('SELECT tag_id FROM modpack_tags WHERE modpack_id = ?');
                $ts->execute([$id]);
                $selectedTags = array_column($ts->fetchAll(), 'tag_id');
            }
        }
    }
}

$pageTitle = $edit ? '编辑整合包' : '新增整合包';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= e($adminTheme ?? 'emerald') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · 管理后台</title>
<link rel="stylesheet" href="assets/admin.css">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/editor.css?v=1.3.21">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<main class="content">
  <div class="page-head">
    <h1><?= e($pageTitle) ?></h1>
    <a class="btn ghost" href="modpacks.php">← 返回列表</a>
  </div>

  <?php if ($success): ?><div class="alert ok">已保存成功！<a class="link" href="../detail.php?slug=<?= e($mp['slug']) ?>" target="_blank">查看前台效果</a></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert err"><?= e(implode('；', $errors)) ?></div>
  <?php endif; ?>

  <form method="post" action="modpack-form.php" enctype="multipart/form-data" class="form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">

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
          <div class="rte" data-input="desc-editor">
            <div class="rte-toolbar">
              <button type="button" data-cmd="bold" title="加粗"><i class="fa-solid fa-bold"></i></button>
              <button type="button" data-cmd="italic" title="斜体"><i class="fa-solid fa-italic"></i></button>
              <button type="button" data-cmd="underline" title="下划线"><i class="fa-solid fa-underline"></i></button>
              <button type="button" data-cmd="formatBlock" data-val="h3" title="小标题">H</button>
              <button type="button" data-cmd="insertUnorderedList" title="无序列表"><i class="fa-solid fa-list-ul"></i></button>
              <button type="button" data-cmd="insertOrderedList" title="有序列表"><i class="fa-solid fa-list-ol"></i></button>
              <button type="button" data-cmd="formatBlock" data-val="blockquote" title="引用"><i class="fa-solid fa-quote-left"></i></button>
              <button type="button" data-cmd="createLink" title="插入链接"><i class="fa-solid fa-link"></i></button>
              <button type="button" data-cmd="insertImage" title="插入图片"><i class="fa-solid fa-image"></i></button>
              <button type="button" data-cmd="removeFormat" title="清除格式"><i class="fa-solid fa-eraser"></i></button>
            </div>
            <div class="rte-area" contenteditable="true"></div>
          </div>
          <textarea id="desc-editor" name="content" style="display:none"><?= e($mp['content']) ?></textarea>
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
          <label>下载（可添加多个：外链或上传到服务器）</label>
          <div id="dlRows">
            <?php foreach ($downloads as $i => $d): ?>
              <div class="dl-row" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;align-items:center">
                <input type="text" name="dl_label[]" value="<?= e($d['label']) ?>" placeholder="说明（如：百度网盘 / 直链）" style="width:36%">
                <input type="text" name="dl_url[]" value="<?= e($d['url']) ?>" placeholder="外链 https://…（可空）" style="flex:1">
                <label class="file-btn" style="margin:0"><i class="fa-solid fa-upload"></i> 上传文件<input type="file" name="dl_file[]" onchange="this.parentNode.nextElementSibling.textContent=this.files[0]?this.files[0].name:''"></label><span class="file-name" style="max-width:120px"></span>
                <?php if ($d['file_path']): ?><span class="muted">已存服务器：<?= e($d['file_path']) ?></span><?php endif; ?>
                <button type="button" class="btn ghost" onclick="this.parentNode.remove()">删除</button>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn ghost" onclick="addDlRow()"><i class="fa-solid fa-plus"></i> 添加下载</button>
        </div>
      </div>

      <div class="form-side">
        <div class="field">
          <label>封面图</label>
          <?php if ($mp['cover']): ?>
            <div class="cover-preview"><img src="<?= e(thumb_url($mp['cover'])) ?>" alt="当前封面"></div>
            <label class="inline-check"><input type="checkbox" name="remove_cover" value="1"> 移除当前封面</label>
          <?php else: ?>
            <p class="muted">暂无封面</p>
          <?php endif; ?>
          <label class="file-btn"><i class="fa-solid fa-folder-open"></i> 选择封面图片<input type="file" name="cover" accept="image/jpeg,image/png,image/webp,image/gif" onchange="this.parentNode.nextElementSibling.textContent=this.files[0]?this.files[0].name:'"></label><span class="file-name"></span>
          <p class="hint">支持 jpg/png/webp/gif，≤ 8MB；也可从图库选用图片作为封面：</p>
          <div style="display:flex;gap:8px;margin-top:8px">
            <input type="text" name="cover_url" id="cover_url" value="<?= e(preg_match('#^https?://#i', $mp['cover']) ? $mp['cover'] : '') ?>" placeholder="图片链接（外链 URL）或图库路径（如 gallery/xxx.png）">
            <button type="button" class="btn ghost" onclick="openGallery('cover_url')">从图库选择</button>
          </div>
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
          <input type="text" name="author" value="<?= e($mp['author']) ?>">
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
            <?php if (!$allTags): ?><p class="muted">还没有标签，可先在「标签管理」中添加。</p><?php endif; ?>
          </div>
        </div>
        <div class="field">
          <label class="inline-check"><input type="checkbox" name="status" value="1" <?= $mp['status'] ? 'checked' : '' ?>> 发布（取消勾选则保存为草稿）</label>
        </div>
      </div>
    </div>

    <div class="form-actions">
      <button class="btn" type="submit"><?= $edit ? '保存修改' : '创建整合包' ?></button>
      <a class="btn ghost" href="modpacks.php">取消</a>
    </div>
  </form>
</main>
<script>
function addDlRow() {
  var box = document.getElementById('dlRows');
  var row = document.createElement('div');
  row.className = 'dl-row';
  row.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;align-items:center';
  row.innerHTML = '<input type="text" name="dl_label[]" placeholder="说明（如：百度网盘 / 直链）" style="width:36%">' +
    '<input type="text" name="dl_url[]" placeholder="外链 https://…（可空）" style="flex:1">' +
    '<label class="file-btn" style="margin:0"><i class="fa-solid fa-upload"></i> 上传文件<input type="file" name="dl_file[]" onchange="this.parentNode.nextElementSibling.textContent=this.files[0]?this.files[0].name:\'\'"></label><span class="file-name" style="max-width:120px"></span>' +
    '<button type="button" class="btn ghost" onclick="this.parentNode.remove()">删除</button>';
  box.appendChild(row);
}
// 打开图库弹窗选择图片，选中后回填到指定输入框
function openGallery(fieldId) {
  var w = window.open('gallery.php?pick=1&field=' + fieldId, 'galleryPicker', 'width=860,height=640');
  if (w) w.focus();
}
</script>
<script src="../assets/js/editor.js?v=1.3.19"></script>
</body>
</html>
