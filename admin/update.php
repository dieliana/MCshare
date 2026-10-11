<?php
/**
 * 后台 · 系统更新（GitHub 源）
 * 连接 GitHub 仓库获取最新版本，一键拉取 ZIP 解压覆盖站点文件。
 * 自动保护：config.php、install.lock、uploads/ 上传目录不会被覆盖。
 * 仓库：https://github.com/dieliana/MCshare
 */
require __DIR__ . '/_init.php'; // 由 _init.php 定义 BASE_PATH

$pageTitle = '系统更新';
$msg = '';
$repo  = 'dieliana/MCshare';
$branch = 'main';
$zipUrl = "https://codeload.github.com/{$repo}/zip/refs/heads/{$branch}";
$apiUrl = "https://api.github.com/repos/{$repo}/commits?per_page=1";
$verFile = __DIR__ . '/../VERSION';

$remote = null; // ['sha','short','msg','date','ver']
$local  = (file_exists($verFile) && ($_v = trim(file_get_contents($verFile)))) ? $_v : '';
$appVersion = '1.0.0';
function up_local_ver($local)
{
    if (preg_match('/^v?\d+(\.\d+)+/', $local, $m)) return $m[0];
    return 'v1.0.0';
}
$localVer = up_local_ver($local);

function up_api($url)
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'MCshare-Update/1.0',
    ]);
    $r = curl_exec($ch);
    return json_decode((string)$r, true);
}

function up_rrmdir($dir)
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

// ---------- 动作 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $msg = ['err', '会话已过期，请刷新后重试'];
    } elseif ($action === 'check') {
        $c = up_api($apiUrl);
        if (!is_array($c) || empty($c[0]['sha'])) {
            $msg = ['err', '检查更新失败：无法访问 GitHub API（可能被限流，稍后再试）'];
        } else {
            $tg = up_api('https://api.github.com/repos/' . $repo . '/tags');
            $remoteTag = (is_array($tg) && !empty($tg[0]['name'])) ? ltrim(trim($tg[0]['name']), 'v') : '';
            $remote = [
                'sha'  => $c[0]['sha'],
                'short'=> substr($c[0]['sha'], 0, 7),
                'ver'  => $remoteTag !== '' ? ('v' . $remoteTag) : ('latest · commit ' . substr($c[0]['sha'], 0, 7)),
                'tag'  => $remoteTag,
                'msg'  => trim(strip_tags($c[0]['commit']['message'] ?? '')),
                'date' => date('Y-m-d H:i', strtotime($c[0]['commit']['committer']['date'] ?? 'now')),
            ];
            $_SESSION['update_remote'] = $remote;
            $msg = ['ok', '已获取远程最新版本：' . $remote['short'] . '（' . $remote['date'] . '）'];
        }
    } elseif ($action === 'update') {
        $remote = $_SESSION['update_remote'] ?? [];
        if (!$remote) {
            $msg = ['err', '请先「检查更新」'];
        } else {
            $base = __DIR__ . '/..';
            $tmpZip = tempnam(sys_get_temp_dir(), 'upd') . '.zip';
            $tmpExt = sys_get_temp_dir() . '/mcshare_upd_' . substr(md5((string)mt_rand()), 0, 8);
            $ok = false; $copied = 0; $skipped = 0;
            try {
                // 下载 zip
                $fp = fopen($tmpZip, 'wb');
                $ch = curl_init($zipUrl);
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $fp,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT => 'MCshare-Update/1.0',
                ]);
                curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                fclose($fp);

                if ($code !== 200 || filesize($tmpZip) < 1000) {
                    throw new Exception('下载更新包失败（HTTP ' . $code . '）');
                }

                $zip = new ZipArchive();
                if ($zip->open($tmpZip) !== true) throw new Exception('无法打开下载的压缩包（请确认服务器已启用 zip 扩展）');

                $rootName = '';
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $n = $zip->getNameIndex($i);
                    if (strpos($n, '/') !== false) { $rootName = explode('/', $n)[0]; break; }
                }
                if ($rootName === '') { $zip->close(); throw new Exception('更新包结构异常'); }

                @mkdir($tmpExt, 0755, true);
                $zip->extractTo($tmpExt);
                $zip->close();

                $rootDir = $tmpExt . '/' . $rootName;
                if (!is_dir($rootDir)) throw new Exception('解压目录不存在');

                // 覆盖文件（保护 config.php / install.lock / uploads）
                $protectExact = ['config.php', 'install.lock'];
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if (!$file->isFile()) continue;
                    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($rootDir) + 1));
                    if (in_array($rel, $protectExact, true)) { $skipped++; continue; }
                    if (strpos($rel, 'uploads/') === 0) { $skipped++; continue; }
                    if (strpos($rel, '.git/') === 0) { $skipped++; continue; }
                    if ($rel === 'VERSION') continue;
                    $target = $base . '/' . $rel;
                    @mkdir(dirname($target), 0755, true);
                    if (copy($file->getPathname(), $target)) $copied++;
                }

                $tag = (isset($remote['tag']) && $remote['tag'] !== '') ? $remote['tag'] : $appVersion;
                @file_put_contents($verFile, 'v' . $tag . ' ' . $remote['sha']);
                $ok = true;
            } catch (Exception $ex) {
                $msg = ['err', '更新失败：' . $ex->getMessage()];
            }

            // 清理临时
            @unlink($tmpZip);
            if (is_dir($tmpExt)) up_rrmdir($tmpExt);

            if ($ok) {
                $msg = ['ok', '更新完成：已更新 ' . $copied . ' 个文件，跳过保护文件 ' . $skipped . ' 个。当前版本 ' . $remote['short']];
                unset($_SESSION['update_remote']);
                $local = 'v' . $tag . ' ' . $remote['sha'];
                $localVer = 'v' . $tag;
            }
        }
    }
}

// 页面展示用远程信息（session 恢复）
if (!$remote && !empty($_SESSION['update_remote'])) $remote = $_SESSION['update_remote'];
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
          <?php if ($local): ?><span class="muted" style="font-size:12px">(commit <?= e(substr($local, -7)) ?>)</span><?php endif; ?>
        </td></tr>
        <tr><th>远程最新版本</th><td>
          <?php if ($remote): ?>
            <span class="pill green"><?= e($remote['ver']) ?></span> · <?= e($remote['date']) ?><br>
            <span class="muted" style="font-size:13px"><?= e(mb_substr($remote['msg'], 0, 120)) ?></span>
          <?php else: ?>
            <span class="muted">尚未检查</span>
          <?php endif; ?>
        </td></tr>
      </tbody>
    </table>

    <div class="form-actions" style="margin-top:20px">
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="check">
        <button class="btn" type="submit"><i class="fa-solid fa-rotate"></i> 检查更新</button>
      </form>
      <?php if ($remote): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('确定更新到 ' + '<?= e($remote['short']) ?>' + ' 吗？\n将覆盖除 config.php / install.lock / uploads 外的所有站点文件。')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update">
        <button class="btn ghost" type="submit" style="color:var(--danger);border-color:rgba(255,107,107,.4)"><i class="fa-solid fa-arrow-down"></i> 立即更新</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <h2>说明</h2>
    <ul style="font-size:13px;color:var(--muted);line-height:1.9;padding-left:18px">
      <li>更新会从 GitHub 拉取最新代码并覆盖站点文件，<b>config.php、install.lock、uploads/ 上传目录</b>不会被覆盖。</li>
      <li>若本地相对 GitHub 有未推送的改动，请先提交推送，否则会被远端版本覆盖。</li>
      <li>更新前后建议备份站点与数据库。</li>
      <li>GitHub API 未认证时每小时约 60 次配额，检查频繁可能临时被限流，稍后重试即可。</li>
      <li>当前版本记录在站点根目录的 <b>VERSION</b> 文件（GitHub 最新提交的短哈希）。</li>
    </ul>
  </div>
</main>
