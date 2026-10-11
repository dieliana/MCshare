<?php
/**
 * Plugin Name: WP 示例插件
 * Description: 演示 WordPress 插件在 MCshare 上的兼容：过滤器与短代码。
 * Version: 1.0.0
 * Author: 你
 * License: GPL v2 or later
 */

// 过滤器：站点名加后缀
add_filter('mc_site_name', function ($name) {
    return $name . ' · WP插件';
});

// 短代码： [wp_alert]文字[/wp_alert]
add_shortcode('wp_alert', function ($atts, $content = '') {
    $atts = shortcode_atts(['type' => 'info'], $atts, 'wp_alert');
    $map = ['info' => '#e8f4fd', 'ok' => '#e9f9ec', 'warn' => '#fff4e5', 'err' => '#fdecec'];
    $bg = $map[$atts['type']] ?? $map['info'];
    return '<div style="padding:10px 14px;border-radius:10px;background:' . esc_attr($bg) . ';color:#223;margin:10px 0">' . ($content ?: '') . '</div>';
});

// 钩子：页面底部注入（mc_body_end）
add_action('mc_body_end', function () {
    echo '<div style="max-width:1200px;margin:16px auto;text-align:center;color:var(--muted);font-size:12px">由 WP 示例插件注入</div>';
});
