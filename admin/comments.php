<?php
/**
 * 后台 · 评论管理
 */
require __DIR__ . '/_init.php';

$act = $_POST['action'] ?? '';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $cid = (int)($_POST['cid'] ?? 0);
    if ($act === 'hide') {
        db()->prepare('UPDATE comments SET status = 0 WHERE id = ?')->execute([$cid]);
        $msg = ['ok', '评论已隐藏'];
    } elseif ($act === 'show') {
        db()->prepare('UPDATE comments SET status = 1 WHERE id = ?')->execute([$cid]);
        $msg = ['ok', '评论已恢复显示'];
    } elseif ($act === 'pin') {
        db()->prepare('UPDATE comments SET is_pinned = 1 WHERE id = ?')->execute([$cid]);
        $msg = ['ok', '评论已置顶'];
    } elseif ($act === 'unpin') {
        db()->prepare('UPDATE comments SET is_pinned = 0 WHERE id = ?')->execute([$cid]);
        $msg = ['ok', '已取消置顶'];
    } elseif ($act === 'approve') {
        db()->prepare('UPDATE comments SET reviewed = 1, status = 1 WHERE id = ?')->execute([$cid]);
        $msg = ['ok', '评论已通过审核并展示'];
    } elseif ($act === 'reject') {
        db()->prepare('UPDATE comments SET reviewed = 1, status = 0 WHERE id = ?')->execute([$cid]);
        $msg = ['ok', '评论已驳回（隐藏）'];
    } elseif ($act === 'mute') {
        $uid = (int)($_POST['uid'] ?? 0);
        $muteDays = (int)($_POST['mute_days'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $append = ($_POST['append'] ?? '0') === '1';
        if ($uid > 0) {
            mute_user($uid, $muteDays > 0 ? $muteDays : null, $reason, $append);
            $why = $reason !== '' ? '禁言原因：' . $reason . '；' : '';
            notify($uid, 0, 'mute', '你已被禁言', $why . ($muteDays > 0 ? ($append ? '你已被追加禁言 ' . $muteDays . ' 天' : '你已被禁言 ' . $muteDays . ' 天') : '你的账号已被永久禁言'), '/profile');
            $msg = ['ok', $muteDays > 0 ? (($append ? '已追加' : '已') . '禁言该用户 ' . $muteDays . ' 天') : '已永久禁言该用户'];
        }
    } elseif ($act === 'del') {
        db()->prepare('DELETE FROM comments WHERE id = ?')->execute([$cid]);
        $msg = ['ok', '评论已删除'];
    }
}

$tab = (($_GET['tab'] ?? 'review') === 'all') ? 'all' : 'review';
$modOn = (int)setting('comment_moderation', 0) === 1;

$reviewCnt = (int)db()->query('SELECT COUNT(*) FROM comments WHERE reviewed = 0')->fetchColumn();

$sql = 'SELECT c.*, u.username AS uname, u.nickname, m.title AS mp_title, m.slug AS mp_slug
        FROM comments c
        LEFT JOIN users u ON u.id = c.user_id
        LEFT JOIN modpacks m ON m.id = c.modpack_id';
if ($tab === 'review') $sql .= ' WHERE c.reviewed = 0';
$sql .= ' ORDER BY c.id DESC LIMIT 300';
$st = db()->prepare($sql);
$st->execute();
$comments = $st->fetchAll();

$pageTitle = '评论管理';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= e($adminTheme ?? 'emerald') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · 管理后台</title>
<link rel="stylesheet" href="assets/admin.css">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<main class="content">
  <div class="page-head">
    <h1><?= e($pageTitle) ?></h1>
    <div class="tabs">
      <a class="tab <?= $tab === 'review' ? 'on' : '' ?>" href="comments.php?tab=review">待审核<span class="count"> (<?= $reviewCnt ?>)</span></a>
      <a class="tab <?= $tab === 'all' ? 'on' : '' ?>" href="comments.php?tab=all">全部<span class="count"> (<?= count($comments) ?>)</span></a>
    </div>
  </div>
  <?php if ($modOn): ?><div class="alert ok">评论审核已开启：新评论需在「待审核」中通过后才在前台展示。<a class="link" href="settings.php">前往设置</a></div>
  <?php else: ?><div class="alert err">评论审核已关闭：新评论直接展示。可在<a class="link" href="settings.php">站点设置</a>中开启评论审核。</div><?php endif; ?>

  <?php if ($msg): ?><div class="alert <?= $msg[0] ?>"><?= e($msg[1]) ?></div><?php endif; ?>

  <section class="panel">
    <table class="table">
      <thead><tr><th>ID</th><th>用户</th><th>整合包</th><th>评论内容</th><th>状态</th><th>时间</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($comments as $c): ?>
        <tr>
          <td><?= (int)$c['id'] ?></td>
          <td><?= e($c['nickname'] ?: $c['uname']) ?></td>
          <td><a href="../detail.php?slug=<?= e($c['mp_slug']) ?>" target="_blank" class="link"><?= e($c['mp_title']) ?></a></td>
          <td style="max-width:340px"><?= e(mb_strimwidth($c['content'], 0, 120, '…')) ?></td>
          <td>
            <?php if ((int)$c['reviewed'] === 0): ?><span class="pill gray">待审核</span>
            <?php else: ?><span class="pill <?= (int)$c['status'] === 1 ? 'green' : 'gray' ?>"><?= (int)$c['status'] === 1 ? '显示' : '隐藏' ?></span><?php endif; ?>
            <?= (int)$c['is_pinned'] === 1 ? ' <span class="pill blue">置顶</span>' : '' ?>
          </td>
          <td><?= e(fmt_date($c['created_at'])) ?></td>
          <td>
            <form method="post" action="comments.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="cid" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="uid" value="<?= (int)$c['user_id'] ?>">
              <?php if ((int)$c['reviewed'] === 0): ?>
                <button class="op" name="action" value="approve" type="submit">通过</button>
                <button class="op danger" name="action" value="reject" type="submit" onclick="return confirm('确定驳回该评论？驳回后不展示')">驳回</button>
              <?php elseif ((int)$c['status'] === 1): ?>
                <button class="op" name="action" value="hide" type="submit">隐藏</button>
              <?php else: ?>
                <button class="op" name="action" value="show" type="submit">显示</button>
              <?php endif; ?>
              <button class="op" name="action" value="<?= (int)$c['is_pinned'] === 1 ? 'unpin' : 'pin' ?>" type="submit"><?= (int)$c['is_pinned'] === 1 ? '取消置顶' : '置顶' ?></button>
              <input type="text" name="reason" placeholder="禁言原因（可空）" class="reject-input" style="max-width:110px">
              <select name="mute_days" style="vertical-align:middle">
                <option value="1">1天</option>
                <option value="3">3天</option>
                <option value="7">7天</option>
                <option value="30">30天</option>
                <option value="0">永久</option>
              </select>
              <label style="font-size:12px"><input type="checkbox" name="append" value="1">追加</label>
              <button class="op" name="action" value="mute" type="submit">禁言</button>
              <button class="op danger" name="action" value="del" type="submit" onclick="return confirm('确定删除该评论？')">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$comments): ?><tr><td colspan="7" class="empty-row">还没有评论。</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
</main>
</body>
</html>
