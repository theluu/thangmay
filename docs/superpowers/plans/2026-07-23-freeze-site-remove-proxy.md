# Đóng băng site & bỏ proxy — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gỡ cơ chế live-proxy (fetch HTML + assets từ vitechlift.com) khỏi theme `vitech-clone`, thay bằng snapshot HTML + assets đóng băng trên đĩa, giữ y nguyên giao diện và nội dung động.

**Architecture:** Một build tool `tools/freeze.php` (chạy `ddev wp eval-file`) chụp HTML thô của ~13 template mẫu vào `snapshots/` và tải toàn bộ assets đã xử lý (recolor/grayscale/rewrite CSS) vào `frozen/`. File render (đổi tên `proxy.php`→`render.php`) đọc snapshot từ đĩa thay vì gọi mạng, và trỏ asset về `frozen/`. Toàn bộ injector nội dung động + recolor + rebrand giữ nguyên. `asset-proxy.php` bị xóa. `freeze.php` là nơi **duy nhất** còn code gọi mạng.

**Tech Stack:** WordPress (PHP 8.4), WooCommerce, DDEV, WP-CLI. Không có PHPUnit — "test" ở đây là lệnh `ddev wp eval` / `curl` / `grep` với output kỳ vọng cụ thể.

## Global Constraints

- Runtime (đường request) **tuyệt đối không** gọi `wp_remote_get` / mạng ra `vitechlift.com`. Chỉ `tools/freeze.php` được phép.
- Route thiếu snapshot → `status_header(500)` + trang lỗi tĩnh, **không** fallback mạng.
- Fetch từ nguồn phải dùng UA trình duyệt thật `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36`, có delay + retry (nguồn có WAF chặn request dồn dập, trả 403 body 1242 bytes).
- Màu recolor: `#159158` và `#0B9344` → `#6b7280`.
- Frozen URL base phải **root-relative** (không nhúng domain) để chạy được cả trên ddev lẫn dev server: `wp_make_link_relative(get_template_directory_uri()) . '/frozen'` = `/wp-content/themes/vitech-clone/frozen`.
- Frozen path = **path của asset nguồn, bỏ query string** (vd `/wp-content/uploads/x.png`), mirror y hệt dưới `frozen/`.
- Assets external không thuộc vitechlift (Google reCAPTCHA, fonts.googleapis...) giữ nguyên, không đóng băng.
- Commit cả `snapshots/**` và `frozen/**` (chấp nhận repo phình vài MB).

**Thứ tự thực thi bắt buộc:** Task 1 → 2 (chụp khi site còn ở chế độ proxy) → 3 (cutover) → 4 (xóa asset-proxy + nghiệm thu). Task 2 crawl trang render local nên phải chạy **trước** cutover.

**Snapshot slug (dùng chung render.php và freeze.php):**

| slug | source path | dùng cho |
|------|-------------|----------|
| `home` | `/` | trang chủ |
| `may-keo-fj100a` | `/may-keo-fj100a/` | mọi single product |
| `may-keo-thang-may` | `/may-keo-thang-may/` | mọi product_cat |
| `may-keo-fjt-new-version` | `/may-keo-fjt-new-version/` | mọi single post |
| `tin-tuc-su-kien` | `/tin-tuc-su-kien/` | page `tin-tuc` |
| `search` | `/?s=thang+may` | mọi tìm kiếm |
| `<page-slug>` | `/<page-slug>/` | từng page tĩnh (gioi-thieu, lien-he, tai-lieu, san-pham, yeu-cau-bao-gia, gio-hang, cart, checkout, shop, my-account, trang-mau) |

Ghi chú: `/gio-hang/`, `/cart/`... nguồn trả trang 404-có-chrome (~78KB, đủ header/footer) — đúng bằng cái proxy đang cache hôm nay, giữ nguyên hành vi.

---

### Task 1: `tools/freeze.php` — chụp snapshot HTML

**Files:**
- Create: `wp-content/themes/vitech-clone/tools/freeze.php`

**Interfaces:**
- Produces: thư mục `wp-content/themes/vitech-clone/snapshots/<slug>.html` (HTML nguồn thô). Hàm `fz_http_get(string $url, int $tries=4): array` trả `[int $code, string $body]` — Task 2 dùng lại. Hàm `fz_slug(string $request_path, bool $is_search): string` — phải khớp hệt `vitech_clone_snapshot_slug()` ở Task 3.

- [ ] **Step 1: Viết freeze.php (phần snapshot)**

Create `wp-content/themes/vitech-clone/tools/freeze.php`:

```php
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
```

- [ ] **Step 2: Chạy để chụp snapshot**

Run: `ddev wp eval-file wp-content/themes/vitech-clone/tools/freeze.php`
Expected: in ra `snapshot home <= /  200  ~253000 bytes`, tương tự cho từng slug; dòng cuối `=== snapshots done: 17 files ===` (6 template + 11 page). Không có dòng `SNAPSHOT FAIL`. (Nếu có FAIL do WAF, chạy lại — retry/backoff thường qua ở lần 2.)

- [ ] **Step 3: Kiểm tra snapshot hợp lệ**

Run:
```bash
ls -la wp-content/themes/vitech-clone/snapshots/ && \
for f in home may-keo-fj100a may-keo-thang-may search gio-hang; do \
  printf "%-24s %s bytes, </html>=%s\n" "$f" \
  "$(wc -c < wp-content/themes/vitech-clone/snapshots/$f.html)" \
  "$(grep -c '</html>' wp-content/themes/vitech-clone/snapshots/$f.html)"; done
```
Expected: mỗi file > 50000 bytes và `</html>=1` (hoặc >0). `home.html` ~253KB.

- [ ] **Step 4: Commit**

```bash
git add wp-content/themes/vitech-clone/tools/freeze.php wp-content/themes/vitech-clone/snapshots/
git commit -m "Add freeze tool + HTML snapshots"
```

---

### Task 2: `tools/freeze.php` — đóng băng assets

**Files:**
- Modify: `wp-content/themes/vitech-clone/tools/freeze.php` (thêm phần asset vào cuối)

**Interfaces:**
- Consumes: `fz_http_get()`, hằng `FZ_SOURCE/FZ_LOCAL/FZ_UA`, `$fz_frozen` từ Task 1.
- Produces: `wp-content/themes/vitech-clone/frozen/wp-content/...` và `.../wp-includes/...` (assets đã xử lý). CSS bên trong trỏ `url()` về `/wp-content/themes/vitech-clone/frozen/...`.

- [ ] **Step 1: Thêm phần đóng băng assets vào cuối freeze.php**

Append vào `wp-content/themes/vitech-clone/tools/freeze.php`:

```php
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
        $sp = fz_src_path(rawurldecode($enc));
        if ($sp !== null) $queue[$sp] = true;
    }
    echo "crawl {$u}: +".count($m[1])." refs (queue ".count($queue).")\n";
    usleep(800000);
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
```

- [ ] **Step 2: Chạy đóng băng assets**

Run: `ddev wp eval-file wp-content/themes/vitech-clone/tools/freeze.php`
Expected: các dòng `crawl /...: +N refs`, rồi dòng cuối `=== frozen assets done: NNN files ===` với NNN vài trăm. Ít/không có dòng `ASSET FAIL` (vài fail lẻ do WAF chấp nhận được — kiểm ở Step 3).

- [ ] **Step 3: Kiểm CSS đã sạch nguồn + file tồn tại**

Run:
```bash
echo "CSS còn ref nguồn (phải =0):"; \
grep -rlE "vitechlift\.com|\?vitech_asset=" wp-content/themes/vitech-clone/frozen --include=*.css | wc -l; \
echo "Số file frozen:"; find wp-content/themes/vitech-clone/frozen -type f | wc -l; \
echo "Có CSS & PNG:"; find wp-content/themes/vitech-clone/frozen -name '*.css' | head -1; \
find wp-content/themes/vitech-clone/frozen -name '*.png' | head -1
```
Expected: dòng đầu `0` (không CSS nào còn trỏ nguồn hay `?vitech_asset=`); số file frozen vài trăm; có ít nhất 1 `.css` và 1 `.png`.

- [ ] **Step 4: Commit**

```bash
git add wp-content/themes/vitech-clone/tools/freeze.php wp-content/themes/vitech-clone/frozen/
git commit -m "Freeze all site assets (CSS/JS/images/fonts) to theme"
```

---

### Task 3: Cutover — `proxy.php` → `render.php` đọc snapshot + trỏ frozen

**Files:**
- Modify: `wp-content/themes/vitech-clone/functions.php` (thêm 2 helper; require `render.php`; bỏ nhánh `vitech_asset`)
- Rename+Modify: `wp-content/themes/vitech-clone/proxy.php` → `render.php` (2 khối: bỏ fetch, đổi `$asset_url`)
- Modify: `wp-content/themes/vitech-clone/front-page.php`, `page.php`, `single.php`, `404.php` (require `render.php`)

**Interfaces:**
- Consumes: `snapshots/<slug>.html` (Task 1), `frozen/**` (Task 2).
- Produces: `vitech_clone_frozen_base(): string` = `/wp-content/themes/vitech-clone/frozen`; `vitech_clone_snapshot_slug(string $request_path, bool $is_search): string` (khớp `fz_slug`).

- [ ] **Step 1: Thêm 2 helper vào functions.php**

Chèn ngay trước `function vitech_clone_proxy_public_pages()` (khoảng dòng 118) trong `functions.php`:

```php
function vitech_clone_frozen_base(): string
{
    return wp_make_link_relative(get_template_directory_uri()) . '/frozen';
}

// Khớp hệt fz_slug() trong tools/freeze.php.
function vitech_clone_snapshot_slug(string $request_path, bool $is_search): string
{
    if ($is_search) return 'search';
    $slug = trim($request_path, '/');
    if ($slug === '') return 'home';
    $slug = preg_replace('#[^a-z0-9/_-]#i', '-', $slug);
    return str_replace('/', '-', strtolower($slug));
}
```

- [ ] **Step 2: Đổi tên file + trỏ require sang render.php**

Run:
```bash
cd wp-content/themes/vitech-clone && git mv proxy.php render.php && cd -
```
Rồi sửa require trong 5 file — mỗi file đổi `proxy.php` → `render.php`:

`functions.php:142` (trong `vitech_clone_proxy_public_pages`):
```php
    require get_template_directory() . '/render.php';
```
`front-page.php`, `page.php`, `single.php`, `404.php` (mỗi file 1 dòng):
```php
require get_template_directory() . '/render.php';
```

- [ ] **Step 3: Bỏ nhánh asset-proxy trong functions.php**

Trong `vitech_clone_proxy_public_pages()`, **xóa** khối (khoảng dòng 124-127):
```php
    if (isset($_GET['vitech_asset'])) {
        require get_template_directory() . '/asset-proxy.php';
        exit;
    }
```

- [ ] **Step 4: render.php — thay khối fetch bằng đọc snapshot**

Trong `render.php`, thay **toàn bộ** khối dòng ~63-96 (từ `$source_url = $source_host . $request_path;` tới hết `set_transient(...)` / `}` đóng `if (!is_string($html))`) bằng:

```php
$snapshot_slug = vitech_clone_snapshot_slug($request_path, $search_query !== '');
$snapshot_file = get_template_directory() . '/snapshots/' . $snapshot_slug . '.html';
$html = is_readable($snapshot_file) ? (string) file_get_contents($snapshot_file) : '';

if ($html === '') {
    status_header(500);
    echo '<!doctype html><meta charset="utf-8"><title>Thiếu snapshot</title>'
        . '<p>Chưa có bản snapshot cho trang này. Chạy lại <code>tools/freeze.php</code>.</p>';
    exit;
}
```

- [ ] **Step 5: render.php — đổi closure `$asset_url` sang frozen**

Thay **toàn bộ** closure `$asset_url` (dòng ~16-26) bằng:

```php
$frozen_base = vitech_clone_frozen_base();
$asset_url = static function (string $url) use ($source_host, $frozen_base): string {
    if (str_starts_with($url, '//vitechlift.com/')) {
        $url = 'https:' . $url;
    }
    if (str_starts_with($url, '/wp-content/') || str_starts_with($url, '/wp-includes/')) {
        $url = $source_host . $url;
    }
    // Chỉ đóng băng asset của nguồn; URL khác giữ nguyên.
    if (!str_starts_with($url, $source_host . '/')) {
        return $url;
    }
    return $frozen_base . (string) parse_url($url, PHP_URL_PATH);
};
```

- [ ] **Step 6: Bust cache + verify trang render từ snapshot/frozen**

Run:
```bash
ddev wp transient delete --all >/dev/null 2>&1; \
for u in / /gioi-thieu/ /gio-hang/ "/?s=thang+may"; do \
  b=$(curl -sk "https://thang-may.ddev.site$u"); \
  printf "%-16s bytes=%-7s frozen=%s vitech_asset=%s vitechlift=%s\n" "$u" \
   "$(printf '%s' "$b" | wc -c)" \
   "$(printf '%s' "$b" | grep -c '/frozen/')" \
   "$(printf '%s' "$b" | grep -c 'vitech_asset=')" \
   "$(printf '%s' "$b" | grep -oc 'https://vitechlift.com')"; done
```
Expected: mỗi URL `bytes` > 50000, `frozen` > 0, `vitech_asset=` = 0, `vitechlift=` (link tuyệt đối tới nguồn) = 0. Nếu `frozen=0` hoặc còn `vitech_asset` → sai Step 4/5.

- [ ] **Step 7: Verify một asset frozen tải được (200)**

Run:
```bash
css=$(curl -sk "https://thang-may.ddev.site/" | grep -oE '/wp-content/themes/vitech-clone/frozen/[^"'"'"']+\.css' | head -1); \
echo "CSS: $css"; curl -sko /dev/null -w "HTTP %{http_code}  %{content_type}\n" "https://thang-may.ddev.site$css"
```
Expected: in ra 1 đường dẫn `.css` dưới `/frozen/` và `HTTP 200  text/css...`.

- [ ] **Step 8: Verify runtime không còn code gọi mạng**

Run:
```bash
grep -nE "wp_remote_get|vitechlift\.com" wp-content/themes/vitech-clone/render.php wp-content/themes/vitech-clone/functions.php
```
Expected: `render.php` **không** còn `wp_remote_get`. Còn lại chỉ các hằng `$source_host`/chuỗi rebrand (không phải lời gọi mạng). Không có dòng `wp_remote_get` nào.

- [ ] **Step 9: Commit**

```bash
git add -A wp-content/themes/vitech-clone/
git commit -m "Cut over to snapshots: rename proxy.php->render.php, serve frozen assets"
```

---

### Task 4: Xóa `asset-proxy.php` + nghiệm thu cuối

**Files:**
- Delete: `wp-content/themes/vitech-clone/asset-proxy.php`

**Interfaces:**
- Consumes: kết quả Task 3 (site chạy hoàn toàn từ snapshot/frozen).

- [ ] **Step 1: Xác nhận không còn tham chiếu asset-proxy.php**

Run: `grep -rn "asset-proxy\|vitech_asset" wp-content/themes/vitech-clone --include=*.php`
Expected: **không** dòng nào ngoài `tools/freeze.php` (freeze dùng `vitech_asset` khi crawl). Đặc biệt `functions.php`/`render.php` không còn nhắc `asset-proxy`.

- [ ] **Step 2: Xóa file**

Run: `git rm wp-content/themes/vitech-clone/asset-proxy.php`
Expected: `rm 'wp-content/themes/vitech-clone/asset-proxy.php'`.

- [ ] **Step 3: Nghiệm thu — không còn phụ thuộc nguồn ở output**

Run:
```bash
ddev wp transient delete --all >/dev/null 2>&1; \
fail=0; \
for u in / /may-keo-fj100a/ /gioi-thieu/ /lien-he/ /tai-lieu/ /gio-hang/ /tin-tuc/ "/?s=thang+may"; do \
  b=$(curl -sk "https://thang-may.ddev.site$u"); \
  va=$(printf '%s' "$b" | grep -c 'vitech_asset='); \
  vl=$(printf '%s' "$b" | grep -oc 'https://vitechlift.com'); \
  fz=$(printf '%s' "$b" | grep -c '/frozen/'); \
  printf "%-16s frozen=%-3s vitech_asset=%-2s vitechlift=%-2s\n" "$u" "$fz" "$va" "$vl"; \
  [ "$va" -ne 0 ] && fail=1; [ "$vl" -ne 0 ] && fail=1; [ "$fz" -eq 0 ] && fail=1; \
done; echo "RESULT: $([ $fail -eq 0 ] && echo PASS || echo FAIL)"
```
Expected: mọi route in `frozen>0 vitech_asset=0 vitechlift=0`, dòng cuối `RESULT: PASS`.

- [ ] **Step 4: Nghiệm thu nội dung động còn sống**

Kiểm injector vẫn dựng dữ liệu WP local (không bị đóng băng cứng):
```bash
b=$(curl -sk "https://thang-may.ddev.site/"); \
echo "product cards: $(printf '%s' "$b" | grep -oc 'vitech-add-cart')"; \
echo "brand VILIFT:  $(printf '%s' "$b" | grep -oc 'VILIFT')"; \
echo "màu ghi #6b7280: $(printf '%s' "$b" | grep -oc '#6b7280')"
```
Expected: `product cards` > 0 (injector sản phẩm chạy), `VILIFT` > 0 (rebrand chạy), `#6b7280` > 0 (recolor chạy). Nếu tất cả > 0 → nội dung động + recolor + rebrand còn nguyên.

- [ ] **Step 5: Kiểm mắt thường + Network tab (thủ công)**

Mở `https://thang-may.ddev.site/` trong trình duyệt, DevTools → Network, hard-reload (Cmd+Shift+R). Xác nhận: giao diện y hệt trước; **0 request tới `vitechlift.com`**; không có asset 404. Duyệt thêm 1 sản phẩm, 1 danh mục, 1 bài viết, trang tin, thêm vào giỏ → trang `/gio-hang/` hoạt động.

- [ ] **Step 6: Commit**

```bash
git add -A wp-content/themes/vitech-clone/
git commit -m "Remove asset-proxy.php; site fully self-contained (no vitechlift.com fetch)"
```

---

## Self-Review

**Spec coverage:**
- Snapshot HTML thô ~13 template → Task 1 ✓
- Đóng băng assets đã xử lý (recolor/grayscale/rewrite CSS đệ quy) → Task 2 ✓
- render.php đọc snapshot, bỏ fetch, route thiếu → 500 không mạng → Task 3 Step 4 ✓
- `$asset_url` trỏ frozen root-relative → Task 3 Step 5 ✓
- Đổi tên proxy.php→render.php + 5 stub → Task 3 Step 2 ✓
- Xóa asset-proxy.php + bỏ nhánh vitech_asset → Task 3 Step 3 + Task 4 ✓
- freeze.php là nơi duy nhất gọi mạng → Task 3 Step 8 verify ✓
- Nội dung động còn sống → Task 4 Step 4 ✓
- Nghiệm thu 0 request vitechlift.com → Task 4 Step 3/5 ✓

**Type consistency:** `vitech_clone_snapshot_slug()` (Task 3) khớp `fz_slug()` (Task 1) — cùng thuật toán. `vitech_clone_frozen_base()` = `/wp-content/themes/vitech-clone/frozen` khớp hằng `FZ_FROZEN_BASE` (Task 2). `fz_http_get()` trả `[code, body]` dùng nhất quán Task 1→2.

**Placeholder scan:** không có TBD/TODO; mọi step có code/lệnh cụ thể + output kỳ vọng.

**Rủi ro đã xử lý:** WAF (UA thật + delay + retry backoff); asset nạp bằng JS có thể sót → Task 4 Step 5 soi Network tab để bổ sung.