<?php
/**
 * 前台首页 · 整合包列表
 */
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/plugins.php';
load_plugins();

ensure_session();
maintenance_check();
$u = current_user();

$siteName = setting('site_name', defined('SITE_NAME') ? SITE_NAME : 'MC Modpack Hub');
$siteDesc = setting('site_desc', defined('SITE_DESC') ? SITE_DESC : '');
$announce = setting('announcement', '');
$theme    = mc_active_theme();
$logo     = setting('logo', '');
$favicon  = setting('favicon', '');
$autoRefresh = (int)setting('auto_refresh', '120');

// ---- 筛选参数 ----
$q        = trim($_GET['q'] ?? '');
$catSlug  = trim($_GET['cat'] ?? '');
$tagSlug  = trim($_GET['tag'] ?? '');
$loader   = trim($_GET['loader'] ?? '');
$sort     = in_array($_GET['sort'] ?? '', ['latest', 'popular', 'downloads'], true) ? $_GET['sort'] : 'latest';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 12;

// 分类
$categories = db()->query('SELECT * FROM categories ORDER BY sort ASC, id ASC')->fetchAll();

$where  = ['m.status = 1'];
$params = [];
$activeCat = null;
$activeTag = null;

if ($catSlug !== '') {
    $st = db()->prepare('SELECT * FROM categories WHERE slug = ?');
    $st->execute([$catSlug]);
    $activeCat = $st->fetch();
    if ($activeCat) {
        $where[]  = 'm.category_id = :cat';
        $params[':cat'] = $activeCat['id'];
    }
}

if ($tagSlug !== '') {
    $st = db()->prepare('SELECT * FROM tags WHERE name = ?');
    $st->execute([$tagSlug]);
    $activeTag = $st->fetch();
    if ($activeTag) {
        $where[] = 'm.id IN (SELECT modpack_id FROM modpack_tags WHERE tag_id = :tag)';
        $params[':tag'] = $activeTag['id'];
    }
}

if ($q !== '') {
    $where[]  = '(m.title LIKE :kw1 OR m.tagline LIKE :kw2 OR m.content LIKE :kw3 OR m.author LIKE :kw4)';
    $params[':kw1'] = '%' . $q . '%';
    $params[':kw2'] = '%' . $q . '%';
    $params[':kw3'] = '%' . $q . '%';
    $params[':kw4'] = '%' . $q . '%';
}

if ($loader !== '' && in_array($loader, ['forge', 'fabric', 'neoforge', 'quilt', 'datapack', 'vanilla', 'other'], true)) {
    $where[]  = 'm.mod_loader = :loader';
    $params[':loader'] = $loader;
}

$whereSql = implode(' AND ', $where);

$orderSql = match ($sort) {
    'popular'   => 'm.views DESC, m.id DESC',
    'downloads' => 'm.downloads DESC, m.id DESC',
    default     => 'm.id DESC',
};

$countSt = db()->prepare("SELECT COUNT(*) FROM modpacks m WHERE $whereSql");
$countSt->execute($params);
$total = (int)$countSt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT m.*, c.name AS category_name, c.slug AS category_slug
        FROM modpacks m
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE $whereSql
        ORDER BY $orderSql
        LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$modpacks = $stmt->fetchAll();

// 标签统计（用于标签云/筛选）
$tags = db()->query(
    'SELECT t.id, t.name, COUNT(mt.modpack_id) AS cnt
     FROM tags t
     LEFT JOIN modpack_tags mt ON mt.tag_id = t.id
     GROUP BY t.id, t.name
     ORDER BY cnt DESC, t.id ASC'
)->fetchAll();

function buildQuery(array $overrides): string
{
    $p = array_merge([
        'q'      => $_GET['q'] ?? '',
        'cat'    => $_GET['cat'] ?? '',
        'tag'    => $_GET['tag'] ?? '',
        'loader' => $_GET['loader'] ?? '',
        'sort'   => $_GET['sort'] ?? 'latest',
    ], $overrides);
    $qs = http_build_query(array_filter($p, fn($v) => $v !== ''));
    return $qs === '' ? '/' : '/?' . $qs;
}

// AJAX 无刷新请求：只输出列表区域
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    include __DIR__ . '/_list.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= e($theme ?: 'emerald') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($siteName) ?></title>
<meta name="description" content="<?= e($siteDesc) ?>">
<?php if ($favicon): ?><link rel="icon" href="<?= e(cover_url($favicon)) ?>"><?php endif; ?>
<link rel="stylesheet" href="/assets/css/style.css?v=<?= e(mc_asset_ver()) ?>">
    <link rel="stylesheet" href="/assets/fontawesome/css/all.min.css?v=<?= e(mc_asset_ver()) ?>">
<?php mc_theme_assets(); ?>
<?php wp_head(); ?>
<?php mc_og_meta(); ?>
</head>
<body data-refresh="<?= (int)$autoRefresh ?>">
<div class="bg-glow"></div>

<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="/">
      <?php if ($logo): ?>
        <img class="brand-logo" src="<?= e(cover_url($logo)) ?>" alt="<?= e($siteName) ?>">
      <?php else: ?>
        <span class="brand-icon"><i class="fa-solid fa-cube"></i></span>
      <?php endif; ?>
      <span class="brand-name"><?= e($siteName) ?></span>
    </a>
    <form class="search" method="get" action="/">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="搜索整合包 / 作者…" aria-label="搜索">
      <button type="submit">搜索</button>
    </form>
    <button type="button" class="theme-btn" id="themeToggle" title="切换主题" onclick="window.cycleTheme && window.cycleTheme()"><i class="fa-solid fa-palette"></i></button>
    <?php if ($u): ?>
      <?php $unread = unread_count($u['id']); ?>
      <a class="bell-btn" href="/messages" title="站内信"><i class="fa-solid fa-bell"></i><?php if ($unread): ?><span class="bell-badge"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?></a>
      <a class="btn-admin" href="/submit">发布</a>
      <div class="user-menu" onclick="this.classList.toggle('open')">
        <?php if ($u['avatar']): ?><img class="avatar" src="<?= e(cover_url($u['avatar'])) ?>" alt="头像">
        <?php else: ?><span class="avatar" style="display:inline-flex;align-items:center;justify-content:center;background:var(--bg2);font-size:16px"><i class="fa-solid fa-user"></i></span><?php endif; ?>
        <span><?= e($u['nickname'] ?: $u['username']) ?></span>
        <div class="user-drop"><a href="/profile"><i class="fa-solid fa-user"></i> 用户中心</a><a href="/messages"><i class="fa-solid fa-bell"></i> 站内信<?= $unread ? '（' . $unread . '）' : '' ?></a><a href="/submit"><i class="fa-solid fa-upload"></i> 发布整合包</a><a href="/my-modpacks"><i class="fa-solid fa-list"></i> 我的发布</a><a href="/logout"><i class="fa-solid fa-right-from-bracket"></i> 退出登录</a></div>
      </div>
    <?php else: ?>
      <a class="btn-admin" href="/login">登录</a>
      <a class="btn-admin" href="/register">注册</a>
    <?php endif; ?>
  </div>
</header>

<?php if ($announce !== ''): ?>
<div class="announce"><div class="wrap"><i class="fa-solid fa-bullhorn"></i> <?= e($announce) ?></div></div>
<?php endif; ?>

<main class="wrap">
  <section class="hero">
    <h1><?= e($siteName) ?></h1>
    <p><?= e($siteDesc ?: '分享优质我的世界整合包资源') ?></p>
  </section>

  <nav class="filters">
    <div class="filter-group">
      <a class="chip <?= $catSlug === '' ? 'on' : '' ?>" href="<?= e(buildQuery(['cat' => '', 'page' => 1])) ?>">全部</a>
      <?php foreach ($categories as $c): ?>
        <a class="chip <?= $activeCat && $activeCat['id'] == $c['id'] ? 'on' : '' ?>" href="<?= e(buildQuery(['cat' => $c['slug'], 'page' => 1])) ?>"><?= e($c['name']) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="filter-group">
      <span class="chip-static dim">加载器：</span>
      <a class="chip <?= $loader === '' ? 'on' : '' ?>" href="<?= e(buildQuery(['loader' => '', 'page' => 1])) ?>">全部</a>
      <?php foreach (['forge', 'fabric', 'neoforge', 'quilt', 'datapack'] as $ld): ?>
        <a class="chip <?= $loader === $ld ? 'on' : '' ?>" href="<?= e(buildQuery(['loader' => $ld, 'page' => 1])) ?>"><?= e(loader_label($ld)) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="filter-group right">
      <a class="chip <?= $sort === 'latest' ? 'on' : '' ?>" href="<?= e(buildQuery(['sort' => 'latest'])) ?>">最新</a>
      <a class="chip <?= $sort === 'popular' ? 'on' : '' ?>" href="<?= e(buildQuery(['sort' => 'popular'])) ?>">热门</a>
      <a class="chip <?= $sort === 'downloads' ? 'on' : '' ?>" href="<?= e(buildQuery(['sort' => 'downloads'])) ?>">下载量</a>
    </div>
  </nav>

  <?php include __DIR__ . '/_list.php'; ?>

</main>

<?php if ($tags): ?>
<footer class="site-footer">
  <div class="wrap">
    <div class="foot-title">热门标签</div>
    <div class="foot-tags">
      <?php foreach (array_slice($tags, 0, 30) as $tg): ?>
        <a href="<?= e(buildQuery(['tag' => $tg['name'], 'page' => 1])) ?>">#<?= e($tg['name']) ?></a>
      <?php endforeach; ?>
    </div>
    <p class="foot-copy"><?= setting('footer_text', $siteName . ' · 我的世界整合包资源分享') ?></p>
  </div>
</footer>
<?php endif; ?>

<script src="/assets/js/app.js?v=<?= e(mc_asset_ver()) ?>"></script>
<script>
// 列表无刷新加载：筛选 / 搜索 / 排序 / 分页不再整页跳闪
(function () {
  function loadList(url) {
    fetch(url)
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var box = document.getElementById('listRegion');
        if (!box) { location.href = url; return; }
        box.innerHTML = html;
        box.classList.remove('list-anim'); void box.offsetWidth; box.classList.add('list-anim');
        var clean = url.replace(/([?&])ajax=1&?/, '$1').replace(/[?&]$/, '');
        if (history.pushState) history.pushState(null, '', clean);
      })
      .catch(function () { location.href = url; });
  }
  // 拦截首页筛选/排序/分页/标签/清除/logo（伪静态：/ 或 /? 开头的首页链接）
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (href === '/' || href.indexOf('/?') === 0 || href === 'index.php' || href.indexOf('index.php?') === 0) {
      e.preventDefault();
      var sep = href.indexOf('?') >= 0 ? '&' : '?';
      loadList(href + sep + 'ajax=1');
    }
  });
  // 搜索框提交
  var searchForm = document.querySelector('header .search');
  if (searchForm) {
    searchForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var q = (searchForm.querySelector('input[name=q]') || {}).value || '';
      loadList('/?q=' + encodeURIComponent(q) + '&ajax=1');
    });
  }
  // 支持浏览器后退/前进
  window.addEventListener('popstate', function () {
    var sep = location.search ? '&' : '?';
    loadList(location.pathname + location.search + sep + 'ajax=1');
  });
})();
</script>
<?php wp_footer(); ?>
<?php do_action('mc_body_end'); ?>
</body>
</html>
