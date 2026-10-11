<?php
/**
 * 前台 · 退出登录
 */
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/includes/functions.php';
ensure_session();

unset($_SESSION['user_id']);
redirect('index.php');
