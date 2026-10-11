<?php
/**
 * ============================================================
 *  每日总结定时脚本（建议每天凌晨 4:00 由系统 cron 调用）
 * ------------------------------------------------------------
 *  汇总过去 24 小时全站动态，通过站内信 + 邮件推送给每位用户。
 *
 *  用法：
 *   - Linux / 宝塔计划任务（Shell）：
 *       0 4 * * * php /网站绝对路径/cron_daily.php >> /网站绝对路径/cron_daily.log 2>&1
 *   - Windows 计划任务：触发器每天 04:00，
 *       程序 php.exe，参数 D:\Desktop\MCshare\cron_daily.php
 *   - 命令行直接测试：php cron_daily.php
 *
 *  未配置 SMTP 时只发站内信；配置后自动同步发美化邮件（notify 已联动）。
 * ============================================================
 */
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';

$since = date('Y-m-d H:i:s', strtotime('-24 hours'));
$pdo   = db();

// —— 全站统计 ——
$newMps     = (int)$pdo->query("SELECT COUNT(*) FROM modpacks WHERE status = 1 AND created_at >= '" . $since . "'")->fetchColumn();
$newComments = (int)$pdo->query("SELECT COUNT(*) FROM comments WHERE status = 1 AND created_at >= '" . $since . "'")->fetchColumn();

// —— 所有用户 ——
$users = $pdo->query('SELECT id FROM users ORDER BY id ASC')->fetchAll();

$sent = 0;
foreach ($users as $u) {
    $uid = (int)$u['id'];

    // 个人：你的整合包收到的评论
    $st = $pdo->prepare('SELECT COUNT(*) FROM comments c JOIN modpacks m ON m.id = c.modpack_id WHERE m.user_id = ? AND c.user_id <> ? AND c.status = 1 AND c.created_at >= ?');
    $st->execute([$uid, $uid, $since]);
    $myNewComments = (int)$st->fetchColumn();

    // 个人：你的评论收到的回复
    $st = $pdo->prepare('SELECT COUNT(*) FROM comments c JOIN comments p ON p.id = c.parent_id WHERE p.user_id = ? AND c.user_id <> ? AND c.status = 1 AND c.created_at >= ?');
    $st->execute([$uid, $uid, $since]);
    $myReplies = (int)$st->fetchColumn();

    // 个人：你新发布的整合包
    $st = $pdo->prepare('SELECT COUNT(*) FROM modpacks WHERE user_id = ? AND created_at >= ?');
    $st->execute([$uid, $since]);
    $myPubs = (int)$st->fetchColumn();

    if ($myNewComments + $myReplies + $myPubs + $newMps + $newComments === 0) {
        continue; // 过去 24 小时没有任何动态，跳过
    }

    $content = '过去 24 小时动态：' . "\n"
        . '· 全站新增整合包 ' . $newMps . ' 个' . "\n"
        . '· 全站新增评论 ' . $newComments . ' 条' . "\n";
    if ($myPubs > 0)           $content .= '· 你发布了 ' . $myPubs . ' 个整合包' . "\n";
    if ($myNewComments > 0)    $content .= '· 你的整合包收到 ' . $myNewComments . ' 条新评论' . "\n";
    if ($myReplies > 0)        $content .= '· 你的评论收到 ' . $myReplies . ' 条回复' . "\n";

    $link = ($myPubs > 0 || $myNewComments > 0) ? '/my-modpacks' : '/';
    notify($uid, 0, 'daily', '每日总结 · ' . date('m月d日'), rtrim($content), $link);
    $sent++;
}

echo '[' . date('Y-m-d H:i:s') . "] 每日总结完成，共发送 {$sent} 位用户\n";
