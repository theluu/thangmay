<?php

if (!defined('ABSPATH')) {
    exit;
}

$source_host = 'https://vitechlift.com';
$local_host = rtrim(home_url('/'), '/');

$source_url = isset($_GET['vitech_asset']) ? rawurldecode((string) wp_unslash($_GET['vitech_asset'])) : '';
$parts = wp_parse_url($source_url);
$asset_path = is_array($parts) && isset($parts['path']) ? $parts['path'] : '';

if (
    !is_string($source_url) ||
    !str_starts_with($source_url, $source_host . '/') ||
    !is_string($asset_path) ||
    !preg_match('#^/(wp-content|wp-includes)/#', $asset_path)
) {
    status_header(404);
    exit;
}

$response = wp_remote_get($source_url, [
    'timeout' => 25,
    'redirection' => 5,
    'user-agent' => 'Mozilla/5.0 (compatible; ThangMayAssetProxy/1.0)',
]);

if (is_wp_error($response)) {
    status_header(502);
    exit;
}

$status = wp_remote_retrieve_response_code($response);
$content_type = (string) wp_remote_retrieve_header($response, 'content-type');
$body = wp_remote_retrieve_body($response);

status_header($status ?: 200);
if ($content_type !== '') {
    header('Content-Type: ' . $content_type);
}
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=86400');

if (stripos($content_type, 'text/css') !== false || str_ends_with($asset_path, '.css')) {
    // Đổi tông màu chủ đạo xanh lá của theme nguồn sang ghi bạc.
    $body = str_ireplace(['#159158', '#0B9344'], ['#6b7280', '#6b7280'], $body);

    $asset_url = static fn(string $url): string => $local_host . '/?vitech_asset=' . rawurlencode($url);
    $base_path = trailingslashit(dirname($asset_path));

    $resolve_relative = static function (string $url) use ($source_host, $base_path): string {
        if (preg_match('#^(data:|https?:|//|/wp-content/|/wp-includes/)#i', $url)) {
            if (str_starts_with($url, '//vitechlift.com/')) {
                return 'https:' . $url;
            }
            if (str_starts_with($url, '/wp-content/') || str_starts_with($url, '/wp-includes/')) {
                return $source_host . $url;
            }
            return $url;
        }

        $path = $base_path . $url;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $source_host . '/' . implode('/', $segments);
    };

    $body = preg_replace_callback(
        '#url\((["\']?)([^)\'"]+)(["\']?)\)#',
        static function (array $matches) use ($asset_url, $resolve_relative): string {
            $url = trim($matches[2]);
            if (str_starts_with($url, 'data:')) {
                return $matches[0];
            }
            return 'url(' . $matches[1] . $asset_url($resolve_relative($url)) . $matches[3] . ')';
        },
        $body
    );
}

// Ảnh PNG trang trí có tông xanh lá (banner, panel "sản phẩm bán chạy", ô icon
// liên hệ...) không đổi được bằng CSS. Tự nhận diện ảnh ngả xanh và chuyển sang
// ghi bạc bằng GD, giữ nguyên vùng trong suốt. Ảnh sản phẩm là JPG nên không bị.
if (
    (stripos($content_type, 'image/png') !== false || str_ends_with(strtolower($asset_path), '.png'))
    && function_exists('imagecreatefromstring')
) {
    $recolored = vitech_clone_greyscale_if_green($body);
    if (is_string($recolored) && $recolored !== '') {
        $body = $recolored;
    }
}

echo $body;

// Nếu ảnh PNG ngả xanh lá thì grayscale + làm sáng nhẹ để hợp tông ghi bạc;
// ngược lại trả null để giữ nguyên (ảnh cam/đỏ không đụng tới).
function vitech_clone_greyscale_if_green(string $data): ?string
{
    $img = @imagecreatefromstring($data);
    if (!$img) {
        return null;
    }

    if (function_exists('imageistruecolor') && !imageistruecolor($img)) {
        imagepalettetotruecolor($img);
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $stepx = max(1, intdiv($w, 24));
    $stepy = max(1, intdiv($h, 24));

    $sumR = $sumG = $sumB = $count = 0;
    for ($y = 0; $y < $h; $y += $stepy) {
        for ($x = 0; $x < $w; $x += $stepx) {
            $c = imagecolorat($img, $x, $y);
            if ((($c >> 24) & 0x7F) > 100) {
                continue; // gần như trong suốt
            }
            $sumR += ($c >> 16) & 0xFF;
            $sumG += ($c >> 8) & 0xFF;
            $sumB += $c & 0xFF;
            $count++;
        }
    }

    if ($count === 0) {
        imagedestroy($img);

        return null;
    }

    $avgR = $sumR / $count;
    $avgG = $sumG / $count;
    $avgB = $sumB / $count;
    if (!($avgG > $avgR + 12 && $avgG > $avgB + 12)) {
        imagedestroy($img); // không ngả xanh

        return null;
    }

    imagefilter($img, IMG_FILTER_GRAYSCALE);
    imagefilter($img, IMG_FILTER_BRIGHTNESS, 35);
    imagealphablending($img, false);
    imagesavealpha($img, true);

    ob_start();
    imagepng($img);
    $out = (string) ob_get_clean();
    imagedestroy($img);

    return $out !== '' ? $out : null;
}
