<?php
/**
 * 前台 · 整合包详情
 */
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/plugins.php';
load_plugins();

ensure_session();
maintenance_check();
$u = current_user();

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: index.php');
    exit;
}

$st = db()->prepare('SELECT m.*, c.name AS category_name FROM modpacks m LEFT JOIN categories c ON c.id = m.category_id WHERE m.slug = ? AND m.status = 1');
$st->execute([$slug]);
$modpack = $st->fetch();

$siteName = setting('site_name', defined('SITE_NAME') ? SITE_NAME : 'MC Modpack Hub');
$theme    = setting('theme', 'emerald');
$logo     = setting('logo', '');
$favicon  = setting('favicon', '');

$notFound = false;
if (!$modpack) {
    http_response_code(404);
    $notFound = true;
} else {
    // 浏览量 +1
    db()->prepare('UPDATE modpacks SET views = views + 1 WHERE id = ?')->execute([$modpack['id']]);

    // 下载项
    $downloads = get_modpack_downloads((int)$modpack['id']);

    $tagSt = db()->prepare(
        'SELECT t.id, t.name FROM tags t JOIN modpack_tags mt ON mt.tag_id = t.id WHERE mt.modpack_id = ? ORDER BY t.id ASC'
    );
    $tagSt->execute([$modpack['id']]);
    $tags = $tagSt->fetchAll();

    // 相关整合包
    $relSt = db()->prepare(
        'SELECT m.id, m.slug, m.title, m.cover, m.tagline FROM modpacks m WHERE m.status = 1 AND m.id != ? ' .
        'AND (? IS NULL OR m.category_id = ?) ORDER BY m.views DESC, m.id DESC LIMIT 4'
    );
    $relSt->execute([$modpack['id'], $modpack['category_id'], $modpack['category_id']]);
    $related = $relSt->fetchAll();

    // 用户状态与点赞/收藏
    $uId = $u ? (int)$u['id'] : 0;
    $liked = false;
    $favd  = false;
    $likeCount = 0;

    $lc = db()->prepare('SELECT COUNT(*) FROM modpack_likes WHERE modpack_id = ?');
    $lc->execute([$modpack['id']]);
    $likeCount = (int)$lc->fetchColumn();

    // 关注作者
    $authorId  = (int)($modpack['user_id'] ?? 0);
    $following = false;
    $fans      = 0;
    if ($authorId > 0) {
        $fans = follower_count($authorId);
        if ($uId) $following = is_following($uId, $authorId);
    }

    if ($uId) {
        $ckL = db()->prepare('SELECT 1 FROM modpack_likes WHERE user_id = ? AND modpack_id = ?');
        $ckL->execute([$uId, $modpack['id']]);
        $liked = (bool)$ckL->fetch();
        $ckF = db()->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND modpack_id = ?');
        $ckF->execute([$uId, $modpack['id']]);
        $favd = (bool)$ckF->fetch();
    }

    // 评论提交（含回复）
    $commentMsg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'comment') {
        if (!$u) {
            $commentMsg = '请先登录后再评论';
        } elseif (is_muted($u)) {
            $commentMsg = '你已被禁言，暂时不能发表评论';
        } else {
            $content = trim($_POST['content'] ?? '');
            $clen = mb_strlen($content);
            $parentId = (int)($_POST['parent_id'] ?? 0);
            if ($content === '' || $clen < 2 || $clen > 500) {
                $commentMsg = '评论内容需 2-500 字';
            } else {
                $parent = null;
                if ($parentId) {
                    $pc = db()->prepare('SELECT id, user_id FROM comments WHERE id = ? AND modpack_id = ? AND status = 1');
                    $pc->execute([$parentId, $modpack['id']]);
                    $parent = $pc->fetch();
                    if (!$parent) $parentId = 0;
                }
                $modOn = (int)setting('comment_moderation', 0) === 1;
                db()->prepare('INSERT INTO comments (modpack_id, user_id, parent_id, content, reviewed) VALUES (?,?,?,?,?)')
                    ->execute([$modpack['id'], $uId, $parentId, $content, $modOn ? 0 : 1]);
                if ($modOn) $commentMsg = 'ok:评论已提交，待管理员审核通过后展示';
                $newId = (int)db()->lastInsertId();
                if ($parentId && $parent && (int)$parent['user_id'] !== $uId) {
                    notify((int)$parent['user_id'], $uId, 'reply', '有人回复了你的评论',
                        ($u['nickname'] ?: $u['username']) . '：' . mb_substr($content, 0, 40),
                        '/modpack/' . $modpack['slug'] . '#comment-' . $newId);
                }
                $commentMsg = 'ok:评论已发表';
            }
        }
    }

    // 评论列表（顶层，置顶优先）+ 回复 + 点赞状态
    $cs = db()->prepare(
        'SELECT c.*, u.nickname, u.username, u.avatar, m.user_id AS mp_user_id,
                (SELECT COUNT(*) FROM comments r WHERE r.parent_id = c.id AND r.status = 1 AND r.reviewed = 1) AS reply_count,
                (SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = c.id) AS like_count
         FROM comments c
         JOIN users u ON u.id = c.user_id
         LEFT JOIN modpacks m ON m.id = c.modpack_id
         WHERE c.modpack_id = ? AND c.status = 1 AND c.reviewed = 1 AND c.parent_id = 0
         ORDER BY c.is_pinned DESC, c.created_at DESC LIMIT 100'
    );
    $cs->execute([$modpack['id']]);
    $comments = $cs->fetchAll();

    // 回复列表（按顶层评论 id 分组）
    $replies = [];
    $myLikedCids = [];
    if ($comments) {
        $ids = array_map(fn($c) => (int)$c['id'], $comments);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rs = db()->prepare(
            'SELECT c.*, u.nickname, u.username, u.avatar, m.user_id AS mp_user_id,
                    (SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = c.id) AS like_count
             FROM comments c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN modpacks m ON m.id = c.modpack_id
             WHERE c.parent_id IN (' . $ph . ') AND c.status = 1 AND c.reviewed = 1 ORDER BY c.created_at ASC LIMIT 200'
        );
        $rs->execute($ids);
        foreach ($rs->fetchAll() as $r) $replies[$r['parent_id']][] = $r;

        if ($uId) {
            $allIds = $ids;
            foreach ($replies as $lst) foreach ($lst as $r) $allIds[] = (int)$r['id'];
            $ph2 = implode(',', array_fill(0, count($allIds), '?'));
            $ls = db()->prepare('SELECT comment_id FROM comment_likes WHERE user_id = ? AND comment_id IN (' . $ph2 . ')');
            $ls->execute(array_merge([$uId], $allIds));
            foreach ($ls->fetchAll() as $l) $myLikedCids[(int)$l['comment_id']] = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= e($theme ?: 'emerald') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $notFound ? '未找到' : e($modpack['title']) ?> · <?= e($siteName ?? 'MC Modpack Hub') ?></title>
<meta name="description" content="<?= $notFound ? '' : e($modpack['tagline']) ?>">
<?php if ($favicon): ?><link rel="icon" href="<?= e(cover_url($favicon)) ?>"><?php endif; ?>
<link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/fontawesome/css/all.min.css">
<?php wp_head(); ?>
</head>
<body>
<div class="bg-glow"></div>

<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="/">
      <?php if ($logo): ?>
        <img class="brand-logo" src="<?= e(cover_url($logo)) ?>" alt="<?= e($siteName ?? 'MC Modpack Hub') ?>">
      <?php else: ?>
        <span class="brand-icon"><i class="fa-solid fa-cube"></i></span>
      <?php endif; ?>
      <span class="brand-name"><?= e($siteName ?? 'MC Modpack Hub') ?></span>
    </a>
    <div style="flex:1"></div>
    <button type="button" class="theme-btn" title="切换主题" onclick="window.cycleTheme && window.cycleTheme()"><i class="fa-solid fa-palette"></i></button>
    <?php if ($u): ?>
      <div class="user-menu" onclick="this.classList.toggle('open')">
        <?php if ($u['avatar']): ?><img class="avatar" src="<?= e(cover_url($u['avatar'])) ?>" alt="头像">
        <?php else: ?><span class="avatar" style="display:inline-flex;align-items:center;justify-content:center;background:var(--bg2);font-size:16px"><i class="fa-solid fa-user"></i></span><?php endif; ?>
        <span><?= e($u['nickname'] ?: $u['username']) ?></span>
        <div class="user-drop"><a href="/profile"><i class="fa-solid fa-user"></i> 用户中心</a><a href="/logout"><i class="fa-solid fa-right-from-bracket"></i> 退出登录</a></div>
      </div>
    <?php else: ?>
      <a class="btn-admin" href="/login">登录</a>
      <a class="btn-admin" href="/register">注册</a>
    <?php endif; ?>
    <a class="btn-admin" href="/">← 返回列表</a>
  </div>
</header>

<main class="wrap">
<?php if ($notFound): ?>
  <div class="empty" style="margin-top:60px;">没有找到这个整合包，它可能已被下架或删除。</div>
  <p style="text-align:center"><a class="btn-admin" href="/">返回首页</a></p>
<?php else: ?>

  <article class="detail">
    <div class="detail-cover">
      <?php if ($modpack['cover']): ?>
        <img src="<?= e(cover_url($modpack['cover'])) ?>" alt="<?= e($modpack['title']) ?>">
      <?php else: ?>
        <div class="thumb-ph big"><i class="fa-solid fa-cube"></i></div>
      <?php endif; ?>
    </div>

    <div class="detail-head">
      <h1><?= e($modpack['title']) ?></h1>
      <?php if ($modpack['tagline']): ?><p class="detail-tagline"><?= e($modpack['tagline']) ?></p><?php endif; ?>

      <div class="detail-meta">
        <?php if ($modpack['category_name']): ?><span class="chip-static">分类：<?= e($modpack['category_name']) ?></span><?php endif; ?>
        <span class="chip-static <?= ($modpack['source_type'] ?? 'original') === 'repost' ? 'loader-chip' : 'chip-orig' ?>"><?= ($modpack['source_type'] ?? 'original') === 'repost' ? '<i class="fa-solid fa-share"></i> 转载' . ($modpack['source_name'] ? '（' . e($modpack['source_name']) . '）' : '') : '<i class="fa-solid fa-pen-nib"></i> 原创' ?></span>
        <?php if ($modpack['mc_version']): ?><span class="chip-static">MC 版本：<?= e($modpack['mc_version']) ?></span><?php endif; ?>
        <?php if (!empty($modpack['mod_loader'])): ?><span class="chip-static loader-chip"><i class="fa-solid fa-gear"></i> 加载器：<?= e(loader_label($modpack['mod_loader'])) ?></span><?php endif; ?>
        <?php if ($modpack['author']): ?><span class="chip-static">作者：<?= e($modpack['author']) ?></span><?php endif; ?>
        <span class="chip-static">发布：<?= e(fmt_date($modpack['created_at'])) ?></span>
        <span class="chip-static"><?= icon('eye', 14) ?> <?= num($modpack['views']) ?> 浏览</span>
        <span class="chip-static"><?= icon('download', 14) ?> <?= num($modpack['downloads']) ?> 下载</span>
      </div>

      <?php
        $links = parse_author_links($modpack['author_links'] ?? '');
        $hasLinks = array_filter($links, fn($v) => $v !== '');
        $linkTitles = ['github' => 'GitHub', 'bilibili' => 'Bilibili', 'douyin' => '抖音', 'website' => '个人网站'];
      ?>
      <?php if ($hasLinks): ?>
      <div class="author-links">
        <span class="author-label">作者主页：</span>
        <?php foreach ($links as $type => $url): if ($url === '') continue; ?>
          <a class="social-link" href="<?= e($url) ?>" target="_blank" rel="noopener nofollow" title="<?= e($linkTitles[$type] ?? $type) ?>">
            <?= icon($type) ?><span><?= e($linkTitles[$type] ?? $type) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($authorId > 0): ?>
      <div class="author-follow">
        <span class="author-follow-info"><i class="fa-solid fa-users"></i> 粉丝 <b id="fansCount"><?= $fans ?></b></span>
        <?php if ($uId && $uId !== $authorId): ?>
          <a href="javascript:void(0)" class="follow-btn <?= $following ? 'active' : '' ?>" id="followBtn" onclick="toggleFollow(<?= $authorId ?>)">
            <i class="fa-solid <?= $following ? 'fa-user-check' : 'fa-user-plus' ?>"></i> <span id="followTxt"><?= $following ? '已关注' : '关注作者' ?></span>
          </a>
        <?php elseif (!$uId): ?>
          <a class="follow-btn" href="/login"><i class="fa-solid fa-user-plus"></i> 关注作者</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php $dlCount = count($downloads); if ($dlCount > 0 || $modpack['download_url']): ?>
      <div class="detail-actions">
        <?php if ($dlCount > 1): ?>
          <?php foreach ($downloads as $d): ?>
            <a class="btn-download" href="/download/<?= e($modpack['slug']) ?>/<?= (int)$d['id'] ?>" target="_blank" rel="noopener"><?= icon('download', 18) ?> <?= e($d['label'] ?: '下载') ?></a>
          <?php endforeach; ?>
        <?php else: ?>
          <a class="btn-download" href="/download/<?= e($modpack['slug']) ?>" target="_blank" rel="noopener"><?= icon('download', 18) ?> <?= $dlCount === 1 && $downloads[0]['label'] ? e($downloads[0]['label']) : '下载整合包' ?></a>
        <?php endif; ?>
        <span class="hint">点击下载会自动统计下载次数<?= $dlCount > 1 ? '，每个下载源单独统计' : '' ?></span>
        <a href="javascript:void(0)" class="like-btn <?= $liked ? 'active' : '' ?>" id="likeBtn" onclick="toggleLike(<?= (int)$modpack['id'] ?>)"><i class="fa-solid fa-heart"></i> 点赞 <span class="count" id="likeCount"><?= $likeCount ?></span></a>
        <a href="javascript:void(0)" class="fav-btn <?= $favd ? 'active' : '' ?>" id="favBtn" onclick="toggleFav(<?= (int)$modpack['id'] ?>)"><i class="fa-solid fa-star"></i> 收藏</a>
      </div>
      <?php else: ?>
      <div class="detail-actions">
        <span class="btn ghost" style="cursor:default;opacity:.6">暂未提供下载</span>
        <a href="javascript:void(0)" class="like-btn <?= $liked ? 'active' : '' ?>" id="likeBtn" onclick="toggleLike(<?= (int)$modpack['id'] ?>)"><i class="fa-solid fa-heart"></i> 点赞 <span class="count" id="likeCount"><?= $likeCount ?></span></a>
        <a href="javascript:void(0)" class="fav-btn <?= $favd ? 'active' : '' ?>" id="favBtn" onclick="toggleFav(<?= (int)$modpack['id'] ?>)"><i class="fa-solid fa-star"></i> 收藏</a>
      </div>
      <?php endif; ?>

    <?php if ($tags): ?>
    <div class="detail-tags">
      <?php foreach ($tags as $tg): ?>
        <a class="tag" href="index.php?tag=<?= e(urlencode($tg['name'])) ?>">#<?= e($tg['name']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (trim($modpack['content'])): ?>
    <div class="detail-content">
      <h2>整合包介绍</h2>
      <div class="content-body"><?= rich_html(do_shortcode($modpack['content'])) ?></div>
      <?php do_action('mc_detail_after_content', $modpack); ?>
    </div>
    <?php endif; ?>
    </article>

  <section class="comments" id="comments">
    <h2>评论（<?= count($comments) ?>）</h2>
    <?php if ($commentMsg): ?>
      <div class="msg-box <?= strpos($commentMsg, 'ok:') === 0 ? 'ok' : 'err' ?>"><?= e(str_replace('ok:', '', $commentMsg)) ?></div>
    <?php endif; ?>
    <?php if ($u): ?>
      <form class="comment-form" method="post" action="/modpack/<?= e($modpack['slug']) ?>#comments">
        <input type="hidden" name="action" value="comment">
        <input type="hidden" name="parent_id" id="replyParent" value="0">
        <div class="reply-hint" id="replyHint" style="display:none">回复给 <b id="replyName"></b> <a href="javascript:void(0)" onclick="closeReply()">取消</a></div>
        <textarea name="content" id="commentInput" placeholder="说点什么…（2-500 字）" required></textarea>
        <div style="margin-top:10px"><button class="btn" type="submit">发表评论</button></div>
      </form>
    <?php else: ?>
      <p class="comment-empty">请 <a href="/login">登录</a> 后参与评论。</p>
    <?php endif; ?>
    <div class="comment-list">
      <?php if (!$comments): ?><p class="comment-empty">还没有评论，来抢沙发吧。</p><?php endif; ?>
      <?php foreach ($comments as $c): ?>
      <div class="comment-item<?= $c['is_pinned'] ? ' pinned' : '' ?>" id="comment-<?= (int)$c['id'] ?>">
        <?php if ($c['avatar']): ?><img class="avatar" src="<?= e(cover_url($c['avatar'])) ?>" alt="">
        <?php else: ?><span class="avatar" style="display:inline-flex;align-items:center;justify-content:center;background:var(--bg2);font-size:14px"><i class="fa-solid fa-user"></i></span><?php endif; ?>
        <div class="c-body">
          <div class="c-meta">
            <span class="c-name"><?= e($c['nickname'] ?: $c['username']) ?></span>
            <?php if ($c['is_pinned']): ?><span class="pin-badge"><i class="fa-solid fa-thumbtack"></i> 置顶</span><?php endif; ?>
            <span><?= e(fmt_date($c['created_at'])) ?></span>
          </div>
          <div class="c-text"><?= e($c['content']) ?></div>
          <div class="c-actions">
            <a href="javascript:void(0)" class="clike<?= isset($myLikedCids[(int)$c['id']]) ? ' active' : '' ?>" data-id="<?= (int)$c['id'] ?>" onclick="toggleCommentLike(this)"><i class="fa-solid fa-heart"></i> 赞 <span class="count"><?= (int)$c['like_count'] ?></span></a>
            <a href="javascript:void(0)" class="creply" onclick="openReply(<?= (int)$c['id'] ?>, '<?= e($c['nickname'] ?: $c['username']) ?>')">回复</a>
            <?php if (can_manage_comment($u, $c)): ?>
              <a href="javascript:void(0)" class="cop" onclick="pinComment(<?= (int)$c['id'] ?>, <?= $c['is_pinned'] ? 0 : 1 ?>)"><?= $c['is_pinned'] ? '取消置顶' : '置顶' ?></a>
              <a href="javascript:void(0)" class="cop danger" onclick="delComment(<?= (int)$c['id'] ?>)">删除</a>
              <?php if ((int)$c['user_id'] !== (int)$u['id']): ?>
                <a href="javascript:void(0)" class="cop danger" onclick="muteComment(<?= (int)$c['id'] ?>, <?= (int)$c['user_id'] ?>)">禁言</a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if (!empty($replies[$c['id']])): ?>
          <div class="c-replies">
            <?php foreach ($replies[$c['id']] as $r): ?>
            <div class="reply-item" id="comment-<?= (int)$r['id'] ?>">
              <?php if ($r['avatar']): ?><img class="avatar sm" src="<?= e(cover_url($r['avatar'])) ?>" alt="">
              <?php else: ?><span class="avatar sm" style="display:inline-flex;align-items:center;justify-content:center;background:var(--bg2);font-size:12px"><i class="fa-solid fa-user"></i></span><?php endif; ?>
              <div class="c-body">
                <div class="c-meta"><span class="c-name"><?= e($r['nickname'] ?: $r['username']) ?></span><span><?= e(fmt_date($r['created_at'])) ?></span></div>
                <div class="c-text"><?= e($r['content']) ?></div>
                <div class="c-actions">
                  <a href="javascript:void(0)" class="clike<?= isset($myLikedCids[(int)$r['id']]) ? ' active' : '' ?>" data-id="<?= (int)$r['id'] ?>" onclick="toggleCommentLike(this)"><i class="fa-solid fa-heart"></i> 赞 <span class="count"><?= (int)$r['like_count'] ?></span></a>
                  <a href="javascript:void(0)" class="creply" onclick="openReply(<?= (int)$r['id'] ?>, '<?= e($r['nickname'] ?: $r['username']) ?>')">回复</a>
                  <?php if (can_manage_comment($u, $r)): ?>
                    <a href="javascript:void(0)" class="cop" onclick="pinComment(<?= (int)$r['id'] ?>, <?= $r['is_pinned'] ? 0 : 1 ?>)"><?= $r['is_pinned'] ? '取消置顶' : '置顶' ?></a>
                    <a href="javascript:void(0)" class="cop danger" onclick="delComment(<?= (int)$r['id'] ?>)">删除</a>
                    <?php if ((int)$r['user_id'] !== (int)$u['id']): ?>
                      <a href="javascript:void(0)" class="cop danger" onclick="muteComment(<?= (int)$r['id'] ?>, <?= (int)$r['user_id'] ?>)">禁言</a>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($related): ?>
  <section class="related">
    <h2>更多整合包</h2>
    <div class="grid">
      <?php foreach ($related as $r): ?>
      <a class="card" href="/modpack/<?= e($r['slug']) ?>">
        <div class="thumb">
          <?php if ($r['cover']): ?><img src="<?= e(thumb_url($r['cover'])) ?>" alt="<?= e($r['title']) ?>" loading="lazy">
          <?php else: ?><div class="thumb-ph"><i class="fa-solid fa-cube"></i></div><?php endif; ?>
        </div>
        <div class="card-body"><h3><?= e($r['title']) ?></h3><?php if ($r['tagline']): ?><p class="tagline"><?= e($r['tagline']) ?></p><?php endif; ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

<?php endif; ?>
</main>

<footer class="site-footer">
  <div class="wrap">
    <p class="foot-copy"><?= setting('footer_text', ($siteName ?? 'MC Modpack Hub') . ' · 我的世界整合包资源分享') ?></p>
  </div>
</footer>

<div id="muteModal" class="mute-modal" style="display:none">
  <div class="mute-modal-box">
    <div class="mute-modal-title"><i class="fa-solid fa-ban"></i> 禁言该用户</div>
    <p class="mute-modal-sub">禁言后该用户仍可登录，但期间不能评论和发布。可填写原因，并可追加到原有禁言时长。</p>
    <select id="muteDays" class="mute-days">
      <option value="1">1 天</option>
      <option value="3">3 天</option>
      <option value="7">7 天</option>
      <option value="30">30 天</option>
      <option value="-1">永久</option>
    </select>
    <input type="text" id="muteReason" class="mute-days" placeholder="禁言原因（可空）" style="margin-top:10px">
    <label class="inline-check" style="display:block;margin-top:10px"><input type="checkbox" id="muteAppend" value="1"> 追加禁言（在原禁言到期时间上延长，而非重新计算）</label>
    <div class="mute-modal-actions">
      <button type="button" class="btn ghost" onclick="closeMute()">取消</button>
      <button type="button" class="btn" onclick="doMute()">确定禁言</button>
    </div>
  </div>
</div>

<script src="/assets/js/app.js"></script>
<script>
function toggleLike(id) {
  fetch('/ajax.php?act=like', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'modpack_id=' + id })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.need_login) { location.href = '/login'; return; }
      if (d.ok) {
        var b = document.getElementById('likeBtn');
        b.classList.toggle('active', d.liked);
        document.getElementById('likeCount').textContent = d.count;
        burstIcon(b, 'fa-heart', d.liked ? 4 : 1, '#f43f5e');
      }
    });
}
function toggleFav(id) {
  fetch('/ajax.php?act=fav', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'modpack_id=' + id })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.need_login) { location.href = '/login'; return; }
      if (d.ok) { var b = document.getElementById('favBtn'); b.classList.toggle('active', d.favd); burstIcon(b, 'fa-star', d.favd ? 4 : 1, '#f59e0b'); }
    });
}
function toggleFollow(authorId) {
  fetch('/ajax.php?act=follow', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'followed_id=' + authorId })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.need_login) { location.href = '/login'; return; }
      if (d.ok) {
        var b = document.getElementById('followBtn');
        b.classList.toggle('active', d.following);
        document.getElementById('followTxt').textContent = d.following ? '已关注' : '关注作者';
        b.querySelector('i').className = 'fa-solid ' + (d.following ? 'fa-user-check' : 'fa-user-plus');
        var fe = document.getElementById('fansCount'); if (fe) fe.textContent = d.count;
      } else alert(d.msg || '操作失败');
    });
}
// 评论：点赞 / 回复 / 置顶 / 删除
function toggleCommentLike(el) {
  var id = el.getAttribute('data-id');
  fetch('/ajax.php?act=comment_like', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'comment_id=' + id })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.need_login) { location.href = '/login'; return; }
      if (d.ok) { el.classList.toggle('active', d.liked); el.querySelector('.count').textContent = d.count; burstIcon(el, 'fa-heart', d.liked ? 3 : 1, '#f43f5e'); }
      else if (d.msg) alert(d.msg);
    });
}
function openReply(id, name) {
  document.getElementById('replyParent').value = id;
  document.getElementById('replyName').textContent = name;
  document.getElementById('replyHint').style.display = 'block';
  var i = document.getElementById('commentInput');
  if (i) i.focus();
}
function closeReply() {
  document.getElementById('replyParent').value = 0;
  document.getElementById('replyHint').style.display = 'none';
}
function pinComment(id) {
  fetch('/ajax.php?act=pin_comment', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'comment_id=' + id })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d.ok) location.reload(); else alert(d.msg || '操作失败'); });
}
function delComment(id) {
  if (!confirm('确定删除这条评论吗？')) return;
  fetch('/ajax.php?act=del_comment', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'comment_id=' + id })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d.ok) location.reload(); else alert(d.msg || '操作失败'); });
}
// 禁言评论者（管理员 / 作者对自己整合包下的评论）
var _muteCid = 0, _muteUid = 0;
function muteComment(cid, uid) {
  _muteCid = cid; _muteUid = uid;
  document.getElementById('muteModal').style.display = 'flex';
}
function closeMute() { document.getElementById('muteModal').style.display = 'none'; }
function doMute() {
  var days = document.getElementById('muteDays').value;
  var reason = encodeURIComponent(document.getElementById('muteReason').value);
  var append = document.getElementById('muteAppend').checked ? '1' : '0';
  fetch('/ajax.php?act=mute_user', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'comment_id=' + _muteCid + '&days=' + days + '&reason=' + reason + '&append=' + append })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d.ok) { closeMute(); alert(d.msg || '已禁言'); } else alert(d.msg || '操作失败'); });
}
</script>
<?php wp_footer(); ?>
<?php do_action('mc_body_end'); ?>
</body>
</html>
