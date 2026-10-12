<?php
/**
 * 后台 · 系统更新（GitHub 源）
 * 连接 GitHub 仓库获取最新版本，一键拉取 ZIP 解压覆盖站点文件。
 * 自动保护：config.php、install.lock、uploads/ 上传目录不会被覆盖。
 * 仓库：https://github.com/dieliana/MCshare
 */
require __DIR__ . '/_init.php'; // 由 _init.php 定义 BASE_PATH
require_once __DIR__ . '/../includes/update.php';

$pageTitle = '系统更新';
$msg = '';
$repo  = 'dieliana/MCshare';
$branch = 'main';
$apiUrl = "https://api.github.com/repos/{$repo}/commits?per_page=1";
$verFile = __DIR__ . '/../VERSION';

$remote = null;
$local  = (file_exists($verFile) && ($_v = trim(file_get_contents($verFile)))) ? $_v : '';
$appVersion = '1.3.9';
$githubToken = setting('github_token', '');
function up_local_ver($local)
{
    if (preg_match('/^v?\d+(\.\d+)+/', $local, $m)) return $m[0];
    return 'v1.0.0';
}
$localVer = up_local_ver($local);

// 自动更新设置
$autoUpdate = (int)setting('auto_update', 1);
$autoKeyword = trim((string)setting('auto_update_keyword', 'auto')) !== '' ? setting('auto_update_keyword') : 'auto';

// ---------- 动作 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $msg = ['err', '会话已过期，请刷新后重试'];
    } elseif ($action === 'check') {
        $remote = mc_remote_latest($repo, $githubToken);
        if (!$remote) {
            $msg = ['err', '检查更新失败：无法访问 GitHub API（可能被限流，稍后再试）'];
        } else {
            $_SESSION['update_remote'] = $remote;
            $msg = ['ok', '已获取远程最新版本：' . $remote['short'] . '（' . $remote['date'] . '）'];
        }
    } elseif ($action === 'update') {
        $remote = $_SESSION['update_remote'] ?? [];
        if (!$remote) {
            $msg = ['err', '请先「检查更新」'];
        } else {
            $r = mc_do_update($remote['tag'], $remote['sha'], $repo, $branch);
            if ($r['ok']) {
                $thumbsMsg = '';
                if (function_exists('generate_all_thumbs')) {
                    $tn = generate_all_thumbs();
                    if ($tn) $thumbsMsg = '（已生成 ' . $tn . ' 个缩略图）';
                }
                @unlink(__DIR__ . '/../batch_thumbs.php');
                $msg = ['ok', '更新完成：已更新 ' . $r['copied'] . ' 个文件，跳过保护文件 ' . $r['skipped'] . ' 个。当前版本 ' . $remote['short'] . $thumbsMsg];
                unset($_SESSION['update_remote']);
                $local = 'v' . ($remote['tag'] !== '' ? $remote['tag'] : $appVersion) . ' ' . $remote['sha'];
                $localVer = up_local_ver($local);
            } else {
                $msg = ['err', '更新失败：' . $r['msg']];
            }
        }
    }
}

// 页面展示用远程信息（session 恢复）
if (!$remote && !empty($_SESSION['update_remote'])) $remote = $_SESSION['update_remote'];

// 本地已是最新？
$isLatest = false;
if ($remote && preg_match('/\b([0-9a-f]{40})\b/i', $local, $m)) {
    $isLatest = (strtolower($m[1]) === strtolower($remote['sha']));
}

// 更新日志（GitHub Releases）
$releases = mc_up_api("https://api.github.com/repos/{$repo}/releases?per_page=8", $githubToken);
$releases = (is_array($releases) && isset($releases[0])) ? $releases : null;
?>
<?php include __DIR__ . '/_nav.php'; ?>
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
<main class="content">
  <div class="page-head"><h1><?= e($pageTitle) ?></h1></div>

  <?php if ($msg): ?>
    <div class="alert <?= $msg[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($msg[1]) ?></div>
  <?php endif; ?>

  <div class="panel">
    <h2>GitHub 更新源</h2>
    <p class="muted">仓库：<a class="link" href="https://github.com/<?= e($repo) ?>" target="_blank" rel="noopener"><?= e($repo) ?></a>（分支：<?= e($branch) ?>）</p>

    <table class="table" style="margin-top:14px">
      <tbody>
        <tr><th style="width:150px">当前版本</th><td>
          <span class="pill green"><?= e($localVer) ?></span>
          <?php if (preg_match('/\b([0-9a-f]{7,40})\b/i', $local, $m)): ?><span class="muted" style="font-size:12px">(commit <?= e($m[1]) ?>)</span><?php endif; ?>
        </td></tr>
        <tr><th>远程最新版本</th><td>
          <?php if ($remote): ?>
            <span class="pill green"><?= e($remote['ver']) ?></span> · <?= e($remote['date']) ?><br>
            <span class="muted" style="font-size:13px"><?= e(mb_substr($remote['msg'], 0, 120)) ?></span>
            <?php if ($isLatest): ?><br><span class="pill green"><i class="fa-solid fa-check"></i> 已是最新版本，无需更新</span><?php endif; ?>
          <?php else: ?>
            <span class="muted">尚未检查</span>
          <?php endif; ?>
        </td></tr>
        <tr><th>自动更新</th><td>
          <span class="pill <?= $autoUpdate ? 'green' : '' ?>"><?= $autoUpdate ? '已开启' : '未开启' ?></span>
          <span class="muted" style="font-size:12px">检测到新 tag 含触发词「<?= e($autoKeyword) ?>」且版本更新时，会由每日定时任务自动拉取更新（设置于「站点设置 → 更新与 API」）。</span>
        </td></tr>
      </tbody>
    </table>

    <div class="form-actions" style="margin-top:20px">
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="check">
        <button class="btn" type="submit"><i class="fa-solid fa-rotate"></i> 检查更新</button>
      </form>
      <?php if ($remote && !$isLatest): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('确定更新到 ' + '<?= e($remote['short']) ?>' + ' 吗？\n将覆盖除 config.php / install.lock / uploads 外的所有站点文件。')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update">
        <button class="btn ghost" type="submit" style="color:var(--danger);border-color:rgba(255,107,107,.4)"><i class="fa-solid fa-arrow-down"></i> 立即更新</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <h2>更新日志 <span class="muted" style="font-size:12px">（GitHub Releases，最近 <?= $releases ? count($releases) : 0 ?> 条）</span></h2>
    <?php if ($releases): ?>
      <?php foreach ($releases as $r): ?>
        <?php $tagName = trim($r['name'] ?? $r['tag_name'] ?? ''); ?>
        <?php $body = trim((string)($r['body'] ?? '')); ?>
        <div style="border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:10px">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <b><a class="link" href="<?= e($r['html_url'] ?? '#') ?>" target="_blank" rel="noopener"><?= e($tagName !== '' ? $tagName : ($r['tag_name'] ?? 'v? ')) ?></a></b>
            <?php if (!empty($r['prerelease'])): ?><span class="pill" style="background:var(--bg2);color:var(--muted)">预发布</span><?php endif; ?>
            <span class="muted" style="font-size:12px"><?= e(date('Y-m-d', strtotime($r['published_at'] ?? 'now'))) ?></span>
          </div>
          <?php if ($body !== ''): ?>
            <div style="margin-top:6px;font-size:13px;color:var(--text);line-height:1.7;max-height:200px;overflow:auto"><?= mc_markdown($body) ?></div>
          <?php else: ?>
            <p class="muted" style="font-size:13px;margin-top:4px">（该版本无发布说明）</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="muted">暂无 Release 更新日志，可在 GitHub 提交历史中查看每次提交说明。</p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>说明</h2>
    <ul style="font-size:13px;color:var(--muted);line-height:1.9;padding-left:18px">
      <li>更新会从 GitHub 拉取最新代码并覆盖站点文件，<b>config.php、install.lock、uploads/ 上传目录</b>不会被覆盖。</li>
      <li>若本地相对 GitHub 有未推送的改动，请先提交推送，否则会被远端版本覆盖。</li>
      <li>更新前后建议备份站点与数据库。</li>
      <li>自动更新：开启后在「站点设置 → 更新与 API」配置触发词；当每日定时任务检测到新 tag 名包含触发词且版本更新时，将自动拉取更新。普通 tag（不含触发词）只会检测、不会自动更新。</li>
      <li>GitHub API 未认证时每小时约 60 次配额，检查频繁可能临时被限流，稍后重试即可。</li>
      <li>当前版本记录在站点根目录的 <b>VERSION</b> 文件（GitHub 最新提交的短哈希）。</li>
    </ul>
  </div>
</main>
</body>
</html>
