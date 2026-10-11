<?php
/**
 * 轻量钩子系统（Action / Filter）
 * 供插件通过 add_action / add_filter 扩展站点功能
 * 使用全局 $GLOBALS['mc_actions'] / $GLOBALS['mc_filters'] 存储回调
 */

/** 注册动作回调 */
function add_action($hook, $cb, $priority = 10)
{
    $GLOBALS['mc_actions'][$hook][(int)$priority][] = $cb;
}

/** 触发动作 */
function do_action($hook, ...$args)
{
    if (empty($GLOBALS['mc_actions'][$hook])) return;
    $list = $GLOBALS['mc_actions'][$hook];
    ksort($list);
    foreach ($list as $prioCbs) {
        foreach ($prioCbs as $cb) {
            if (is_callable($cb)) $cb(...$args);
        }
    }
}

/** 注册过滤器回调 */
function add_filter($hook, $cb, $priority = 10)
{
    $GLOBALS['mc_filters'][$hook][(int)$priority][] = $cb;
}

/** 应用过滤器，返回处理后的值 */
function apply_filters($hook, $value, ...$args)
{
    if (empty($GLOBALS['mc_filters'][$hook])) return $value;
    $list = $GLOBALS['mc_filters'][$hook];
    ksort($list);
    foreach ($list as $prioCbs) {
        foreach ($prioCbs as $cb) {
            if (is_callable($cb)) $value = $cb($value, ...$args);
        }
    }
    return $value;
}
