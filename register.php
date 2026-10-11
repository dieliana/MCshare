<?php
/**
 * 前台 · 用户注册（邮箱验证码 + 算术验证码）
 */
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';
ensure_session();

if (is_logged()) {
    redirect('/');
}

$errors = [];
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $code     = trim($_POST['code'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');
    $captcha  = (string)($_POST['captcha'] ?? '');

    if (!captcha_check($captcha)) {
        $errors[] = '算术验证码错误，请重新输入';
    }
    if (!preg_match('/^[\w\x{4e00}-\x{9fa5}][\w\x{4e00}-\x{9fa5}\-]{1,19}$/u', $username)) {
        $errors[] = '用户名需 2-20 位，可由中英文、数字、下划线、短横线组成';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = '邮箱格式不正确';
    }
    if (strlen($password) < 6 || strlen($password) > 64) {
        $errors[] = '密码长度需 6-64 位';
    } elseif ($password !== $confirm) {
        $errors[] = '两次输入的密码不一致';
    }
    if (mb_strlen($code) !== 6) {
        $errors[] = '请输入邮箱收到的 6 位验证码';
    }

    if (!$errors) {
        // 用户名 / 邮箱唯一性
        $chkU = db()->prepare('SELECT id FROM users WHERE username = ?');
        $chkU->execute([$username]);
        if ($chkU->fetch()) $errors[] = '该用户名已被占用';
        $chkE = db()->prepare('SELECT id FROM users WHERE email = ?');
        $chkE->execute([$email]);
        if ($chkE->fetch()) $errors[] = '该邮箱已被注册';
    }

    if (!$errors) {
        if (!verify_email_code($email, 'register', $code)) {
            $errors[] = '邮箱验证码错误或已过期，请重新获取';
        }
    }

    if (!$errors) {
        $st = db()->prepare('INSERT INTO users (username, email, password, nickname) VALUES (?,?,?,?)');
        $st->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $username]);
        $_SESSION['user_id'] = (int)db()->lastInsertId();
        $after = $_SESSION['after_login'] ?? '/';
        unset($_SESSION['after_login']);
        redirect($after);
    }
}

$captchaQ = captcha_get();
include __DIR__ . '/includes/site_head.php';
?>
<main class="wrap">
  <div class="auth-wrap">
    <div class="auth-card">
      <h1>注册账号</h1>
      <?php if ($errors): ?><div class="msg-box err"><?= e(implode('；', $errors)) ?></div><?php endif; ?>

      <form method="post" action="/register">
        <label>用户名</label>
        <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" maxlength="20" placeholder="2-20 位，中英文/数字" required>

        <label>邮箱</label>
        <input type="email" name="email" id="regEmail" value="<?= e($_POST['email'] ?? '') ?>" placeholder="用于接收验证码" required>

        <label>邮箱验证码</label>
        <div class="code-row">
          <input type="text" name="code" id="regCode" maxlength="6" placeholder="6 位验证码" required>
          <button type="button" class="btn ghost" id="sendCodeBtn" onclick="sendCode()">发送验证码</button>
        </div>
        <div id="codeMsg" class="msg-box" style="display:none"></div>

        <label>密码</label>
        <input type="password" name="password" placeholder="至少 6 位" required>
        <label>确认密码</label>
        <input type="password" name="confirm" placeholder="再次输入密码" required>

        <label>验证码</label>
        <div class="captcha-row">
          <span class="captcha-q"><?= e($captchaQ) ?></span>
          <input type="text" name="captcha" maxlength="2" placeholder="答案" required>
        </div>

        <button class="btn btn-block" type="submit">注 册</button>
      </form>
      <div class="auth-foot">已有账号？<a href="/login">去登录</a></div>
    </div>
  </div>
</main>
<script src="/assets/js/app.js"></script>
<script>
function sendCode() {
  var email = document.getElementById('regEmail').value.trim();
  var msg = document.getElementById('codeMsg');
  var btn = document.getElementById('sendCodeBtn');
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    msg.className = 'msg-box err'; msg.style.display = 'block';
    msg.textContent = '请先填写正确的邮箱'; return;
  }
  btn.disabled = true;
  msg.className = 'msg-box'; msg.style.display = 'block'; msg.textContent = '发送中…';
  var fd = new FormData();
  fd.append('email', email);
  fd.append('purpose', 'register');
  fd.append('act', 'send_code');
  fetch('ajax.php?act=send_code', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      msg.className = d.ok ? 'msg-box ok' : 'msg-box err';
      msg.style.display = 'block';
      msg.textContent = d.msg;
      btn.disabled = false;
      if (d.ok) {
        var n = 60;
        var t = setInterval(function () {
          btn.textContent = '重新发送(' + n + 's)';
          btn.disabled = true;
          if (--n < 0) { clearInterval(t); btn.textContent = '发送验证码'; btn.disabled = false; }
        }, 1000);
      }
    })
    .catch(function () { msg.className = 'msg-box err'; msg.style.display = 'block'; msg.textContent = '网络异常，请重试'; btn.disabled = false; });
}
</script>
</body>
</html>
