<?php
/**
 * 示例插件 · demo
 * 演示插件结构与钩子用法：向页面底部注入一段文字，并给站点名加后缀
 * 结构：plugins/demo/plugin.php（本文件）+ 可选资源文件
 */
$pluginInfo = [
    'id'      => 'demo',
    'name'    => '示例插件',
    'version' => '1.0.0',
    'author'  => '你',
    'desc'    => '演示插件系统：在页面底部注入文字、给站点名加后缀。复制本目录并按需修改即可二创。',
];
return $pluginInfo;

// 动作：页面底部注入
add_action('mc_body_end', function () {
    echo '<div style="max-width:1200px;margin:20px auto;text-align:center;color:var(--muted);font-size:12px">插件注入内容 · 由示例插件渲染</div>';
});

// 过滤器：给站点名加后缀
add_filter('mc_site_name', function ($name) {
    return $name . ' · Powered by Plugin';
});
