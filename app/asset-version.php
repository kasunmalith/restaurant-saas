<?php
declare(strict_types=1);

function asset_version_for(string $relativePath): string
{
    static $versions = [];

    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) return '1';

    $assetRoot = realpath(dirname(__DIR__) . '/public/assets');
    $assetPath = $assetRoot === false
        ? false
        : realpath($assetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($assetRoot === false || $assetPath === false || !is_file($assetPath)
        || !str_starts_with($assetPath, $assetRoot . DIRECTORY_SEPARATOR)
        || !preg_match('/\.(?:css|js)$/i', $assetPath)) {
        return '1';
    }

    if (!isset($versions[$assetPath])) {
        $hash = hash_file('sha256', $assetPath);
        $versions[$assetPath] = $hash === false ? (string) filemtime($assetPath) : substr($hash, 0, 16);
    }
    return $versions[$assetPath];
}

function version_static_asset_urls(string $output): string
{
    $contentType = '';
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0) {
            $contentType = trim(substr($header, strlen('Content-Type:')));
            break;
        }
    }
    // This callback runs for every buffered response. Never rewrite binary payloads
    // such as PDFs or images: their bytes can coincidentally contain escaped slashes.
    if ($contentType !== '' && !preg_match('/^(?:text\/html|application\/json)(?:\s*;|$)/i', $contentType)) {
        return $output;
    }

    // JSON endpoints may escape forward slashes; they are optional in JSON and can be unescaped safely.
    $output = str_replace('\\/', '/', $output);
    if (!str_contains($output, '/assets/')) return $output;

    $pattern = '~((?:href|src)\s*=\s*\\\\?["\'])([^"\'\\\\]*?/assets/[^"\'?\\\\]+\.(?:css|js))(?:\?[^"\'\\\\]*)?(\\\\?["\'])~i';
    $versioned = preg_replace_callback($pattern, static function (array $match): string {
        $urlPath = parse_url($match[2], PHP_URL_PATH);
        if (!is_string($urlPath)) return $match[0];
        $assetMarker = '/assets/';
        $assetPosition = strrpos($urlPath, $assetMarker);
        if ($assetPosition === false) return $match[0];

        $relativePath = rawurldecode(substr($urlPath, $assetPosition + strlen($assetMarker)));
        return $match[1] . $match[2] . '?v=' . rawurlencode(asset_version_for($relativePath)) . $match[3];
    }, $output);

    return $versioned ?? $output;
}
