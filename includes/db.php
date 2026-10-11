<?php
/**
 * 数据库连接与引导
 * 站点根目录统一通过本文件引入配置并建立 PDO 连接。
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

$configFile = BASE_PATH . '/config.php';

// 尚未安装 → 跳转安装向导
if (!file_exists($configFile)) {
    header('Location: install.php');
    exit;
}

require $configFile;

if (!defined('INSTALLED') || !INSTALLED) {
    header('Location: install.php');
    exit;
}

/**
 * 获取全局 PDO 连接（单例）。
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
