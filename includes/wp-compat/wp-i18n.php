<?php
/**
 * WP 兼容层 · 国际化与转义
 * 简化实现：无翻译表时返回原文；转义函数委托 PHP 内置。
 */

if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('_e')) {
    function _e($text, $domain = 'default') { echo $text; }
}
if (!function_exists('_x')) {
    function _x($text, $context, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default') { echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default') { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return htmlspecialchars((string)$url, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title)
    {
        $t = strtolower(trim($title));
        $t = preg_replace('/[^a-z0-9\-\s_]/u', '', $t);
        return preg_replace('/[\s_]+/', '-', $t);
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return trim(strip_tags((string)$str)); }
}
if (!function_exists('absint')) {
    function absint($maybeint) { return abs((int)$maybeint); }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url) { return parse_url($url); }
}
