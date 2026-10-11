<?php
/**
 * ============================================================
 *  MC Modpack Hub · Web 安装向导
 * ------------------------------------------------------------
 *  流程：环境检测 → 数据库配置 → 站点/管理员 → 执行安装
 *  安装完成后生成 config.php 与 install.lock，
 *  再次访问本页面将提示已安装。
 * ============================================================
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = __DIR__;
$lock = $base . '/install.lock';
$configFile = $base . '/config.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------- 已安装拦截（存在 install.lock 即视为已安装） ----------
if (file_exists($lock)) {
    $step = 99; // 已安装
} else {
    $step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
    if ($step < 1) $step = 1;
}

// 记录本次 POST 原始提交的步骤，避免步骤间相互串扰
$postedStep = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (int)($_POST['step'] ?? 0) : 0;

// ---------- 步骤 4：执行安装 ----------
if ($postedStep === 4) {
    $dbConf  = $_SESSION['install_db']  ?? null;
    $siteAdm = $_SESSION['install_site'] ?? null;

    if (!$dbConf || !$siteAdm) { $step = 2; }
    else {
        $errors = [];
        try {
            // 1) 连接数据库
            $host = $dbConf['host']; $port = $dbConf['port'];
            $name = $dbConf['name']; $user = $dbConf['user']; $pass = $dbConf['pass'];
            $charset = 'utf8mb4';

            if (!empty($dbConf['create_db'])) {
                $pdoSrv = new PDO("mysql:host=$host;port=$port;charset=$charset", $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $pdoSrv->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }

            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=$charset", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // 2) 执行 schema.sql
            $schema = @file_get_contents($base . '/schema.sql');
            if ($schema === false) throw new Exception('无法读取 schema.sql');
            foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
                if ($stmt !== '') $pdo->exec($stmt);
            }

            // 3) 写入站点设置
            $setSt = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
            $settings = [
                'site_name'   => $siteAdm['site_name'],
                'site_desc'   => $siteAdm['site_desc'],
                'announcement'=> $siteAdm['announcement'],
                'footer_text' => $siteAdm['site_name'] . ' · MC Modpack Hub',
                'theme'       => 'emerald',
                'auto_refresh'=> '120',
            ];
            foreach ($settings as $k => $v) $setSt->execute([$k, $v]);

            // 3.1) 可选 SMTP 配置（勾选启用才写入）
            if (!empty($siteAdm['smtp']['enable'])) {
                $s = $siteAdm['smtp'];
                foreach ([
                    'smtp_host'     => $s['host'],
                    'smtp_port'     => $s['port'],
                    'smtp_user'     => $s['user'],
                    'smtp_pass'     => $s['pass'],
                    'smtp_from'     => $s['from'],
                    'smtp_fromname' => $s['fromname'],
                    'smtp_secure'   => $s['secure'],
                ] as $k => $v) $setSt->execute([$k, $v]);
            }

            // 4) 创建管理员
            $hash = password_hash($siteAdm['password'], PASSWORD_DEFAULT);
            $admSt = $pdo->prepare('INSERT INTO admins (username, password) VALUES (?, ?)');
            $admSt->execute([$siteAdm['username'], $hash]);

            // 5) 写入 config.php
            $cfg  = "<?php\n";
            $cfg .= "// 本文件由安装向导生成，请勿直接编辑数据库连接信息\n";
            $cfg .= "define('DB_HOST', "    . var_export($host, true) . ");\n";
            $cfg .= "define('DB_PORT', "    . var_export((string)$port, true) . ");\n";
            $cfg .= "define('DB_NAME', "    . var_export($name, true) . ");\n";
            $cfg .= "define('DB_USER', "    . var_export($user, true) . ");\n";
            $cfg .= "define('DB_PASS', "    . var_export($pass, true) . ");\n";
            $cfg .= "define('DB_CHARSET', 'utf8mb4');\n\n";
            $cfg .= "define('SITE_NAME', " . var_export($siteAdm['site_name'], true) . ");\n";
            $cfg .= "define('SITE_DESC', " . var_export($siteAdm['site_desc'], true) . ");\n";
            // 自动检测站点根地址（install.php 位于根目录），避免后台页拼错图片路径
            $__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $__host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $__dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $cfg .= "define('SITE_URL', " . var_export($__scheme . '://' . $__host . $__dir, true) . ");\n\n";
            $cfg .= "define('UPLOAD_DIR', __DIR__ . '/uploads');\n";
            $cfg .= "define('UPLOAD_URL', 'uploads');\n\n";
            $cfg .= "define('INSTALLED', true);\n";
            if (@file_put_contents($configFile, $cfg) === false) {
                throw new Exception('config.php 写入失败，请检查目录写入权限');
            }

            // 6) 写安装锁
            @file_put_contents($lock, date('Y-m-d H:i:s') . "\n");

            $done = true;
        } catch (Exception $ex) {
            $errors[] = $ex->getMessage();
        }
        $step = 4; // 无论成功失败，展示第 4 步结果
    }
}

// ---------- 步骤 3 提交：保存站点/管理员信息 ----------
elseif ($postedStep === 3) {
    $site_name    = trim($_POST['site_name'] ?? '');
    $site_desc    = trim($_POST['site_desc'] ?? '');
    $announcement = trim($_POST['announcement'] ?? '');
    $username     = trim($_POST['username'] ?? '');
    $password     = (string)($_POST['password'] ?? '');
    $password2    = (string)($_POST['password2'] ?? '');

    $errs = [];
    if ($site_name === '') $errs[] = '请填写站点名称';
    if ($username === '' || mb_strlen($username) < 3) $errs[] = '管理员用户名至少 3 个字符';
    if (mb_strlen($password) < 6) $errs[] = '管理员密码至少 6 位';
    if ($password !== $password2) $errs[] = '两次输入的密码不一致';

    if ($errs) {
        $step = 3;
        $step3Errors = $errs;
    } else {
        $smtp = [
            'enable'   => isset($_POST['smtp_enable']),
            'host'     => trim($_POST['smtp_host'] ?? ''),
            'port'     => trim($_POST['smtp_port'] ?? '587'),
            'user'     => trim($_POST['smtp_user'] ?? ''),
            'pass'     => (string)($_POST['smtp_pass'] ?? ''),
            'from'     => trim($_POST['smtp_from'] ?? ''),
            'fromname' => trim($_POST['smtp_fromname'] ?? ''),
            'secure'   => in_array($_POST['smtp_secure'] ?? '', ['none', 'ssl', 'tls'], true) ? $_POST['smtp_secure'] : 'tls',
        ];
        $_SESSION['install_site'] = [
            'site_name'    => $site_name,
            'site_desc'    => $site_desc,
            'announcement' => $announcement,
            'username'     => $username,
            'password'     => $password,
            'smtp'         => $smtp,
        ];
        $step = 4;
    }
}

// ---------- 步骤 2 提交：保存数据库配置 ----------
elseif ($postedStep === 2) {
    $host = trim($_POST['db_host'] ?? '127.0.0.1');
    $port = trim($_POST['db_port'] ?? '3306');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = (string)($_POST['db_pass'] ?? '');
    $create = isset($_POST['create_db']);

    $errs = [];
    if ($host === '') $errs[] = '请填写数据库地址';
    if (!ctype_digit($port) || (int)$port <= 0 || (int)$port > 65535) $errs[] = '数据库端口无效';
    if ($name === '') $errs[] = '请填写数据库名';
    if ($user === '') $errs[] = '请填写数据库用户名';

    if (!$errs) {
        try {
            if ($create) {
                $tmp = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $tmp->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } else {
                $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }
            $_SESSION['install_db'] = compact('host', 'port', 'name', 'user', 'pass', 'create');
            $step = 3;
        } catch (Exception $ex) {
            $step = 2;
            $step2Errors = ['数据库连接失败：' . $ex->getMessage()];
        }
    } else {
        $step = 2;
        $step2Errors = $errs;
    }
}

// ---------- 环境检测 ----------
function env_checks()
{
    $checks = [];
    $checks[] = ['PHP 版本 >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), '当前 ' . PHP_VERSION];
    $exts = ['pdo', 'pdo_mysql', 'json', 'session', 'mbstring'];
    foreach ($exts as $ext) {
        $checks[] = ['PHP 扩展：' . $ext, extension_loaded($ext), extension_loaded($ext) ? '已启用' : '未启用'];
    }
    $checks[] = ['目录写入权限（站点根）', is_writable(__DIR__), is_writable(__DIR__) ? '可写' : '不可写'];
    $checks[] = ['配置写入权限', is_writable(__DIR__) || is_writable(dirname(__DIR__)), 'config.php 需可写入'];
    return $checks;
}
$checks = env_checks();
$envOk = true;
foreach ($checks as $c) { if (!$c[1]) $envOk = false; }

// 步骤 1 需环境通过才能继续
if ($step === 1 && !$envOk) {
    $step = 1;
}

$dbConf  = $_SESSION['install_db']  ?? null;
$siteAdm = $_SESSION['install_site'] ?? null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>安装向导 · MC Modpack Hub</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: "Segoe UI", "Microsoft YaHei", system-ui, sans-serif;
    background: linear-gradient(135deg, #0e1524 0%, #16253a 55%, #1a2f22 100%);
    min-height: 100vh; color: #dfe7f2;
    display: flex; align-items: center; justify-content: center; padding: 24px;
  }
  .card {
    width: 100%; max-width: 640px;
    background: rgba(20, 30, 48, .92);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 16px; padding: 34px 40px;
    box-shadow: 0 20px 60px rgba(0,0,0,.45);
  }
  .logo { font-size: 24px; font-weight: 700; color: #3ddc84; margin-bottom: 4px; }
  .sub { color: #8ba0bd; font-size: 13px; margin-bottom: 24px; }
  h2 { font-size: 18px; margin-bottom: 18px; color: #fff; }
  .stepbar { display: flex; gap: 8px; margin-bottom: 26px; }
  .stepbar .dot { flex: 1; height: 5px; border-radius: 4px; background: #2a3a55; }
  .stepbar .dot.on { background: #3ddc84; }
  label { display: block; font-size: 13px; color: #a9bcd6; margin: 14px 0 6px; }
  input[type=text], input[type=password], input[type=number] {
    width: 100%; padding: 10px 12px; border-radius: 8px;
    border: 1px solid #33456a; background: #121c2e; color: #eef3fb; font-size: 14px;
  }
  input:focus { outline: none; border-color: #3ddc84; }
  .row { display: flex; gap: 12px; }
  .row > div { flex: 1; }
  .check { display: flex; align-items: center; gap: 8px; margin-top: 12px; font-size: 13px; color: #a9bcd6; }
  .check input { width: auto; }
  .btn {
    display: inline-block; margin-top: 22px; padding: 11px 26px;
    background: #3ddc84; color: #0b2013; border: none; border-radius: 8px;
    font-size: 15px; font-weight: 600; cursor: pointer; text-decoration: none;
  }
  .btn:hover { background: #4df094; }
  .btn.ghost { background: #2a3a55; color: #dbe7f5; }
  .err { background: rgba(255,80,80,.12); border: 1px solid rgba(255,80,80,.35); color: #ff9c9c; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-top: 16px; }
  .ok  { background: rgba(61,220,132,.12); border: 1px solid rgba(61,220,132,.35); color: #7ce8ad; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-top: 16px; }
  ul.checks { list-style: none; }
  ul.checks li { display: flex; justify-content: space-between; padding: 10px 2px; border-bottom: 1px solid #23334e; font-size: 14px; }
  ul.checks li span.s { color: #3ddc84; font-weight: 600; }
  ul.checks li span.f { color: #ff9c9c; font-weight: 600; }
  .meta { font-size: 12px; color: #5f7391; margin-top: 4px; }
  .summary { background: #121c2e; border: 1px solid #33456a; border-radius: 10px; padding: 16px 18px; font-size: 14px; line-height: 1.9; }
  .summary b { color: #3ddc84; }
  .actions { display: flex; justify-content: space-between; align-items: center; }
  .actions .btn { margin-top: 22px; }
  a.back { color: #8ba0bd; font-size: 13px; text-decoration: none; margin-top: 24px; display: inline-block; }
</style>
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
</head>
<body>
<div class="card">
  <div class="logo"><i class="fa-solid fa-cube"></i> MC Modpack Hub</div>
  <div class="sub">我的世界整合包资源分享 · 安装向导</div>

  <?php if ($step === 99): ?>
    <h2>已安装</h2>
    <div class="ok">站点已安装完成。如需重新安装，请删除站点根目录下的 <b>install.lock</b> 文件后重试。</div>
    <a class="btn" href="index.php">进入前台首页</a>
    <a class="btn ghost" href="admin/login.php">进入管理后台</a>

  <?php elseif ($step === 1): ?>
    <div class="stepbar"><div class="dot on"></div><div class="dot"></div><div class="dot"></div><div class="dot"></div></div>
    <h2>第 1 步 / 4 · 环境检测</h2>
    <ul class="checks">
      <?php foreach ($checks as $c): ?>
        <li><?= h($c[0]) ?><span class="<?= $c[1] ? 's' : 'f' ?>"><?= $c[1] ? '<i class="fa-solid fa-check"></i>' : '<i class="fa-solid fa-xmark"></i>' ?> <?= h($c[2]) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$envOk): ?>
      <div class="err">存在未通过项，请先解决环境问题后再继续安装。</div>
      <form method="post" action="install.php"><input type="hidden" name="step" value="1"><button class="btn ghost" type="submit">重新检测</button></form>
    <?php else: ?>
      <form method="post" action="install.php"><input type="hidden" name="step" value="2"><button class="btn" type="submit">下一步：数据库配置 →</button></form>
    <?php endif; ?>

  <?php elseif ($step === 2): ?>
    <div class="stepbar"><div class="dot on"></div><div class="dot on"></div><div class="dot"></div><div class="dot"></div></div>
    <h2>第 2 步 / 4 · 数据库配置</h2>
    <form method="post" action="install.php">
      <input type="hidden" name="step" value="2">
      <div class="row">
        <div>
          <label>数据库地址</label>
          <input type="text" name="db_host" value="<?= h($_POST['db_host'] ?? ($dbConf['host'] ?? '127.0.0.1')) ?>" placeholder="127.0.0.1">
        </div>
        <div style="max-width:130px;">
          <label>端口</label>
          <input type="number" name="db_port" value="<?= h($_POST['db_port'] ?? ($dbConf['port'] ?? '3306')) ?>" placeholder="3306">
        </div>
      </div>
      <label>数据库名</label>
      <input type="text" name="db_name" value="<?= h($_POST['db_name'] ?? ($dbConf['name'] ?? '')) ?>" placeholder="如 mcshare">
      <label>数据库用户名</label>
      <input type="text" name="db_user" value="<?= h($_POST['db_user'] ?? ($dbConf['user'] ?? 'root')) ?>" placeholder="root">
      <label>数据库密码</label>
      <input type="password" name="db_pass" value="<?= h($_POST['db_pass'] ?? ($dbConf['pass'] ?? '')) ?>" placeholder="数据库密码">
      <div class="check"><input type="checkbox" name="create_db" id="create_db" <?= isset($_POST['create_db']) || !empty($dbConf['create']) ? 'checked' : '' ?>><label for="create_db" style="margin:0">数据库不存在时自动创建（需账号有创建权限）</label></div>
      <?php if (!empty($step2Errors)): ?>
        <div class="err"><?= h(implode('；', $step2Errors)) ?></div>
      <?php endif; ?>
      <div class="actions">
        <a class="back" href="install.php?step=1">← 上一步</a>
        <button class="btn" type="submit">下一步：站点信息 →</button>
      </div>
    </form>

  <?php elseif ($step === 3): ?>
    <div class="stepbar"><div class="dot on"></div><div class="dot on"></div><div class="dot on"></div><div class="dot"></div></div>
    <h2>第 3 步 / 4 · 站点信息与管理账号</h2>
    <form method="post" action="install.php">
      <input type="hidden" name="step" value="3">
      <label>站点名称</label>
      <input type="text" name="site_name" value="<?= h($_POST['site_name'] ?? ($siteAdm['site_name'] ?? '')) ?>" placeholder="MC Modpack Hub">
      <label>站点描述</label>
      <input type="text" name="site_desc" value="<?= h($_POST['site_desc'] ?? ($siteAdm['site_desc'] ?? '')) ?>" placeholder="我的世界整合包资源分享">
      <label>站点公告（可选）</label>
      <input type="text" name="announcement" value="<?= h($_POST['announcement'] ?? ($siteAdm['announcement'] ?? '')) ?>" placeholder="欢迎语，展示在前台顶部">
      <label>管理员用户名</label>
      <input type="text" name="username" value="<?= h($_POST['username'] ?? ($siteAdm['username'] ?? 'admin')) ?>" placeholder="admin">
      <div class="row">
        <div><label>管理员密码</label><input type="password" name="password" placeholder="至少 6 位"></div>
        <div><label>确认密码</label><input type="password" name="password2" placeholder="再次输入"></div>
      </div>

      <hr style="border:none;border-top:1px solid #23334e;margin:24px 0">
      <h2 style="font-size:16px">SMTP 邮件配置（可选，可跳过）</h2>
      <p class="meta">用于发送注册邮箱验证码、批量邮件、站内信邮件通知；跳过则安装后再到后台「站点设置」配置。</p>
      <div class="check"><input type="checkbox" name="smtp_enable" id="smtp_enable" value="1" <?= isset($_POST['smtp_enable']) ? 'checked' : '' ?>><label for="smtp_enable" style="margin:0">启用 SMTP</label></div>
      <div id="smtpBox" style="display:none">
        <div class="row">
          <div><label>SMTP 服务器</label><input type="text" name="smtp_host" value="<?= h($_POST['smtp_host'] ?? '') ?>" placeholder="如 smtp.qq.com / smtp.163.com"></div>
          <div style="max-width:130px;"><label>端口</label><input type="number" name="smtp_port" value="<?= h($_POST['smtp_port'] ?? '587') ?>"></div>
        </div>
        <div class="row">
          <div><label>邮箱账号</label><input type="text" name="smtp_user" value="<?= h($_POST['smtp_user'] ?? '') ?>" placeholder="your@qq.com"></div>
          <div><label>密码 / 授权码</label><input type="password" name="smtp_pass" placeholder="QQ 邮箱用授权码"></div>
        </div>
        <div class="row">
          <div><label>发件人地址</label><input type="text" name="smtp_from" value="<?= h($_POST['smtp_from'] ?? '') ?>" placeholder="留空默认用账号"></div>
          <div><label>发件人名称</label><input type="text" name="smtp_fromname" value="<?= h($_POST['smtp_fromname'] ?? '') ?>" placeholder="站点名称"></div>
        </div>
        <label>加密方式</label>
        <select name="smtp_secure" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #33456a;background:#121c2e;color:#eef3fb;font-size:14px">
          <option value="tls">STARTTLS（端口 587）</option>
          <option value="ssl">SSL（端口 465）</option>
          <option value="none">无加密</option>
        </select>
      </div>

      <?php if (!empty($step3Errors)): ?>
        <div class="err"><?= h(implode('；', $step3Errors)) ?></div>
      <?php endif; ?>
      <div class="actions">
        <a class="back" href="install.php?step=2">← 上一步</a>
        <button class="btn" type="submit">下一步：确认安装 →</button>
      </div>
    </form>
    <script>
    (function () {
      var cb = document.getElementById('smtp_enable'), box = document.getElementById('smtpBox');
      function t() { if (cb) box.style.display = cb.checked ? 'block' : 'none'; }
      if (cb) { cb.addEventListener('change', t); t(); }
    })();
    </script>

  <?php elseif ($step === 4): ?>
    <div class="stepbar"><div class="dot on"></div><div class="dot on"></div><div class="dot on"></div><div class="dot on"></div></div>
    <h2>第 4 步 / 4 · 确认安装</h2>
    <?php if (!empty($done)): ?>
      <div class="ok"><b>安装成功！</b> 站点已初始化完成，可进入首页或后台。</div>
      <p class="meta" style="margin-top:12px">⚠ 出于安全考虑，建议安装完成后删除站点根目录下的 <b>install.lock</b> 外的临时文件，并修改后台默认密码。</p>
      <a class="btn" href="index.php">进入前台首页</a>
      <a class="btn ghost" href="admin/login.php">进入管理后台</a>
    <?php elseif (!empty($errors)): ?>
      <div class="err"><?= h(implode('；', $errors)) ?></div>
      <form method="post" action="install.php"><input type="hidden" name="step" value="4"><button class="btn ghost" type="submit">重试安装</button></form>
      <a class="back" href="install.php?step=3">← 返回修改</a>
    <?php else: ?>
      <div class="summary">
        数据库地址：<b><?= h($dbConf['host']) ?>:<?= h($dbConf['port']) ?></b><br>
        数据库名：<b><?= h($dbConf['name']) ?></b>（用户名：<b><?= h($dbConf['user']) ?></b>）<br>
        站点名称：<b><?= h($siteAdm['site_name']) ?></b><br>
        管理员：<b><?= h($siteAdm['username']) ?></b>
      </div>
      <form method="post" action="install.php">
        <input type="hidden" name="step" value="4">
        <div class="actions">
          <a class="back" href="install.php?step=3">← 上一步</a>
          <button class="btn" type="submit">开始安装 <i class="fa-solid fa-cube"></i></button>
        </div>
      </form>
    <?php endif; ?>

  <?php else: ?>
    <div class="err">未知步骤，请重新开始。</div>
    <a class="btn" href="install.php?step=1">重新开始</a>
  <?php endif; ?>
</div>
</body>
</html>
