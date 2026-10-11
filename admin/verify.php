<?php
/**
 * 后台 · 审核中心
 * 整合包审核（通过 / 驳回）+ 作者申请审核（通过 / 拒绝）
 */
require __DIR__ . '/_init.php';

$tab = in_array($_GET['tab'] ?? '', ['modpacks', 'authors'], true) ? $_GET['tab'] : 'modpacks';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $act = $_POST['action'] ?? '';
    if ($act === 'approve_modpack') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM modpacks WHERE id = ? AND status = 0');
        $st->execute([$id]);
        $m = $st->fetch();
        if ($m) {
            db()->prepare('UPDATE modpacks SET status = 1, reject_reason = "" WHERE id = ?')->execute([$id]);
            if ($m['user_id']) {
                notify((int)$m['user_id'], 0, 'approve', '你的整合包已通过审核', '《' . $m['title'] . '》已通过审核并上线展示', '/modpack/' . $m['slug']);
            }
            $msg = ['ok', '已通过审核'];
        }
    } elseif ($act === 'reject_modpack') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $st = db()->prepare('SELECT * FROM modpacks WHERE id = ? AND status = 0');
        $st->execute([$id]);
        $m = $st->fetch();
        if ($m) {
            db()->prepare('UPDATE modpacks SET status = 2, reject_reason = ? WHERE id = ?')->execute([$reason, $id]);
            if ($m['user_id']) {
                notify((int)$m['user_id'], 0, 'reject', '你的整合包被驳回', ($reason !== '' ? '驳回原因：' . $reason : '请修改后重新提交'), '/submit?edit=' . $id);
            }
            $msg = ['ok', '已驳回'];
        }
    } elseif ($act === 'approve_author') {
        $uid = (int)($_POST['uid'] ?? 0);
        $st = db()->prepare('SELECT id FROM users WHERE id = ? AND author_status = 1');
        $st->execute([$uid]);
        if ($st->fetch()) {
            db()->prepare('UPDATE users SET role = "author", author_status = 2 WHERE id = ?')->execute([$uid]);
            notify($uid, 0, 'author', '你已成为作者', '恭喜，你现在可以直接发布整合包，并管理自己的内容了', '/profile');
            $msg = ['ok', '已通过作者申请'];
        }
    } elseif ($act === 'reject_author') {
        $uid = (int)($_POST['uid'] ?? 0);
        $st = db()->prepare('SELECT id FROM users WHERE id = ? AND author_status = 1');
        $st->execute([$uid]);
        if ($st->fetch()) {
            db()->prepare('UPDATE users SET author_status = 3 WHERE id = ?')->execute([$uid]);
            notify($uid, 0, 'author', '作者申请未通过', '很遗憾，你的作者申请未通过，可在用户中心重新申请', '/profile#author');
            $msg = ['ok', '已拒绝作者申请'];
        }
    }
}

// 待审核整合包
$mpSt = db()->prepare(
    'SELECT m.*, u.nickname, u.username, c.name AS category_name
     FROM modpacks m
     LEFT JOIN users u ON u.id = m.user_id
     LEFT JOIN categories c ON c.id = m.category_id
     WHERE m.status = 0 ORDER BY m.created_at DESC LIMIT 200'
);
$mpSt->execute();
$pendingMps = $mpSt->fetchAll();

// 待审核作者
$auSt = db()->prepare('SELECT * FROM users WHERE author_status = 1 ORDER BY id DESC LIMIT 200');
$auSt->execute();
$pendingAuthors = $auSt->fetchAll();

$pageTitle = '审核中心';
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
      <a class="tab <?= $tab === 'modpacks' ? 'on' : '' ?>" href="verify.php?tab=modpacks">整合包审核<span class="count"> (<?= count($pendingMps) ?>)</span></a>
      <a class="tab <?= $tab === 'authors' ? 'on' : '' ?>" href="verify.php?tab=authors">作者申请<span class="count"> (<?= count($pendingAuthors) ?>)</span></a>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert <?= $msg[0] ?>"><?= e($msg[1]) ?></div><?php endif; ?>

  <?php if ($tab === 'modpacks'): ?>
  <section class="panel">
    <?php if (!$pendingMps): ?><div class="empty-row">暂无待审核的整合包。</div><?php endif; ?>
    <?php foreach ($pendingMps as $m): ?>
      <div class="verify-item">
        <?php if ($m['cover']): ?><img class="mini-thumb" src="<?= e(cover_url($m['cover'])) ?>" alt="">
        <?php else: ?><span class="mini-thumb" style="display:inline-flex;align-items:center;justify-content:center;background:var(--field)"><i class="fa-solid fa-cube"></i></span><?php endif; ?>
        <div class="verify-main">
          <div class="verify-title"><?= e($m['title']) ?>
            <?php if ($m['mc_version']): ?><small class="muted">MC <?= e($m['mc_version']) ?></small><?php endif; ?>
            <?php if (!empty($m['mod_loader'])): ?><small class="muted">· <?= e(loader_label($m['mod_loader'])) ?></small><?php endif; ?>
          </div>
          <div class="verify-meta">
            <span>发布者：<?= e($m['nickname'] ?: $m['username'] ?: '未登录用户') ?></span>
            <?php if ($m['category_name']): ?><span>分类：<?= e($m['category_name']) ?></span><?php endif; ?>
            <span>提交于 <?= e(fmt_date($m['created_at'])) ?></span>
          </div>
          <?php if (trim($m['tagline'])): ?><div class="verify-desc"><?= e(mb_strimwidth($m['tagline'], 0, 100, '…')) ?></div><?php endif; ?>
        </div>
        <div class="verify-ops">
          <?php if ($m['slug']): ?><a class="op" href="/modpack/<?= e($m['slug']) ?>" target="_blank">预览</a><?php endif; ?>
          <form method="post" action="verify.php?tab=modpacks" style="display:inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button class="op" name="action" value="approve_modpack" type="submit">通过</button>
          </form>
          <form method="post" action="verify.php?tab=modpacks" style="display:inline-flex;gap:6px">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <input type="text" name="reason" placeholder="驳回原因（可空）" class="reject-input">
            <button class="op danger" name="action" value="reject_modpack" type="submit">驳回</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
  <?php else: ?>
  <section class="panel">
    <?php if (!$pendingAuthors): ?><div class="empty-row">暂无待审核的作者申请。</div><?php endif; ?>
    <?php foreach ($pendingAuthors as $a): ?>
      <div class="verify-item">
        <?php if ($a['avatar']): ?><img class="mini-thumb" style="border-radius:50%" src="<?= e(cover_url($a['avatar'])) ?>" alt="">
        <?php else: ?><span class="mini-thumb" style="border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:var(--field)"><i class="fa-solid fa-user"></i></span><?php endif; ?>
        <div class="verify-main">
          <div class="verify-title"><?= e($a['nickname'] ?: $a['username']) ?> <small class="muted">@<?= e($a['username']) ?></small></div>
          <div class="verify-meta"><span><?= e($a['email']) ?></span></div>
          <?php if (trim($a['author_reason'])): ?><div class="verify-desc">申请理由：<?= e($a['author_reason']) ?></div><?php endif; ?>
        </div>
        <div class="verify-ops">
          <form method="post" action="verify.php?tab=authors" style="display:inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="uid" value="<?= (int)$a['id'] ?>">
            <button class="op" name="action" value="approve_author" type="submit">通过</button>
          </form>
          <form method="post" action="verify.php?tab=authors" style="display:inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="uid" value="<?= (int)$a['id'] ?>">
            <button class="op danger" name="action" value="reject_author" type="submit">拒绝</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
</main>
</body>
</html>
