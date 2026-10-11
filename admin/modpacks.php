<?php
/**
 * 后台 · 整合包列表
 */
require __DIR__ . '/_init.php';

$q    = trim($_GET['q'] ?? '');
$cat  = (int)($_GET['cat'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 15;

$where  = ['1=1'];
$params = [];
if ($q !== '') {
    $where[]  = '(m.title LIKE :kw1 OR m.author LIKE :kw2)';
    $params[':kw1'] = '%' . $q . '%';
    $params[':kw2'] = '%' . $q . '%';
}
if ($cat > 0) {
    $where[]  = 'm.category_id = :cat';
    $params[':cat'] = $cat;
}
$whereSql = implode(' AND ', $where);

$cs = db()->prepare("SELECT COUNT(*) FROM modpacks m WHERE $whereSql");
$cs->execute($params);
$total = (int)$cs->fetchColumn();
$pages = max(1, ceil($total / $per));
$page  = min($page, $pages);
$off   = ($page - 1) * $per;

$sql = "SELECT m.*, c.name AS category_name FROM modpacks m
        LEFT JOIN categories c ON c.id = m.category_id
        WHERE $whereSql ORDER BY m.id DESC LIMIT $per OFFSET $off";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$categories = db()->query('SELECT * FROM categories ORDER BY sort ASC, id ASC')->fetchAll();

$pageTitle = '整合包管理';
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
  <div class="page-head">
    <h1><?= e($pageTitle) ?> <span class="count">(<?= $total ?>)</span></h1>
    <a class="btn" href="modpack-form.php">＋ 新增整合包</a>
  </div>

  <form class="toolbar" method="get" action="modpacks.php">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="按标题 / 作者搜索">
    <select name="cat">
      <option value="0">全部分类</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $cat == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn ghost" type="submit">筛选</button>
  </form>

  <section class="panel">
    <table class="table">
      <thead>
        <tr><th>封面</th><th>标题</th><th>分类</th><th>MC版本</th><th>状态</th><th>浏览</th><th>下载</th><th>操作</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <?php if ($r['cover']): ?>
              <img class="tbl-thumb" src="<?= e(cover_url($r['cover'])) ?>" alt="">
            <?php else: ?>
              <span class="tbl-nothumb">无</span>
            <?php endif; ?>
          </td>
          <td><a class="link" href="modpack-form.php?id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a></td>
          <td><?= e($r['category_name'] ?: '-') ?></td>
          <td><?= e($r['mc_version'] ?: '-') ?></td>
          <td><span class="pill <?= $r['status'] ? 'green' : 'gray' ?>"><?= $r['status'] ? '已发布' : '草稿' ?></span></td>
          <td><?= (int)$r['views'] ?></td>
          <td><?= (int)$r['downloads'] ?></td>
          <td class="ops">
            <a class="op" href="modpack-form.php?id=<?= (int)$r['id'] ?>">编辑</a>
            <form method="post" action="modpack-delete.php" onsubmit="return confirm('确定删除该整合包吗？此操作不可恢复。')" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="op danger" type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="empty-row">没有符合条件的整合包。</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>

  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php if ($page > 1): ?><a class="pbtn" href="modpacks.php?page=<?= $page-1 ?>&q=<?= e(urlencode($q)) ?>&cat=<?= $cat ?>">上一页</a><?php endif; ?>
    <span class="pinfo"><?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="pbtn" href="modpacks.php?page=<?= $page+1 ?>&q=<?= e(urlencode($q)) ?>&cat=<?= $cat ?>">下一页</a><?php endif; ?>
  </div>
  <?php endif; ?>
</main>
</body>
</html>
