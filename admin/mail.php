<?php
/**
 * 后台 · 邮件发送（勾选用户批量发邮件 / 测试 SMTP）
 */
require __DIR__ . '/_init.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
    $subject = trim($_POST['subject'] ?? '');
    $body    = trim($_POST['body'] ?? '');
    $uids    = array_map('intval', (array)($_POST['uid'] ?? []));
    $uids    = array_values(array_unique(array_filter($uids, fn($v) => $v > 0)));

    if ($subject === '' || $body === '') {
        $msg = ['err', '请填写邮件主题和正文'];
    } elseif (setting('smtp_host', '') === '') {
        $msg = ['err', '尚未配置 SMTP，请先在「站点设置」中填写 SMTP 邮箱'];
    } elseif (!$uids) {
        $msg = ['err', '请至少勾选一个收件用户'];
    } else {
        // 取勾选用户的邮箱
        $place = implode(',', array_fill(0, count($uids), '?'));
        $st = db()->prepare('SELECT email FROM users WHERE id IN (' . $place . ') AND email <> ""');
        $st->execute($uids);
        $to = array_column($st->fetchAll(), 'email');
        $to = array_values(array_unique(array_filter($to, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));

        if (!$to) {
            $msg = ['err', '勾选的用户中没有有效邮箱'];
        } else {
            $html = strip_tags((string)$_POST['body'] ?? '', '<p><br><b><strong><i><em><u><h2><h3><ul><ol><li><a><blockquote><hr><span><div>');
            $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html);
            $html = preg_replace('/<a([^>]*)href="(?!https?:)/i', '<a$1href="https://', $html);
            $okCnt = 0; $failCnt = 0; $firstErr = '';
            foreach ($to as $email) {
                if (smtp_mail($email, $subject, $html)) $okCnt++;
                else { $failCnt++; if ($firstErr === '') $firstErr = smtp_error(); }
            }
            $msg = ['ok', "已发送：成功 {$okCnt}，失败 {$failCnt}（共 " . count($to) . ' 个收件人）' . ($failCnt > 0 && $firstErr !== '' ? "；首次失败原因：{$firstErr}" : '')];
        }
    }
}

$st = db()->query('SELECT id, username, nickname, email, status FROM users ORDER BY id DESC LIMIT 500');
$users = $st->fetchAll();
$smtpReady = setting('smtp_host', '') !== '';

$pageTitle = '邮件发送';
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
  <div class="page-head"><h1><?= e($pageTitle) ?><span class="count">（<?= count($users) ?> 位用户）</span></h1></div>

  <?php if (!$smtpReady): ?>
    <div class="alert err">尚未配置 SMTP 邮箱，请在「站点设置 → SMTP 邮箱」中填写后再发送邮件。</div>
  <?php endif; ?>

  <?php if ($msg): ?><div class="alert <?= $msg[0] ?>"><?= e($msg[1]) ?></div><?php endif; ?>

  <section class="panel" style="max-width:860px">
    <form method="post" action="mail.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="field">
        <label>选择收件用户（可多选）
          <span style="font-weight:400;margin-left:10px">
            <label class="inline-check" style="display:inline-flex"><input type="checkbox" onclick="toggleAll(this)"> 全选 / 全不选</label>
          </span>
        </label>

        <?php if ($users): ?>
        <div class="user-pick">
          <?php foreach ($users as $u): ?>
            <label class="pick-item">
              <input type="checkbox" name="uid[]" value="<?= (int)$u['id'] ?>" class="pick-cb" <?= ((int)($_GET['to'] ?? 0) === (int)$u['id']) ? 'checked' : '' ?>>
              <span class="pick-avatar"><?= e($u['nickname'] ?: $u['username']) ?></span>
              <span class="pick-mail"><?= e($u['email']) ?></span>
              <span class="pick-status <?= (int)$u['status'] === 1 ? 'on' : '' ?>"><?= (int)$u['status'] === 1 ? '正常' : '封禁' ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
          <p class="hint">还没有任何用户。</p>
        <?php endif; ?>
      </div>

      <div class="field"><label>邮件主题</label>
        <input type="text" name="subject" value="<?= e($_POST['subject'] ?? '') ?>" placeholder="请输入主题">
      </div>
      <div class="field"><label>邮件正文（富文本）</label>
        <div class="rich-wrap">
          <div class="rich-toolbar" id="richToolbar">
            <button type="button" data-cmd="bold" title="加粗"><b>B</b></button>
            <button type="button" data-cmd="italic" title="斜体"><i>I</i></button>
            <button type="button" data-cmd="underline" title="下划线"><u>U</u></button>
            <span class="rich-sep"></span>
            <button type="button" data-cmd="formatBlock" data-val="h2" title="大标题">H2</button>
            <button type="button" data-cmd="formatBlock" data-val="h3" title="小标题">H3</button>
            <button type="button" data-cmd="insertUnorderedList" title="无序列表">• 列表</button>
            <button type="button" data-cmd="insertOrderedList" title="有序列表">1. 列表</button>
            <span class="rich-sep"></span>
            <button type="button" data-cmd="createLink" title="插入链接"><i class="fa-solid fa-link"></i> 链接</button>
            <button type="button" data-cmd="removeFormat" title="清除格式"><i class="fa-solid fa-xmark"></i> 清除</button>
          </div>
          <div class="rich-editor" id="richEditor" contenteditable="true" data-placeholder="请输入正文内容…"><?= e($_POST['body'] ?? '') ?></div>
          <input type="hidden" name="body" id="bodyHidden">
        </div>
      </div>

      <div class="form-actions">
        <button class="btn" type="submit" <?= $smtpReady ? '' : 'disabled' ?>>发送给勾选用户</button>
      </div>
    </form>
  </section>
</main>

<script>
function toggleAll(checkbox) {
  document.querySelectorAll('.pick-cb').forEach(function (cb) { cb.checked = checkbox.checked; });
}

// 富文本编辑器（邮件正文）
(function () {
  var toolbar = document.getElementById('richToolbar');
  var editor = document.getElementById('richEditor');
  var hidden = document.getElementById('bodyHidden');
  if (!toolbar || !editor || !hidden) return;
  toolbar.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-cmd]');
    if (!btn) return;
    var cmd = btn.getAttribute('data-cmd');
    var val = btn.getAttribute('data-val') || null;
    if (cmd === 'createLink') {
      var url = prompt('请输入链接地址（http:// 或 https://）:');
      if (url && /^https?:\/\//i.test(url)) document.execCommand('createLink', false, url);
      return;
    }
    document.execCommand(cmd, false, val);
  });
  var form = document.querySelector('.content form');
  form.addEventListener('submit', function () { hidden.value = editor.innerHTML; });
})();
</script>
</body>
</html>
