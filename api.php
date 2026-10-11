<?php
/**
 * 对外 API
 * 供其他同源码网站或第三方调用整合包数据。
 * 使用前需在后台「站点设置 → API 接口」开启；若设置了 API Key，调用需带 key 参数或 X-API-Key 请求头。
 *
 * 端点（返回 JSON）：
 *   /api.php?act=modpacks&category=&version=&loader=&keyword=&page=&per_page=   整合包列表
 *   /api.php?act=modpack&id=1|slug=xxx                                           单个整合包详情
 *   /api.php?act=categories                                                       分类列表
 *   /api.php?act=tags                                                             标签列表
 * 伪静态：/api/modpacks /api/modpack?id=1 /api/categories /api/tags
 */
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

function api_json($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: X-API-Key');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_err($msg, $code = 400)
{
    api_json(['ok' => false, 'error' => $msg], $code);
}

// 鉴权
if (!(int)setting('api_enabled', 0)) {
    api_err('API 未开启', 403);
}
$apiKey = trim(setting('api_key', ''));
if ($apiKey !== '') {
    $k = $_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!is_string($k) || !hash_equals($apiKey, $k)) {
        api_err('API Key 无效', 401);
    }
}

// 解析 act（支持 /api.php?act=xxx 与伪静态 /api/xxx）
$act = strtolower(trim((string)($_GET['act'] ?? '')));
if ($act === '') {
    $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
    if (preg_match('#^api/([a-z_]+)$#i', $path, $m)) $act = strtolower($m[1]);
}
if ($act === '') $act = 'modpacks';

switch ($act) {
    case 'categories':
        $cats = db()->query('SELECT c.id, c.name, c.slug, c.sort, (SELECT COUNT(*) FROM modpacks m WHERE m.category_id = c.id AND m.status = 1) AS count FROM categories c ORDER BY c.sort ASC, c.id ASC')->fetchAll();
        api_json(['ok' => true, 'data' => $cats]);
        break;

    case 'tags':
        $tags = db()->query('SELECT t.id, t.name, t.slug FROM tags t ORDER BY t.id ASC')->fetchAll();
        api_json(['ok' => true, 'data' => $tags]);
        break;

    case 'modpack':
        $id = (int)($_GET['id'] ?? 0);
        $slug = trim($_GET['slug'] ?? '');
        if ($id) $stmt = db()->prepare('SELECT m.*, c.name AS category_name, c.slug AS category_slug FROM modpacks m LEFT JOIN categories c ON c.id = m.category_id WHERE m.id = ? AND m.status = 1');
        else $stmt = db()->prepare('SELECT m.*, c.name AS category_name, c.slug AS category_slug FROM modpacks m LEFT JOIN categories c ON c.id = m.category_id WHERE m.slug = ? AND m.status = 1');
        $stmt->execute([$id ?: $slug]);
        $m = $stmt->fetch();
        if (!$m) api_err('整合包不存在', 404);

        $out = api_map_modpack($m);
        // 标签
        $tagSt = db()->prepare('SELECT t.name, t.slug FROM tags t JOIN modpack_tags mt ON mt.tag_id = t.id WHERE mt.modpack_id = ? ORDER BY t.id ASC');
        $tagSt->execute([$m['id']]);
        $out['tags'] = $tagSt->fetchAll();
        // 下载链接
        $dlSt = db()->prepare('SELECT label, url, file_path, sort FROM modpack_downloads WHERE modpack_id = ? ORDER BY sort ASC, id ASC');
        $dlSt->execute([$m['id']]);
        $dl = $dlSt->fetchAll();
        $out['downloads'] = array_map(function ($d) {
            $d['url'] = $d['url'] ?: (site_url() . UPLOAD_URL . '/' . ltrim($d['file_path'], '/'));
            unset($d['file_path']);
            return $d;
        }, $dl);
        api_json(['ok' => true, 'data' => $out]);
        break;

    case 'modpacks':
    default:
        $cat = trim($_GET['category'] ?? '');
        $version = trim($_GET['version'] ?? '');
        $loader = trim($_GET['loader'] ?? '');
        $keyword = trim($_GET['keyword'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(50, (int)($_GET['per_page'] ?? 12)));

        $where = ['m.status = 1'];
        $params = [];
        if ($cat !== '') { $where[] = '(c.slug = ? OR c.name = ?)'; $params[] = $cat; $params[] = $cat; }
        if ($version !== '') { $where[] = 'm.mc_version = ?'; $params[] = $version; }
        if ($loader !== '') { $where[] = 'm.mod_loader = ?'; $params[] = $loader; }
        if ($keyword !== '') { $where[] = '(m.title LIKE ? OR m.tagline LIKE ? OR m.content LIKE ?)'; $kw = '%' . $keyword . '%'; $params[] = $kw; $params[] = $kw; $params[] = $kw; }
        $whereSql = implode(' AND ', $where);

        $countSt = db()->prepare("SELECT COUNT(*) FROM modpacks m LEFT JOIN categories c ON c.id = m.category_id WHERE $whereSql");
        $countSt->execute($params);
        $total = (int)$countSt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT m.*, c.name AS category_name, c.slug AS category_slug FROM modpacks m LEFT JOIN categories c ON c.id = m.category_id WHERE $whereSql ORDER BY m.id DESC LIMIT $perPage OFFSET $offset";
        $st = db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();

        $data = array_map('api_map_modpack', $rows);
        api_json(['ok' => true, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'data' => $data]);
        break;
}

/** 单条整合包 → API 结构 */
function api_map_modpack($m)
{
    return [
        'id'           => (int)$m['id'],
        'slug'         => $m['slug'],
        'title'        => $m['title'],
        'tagline'      => $m['tagline'],
        'content'      => $m['content'],
        'cover'        => $m['cover'] ? cover_url($m['cover']) : '',
        'mc_version'   => $m['mc_version'],
        'mod_loader'   => $m['mod_loader'],
        'author'       => $m['author'],
        'category'     => $m['category_name'] ?: '',
        'category_slug'=> $m['category_slug'] ?: '',
        'source_type'  => $m['source_type'],
        'source_name'  => $m['source_name'],
        'views'        => (int)$m['views'],
        'downloads'    => (int)$m['downloads'],
        'created_at'   => $m['created_at'],
    ];
}
