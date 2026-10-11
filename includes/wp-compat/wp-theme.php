<?php
/**
 * WP 兼容层 · 主题
 * 识别 WordPress 主题（style.css 头部含 "Theme Name:"）并加载其 functions.php。
 * 兼容策略：WP 主题作为「增强层」加载——其 functions.php 注册的钩子、enqueue 资源、短代码在 MCshare 前台生效；
 * 页面主体仍由 MCshare 渲染（如需完整 WP 模板渲染的主题，仅样式/钩子层生效）。
 */

/** 解析 WP 主题 style.css 头部注释 */
if (!function_exists('mc_wp_theme_info')) {
    function mc_wp_theme_info($dir)
    {
        $style = rtrim($dir, '/') . '/style.css';
        if (!is_file($style)) return null;
        $head = (string)file_get_contents($style);
        // 只读前 8KB 头部注释
        $head = substr($head, 0, 8192);
        if (stripos($head, 'Theme Name:') === false) return null;
        $fields = ['name' => 'Theme Name', 'uri' => 'Theme URI', 'version' => 'Version', 'author' => 'Author', 'desc' => 'Description', 'tags' => 'Tags'];
        $info = ['id' => basename($dir)];
        foreach ($fields as $k => $label) {
            if (preg_match('/^' . preg_quote($label, '/') . '\s*:\s*(.*)$/mi', $head, $m)) {
                $info[$k] = trim($m[1]);
            }
        }
        $info['type'] = 'wp';
        return $info;
    }
}

/** 加载 WP 主题 functions.php */
if (!function_exists('load_wp_theme_functions')) {
    function load_wp_theme_functions($dir)
    {
        $dir = rtrim($dir, '/');
        if (!defined('STYLESHEETPATH')) define('STYLESHEETPATH', $dir);
        if (!defined('TEMPLATEPATH')) define('TEMPLATEPATH', $dir);
        $GLOBALS['wp_active_theme_dir'] = $dir;
        $fn = $dir . '/functions.php';
        if (is_file($fn)) {
            try { require $fn; } catch (Throwable $e) { /* 主题 functions 异常不阻断前台 */ }
        }
    }
}

/* 常用模板/资源函数 */
if (!function_exists('get_stylesheet_directory')) {
    function get_stylesheet_directory() { return $GLOBALS['wp_active_theme_dir'] ?? ''; }
}
if (!function_exists('get_template_directory')) {
    function get_template_directory() { return $GLOBALS['wp_active_theme_dir'] ?? ''; }
}
if (!function_exists('get_stylesheet_directory_uri')) {
    function get_stylesheet_directory_uri() { return site_url() . '/themes/' . basename($GLOBALS['wp_active_theme_dir'] ?? ''); }
}
if (!function_exists('get_template_directory_uri')) {
    function get_template_directory_uri() { return get_stylesheet_directory_uri(); }
}
if (!function_exists('get_stylesheet_uri')) {
    function get_stylesheet_uri() { return get_stylesheet_directory_uri() . '/style.css'; }
}
if (!function_exists('get_template_uri')) {
    function get_template_uri() { return get_stylesheet_directory_uri(); }
}
/* header/footer 片段：MCshare 页面主体不由 WP 模板驱动，此处降级为仅触发钩子 */
if (!function_exists('get_header')) {
    function get_header($name = null) { do_action('get_header', $name); }
}
if (!function_exists('get_footer')) {
    function get_footer($name = null) { do_action('get_footer', $name); }
}
if (!function_exists('bloginfo')) {
    function bloginfo($show = '')
    {
        switch ($show) {
            case 'name': case 'blogname': echo e((string)setting('site_name', 'MC Modpack Hub')); break;
            case 'description': case 'blogdescription': echo e((string)setting('site_desc', '')); break;
            case 'url': case 'home': case 'siteurl': echo e(site_url()); break;
            default: echo '';
        }
    }
}
