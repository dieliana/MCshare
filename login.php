<?php
/**
 * 前台 · 用户登录（算术验证码）
 */
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';
ensure_session();

if (is_logged()) {
    redirect('/');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account  = trim($_POST['account'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $captcha  = (string)($_POST['captcha'] ?? '');

    if (!captcha_check($captcha)) {
        $errors[] = '算术验证码错误，请重新输入';
    } elseif ($account === '' || $password === '') {
        $errors[] = '请输入账号和密码';
    } elseif (mc_login_blocked()) {
        $errors[] = '尝试次数过多，请 10 分钟后再试';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$account, $account]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, $u['password'])) {
            mc_login_failed();
            $errors[] = '账号或密码错误';
        } elseif ((int)$u['status'] !== 1) {
            $errors[] = '该账号已被禁止登录';
        } else {
            mc_login_reset();
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$u['id'];
            if (!empty($_POST['remember'])) {
                $p = session_get_cookie_params();
                setcookie(session_name(), session_id(), [
                    'expires'  => time() + 30 * 86400,
                    'path'     => $p['path'],
                    'domain'   => $p['domain'] ?? '',
                    'secure'   => !empty($p['secure']),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            $after = $_SESSION['after_login'] ?? '/';
            unset($_SESSION['after_login']);
            redirect($after);
        }
    }
}

$captchaQ = captcha_get();
include __DIR__ . '/includes/site_head.php';
?>
<main class="wrap">
  <div class="auth-wrap">
    <div class="auth-card">
      <h1>登录</h1>
      <?php if ($errors): ?><div class="msg-box err"><?= e(implode('；', $errors)) ?></div><?php endif; ?>

      <form method="post" action="/login">
        <label>账号</label>
        <input type="text" name="account" value="<?= e($_POST['account'] ?? '') ?>" placeholder="用户名或邮箱" autofocus required>

        <label>密码</label>
        <input type="password" name="password" placeholder="请输入密码" required>

        <label>验证码</label>
        <div class="captcha-row">
          <span class="captcha-q"><?= e($captchaQ) ?></span>
          <input type="text" name="captcha" maxlength="2" placeholder="答案" required>
        </div>

        <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);margin:8px 0">
          <input type="checkbox" name="remember" value="1"> 记住我（30 天内保持登录）
        </label>

        <button class="btn btn-block" type="submit">登 录</button>
      </form>
      <div class="auth-foot">还没有账号？<a href="/register">立即注册</a></div>
    </div>
  </div>
</main>
<script src="/assets/js/app.js"></script>
</body>
</html>
