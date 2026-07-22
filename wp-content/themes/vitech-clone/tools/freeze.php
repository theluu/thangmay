<?php
// Build tool: chụp snapshot HTML + đóng băng assets. Chạy: ddev wp eval-file <path>
// ĐÂY LÀ NƠI DUY NHẤT CÒN GỌI MẠNG. Không require từ đường request.
if (!defined('ABSPATH')) { exit; }

const FZ_SOURCE   = 'https://vitechlift.com';
const FZ_LOCAL    = 'https://thang-may.ddev.site';
const FZ_UA       = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
$fz_theme  = get_template_directory();
$fz_snaps  = $fz_theme . '/snapshots';
$fz_frozen = $fz_theme . '/frozen';

function fz_slug(string $request_path, bool $is_search): string {
    if ($is_search) return 'search';
    $slug = trim($request_path, '/');
    if ($slug === '') return 'home';
    $slug = preg_replace('#[^a-z0-9/_-]#i', '-', $slug);
    return str_replace('/', '-', strtolower($slug));
}

// GET với UA trình duyệt + retry/backoff (nguồn có WAF chặn request dồn dập).
function fz_http_get(string $url, int $tries = 4): array {
    $code = 0; $body = '';
    for ($i = 0; $i < $tries; $i++) {
        $r = wp_remote_get($url, [
            'timeout' => 30, 'redirection' => 3,
            'sslverify' => !str_starts_with($url, FZ_LOCAL),
            'user-agent' => FZ_UA,
            'headers' => ['Accept' => '*/*'],
        ]);
        if (!is_wp_error($r)) {
            $code = (int) wp_remote_retrieve_response_code($r);
            $body = (string) wp_remote_retrieve_body($r);
            if ($code !== 403 && $code !== 429 && $code < 500) {
                return [$code, $body];
            }
        }
        usleep(500000 * ($i + 1)); // backoff 0.5s,1s,1.5s
    }
    return [$code, $body];
}

// ===== 1) Snapshot HTML =====
wp_mkdir_p($fz_snaps);

// Template mẫu cố định: [source path, is_search]
$targets = [
    ['/', false],
    ['/may-keo-fj100a/', false],
    ['/may-keo-thang-may/', false],
    ['/may-keo-fjt-new-version/', false],
    ['/tin-tuc-su-kien/', false],
    ['/?s=thang+may', true],
];
// Mọi page tĩnh publish (trừ tin-tuc đã có template news).
foreach (get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1]) as $p) {
    if ($p->post_name === 'tin-tuc') continue;
    $targets[] = ['/' . $p->post_name . '/', false];
}

foreach ($targets as [$path, $is_search]) {
    $slug = fz_slug($is_search ? '/' : $path, $is_search);
    [$code, $body] = fz_http_get(FZ_SOURCE . $path);
    if ($body === '' || stripos($body, '</html>') === false) {
        fwrite(STDERR, "SNAPSHOT FAIL {$path} (code {$code}, ".strlen($body)." bytes)\n");
        continue;
    }
    file_put_contents("{$fz_snaps}/{$slug}.html", $body);
    echo "snapshot {$slug} <= {$path}  {$code}  ".strlen($body)." bytes\n";
    usleep(1200000); // 1.2s giữa các page để né WAF
}
echo "=== snapshots done: ".count(glob("{$fz_snaps}/*.html"))." files ===\n";
