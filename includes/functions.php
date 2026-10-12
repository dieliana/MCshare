<?php
/**
 * 通用辅助函数
 */

/** HTML 转义输出 */
function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 获取站点根地址（带尾斜杠） */
function site_url()
{
    if (defined('SITE_URL') && SITE_URL) {
        return rtrim(SITE_URL, '/') . '/';
    }
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = ($https ? 'https://' : 'http://') . $host;
    // 当前脚本所在目录；剥掉后台/资源等子目录，定位回站点根，避免后台页拼错路径
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = preg_replace('#/(admin|assets|includes|uploads)(/.*)?$#', '', $dir);
    if ($dir !== '/' && $dir !== '.' && $dir !== '') {
        $base .= rtrim($dir, '/');
    }
    return rtrim($base, '/') . '/';
}

/** 读取站点设置 */
function setting($key, $default = '', $refresh = false)
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        try {
            $st = db()->query('SELECT skey, svalue FROM settings');
            foreach ($st as $row) {
                $cache[$row['skey']] = $row['svalue'];
            }
        } catch (Exception $e) {
            // 表不存在或未初始化时静默返回默认值
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/** 生成 URL 友好别名（slug） */
function slugify($text)
{
    $text = trim((string)$text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
    $text = trim($text, '-');
    $text = mb_strtolower($text, 'UTF-8');
    return $text !== '' ? $text : 'item-' . substr(bin2hex(random_bytes(4)), 0, 6);
}

/** CSRF 令牌 */
function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/** 校验 CSRF 令牌 */
function csrf_check($token)
{
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$token);
}

/** 安全跳转 */
function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

/** 处理图片上传到指定子目录，成功返回相对 uploads 的路径（如 covers/xxx.jpg），失败返回 null */
function upload_image(array $file, string $subdir): ?string
{
    if (!isset($file['error']) || is_array($file['error'])) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return null;

    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) return null;

    $info = @getimagesize($tmp);
    if ($info === false) return null;

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$info['mime']])) return null;
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) return null;

    $sub = trim($subdir, '/\\');
    $ext  = $allowed[$info['mime']];
    $name = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dir  = rtrim(UPLOAD_DIR, '/\\') . '/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) return null;
    make_thumb($dir . '/' . $name); // 生成列表用缩略图（失败静默，不影响上传）
    return $sub . '/' . $name;
}

/** 处理封面图上传（存到 covers 子目录） */
function upload_cover(array $file, string $uploadDir): ?string
{
    return upload_image($file, 'covers');
}

/**
 * 整合包安装包上传（存到 modpacks 子目录）
 * 允许 zip/rar/7z/jar/mrpack/json/txt 等常见整合包与文本格式，≤ 500MB
 */
function upload_modpack_file(array $file, string $subdir = 'modpacks'): ?string
{
    if (!isset($file['error']) || is_array($file['error'])) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) return null;
    if (($file['size'] ?? 0) > 500 * 1024 * 1024) return null;
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $allowed = ['zip', 'rar', '7z', 'jar', 'mrpack', 'json', 'txt'];
    if ($ext !== '' && !in_array($ext, $allowed, true)) return null;
    $sub = trim($subdir, '/\\');
    $name = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . ($ext ?: 'file');
    $dir  = rtrim(UPLOAD_DIR, '/\\') . '/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) return null;
    return $sub . '/' . $name;
}

/**
 * 处理图标类文件上传（Logo / Favicon）
 * 支持：jpg/png/webp/gif + svg + ico，≤ 8MB
 * SVG 仅接受纯矢量（含 <script 一律拒绝，防 XSS）
 */
function upload_icon(array $file, string $subdir): ?string
{
    if (!isset($file['error']) || is_array($file['error'])) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return null;

    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) return null;
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) return null;

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $ok  = false;

    if ($ext === 'svg') {
        $data = @file_get_contents($tmp);
        if ($data !== false && stripos($data, '<svg') !== false) {
            // 彻底移除脚本与危险内容，防 SVG XSS（仅查 <script 字面可被 <svg onload> 等绕过）
            $data = preg_replace('/<\s*script\b[^>]*>.*?<\s*\/\s*script\s*>/is', '', $data);
            $data = preg_replace('/<\s*script\b[^>]*\/?>/i', '', $data);
            $data = preg_replace('/<\s*foreignObject\b[^>]*>.*?<\s*\/\s*foreignObject\s*>/is', '', $data);
            $data = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $data);
            $data = preg_replace('/javascript\s*:/i', '', $data);
            $data = preg_replace('/expression\s*:/i', '', $data);
            @file_put_contents($tmp, $data);
            $ok = true;
        }
    } elseif ($ext === 'ico') {
        // ICO 用 getimagesize 也能识别
        if (@getimagesize($tmp) !== false) $ok = true;
    } else {
        $info = @getimagesize($tmp);
        if ($info !== false && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            $ok = true;
        }
    }
    if (!$ok) return null;

    $sub = trim($subdir, '/\\');
    $name = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dir  = rtrim(UPLOAD_DIR, '/\\') . '/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) return null;
    return $sub . '/' . $name;
}

/** 封面图完整 URL（支持相对路径或完整外链） */
function cover_url($cover)
{
    if (!$cover) return '';
    $cover = ltrim($cover, '/');
    if (preg_match('#^https?://#i', $cover)) return $cover;
    return site_url() . UPLOAD_URL . '/' . $cover;
}

/**
 * 为图片生成压缩缩略图（webp 优先，回退 jpg）
 * 仅当原图宽度超过 $maxW 才生成；命名 {原文件名}_thumb.{webp|jpg}，与原图同目录
 */
function make_thumb($srcPath, $maxW = 480)
{
    if (!function_exists('imagecreatetruecolor')) return null;
    if (!is_file($srcPath)) return null;
    $info = @getimagesize($srcPath);
    if ($info === false) return null;
    [$w, $h] = $info;
    $mime = $info['mime'];
    if ($w <= $maxW) return null; // 原图已足够小，不生成

    switch ($mime) {
        case 'image/jpeg': $im = @imagecreatefromjpeg($srcPath); break;
        case 'image/png':  $im = @imagecreatefrompng($srcPath); break;
        case 'image/webp': $im = @imagecreatefromwebp($srcPath); break;
        case 'image/gif':  $im = @imagecreatefromgif($srcPath); break;
        default: return null;
    }
    if (!$im) return null;

    $nh = (int)round($h * $maxW / $w);
    $dst = imagecreatetruecolor($maxW, $nh);
    if (!$dst) { return null; }
    if (in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $maxW, $nh, $w, $h);
    $dir = dirname($srcPath);
    $base = pathinfo($srcPath, PATHINFO_FILENAME);
    if (function_exists('imagewebp')) {
        $tp = $dir . '/' . $base . '_thumb.webp';
        if (@imagewebp($dst, $tp, 75)) { return basename($tp); }
    }
    $tp = $dir . '/' . $base . '_thumb.jpg';
    if (@imagejpeg($dst, $tp, 78)) { return basename($tp); }
    return null;
}

/**
 * 富文本净化：允许常见排版标签，剥离脚本/事件属性/危险协议
 * 用于整合包详细介绍等用户提交内容的安全展示
 */
function rich_html($html)
{
    if ($html === null || $html === '') return '';
    $allowed = '<p><br><b><strong><i><em><u><s><del><span><div><ul><ol><li><h1><h2><h3><h4><h5><blockquote><code><pre><table><thead><tbody><tr><td><th><hr><img><a>';
    $html = strip_tags($html, $allowed);
    // 剥离事件属性 onXxx
    $html = preg_replace('/\son[a-z]+\s*=\s*(["\'])[^"\']*\1/i', '', $html);
    $html = preg_replace('/\son[a-z]+\s*=\s*[^\s>]+/i', '', $html);
    // 危险协议
    $html = preg_replace('/javascript\s*:/i', '', $html);
    $html = preg_replace('/\b(?:expression|vbscript)\s*:/i', '', $html);
    // a 仅允许 http(s)/锚点/相对
    $html = preg_replace('/<a\s+href\s*=\s*["\'](?!https?:|\/|#|mailto:)[^"\']*["\']/i', '<a href="#', $html);
    return $html;
}

/**
 * 批量生成 uploads 下所有图片的缩略图，返回生成的个数（跳过已存在的 _thumb）
 */
function generate_all_thumbs()
{
    if (!defined('UPLOAD_DIR')) return 0;
    $root = rtrim(UPLOAD_DIR, '/\\');
    if (!is_dir($root)) return 0;
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $ext = strtolower($f->getExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) continue;
        if (strpos($f->getFilename(), '_thumb.') !== false) continue;
        if (make_thumb($f->getPathname())) $n++;
    }
    return $n;
}

/**
 * 列表/卡片用缩略图 URL
 * 若同目录存在 _thumb.webp / _thumb.jpg 则用缩略图，否则回退原图；外链不处理
 */
function thumb_url($cover)
{
    if (!$cover) return '';
    $cover = ltrim($cover, '/');
    if (preg_match('#^https?://#i', $cover)) return $cover;
    $dir = dirname($cover);
    $base = pathinfo($cover, PATHINFO_FILENAME);
    foreach (['webp', 'jpg'] as $e) {
        if (is_file(rtrim(UPLOAD_DIR, '/\\') . '/' . $dir . '/' . $base . '_thumb.' . $e)) {
            return site_url() . UPLOAD_URL . '/' . $dir . '/' . $base . '_thumb.' . $e;
        }
    }
    return cover_url($cover);
}

/** 解析作者社交链接（JSON 字符串 → 数组） */
function parse_author_links($json): array
{
    $out = ['github' => '', 'bilibili' => '', 'douyin' => '', 'website' => ''];
    if (!$json) return $out;
    $data = json_decode($json, true);
    if (!is_array($data)) return $out;
    foreach ($out as $k => $v) {
        if (isset($data[$k])) $out[$k] = trim((string)$data[$k]);
    }
    return $out;
}

/** 内联 SVG 图标（无需外部字体，内置常用图标） */
function icon(string $name, int $size = 16): string
{
    $map = [
        'eye'      => 'fa-solid fa-eye',
        'download' => 'fa-solid fa-download',
        'github'   => 'fa-brands fa-github',
        'bilibili' => 'fa-brands fa-bilibili',
        'douyin'   => 'fa-brands fa-tiktok',
        'website'  => 'fa-solid fa-globe',
    ];
    if (!isset($map[$name])) return '';
    return '<i class="' . $map[$name] . '" style="font-size:' . $size . 'px" aria-hidden="true"></i>';
}

function loader_label($loader): string
{
    if (!$loader) return '';
    $map = [
        'forge'    => 'Forge',
        'fabric'   => 'Fabric',
        'neoforge' => 'NeoForge',
        'quilt'    => 'Quilt',
        'datapack' => '数据包',
        'vanilla'  => '原版',
        'other'    => '其他',
    ];
    $key = strtolower((string)$loader);
    return $map[$key] ?? (string)$loader;
}

/** 格式化时间 */
function fmt_date($dt)
{
    return $dt ? date('Y-m-d', strtotime($dt)) : '-';
}

/** 友好数量显示 */
function num($n)
{
    $n = (int)$n;
    if ($n >= 10000) return round($n / 10000, 1) . 'w';
    if ($n >= 1000)  return round($n / 1000, 1) . 'k';
    return (string)$n;
}

/* ============================================================
   会话 / 前台用户
   ============================================================ */

/** 确保会话已启动 */
function ensure_session()
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    // 会话启动时确保 users.is_super 列存在（兼容升级前旧库）
    static $col = false;
    if ($col === false) { mc_ensure_super_column(); $col = true; }
}

/** 确保 users 表存在 is_super 列（超级管理员标记），缺失则自动补列 */
function mc_ensure_super_column()
{
    try {
        $r = db()->query("SHOW COLUMNS FROM users LIKE 'is_super'");
        if (!$r || $r->fetch() === false) {
            db()->exec("ALTER TABLE users ADD COLUMN is_super TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
        }
    } catch (Exception $e) {
        // 忽略（无连接等场景），后续按需再补
    }
}

/** 是否已登录（前台用户） */
function is_logged(): bool
{
    ensure_session();
    return !empty($_SESSION['user_id']);
}

/** 获取当前登录用户（数组或 null），带封禁检测 */
function current_user()
{
    ensure_session();
    if (empty($_SESSION['user_id'])) return null;
    static $u = false;
    if ($u === false) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ? AND status = 1');
        $st->execute([$_SESSION['user_id']]);
        $u = $st->fetch() ?: null;
        if (!$u) unset($_SESSION['user_id']); // 已被封禁则清除登录态
    }
    return $u;
}

/** 前台登录守卫：未登录则记录回跳地址并跳转登录页 */
function user_login_required()
{
    if (!is_logged()) {
        ensure_session();
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        redirect('/login');
    }
}

/* ============================================================
   登录算术验证码（无需 GD）
   ============================================================ */

/** 生成并返回算术题文案，答案存会话 */
function captcha_get(): string
{
    ensure_session();
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    $_SESSION['captcha'] = $a + $b;
    return "{$a} + {$b} = ?";
}

/** 校验算术验证码（一次性，用完即失效） */
function captcha_check($ans): bool
{
    ensure_session();
    $v = $_SESSION['captcha'] ?? null;
    unset($_SESSION['captcha']);
    return $v !== null && (string)(int)$ans === (string)$v;
}

/* ============================================================
   邮箱验证码（SMTP 发送，10 分钟有效，存会话）
   ============================================================ */

/** 生成并发送邮箱验证码到指定邮箱，返回是否发送成功 */
function send_email_code(string $email, string $purpose, string $subject, string $tip): bool
{
    ensure_session();
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['email_code'][$purpose] = [
        'code'    => $code,
        'email'   => strtolower(trim($email)),
        'expires' => time() + 600,
    ];
    $siteName = setting('site_name', defined('SITE_NAME') ? SITE_NAME : 'MC Modpack Hub');
    $contentHtml = '<p>' . nl2br(e($tip)) . '</p>'
        . '<p style="font-size:26px;font-weight:bold;letter-spacing:6px;color:#22c55e;background:#f0fdf4;border:1px dashed #bbf7d0;border-radius:10px;padding:14px 20px;text-align:center;margin:18px 0">' . e($code) . '</p>'
        . '<p style="margin:0;color:#7a8a98;font-size:12px">验证码 10 分钟内有效，请勿泄露给他人。</p>';
    $body = mail_template($subject, $contentHtml);
    return smtp_mail($email, $subject, $body);
}

/** 校验邮箱验证码（一次性） */
function verify_email_code(string $email, string $purpose, string $code): bool
{
    ensure_session();
    $d = $_SESSION['email_code'][$purpose] ?? null;
    if (!$d) return false;
    unset($_SESSION['email_code'][$purpose]);
    if (strtolower(trim($email)) !== $d['email']) return false;
    if (time() > $d['expires']) return false;
    return hash_equals($d['code'], strval($code));
}

/* ============================================================
   邮件美化模板
   ============================================================ */

/** 生成一封站点风格的美化 HTML 邮件 */
function mail_template(string $title, string $contentHtml, string $link = '', string $linkText = '查看详情'): string
{
    $siteName = setting('site_name', 'MC Modpack Hub');
    $logoUrl  = setting('logo', '') ? cover_url(setting('logo')) : '';
    $logoHtml = $logoUrl
        ? '<img src="' . e($logoUrl) . '" alt="" style="height:38px;width:38px;border-radius:8px;object-fit:cover">'
        : '<span style="width:34px;height:34px;border-radius:8px;background:#22c55e;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:700">' . e(mb_substr($siteName, 0, 1)) . '</span>';
    $btn = $link
        ? '<p style="margin:22px 0 6px;text-align:center"><a href="' . e($link) . '" style="display:inline-block;background:#22c55e;color:#fff;text-decoration:none;font-weight:600;padding:11px 26px;border-radius:10px">' . e($linkText) . '</a></p>'
        : '';
    return '<!DOCTYPE html><html lang="zh-CN"><body style="margin:0;padding:0;background:#eef2ef;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">
<div style="max-width:560px;margin:28px auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 10px 34px rgba(15,40,28,.10)">
  <div style="background:#0b1320;padding:22px 30px;display:flex;align-items:center;gap:12px">' . $logoHtml . '<span style="color:#fff;font-size:18px;font-weight:700">' . e($siteName) . '</span></div>
  <div style="padding:28px 30px;color:#22303f">
    <h2 style="margin:0 0 14px;font-size:20px;color:#14202e">' . e($title) . '</h2>
    <div style="font-size:14px;line-height:1.75">' . $contentHtml . '</div>
    ' . $btn . '
  </div>
  <div style="background:#f6f8f7;padding:16px 30px;font-size:12px;color:#8a97a4">本邮件由 ' . e($siteName) . ' 自动发送，请勿直接回复。</div>
</div></body></html>';
}

/** 站内信联动邮件：给用户发送一条美化通知邮件（失败静默，不阻断主流程） */
function send_notify_email(int $userId, string $type, string $title, string $content, string $link = '')
{
    try {
        if (setting('smtp_host', '') === '') return; // 未配置 SMTP 则跳过
        $st = db()->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([$userId]);
        $email = $st->fetchColumn();
        if (!$email) return;
        $body = mail_template($title, '<p style="margin:0">' . nl2br(e($content)) . '</p>', $link, '查看详情');
        $ok = smtp_mail((string)$email, $title, $body);
        if (!$ok && smtp_error() !== '') {
            @error_log('[notify-mail] ' . smtp_error()); // 记录失败原因，便于排查（不阻断站内信）
        }
    } catch (Throwable $e) { /* 静默 */ }
}

/* ============================================================
   SMTP 邮件发送（纯 PHP，无需扩展/Composer）
   配置来自后台「站点设置」：smtp_host/port/user/pass/from/fromname/secure
   ============================================================ */

/**
 * 通过 SMTP 发送一封 HTML 邮件。
 * @param string $to   收件人
 * @param string $subject 主题
 * @param string $bodyHTML 正文（HTML）
 * @return bool 是否发送成功（失败原因见 smtp_error()）
 */
function smtp_mail(string $to, string $subject, string $bodyHTML): bool
{
    $host     = setting('smtp_host', '');
    $port     = (int)setting('smtp_port', 587);
    $user     = setting('smtp_user', '');
    $pass     = setting('smtp_pass', '');
    $from     = setting('smtp_from', $user ?: 'noreply@example.com');
    $fromName = setting('smtp_fromname', setting('site_name', 'MC Modpack Hub'));
    $secure   = setting('smtp_secure', 'tls'); // tls / ssl / none

    if ($host === '' || $from === '') {
        $GLOBALS['smtp_error'] = '未配置 SMTP 服务器';
        return false;
    }

    $encHost = $host;
    if ($secure === 'ssl' && $port !== 465) $port = 465;
    if ($secure === 'ssl') $encHost = 'ssl://' . $host;

    $fp = @fsockopen($encHost, $port, $errno, $errstr, 30);
    if (!$fp) {
        $GLOBALS['smtp_error'] = "连接失败：{$errstr} ({$errno})";
        return false;
    }
    stream_set_timeout($fp, 30);

    $log = [];
    $readLine = function () use ($fp, &$log) {
        $line = fgets($fp, 8192);
        $log[] = 'S: ' . trim((string)$line);
        return $line;
    };
    $writeLine = function ($s) use ($fp, &$log) {
        $log[] = 'C: ' . trim($s);
        fwrite($fp, $s . "\r\n");
    };

    // 读取命令响应：丢弃非预期的残留行（如 EHLO 多行回执 250-），直到读到期望的状态码
    $readCode = function (array $expect, $max = 40) use ($readLine) {
        for ($i = 0; $i < $max; $i++) {
            $line = $readLine();
            if ($line === false || trim((string)$line) === '') continue;
            $code = (int)substr((string)$line, 0, 3);
            if (in_array($code, $expect, true)) return $code;
            if ($code >= 400) return $code; // 硬错误直接返回
            // 其它码（250- 残留、334、354 等）继续读
        }
        return 0;
    };

    $readLine(); // banner 220

    // EHLO 多行回执：读到不以 <code>- 开头的行为止（上限 40 行）
    $writeLine('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $ehlo = $readLine();
    while ($ehlo !== false && strlen((string)$ehlo) >= 4 && (int)substr((string)$ehlo, 3, 1) === '-') {
        $ehlo = $readLine();
    }

    if ($secure === 'tls' && function_exists('stream_socket_enable_crypto')) {
        $writeLine('STARTTLS');
        $c = $readCode([220], 40);
        if ($c !== 220) {
            $GLOBALS['smtp_error'] = 'STARTTLS 失败（返回码 ' . $c . '）';
            fclose($fp);
            return false;
        }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $GLOBALS['smtp_error'] = 'TLS 握手失败（请确认 PHP 已开启 openssl 扩展）';
            fclose($fp);
            return false;
        }
        // TLS 之后重新 EHLO
        $writeLine('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $ehlo = $readLine();
        while ($ehlo !== false && strlen((string)$ehlo) >= 4 && (int)substr((string)$ehlo, 3, 1) === '-') {
            $ehlo = $readLine();
        }
    }

    if ($user !== '') {
        $writeLine('AUTH LOGIN');
        $c = $readCode([334], 40);
        if ($c !== 334) {
            $GLOBALS['smtp_error'] = '服务器不接受 AUTH LOGIN（返回码 ' . $c . '）';
            fclose($fp);
            return false;
        }
        $writeLine(base64_encode($user));
        $c = $readCode([334], 40);
        if ($c !== 334) {
            $GLOBALS['smtp_error'] = '用户名被拒绝（返回码 ' . $c . '）';
            fclose($fp);
            return false;
        }
        $writeLine(base64_encode($pass));
        $c = $readCode([235], 40);
        if ($c !== 235) {
            $GLOBALS['smtp_error'] = '认证失败：授权码或账号错误（返回码 ' . $c . '）';
            fclose($fp);
            return false;
        }
    }

    $writeLine('MAIL FROM:<' . $from . '>');
    if ($readCode([250], 40) !== 250) { fclose($fp); $GLOBALS['smtp_error'] = 'MAIL FROM 失败'; return false; }
    $writeLine('RCPT TO:<' . $to . '>');
    if ($readCode([250], 40) !== 250) { fclose($fp); $GLOBALS['smtp_error'] = 'RCPT TO 失败'; return false; }

    $writeLine('DATA');
    if ($readCode([354], 40) !== 354) { fclose($fp); $GLOBALS['smtp_error'] = 'DATA 失败'; return false; }

    $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n"
        . "To: <{$to}>\r\n"
        . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n";
    $body    = chunk_split(base64_encode($bodyHTML));
    $writeLine($headers . "\r\n" . $body);
    $writeLine('.');
    if ($readCode([250], 40) !== 250) {
        $GLOBALS['smtp_error'] = '邮件正文发送失败';
        fclose($fp);
        return false;
    }

    $writeLine('QUIT');
    $readLine();
    fclose($fp);
    $GLOBALS['smtp_error'] = '';
    return true;
}

/** 获取最近一次 SMTP 发送的错误信息 */
function smtp_error(): string
{
    return $GLOBALS['smtp_error'] ?? '';
}

// ============ 角色与权限 ============

/** 是否为管理员（可传 null 使用当前登录用户）——普通管理员或超级管理员 */
function is_admin($u = null)
{
    if ($u === null) $u = current_user();
    return is_array($u) && ($u['role'] ?? 'user') === 'admin';
}

/** 是否为超级管理员（仅超级管理员可访问后台 /admin/） */
function is_super_admin($u = null)
{
    if ($u === null) $u = current_user();
    return is_array($u) && ($u['role'] ?? 'user') === 'admin' && (int)($u['is_super'] ?? 0) === 1;
}

/** 是否为作者（作者或管理员均视为作者） */
function is_author($u = null)
{
    if ($u === null) $u = current_user();
    return is_array($u) && in_array($u['role'] ?? 'user', ['author', 'admin'], true);
}

/** 用户能否管理某整合包（管理员任意；作者可管理自己的） */
function can_manage_modpack($u, $mp)
{
    if (!is_array($u)) return false;
    if (is_admin($u)) return true;
    return in_array($u['role'] ?? 'user', ['author'], true)
        && isset($mp['user_id']) && (int)$mp['user_id'] === (int)$u['id'];
}

/** 用户能否管理某评论（评论所属整合包作者的作者/管理员） */
function can_manage_comment($u, $comment)
{
    if (is_admin($u)) return true;
    return in_array($u['role'] ?? 'user', ['author'], true)
        && isset($comment['mp_user_id']) && (int)$comment['mp_user_id'] === (int)$u['id'];
}

// ============ 禁言 ============

/** 是否处于禁言中（仍可登录但不能评论 / 发布；含到期自动解除） */
function is_muted($u = null)
{
    if ($u === null) $u = current_user();
    if (!is_array($u)) return false;
    if ((int)($u['muted'] ?? 0) !== 1) return false;
    $until = $u['muted_until'] ?? null;
    if ($until && strtotime($until) <= time()) {
        db()->prepare('UPDATE users SET muted = 0, muted_until = NULL, mute_reason = "" WHERE id = ?')->execute([(int)$u['id']]);
        return false;
    }
    return true;
}

/** 禁言到期描述（未禁言返回空串） */
function mute_until_text($u)
{
    if (!is_array($u) || (int)($u['muted'] ?? 0) !== 1) return '';
    $until = $u['muted_until'] ?? null;
    return $until ? '禁言至 ' . date('Y-m-d H:i', strtotime($until)) : '永久禁言';
}

/** 设置禁言；$days 为 null 或 <=0 表示永久，否则禁言 $days 天
 *  $reason：禁言原因（存入 mute_reason）
 *  $append：为 true 时在现有禁言到期时间上追加，而非从今天重新计算 */
function mute_user($userId, $days, $reason = '', $append = false)
{
    $uid = (int)$userId;
    if ($uid <= 0) return;
    if ($days === null || $days <= 0) {
        db()->prepare('UPDATE users SET muted = 1, muted_until = NULL, mute_reason = ? WHERE id = ?')->execute([$reason, $uid]);
        return;
    }
    $d = (int)$days;
    if ($append) {
        $cur = db()->prepare('SELECT muted_until FROM users WHERE id = ?');
        $cur->execute([$uid]);
        $row = $cur->fetch();
        $base = ($row && $row['muted_until'] && strtotime($row['muted_until']) > time())
            ? strtotime($row['muted_until']) : time();
        $until = date('Y-m-d H:i:s', $base + $d * 86400);
    } else {
        $until = date('Y-m-d H:i:s', strtotime('+' . $d . ' days'));
    }
    db()->prepare('UPDATE users SET muted = 1, muted_until = ?, mute_reason = ? WHERE id = ?')->execute([$until, $reason, $uid]);
}

/** 整合包全部下载项（按 sort 排序） */
function get_modpack_downloads(int $modpackId): array
{
    $st = db()->prepare('SELECT id, label, url, file_path FROM modpack_downloads WHERE modpack_id = ? ORDER BY sort ASC, id ASC');
    $st->execute([$modpackId]);
    return $st->fetchAll();
}

/** 原创/转载标记显示 */
function source_label(string $type): string
{
    return $type === 'repost' ? '转载' : '原创';
}

// ============ 站内信 ============

/** 发送站内信；type：reply/like/pin/update/system */
function notify($userId, $fromUserId, $type, $title, $content, $link = '')
{
    if ((int)$userId <= 0) return;
    $st = db()->prepare('INSERT INTO messages (user_id, from_user_id, type, title, content, link) VALUES (?,?,?,?,?,?)');
    $st->execute([(int)$userId, (int)$fromUserId, $type, $title, $content, $link]);
    // 联动邮件通知（站内信 + 邮件）
    send_notify_email((int)$userId, $type, $title, $content, $link);
}

/** 未读站内信数量 */
function unread_count($userId)
{
    if ((int)$userId <= 0) return 0;
    return (int)db()->query('SELECT COUNT(*) FROM messages WHERE user_id=' . (int)$userId . ' AND is_read=0')->fetchColumn();
}

// ============ 关注作者 ============

/** 是否已关注该作者 */
function is_following(int $followerId, int $followedId): bool
{
    if ($followerId <= 0 || $followedId <= 0) return false;
    $st = db()->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?');
    $st->execute([$followerId, $followedId]);
    return (bool)$st->fetch();
}

/** 粉丝数 */
function follower_count(int $userId): int
{
    if ($userId <= 0) return 0;
    return (int)db()->query('SELECT COUNT(*) FROM follows WHERE followed_id = ' . (int)$userId)->fetchColumn();
}

/** 关注数 */
function following_count(int $userId): int
{
    if ($userId <= 0) return 0;
    return (int)db()->query('SELECT COUNT(*) FROM follows WHERE follower_id = ' . (int)$userId)->fetchColumn();
}

/**
 * 维护模式检查：开启时非管理员访客显示维护页并退出
 * 后台管理员、前台 admin 角色用户放行
 */
function maintenance_check()
{
    if ((int)setting('maintenance', 0) !== 1) return;
    if (function_exists('ensure_session')) ensure_session();
    if (!empty($_SESSION['admin_id'])) return; // 后台已登录管理员放行，可预览前台
    $u = current_user();
    if (!empty($u) && isset($u['role']) && $u['role'] === 'admin') return;
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    $msg  = (string)setting('maintenance_msg', '');
    if ($msg === '') $msg = '站点正在维护升级中，请稍后再来。';
    $name = (string)setting('site_name', 'MC Modpack Hub');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' · 维护中</title><style>body{background:#0e1524;color:#e8eef7;font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.box{text-align:center;max-width:420px;padding:30px 24px}.ic{font-size:54px;color:#3ddc84;margin-bottom:10px}h1{font-size:24px;margin:10px 0}.msg{color:#8ba0bd;line-height:1.8;word-break:break-word}.sub{color:#5f7391;font-size:12px;margin-top:14px}</style></head><body><div class="box"><div class="ic">&#9881;</div><h1>站点维护中</h1><p class="msg">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p><div class="sub">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div></div></body></html>';
    exit;
}

/** 后台邮箱绑定：将前端同邮箱账号提升为超级管理员（users.role=admin），并同步 admins 表 */
function mc_bind_superadmin($email)
{
    $email = trim((string)$email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['err', '邮箱格式不正确'];
    mc_ensure_super_column(); // 确保 is_super 列存在（兼容升级前旧库）
    $st = db()->prepare('SELECT id, username, password, role, is_super FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u) return ['err', '该邮箱没有对应前端用户，请先在前台注册后再绑定'];
    if ($u['role'] === 'admin' && (int)($u['is_super'] ?? 0) === 1) return ['ok', '账号「' . $u['username'] . '」已是超级管理员'];

    db()->prepare('UPDATE users SET role = ?, is_super = ? WHERE id = ?')->execute(['admin', 1, (int)$u['id']]);

    // 同步 admins 表（保持旧后台登录体系兼容）
    $a = db()->prepare('SELECT id FROM admins WHERE username = ?');
    $a->execute([$u['username']]);
    if ($a->fetch()) {
        db()->prepare('UPDATE admins SET password = ? WHERE username = ?')->execute([$u['password'], $u['username']]);
    } else {
        db()->prepare('INSERT INTO admins (username, password) VALUES (?, ?)')->execute([$u['username'], $u['password']]);
    }
    return ['ok', '已将前端账号「' . $u['username'] . '」绑定为超级管理员，该账号登录后即可访问后台'];
}

/** 登录失败节流：连续 5 次失败锁定 10 分钟，防暴力破解 */
function mc_login_blocked()
{
    if (empty($_SESSION['login_fail'])) return false;
    $f = (int)$_SESSION['login_fail'];
    $t = (int)($_SESSION['login_fail_time'] ?? 0);
    if ($f >= 5) {
        if (time() - $t > 600) { $_SESSION['login_fail'] = 0; return false; }
        return true;
    }
    return false;
}
function mc_login_failed()
{
    $_SESSION['login_fail'] = (int)($_SESSION['login_fail'] ?? 0) + 1;
    $_SESSION['login_fail_time'] = time();
}
function mc_login_reset()
{
    $_SESSION['login_fail'] = 0;
    unset($_SESSION['login_fail_time']);
}

/** 输出社交分享标签（Open Graph + Twitter Card），供微信/QQ 等抓取生成分享卡片 */
function mc_og_meta($title = '', $desc = '', $image = '', $url = '')
{
    $siteName = setting('site_name', defined('SITE_NAME') ? SITE_NAME : 'MC Modpack Hub');
    $siteDesc = setting('site_desc', '');
    $t = $title !== '' ? $title : $siteName;
    $d = $desc !== '' ? $desc : ($siteDesc !== '' ? $siteDesc : $siteName);
    if (mb_strlen($d) > 200) $d = mb_substr($d, 0, 200) . '…';
    $img = $image !== '' ? cover_url($image) : cover_url(setting('logo', ''));
    $u = $url !== '' ? $url : site_url() . ltrim($_SERVER['REQUEST_URI'] ?? '/', '/');
    echo "\n";
    echo '  <meta property="og:site_name" content="' . e($siteName) . '">' . "\n";
    echo '  <meta property="og:type" content="website">' . "\n";
    echo '  <meta property="og:title" content="' . e($t) . '">' . "\n";
    echo '  <meta property="og:description" content="' . e($d) . '">' . "\n";
    echo '  <meta property="og:url" content="' . e($u) . '">' . "\n";
    if ($img) echo '  <meta property="og:image" content="' . e($img) . '">' . "\n";
    echo '  <meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '  <meta name="twitter:title" content="' . e($t) . '">' . "\n";
    echo '  <meta name="twitter:description" content="' . e($d) . '">' . "\n";
    if ($img) echo '  <meta name="twitter:image" content="' . e($img) . '">' . "\n";
}
