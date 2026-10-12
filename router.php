<?php
/**
 * ============================================================
 *  PHP 内置服务器路由兜底（router.php）
 * ------------------------------------------------------------
 *  当你用 PHP 内置开发服务器（php -S）而不是 Apache/Nginx 时，
 *  .htaccess 不会生效，/submit、/my-modpacks、/messages 以及
 *  /modpack/x、/download/x、/login … 等伪静态地址会 404。
 *
 *  使用方法（在项目根目录执行）：
 *    php -S 127.0.0.1:8080 router.php
 *  然后访问 http://127.0.0.1:8080/ 即可，所有伪静态地址都可用。
 *
 *  Apache / Nginx 环境无需本文件。
 * ============================================================
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rtrim($path, '/');
if ($path === '') $path = '/';

$direct = [
    '/'            => 'index.php',
    '/login'       => 'login.php',
    '/register'    => 'register.php',
    '/logout'      => 'logout.php',
    '/profile'     => 'profile.php',
    '/submit'      => 'submit.php',
    '/my-modpacks' => 'my-modpacks.php',
    '/messages'    => 'messages.php',
];

if (isset($direct[$path])) {
    require __DIR__ . '/' . $direct[$path];
    return true;
}

if (preg_match('#^/modpack/([^/]+)$#', $path, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/detail.php';
    return true;
}

if (preg_match('#^/download/([^/]+)/([^/]+)$#', $path, $m)) {
    $_GET['slug'] = $m[1];
    $_GET['dl'] = $m[2];
    require __DIR__ . '/download.php';
    return true;
}

if (preg_match('#^/download/([^/]+)$#', $path, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/download.php';
    return true;
}

if (preg_match('#^/api/([a-z_]+)$#', $path, $m)) {
    $_GET['act'] = $m[1];
    require __DIR__ . '/api.php';
    return true;
}

// 其余（静态资源 / admin / uploads 等）交给 PHP 内置服务器默认处理
return false;
