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
    $snap_file = "{$fz_snaps}/{$slug}.html";
    if (file_exists($snap_file)) {
        echo "snapshot {$slug} <= {$path}  SKIP (already exists)\n";
        continue;
    }
    [$code, $body] = fz_http_get(FZ_SOURCE . $path);
    if ($body === '' || stripos($body, '</html>') === false) {
        fwrite(STDERR, "SNAPSHOT FAIL {$path} (code {$code}, ".strlen($body)." bytes)\n");
        continue;
    }
    file_put_contents($snap_file, $body);
    echo "snapshot {$slug} <= {$path}  {$code}  ".strlen($body)." bytes\n";
    usleep(1200000); // 1.2s giữa các page để né WAF
}
echo "=== snapshots done: ".count(glob("{$fz_snaps}/*.html"))." files ===\n";

// ===== 2) Đóng băng assets =====
const FZ_FROZEN_BASE = '/wp-content/themes/vitech-clone/frozen'; // khớp vitech_clone_frozen_base()

// src URL nguồn -> path /wp-content|/wp-includes (bỏ query); null nếu không phải asset nguồn.
function fz_src_path(string $url): ?string {
    if (str_starts_with($url, '//vitechlift.com/')) $url = 'https:' . $url;
    $p = wp_parse_url($url);
    if (!is_array($p) || !isset($p['host']) || stripos($p['host'], 'vitechlift.com') === false) return null;
    $path = $p['path'] ?? '';
    return preg_match('#^/(wp-content|wp-includes)/#', $path) ? $path : null;
}

// Copy từ asset-proxy.php: PNG ngả xanh -> grayscale + sáng nhẹ; ngược lại null.
function fz_greyscale_if_green(string $data): ?string {
    if (!function_exists('imagecreatefromstring')) return null;
    $img = @imagecreatefromstring($data);
    if (!$img) return null;
    if (function_exists('imageistruecolor') && !imageistruecolor($img)) imagepalettetotruecolor($img);
    $w = imagesx($img); $h = imagesy($img);
    $sx = max(1, intdiv($w, 24)); $sy = max(1, intdiv($h, 24));
    $sr = $sg = $sb = $n = 0;
    for ($y = 0; $y < $h; $y += $sy) for ($x = 0; $x < $w; $x += $sx) {
        $c = imagecolorat($img, $x, $y);
        if ((($c >> 24) & 0x7F) > 100) continue;
        $sr += ($c >> 16) & 0xFF; $sg += ($c >> 8) & 0xFF; $sb += $c & 0xFF; $n++;
    }
    if ($n === 0) { imagedestroy($img); return null; }
    $ar = $sr / $n; $ag = $sg / $n; $ab = $sb / $n;
    if (!($ag > $ar + 12 && $ag > $ab + 12)) { imagedestroy($img); return null; }
    imagefilter($img, IMG_FILTER_GRAYSCALE);
    imagefilter($img, IMG_FILTER_BRIGHTNESS, 35);
    imagealphablending($img, false); imagesavealpha($img, true);
    ob_start(); imagepng($img); $out = (string) ob_get_clean(); imagedestroy($img);
    return $out !== '' ? $out : null;
}

// Resolve url() tương đối trong CSS -> URL nguồn tuyệt đối (copy asset-proxy.php).
function fz_resolve_css_url(string $url, string $base_path): string {
    if (preg_match('#^(data:|https?:|//|/wp-content/|/wp-includes/)#i', $url)) {
        if (str_starts_with($url, '//vitechlift.com/')) return 'https:' . $url;
        if (str_starts_with($url, '/wp-content/') || str_starts_with($url, '/wp-includes/')) return FZ_SOURCE . $url;
        return $url;
    }
    $path = trailingslashit(dirname($base_path)) . $url;
    $seg = [];
    foreach (explode('/', $path) as $s) {
        if ($s === '' || $s === '.') continue;
        if ($s === '..') { array_pop($seg); continue; }
        $seg[] = $s;
    }
    return FZ_SOURCE . '/' . implode('/', $seg);
}

// 2a) Gom tập asset thực sự được render (crawl trang local, còn ở chế độ proxy).
$fz_local_urls = ['/', '/tin-tuc/', '/?s=thang+may'];
$one = fn($t) => ($x = get_posts(['post_type' => $t, 'post_status' => 'publish', 'numberposts' => 1])) ? $x[0] : null;
if ($pr = $one('product')) $fz_local_urls[] = wp_make_link_relative(get_permalink($pr));
if ($po = $one('post'))    $fz_local_urls[] = wp_make_link_relative(get_permalink($po));
$cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1]);
if ($cats && !is_wp_error($cats)) $fz_local_urls[] = wp_make_link_relative(get_term_link($cats[0]));
foreach (get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1]) as $p) {
    $fz_local_urls[] = '/' . $p->post_name . '/';
}

$queue = []; // set path => true
foreach (array_unique($fz_local_urls) as $u) {
    [$code, $html] = fz_http_get(FZ_LOCAL . $u);
    if ($html === '') { fwrite(STDERR, "CRAWL FAIL {$u} ({$code})\n"); continue; }
    preg_match_all('#[?&]vitech_asset=([^"\'\s>()]+)#', $html, $m);
    foreach ($m[1] as $enc) {
        $dec = str_replace('\\/', '/', rawurldecode($enc));
        $sp = fz_src_path($dec);
        if ($sp !== null) $queue[$sp] = true;
    }
    echo "crawl {$u}: +".count($m[1])." refs (queue ".count($queue).")\n";
    usleep(800000);
}

// 2a-bis) Gom asset tham chiếu trực tiếp trong các snapshot shell (nguồn sự thật cho render.php).
// Snapshot HTML là bản chụp thô từ vitechlift.com nên chứa src/href/srcset/url() trỏ thẳng nguồn,
// khác với trang local (crawl ở trên) vốn đã được vitech_asset= hoá.
function fz_extract_snapshot_refs(string $html): array {
    $html = str_replace('\\/', '/', $html); // gỡ escape JSON (vd trong <script type="application/ld+json">)
    $refs = [];

    // Absolute / protocol-relative refs to the source host.
    if (preg_match_all('#(?:https?:)?//vitechlift\.com/(?:wp-content|wp-includes)/[^"\'\s<>)]+#i', $html, $m)) {
        foreach ($m[0] as $u) $refs[] = $u;
    }

    // Root-relative refs: /wp-content/... hoặc /wp-includes/... bên trong src=, href=, srcset=, url(...)
    if (preg_match_all('#(?:src|href|srcset)=["\']([^"\']*(?:/wp-content/|/wp-includes/)[^"\']*)["\']#i', $html, $m)) {
        foreach ($m[1] as $attr) {
            // srcset có thể chứa nhiều "URL 300w" phân cách bởi dấu phẩy.
            foreach (preg_split('#\s*,\s*#', $attr) as $entry) {
                $entry = trim($entry);
                if ($entry === '') continue;
                $parts = preg_split('#\s+#', $entry);
                $u = $parts[0] ?? '';
                if ($u !== '' && preg_match('#(?:https?:)?//vitechlift\.com/(?:wp-content|wp-includes)/|^/(?:wp-content|wp-includes)/#i', $u)) {
                    $refs[] = $u;
                }
            }
        }
    }

    // url(...) trong <style>/inline CSS bên trong shell.
    if (preg_match_all('#url\((["\']?)((?:https?:)?//vitechlift\.com/(?:wp-content|wp-includes)/[^)\'"]+|/(?:wp-content|wp-includes)/[^)\'"]+)\1\)#i', $html, $m)) {
        foreach ($m[2] as $u) $refs[] = $u;
    }

    // Chuẩn hoá: bỏ query string + dấu phẩy/khoảng trắng thừa còn sót.
    $out = [];
    foreach ($refs as $u) {
        $u = trim($u, " \t\n\r,");
        if ($u === '') continue;
        $u = preg_replace('~[?#].*$~', '', $u);
        if ($u !== '') $out[] = $u;
    }
    return $out;
}

foreach (glob("{$fz_snaps}/*.html") as $snap_path) {
    $html = (string) file_get_contents($snap_path);
    if ($html === '') continue;
    $refs = fz_extract_snapshot_refs($html);
    $added = 0;
    foreach ($refs as $ref) {
        $abs = str_starts_with($ref, '/') ? FZ_SOURCE . $ref : $ref;
        $sp = fz_src_path($abs);
        if ($sp !== null && !isset($queue[$sp])) { $queue[$sp] = true; $added++; }
    }
    echo "shell-scan ".basename($snap_path).": +{$added} refs (queue ".count($queue).")\n";
}

// 2b) BFS: tải + xử lý từng asset; CSS đẩy thêm url() con vào queue.
$done = [];
while ($queue) {
    $path = array_key_first($queue);
    unset($queue[$path]);
    if (isset($done[$path])) continue;
    $done[$path] = true;

    [$code, $body] = fz_http_get(FZ_SOURCE . $path);
    if ($body === '' || $code >= 400) { fwrite(STDERR, "ASSET FAIL {$path} ({$code})\n"); continue; }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($ext === 'css') {
        $body = str_ireplace(['#159158', '#0B9344'], ['#6b7280', '#6b7280'], $body);
        $rewrite = function (array $m) use ($path, &$queue, $done) {
            $u = trim($m[2]);
            if ($u === '' || str_starts_with($u, 'data:')) return $m[0];
            $abs = fz_resolve_css_url($u, $path);
            $sp = fz_src_path($abs);
            if ($sp === null) return $m[0]; // external: giữ nguyên
            if (!isset($done[$sp])) $queue[$sp] = true;
            return 'url(' . ($m[1] ?? '') . FZ_FROZEN_BASE . $sp . ($m[3] ?? '') . ')';
        };
        $body = preg_replace_callback('#url\((["\']?)([^)\'"]+)(["\']?)\)#', $rewrite, $body);
        // @import "x"; dạng không dùng url()
        $body = preg_replace_callback('#@import\s+(["\'])([^"\']+)\1#', function (array $m) use ($path, &$queue, $done) {
            $abs = fz_resolve_css_url(trim($m[2]), $path);
            $sp = fz_src_path($abs);
            if ($sp === null) return $m[0];
            if (!isset($done[$sp])) $queue[$sp] = true;
            return '@import "' . FZ_FROZEN_BASE . $sp . '"';
        }, $body);
    } elseif ($ext === 'png') {
        $re = fz_greyscale_if_green($body);
        if (is_string($re) && $re !== '') $body = $re;
    }

    $target = $fz_frozen . $path;
    wp_mkdir_p(dirname($target));
    file_put_contents($target, $body);
    usleep(250000); // né WAF cho asset
}
echo "=== frozen assets done: ".iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fz_frozen, FilesystemIterator::SKIP_DOTS)))." files ===\n";
