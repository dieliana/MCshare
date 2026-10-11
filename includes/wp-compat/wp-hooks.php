<?php
/**
 * WP 兼容层 · 钩子补充
 * 现有 includes/hooks.php 提供 add_action/do_action/add_filter/apply_filters；
 * 这里补上 WordPress 主题/插件常用但尚未提供的方法。
 */

if (!function_exists('has_action')) {
    function has_action($hook, $cb = null)
    {
        if (!isset($GLOBALS['mc_actions'][$hook])) return false;
        if ($cb === null) return count($GLOBALS['mc_actions'][$hook]) > 0;
        foreach ($GLOBALS['mc_actions'][$hook] as $prioCbs) {
            foreach ($prioCbs as $registered) {
                if ($registered === $cb || $registered == $cb) return true;
            }
        }
        return false;
    }
}

if (!function_exists('has_filter')) {
    function has_filter($hook, $cb = null)
    {
        if (!isset($GLOBALS['mc_filters'][$hook])) return false;
        if ($cb === null) return count($GLOBALS['mc_filters'][$hook]) > 0;
        foreach ($GLOBALS['mc_filters'][$hook] as $prioCbs) {
            foreach ($prioCbs as $registered) {
                if ($registered === $cb || $registered == $cb) return true;
            }
        }
        return false;
    }
}

if (!function_exists('remove_action')) {
    function remove_action($hook, $cb, $priority = 10)
    {
        if (!isset($GLOBALS['mc_actions'][$hook])) return false;
        foreach ($GLOBALS['mc_actions'][$hook] as $prio => $prioCbs) {
            foreach ($prioCbs as $i => $registered) {
                if ($registered === $cb || $registered == $cb) {
                    unset($GLOBALS['mc_actions'][$hook][$prio][$i]);
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter($hook, $cb, $priority = 10)
    {
        if (!isset($GLOBALS['mc_filters'][$hook])) return false;
        foreach ($GLOBALS['mc_filters'][$hook] as $prio => $prioCbs) {
            foreach ($prioCbs as $i => $registered) {
                if ($registered === $cb || $registered == $cb) {
                    unset($GLOBALS['mc_filters'][$hook][$prio][$i]);
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('doing_action')) {
    function doing_action($hook)
    {
        return false;
    }
}

if (!function_exists('current_filter')) {
    function current_filter()
    {
        return '';
    }
}
