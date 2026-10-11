<?php
/**
 * 后台 · 图库
 * 上传管理图片；?pick=1 为"选用"模式（供其他表单弹窗调用）
 */
require __DIR__ . '/_init.php';

$pick = isset($_GET['pick']) && $_GET['pick'] == 1;
$field = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['field'] ?? 'cover_url');

$msg = '';

// 删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'del') {
    if (csrf_check($_POST['csrf'] ?? '')) {
        $p = trim((string)$_POST['path'] ?? '');
        // 只允许删除 gallery 目录下的文件
        if ($p !== '' && strpos($p, 'gallery/') === 0 && strpos($p, '..') === false) {
            $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . $p;
            if (is_file($abs)) {
                @unlink($abs);
                $msg = ['ok', '图片已删除'];
            } else {
                $msg = ['err', '文件不存在'];
            }
        } else {
            $msg = ['err', '无效路径'];
        }
    }
}

// 上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $msg = ['err', '会话已过期'];
    } elseif (empty($_FILES['images'])) {
        $msg = ['err', '请选择要上传的图片'];
    } else {
        $files = $_FILES['images'];
        $ok = 0; $fail = 0;
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        foreach ($names as $i => $n) {
            $one = [
                'name'     => $n,
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
            if (upload_image($one, 'gallery')) $ok++;
            else $fail++;
        }
        if ($ok > 0) {
            $msg = ['ok', "上传成功 {$ok} 张" . ($fail > 0 ? "，失败 {$fail} 张" : '')];
        } else {
            $msg = ['err', '上传失败：仅支持 jpg/png/webp/gif，且不超过 8MB'];
        }
    }
}

// 读取图库图片列表
$dir = rtrim(UPLOAD_DIR, '/\\') . '/gallery';
$images = [];
if (is_dir($dir)) {
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..' || $f === 'index.php') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $images[] = ['path' => 'gallery/' . $f, 'name' => $f, 'mtime' => @filemtime($dir . '/' . $f)];
        }
    }
    usort($images, fn($a, $b) => $b['mtime'] - $a['mtime']);
}

$pageTitle = '图库管理';
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
<?php if (!$pick): ?>
  <?php include __DIR__ . '/_nav.php'; ?>
<?php endif; ?>
<main class="content" <?= $pick ? 'style="max-width:980px;margin:0 auto"' : '' ?>>
  <div class="page-head">
    <h1><?= $pick ? '选择图片（点击图片即可选用）' : e($pageTitle) ?><span class="count"> (<?= count($images) ?>)</span></h1>
    <?php if ($pick): ?><a class="btn ghost" href="javascript:window.close()">关闭</a><?php endif; ?>
  </div>

  <?php if ($msg): ?><div class="alert <?= $msg[0] ?>"><?= e($msg[1]) ?></div><?php endif; ?>

  <?php if (!$pick): ?>
  <section class="panel">
    <h2>上传图片</h2>
    <form class="inline-form" method="post" action="gallery.php" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="upload">
      <label class="file-btn"><i class="fa-solid fa-folder-open"></i> 选择图片（可多选）<input type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required onchange="this.parentNode.nextElementSibling.textContent=this.files.length+' 个文件已选择'"></label><span class="file-name"></span>
      <button class="btn" type="submit">上传到服务器</button>
    </form>
    <p class="hint">可多选；仅支持 jpg/png/webp/gif，单张 ≤ 8MB。上传后的图片会自动存到服务器的 uploads/gallery 目录。</p>
  </section>
  <?php endif; ?>

  <section class="panel">
    <div class="gallery-grid">
      <?php foreach ($images as $im): ?>
        <div class="gallery-item">
          <img src="<?= e(thumb_url($im['path'])) ?>" alt="<?= e($im['name']) ?>" loading="lazy"
               <?= $pick ? 'style="cursor:pointer" onclick="pickImage(\'' . e(addslashes($im['path'])) . '\')"' : '' ?>>
          <div class="gallery-item-bar">
            <code title="相对路径"><?= e($im['path']) ?></code>
          </div>
          <div class="gallery-item-ops">
            <button class="op" type="button" onclick="copyPath('<?= e(addslashes($im['path'])) ?>')">复制路径</button>
            <?php if (!$pick): ?>
            <form method="post" action="gallery.php" onsubmit="return confirm('确定删除该图片吗？')" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="del">
              <input type="hidden" name="path" value="<?= e($im['path']) ?>">
              <button class="op danger" type="submit">删除</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$images): ?><p class="empty-row" style="grid-column:1/-1;text-align:center;color:var(--muted);padding:40px 0">图库为空，先上传一些图片吧。</p><?php endif; ?>
    </div>
  </section>
</main>

<script>
// 选用模式：把路径回填到打开本窗口的表单输入框，然后关闭
function pickImage(path) {
  var field = <?= $pick ? json_encode($field) : "''" ?>;
  if (field && window.opener && !window.opener.closed) {
    var el = window.opener.document.getElementById(field);
    if (el) {
      el.value = path;
      window.close();
      return;
    }
  }
  copyPath(path);
}

function copyPath(path) {
  var t = document.createElement('textarea');
  t.value = path;
  document.body.appendChild(t);
  t.select();
  try { document.execCommand('copy'); } catch (e) {}
  document.body.removeChild(t);
  alert('已复制路径：' + path);
}
</script>
</body>
</html>
