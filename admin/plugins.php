<?php
/**
 * 后台 · 插件管理
 * 支持上传插件 zip（含 plugin.php）、启用/禁用、删除；插件通过钩子扩展前台
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/hooks.php'; // 供插件元信息 include 时使用 add_action/add_filter
require_once __DIR__ . '/../includes/wp-compat/wp-compat.php'; // 供识别/加载 WordPress 插件

$pageTitle = '插件管理';
$msg = '';
$pluginsDir = __DIR__ . '/../plugins';

function pl_rmdir($dir)
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}

function pl_active()
{
    $a = json_decode((string)setting('active_plugins', '[]'), true);
    return is_array($a) ? $a : [];
}

function pl_save_active($list)
{
    $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $st->execute(['active_plugins', json_encode(array_values(array_unique($list)), JSON_UNESCAPED_UNICODE)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $msg = ['err', '会话已过期'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'enable' || $action === 'disable') {
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['id'] ?? ''));
            $file = $pluginsDir . '/' . $id . '/plugin.php';
            if ($id === '' || !is_file($file)) {
                $msg = ['err', '插件不存在'];
            } else {
                $active = pl_active();
                if ($action === 'enable') { if (!in_array($id, $active, true)) $active[] = $id; }
                else { $active = array_values(array_diff($active, [$id])); }
                pl_save_active($active);
                $msg = ['ok', '已' . ($action === 'enable' ? '启用' : '禁用') . '插件：' . $id];
            }
        } elseif ($action === 'delete') {
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['id'] ?? ''));
            if ($id === '' || !is_dir($pluginsDir . '/' . $id)) {
                $msg = ['err', '插件不存在'];
            } else {
                pl_rmdir($pluginsDir . '/' . $id);
                pl_save_active(array_values(array_diff(pl_active(), [$id])));
                $msg = ['ok', '已删除插件：' . $id];
            }
        } elseif ($action === 'upload') {
            if (empty($_FILES['zip']['tmp_name']) || !is_uploaded_file($_FILES['zip']['tmp_name'])) {
                $msg = ['err', '请选择插件 zip 文件'];
            } elseif (!class_exists('ZipArchive')) {
                $msg = ['err', '服务器未启用 zip 扩展，无法解压'];
            } else {
                $tmpExt = sys_get_temp_dir() . '/mcshare_plugin_' . substr(md5((string)mt_rand()), 0, 8);
                @mkdir($tmpExt, 0755, true);
                $zip = new ZipArchive();
                if ($zip->open($_FILES['zip']['tmp_name']) !== true) {
                    $msg = ['err', '无法打开 zip 文件'];
                } else {
                    $zip->extractTo($tmpExt);
                    $zip->close();

                    $found = null;
                    $wpType = false;
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpExt, FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $f) {
                        if ($f->isFile() && $f->getFilename() === 'plugin.php') { $found = dirname($f->getPathname()); break; }
                    }
                    if (!$found) {
                        $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpExt, FilesystemIterator::SKIP_DOTS));
                        foreach ($it2 as $f) {
                            if ($f->isFile() && preg_match('/\.php$/i', $f->getFilename()) && mc_wp_plugin_info($f->getPathname())) { $found = dirname($f->getPathname()); $wpType = true; break; }
                        }
                    }
                    if (!$found) {
                        $msg = ['err', 'zip 中未找到 plugin.php（MC 插件）或含 Plugin Name 的 PHP 主文件（WordPress 插件）'];
                    } else {
                        if ($wpType) {
                            $info = mc_wp_plugin_info($found . '/' . basename($found) . '.php') ?: mc_wp_plugin_info($found . '/index.php');
                            $main = null;
                            foreach (glob($found . '/*.php') ?: [] as $g) { if (mc_wp_plugin_info($g)) { $main = mc_wp_plugin_info($g); break; } }
                            $info = $main ?: $info;
                            $id = basename($found);
                        } else {
                            $pluginInfo = [];
                            include $found . '/plugin.php';
                            $info = is_array($pluginInfo ?? null) ? $pluginInfo : [];
                            $id = $info['id'] ?? basename($found);
                        }
                        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
                        if ($id === '') {
                            $msg = ['err', '插件 id 无效'];
                        } else {
                            $target = $pluginsDir . '/' . $id;
                            if (is_dir($target)) pl_rmdir($target);
                            @mkdir($target, 0755, true);
                            $ok = true;
                            $rit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($found, FilesystemIterator::SKIP_DOTS));
                            foreach ($rit as $rf) {
                                if (!$rf->isFile()) continue;
                                $rel = str_replace('\\', '/', substr($rf->getPathname(), strlen($found) + 1));
                                @mkdir(dirname($target . '/' . $rel), 0755, true);
                                if (!copy($rf->getPathname(), $target . '/' . $rel)) $ok = false;
                            }
                            if (!$ok) $msg = ['err', '复制插件文件失败'];
                            else $msg = ['ok', '已上传' . ($wpType ? ' WordPress 插件' : '插件') . '：' . ($info['name'] ?? $id) . '（' . $id . '），请启用后生效'];
                        }
                    }
                    pl_rmdir($tmpExt);
                }
            }
        }
    }
}

$active = pl_active();
$plugins = [];
foreach (glob($pluginsDir . '/*', GLOB_ONLYDIR) ?: [] as $pd) {
    $id = basename($pd);
    $info = null;
    $wp = false;
    if (is_file($pd . '/plugin.php')) {
        $pluginInfo = [];
        include $pd . '/plugin.php';
        $info = is_array($pluginInfo ?? null) ? $pluginInfo : [];
        $id   = $info['id'] ?? $id;
    } else {
        $main = mc_wp_find_main_file($pd);
        if (!$main) continue; // 既非 MC 插件也非 WP 插件
        $info = mc_wp_plugin_info($main);
        $wp = true;
        $id = basename($pd);
    }
    $plugins[] = [
        'id'      => $id,
        'name'    => $info['name'] ?? $id,
        'version' => $info['version'] ?? '',
        'author'  => $info['author'] ?? '',
        'desc'    => $info['desc'] ?? '',
        'wp'      => $wp,
        'active'  => in_array($id, $active, true),
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
    <h2>已安装插件</h2>
    <?php if (!$plugins): ?>
      <p class="muted">暂无插件。可通过下方上传 zip，或手动放到 <code>plugins/插件id/</code>（MC 插件含 plugin.php；WordPress 插件含带 <code>Plugin Name:</code> 头的主文件）。</p>
    <?php else: ?>
      <table class="table">
        <thead><tr><th style="width:30px">状态</th><th>插件</th><th style="width:170px">操作</th></tr></thead>
        <tbody>
          <?php foreach ($plugins as $p): ?>
          <tr>
            <td><?= $p['active'] ? '<span class="pill green">启用</span>' : '<span class="pill gray">禁用</span>' ?></td>
            <td>
              <b><?= e($p['name']) ?></b> <span class="muted">(<?= e($p['id']) ?>)</span><?php if (!empty($p['wp'])): ?><span class="pill gray" style="margin-left:6px">WordPress</span><?php endif; ?>
              <?php if ($p['version']): ?><span class="pill gray">v<?= e($p['version']) ?></span><?php endif; ?>
              <?php if ($p['author']): ?><span class="muted" style="font-size:12px">by <?= e($p['author']) ?></span><?php endif; ?>
              <?php if ($p['desc']): ?><div class="muted" style="font-size:12px;margin-top:4px"><?= e($p['desc']) ?></div><?php endif; ?>
            </td>
            <td>
              <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $p['active'] ? 'disable' : 'enable' ?>"><input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="btn ghost btn-sm" type="submit"><?= $p['active'] ? '禁用' : '启用' ?></button></form>
              <form method="post" style="display:inline" onsubmit="return confirm('确定删除插件 <?= e($p['name']) ?> 吗？')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="btn ghost btn-sm" type="submit" style="color:var(--danger)">删除</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <div class="section-title" style="margin:20px 0 8px">上传插件（zip）</div>
    <p class="hint">打包结构：zip 内包含一个插件目录，目录下有 <code>plugin.php</code>（返回 <code>$pluginInfo</code>，含 id/name/version/author/desc，并可用 <code>add_action</code>/<code>add_filter</code> 注册扩展）。参考 <code>plugins/demo/</code>。钩子：mc_body_top / mc_after_header / mc_body_end / mc_list_item / mc_detail_after_content / mc_site_name。</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="upload">
      <div class="field"><input type="file" name="zip" accept=".zip"></div>
      <div class="form-actions"><button class="btn" type="submit"><i class="fa-solid fa-upload"></i> 上传并安装插件</button></div>
    </form>
  </div>
</main>
