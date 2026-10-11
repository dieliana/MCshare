<?php
/**
 * 插件加载器
 * 读取 settings 中启用的插件（active_plugins JSON），逐一 require 其 plugin.php
 * 插件目录：plugins/插件id/plugin.php（返回 $pluginInfo 元信息 + 通过 add_action/add_filter 注册扩展）
 */
require_once __DIR__ . '/hooks.php';

function load_plugins()
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $active = json_decode((string)setting('active_plugins', '[]'), true);
    if (!is_array($active) || !$active) return;

    $dir = dirname(__DIR__) . '/plugins';
    foreach ($active as $id) {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$id);
        if ($id === '') continue;
        $f = $dir . '/' . $id . '/plugin.php';
        if (is_file($f)) {
            try { require $f; } catch (Throwable $e) { /* 插件异常不阻断前台 */ }
        }
    }
}
