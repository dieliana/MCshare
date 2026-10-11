<?php
/**
 * 插件加载器
 * 1) 引入 WordPress 兼容层（wp-compat），并提供 wp_head/wp_footer 等函数
 * 2) 加载启用的自定义主题（若为 WordPress 主题则加载其 functions.php）
 * 3) 加载启用的插件：支持 MCshare 原生插件（plugins/xx/plugin.php，返回 $pluginInfo）
 *    与 WordPress 插件（主文件头部含 "Plugin Name:"）
 */
require_once __DIR__ . '/hooks.php';

/** 输出启用主题的 theme.css 与可选主题列表（前台 <head> 使用） */
function mc_theme_assets()
{
    $theme = setting('theme', 'emerald');
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
