<?php
/**
 * 前台 · 站内信中心（评论回复 / 点赞 / 置顶 / 整合包更新 / 系统消息）
 */
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';
ensure_session();

// ---- AJAX 接口（未登录也可安全返回，供顶部铃铛轮询红点用）----
if (($_GET['api'] ?? '') === 'unread') {
    header('Content-Type: application/json; charset=utf-8');
    if (!is_logged()) { echo json_encode(['ok' => false, 'logged' => false, 'unread' => 0]); exit; }
    $me = current_user();
    echo json_encode(['ok' => true, 'logged' => true, 'unread' => $me ? unread_count((int)$me['id']) : 0]);
    exit;
}

if (!is_logged()) { redirect('/login'); }
$u = current_user();
if (!$u) { redirect('/login'); }
$uid = (int)$u['id'];

// 全部标为已读
if (($_GET['all'] ?? '') === 'read') {
    db()->prepare('UPDATE messages SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
    redirect('/messages');
}
// 单条标为已读
if (isset($_GET['read'])) {
    $mid = (int)$_GET['read'];
    db()->prepare('UPDATE messages SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$mid, $uid]);
    redirect('/messages');
}
// 删除单条
if (isset($_GET['del'])) {
    $mid = (int)$_GET['del'];
    db()->prepare('DELETE FROM messages WHERE id = ? AND user_id = ?')->execute([$mid, $uid]);
    redirect('/messages');
}
// AJAX：单条标为已读（弹窗打开时调用）
if (($_GET['api'] ?? '') === 'read') {
    $mid = (int)($_GET['id'] ?? 0);
    if ($mid > 0) db()->prepare('UPDATE messages SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$mid, $uid]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 20;
$total = (int)db()->query('SELECT COUNT(*) FROM messages WHERE user_id=' . $uid)->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$off = ($page - 1) * $per;
$st = db()->prepare(
    'SELECT m.*, u.nickname AS f_nick, u.username AS f_user
     FROM messages m LEFT JOIN users u ON u.id = m.from_user_id
     WHERE m.user_id = ? ORDER BY m.is_read ASC, m.created_at DESC LIMIT ' . $per . ' OFFSET ' . $off
);
$st->execute([$uid]);
$msgs = $st->fetchAll();

$typeIcon  = ['reply' => 'fa-reply', 'like' => 'fa-heart', 'pin' => 'fa-thumbtack', 'update' => 'fa-rotate', 'approve' => 'fa-circle-check', 'reject' => 'fa-circle-xmark', 'author' => 'fa-pen-nib', 'mute' => 'fa-ban', 'follow' => 'fa-user-plus', 'role' => 'fa-user-shield', 'mp_like' => 'fa-heart', 'mp_fav' => 'fa-star', 'daily' => 'fa-sun', 'appeal' => 'fa-gavel', 'system' => 'fa-bell'];
$typeLabel = ['reply' => '评论回复', 'like' => '评论点赞', 'pin' => '评论置顶', 'update' => '整合包更新', 'approve' => '审核通过', 'reject' => '被驳回', 'author' => '作者', 'mute' => '禁言', 'follow' => '新粉丝', 'role' => '角色变更', 'mp_like' => '整合包被赞', 'mp_fav' => '被收藏', 'daily' => '每日总结', 'appeal' => '申诉', 'system' => '系统消息'];

$pageTitle = '站内信';
include __DIR__ . '/includes/site_head.php';
?>
<main class="wrap">
  <div class="msg-page">
    <div class="msg-topbar">
      <h1>站内信 <span class="dim">（<?= $total ?> 条）</span></h1>
      <div class="msg-top-actions">
        <a class="btn ghost" href="/messages?all=read"><i class="fa-solid fa-check-double"></i> 全部已读</a>
        <a class="btn ghost" href="/profile"><i class="fa-solid fa-user"></i> 用户中心</a>
      </div>
    </div>

    <div class="msg-list">
      <?php if (!$msgs): ?><p class="comment-empty">暂无站内信。</p><?php endif; ?>
      <?php foreach ($msgs as $m): ?>
      <div class="msg-item<?= $m['is_read'] ? '' : ' unread' ?>" data-id="<?= (int)$m['id'] ?>" data-title="<?= e($m['title']) ?>" data-content="<?= e($m['content']) ?>" data-from="<?= e($m['f_nick'] ?: $m['f_user'] ?: '系统') ?>" data-time="<?= e(fmt_date($m['created_at'])) ?>" data-link="<?= e($m['link']) ?>" data-read="<?= (int)$m['is_read'] ?>" onclick="openMsg(this)">
        <div class="msg-icon"><i class="fa-solid <?= e($typeIcon[$m['type']] ?? 'fa-bell') ?>"></i></div>
        <div class="msg-body">
          <div class="msg-title">
            <span class="msg-type"><?= e($typeLabel[$m['type']] ?? '系统') ?></span>
            <?= e($m['title']) ?>
            <?php if (!$m['is_read']): ?><span class="msg-new">新</span><?php endif; ?>
          </div>
          <?php if ($m['content']): ?><div class="msg-content"><?= e(mb_strimwidth($m['content'], 0, 60, '…')) ?></div><?php endif; ?>
          <div class="msg-meta">
            <span><i class="fa-solid fa-user"></i> <?= e($m['f_nick'] ?: $m['f_user'] ?: '系统') ?></span>
            <span><?= e(fmt_date($m['created_at'])) ?></span>
            <?php if (!$m['is_read']): ?><span class="msg-hint">点击查看详情</span><?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
    <div class="pager">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a class="page-num <?= $i === $page ? 'on' : '' ?>" href="/messages?page=<?= $i ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
<div id="msgModal" class="mute-modal" style="display:none">
  <div class="mute-modal-box msg-modal-box">
    <div class="msg-modal-head">
      <span class="msg-type">站内信</span>
      <button type="button" class="msg-modal-close" onclick="closeMsg()">&times;</button>
    </div>
    <h3 id="msgMTitle" class="msg-modal-title"></h3>
    <p id="msgMContent" class="msg-modal-content"></p>
    <div id="msgMMeta" class="msg-modal-meta"></div>
    <div class="mute-modal-actions">
      <button type="button" class="btn ghost" onclick="closeMsg()">关闭</button>
      <a id="msgMLink" class="btn" href="#" target="_blank" style="display:none">查看详情</a>
    </div>
  </div>
</div>
<script src="/assets/js/app.js"></script>
<script>
function openMsg(el) {
  var id = el.getAttribute('data-id');
  document.getElementById('msgMTitle').textContent = el.getAttribute('data-title');
  document.getElementById('msgMContent').textContent = el.getAttribute('data-content');
  document.getElementById('msgMMeta').textContent = '来自 ' + el.getAttribute('data-from') + ' · ' + el.getAttribute('data-time');
  var link = el.getAttribute('data-link');
  var lbtn = document.getElementById('msgMLink');
  if (link) { lbtn.style.display = 'inline-block'; lbtn.setAttribute('href', link); }
  else { lbtn.style.display = 'none'; }
  document.getElementById('msgModal').style.display = 'flex';
  if (el.getAttribute('data-read') !== '1') {
    el.classList.remove('unread');
    el.setAttribute('data-read', '1');
    var n = el.querySelector('.msg-new'); if (n) n.remove();
    var h = el.querySelector('.msg-hint'); if (h) h.remove();
    fetch('messages.php?api=read&id=' + id).then(function (r) { return r.json(); });
  }
}
function closeMsg() { document.getElementById('msgModal').style.display = 'none'; }
</script>
</body>
</html>
