<?php
/**
 * 批量生成缩略图（一次性脚本）
 * 遍历 uploads 目录，为所有宽度超过 480px 的 jpg/png/webp/gif 生成 _thumb 缩略图，
 * 用于加速前台列表页图片加载。已存在的缩略图自动跳过，可重复运行。
 * 访问：浏览器打开 /batch_thumbs.php（系统更新后会自动执行本脚本并删除，无需手动运行）
 */
define('BASE_PATH', __DIR__);
require __DIR__ . '/includes/functions.php';
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', __DIR__ . '/uploads');

header('Content-Type: text/plain; charset=utf-8');
$root = rtrim(UPLOAD_DIR, '/\\');
if (!is_dir($root)) { exit('上传目录不存在：' . $root); }

$n = generate_all_thumbs();
echo "完成：生成 $n 个缩略图。\n提示：本脚本由系统更新后自动执行并删除，也可手动删除。\n";
