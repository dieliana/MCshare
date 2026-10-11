<?php
/**
 * 后台数据看板
 */
require __DIR__ . '/_init.php';

$count = function (string $sql, ...$params) {
    $st = db()->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
};

$stats = [
    'modpacks'   => $count('SELECT COUNT(*) FROM modpacks'),
    'published'  => $count('SELECT COUNT(*) FROM modpacks WHERE status = 1'),
    'categories' => $count('SELECT COUNT(*) FROM categories'),
    'tags'       => $count('SELECT COUNT(*) FROM tags'),
    'views'      => $count('SELECT COALESCE(SUM(views),0) FROM modpacks'),
    'downloads'  => $count('SELECT COALESCE(SUM(downloads),0) FROM modpacks'),
];

$recent = db()->query(
    'SELECT m.id, m.title, m.status, m.views, m.downloads, m.created_at, c.name AS category_name
     FROM modpacks m LEFT JOIN categories c ON c.id = m.category_id
     ORDER BY m.id DESC LIMIT 8'
)->fetchAll();

$topViews = db()->query(
    'SELECT title, views, downloads FROM modpacks ORDER BY views DESC LIMIT 5'
)->fetchAll();

$pageTitle = '数据看板';
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
  <div class="page-head"><h1><?= e($pageTitle) ?></h1><span class="hello">你好，<?= e($admin['username']) ?></span></div>

  <div class="stats">
    <div class="stat"><div class="stat-num"><?= $stats['modpacks'] ?></div><div class="stat-label">整合包总数</div></div>
    <div class="stat"><div class="stat-num"><?= $stats['published'] ?></div><div class="stat-label">已发布</div></div>
    <div class="stat"><div class="stat-num"><?= $stats['categories'] ?></div><div class="stat-label">分类</div></div>
    <div class="stat"><div class="stat-num"><?= $stats['tags'] ?></div><div class="stat-label">标签</div></div>
    <div class="stat"><div class="stat-num"><?= num($stats['views']) ?></div><div class="stat-label">总浏览量</div></div>
    <div class="stat"><div class="stat-num"><?= num($stats['downloads']) ?></div><div class="stat-label">总下载量</div></div>
  </div>

  <div class="two-col">
    <section class="panel">
      <h2>最近发布</h2>
      <table class="table">
        <thead><tr><th>标题</th><th>分类</th><th>状态</th><th>浏览</th><th>下载</th><th>发布时间</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td><a class="link" href="modpack-form.php?id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a></td>
            <td><?= e($r['category_name'] ?: '-') ?></td>
            <td><span class="pill <?= $r['status'] ? 'green' : 'gray' ?>"><?= $r['status'] ? '已发布' : '草稿' ?></span></td>
            <td><?= (int)$r['views'] ?></td>
            <td><?= (int)$r['downloads'] ?></td>
            <td><?= e(fmt_date($r['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="6" class="empty-row">还没有整合包，去「整合包管理」添加一个吧。</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>

    <section class="panel">
      <h2>热门整合包 TOP 5</h2>
      <table class="table">
        <thead><tr><th>标题</th><th>浏览</th><th>下载</th></tr></thead>
        <tbody>
        <?php foreach ($topViews as $t): ?>
          <tr><td><?= e($t['title']) ?></td><td><?= (int)$t['views'] ?></td><td><?= (int)$t['downloads'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$topViews): ?><tr><td colspan="3" class="empty-row">暂无数据</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>
  </div>
</main>
</body>
</html>
