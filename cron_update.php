<?php
/**
 * ============================================================
 *  自动更新检测脚本（可独立由系统 cron 调度）
 * ------------------------------------------------------------
 *  检测 GitHub 最新 tag；后台已开启 auto_update 且新 tag 名
 *  含触发词（auto_update_keyword，默认 auto）时，自动拉取更新。
 *
 *  用法（宝塔计划任务，建议每日一次或更频繁）：
 *    */30 * * * * php /网站绝对路径/cron_update.php >> /网站绝对路径/cron_update.log 2>&1
 *  命令行直接测试：php cron_update.php
 * ============================================================
 */
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/update.php';

$autoUp = (int)setting('auto_update', 0);
echo '[' . date('Y-m-d H:i:s') . '] 自动更新检测开始（开关：' . ($autoUp ? '开' : '关') . '）' . "\n";
if (!$autoUp) {
    echo '[' . date('Y-m-d H:i:s') . '] 未开启自动更新，退出' . "\n";
    exit;
}

$kw  = trim((string)setting('auto_update_keyword', 'auto')) !== '' ? setting('auto_update_keyword') : 'auto';
$tok = setting('github_token', '');
$remote = mc_remote_latest('dieliana/MCshare', $tok);

if (!$remote) {
    echo '[' . date('Y-m-d H:i:s') . '] 无法连接 GitHub，稍后重试' . "\n";
    exit;
}
if ($remote['tag'] === '') {
    echo '[' . date('Y-m-d H:i:s') . '] 远程暂无可比对的 tag，跳过' . "\n";
    exit;
}

$localTag = '';
if (file_exists(BASE_PATH . '/VERSION') && preg_match('/^v?(\d+\.\d+\.\d+)/', (string)file_get_contents(BASE_PATH . '/VERSION'), $m)) {
    $localTag = $m[1];
}

if ($localTag !== '' && !version_compare($remote['tag'], $localTag, '>')) {
    echo '[' . date('Y-m-d H:i:s') . "] 无需更新（当前 v{$localTag}，远程 v{$remote['tag']}）\n";
    exit;
}

if (stripos($remote['tag'], (string)$kw) === false) {
    echo '[' . date('Y-m-d H:i:s') . "] 检测到新版本 v{$remote['tag']}，但不含触发词 \"{$kw}\"，不自动更新\n";
    exit;
}

$ur = mc_do_update($remote['tag'], $remote['sha'], 'dieliana/MCshare', 'main');
echo '[' . date('Y-m-d H:i:s') . '] 自动更新：' . ($ur['ok'] ? 'v' . $remote['tag'] . ' 成功（更新 ' . $ur['copied'] . ' 文件，跳过保护 ' . $ur['skipped'] . '）' : '失败：' . $ur['msg']) . "\n";
