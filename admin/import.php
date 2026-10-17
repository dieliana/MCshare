<?php
/**
 * 后台 · 整合包导入（爬虫）
 * 抓取指定列表页的整合包卡片，预览后批量导入「整合包管理」。
 * 导入状态为「待审核」，可在审核中心 / 整合包管理继续处理。
 */
require __DIR__ . '/_init.php'; // 由 _init.php 定义 BASE_PATH

$pageTitle = '导入整合包（爬虫）';
$msg = '';
$cards = [];
$imported = [];

// 分类列表
$cats = db()->query('SELECT id, name FROM categories ORDER BY sort, id')->fetchAll();

/* ---------- 工具函数 ---------- */
function imp_fetch($url)
{
    if (function_exists('curl_init')) {
        $ck = tempnam(sys_get_temp_dir(), 'ck');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_COOKIEJAR      => $ck,
            CURLOPT_COOKIEFILE     => $ck,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Upgrade-Insecure-Requests: 1',
                'Referer: ' . parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/',
            ],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
        ]);
        $html = curl_exec($ch);
        if (is_string($ck)) @unlink($ck);
        return (is_string($html) && $html !== '') ? $html : null; // PHP 8+ 句柄自动释放，无需 curl_close
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 20, 'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36\r\nAccept: text/html,*/*\r\n"],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $html = @file_get_contents($url, false, $ctx);
    return ($html === false) ? null : $html;
}

function imp_abs($base, $href)
{
    $href = trim($href);
    if ($href === '') return '';
    if (preg_match('#^https?://#i', $href)) return $href;
    if ($href[0] === '/') {
        $p = parse_url($base);
        return $p['scheme'] . '://' . $p['host'] . $href;
    }
    return rtrim($base, '/') . '/' . ltrim($href, '/');
}

function imp_dom($html)
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8">' . $html);
    libxml_clear_errors();
    return new DOMXPath($doc);
}

function imp_is_card($href, $title)
{
    if (!preg_match('#^(https?://|/)#', $href)) return false;
    if (!preg_match('~\.(css|js|png|jpe?g|gif|svg|ico|webp|xml|json)(\?|$)~i', $href)) return false;
    if (preg_match('~#|javascript:|mailto:|login|register|logout|admin|privacy|about|terms|feed|/submit~i', $href)) return false;
    $t = trim($title);
    if ($t === '' || mb_strlen($t) < 2) return false;
    if (preg_match('/首页|关于|登录|注册|搜索|更多|下一页|上一页|返回|全部|分类|投稿|发布/i', $t)) return false;
    if (!preg_match('~modpack|/pack|/post|/thread|/mod/|/mods/|/item|/detail|/download|\.html|\.htm~i', $href)) return false;
    return true;
}

/**
 * BBSMC 专用解析：Nuxt 站 project-card 卡片结构
 * 标题在 <article class="project-card" aria-label="中文标题 - English">，
 * 内 <a href="/modpack/slug" class="icon"> 包裹封面 img(cdn.bbsmc.net)，<div class="description"> 为简介。
 */
function imp_scan_bbsmc($url, $html)
{
    $cards = []; $seen = [];
    $parts = preg_split('/class="[^"]*project-card[^"]*"/i', $html);
    for ($i = 1; $i < count($parts); $i++) {
        $block = $parts[$i];
        if (!preg_match('/aria-label="([^"]+)"/', $block, $m)) continue;
        $title = trim($m[1]);
        $dash = mb_strrpos($title, ' - ');
        if ($dash !== false) $title = trim(mb_substr($title, 0, $dash));
        if ($title === '' || mb_strlen($title) < 2) continue;
        if (!preg_match('#href="(/modpack/[^"]+)"#i', $block, $m2)) continue;
        $href = imp_abs($url, $m2[1]);
        $key = md5($href);
        if (isset($seen[$key])) continue;
        $seen[$key] = 1;
        $cover = '';
        if (preg_match('#<img\b[^>]*src="(https://cdn\.bbsmc\.net/[^"]+)"[^>]*>#i', $block, $m3)) {
            $cover = $m3[1];
        } elseif (preg_match('#<img\b[^>]*src="([^"]+)"[^>]*>#i', $block, $m3)) {
            $cover = imp_abs($url, $m3[1]);
        }
        $desc = '';
        if (preg_match('#class="description"[^>]*>([\s\S]*?)</div>#i', $block, $m4)) {
            $desc = trim(preg_replace('/\s+/', ' ', trim(strip_tags($m4[1]))));
        }
        $author = '';
        if (preg_match('#href="(/user/[^"]+)"#i', $block, $m5)) {
            $author = html_entity_decode(urldecode(basename($m5[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $desc  = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cards[] = ['title' => $title, 'href' => $href, 'cover' => $cover, 'text' => $desc, 'author' => $author];
    }
    return $cards;
}

/** 加载器名标准化：forge → Forge、fabric → Fabric … */
function imp_loader($loaders)
{
    if (empty($loaders) || !is_array($loaders)) return '';
    $l = strtolower(trim((string)$loaders[0]));
    $map = [
        'forge' => 'Forge', 'fabric' => 'Fabric', 'neoforge' => 'NeoForge', 'quilt' => 'Quilt',
        'vanilla' => 'Vanilla', 'bukkit' => 'Bukkit', 'paper' => 'Paper', 'spigot' => 'Spigot',
        'purpur' => 'Purpur', 'folia' => 'Folia', 'rift' => 'Rift', 'liteloader' => 'LiteLoader',
    ];
    return $map[$l] ?? ucfirst($l);
}

/** BBSMC 详情（API）：项目 + 版本，返回完整导入字段 */
function imp_bbsmc_api($slug)
{
    $api  = 'https://api.bbsmc.net/v2/';
    $slug = rawurlencode($slug);
    $out  = ['title' => '', 'desc' => '', 'body' => '', 'cover' => '', 'loaders' => [], 'game_versions' => [], 'categories' => [], 'download' => '', 'downloads_list' => []];

    $h = imp_fetch($api . 'project/' . $slug);
    if ($h && ($p = json_decode($h, true)) && is_array($p)) {
        $out['title']         = (string)($p['title'] ?? '');
        $out['desc']          = (string)($p['description'] ?? '');
        $out['body']          = (string)($p['body'] ?? '');
        $out['cover']         = (string)($p['icon_url'] ?? '');
        $out['loaders']       = is_array($p['loaders'] ?? null) ? $p['loaders'] : [];
        $out['game_versions'] = is_array($p['game_versions'] ?? null) ? $p['game_versions'] : [];
        $out['categories']    = array_merge(
            is_array($p['categories'] ?? null) ? $p['categories'] : [],
            is_array($p['additional_categories'] ?? null) ? $p['additional_categories'] : []
        );
    }

    $v = imp_fetch($api . 'project/' . $slug . '/version?featured=true');
    if ($v && ($arr = json_decode($v, true)) && is_array($arr)) {
        $seenUrl = [];
        foreach ($arr as $ver) {
            if (!is_array($ver)) continue;
            $url = '';
            if (!empty($ver['files']) && is_array($ver['files'])) {
                foreach ($ver['files'] as $f) { if (!empty($f['url'])) { $url = $f['url']; break; } }
            }
            if ($url === '' && !empty($ver['disk_urls']) && is_array($ver['disk_urls'])) {
                foreach ($ver['disk_urls'] as $d) { if (!empty($d['url'])) { $url = $d['url']; break; } }
            }
            if ($url === '') continue;
            $key = md5($url);
            if (isset($seenUrl[$key])) continue;
            $seenUrl[$key] = 1;
            $label = ($ver['version_number'] ?? '') !== '' ? $ver['version_number'] : ($ver['name'] ?? '下载');
            $out['downloads_list'][] = ['label' => (string)$label, 'url' => $url];
        }
        if ($out['downloads_list']) $out['download'] = $out['downloads_list'][0]['url'];
    }
    return $out;
}

function imp_scan($url, $html = null)
{
    if ($html === null) {
        $html = imp_fetch($url);
        if (!$html) return [];
    }
    // BBSMC / Nuxt project-card 结构优先
    if (stripos($html, 'project-card') !== false && preg_match('/aria-label=/', $html)) {
        $bs = imp_scan_bbsmc($url, $html);
        if ($bs) return array_slice($bs, 0, 60);
    }
    $x = imp_dom($html);
    $cards = [];
    $seen = [];
    $nodes = $x->query('//li//a[@href] | //div//a[@href] | //a[@href]');
    foreach ($nodes as $a) {
        $href = imp_abs($url, $a->getAttribute('href'));
        // 标题：a 的 title 属性 → 内部图片 alt → 文本（多数卡片 a 内只有图）
        $title = trim($a->getAttribute('title'));
        $cover = '';
        foreach ($a->getElementsByTagName('img') as $im) {
            $src = $im->getAttribute('data-src') ?: $im->getAttribute('src');
            if (!$cover && $src) $cover = imp_abs($url, $src);
            if ($title === '' && trim($im->getAttribute('alt'))) $title = trim($im->getAttribute('alt'));
        }
        if ($title === '') $title = trim(preg_replace('/\s+/', ' ', $a->textContent));
        if (!imp_is_card($href, $title)) continue;
        $key = md5($href);
        if (isset($seen[$key])) continue;
        $seen[$key] = 1;
        $text = $title;
        $parent = $a->parentNode;
        if ($parent && $parent->nodeType === XML_ELEMENT_NODE) {
            $pt = trim(preg_replace('/\s+/', ' ', $parent->textContent));
            if ($pt !== $title) $text = mb_substr($pt, 0, 160);
        }
        $cards[] = ['title' => $title, 'href' => $href, 'cover' => $cover, 'text' => $text];
    }
    return array_slice($cards, 0, 60);
}

function imp_field($html)
{
    $x = imp_dom($html);
    $out = ['title' => '', 'desc' => '', 'cover' => '', 'version' => '', 'loader' => '', 'download' => ''];
    $t = $x->query('//meta[@property="og:title"]/@content');
    if ($t->length) $out['title'] = trim($t->item(0)->nodeValue);
    if (!$out['title']) { $h1 = $x->query('//h1'); if ($h1->length) $out['title'] = trim($h1->item(0)->textContent); }

    $d = $x->query('//meta[@property="og:description"]/@content');
    if ($d->length) $out['desc'] = trim($d->item(0)->nodeValue);
    if (!$out['desc']) { $dm = $x->query('//meta[@name="description"]/@content'); if ($dm->length) $out['desc'] = trim($dm->item(0)->nodeValue); }
    if (!$out['desc']) { $out['desc'] = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($html))), 0, 200); }

    $c = $x->query('//meta[@property="og:image"]/@content');
    if ($c->length) $out['cover'] = trim($c->item(0)->nodeValue);
    if (!$out['cover']) { $ci = $x->query('//link[@rel="image_src"]/@href'); if ($ci->length) $out['cover'] = trim($ci->item(0)->nodeValue); }

    if (preg_match('/MC\s*(\d{1,2}\.\d{1,2}(?:\.\d{1,2})?)/i', $html, $m)) $out['version'] = $m[1];
    elseif (preg_match('/(?:版本|支持)[:：]?\s*(\d{1,2}\.\d{1,2}(?:\.\d{1,2})?)/i', $html, $m)) $out['version'] = $m[1];

    foreach (['NeoForge', 'Fabric', 'Forge', 'Quilt'] as $l) {
        if (stripos($html, $l) !== false) { $out['loader'] = $l; break; }
    }

    $as = $x->query('//a[@href]');
    foreach ($as as $a) {
        $h = $a->getAttribute('href');
        if (preg_match('/\.(zip|jar|mrpack|rar|7z|json)(\?|$)/i', $h) || preg_match('/download|pan|drive/i', $h)) {
            $out['download'] = $h;
            break;
        }
    }
    return $out;
}

function imp_slug($title, $n = 0)
{
    $s = strtolower(trim($title));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    if ($s === '' || strlen($s) < 3) $s = 'pack-' . substr(md5($title), 0, 6);
    if ($n > 0) $s .= '-' . $n;
    $st = db()->prepare('SELECT 1 FROM modpacks WHERE slug = ?');
    $st->execute([$s]);
    if ($st->fetch()) return imp_slug($title, $n + 1);
    return $s;
}

/* ---------- 动作 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check($_POST['csrf'] ?? '')) { $msg = ['err', '会话已过期，请刷新页面后重试']; $action = null; }

    if ($action === 'scan') {
        $url = trim($_POST['url'] ?? '');
        // 常见纠错：.../modpack 或 .../modpack/ → .../modpacks（modpack/xxx 是单包详情，非列表）
        if (preg_match('#^https?://[^/]+/modpack/?$#i', $url)) {
            $url = preg_replace('#/modpack/?$#i', '/modpacks', $url);
        }
        $cat = (int)($_POST['category_id'] ?? 0);
        $htmlInput = $_POST['html'] ?? '';
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $msg = ['err', '请填写以 http(s):// 开头的目标列表页地址'];
        } else {
            $cards = $htmlInput !== '' ? imp_scan($url, $htmlInput) : imp_scan($url);
            if (!$cards) {
                $msg = ['err', '未能从该页面提取到整合包卡片，请检查地址或页面结构'];
            } else {
                $_SESSION['imp_scan'] = ['url' => $url, 'category' => $cat, 'cards' => $cards];
                $msg = ['ok', '提取到 ' . count($cards) . ' 条整合包，勾选后点击「导入选中项」'];
            }
        }
    }

    if ($action === 'import') {
        $sel = $_POST['sel'] ?? [];
        $scan = $_SESSION['imp_scan'] ?? [];
        if (!$scan) {
            $msg = ['err', '请先抓取列表'];
        } elseif (!$sel) {
            $msg = ['err', '请先勾选要导入的整合包'];
        } else {
            $cat = (int)$scan['category'];
            $domain = parse_url($scan['url'], PHP_URL_HOST) ?: '';
            $isBbsmc = stripos($domain, 'bbsmc') !== false;
            $ok = 0; $fail = 0;
            foreach ($sel as $href) {
                $href = (string)$href;
                $item = null;
                foreach ($scan['cards'] as $c) if ($c['href'] === $href) { $item = $c; break; }
                if (!$item) { $fail++; continue; }
                $title = $item['title']; $desc = $item['text']; $cover = $item['cover'];
                $ver = ''; $loader = ''; $dl = ''; $author = $item['author'] ?? ''; $contentHtml = ''; $cats = []; $a = null;

                if ($isBbsmc && preg_match('#/modpack/([^/?#]+)#', $href, $mm)) {
                    // BBSMC：走公开 API 获取完整字段
                    $a = imp_bbsmc_api($mm[1]);
                    if ($a['title']) $title = $a['title'];
                    if ($a['body'] !== '') { $contentHtml = rich_html($a['body']); $desc = $a['desc'] !== '' ? $a['desc'] : $a['body']; }
                    elseif ($a['desc'] !== '') { $contentHtml = rich_html($a['desc']); $desc = $a['desc']; }
                    if ($a['cover']) $cover = $a['cover'];
                    $ver = $a['game_versions'][0] ?? '';
                    $loader = imp_loader($a['loaders']);
                    $dl = $a['download'];
                    $cats = $a['categories'];
                } else {
                    $dhtml = imp_fetch($href);
                    if ($dhtml) {
                        $f = imp_field($dhtml);
                        if ($f['title']) $title = $f['title'];
                        if ($f['desc']) { $desc = $f['desc']; $contentHtml = rich_html($f['desc']); }
                        if ($f['cover']) $cover = $f['cover'];
                        $ver = $f['version']; $loader = $f['loader']; $dl = $f['download'];
                    }
                }
                if ($contentHtml === '') $contentHtml = rich_html($desc);

                $slug = imp_slug($title);
                try {
                    db()->prepare('INSERT INTO modpacks (slug,title,tagline,content,cover,mc_version,mod_loader,author,author_links,download_url,source_type,source_name,category_id,user_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$slug, $title, mb_substr($desc, 0, 240), $contentHtml, $cover, $ver, $loader, $author, '', $dl, 'repost', $domain, $cat ?: null, 0, 0]);
                    $mpId = (int)db()->lastInsertId();

                    // 下载链接（多版本）
                    if (!empty($a['downloads_list'])) {
                        db()->prepare('DELETE FROM modpack_downloads WHERE modpack_id = ?')->execute([$mpId]);
                        $insDl = db()->prepare('INSERT INTO modpack_downloads (modpack_id, label, url, sort) VALUES (?,?,?,?)');
                        $srt = 0;
                        foreach ($a['downloads_list'] as $dli) $insDl->execute([$mpId, mb_substr($dli['label'], 0, 60), $dli['url'], $srt++]);
                    } elseif ($dl !== '') {
                        db()->prepare('DELETE FROM modpack_downloads WHERE modpack_id = ?')->execute([$mpId]);
                        db()->prepare('INSERT INTO modpack_downloads (modpack_id, label, url, sort) VALUES (?,?,?,?)')
                            ->execute([$mpId, '下载', $dl, 0]);
                    }

                    // 自动打标签
                    $tagFind = db()->prepare('SELECT id FROM tags WHERE name = ?');
                    $tagAdd  = db()->prepare('INSERT INTO tags (name) VALUES (?)');
                    $relTag  = db()->prepare('INSERT IGNORE INTO modpack_tags (modpack_id, tag_id) VALUES (?,?)');
                    foreach ($cats as $cn) {
                        $cn = trim((string)$cn);
                        if ($cn === '') continue;
                        $tagFind->execute([$cn]);
                        $tid = $tagFind->fetchColumn();
                        if (!$tid) {
                            try { $tagAdd->execute([$cn]); $tid = (int)db()->lastInsertId(); }
                            catch (Exception $e) { $tagFind->execute([$cn]); $tid = $tagFind->fetchColumn(); }
                        }
                        if ($tid) $relTag->execute([$mpId, $tid]);
                    }

                    $ok++; $imported[] = '<a href="modpack-form.php?id=' . $mpId . '" class="link">' . e($title) . '</a>';
                } catch (Exception $e) { $fail++; }
            }
            $msg = ['ok', '导入完成：成功 ' . $ok . ' 条，失败 ' . $fail . ' 条（已导入内容状态为「待审核」）。'];
            unset($_SESSION['imp_scan']);
        }
    }
}

// 从 session 恢复预览
if (!$cards && !empty($_SESSION['imp_scan'])) {
    $cards = $_SESSION['imp_scan']['cards'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= e($adminTheme ?? 'emerald') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · 管理后台</title>
<link rel="stylesheet" href="assets/admin.css">
<link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<main class="content">
  <div class="page-head"><h1><?= e($pageTitle) ?></h1></div>

  <?php if ($msg): ?>
    <div class="alert <?= $msg[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($msg[1]) ?></div>
  <?php endif; ?>

  <?php if (!empty($imported)): ?>
    <div class="panel">
      <h2>已导入</h2>
      <ul style="display:flex;flex-direction:column;gap:6px"><?php foreach ($imported as $r): ?><li><?= $r ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="panel">
    <h2>抓取整合包列表</h2>
    <form method="post" class="form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="field"><label>目标列表页地址</label>
        <input type="text" name="url" value="<?= e($_POST['url'] ?? ($_SESSION['imp_scan']['url'] ?? '')) ?>" placeholder="如 https://bbsmc.net/modpacks">
        <p class="hint">填「列表页」地址（如 bbsmc.net 的 /modpacks），系统会自动抓取卡片；若误填成单包详情 /modpack/xxx，会自动纠错到 /modpacks。</p></div>
      <div class="field"><label>导入到分类</label>
        <select name="category_id">
          <option value="0">不指定分类</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)($_SESSION['imp_scan']['category'] ?? 0)) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>或直接粘贴列表页 HTML 源码（服务器抓不到时用）</label>
        <textarea name="html" rows="6" style="min-height:90px" placeholder="在浏览器打开列表页 → 查看网页源代码 → 全选复制粘贴到这里，可绕过目标站反爬"></textarea></div>
      <div class="form-actions"><button type="submit" name="action" value="scan" class="btn"><i class="fa-solid fa-magnifying-glass"></i> 抓取并预览</button></div>
    </form>
  </div>

  <?php if ($cards): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="import">
      <div class="panel">
        <h2>抓取结果（<?= count($cards) ?> 条）— 勾选要导入的</h2>
        <table class="table">
          <thead><tr>
            <th style="width:40px"><input type="checkbox" id="selAll" onclick="var cb=this;document.querySelectorAll('.sel-item').forEach(function(b){b.checked=cb.checked;})"></th>
            <th>标题</th><th>封面</th><th>简介</th><th>链接</th>
          </tr></thead>
          <tbody>
          <?php foreach ($cards as $c): ?>
            <tr>
              <td><input type="checkbox" class="sel-item" name="sel[]" value="<?= e($c['href']) ?>"></td>
              <td style="font-weight:600"><?= e($c['title']) ?></td>
              <td><?= $c['cover'] ? '<img src="' . e($c['cover']) . '" class="tbl-thumb" onerror="this.style.display=\'none\'">' : '<span class="tbl-nothumb">无</span>' ?></td>
              <td style="max-width:260px;color:var(--muted);font-size:13px"><?= e(mb_substr($c['text'], 0, 90)) ?></td>
              <td style="max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><a href="<?= e($c['href']) ?>" target="_blank" rel="noopener" class="link"><?= e($c['href']) ?></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn"><i class="fa-solid fa-file-import"></i> 导入选中项（待审核）</button>
      </div>
    </form>
  <?php endif; ?>
</main>
