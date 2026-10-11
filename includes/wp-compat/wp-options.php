<?php
/**
 * WP 兼容层 · 设置选项（映射到 MCshare settings 表）
 * get_option / update_option / add_option / delete_option
 * transient 简化：直接持久化，不做过期（与 MCshare settings 表共用）
 */

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        $v = setting((string)$name, null);
        if ($v === null) return $default;
        // 若曾以 JSON 序列化存储则还原
        $j = json_decode($v, true);
        return $j !== null && json_last_error() === JSON_ERROR_NONE ? $j : $v;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
        $st->execute([(string)$name, is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)]);
        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
    {
        $exists = db()->prepare('SELECT COUNT(*) FROM settings WHERE skey = ?');
        $exists->execute([(string)$name]);
        if ((int)$exists->fetchColumn() > 0) return false;
        $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)');
        $st->execute([(string)$name, is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)]);
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($name)
    {
        $st = db()->prepare('DELETE FROM settings WHERE skey = ?');
        $st->execute([(string)$name]);
        return $st->rowCount() > 0;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($name)
    {
        $v = setting('trans_' . $name, null);
        return $v === null ? false : $v;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($name, $value, $expiration = 0)
    {
        return update_option('trans_' . $name, $value);
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($name)
    {
        return delete_option('trans_' . $name);
    }
}
