<?php
/**
 * 后台 · 删除整合包（POST）
 */
require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? '')) {
    redirect('modpacks.php');
}

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    db()->prepare('DELETE FROM modpacks WHERE id = ?')->execute([$id]); // 关联表级联删除
}

redirect('modpacks.php');
