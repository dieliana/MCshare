<?php
/**
 * 前台 · 用户中心
 * 修改昵称 / 上传头像 / 修改密码 / 我的收藏·评论·点赞
 */
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';
ensure_session();

user_login_required();
$u = current_user();
if (!$u) redirect('/login');

$msg = '';

// 后台权限不足跳转提示
if (isset($_GET['denied'])) {
    $msg = ['err', '权限不足：当前账号不是最高级管理员，无法访问后台管理页面。'];
}

// 禁言状态下可申诉
$pendingAppeal = false;
if (is_muted($u)) {
    $pa = db()->prepare('SELECT 1 FROM appeals WHERE user_id = ? AND status = 0');
    $pa->execute([(int)$u['id']]);
    $pendingAppeal = (bool)$pa->fetch();
}

// 修改昵称
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'nickname') {
    $nick = trim($_POST['nickname'] ?? '');
    if (mb_strlen($nick) > 20) {
        $msg = ['err', '昵称最长 20 个字符'];
    } else {
        db()->prepare('UPDATE users SET nickname = ? WHERE id = ?')->execute([$nick, $u['id']]);
        $msg = ['ok', '昵称已更新'];
    }
}

// 上传头像
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'avatar') {
    if (!empty($_FILES['avatar']['name'])) {
        $p = upload_icon($_FILES['avatar'], 'avatars');
        if ($p) {
            db()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$p, $u['id']]);
            $msg = ['ok', '头像已更新'];
        } else {
            $msg = ['err', '头像上传失败：仅支持 jpg/png/webp/gif/svg，且不超过 8MB'];
        }
    } else {
        $msg = ['err', '请选择要上传的图片'];
    }
}

// 修改密码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    $old = (string)($_POST['old_pass'] ?? '');
    $new = (string)($_POST['new_pass'] ?? '');
    $cf  = (string)($_POST['cf_pass'] ?? '');
    if (!password_verify($old, $u['password'])) {
        $msg = ['err', '原密码不正确'];
    } elseif (strlen($new) < 6 || strlen($new) > 64) {
        $msg = ['err', '新密码长度需 6-64 位'];
    } elseif ($new !== $cf) {
        $msg = ['err', '两次输入的新密码不一致'];
    } else {
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        $msg = ['ok', '密码已修改'];
    }
}

// 申请成为作者
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_author') {
    if (is_author($u)) {
        $msg = ['err', '你已是作者，无需重复申请'];
    } else {
        $reason = trim($_POST['author_reason'] ?? '');
        if (mb_strlen($reason) < 5) {
            $msg = ['err', '请简单描述你的申请理由（至少 5 个字）'];
        } else {
            db()->prepare('UPDATE users SET author_status = 1, author_reason = ? WHERE id = ?')->execute([$reason, $u['id']]);
            $msg = ['ok', '申请已提交，请等待管理员审核'];
        }
    }
}

// 申诉处理（仅前台管理员，独立于后台）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'appeal_handle') {
    if (!is_admin($u)) {
        $msg = ['err', '无权限操作'];
    } else {
        $aid = (int)($_POST['aid'] ?? 0);
        $do  = $_POST['do'] ?? '';
        $reply = trim($_POST['reply'] ?? '');
        $a = db()->prepare('SELECT user_id FROM appeals WHERE id = ?');
        $a->execute([$aid]);
        $app = $a->fetch();
        if (!$app) {
            $msg = ['err', '申诉不存在'];
        } elseif ($do === 'approve') {
            db()->prepare('UPDATE users SET muted = 0, muted_until = NULL, mute_reason = "" WHERE id = ?')->execute([(int)$app['user_id']]);
            db()->prepare('UPDATE appeals SET status = 1, handled_at = NOW() WHERE id = ?')->execute([$aid]);
            notify((int)$app['user_id'], (int)$u['id'], 'appeal', '申诉通过，禁言已解除', '管理员已处理你的申诉，禁言已解除，可以正常评论和发布了', '/profile');
            $msg = ['ok', '已通过，该用户禁言已解除'];
        } elseif ($do === 'reject') {
            db()->prepare('UPDATE appeals SET status = 2, reply = ?, handled_at = NOW() WHERE id = ?')->execute([$reply, $aid]);
            notify((int)$app['user_id'], (int)$u['id'], 'appeal', '申诉被驳回', $reply !== '' ? '管理员驳回了你的申诉：' . $reply : '管理员驳回了你的申诉', '/profile');
            $msg = ['ok', '已驳回该申诉'];
        }
    }
}

// 数据
$pdo = db();
$favorites = $pdo->prepare('SELECT m.id, m.slug, m.title, m.cover, m.mc_version, m.views, m.downloads, f.created_at AS fav_at
                            FROM favorites f JOIN modpacks m ON m.id = f.modpack_id
                            WHERE f.user_id = ? ORDER BY f.created_at DESC LIMIT 30');
$favorites->execute([$u['id']]);
$favRows = $favorites->fetchAll();

$comments = $pdo->prepare('SELECT c.*, m.title AS mp_title, m.slug AS mp_slug
                           FROM comments c JOIN modpacks m ON m.id = c.modpack_id
                           WHERE c.user_id = ? ORDER BY c.created_at DESC LIMIT 30');
$comments->execute([$u['id']]);
$comRows = $comments->fetchAll();

$likeCnt = (int)$pdo->prepare('SELECT COUNT(*) FROM modpack_likes WHERE user_id = ?')->execute([$u['id']]);
$st = $pdo->prepare('SELECT COUNT(*) FROM modpack_likes WHERE user_id = ?');
$st->execute([$u['id']]);
$likeCnt = (int)$st->fetchColumn();

// 前台管理员可见的申诉列表（独立于后台）
$appealsList = [];
if (is_admin($u)) {
    $al = db()->prepare('SELECT a.*, u.username AS uname, u.nickname FROM appeals a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 100');
    $al->execute();
    $appealsList = $al->fetchAll();
}

$pageTitle = '用户中心';
include __DIR__ . '/includes/site_head.php';
?>
<main class="wrap">
  <div class="profile-wrap">

    <div class="profile-topbar">
      <a class="btn" href="/"><i class="fa-solid fa-house"></i> 返回主页</a>
      <span class="topbar-right">
        <a class="btn ghost" href="/submit"><i class="fa-solid fa-upload"></i> 发布整合包</a>
        <a class="btn ghost" href="/my-modpacks"><i class="fa-solid fa-list"></i> 我的发布</a>
        <a class="btn ghost" href="/messages"><i class="fa-solid fa-bell"></i> 站内信</a>
      </span>
    </div>

    <div class="profile-card">
      <div class="profile-head">
        <?php if ($u['avatar']): ?>
          <img class="profile-avatar" src="<?= e(cover_url($u['avatar'])) ?>" alt="头像">
        <?php else: ?>
          <span class="profile-avatar" style="display:inline-flex;align-items:center;justify-content:center;background:var(--bg2);font-size:34px"><i class="fa-solid fa-user"></i></span>
        <?php endif; ?>
        <div>
          <div class="profile-name"><?= e($u['nickname'] ?: $u['username']) ?></div>
          <div class="profile-sub">@<?= e($u['username']) ?> · <?= e($u['email']) ?> · 注册于 <?= e(fmt_date($u['created_at'])) ?>
            <?php if (is_author($u)): ?><span class="role-badge"><?= is_admin($u) ? '管理员' : '作者' ?></span><?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?php if ($msg): ?><div class="msg-box <?= $msg[0] ?>"><?= e($msg[1]) ?></div><?php endif; ?>

    <?php if (is_muted($u)): ?>
      <div class="msg-box" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <span><i class="fa-solid fa-ban"></i> 你已被禁言<?= trim($u['mute_reason'] ?? '') ? '（原因：' . e($u['mute_reason']) . '）' : '' ?><?= !empty($u['muted_until']) ? '，至 ' . e(fmt_date($u['muted_until'])) : '（永久禁言）' ?>，期间不能评论和发布。</span>
        <?php if ($pendingAppeal): ?><span class="muted">已提交申诉，请等待管理员处理。</span>
        <?php else: ?><button type="button" class="btn ghost" onclick="openAppeal()"><i class="fa-solid fa-gavel"></i> 申诉</button><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="pane-tabs" id="paneTabs">
      <button type="button" class="pane-tab on" data-pane="pane1">资料设置</button>
      <button type="button" class="pane-tab" data-pane="pane2">修改密码</button>
      <button type="button" class="pane-tab" data-pane="pane3">我的收藏</button>
      <button type="button" class="pane-tab" data-pane="pane4">我的评论</button>
      <button type="button" class="pane-tab" data-pane="pane5">我的互动</button>
      <?php if (is_admin($u)): ?><button type="button" class="pane-tab" data-pane="pane6">申诉审核</button><?php endif; ?>
    </div>

    <div class="pane-body">
      <div class="pane on" id="pane1">
        <div class="profile-card">
          <div class="section-title">资料设置</div>
          <form method="post" action="/profile" class="field">
            <input type="hidden" name="action" value="nickname">
            <label>昵称</label>
            <input type="text" name="nickname" value="<?= e($u['nickname']) ?>" maxlength="20">
            <button class="btn" type="submit" style="margin-top:12px">保存昵称</button>
          </form>
          <form method="post" action="/profile" enctype="multipart/form-data" class="field" style="margin-top:20px">
            <input type="hidden" name="action" value="avatar">
            <label>上传头像</label>
            <label class="file-btn"><i class="fa-solid fa-folder-open"></i> 选择图片<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml" onchange="this.parentNode.nextElementSibling.textContent=this.files[0]?this.files[0].name:''"></label>
            <span class="file-name"></span>
            <button class="btn" type="submit" style="margin-top:12px">上传头像</button>
          </form>
        </div>
        <div class="profile-card" id="author" style="margin-top:20px">
          <div class="section-title">作者身份</div>
          <?php if (is_author($u)): ?>
            <p class="mini-meta" style="font-size:14px;color:var(--text)"><i class="fa-solid fa-user-tie"></i> 你是<?= is_admin($u) ? '管理员' : '作者' ?>，可以发布整合包并管理自己的内容。</p>
          <?php else: ?>
            <?php $ast = (int)($u['author_status'] ?? 0); ?>
            <?php if ($ast === 1): ?>
              <p class="mini-meta" style="font-size:14px;color:var(--text)"><i class="fa-solid fa-clock"></i> 你的作者申请正在审核中，请耐心等待。</p>
            <?php else: ?>
              <p class="mini-meta" style="font-size:14px;color:var(--text)">申请成为作者后，即可发布整合包，并管理自己的整合包与评论。</p>
              <form method="post" action="/profile" class="field">
                <input type="hidden" name="action" value="apply_author">
                <label>申请理由</label>
                <textarea name="author_reason" placeholder="例如：我是 XX 整合包作者，想长期分享作品…（至少 5 个字）" required></textarea>
                <button class="btn" type="submit" style="margin-top:12px"><?= $ast === 3 ? '重新申请' : '申请成为作者' ?></button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="pane" id="pane2">
        <div class="profile-card">
          <div class="section-title">修改密码</div>
          <form method="post" action="/profile">
            <input type="hidden" name="action" value="password">
            <div class="field"><label>原密码</label><input type="password" name="old_pass" required></div>
            <div class="field"><label>新密码</label><input type="password" name="new_pass" required></div>
            <div class="field"><label>确认新密码</label><input type="password" name="cf_pass" required></div>
            <button class="btn" type="submit">保存密码</button>
          </form>
        </div>
      </div>
      <div class="pane" id="pane3">
        <div class="profile-card">
          <div class="section-title">我的收藏（<?= count($favRows) ?>）</div>
          <?php if ($favRows): ?>
          <div class="mini-list">
            <?php foreach ($favRows as $f): ?>
              <div class="mini-item">
                <?php if ($f['cover']): ?><img class="mini-thumb" src="<?= e(cover_url($f['cover'])) ?>" alt=""><?php endif; ?>
                <a href="/modpack/<?= e($f['slug']) ?>"><?= e($f['title']) ?></a>
                <span class="mini-meta">收藏于 <?= e(fmt_date($f['fav_at'])) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?><p class="mini-empty">还没有收藏任何整合包，去前台逛逛吧。</p><?php endif; ?>
        </div>
      </div>
      <div class="pane" id="pane4">
        <div class="profile-card">
          <div class="section-title">我的评论（<?= count($comRows) ?>）</div>
          <?php if ($comRows): ?>
          <div class="mini-list">
            <?php foreach ($comRows as $c): ?>
              <div class="mini-item" style="flex-direction:column;align-items:stretch;gap:4px">
                <a href="/modpack/<?= e($c['mp_slug']) ?>">评论了《<?= e($c['mp_title']) ?>》</a>
                <span style="color:var(--text);font-size:14px"><?= e(mb_strimwidth($c['content'], 0, 80, '…')) ?></span>
                <span class="mini-meta"><?= e(fmt_date($c['created_at'])) ?> · <?= (int)$c['status'] === 1 ? '已展示' : '已隐藏' ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?><p class="mini-empty">还没有发表过评论。</p><?php endif; ?>
        </div>
      </div>
      <div class="pane" id="pane5">
        <div class="profile-card">
          <div class="section-title">我的互动</div>
          <p class="mini-meta" style="font-size:14px;color:var(--text)">共点赞 <b><?= $likeCnt ?></b> 个整合包</p>
        </div>
      </div>

      <?php if (is_admin($u)): ?>
      <div class="pane" id="pane6">
        <div class="profile-card">
          <div class="section-title"><i class="fa-solid fa-gavel"></i> 申诉审核</div>
          <?php $pendingCnt = count(array_filter($appealsList, function ($x) { return (int)$x['status'] === 0; })); ?>
          <p class="mini-meta" style="font-size:14px;color:var(--text)">待处理 <b><?= $pendingCnt ?></b> 条</p>
          <?php if (!$appealsList): ?><p class="muted">暂无申诉记录。</p>
          <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:12px">
              <?php foreach ($appealsList as $a): ?>
                <div style="background:var(--bg2);border:1px solid var(--line);border-radius:12px;padding:14px 16px">
                  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                    <span style="font-weight:700">@<?= e($a['uname'] ?: ('用户#' . (int)$a['user_id'])) ?><?= $a['nickname'] ? '（' . e($a['nickname']) . '）' : '' ?></span>
                    <span class="role-badge"><?= (int)$a['status'] === 0 ? '待处理' : ((int)$a['status'] === 1 ? '已通过' : '已驳回') ?></span>
                  </div>
                  <p style="white-space:pre-wrap;margin:10px 0 6px;font-size:14px;line-height:1.7"><?= e($a['content']) ?></p>
                  <div class="mini-meta" style="font-size:12px"><?= e(fmt_date($a['created_at'])) ?><?= trim($a['reply']) ? ' · 处理回复：' . e($a['reply']) : '' ?></div>
                  <?php if ((int)$a['status'] === 0): ?>
                    <form method="post" action="/profile" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px">
                      <input type="hidden" name="action" value="appeal_handle">
                      <input type="hidden" name="aid" value="<?= (int)$a['id'] ?>">
                      <input type="text" name="reply" placeholder="驳回原因（可空）" style="flex:1;min-width:150px;background:var(--bg2);border:1px solid var(--line);color:var(--text);border-radius:8px;padding:8px 10px;font-size:14px">
                      <button class="btn" type="submit" name="do" value="approve">通过并解除禁言</button>
                      <button class="btn ghost" type="submit" name="do" value="reject">驳回</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>
<div id="appealModal" class="mute-modal" style="display:none">
  <div class="mute-modal-box">
    <div class="mute-modal-title"><i class="fa-solid fa-gavel"></i> 提交禁言申诉</div>
    <p class="mute-modal-sub">说明你的情况，管理员处理后会通过站内信和邮件通知你。</p>
    <textarea id="appealContent" rows="5" class="mute-days" placeholder="申诉理由…"></textarea>
    <div class="mute-modal-actions">
      <button type="button" class="btn ghost" onclick="closeAppeal()">取消</button>
      <button type="button" class="btn" onclick="doAppeal()">提交申诉</button>
    </div>
  </div>
</div>
<script src="/assets/js/app.js"></script>
<script>
function openAppeal() { document.getElementById('appealModal').style.display = 'flex'; }
function closeAppeal() { document.getElementById('appealModal').style.display = 'none'; }
function doAppeal() {
  var content = encodeURIComponent(document.getElementById('appealContent').value);
  fetch('/ajax.php?act=appeal', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'content=' + content })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d.ok) { closeAppeal(); alert(d.msg || '申诉已提交'); location.reload(); } else alert(d.msg || '提交失败'); });
}
(function () {
  var tabs = document.getElementById('paneTabs');
  if (!tabs) return;
  tabs.addEventListener('click', function (e) {
    var btn = e.target.closest('.pane-tab');
    if (!btn) return;
    var id = btn.getAttribute('data-pane');
    tabs.querySelectorAll('.pane-tab').forEach(function (t) { t.classList.remove('on'); });
    btn.classList.add('on');
    document.querySelectorAll('.pane').forEach(function (p) {
      if (p.id === id) p.classList.add('on');
      else p.classList.remove('on');
    });
  });
})();
</script>
</body>
</html>