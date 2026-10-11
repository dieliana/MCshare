<?php
/**
 * 前台 · 我的发布（作者 / 用户管理自己的整合包）
 */
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';
ensure_session();
maintenance_check();
if (!is_logged()) { redirect('/login'); }
$u = current_user();
if (!$u) { redirect('/login'); }
$uid = (int)$u['id'];

// 删除自己的整合包
if (isset($_GET['del'])) {
    $mid = (int)$_GET['del'];
    $okDel = false;
    $ck = db()->prepare('SELECT id FROM modpacks WHERE id = ? AND user_id = ?');
    $ck->execute([$mid, $uid]);
    if ($ck->fetch() || is_admin($u)) {
        db()->prepare('DELETE FROM modpack_tags WHERE modpack_id = ?')->execute([$mid]);
        db()->prepare('DELETE FROM comments WHERE modpack_id = ?')->execute([$mid]);
        db()->prepare('DELETE FROM comment_likes WHERE comment_id IN (SELECT id FROM comments WHERE modpack_id = ?)')->execute([$mid]);
        db()->prepare('DELETE FROM modpack_likes WHERE modpack_id = ?')->execute([$mid]);
        db()->prepare('DELETE FROM favorites WHERE modpack_id = ?')->execute([$mid]);
        db()->prepare('DELETE FROM modpacks WHERE id = ?')->execute([$mid]);
        $okDel = true;
    }
    redirect('/my-modpacks' . ($okDel ? '?deleted=1' : '?deleted=0'));
}

$delMsg = '';
if (isset($_GET['deleted'])) {
    $delMsg = $_GET['deleted'] === '1' ? ['ok', '整合包已删除'] : ['err', '无法删除该整合包'];
}

$st = db()->prepare(
    'SELECT m.*, c.name AS category_name FROM modpacks m LEFT JOIN categories c ON c.id = m.category_id WHERE m.user_id = ? ORDER BY m.id DESC'
);
$st->execute([$uid]);
$mps = $st->fetchAll();

$statusMap = [0 => ['待审核', 'warn'], 1 => ['已发布', 'ok'], 2 => ['已驳回', 'err']];

$pageTitle = '我的发布';
include __DIR__ . '/includes/site_head.php';
?>
<main class="wrap">
  <div class="publish-page">
    <div class="publish-topbar">
      <h1>我的发布 <span class="dim">（<?= count($mps) ?>）</span></h1>
      <div>
        <a class="btn ghost" href="/submit"><i class="fa-solid fa-plus"></i> 发布整合包</a>
        <a class="btn ghost" href="/profile"><i class="fa-solid fa-user"></i> 用户中心</a>
      </div>
    </div>

    <?php if ($delMsg): ?><div class="msg-box <?= $delMsg[0] ?>"><?= e($delMsg[1]) ?></div><?php endif; ?>

    <?php if (!$mps): ?>
      <p class="comment-empty">你还没有发布过整合包，<a href="/submit">去发布一个吧</a>。</p>
    <?php else: ?>
    <div class="mp-admin-list">
      <?php foreach ($mps as $m): ?>
      <div class="mp-admin-item">
        <?php if ($m['cover']): ?><img class="mini-thumb" src="<?= e(cover_url($m['cover'])) ?>" alt=""><?php endif; ?>
        <div class="mp-admin-main">
          <div class="mp-admin-title">
            <?= e($m['title']) ?>
            <?php $sm = $statusMap[(int)$m['status']] ?? ['未知', 'err']; ?>
            <span class="status-chip <?= $sm[1] ?>"><?= e($sm[0]) ?></span>
          </div>
          <div class="mp-admin-meta">
            <span><?= icon('eye', 13) ?> <?= num($m['views']) ?> 浏览</span>
            <span><?= icon('download', 13) ?> <?= num($m['downloads']) ?> 下载</span>
            <span>发布 <?= e(fmt_date($m['created_at'])) ?></span>
            <?php if ((int)$m['status'] === 2 && $m['reject_reason']): ?>
              <span class="reject-reason">驳回原因：<?= e($m['reject_reason']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="mp-admin-ops">
          <?php if ((int)$m['status'] === 1): ?><a class="op" href="/modpack/<?= e($m['slug']) ?>" target="_blank">查看</a><?php endif; ?>
          <a class="op" href="/submit?edit=<?= (int)$m['id'] ?>">编辑</a>
          <a class="op danger" href="/my-modpacks?del=<?= (int)$m['id'] ?>" onclick="return confirm('确定删除「<?= e($m['title']) ?>」？删除后不可恢复')">删除</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
<script src="/assets/js/app.js"></script>
</body>
</html>
