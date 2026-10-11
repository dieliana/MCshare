<?php
/**
 * 管理员登录态
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** 后台页面鉴权：未登录则跳转登录页 */
function require_login()
{
    if (empty($_SESSION['admin_id'])) {
        redirect('/login');
    }
}

/** 获取当前登录管理员（返回数组或 null） */
function current_admin()
{
    if (empty($_SESSION['admin_id'])) return null;
    try {
        $st = db()->prepare('SELECT id, username FROM admins WHERE id = ?');
        $st->execute([$_SESSION['admin_id']]);
        return $st->fetch();
    } catch (Exception $e) {
        return null;
    }
}
