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

/** 获取当前登录管理员（返回数组或 null）——超级管理员即前端 users 表中 role=admin 的账号；兼容旧 admins 表 */
function current_admin()
{
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id) return null;
    // 新体系：前端 users 表 role=admin 且 is_super=1（超级管理员）
    try {
        $st = db()->prepare('SELECT id, username, email, role, is_super FROM users WHERE id = ? AND role = ? AND is_super = ? AND status = 1');
        $st->execute([(int)$id, 'admin', 1]);
        $r = $st->fetch();
        if ($r) return $r;
    } catch (Exception $e) {}
    // 旧体系：admins 表（admin_id 为 admins.id）
    try {
        $a = db()->prepare('SELECT id, username FROM admins WHERE id = ?');
        $a->execute([(int)$id]);
        $row = $a->fetch();
        if ($row) return ['id' => (int)$row['id'], 'username' => $row['username'], 'email' => null, 'role' => 'admin'];
    } catch (Exception $e) {}
    return null;
}
