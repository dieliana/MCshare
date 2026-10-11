<?php
/**
 * 后台 · 主题管理
 * 支持上传自定义主题（zip，含 theme.php + 可选 theme.css）并启用/删除
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/wp-compat/wp-compat.php'; // 用于识别 WordPress 主题

$pageTitle = '主题管理';
$msg = '';
$themesDir = __DIR__ . '/../themes';

function th_rmdir($dir)
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}

// 内置主题（data-theme 变量定义在 style.css）
$builtin = [
    'emerald' => '翠绿', 'ocean' => '海洋', 'violet' => '紫罗兰', 'forest' => '森林', 'light' => '明亮',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $msg = ['err', '会话已过期'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'enable') {
            $t = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['theme'] ?? ''));
            $ok = isset($builtin[$t]);
            if (!$ok) $ok = is_file($themesDir . '/' . $t . '/theme.php');
            if ($ok) {
                $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
                $st->execute(['theme', $t]);
                $msg = ['ok', '已启用主题：' . $t];
            } else {
                $msg = ['err', '主题不存在'];
            }
        } elseif ($action === 'delete') {
            $t = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['theme'] ?? ''));
            if (isset($builtin[$t])) {
                $msg = ['err', '内置主题不能删除'];
            } elseif ($t !== '' && is_dir($themesDir . '/' . $t)) {
                th_rmdir($themesDir . '/' . $t);
                $msg = ['ok', '已删除主题：' . $t];
            } else {
                $msg = ['err', '主题不存在'];
            }
        } elseif ($action === 'upload') {
            if (empty($_FILES['zip']['tmp_name']) || !is_uploaded_file($_FILES['zip']['tmp_name'])) {
                $msg = ['err', '请选择主题 zip 文件'];
            } elseif (!class_exists('ZipArchive')) {
                $msg = ['err', '服务器未启用 zip 扩展，无法解压'];
            } else {
                $tmpExt = sys_get_temp_dir() . '/mcshare_theme_' . substr(md5((string)mt_rand()), 0, 8);
                @mkdir($tmpExt, 0755, true);
                $zip = new ZipArchive();
                if ($zip->open($_FILES['zip']['tmp_name']) !== true) {
                    $msg = ['err', '无法打开 zip 文件'];
                } else {
                    $zip->extractTo($tmpExt);
                    $zip->close();

                    // 定位主题目录：优先 MC 主题 theme.php，其次 WordPress 主题 style.css（含 Theme Name）
                    $found = null;
                    $wpType = false;
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpExt, FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $f) {
                        if ($f->isFile() && $f->getFilename() === 'theme.php') { $found = dirname($f->getPathname()); break; }
                    }
                    if (!$found) {
                        foreach ($it as $f) {
                            if ($f->isFile() && $f->getFilename() === 'style.css' && mc_wp_theme_info(dirname($f->getPathname()))) { $found = dirname($f->getPathname()); $wpType = true; break; }
                        }
                    }
                    if (!$found) {
                        $msg = ['err', 'zip 中未找到 theme.php（MC 主题）或含 Theme Name 的 style.css（WordPress 主题）'];
                    } else {
                        if ($wpType) {
                            $wpInfo = mc_wp_theme_info($found);
                            $info = $wpInfo;
                            $id = basename($found);
                        } else {
                            $themeInfo = [];
                            include $found . '/theme.php';
                            $info = is_array($themeInfo ?? null) ? $themeInfo : [];
                            $id = $info['id'] ?? basename($found);
                        }
                        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
                        if ($id === '' || isset($builtin[$id])) {
                            $msg = ['err', '主题 id 无效或与内置主题重名'];
                        } else {
                            $target = $themesDir . '/' . $id;
                            if (is_dir($target)) th_rmdir($target);
                            @mkdir($target, 0755, true);
                            $ok = true;
                            $rit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($found, FilesystemIterator::SKIP_DOTS));
                            foreach ($rit as $rf) {
                                if (!$rf->isFile()) continue;
                                $rel = str_replace('\\', '/', substr($rf->getPathname(), strlen($found) + 1));
                                @mkdir(dirname($target . '/' . $rel), 0755, true);
                                if (!copy($rf->getPathname(), $target . '/' . $rel)) $ok = false;
                            }
                            if (!$ok) $msg = ['err', '复制主题文件失败'];
                            else $msg = ['ok', '已上传' . ($wpType ? ' WordPress 主题' : '主题') . '：' . ($info['name'] ?? $id) . '（' . $id . '）'];
                        }
                    }
                    th_rmdir($tmpExt);
                }
            }
        }
    }
}

// 当前启用主题
$active = setting('theme', 'emerald');

// 自定义主题列表（兼容：MCshare 主题 theme.php 与 WordPress 主题 style.css）
$custom = [];
foreach (glob($themesDir . '/*', GLOB_ONLYDIR) ?: [] as $td) {
    $id = basename($td);
    $info = null;
    $wp = null;
    if (is_file($td . '/theme.php')) {
        $themeInfo = [];
        include $td . '/theme.php';
        $info = is_array($themeInfo ?? null) ? $themeInfo : [];
        $id   = $info['id'] ?? $id;
    } else {
        $wp = mc_wp_theme_info($td);
        if (!$wp) continue; // 既非 MC 主题也非 WP 主题
        $info = $wp;
        $id   = $info['id'] ?? $id;
    }
    $custom[] = [
        'id'      => $id,
        'name'    => $info['name'] ?? $id,
        'version' => $info['version'] ?? '',
        'author'  => $info['author'] ?? '',
        'desc'    => $info['desc'] ?? '',
        'wp'      => $wp !== null,
    ];
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

  <div class="panel">
    <h2>内置主题</h2>
    <table class="table">
      <tbody>
        <?php foreach ($builtin as $id => $name): ?>
        <tr>
          <td style="width:30px"><?= $active === $id ? '<i class="fa-solid fa-circle-check" style="color:var(--accent)"></i>' : '<i class="fa-solid fa-circle" style="color:var(--line)"></i>' ?></td>
          <td><b><?= e($name) ?></b> <span class="muted">(<?= e($id) ?>)</span></td>
          <td style="width:120px">
            <?php if ($active !== $id): ?>
              <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="enable"><input type="hidden" name="theme" value="<?= e($id) ?>"><button class="btn ghost btn-sm" type="submit">启用</button></form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="panel">
    <h2>自定义主题</h2>
    <?php if (!$custom): ?>
      <p class="muted">暂无自定义主题。可通过下方上传 zip，或手动放到 <code>themes/主题名/</code>（含 theme.php，可选 theme.css）。</p>
    <?php else: ?>
      <table class="table">
        <tbody>
          <?php foreach ($custom as $c): ?>
          <tr>
            <td style="width:30px"><?= $active === $c['id'] ? '<i class="fa-solid fa-circle-check" style="color:var(--accent)"></i>' : '' ?></td>
            <td>
              <b><?= e($c['name']) ?></b> <span class="muted">(<?= e($c['id']) ?>)</span><?php if (!empty($c['wp'])): ?><span class="pill gray" style="margin-left:6px">WordPress</span><?php endif; ?>
              <?php if ($c['version']): ?><span class="pill gray">v<?= e($c['version']) ?></span><?php endif; ?>
              <?php if ($c['author']): ?><span class="muted" style="font-size:12px">by <?= e($c['author']) ?></span><?php endif; ?>
              <?php if ($c['desc']): ?><div class="muted" style="font-size:12px;margin-top:4px"><?= e($c['desc']) ?></div><?php endif; ?>
            </td>
            <td style="width:220px">
              <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="enable"><input type="hidden" name="theme" value="<?= e($c['id']) ?>"><button class="btn ghost btn-sm" type="submit"><?= $active === $c['id'] ? '已启用' : '启用' ?></button></form>
              <?php if ($active !== $c['id']): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('确定删除主题 <?= e($c['name']) ?> 吗？')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="theme" value="<?= e($c['id']) ?>"><button class="btn ghost btn-sm" type="submit" style="color:var(--danger)">删除</button></form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <div class="section-title" style="margin:20px 0 8px">上传主题（zip）</div>
    <p class="hint">打包结构：zip 内包含一个主题目录，目录下有 <code>theme.php</code>（返回 <code>$themeInfo</code>，含 id/name/version/author/desc）与可选 <code>theme.css</code>。参考 <code>themes/sample/</code>。</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="upload">
      <div class="field"><input type="file" name="zip" accept=".zip"></div>
      <div class="form-actions"><button class="btn" type="submit"><i class="fa-solid fa-upload"></i> 上传并安装主题</button></div>
    </form>
  </div>
</main>
