<?php
/**
 * 后台侧边导航（被各后台页面引入）
 */
?>
<aside class="sidebar">
  <div class="side-brand"><i class="fa-solid fa-cube"></i> <?= e($siteName) ?></div>
  <nav class="side-nav">
    <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'on' : '' ?>"><i class="fa-solid fa-chart-line"></i> 数据看板</a>
    <a href="modpacks.php" class="<?= in_array(basename($_SERVER['PHP_SELF']), ['modpacks.php','modpack-form.php']) ? 'on' : '' ?>"><i class="fa-solid fa-puzzle-piece"></i> 整合包管理</a>
    <a href="verify.php" class="<?= basename($_SERVER['PHP_SELF']) === 'verify.php' ? 'on' : '' ?>"><i class="fa-solid fa-clipboard-check"></i> 审核中心</a>
    <a href="categories.php" class="<?= basename($_SERVER['PHP_SELF']) === 'categories.php' ? 'on' : '' ?>"><i class="fa-solid fa-folder"></i> 分类管理</a>
    <a href="tags.php" class="<?= basename($_SERVER['PHP_SELF']) === 'tags.php' ? 'on' : '' ?>"><i class="fa-solid fa-tags"></i> 标签管理</a>
    <a href="gallery.php" class="<?= basename($_SERVER['PHP_SELF']) === 'gallery.php' ? 'on' : '' ?>"><i class="fa-solid fa-images"></i> 图库管理</a>
    <a href="users.php" class="<?= basename($_SERVER['PHP_SELF']) === 'users.php' ? 'on' : '' ?>"><i class="fa-solid fa-users"></i> 用户管理</a>
    <a href="appeals.php" class="<?= basename($_SERVER['PHP_SELF']) === 'appeals.php' ? 'on' : '' ?>"><i class="fa-solid fa-gavel"></i> 申诉管理</a>
    <a href="comments.php" class="<?= basename($_SERVER['PHP_SELF']) === 'comments.php' ? 'on' : '' ?>"><i class="fa-solid fa-comments"></i> 评论管理</a>
    <a href="mail.php" class="<?= basename($_SERVER['PHP_SELF']) === 'mail.php' ? 'on' : '' ?>"><i class="fa-solid fa-envelope"></i> 邮件发送</a>
    <a href="settings.php" class="<?= basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'on' : '' ?>"><i class="fa-solid fa-gear"></i> 站点设置</a>
  </nav>
  <div class="side-foot">
    <button type="button" class="theme-btn-admin" id="adminThemeToggle" title="切换主题" onclick="window.adminCycleTheme && window.adminCycleTheme()"><i class="fa-solid fa-palette"></i> 切换主题</button>
    <a href="../index.php" target="_blank">查看前台 <i class="fa-solid fa-up-right-from-square"></i></a>
    <a href="logout.php">退出登录</a>
  </div>
</aside>
<script>
(function () {
  var THEMES = ['emerald', 'ocean', 'violet', 'forest', 'light'];
  var cur = document.documentElement.getAttribute('data-theme');
  try { var s = localStorage.getItem('mc_theme'); if (s && THEMES.indexOf(s) !== -1) cur = s; } catch (e) {}
  document.documentElement.setAttribute('data-theme', cur);
  window.adminCycleTheme = function () {
    var i = THEMES.indexOf(cur);
    cur = THEMES[(i + 1) % THEMES.length];
    document.documentElement.setAttribute('data-theme', cur);
    try { localStorage.setItem('mc_theme', cur); } catch (e) {}
  };
})();
</script>
