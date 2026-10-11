<?php
/**
 * 前台公共头部（登录 / 注册 / 用户中心等页面引入）
 * 需先加载 db.php 与 functions.php
 */
require_once __DIR__ . '/plugins.php';
load_plugins();
$siteName = apply_filters('mc_site_name', setting('site_name', defined('SITE_NAME') ? SITE_NAME : 'MC Modpack Hub'));
$logo     = setting('logo', '');
$favicon  = setting('favicon', '');
$theme    = setting('theme', 'emerald');
$announce = setting('announcement', '');
$u        = current_user();
$pageTitle = $pageTitle ?? $siteName;
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · <?= e($siteName) ?></title>
<?php if ($favicon): ?><link rel="icon" href="<?= e(cover_url($favicon)) ?>"><?php endif; ?>
<link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/fontawesome/css/all.min.css">
<?php
// 加载自定义主题的 theme.css（若当前主题为 themes/ 下的目录主题）
$_themeCss = __DIR__ . '/../themes/' . $theme . '/theme.css';
if (is_file($_themeCss)) echo '<link rel="stylesheet" href="/themes/' . rawurlencode($theme) . '/theme.css">';
// 收集全部可用主题（内置 + themes/ 自定义），供前台切换按钮轮换
$_allThemes = ['emerald', 'ocean', 'violet', 'forest', 'light'];
foreach (glob(__DIR__ . '/../themes/*/theme.css') ?: [] as $_tf) {
    $_t = basename(dirname($_tf));
    if (!in_array($_t, $_allThemes, true)) $_allThemes[] = $_t;
}
echo '<script>window.MC_THEMES = ' . json_encode($_allThemes) . ';</script>';
?>
<?php wp_head(); ?>
</head>
<body>
<?php do_action('mc_body_top'); ?>
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
    <?php
      $_nav = json_decode((string)setting('nav_items', '[]'), true);
      if (!is_array($_nav)) $_nav = [];
      $_nav = array_filter($_nav, fn($n) => !empty($n['visible']) && trim((string)($n['title'] ?? '')) !== '');
      usort($_nav, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));
    ?>
    <?php if ($_nav): ?>
      <nav class="site-nav">
        <?php foreach ($_nav as $_n): ?>
          <a class="nav-link" href="<?= e($_n['url'] ?? '#') ?>"><?= e($_n['title']) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <div style="flex:1"></div>
    <button type="button" class="theme-btn" title="切换主题" onclick="window.cycleTheme && window.cycleTheme()"><i class="fa-solid fa-palette"></i></button>
    <?php if ($u): ?>
      <?php $unread = unread_count($u['id']); ?>
      <a class="bell-btn" href="/messages" title="站内信">
        <i class="fa-solid fa-bell"></i>
        <?php if ($unread): ?><span class="bell-badge"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?>
      </a>
      <a class="btn-admin" href="/submit">发布</a>
      <div class="user-menu" onclick="this.classList.toggle('open')">
        <?php if ($u['avatar']): ?>
          <img class="avatar" src="<?= e(cover_url($u['avatar'])) ?>" alt="头像">
        <?php else: ?>
          <span class="avatar" style="display:inline-flex;align-items:center;justify-content:center;background:var(--bg2);font-size:16px"><i class="fa-solid fa-user"></i></span>
        <?php endif; ?>
        <span><?= e($u['nickname'] ?: $u['username']) ?></span>
        <div class="user-drop">
          <a href="/profile"><i class="fa-solid fa-user"></i> 用户中心</a>
          <a href="/messages"><i class="fa-solid fa-bell"></i> 站内信<?= $unread ? '（' . $unread . '）' : '' ?></a>
          <a href="/submit"><i class="fa-solid fa-upload"></i> 发布整合包</a>
          <a href="/my-modpacks"><i class="fa-solid fa-list"></i> 我的发布</a>
          <?php if ((int)($u['author_status'] ?? 0) === 0): ?>
            <a href="/profile#author"><i class="fa-solid fa-user-tie"></i> 申请成为作者</a>
          <?php elseif ((int)($u['author_status'] ?? 0) === 1): ?>
            <span class="drop-dim"><i class="fa-solid fa-clock"></i> 作者申请审核中</span>
          <?php elseif ((int)($u['author_status'] ?? 0) === 3): ?>
            <a href="/profile#author"><i class="fa-solid fa-rotate"></i> 重新申请作者</a>
          <?php endif; ?>
          <a href="/logout"><i class="fa-solid fa-right-from-bracket"></i> 退出登录</a>
        </div>
      </div>
    <?php else: ?>
      <a class="btn-admin" href="/login">登录</a>
      <a class="btn-admin" href="/register">注册</a>
    <?php endif; ?>
  </div>
</header>
<?php do_action('mc_after_header'); ?>

<?php if ($announce !== ''): ?>
<div class="announce"><div class="wrap"><i class="fa-solid fa-bullhorn"></i> <?= e($announce) ?></div></div>
<?php endif; ?>
