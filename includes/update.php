<?php
/**
 * ============================================================
 *  GitHub 更新公共模块（后台系统更新 / cron 自动更新共用）
 * ------------------------------------------------------------
 *  mc_remote_latest(): 查询 GitHub 最新提交与 tag
 *  mc_do_update():     下载仓库 ZIP 解压覆盖站点文件
 *                      自动保护：config.php、install.lock、uploads/ 不被覆盖
 *  mc_up_api / mc_up_rrmdir(): 内部辅助
 * ============================================================
 */

if (!function_exists('mc_up_api')) {
    function mc_up_api($url, $token = '')
    {
        if (!function_exists('curl_init')) return null;
        $headers = ['User-Agent: MCshare-Update/1.0'];
        if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $r = curl_exec($ch);
        return json_decode((string)$r, true);
    }
}

if (!function_exists('mc_up_rrmdir')) {
    function mc_up_rrmdir($dir)
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
}

/** 查询远程最新版本信息 */
if (!function_exists('mc_remote_latest')) {
    function mc_remote_latest($repo = 'dieliana/MCshare', $token = '')
    {
        $apiBase = "https://api.github.com/repos/{$repo}";
        $c = mc_up_api($apiBase . '/commits?per_page=1', $token);
        if (!is_array($c) || empty($c[0]['sha'])) return null;
        $tg = mc_up_api($apiBase . '/tags', $token);
        $tag = (is_array($tg) && !empty($tg[0]['name'])) ? ltrim(trim($tg[0]['name']), 'v') : '';
        return [
            'sha'   => $c[0]['sha'],
            'short' => substr($c[0]['sha'], 0, 7),
            'tag'   => $tag,
            'ver'   => $tag !== '' ? ('v' . $tag) : ('latest · commit ' . substr($c[0]['sha'], 0, 7)),
            'msg'   => trim(strip_tags($c[0]['commit']['message'] ?? '')),
            'date'  => date('Y-m-d H:i', strtotime($c[0]['commit']['committer']['date'] ?? 'now')),
        ];
    }
}

/** 执行更新：拉取仓库 ZIP 解压覆盖（保护 config/install.lock/uploads/VERSION） */
if (!function_exists('mc_do_update')) {
    function mc_do_update($tag = '', $sha = '', $repo = 'dieliana/MCshare', $branch = 'main')
    {
        $base    = dirname(__DIR__); // includes/ 的上级 = 项目根
        $verFile = $base . '/VERSION';
        $zipUrl  = "https://codeload.github.com/{$repo}/zip/refs/heads/{$branch}";
        $tmpZip  = tempnam(sys_get_temp_dir(), 'upd') . '.zip';
        $tmpExt  = sys_get_temp_dir() . '/mcshare_upd_' . substr(md5((string)mt_rand()), 0, 8);
        $copied = 0; $skipped = 0; $ok = false; $err = '';

        try {
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

            // 覆盖文件（保护 config.php / install.lock / uploads / VERSION）
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

            @file_put_contents($verFile, 'v' . ($tag !== '' ? $tag : '1.0.0') . ($sha !== '' ? ' ' . $sha : ''));
            $ok = true;
        } catch (Exception $ex) {
            $err = $ex->getMessage();
        }

        @unlink($tmpZip);
        if (is_dir($tmpExt)) mc_up_rrmdir($tmpExt);
        return ['ok' => $ok, 'copied' => $copied, 'skipped' => $skipped, 'msg' => $err];
    }
}
