<?php
/**
 * WP 示例主题 functions.php
 * 演示 WordPress 主题兼容层能力：钩子 / 短代码 / enqueue / inline style
 */

// 在 <head> 输出一行 meta（通过 wp_head 钩子）
add_action('wp_head', function () {
    echo '<meta name="generator" content="MCshare · WP 示例主题">' . PHP_EOL;
});

// 演示 wp_enqueue_style + wp_add_inline_style（在 wp_head 输出）
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('wp-sample-base', '');
    wp_add_inline_style('wp-sample-base', '.site-header{box-shadow:0 2px 18px rgba(0,201,255,.18)}');
});

// 演示短代码 [wp_badge]内容[/wp_badge]
add_shortcode('wp_badge', function ($atts, $content = '') {
    $atts = shortcode_atts(['color' => 'var(--accent)'], $atts);
    return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;color:#fff;background:' . esc_attr($atts['color']) . '">' . ($content ?: 'WP 短代码') . '</span>';
});

// 演示过滤器（站点名加后缀）
add_filter('mc_site_name', function ($name) {
    return $name . ' · WP主题';
});
