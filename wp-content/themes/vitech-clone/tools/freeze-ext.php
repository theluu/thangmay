<?php
// Build tool phụ: đóng băng các asset load từ host NGOÀI vitechlift.com
// (mirror webrt + CDN polyfill/fontawesome) vào frozen/_ext/<host><path>.
// Chạy: ddev wp eval-file wp-content/themes/vitech-clone/tools/freeze-ext.php
// Là nơi gọi mạng, chỉ chạy khi cần tái tạo. Khớp danh sách host trong render.php.
if (!defined('ABSPATH')) { exit; }

const FZX_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

$theme   = get_template_directory();
$extbase = $theme . '/frozen/_ext';

$fzx_get = static function (string $url): array {
    $r = wp_remote_get($url, ['timeout' => 40, 'redirection' => 3, 'user-agent' => FZX_UA]);
    if (is_wp_error($r)) { return [0, '']; }
    return [(int) wp_remote_retrieve_response_code($r), (string) wp_remote_retrieve_body($r)];
};

// Lưu bytes vào frozen/_ext/<host><path> (bỏ query); trả URL local root-relative.
$fzx_save = static function (string $host, string $path, string $body) use ($extbase): string {
    $path = preg_replace('~[?#].*$~', '', $path);
    $target = $extbase . '/' . $host . $path;
    if (!is_dir(dirname($target))) { mkdir(dirname($target), 0755, true); }
    file_put_contents($target, $body);
    return '/wp-content/themes/vitech-clone/frozen/_ext/' . $host . $path;
};

// Asset đơn (ảnh, js) — [host, path]
$simple = [
    ['luan.webrt.net',   '/vitechlift/wp-content/uploads/2023/08/Group-630490.png'],
    ['son.webrt.vn',     '/umiphar/wp-content/uploads/2019/03/duongdan.png'],
    ['cdn.jsdelivr.net', '/gh/nuxodin/ie11CustomProperties@4.0.1/ie11CustomProperties.min.js'],
    ['cdn.jsdelivr.net', '/npm/intersection-observer-polyfill@0.1.0/dist/IntersectionObserver.js'],
];
foreach ($simple as [$host, $path]) {
    [$code, $body] = $fzx_get('https://' . $host . $path);
    if ($code === 200 && $body !== '') {
        echo 'OK ' . $fzx_save($host, $path, $body) . ' (' . strlen($body) . ")\n";
    } else {
        fwrite(STDERR, "FAIL {$host}{$path} http {$code}\n");
    }
}

// FontAwesome CSS + webfonts nó tham chiếu (url(../webfonts/..)).
$fahost = 'use.fontawesome.com';
$fapath = '/releases/v5.7.2/css/all.css';
[$code, $css] = $fzx_get('https://' . $fahost . $fapath);
if ($code === 200 && $css !== '') {
    $dir = dirname($fapath); // /releases/v5.7.2/css
    $css = preg_replace_callback('#url\((["\']?)([^)\'"]+)(["\']?)\)#', static function (array $m) use ($fzx_get, $fzx_save, $fahost, $dir): string {
        $u = trim($m[2]);
        if ($u === '' || str_starts_with($u, 'data:')) { return $m[0]; }
        if (preg_match('#^https?://#', $u)) {
            $absurl = $u;
            $rel = (string) parse_url($u, PHP_URL_PATH);
        } else {
            $path = $dir . '/' . preg_replace('~[?#].*$~', '', $u);
            $seg = [];
            foreach (explode('/', $path) as $s) {
                if ($s === '' || $s === '.') { continue; }
                if ($s === '..') { array_pop($seg); continue; }
                $seg[] = $s;
            }
            $rel = '/' . implode('/', $seg);
            $absurl = 'https://' . $fahost . $rel;
        }
        [$fc, $fb] = $fzx_get($absurl);
        if ($fc === 200 && $fb !== '') {
            return 'url(' . $m[1] . $fzx_save($fahost, $rel, $fb) . $m[3] . ')';
        }
        return $m[0];
    }, $css);
    echo 'OK ' . $fzx_save($fahost, $fapath, $css) . ' (css ' . strlen($css) . ")\n";
} else {
    fwrite(STDERR, "FAIL fontawesome css http {$code}\n");
}

echo "=== ext freeze done: " . iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extbase, FilesystemIterator::SKIP_DOTS))) . " files ===\n";
