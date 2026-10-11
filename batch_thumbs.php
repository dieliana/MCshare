<?php
/**
 * 批量生成缩略图（一次性脚本）
 * 遍历 uploads 目录，为所有宽度超过 480px 的 jpg/png/webp/gif 生成 _thumb 缩略图，
 * 用于加速前台列表页图片加载。已存在的缩略图自动跳过，可重复运行。
 * 访问：浏览器打开 /batch_thumbs.php（生成完成后请删除本文件）
 */
define('BASE_PATH', __DIR__);
require __DIR__ . '/includes/functions.php';
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', __DIR__ . '/uploads');

header('Content-Type: text/plain; charset=utf-8');
$root = rtrim(UPLOAD_DIR, '/\\');
if (!is_dir($root)) { exit('上传目录不存在：' . $root); }

$n = 0; $skip = 0; $err = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $ext = strtolower($f->getExtension());
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) continue;
    if (strpos($f->getFilename(), '_thumb.') !== false) { $skip++; continue; }
    if (make_thumb($f->getPathname())) $n++; else $err++;
}
echo "完成：生成 $n 个缩略图，跳过 $skip 个已有缩略图，$err 个无法处理。\n";
echo "提示：生成完成后请删除本文件。\n";
