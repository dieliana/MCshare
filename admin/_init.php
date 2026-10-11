<?php
/**
 * 后台公共引导：连接 + 登录校验
 * 所有后台页面顶部引入本文件
 */
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/auth.php';

$admin = current_admin();
if (!$admin) {
    // 前台已登录：用户名与后台 admins 表匹配的为最高级账号，自动放行
    if (!empty($_SESSION['user_id'])) {
        $st = db()->prepare('SELECT a.id AS aid, a.username AS aname FROM users u INNER JOIN admins a ON a.username = u.username WHERE u.id = ?');
        $st->execute([(int)$_SESSION['user_id']]);
        $row = $st->fetch();
        if ($row) {
            $_SESSION['admin_id'] = (int)$row['aid'];
            $_SESSION['admin_name'] = $row['aname'];
            $admin = ['id' => (int)$row['aid'], 'username' => $row['aname']];
        } else {
            // 普通管理员 / 普通用户：权限不足，返回用户中心
            redirect('../profile?denied=1');
        }
    } else {
        redirect('login.php');
    }
}

$siteName = setting('site_name', defined('SITE_NAME') ? SITE_NAME : 'MC Modpack Hub');
$adminTheme = setting('theme', 'emerald');
