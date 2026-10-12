<?php
/**
 * 后台 · 账号绑定
 * 将前端同邮箱账号绑定为超级管理员（users.role=admin），后台超级管理员与前端账户同步
 */
require __DIR__ . '/_init.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $msg = ['err', '会话已过期'];
    } elseif (!empty($_POST['bind_email'])) {
        $r = mc_bind_superadmin($_POST['bind_email']);
        $msg = ['ok', $r[1]];
    } else {
        $msg = ['err', '请输入要绑定的邮箱'];
    }
}

$admins = [];
try {
    $st = db()->prepare('SELECT id, username, email, nickname FROM users WHERE role = ? ORDER BY id ASC');
    $st->execute(['admin']);
    $admins = $st->fetchAll();
} catch (Exception $e) {
    $admins = [];
}

$pageTitle = '账号绑定';
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
  <div class="page-head"><h1><?= e($pageTitle) ?></h1></div>

  <?php if ($msg): ?><div class="alert <?= $msg[0] ?>"><?= e($msg[1]) ?></div><?php endif; ?>

  <section class="panel" style="max-width:640px">
    <h2>绑定超级管理员</h2>
    <p style="color:var(--muted);margin:0 0 14px">超级管理员与前端账户同步。填入一个前端已注册账号的邮箱，即可把该账号提升为超级管理员（role=admin）；该账号在前台登录后即可访问后台（普通用户访问后台会提示权限不足并返回用户中心）。</p>
    <form method="post" action="bind.php" class="form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="field"><label>前端用户邮箱</label>
        <input type="email" name="bind_email" required placeholder="输入前端已注册账号的邮箱">
      </div>
      <button class="btn" type="submit"><i class="fa-solid fa-link"></i> 绑定为超级管理员</button>
    </form>
  </section>

  <section class="panel" style="max-width:640px">
    <h2>当前超级管理员</h2>
    <?php if ($admins): ?>
      <table class="table">
        <thead><tr><th>用户名</th><th>邮箱</th><th>昵称</th></tr></thead>
        <tbody>
        <?php foreach ($admins as $a): ?>
          <tr><td><?= e($a['username']) ?></td><td><?= e($a['email']) ?></td><td><?= e($a['nickname'] ?: '-') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="muted">暂无超级管理员。</p>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
