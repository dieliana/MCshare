<?php
/**
 * 插件加载器
 * 1) 引入 WordPress 兼容层（wp-compat），并提供 wp_head/wp_footer 等函数
 * 2) 加载启用的自定义主题（若为 WordPress 主题则加载其 functions.php）
 * 3) 加载启用的插件：支持 MCshare 原生插件（plugins/xx/plugin.php，返回 $pluginInfo）
 *    与 WordPress 插件（主文件头部含 "Plugin Name:"）
 */
require_once __DIR__ . '/hooks.php';

/** 当前生效主题：后台设置 + 访客 Cookie 记忆覆盖 */
function mc_active_theme()
{
    $theme = setting('theme', 'emerald');
    $builtin = ['emerald', 'ocean', 'violet', 'forest', 'light'];
    if (!empty($_COOKIE['mc_theme'])) {
        $c = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_COOKIE['mc_theme']);
        if ($c !== '' && (in_array($c, $builtin) || is_dir(dirname(__DIR__) . '/themes/' . $c))) $theme = $c;
    }
    return $theme;
}

/** 输出启用主题的 theme.css 与可选主题列表（前台 <head> 使用） */
function mc_theme_assets()
{
    $theme = mc_active_theme();
    $css = dirname(__DIR__) . '/themes/' . $theme . '/theme.css';
    if (is_file($css)) {
        echo '<link rel="stylesheet" href="/themes/' . rawurlencode($theme) . '/theme.css">' . PHP_EOL;
    }
    $all = ['emerald', 'ocean', 'violet', 'forest', 'light'];
    foreach (glob(dirname(__DIR__) . '/themes/*/theme.css') ?: [] as $tf) {
        $t = basename(dirname($tf));
        if (!in_array($t, $all, true)) $all[] = $t;
    }
    echo '<script>window.MC_THEMES = ' . json_encode($all) . ';</script>' . PHP_EOL;

    // 主页背景图片（后台可自定义，覆盖全屏、固定不随滚动）
    $bg = trim((string)setting('background_img', ''));
    if ($bg !== '') {
        echo '<style>body{background-image:url(\'' . esc_url(cover_url($bg)) . '\');background-size:cover;background-attachment:fixed;background-position:center;} .bg-glow{opacity:.4;}</style>' . PHP_EOL;
    }
}

function load_plugins()
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    require_once __DIR__ . '/wp-compat/wp-compat.php';

    // 加载启用主题：若为 WordPress 主题（style.css 含 Theme Name），加载其 functions.php
    $theme  = setting('theme', 'emerald');
    $tDir   = dirname(__DIR__) . '/themes/' . $theme;
    if (is_dir($tDir) && mc_wp_theme_info($tDir)) {
        load_wp_theme_functions($tDir);
    }

    $active = json_decode((string)setting('active_plugins', '[]'), true);
    if (!is_array($active) || !$active) return;

    $dir = dirname(__DIR__) . '/plugins';

    // WordPress 插件（主文件含 Plugin Name 头）
    load_wp_plugins();

    // MCshare 原生插件（plugin.php，无 WP 头部）
    foreach ($active as $id) {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$id);
        if ($id === '') continue;
        $f = $dir . '/' . $id . '/plugin.php';
        if (is_file($f) && !mc_wp_plugin_info($f)) {
            try { require $f; } catch (Throwable $e) { /* 插件异常不阻断前台 */ }
        }
    }
}

/** Cookie 使用提示条（首次访问显示，点同意后写 mc_cookie_consent 不再出现） */
function mc_cookie_bar()
{
    echo <<<HTML
<div id="mc-cookie-bar" style="display:none;position:fixed;left:0;right:0;bottom:0;z-index:9999;background:var(--panel);border-top:1px solid var(--line);padding:12px 18px;font-size:13px;color:var(--text);align-items:center;gap:14px;flex-wrap:wrap;box-shadow:0 -6px 24px rgba(0,0,0,.25)">
  <span style="flex:1;min-width:240px">本站使用 Cookie 以提供更佳的浏览体验，继续访问即表示你同意本站使用 Cookie。</span>
  <button type="button" id="mc-cookie-ok" style="background:var(--accent);color:var(--accent-fg);border:none;padding:8px 18px;border-radius:8px;font-weight:600;cursor:pointer">知道了</button>
</div>
<script>
(function(){
  try{ if (localStorage.getItem('mc_cookie_consent')) return; }catch(e){}
  var bar=document.getElementById('mc-cookie-bar'); if(!bar) return;
  bar.style.display='flex';
  var ok=document.getElementById('mc-cookie-ok');
  if(ok) ok.addEventListener('click', function(){
    try{ localStorage.setItem('mc_cookie_consent','1'); }catch(e){}
    document.cookie='mc_cookie_consent=1;path=/;max-age=31536000';
    bar.style.display='none';
  });
})();
</script>
HTML;
}
add_action('mc_body_end', 'mc_cookie_bar');
