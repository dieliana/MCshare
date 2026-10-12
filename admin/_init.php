<?php
/**
 * 后台公共引导：连接 + 登录校验
 * 所有后台页面顶部引入本文件
 */
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/auth.php';

// 确保 users.is_super 列存在（兼容升级前旧库）
mc_ensure_super_column();

$admin = current_admin();
if (!$admin) {
    // 前台已登录：role=admin 且 is_super=1 的超级管理员自动放行
    if (!empty($_SESSION['user_id'])) {
        try {
            $st = db()->prepare('SELECT id, username FROM users WHERE id = ? AND role = ? AND is_super = ? AND status = 1');
            $st->execute([(int)$_SESSION['user_id'], 'admin', 1]);
            $row = $st->fetch();
        } catch (Exception $e) {
            $row = null;
        }
        if ($row) {
            $_SESSION['admin_id'] = (int)$row['id'];
            $_SESSION['admin_name'] = $row['username'];
            $admin = current_admin();
        } else {
            // 平滑迁移：前端用户名与旧 admins 表匹配 → 自动提升为超级管理员并放行
            try {
                $st2 = db()->prepare('SELECT a.id AS aid, a.username AS aname FROM admins a INNER JOIN users u ON a.username = u.username WHERE u.id = ? LIMIT 1');
                $st2->execute([(int)$_SESSION['user_id']]);
                $legacy = $st2->fetch();
            } catch (Exception $e) {
                $legacy = null;
            }
            if ($legacy) {
                db()->prepare('UPDATE users SET role = ?, is_super = ? WHERE id = ?')->execute(['admin', 1, (int)$_SESSION['user_id']]);
                $_SESSION['admin_id'] = (int)$legacy['aid'];
                $_SESSION['admin_name'] = $legacy['aname'];
                $admin = current_admin();
            } else {
                // 普通用户 / 普通管理员（非超级）：权限不足，返回用户中心
                redirect('../profile?denied=1');
            }
        }
    } else {
        redirect('login.php');
    }
}

$siteName = setting('site_name', defined('SITE_NAME') ? SITE_NAME : 'MC Modpack Hub');
$adminTheme = setting('theme', 'emerald');
