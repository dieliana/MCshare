<?php
/**
 * 前台 AJAX 接口
 *  act=send_code  发送邮箱验证码（注册/忘记密码等）
 *  act=like       点赞 / 取消点赞（需登录）
 *  act=fav        收藏 / 取消收藏（需登录）
 *  act=login_check 返回登录状态
 */
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';
ensure_session();
maintenance_check();

header('Content-Type: application/json; charset=utf-8');

$act = $_GET['act'] ?? ($_POST['act'] ?? '');
$out = ['ok' => false, 'msg' => '参数错误'];

function json_out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

switch ($act) {

    case 'send_code':
        $email   = trim($_POST['email'] ?? '');
        $purpose = trim($_POST['purpose'] ?? 'register');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out = ['ok' => false, 'msg' => '邮箱格式不正确'];
            break;
        }
        if (!in_array($purpose, ['register', 'forgot'], true)) {
            $out = ['ok' => false, 'msg' => '用途参数错误'];
            break;
        }
        if ($purpose === 'register') {
            $st = db()->prepare('SELECT id FROM users WHERE email = ?');
            $st->execute([$email]);
            if ($st->fetch()) {
                $out = ['ok' => false, 'msg' => '该邮箱已被注册'];
                break;
            }
        }
        // 60 秒内限制发送一次
        $now = time();
        if (isset($_SESSION['email_code_sent'][$purpose]) && $now - $_SESSION['email_code_sent'][$purpose] < 60) {
            $out = ['ok' => false, 'msg' => '发送过于频繁，请 60 秒后再试'];
            break;
        }
        $ok = send_email_code($email, $purpose, '邮箱验证码', '请使用以下验证码完成验证：');
        if ($ok) {
            $_SESSION['email_code_sent'][$purpose] = $now;
            $out = ['ok' => true, 'msg' => '验证码已发送到你的邮箱，请查收'];
        } else {
            $out = ['ok' => false, 'msg' => '邮件发送失败：' . smtp_error() . '（请先在后台配置 SMTP 邮箱）'];
        }
        break;

    case 'like':
        $mpid = (int)($_POST['modpack_id'] ?? 0);
        if (!is_logged()) { $out = ['ok' => false, 'msg' => '请先登录', 'need_login' => true]; break; }
        if ($mpid <= 0) break;
        $u = current_user();
        if (!$u) { $out = ['ok' => false, 'msg' => '登录状态失效']; break; }
        $pdo = db();
        $ck = $pdo->prepare('SELECT 1 FROM modpack_likes WHERE user_id = ? AND modpack_id = ?');
        $ck->execute([$u['id'], $mpid]);
        $liked = (bool)$ck->fetch();
        if ($liked) {
            $pdo->prepare('DELETE FROM modpack_likes WHERE user_id = ? AND modpack_id = ?')->execute([$u['id'], $mpid]);
        } else {
            $pdo->prepare('INSERT IGNORE INTO modpack_likes (user_id, modpack_id) VALUES (?,?)')->execute([$u['id'], $mpid]);
            $au = $pdo->prepare('SELECT user_id, title, slug FROM modpacks WHERE id = ?');
            $au->execute([$mpid]);
            $mp = $au->fetch();
            if ($mp && (int)$mp['user_id'] > 0 && (int)$mp['user_id'] !== (int)$u['id']) {
                notify((int)$mp['user_id'], $u['id'], 'mp_like', '你的整合包被点赞', '《' . $mp['title'] . '》被赞了一下', '/modpack/' . $mp['slug']);
            }
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM modpack_likes WHERE modpack_id = ?');
        $st->execute([$mpid]);
        $cnt = (int)$st->fetchColumn();
        $out = ['ok' => true, 'liked' => !$liked, 'count' => $cnt];
        break;

    case 'fav':
        $mpid = (int)($_POST['modpack_id'] ?? 0);
        if (!is_logged()) { $out = ['ok' => false, 'msg' => '请先登录', 'need_login' => true]; break; }
        if ($mpid <= 0) break;
        $u = current_user();
        if (!$u) { $out = ['ok' => false, 'msg' => '登录状态失效']; break; }
        $pdo = db();
        $ck = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND modpack_id = ?');
        $ck->execute([$u['id'], $mpid]);
        $favd = (bool)$ck->fetch();
        if ($favd) {
            $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND modpack_id = ?')->execute([$u['id'], $mpid]);
        } else {
            $pdo->prepare('INSERT IGNORE INTO favorites (user_id, modpack_id) VALUES (?,?)')->execute([$u['id'], $mpid]);
            $au = $pdo->prepare('SELECT user_id, title, slug FROM modpacks WHERE id = ?');
            $au->execute([$mpid]);
            $mp = $au->fetch();
            if ($mp && (int)$mp['user_id'] > 0 && (int)$mp['user_id'] !== (int)$u['id']) {
                notify((int)$mp['user_id'], $u['id'], 'mp_fav', '你的整合包被收藏', '《' . $mp['title'] . '》被收藏了', '/modpack/' . $mp['slug']);
            }
        }
        $out = ['ok' => true, 'favd' => !$favd];
        break;

    case 'login_check':
        $out = ['ok' => true, 'logged' => is_logged()];
        break;

    case 'comment_like':
        $cid = (int)($_POST['comment_id'] ?? 0);
        if (!is_logged()) { $out = ['ok' => false, 'msg' => '请先登录', 'need_login' => true]; break; }
        if ($cid <= 0) break;
        $u = current_user();
        if (!$u) { $out = ['ok' => false, 'msg' => '登录状态失效']; break; }
        $pdo = db();
        $cm = $pdo->prepare('SELECT user_id FROM comments WHERE id = ? AND status = 1');
        $cm->execute([$cid]);
        $owner = $cm->fetch();
        if (!$owner) { $out = ['ok' => false, 'msg' => '评论不存在']; break; }
        $ck = $pdo->prepare('SELECT 1 FROM comment_likes WHERE user_id = ? AND comment_id = ?');
        $ck->execute([$u['id'], $cid]);
        $liked = (bool)$ck->fetch();
        if ($liked) {
            $pdo->prepare('DELETE FROM comment_likes WHERE user_id = ? AND comment_id = ?')->execute([$u['id'], $cid]);
        } else {
            $pdo->prepare('INSERT IGNORE INTO comment_likes (user_id, comment_id) VALUES (?,?)')->execute([$u['id'], $cid]);
            if ((int)$owner['user_id'] !== (int)$u['id']) {
                notify((int)$owner['user_id'], $u['id'], 'like', '有人赞了你的评论', ($u['nickname'] ?: $u['username']) . ' 赞了你的评论', '');
            }
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM comment_likes WHERE comment_id = ?');
        $st->execute([$cid]);
        $out = ['ok' => true, 'liked' => !$liked, 'count' => (int)$st->fetchColumn()];
        break;

    case 'pin_comment':
        $cid = (int)($_POST['comment_id'] ?? 0);
        if (!is_logged()) { $out = ['ok' => false, 'msg' => '请先登录']; break; }
        $u = current_user();
        if (!$u) { $out = ['ok' => false, 'msg' => '登录状态失效']; break; }
        if ($cid <= 0) break;
        $pdo = db();
        $cm = $pdo->prepare('SELECT c.user_id, c.is_pinned, m.slug, m.user_id AS mp_user_id FROM comments c LEFT JOIN modpacks m ON m.id = c.modpack_id WHERE c.id = ?');
        $cm->execute([$cid]);
        $c = $cm->fetch();
        if (!$c) { $out = ['ok' => false, 'msg' => '评论不存在']; break; }
        if (!can_manage_comment($u, $c)) { $out = ['ok' => false, 'msg' => '没有权限']; break; }
        $new = $c['is_pinned'] ? 0 : 1;
        $pdo->prepare('UPDATE comments SET is_pinned = ? WHERE id = ?')->execute([$new, $cid]);
        if ($new && (int)$c['user_id'] !== (int)$u['id']) {
            notify((int)$c['user_id'], $u['id'], 'pin', '你的评论被置顶了', ($u['nickname'] ?: $u['username']) . ' 置顶了你的评论', '/modpack/' . $c['slug']);
        }
        $out = ['ok' => true, 'pinned' => (bool)$new];
        break;

    case 'del_comment':
        $cid = (int)($_POST['comment_id'] ?? 0);
        if (!is_logged()) { $out = ['ok' => false, 'msg' => '请先登录']; break; }
        $u = current_user();
        if (!$u) { $out = ['ok' => false, 'msg' => '登录状态失效']; break; }
        if ($cid <= 0) break;
        $pdo = db();
        $cm = $pdo->prepare('SELECT c.user_id, c.modpack_id, m.user_id AS mp_user_id FROM comments c LEFT JOIN modpacks m ON m.id = c.modpack_id WHERE c.id = ?');
        $cm->execute([$cid]);
        $c = $cm->fetch();
        if (!$c) { $out = ['ok' => false, 'msg' => '评论不存在']; break; }
        if (!can_manage_comment($u, $c)) { $out = ['ok' => false, 'msg' => '没有权限']; break; }
        $pdo->prepare('DELETE FROM comment_likes WHERE comment_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM comments WHERE id = ? OR parent_id = ?')->execute([$cid, $cid]);
        $out = ['ok' => true];
        break;

    case 'mute_user':
        $cid  = (int)($_POST['comment_id'] ?? 0);
        $days = (int)($_POST['days'] ?? -1);
        $reason = trim($_POST['reason'] ?? '');
        $append = ($_POST['append'] ?? '0') === '1';
        if (!is_logged()) { $out = ['ok' => false, 'msg' => '请先登录']; break; }
        $u = current_user();
        if (!$u) { $out = ['ok' => false, 'msg' => '登录状态失效']; break; }
        if ($cid <= 0) break;
        if ($days < -1 || $days > 3650) { $out = ['ok' => false, 'msg' => '禁言时长参数错误']; break; }
        $pdo = db();
        $cm = $pdo->prepare('SELECT c.user_id AS c_user, m.slug, m.user_id AS mp_user_id, u.username AS tuname, u.nickname AS tnick
                             FROM comments c LEFT JOIN modpacks m ON m.id = c.modpack_id LEFT JOIN users u ON u.id = c.user_id WHERE c.id = ?');
        $cm->execute([$cid]);
        $c = $cm->fetch();
        if (!$c) { $out = ['ok' => false, 'msg' => '评论不存在']; break; }
        if (!can_manage_comment($u, $c)) { $out = ['ok' => false, 'msg' => '没有权限']; break; }
        $target = (int)$c['c_user'];
        if ($target <= 0) { $out = ['ok' => false, 'msg' => '无法禁言该用户']; break; }
        if ($target === (int)$u['id']) { $out = ['ok' => false, 'msg' => '不能禁言自己']; break; }
        $permanent = $days <= 0;
        mute_user($target, $permanent ? null : $days, $reason, $append);
        $why = $reason !== '' ? '禁言原因：' . $reason . '；' : '';
        if ($permanent) {
            notify($target, $u['id'], 'mute', '你已被禁言', $why . '你的账号已被永久禁言，期间不能评论和发布', '/profile');
        } else {
            $until = date('Y-m-d H:i', strtotime('+' . $days . ' days'));
            notify($target, $u['id'], 'mute', '你已被禁言', $why . ($append ? '你已被追加禁言至 ' : '你已被禁言至 ') . $until . '，期间不能评论和发布', '/profile');
        }
        $out = ['ok' => true, 'msg' => $permanent ? '已永久禁言该用户' : (($append ? '已追加' : '已') . '禁言该用户 ' . $days . ' 天')];
        break;

    case 'appeal':
        if (!is_logged()) { $out = ['ok' => false, 'msg' => '请先登录']; break; }
        $u = current_user();
        if (!$u) { $out = ['ok' => false, 'msg' => '登录状态失效']; break; }
        if (!is_muted($u)) { $out = ['ok' => false, 'msg' => '你当前未被禁言，无需申诉']; break; }
        $content = trim($_POST['content'] ?? '');
        if (mb_strlen($content) < 5) { $out = ['ok' => false, 'msg' => '请填写申诉内容（至少 5 个字）']; break; }
        $pa = db()->prepare('SELECT 1 FROM appeals WHERE user_id = ? AND status = 0');
        $pa->execute([(int)$u['id']]);
        if ($pa->fetch()) { $out = ['ok' => false, 'msg' => '你已提交过申诉，请等待处理']; break; }
        db()->prepare('INSERT INTO appeals (user_id, content) VALUES (?,?)')->execute([(int)$u['id'], $content]);
        $admins = db()->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
        $who = $u['nickname'] ?: $u['username'];
        foreach ($admins as $ad) {
            if ((int)$ad['id'] !== (int)$u['id']) {
                notify((int)$ad['id'], (int)$u['id'], 'appeal', '收到禁言申诉', $who . ' 提交了禁言申诉，请到后台处理', '/admin/appeals.php');
            }
        }
        $out = ['ok' => true, 'msg' => '申诉已提交，管理员会尽快处理'];
        break;

    case 'follow':
        $followedId = (int)($_POST['followed_id'] ?? 0);
        if (!is_logged()) { $out = ['ok' => false, 'msg' => '请先登录', 'need_login' => true]; break; }
        $u = current_user();
        if (!$u) { $out = ['ok' => false, 'msg' => '登录状态失效']; break; }
        if ($followedId <= 0) break;
        if ($followedId === (int)$u['id']) { $out = ['ok' => false, 'msg' => '不能关注自己']; break; }
        $ex = db()->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?');
        $ex->execute([$u['id'], $followedId]);
        $following = (bool)$ex->fetch();
        if ($following) {
            db()->prepare('DELETE FROM follows WHERE follower_id = ? AND followed_id = ?')->execute([$u['id'], $followedId]);
        } else {
            db()->prepare('INSERT IGNORE INTO follows (follower_id, followed_id) VALUES (?,?)')->execute([$u['id'], $followedId]);
            notify($followedId, $u['id'], 'follow', '你有了新粉丝', ($u['nickname'] ?: $u['username']) . ' 关注了你', '/profile');
        }
        $cnt = (int)db()->query('SELECT COUNT(*) FROM follows WHERE followed_id = ' . $followedId)->fetchColumn();
        $out = ['ok' => true, 'following' => !$following, 'count' => $cnt];
        break;

    default:
        break;
}

json_out($out);
