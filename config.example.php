<?php
/**
 * ============================================================
 *  MC Modpack Hub · 配置模板
 * ------------------------------------------------------------
 *  运行 install.php 安装向导后会自动生成 config.php，
 *  本文件仅作参考模板，无需手动修改。
 *  如需手动部署，可复制为 config.php 后填写以下常量。
 * ============================================================
 */

// ---- 数据库连接 ----
define('DB_HOST', '127.0.0.1');   // 数据库地址
define('DB_PORT', '3306');        // 数据库端口
define('DB_NAME', 'mcshare');     // 数据库名
define('DB_USER', 'root');        // 数据库用户名
define('DB_PASS', '');            // 数据库密码
define('DB_CHARSET', 'utf8mb4');

// ---- 站点信息 ----
define('SITE_NAME', 'MC Modpack Hub');      // 站点名称
define('SITE_DESC', '我的世界整合包资源分享'); // 站点描述
define('SITE_URL', '');   // 站点根地址，留空自动检测；也可写 https://你的域名

// ---- 上传目录（相对站点根） ----
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_URL', 'uploads');

// ---- 安装状态（安装完成后置为 true） ----
define('INSTALLED', false);
