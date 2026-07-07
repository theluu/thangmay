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

echo $body;
