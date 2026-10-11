<?php
/**
 * WP 兼容层 · 资源入队（enqueue）
 * 存储入队队列，在 wp_head() 时输出样式与脚本（脚本简化全放 head，避免 wp_footer 在部分页面不触发导致丢失）。
 */

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all')
    {
        $GLOBALS['wp_styles'][$handle] = ['src' => $src, 'ver' => $ver, 'media' => $media];
    }
}
if (!function_exists('wp_register_style')) {
    function wp_register_style($handle, $src = '', $deps = [], $ver = false, $media = 'all')
    {
        wp_enqueue_style($handle, $src, $deps, $ver, $media);
    }
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false)
    {
        $GLOBALS['wp_scripts'][$handle] = ['src' => $src, 'ver' => $ver, 'in_footer' => $in_footer];
    }
}
if (!function_exists('wp_register_script')) {
    function wp_register_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false)
    {
        wp_enqueue_script($handle, $src, $deps, $ver, $in_footer);
    }
}
if (!function_exists('wp_add_inline_style')) {
    function wp_add_inline_style($handle, $css)
    {
        $GLOBALS['wp_inline_css'][$handle][] = $css;
    }
}
if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script($handle, $js, $position = 'after')
    {
        $GLOBALS['wp_inline_js'][$handle][] = $js;
    }
}
if (!function_exists('wp_enqueue_block_style')) {
    function wp_enqueue_block_style($handle, $args) { wp_enqueue_style($handle, $args['src'] ?? '', [], $args['ver'] ?? false); }
}

/** 渲染资源地址（相对路径补 site 根，绝对 http 原样） */
if (!function_exists('mc_wp_resolve_src')) {
    function mc_wp_resolve_src($src, $ver)
    {
        $s = (string)$src;
        if ($s === '') return '';
        if (!preg_match('#^(https?:)?//#i', $s)) {
            // 相对当前站根
            $s = site_url() . '/' . ltrim($s, '/');
        }
        if ($ver) {
            $s .= (strpos($s, '?') !== false ? '&' : '?') . 'ver=' . urlencode((string)$ver);
        }
        return $s;
    }
}

if (!function_exists('wp_head')) {
    function wp_head()
    {
        static $done = false;
        if ($done) return;
        $done = true;
        do_action('wp_enqueue_scripts'); // 让主题/插件先入队资源
        do_action('wp_head');
        if (!empty($GLOBALS['wp_styles'])) {
            foreach ($GLOBALS['wp_styles'] as $h => $s) {
                $src = mc_wp_resolve_src($s['src'], $s['ver']);
                if ($src) echo '<link rel="stylesheet" id="' . esc_attr($h) . '-css" href="' . esc_url($src) . '" media="' . esc_attr($s['media'] ?? 'all') . '">' . PHP_EOL;
                if (!empty($GLOBALS['wp_inline_css'][$h])) {
                    foreach ($GLOBALS['wp_inline_css'][$h] as $css) {
                        echo '<style id="' . esc_attr($h) . '-inline">' . $css . '</style>' . PHP_EOL;
                    }
                }
            }
        }
        if (!empty($GLOBALS['wp_scripts'])) {
            foreach ($GLOBALS['wp_scripts'] as $h => $s) {
                $src = mc_wp_resolve_src($s['src'], $s['ver']);
                if ($src) echo '<script id="' . esc_attr($h) . '-js" src="' . esc_url($src) . '"></script>' . PHP_EOL;
                if (!empty($GLOBALS['wp_inline_js'][$h])) {
                    foreach ($GLOBALS['wp_inline_js'][$h] as $js) {
                        echo '<script id="' . esc_attr($h) . '-inline">' . $js . '</script>' . PHP_EOL;
                    }
                }
            }
        }
    }
}

if (!function_exists('wp_footer')) {
    function wp_footer()
    {
        do_action('wp_footer');
    }
}
