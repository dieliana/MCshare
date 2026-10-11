<?php
/**
 * 下载跳转 / 服务器文件下载
 * /download/:slug      → 该整合包第一个下载项（外链跳转 / 本地文件流式输出）
 * /download/:slug/:id  → 指定下载项
 * 统一统计下载次数。
 */
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$slug = trim($_GET['slug'] ?? '');
$dlId = (int)($_GET['dl'] ?? 0);
if ($slug === '') { header('Location: /'); exit; }

$st = db()->prepare('SELECT id FROM modpacks WHERE slug = ? AND status = 1');
$st->execute([$slug]);
$row = $st->fetch();
if (!$row) { header('Location: /modpack/' . urlencode($slug)); exit; }
$mpId = (int)$row['id'];

// 指定下载项
$dl = null;
if ($dlId > 0) {
    $q = db()->prepare('SELECT * FROM modpack_downloads WHERE id = ? AND modpack_id = ?');
    $q->execute([$dlId, $mpId]);
    $dl = $q->fetch();
    if (!$dl) { header('Location: /modpack/' . urlencode($slug)); exit; }
}

// 未指定：取第一个下载项
if (!$dl) {
    $q = db()->prepare('SELECT * FROM modpack_downloads WHERE modpack_id = ? ORDER BY sort ASC, id ASC LIMIT 1');
    $q->execute([$mpId]);
    $dl = $q->fetch();
}

// 无下载项 → 回退到旧的 download_url
if (!$dl) {
    $st2 = db()->prepare('SELECT download_url FROM modpacks WHERE id = ?');
    $st2->execute([$mpId]);
    $legacy = $st2->fetchColumn();
    if (!$legacy) { header('Location: /modpack/' . urlencode($slug)); exit; }
    db()->prepare('UPDATE modpacks SET downloads = downloads + 1 WHERE id = ?')->execute([$mpId]);
    header('Location: ' . $legacy);
    exit;
}

db()->prepare('UPDATE modpacks SET downloads = downloads + 1 WHERE id = ?')->execute([$mpId]);

// 服务器本地文件 → 流式输出
if ($dl['file_path']) {
    $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($dl['file_path'], '/\\');
    if (!is_file($abs)) { header('Location: /modpack/' . urlencode($slug)); exit; }
    $label = trim($dl['label']) ?: basename($dl['file_path']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($label) . '"');
    header('Content-Length: ' . filesize($abs));
    header('X-Accel-Buffering: no');
    readfile($abs);
    exit;
}

header('Location: ' . $dl['url']);
exit;
