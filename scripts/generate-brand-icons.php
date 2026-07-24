<?php

/**
 * Generates favicon + extension icons from public/images/brand/app-logo.png.
 * Run: php scripts/generate-brand-icons.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root.'/public/images/brand/app-logo.png';

if (! is_file($source)) {
    fwrite(STDERR, "Missing source logo: {$source}\n");
    exit(1);
}

function resizeLogo(string $source, int $size): GdImage
{
    $src = imagecreatefrompng($source);
    if ($src === false) {
        throw new RuntimeException("Could not read {$source}");
    }

    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, $srcW, $srcH);
    imagedestroy($src);

    return $dst;
}

function savePng(GdImage $img, string $path): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    imagepng($img, $path);
    imagedestroy($img);
    echo "Wrote {$path}\n";
}

foreach ([
    $root.'/public/apple-touch-icon.png' => 180,
    $root.'/../v2-extension/icons/icon16.png' => 16,
    $root.'/../v2-extension/icons/icon48.png' => 48,
    $root.'/../v2-extension/icons/icon128.png' => 128,
    $root.'/../v2-extension/icons/app-logo.png' => 256,
] as $path => $size) {
    savePng(resizeLogo($source, $size), $path);
}

$icoPath = $root.'/public/favicon.ico';
$images = [];
foreach ([16, 32, 48] as $size) {
    $img = resizeLogo($source, $size);
    ob_start();
    imagepng($img);
    $images[] = ['size' => $size, 'data' => ob_get_clean()];
    imagedestroy($img);
}

$offset = 6 + (16 * count($images));
$blob = pack('vvv', 0, 1, count($images));
foreach ($images as $entry) {
    $blob .= pack('CCCCvvVV', $entry['size'], $entry['size'], 0, 0, 1, 32, strlen($entry['data']), $offset);
    $offset += strlen($entry['data']);
}
foreach ($images as $entry) {
    $blob .= $entry['data'];
}
file_put_contents($icoPath, $blob);
echo "Wrote {$icoPath}\n";
