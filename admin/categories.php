<?php
/**
 * 后台 · 分类管理（增删改）
 */
require __DIR__ . '/_init.php';

$msg = '';

// 新增
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    if (!csrf_check($_POST['csrf'] ?? '')) $msg = ['err', '会话已过期'];
    else {
        $name = trim($_POST['name'] ?? '');
        $sort = (int)($_POST['sort'] ?? 0);
        if ($name === '') $msg = ['err', '请填写分类名称'];
        else {
            $slug = slugify($name);
            $st = db()->prepare('SELECT id FROM categories WHERE slug = ?');
            $st->execute([$slug]);
            if ($st->fetch()) $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            db()->prepare('INSERT INTO categories (name, slug, sort) VALUES (?,?,?)')->execute([$name, $slug, $sort]);
            $msg = ['ok', '分类已添加'];
        }
    }
}
// 删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'del') {
    if (csrf_check($_POST['csrf'] ?? '')) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]); // modpacks.category_id 置空
            $msg = ['ok', '分类已删除'];
        }
    }
}
// 编辑
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    if (csrf_check($_POST['csrf'] ?? '')) {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $sort = (int)($_POST['sort'] ?? 0);
        if ($id > 0 && $name !== '') {
            $slug = slugify($name);
            $st = db()->prepare('SELECT id FROM categories WHERE slug = ? AND id != ?');
            $st->execute([$slug, $id]);
            if ($st->fetch()) $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            db()->prepare('UPDATE categories SET name=?, slug=?, sort=? WHERE id=?')->execute([$name, $slug, $sort, $id]);
            $msg = ['ok', '分类已更新'];
        }
    }
}

$list = db()->query('SELECT c.*, (SELECT COUNT(*) FROM modpacks m WHERE m.category_id = c.id) AS cnt FROM categories c ORDER BY c.sort ASC, c.id ASC')->fetchAll();

$pageTitle = '分类管理';
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

  <?php if ($msg): ?><div class="alert <?= $msg[0] ?>"><?= e($msg[1]) ?></div><?php endif; ?>

  <section class="panel">
    <h2>新增分类</h2>
    <form class="inline-form" method="post" action="categories.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add">
      <input type="text" name="name" placeholder="分类名称（如：原版增强）" required>
      <input type="number" name="sort" value="0" placeholder="排序（小在前）" style="width:120px">
      <button class="btn" type="submit">添加</button>
    </form>
  </section>

  <section class="panel">
    <h2>分类列表</h2>
    <table class="table">
      <thead><tr><th>ID</th><th>名称</th><th>别名</th><th>排序</th><th>整合包数</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($list as $c): $cid = (int)$c['id']; ?>
        <form id="cat-edit-<?= $cid ?>" method="post" action="categories.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="edit">
          <input type="hidden" name="id" value="<?= $cid ?>">
        </form>
        <form id="cat-del-<?= $cid ?>" method="post" action="categories.php" onsubmit="return confirm('删除该分类后，其下的整合包将变为未分类。确定删除？')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="del">
          <input type="hidden" name="id" value="<?= $cid ?>">
        </form>
        <tr>
          <td><?= $cid ?></td>
          <td><input type="text" name="name" form="cat-edit-<?= $cid ?>" value="<?= e($c['name']) ?>"></td>
          <td><span class="muted"><?= e($c['slug']) ?></span></td>
          <td><input type="number" name="sort" form="cat-edit-<?= $cid ?>" value="<?= (int)$c['sort'] ?>" style="width:70px"></td>
          <td><?= (int)$c['cnt'] ?></td>
          <td class="ops">
            <button class="op" type="submit" form="cat-edit-<?= $cid ?>">保存</button>
            <button class="op danger" type="submit" form="cat-del-<?= $cid ?>">删除</button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="6" class="empty-row">还没有分类。</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
</main>
</body>
</html>
