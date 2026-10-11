<?php
/**
 * WP 兼容层 · 短代码（shortcode）
 * add_shortcode / do_shortcode / shortcode_atts / remove_shortcode
 */

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $cb)
    {
        $GLOBALS['wp_shortcodes'][$tag] = $cb;
    }
}
if (!function_exists('remove_shortcode')) {
    function remove_shortcode($tag)
    {
        unset($GLOBALS['wp_shortcodes'][$tag]);
    }
}
if (!function_exists('shortcode_atts')) {
    function shortcode_atts($pairs, $atts = [], $shortcode = '')
    {
        if (!is_array($atts)) $atts = [];
        return array_merge((array)$pairs, $atts);
    }
}
if (!function_exists('wp_shortcode_parse_atts')) {
    function wp_shortcode_parse_atts($text)
    {
        $out = [];
        if (preg_match_all('/([\w-]+)\s*=\s*"([^"]*)"|([\w-]+)\s*=\s*\'([^\']*)\'|([\w-]+)\s*=\s*([^\s"\']+)/', $text, $m)) {
            foreach ($m[0] as $i => $_) {
                $k = $m[1][$i] !== '' ? $m[1][$i] : ($m[3][$i] !== '' ? $m[3][$i] : $m[5][$i]);
                $v = $m[2][$i] !== '' ? $m[2][$i] : ($m[4][$i] !== '' ? $m[4][$i] : ($m[6][$i] ?? ''));
                $out[$k] = $v;
            }
        }
        return $out;
    }
}

if (!function_exists('mc_wp_do_shortcode_cb')) {
    function mc_wp_do_shortcode_cb($m)
    {
        $tag = $m[1];
        if (!isset($GLOBALS['wp_shortcodes'][$tag])) return $m[0];
        $atts = wp_shortcode_parse_atts($m[2] ?? '');
        $content = $m[3] ?? '';
        try {
            return (string)call_user_func($GLOBALS['wp_shortcodes'][$tag], $atts, $content, $tag);
        } catch (Throwable $e) {
            return $content;
        }
    }
}

if (!function_exists('do_shortcode')) {
    function do_shortcode($content)
    {
        if (!is_string($content) || !strpos($content, '[') || empty($GLOBALS['wp_shortcodes'])) {
            return $content;
        }
        // 成对： [tag atts]content[/tag]
        $content = preg_replace_callback('#\[([a-zA-Z0-9_-]+)([^\]]*)\](.*?)\[\/\1\]#s', 'mc_wp_do_shortcode_cb', $content);
        // 自闭合： [tag atts /]
        $content = preg_replace_callback('#\[([a-zA-Z0-9_-]+)([^\]]*)/\]#s', 'mc_wp_do_shortcode_cb', $content);
        // 无内容： [tag atts]
        $content = preg_replace_callback('#\[([a-zA-Z0-9_-]+)([^\]]*)\]#s', 'mc_wp_do_shortcode_cb', $content);
        return $content;
    }
}
