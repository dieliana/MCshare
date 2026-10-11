<?php
/**
 * 后台 · 禁言申诉管理
 * 通过：解除禁言；驳回：填写驳回原因并通知用户
 */
require __DIR__ . '/_init.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $aid = (int)($_POST['aid'] ?? 0);
    $act = $_POST['action'] ?? '';
    if ($aid > 0) {
        $a = db()->prepare('SELECT user_id FROM appeals WHERE id = ?');
        $a->execute([$aid]);
        $app = $a->fetch();
        if ($app) {
            $uid = (int)$app['user_id'];
            if ($act === 'approve') {
                // 解除禁言
                db()->prepare('UPDATE users SET muted = 0, muted_until = NULL, mute_reason = "" WHERE id = ?')->execute([$uid]);
                db()->prepare('UPDATE appeals SET status = 1, handled_at = NOW() WHERE id = ?')->execute([$aid]);
                notify($uid, 0, 'appeal', '申诉通过，禁言已解除', '管理员已处理你的申诉，禁言已解除，可以正常评论和发布了', '/profile');
                $msg = ['ok', '申诉已通过，该用户禁言已解除'];
            } elseif ($act === 'reject') {
                $reply = trim($_POST['reply'] ?? '');
                db()->prepare('UPDATE appeals SET status = 2, reply = ?, handled_at = NOW() WHERE id = ?')->execute([$reply, $aid]);
                notify($uid, 0, 'appeal', '申诉被驳回', $reply !== '' ? '管理员驳回了你的申诉：' . $reply : '管理员驳回了你的申诉', '/profile');
                $msg = ['ok', '申诉已驳回'];
            }
        }
    }
}

$tab = (($_GET['tab'] ?? 'pending') === 'all') ? 'all' : 'pending';
$pendingCnt = (int)db()->query('SELECT COUNT(*) FROM appeals WHERE status = 0')->fetchColumn();

$sql = 'SELECT a.*, u.username AS uname, u.nickname, u.muted, u.muted_until
        FROM appeals a LEFT JOIN users u ON u.id = a.user_id';
if ($tab === 'pending') $sql .= ' WHERE a.status = 0';
$sql .= ' ORDER BY a.id DESC LIMIT 300';
$st = db()->prepare($sql);
$st->execute();
$appeals = $st->fetchAll();

$pageTitle = '申诉管理';
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
    <h1><i class="fa-solid fa-gavel"></i> <?= e($pageTitle) ?></h1>
    <a class="btn ghost <?= $tab === 'pending' ? 'disabled' : '' ?>" href="appeals.php?tab=pending">待处理 <?= $pendingCnt ? "($pendingCnt)" : '' ?></a>
    <a class="btn ghost <?= $tab === 'all' ? 'disabled' : '' ?>" href="appeals.php?tab=all">全部</a>
  </div>

  <?php if ($msg): ?><div class="alert <?= $msg[0] ?>"><?= e($msg[1]) ?></div><?php endif; ?>

  <table class="table">
    <thead><tr><th>ID</th><th>申诉用户</th><th>申诉内容</th><th>状态</th><th>提交时间</th><th>处理</th></tr></thead>
    <tbody>
      <?php foreach ($appeals as $a): ?>
        <tr>
          <td><?= (int)$a['id'] ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <?php if ($a['nickname']): ?><span class="pill <?= (int)$a['muted'] === 1 ? 'gray' : 'green' ?>"><?= e($a['nickname']) ?></span><?php endif; ?>
              @<?= e($a['uname'] ?: ('用户#' . (int)$a['user_id'])) ?>
            </div>
          </td>
          <td style="max-width:340px">
            <div style="white-space:pre-wrap;word-break:break-word"><?= e($a['content']) ?></div>
            <?php if (trim($a['reply'])): ?><div class="muted" style="margin-top:4px;font-size:12px">处理回复：<?= e($a['reply']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if ((int)$a['status'] === 0): ?><span class="pill gray">待处理</span>
            <?php elseif ((int)$a['status'] === 1): ?><span class="pill green">已通过（解除禁言）</span>
            <?php else: ?><span class="pill red">已驳回</span><?php endif; ?>
          </td>
          <td><?= e(fmt_date($a['created_at'])) ?></td>
          <td>
            <?php if ((int)$a['status'] === 0): ?>
              <form method="post" action="appeals.php" style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="aid" value="<?= (int)$a['id'] ?>">
                <input type="text" name="reply" placeholder="驳回原因（可空）" class="reject-input">
                <button class="op" name="action" value="approve" type="submit">通过并解除禁言</button>
                <button class="op danger" name="action" value="reject" type="submit">驳回</button>
              </form>
            <?php else: ?><span class="muted">已处理</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$appeals): ?><tr><td colspan="6" class="empty-row"><?= $tab === 'pending' ? '没有待处理的申诉。' : '暂无申诉记录。' ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</main>
</body>
</html>
