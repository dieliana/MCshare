<?php
/**
 * WP 兼容层 · 常量
 * 提供 WordPress 主题/插件常见的路径常量
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('WPINC')) {
    define('WPINC', 'includes');
}
if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', ABSPATH . 'plugins');
}
if (!defined('WP_PLUGIN_URL')) {
    define('WP_PLUGIN_URL', site_url() . '/plugins');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'themes');
}
if (!defined('WP_CONTENT_URL')) {
    define('WP_CONTENT_URL', site_url() . '/themes');
}
