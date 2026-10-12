<?php
/**
 * 后台登录
 */
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/auth.php';

// 已登录则跳转后台
if (!empty($_SESSION['admin_id'])) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $token    = $_POST['csrf'] ?? '';

    if (!csrf_check($token)) {
        $error = '会话已过期，请刷新页面重试';
    } elseif ($username === '' || $password === '') {
        $error = '请输入用户名和密码';
    } elseif (mc_login_blocked()) {
        $error = '尝试次数过多，请 10 分钟后再试';
    } else {
        // 超级管理员与前端账户同步：查 users 表中 role=admin 且 is_super=1 的账号，支持用户名或邮箱登录
        $st = db()->prepare('SELECT id, username, email, password, role, is_super FROM users WHERE (username = ? OR email = ?) AND role = ? AND is_super = ? AND status = 1 LIMIT 1');
        $st->execute([$username, $username, 'admin', 1]);
        $user = $st->fetch();
        if ($user && password_verify($password, $user['password'])) {
            mc_login_reset();
            session_regenerate_id(true);
            $_SESSION['admin_id']   = (int)$user['id'];
            $_SESSION['admin_name'] = $user['username'];
            $_SESSION['user_id']    = (int)$user['id'];
            redirect('index.php');
        } else {
            // 兼容旧体系：admins 表（旧超级管理员账号）
            $st2 = db()->prepare('SELECT id, username, password FROM admins WHERE username = ?');
            $st2->execute([$username]);
            $old = $st2->fetch();
            if ($old && password_verify($password, $old['password'])) {
                mc_login_reset();
                session_regenerate_id(true);
                $_SESSION['admin_id']   = (int)$old['id'];
                $_SESSION['admin_name'] = $old['username'];
                redirect('index.php');
            } else {
                mc_login_failed();
                $error = '用户名 / 邮箱或密码错误';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= e(setting('theme','emerald')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>管理员登录 · <?= e(setting('site_name', 'MC Modpack Hub')) ?></title>
<link rel="stylesheet" href="assets/admin.css">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
</head>
<body class="login-body">
<div class="login-card">
  <div class="login-logo"><i class="fa-solid fa-cube"></i> <?= e(setting('site_name', 'MC Modpack Hub')) ?></div>
  <p class="login-sub">管理后台</p>
  <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="login.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>用户名 / 邮箱</label>
    <input type="text" name="username" autofocus required placeholder="前端超级管理员用户名或邮箱">
    <label>密码</label>
    <input type="password" name="password" required placeholder="请输入密码">
    <button class="btn block" type="submit">登 录</button>
  </form>
  <a class="back-home" href="../index.php">← 返回前台首页</a>
</div>
</body>
</html>
