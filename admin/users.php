<?php
/**
 * 后台 · 用户管理
 */
require __DIR__ . '/_init.php';

$q = trim($_GET['q'] ?? '');
$act = $_POST['action'] ?? '';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($act === 'ban') {
        $reason = trim($_POST['ban_reason'] ?? '');
        db()->prepare('UPDATE users SET status = 0, ban_reason = ? WHERE id = ?')->execute([$reason, $uid]);
        notify($uid, 0, 'system', '账号已被封禁', $reason !== '' ? '封禁原因：' . $reason : '你的账号已被封禁，暂时无法登录', '/profile');
        $msg = ['ok', '已封禁该用户'];
    } elseif ($act === 'unban') {
        db()->prepare('UPDATE users SET status = 1 WHERE id = ?')->execute([$uid]);
        notify($uid, 0, 'system', '账号已解封', '你的账号已解封，可以正常登录了', '/profile');
        $msg = ['ok', '已解封该用户'];
    } elseif ($act === 'make_admin') {
        db()->prepare('UPDATE users SET role = "admin" WHERE id = ?')->execute([$uid]);
        notify($uid, 0, 'role', '你已成为管理员', '你已被设为管理员，获得全部管理权限', '/profile');
        $msg = ['ok', '已设为管理员'];
    } elseif ($act === 'rm_admin') {
        db()->prepare('UPDATE users SET role = "user" WHERE id = ?')->execute([$uid]);
        notify($uid, 0, 'role', '管理员身份已取消', '你的管理员身份已被取消，恢复为普通用户', '/profile');
        $msg = ['ok', '已取消管理员'];
    } elseif ($act === 'make_author') {
        db()->prepare('UPDATE users SET role = "author", author_status = 2 WHERE id = ?')->execute([$uid]);
        notify($uid, 0, 'author', '你已成为作者', '你已被设为作者，可直接发布整合包并管理自己的内容', '/profile');
        $msg = ['ok', '已设为作者'];
    } elseif ($act === 'rm_author') {
        db()->prepare('UPDATE users SET role = "user" WHERE id = ?')->execute([$uid]);
        notify($uid, 0, 'role', '作者身份已取消', '你的作者身份已被取消，恢复为普通用户', '/profile');
        $msg = ['ok', '已取消作者'];
    } elseif ($act === 'mute') {
        $muteDays = (int)($_POST['mute_days'] ?? 0);
        $muteReason = trim($_POST['mute_reason'] ?? '');
        mute_user($uid, $muteDays > 0 ? $muteDays : null);
        db()->prepare('UPDATE users SET mute_reason = ? WHERE id = ?')->execute([$muteReason, $uid]);
        notify($uid, 0, 'mute', '你已被禁言', ($muteReason !== '' ? '禁言原因：' . $muteReason . '；' : '') . ($muteDays > 0 ? '你已被禁言 ' . $muteDays . ' 天' : '你的账号已被永久禁言'), '/profile');
        $msg = ['ok', $muteDays > 0 ? '已禁言该用户 ' . $muteDays . ' 天' : '已永久禁言该用户'];
    } elseif ($act === 'unmute') {
        db()->prepare('UPDATE users SET muted = 0, muted_until = NULL, mute_reason = "" WHERE id = ?')->execute([$uid]);
        notify($uid, 0, 'mute', '禁言已解除', '你的禁言已解除，可以正常评论和发布了', '/profile');
        $msg = ['ok', '已解除禁言'];
    } elseif ($act === 'make_no_review') {
        db()->prepare('UPDATE users SET no_review = 1 WHERE id = ?')->execute([$uid]);
        notify($uid, 0, 'role', '你已开启免审核', '你发布整合包将无需审核、直接上线', '/profile');
        $msg = ['ok', '已开启该用户免审核'];
    } elseif ($act === 'rm_no_review') {
        db()->prepare('UPDATE users SET no_review = 0 WHERE id = ?')->execute([$uid]);
        notify($uid, 0, 'role', '免审核已取消', '你发布整合包将需要管理员审核', '/profile');
        $msg = ['ok', '已取消该用户免审核'];
    }
}

$sql = 'SELECT u.*,
        (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS comment_count,
        (SELECT COUNT(*) FROM favorites f WHERE f.user_id = u.id) AS fav_count
        FROM users u';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE u.username LIKE :kw1 OR u.email LIKE :kw2 OR u.nickname LIKE :kw3';
    $params[':kw1'] = '%' . $q . '%';
    $params[':kw2'] = '%' . $q . '%';
    $params[':kw3'] = '%' . $q . '%';
}
$sql .= ' ORDER BY u.id DESC LIMIT 200';
$st = db()->prepare($sql);
$st->execute($params);
$users = $st->fetchAll();

$pageTitle = '用户管理';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= e($adminTheme ?? 'emerald') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · 管理后台</title>
<link rel="stylesheet" href="assets/admin.css">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<main class="content">
  <div class="page-head">
    <h1><?= e($pageTitle) ?><span class="count"> (<?= count($users) ?>)</span></h1>
    <form class="inline-form" method="get" action="users.php">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="搜索用户名/邮箱/昵称">
      <button class="btn ghost" type="submit">搜索</button>
    </form>
  </div>

  <?php if ($msg): ?><div class="alert <?= $msg[0] ?>"><?= e($msg[1]) ?></div><?php endif; ?>

  <section class="panel">
    <table class="table">
      <thead><tr><th>ID</th><th>用户</th><th>邮箱</th><th>角色</th><th>状态</th><th>评论/收藏</th><th>注册时间</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <?php if ($u['avatar']): ?><img src="<?= e(cover_url($u['avatar'])) ?>" style="width:34px;height:34px;border-radius:50%;object-fit:cover" alt="">
              <?php else: ?><span style="width:34px;height:34px;border-radius:50%;background:var(--field);display:inline-flex;align-items:center;justify-content:center"><i class="fa-solid fa-user"></i></span><?php endif; ?>
              <div><b><?= e($u['nickname'] ?: $u['username']) ?></b><br><small class="muted">@<?= e($u['username']) ?></small></div>
            </div>
          </td>
          <td><?= e($u['email']) ?></td>
          <td>
            <?php if ($u['role'] === 'admin'): ?><span class="pill green"><?= (int)($u['is_super'] ?? 0) === 1 ? '超级管理员' : '管理员' ?></span>
            <?php elseif ($u['role'] === 'author'): ?><span class="pill blue">作者</span>
            <?php else: ?><span class="pill gray">用户</span><?php endif; ?>
          </td>
          <td><span class="pill <?= (int)$u['status'] === 1 ? 'green' : 'gray' ?>"><?= (int)$u['status'] === 1 ? '正常' : '封禁' ?></span>
            <?php if ((int)$u['muted'] === 1): ?><span class="pill gray" title="禁言到期时间"><?= e(mute_until_text($u)) ?><?= trim($u['mute_reason'] ?? '') ? '·' . e($u['mute_reason']) : '' ?></span><?php endif; ?>
            <?php if ((int)($u['no_review'] ?? 0) === 1): ?><span class="pill blue">免审</span><?php endif; ?>
            <?php if ((int)$u['status'] === 0 && trim($u['ban_reason'] ?? '')): ?><span class="pill gray" title="封禁原因"><?= e(mb_strimwidth($u['ban_reason'], 0, 18, '…')) ?></span><?php endif; ?>
          </td>
          <td><?= (int)$u['comment_count'] ?> / <?= (int)$u['fav_count'] ?></td>
          <td><?= e(fmt_date($u['created_at'])) ?></td>
          <td>
            <a class="op" href="mail.php?to=<?= (int)$u['id'] ?>" title="给该用户发邮件"><i class="fa-solid fa-envelope"></i> 发邮件</a>
            <form method="post" action="users.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
              <?php if ((int)$u['status'] === 1): ?>
                <input type="text" name="ban_reason" placeholder="封禁原因（可空）" class="reject-input">
                <button class="op danger" name="action" value="ban" type="submit">封禁</button>
              <?php else: ?>
                <button class="op" name="action" value="unban" type="submit">解封</button>
              <?php endif; ?>
              <?php if ($u['role'] === 'admin'): ?>
                <button class="op" name="action" value="rm_admin" type="submit">取消管理</button>
              <?php elseif ($u['role'] === 'author'): ?>
                <button class="op" name="action" value="rm_author" type="submit">取消作者</button>
                <button class="op" name="action" value="make_admin" type="submit">设为管理</button>
              <?php else: ?>
                <button class="op" name="action" value="make_author" type="submit">设为作者</button>
                <button class="op" name="action" value="make_admin" type="submit">设为管理</button>
              <?php endif; ?>
              <?php if ((int)($u['no_review'] ?? 0) === 1): ?>
                <button class="op" name="action" value="rm_no_review" type="submit">取消免审</button>
              <?php else: ?>
                <button class="op" name="action" value="make_no_review" type="submit">设为免审</button>
              <?php endif; ?>
              <?php if ((int)$u['muted'] === 1): ?>
                <button class="op" name="action" value="unmute" type="submit">解除禁言</button>
              <?php else: ?>
                <select name="mute_days" style="vertical-align:middle">
                  <option value="1">1天</option>
                  <option value="3">3天</option>
                  <option value="7">7天</option>
                  <option value="30">30天</option>
                  <option value="0">永久</option>
                </select>
                <input type="text" name="mute_reason" placeholder="禁言原因（可空）" class="reject-input">
                <button class="op" name="action" value="mute" type="submit">禁言</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="8" class="empty-row">没有找到用户。</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
</main>
</body>
</html>
