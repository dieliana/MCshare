<?php
/**
 * WP 兼容层 · 插件
 * 识别 WordPress 插件（主文件头部含 "Plugin Name:"）并加载其主文件。
 * 加载时模拟 WP 常用全局/函数，使其 add_action/add_filter/add_shortcode/get_option/wp_enqueue_* 生效。
 */

/** 解析 WP 插件主文件头部注释 */
if (!function_exists('mc_wp_plugin_info')) {
    function mc_wp_plugin_info($file)
    {
        if (!is_file($file)) return null;
        $head = (string)file_get_contents($file);
        $head = substr($head, 0, 8192);
        if (stripos($head, 'Plugin Name:') === false) return null;
        $fields = ['name' => 'Plugin Name', 'uri' => 'Plugin URI', 'version' => 'Version', 'desc' => 'Description', 'author' => 'Author'];
        $info = ['file' => realpath($file), 'dir' => dirname(realpath($file))];
        foreach ($fields as $k => $label) {
            if (preg_match('/^' . preg_quote($label, '/') . '\s*:\s*(.*)$/mi', $head, $m)) {
                $info[$k] = trim($m[1]);
            }
        }
        $info['type'] = 'wp';
        return $info;
    }
}

/** 在插件目录中定位主文件（含 Plugin Name 头的 php） */
if (!function_exists('mc_wp_find_main_file')) {
    function mc_wp_find_main_file($dir)
    {
        foreach (glob(rtrim($dir, '/') . '/*.php') ?: [] as $f) {
            if (mc_wp_plugin_info($f)) return $f;
        }
        return null;
    }
}

/** 加载全部 WP 插件 */
if (!function_exists('load_wp_plugins')) {
    function load_wp_plugins()
    {
        $active = json_decode((string)setting('active_plugins', '[]'), true);
        if (!is_array($active)) return;
        $pluginsDir = ABSPATH . 'plugins';
        foreach ($active as $id) {
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$id);
            if ($id === '') continue;
            $dir = $pluginsDir . '/' . $id;
            if (!is_dir($dir)) continue;
            $main = mc_wp_find_main_file($dir);
            if (!$main) continue;
            $GLOBALS['wp_active_plugin_file'] = $main;
            $GLOBALS['wp_active_plugin_dir'] = $dir;
            try {
                require $main;
            } catch (Throwable $e) {
                // 插件异常不阻断前台
            }
            unset($GLOBALS['wp_active_plugin_file'], $GLOBALS['wp_active_plugin_dir']);
        }
    }
}

/* WP 插件常用辅助函数 */
if (!function_exists('plugin_basename')) {
    function plugin_basename($file)
    {
        $pluginsDir = rtrim(WP_PLUGIN_DIR, '/') . '/';
        return str_replace('\\', '/', str_replace($pluginsDir, '', $file));
    }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return trailingslashit(dirname($file)); }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) { return site_url() . '/plugins/' . basename(dirname($file)) . '/'; }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $cb) { /* 简化：激活钩子不执行 */ }
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $cb) { /* 简化 */ }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($string) { return rtrim($string, '/') . '/'; }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($string) { return rtrim($string, '/'); }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir()
    {
        $base = site_url() . '/uploads';
        $path = ABSPATH . 'uploads';
        return ['basedir' => $path, 'baseurl' => $base, 'path' => $path, 'url' => $base];
    }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true) { /* 简化 */ }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) { return ''; }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return false; }
}
