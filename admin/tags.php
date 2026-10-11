<?php
/**
 * 后台 · 标签管理（增删）
 */
require __DIR__ . '/_init.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) $msg = ['err', '会话已过期'];
    else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') $msg = ['err', '请填写标签名称'];
            else {
                try {
                    db()->prepare('INSERT INTO tags (name) VALUES (?)')->execute([$name]);
                    $msg = ['ok', '标签已添加'];
                } catch (Exception $e) {
                    $msg = ['err', '标签已存在'];
                }
            }
        } elseif ($action === 'del') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                db()->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]); // 关联表级联删除
                $msg = ['ok', '标签已删除'];
            }
        }
    }
}

$list = db()->query(
    'SELECT t.id, t.name, COUNT(mt.modpack_id) AS cnt
     FROM tags t LEFT JOIN modpack_tags mt ON mt.tag_id = t.id
     GROUP BY t.id, t.name ORDER BY cnt DESC, t.id ASC'
)->fetchAll();

$pageTitle = '标签管理';
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
    <h2>新增标签</h2>
    <form class="inline-form" method="post" action="tags.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add">
      <input type="text" name="name" placeholder="标签名称（如：冒险 / 科技 / 优化）" required>
      <button class="btn" type="submit">添加</button>
    </form>
  </section>

  <section class="panel">
    <h2>标签列表</h2>
    <table class="table">
      <thead><tr><th>ID</th><th>名称</th><th>使用数</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($list as $t): ?>
        <tr>
          <td><?= (int)$t['id'] ?></td>
          <td><span class="pill green">#<?= e($t['name']) ?></span></td>
          <td><?= (int)$t['cnt'] ?></td>
          <td class="ops">
            <form method="post" action="tags.php" onsubmit="return confirm('确定删除该标签吗？')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="del">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="op danger" type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="4" class="empty-row">还没有标签。</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
</main>
</body>
</html>
