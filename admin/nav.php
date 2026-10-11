<?php
/**
 * 后台 · 导航管理
 * 自定义前台首页顶部导航菜单（存储于 settings 的 nav_items JSON）
 */
require __DIR__ . '/_init.php';

$pageTitle = '导航管理';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $msg = ['err', '会话已过期'];
    } else {
        $items = [];
        // 更新已有项
        foreach (($_POST['title'] ?? []) as $i => $t) {
            $t = trim((string)$t);
            if ($t === '') continue;
            if (isset($_POST['del'][$i])) continue; // 删除
            $url = trim((string)($_POST['url'][$i] ?? ''));
            $items[] = [
                'title'   => $t,
                'url'     => $url,
                'sort'    => (int)($_POST['sort'][$i] ?? 0),
                'visible' => isset($_POST['visible'][$i]) ? 1 : 0,
            ];
        }
        // 新增项
        $nt = trim((string)($_POST['new_title'] ?? ''));
        if ($nt !== '') {
            $items[] = [
                'title'   => $nt,
                'url'     => trim((string)($_POST['new_url'] ?? '')),
                'sort'    => (int)($_POST['new_sort'] ?? 0),
                'visible' => isset($_POST['new_visible']) ? 1 : 0,
            ];
        }
        usort($items, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));

        $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
        $st->execute(['nav_items', json_encode($items, JSON_UNESCAPED_UNICODE)]);
        $msg = ['ok', '导航已保存'];
    }
}

$nav = json_decode((string)setting('nav_items', '[]'), true);
if (!is_array($nav)) $nav = [];
$nav = array_values($nav);
usort($nav, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));
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
    <h2>导航菜单</h2>
    <p class="hint">配置前台顶部导航链接。sort 数字越小越靠前；勾选「显示」才会在前台展示；链接支持站点内相对路径（如 /categories）或站外完整 URL（如 https://example.com）。</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <table class="table">
        <thead>
          <tr>
            <th style="width:40px">排序</th>
            <th>名称</th>
            <th>链接</th>
            <th style="width:70px">显示</th>
            <th style="width:60px">删除</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$nav): ?>
            <tr><td colspan="5" class="muted" style="text-align:center">还没有导航项，请在下方新增</td></tr>
          <?php else: ?>
            <?php foreach ($nav as $i => $n): ?>
            <tr>
              <td><input type="number" name="sort[<?= $i ?>]" value="<?= e($n['sort'] ?? 0) ?>" style="width:56px"></td>
              <td><input type="text" name="title[<?= $i ?>]" value="<?= e($n['title'] ?? '') ?>" style="width:100%"></td>
              <td><input type="text" name="url[<?= $i ?>]" value="<?= e($n['url'] ?? '') ?>" style="width:100%" placeholder="/ 或 https://"></td>
              <td style="text-align:center"><input type="checkbox" name="visible[<?= $i ?>]" value="1" <?= !empty($n['visible']) ? 'checked' : '' ?>></td>
              <td style="text-align:center"><input type="checkbox" name="del[<?= $i ?>]" value="1"></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <div class="section-title" style="margin:20px 0 8px">新增导航项</div>
      <div class="two-col">
        <div class="field"><label>名称</label><input type="text" name="new_title" placeholder="如：关于本站" value=""></div>
        <div class="field"><label>链接</label><input type="text" name="new_url" placeholder="/about 或 https://…"></div>
      </div>
      <div class="two-col">
        <div class="field"><label>排序</label><input type="number" name="new_sort" value="10"></div>
        <div class="field"><label>显示</label><label class="inline-check"><input type="checkbox" name="new_visible" value="1" checked> 前台展示</label></div>
      </div>

      <div class="form-actions"><button class="btn" type="submit"><i class="fa-solid fa-save"></i> 保存导航</button></div>
    </form>
  </div>
</main>
