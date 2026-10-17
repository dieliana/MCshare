<?php
/**
 * 后台 · 整合包列表
 */
require __DIR__ . '/_init.php';

/* ---------- 批量管理 ---------- */
if (($_POST['action'] ?? '') === 'batch') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $msg = ['err', '会话已过期，请刷新页面后重试'];
    } else {
        $ids = array_values(array_filter(array_map('intval', $_POST['batch_ids'] ?? []), function ($v) { return $v > 0; }));
        $op  = $_POST['batch_op'] ?? '';
        $cnt = count($ids);
        if (!$cnt) {
            $msg = ['err', '请先勾选要操作的整合包'];
        } else {
            $in = implode(',', array_fill(0, $cnt, '?'));
            try {
                if ($op === 'publish') {
                    db()->prepare("UPDATE modpacks SET status = 1 WHERE id IN ($in)")->execute($ids);
                    $msg = ['ok', "已发布 $cnt 条整合包"];
                } elseif ($op === 'unpublish') {
                    db()->prepare("UPDATE modpacks SET status = 0 WHERE id IN ($in)")->execute($ids);
                    $msg = ['ok', "已下架 $cnt 条整合包"];
                } elseif ($op === 'delete') {
                    db()->prepare("DELETE FROM modpacks WHERE id IN ($in)")->execute($ids); // 关联表级联删除
                    $msg = ['ok', "已删除 $cnt 条整合包"];
                } elseif ($op === 'category') {
                    $cid = (int)($_POST['batch_cat'] ?? 0);
                    if ($cid <= 0) $msg = ['err', '请选择目标分类'];
                    else {
                        db()->prepare("UPDATE modpacks SET category_id = ? WHERE id IN ($in)")->execute(array_merge([$cid], $ids));
                        $msg = ['ok', "已修改 $cnt 条整合包的分类"];
                    }
                } elseif ($op === 'tag') {
                    $names = array_values(array_filter(array_map('trim', explode(',', $_POST['batch_tag'] ?? '')), function ($x) { return $x !== ''; }));
                    if (!$names) $msg = ['err', '请填写要添加的标签（用逗号分隔）'];
                    else {
                        $tagFind = db()->prepare('SELECT id FROM tags WHERE name = ?');
                        $tagAdd  = db()->prepare('INSERT INTO tags (name) VALUES (?)');
                        $rel     = db()->prepare('INSERT IGNORE INTO modpack_tags (modpack_id, tag_id) VALUES (?,?)');
                        $tids = [];
                        foreach ($names as $nm) {
                            $tagFind->execute([$nm]);
                            $tid = $tagFind->fetchColumn();
                            if (!$tid) {
                                try { $tagAdd->execute([$nm]); $tid = (int)db()->lastInsertId(); }
                                catch (Exception $e) { $tagFind->execute([$nm]); $tid = $tagFind->fetchColumn(); }
                            }
                            if ($tid) $tids[] = (int)$tid;
                        }
                        if (!$tids) $msg = ['err', '标签创建失败'];
                        else {
                            foreach ($ids as $mpid) foreach ($tids as $tid) $rel->execute([$mpid, $tid]);
                            $msg = ['ok', "已为 $cnt 条整合包添加 " . count($tids) . ' 个标签'];
                        }
                    }
                } else {
                    $msg = ['err', '未知的批量操作'];
                }
            } catch (Exception $e) {
                $msg = ['err', '批量操作失败：' . $e->getMessage()];
            }
        }
    }
}

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
    <form class="toolbar" method="post" action="modpacks.php" id="batch-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="batch">
      <span class="batch-hint">已选 <b id="sel-count">0</b> 项</span>
      <select name="batch_op" id="batch-op">
        <option value="">批量操作…</option>
        <option value="publish">发布</option>
        <option value="unpublish">下架</option>
        <option value="category">修改分类</option>
        <option value="tag">添加标签</option>
        <option value="delete">删除</option>
      </select>
      <select name="batch_cat" id="batch-cat" style="display:none">
        <option value="0">选择目标分类…</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="batch_tag" id="batch-tag" placeholder="标签名，多个用逗号分隔" style="display:none">
      <button class="btn" type="submit">执行</button>
    </form>

    <table class="table">
      <thead>
        <tr><th><input type="checkbox" class="check-all" onchange="checkAll(this)" title="全选本页"></th><th>封面</th><th>标题</th><th>分类</th><th>MC版本</th><th>状态</th><th>浏览</th><th>下载</th><th>操作</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><input type="checkbox" class="row-check" value="<?= (int)$r['id'] ?>" onchange="updateCount()"></td>
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
      <?php if (!$rows): ?><tr><td colspan="9" class="empty-row">没有符合条件的整合包。</td></tr><?php endif; ?>
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
<script>
function updateCount(){ var n=document.querySelectorAll('.row-check:checked').length; document.getElementById('sel-count').textContent=n; }
function checkAll(cb){ document.querySelectorAll('.row-check').forEach(function(c){c.checked=cb.checked;}); updateCount(); }
document.getElementById('batch-form').addEventListener('submit', function(e){
  var f=this; f.querySelectorAll('input[name="batch_ids[]"]').forEach(function(x){x.remove();});
  document.querySelectorAll('.row-check:checked').forEach(function(c){
    var i=document.createElement('input'); i.type='hidden'; i.name='batch_ids[]'; i.value=c.value; f.appendChild(i);
  });
});
document.getElementById('batch-op').addEventListener('change', function(){
  var op=this.value;
  document.getElementById('batch-cat').style.display = op==='category' ? '' : 'none';
  document.getElementById('batch-tag').style.display  = op==='tag' ? '' : 'none';
});
</script>
</body>
</html>
