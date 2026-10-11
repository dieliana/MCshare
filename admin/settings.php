<?php
/**
 * 后台 · 站点设置
 * 站点信息 / 主题 / Logo 与标签图标 / 主页自动刷新
 */
require __DIR__ . '/_init.php';

$themes = [
    'emerald' => '深绿翡翠（默认）',
    'ocean'   => '海洋蓝',
    'violet'  => '暗夜紫',
    'forest'  => '森林绿',
    'light'   => '明亮',
];

$fields = ['site_name', 'site_desc', 'announcement', 'footer_text', 'theme', 'auto_refresh', 'comment_moderation',
    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_fromname', 'smtp_secure', 'github_token', 'maintenance', 'maintenance_msg', 'api_enabled', 'api_key'];

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $msg = ['err', '会话已过期'];
    } else {
        $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');

        $theme = $_POST['theme'] ?? 'emerald';
        if (!isset($themes[$theme])) $theme = 'emerald';
        $refresh = (int)($_POST['auto_refresh'] ?? 0);
        if ($refresh < 0) $refresh = 0;

        $vals = [
            'site_name'   => trim($_POST['site_name'] ?? ''),
            'site_desc'   => trim($_POST['site_desc'] ?? ''),
            'announcement'=> trim($_POST['announcement'] ?? ''),
            'footer_text' => trim($_POST['footer_text'] ?? ''),
            'theme'       => $theme,
            'auto_refresh'=> (string)$refresh,
            'comment_moderation'=> (int)((($_POST['comment_moderation'] ?? 0) == 1) ? 1 : 0),
            'smtp_host'    => trim($_POST['smtp_host'] ?? ''),
            'smtp_port'    => trim($_POST['smtp_port'] ?? '587'),
            'smtp_user'    => trim($_POST['smtp_user'] ?? ''),
            'smtp_pass'    => (string)($_POST['smtp_pass'] ?? ''),
            'smtp_from'    => trim($_POST['smtp_from'] ?? ''),
            'smtp_fromname'=> trim($_POST['smtp_fromname'] ?? ''),
            'smtp_secure'  => in_array($_POST['smtp_secure'] ?? '', ['none', 'ssl', 'tls'], true) ? $_POST['smtp_secure'] : 'tls',
            'github_token' => trim($_POST['github_token'] ?? ''),
            'api_enabled'  => (int)((( $_POST['api_enabled'] ?? 0) == 1) ? 1 : 0),
            'api_key'      => trim($_POST['api_key'] ?? ''),
            'maintenance'    => (int)(((($_POST['maintenance'] ?? 0) == 1) ? 1 : 0)),
            'maintenance_msg'=> trim($_POST['maintenance_msg'] ?? ''),
        ];
        if ($vals['site_name'] === '') {
            $msg = ['err', '站点名称不能为空'];
        } else {
            $didSave = true;
            foreach ($vals as $k => $v) $st->execute([$k, $v]);

            // Logo 上传（支持 jpg/png/webp/gif/svg/ico）
            if (!empty($_FILES['logo']['name'])) {
                $p = upload_icon($_FILES['logo'], 'logo');
                if ($p) $st->execute(['logo', $p]);
                else $msg = ['err', 'Logo 上传失败：仅支持 jpg/png/webp/gif/svg/ico，且不超过 8MB'];
            }
            if (isset($_POST['remove_logo'])) $st->execute(['logo', '']);

            // 标签图标（favicon）上传（支持 jpg/png/webp/gif/svg/ico）
            if (!empty($_FILES['favicon']['name'])) {
                $p = upload_icon($_FILES['favicon'], 'favicon');
                if ($p) $st->execute(['favicon', $p]);
                else $msg = ['err', '标签图标上传失败：仅支持 jpg/png/webp/gif/svg/ico，且不超过 8MB'];
            }
            if (isset($_POST['remove_favicon'])) $st->execute(['favicon', '']);

            if (!$msg) $msg = ['ok', '设置已保存'];
        }
    }
}

$vals = [];
foreach ($fields as $f) $vals[$f] = setting($f, '', !empty($didSave));
if ($vals['auto_refresh'] === '') $vals['auto_refresh'] = '120';
$vals['logo']    = setting('logo', '');
$vals['favicon'] = setting('favicon', '');

$pageTitle = '站点设置';
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

  <form method="post" action="settings.php" class="form" style="max-width:680px" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="field"><label>站点名称 <span class="req">*</span></label>
      <input type="text" name="site_name" value="<?= e($vals['site_name'] ?: (defined('SITE_NAME') ? SITE_NAME : '')) ?>"></div>

    <div class="field"><label>站点描述</label>
      <input type="text" name="site_desc" value="<?= e($vals['site_desc']) ?>" placeholder="用于前台标题下的简介"></div>

    <div class="field"><label>站点公告（显示在前台顶部）</label>
      <input type="text" name="announcement" value="<?= e($vals['announcement']) ?>" placeholder="留空则不显示"></div>

    <div class="field"><label>页脚文字</label>
      <textarea name="footer_text" rows="3" style="width:100%;background:var(--bg2);border:1px solid var(--line);color:var(--text);border-radius:8px;padding:10px 12px;font-size:14px" placeholder="站点版权信息"><?= e($vals['footer_text']) ?></textarea>
      <p class="mini-meta" style="font-size:12px;margin-top:6px">支持 HTML：可填写版权、备案号、友情链接等</p></div>

    <div class="field">
      <label>前台主题</label>
      <select name="theme">
        <?php foreach ($themes as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= ($vals['theme'] ?: 'emerald') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="hint">前台访客也可在顶部点「<i class="fa-solid fa-palette"></i>」按钮自行切换主题（本机记忆）。</p>
    </div>

    <div class="field">
      <label>站点 Logo（显示在顶栏）</label>
      <?php if ($vals['logo']): ?>
        <div class="cover-preview"><img src="<?= e(cover_url($vals['logo'])) ?>" alt="当前 Logo" style="max-height:56px"></div>
        <label class="inline-check"><input type="checkbox" name="remove_logo" value="1"> 移除当前 Logo</label>
      <?php else: ?>
        <p class="muted">未设置，将显示默认图标 <i class="fa-solid fa-cube"></i></p>
      <?php endif; ?>
      <label class="file-btn"><i class="fa-solid fa-folder-open"></i> 选择 Logo<input type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml,.ico" onchange="this.parentNode.nextElementSibling.textContent=this.files[0]?this.files[0].name:'"></label><span class="file-name"></span>
      <p class="hint">支持 jpg/png/webp/gif/svg/ico，≤ 8MB（SVG/ICO 常见用于透明 Logo）</p>
    </div>

    <div class="field">
      <label>浏览器标签图标（Favicon）</label>
      <?php if ($vals['favicon']): ?>
        <div class="cover-preview"><img src="<?= e(cover_url($vals['favicon'])) ?>" alt="当前图标" style="max-height:40px"></div>
        <label class="inline-check"><input type="checkbox" name="remove_favicon" value="1"> 移除当前图标</label>
      <?php else: ?>
        <p class="muted">未设置</p>
      <?php endif; ?>
      <label class="file-btn"><i class="fa-solid fa-folder-open"></i> 选择 Favicon<input type="file" name="favicon" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml,.ico" onchange="this.parentNode.nextElementSibling.textContent=this.files[0]?this.files[0].name:'"></label><span class="file-name"></span>
      <p class="hint">建议使用 png/ico/svg 图标（如 32×32 或 64×64）</p>
    </div>

    <div class="field">
      <label>主页自动刷新间隔（秒）</label>
      <input type="number" name="auto_refresh" value="<?= (int)($vals['auto_refresh'] ?: '0') ?>" min="0" placeholder="如 120">
      <p class="hint">前台首页会每隔该秒数自动刷新以展示最新内容；填 0 表示关闭自动刷新。</p>
    </div>

    <div class="field" style="border-top:1px solid var(--line);padding-top:16px;margin-top:16px">
      <label>评论是否需要审核</label>
      <label class="inline-check"><input type="checkbox" name="comment_moderation" value="1" <?= (int)$vals['comment_moderation'] === 1 ? 'checked' : '' ?>> 开启评论审核（新评论需后台审核通过后才展示）</label>
      <p class="hint">关闭时新评论直接展示；开启后新评论进入「评论管理 → 待审核」，通过后才在前台显示。</p>
    </div>

    
    <div class="field" style="border-top:1px solid var(--line);padding-top:20px;margin-top:20px">
      <div class="section-title" style="margin:0 0 6px">SMTP 邮箱（用于注册验证码 / 批量发邮件）</div>
      <p class="hint" style="margin-bottom:8px">在服务商开启 SMTP 服务并取得授权码后填写，未配置则注册的邮箱验证码与后台批量邮件不可用。</p>
      <div class="two-col" style="margin-top:6px">
        <div class="field"><label>SMTP 服务器</label><input type="text" name="smtp_host" value="<?= e($vals['smtp_host']) ?>" placeholder="如 smtp.qq.com / smtp.163.com"></div>
        <div class="field"><label>端口</label><input type="number" name="smtp_port" value="<?= e($vals['smtp_port'] ?: '587') ?>" min="1" max="65535"></div>
      </div>
      <div class="two-col">
        <div class="field"><label>账号（邮箱）</label><input type="text" name="smtp_user" value="<?= e($vals['smtp_user']) ?>" placeholder="你的邮箱地址"></div>
        <div class="field"><label>密码 / 授权码</label><input type="password" name="smtp_pass" value="<?= e($vals['smtp_pass']) ?>" placeholder="授权码或密码"></div>
      </div>
      <div class="two-col">
        <div class="field"><label>发件人地址（From）</label><input type="email" name="smtp_from" value="<?= e($vals['smtp_from']) ?>" placeholder="留空默认用账号"></div>
        <div class="field"><label>发件人名称</label><input type="text" name="smtp_fromname" value="<?= e($vals['smtp_fromname']) ?>" placeholder="显示在收件人处的名称"></div>
      </div>
      <div class="field">
        <label>加密方式</label>
        <select name="smtp_secure">
          <option value="tls" <?= ($vals['smtp_secure'] ?: 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS（587 常用）</option>
          <option value="ssl" <?= $vals['smtp_secure'] === 'ssl' ? 'selected' : '' ?>>SSL（465 常用）</option>
          <option value="none" <?= $vals['smtp_secure'] === 'none' ? 'selected' : '' ?>>无加密</option>
        </select>
      </div>
      <p class="hint">QQ 邮箱示例：登录邮箱 → 设置 → 账户 → 开启「SMTP 服务」→ 生成授权码；服务器 smtp.qq.com，端口 465(SSL) 或 587(STARTTLS)；账号填完整邮箱（如 xxx@qq.com），密码/授权码填 16 位授权码（不是 QQ 登录密码）；发件人地址需与账号一致。</p><p class="hint">保存后可到「邮件发送」页发一封测试邮件验证配置。</p>
    </div>
    <div class="field" style="border-top:1px solid var(--line);padding-top:20px;margin-top:20px">
      <div class="section-title" style="margin:0 0 6px">GitHub API Token（可选，用于「系统更新」）</div>
      <p class="hint" style="margin-bottom:8px">调用 GitHub API 时使用。不填则匿名调用，限流约每小时 60 次；填写 Personal Access Token 后额度提升到每小时 5000 次。</p>
      <div class="field">
        <label>GitHub Personal Access Token</label>
        <input type="password" name="github_token" value="<?= e($vals['github_token'] ?? '') ?>" placeholder="ghp_xxx / ghp_xxx（留空则不认证）" autocomplete="off">
      </div>
      <p class="hint">获取：GitHub → Settings → Developer settings → Personal access tokens → Generate new token（勾选 repo 权限即可）。Token 仅保存在本站数据库，仅用于 GitHub API 认证。</p>
    </div>
    <div class="field" style="border-top:1px solid var(--line);padding-top:20px;margin-top:20px">
      <div class="section-title" style="margin:0 0 6px">维护模式</div>
      <p class="hint" style="margin-bottom:8px">开启后前台访客访问将显示维护提示，管理员不受影响；适合升级、维护期间使用。</p>
      <label class="inline-check"><input type="checkbox" name="maintenance" value="1" <?= !empty($vals['maintenance']) ? 'checked' : '' ?>> 开启维护模式</label>
      <div class="field" style="margin-top:10px"><label>维护提示语</label><textarea name="maintenance_msg" rows="3" style="width:100%;background:var(--bg2);border:1px solid var(--line);color:var(--text);border-radius:8px;padding:9px 12px;font-size:14px"><?= e($vals['maintenance_msg']) ?></textarea></div>
    </div>
    <div class="field" style="border-top:1px solid var(--line);padding-top:20px;margin-top:20px">
      <div class="section-title" style="margin:0 0 6px">API 接口（供外部调用整合包数据）</div>
      <p class="hint" style="margin-bottom:8px">开启后，其他同源码网站或第三方可通过接口拉取已发布的整合包数据（列表 / 详情 / 分类 / 标签）。</p>
      <label class="inline-check"><input type="checkbox" name="api_enabled" value="1" <?= !empty($vals['api_enabled']) ? 'checked' : '' ?>> 开启 API</label>
      <div class="field" style="margin-top:10px"><label>API Key（可选）</label>
        <input type="text" name="api_key" value="<?= e($vals['api_key']) ?>" placeholder="留空则不鉴权；填写后调用需带 key 参数或 X-API-Key 请求头" autocomplete="off">
      </div>
      <p class="hint">端点（返回 JSON）：<code>/api.php?act=modpacks</code>（列表，可加 <code>category/version/loader/keyword/page/per_page</code>）、<code>act=modpack&amp;id=1</code>（详情）、<code>act=categories</code>（分类）、<code>act=tags</code>（标签）。伪静态形式：<code>/api/modpacks</code> 等。</p>
    </div>
    <div class="form-actions"><button class="btn" type="submit">保存设置</button></div>
  </form>
</main>
</body>
</html>
