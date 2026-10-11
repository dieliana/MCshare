<?php
/**
 * WordPress 兼容层 · 总入口
 * 提供 WordPress 主题/插件运行所需的常用 API 适配。
 * 由 includes/plugins.php 的 load_plugins() 在页面首加载时引入。
 * 说明：本层为「适配层」，支持结构合规、依赖常见核心 API（钩子/短代码/选项/enqueue）的主题与插件；
 * 依赖 $wpdb / WP_Query / 完整用户系统的重型 WP 生态插件不在支持范围。
 */
require_once __DIR__ . '/wp-constants.php';
require_once __DIR__ . '/wp-hooks.php';
require_once __DIR__ . '/wp-i18n.php';
require_once __DIR__ . '/wp-options.php';
require_once __DIR__ . '/wp-assets.php';
require_once __DIR__ . '/wp-shortcodes.php';
require_once __DIR__ . '/wp-theme.php';
require_once __DIR__ . '/wp-plugin.php';
