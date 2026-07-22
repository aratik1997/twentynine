<?php
declare(strict_types=1);

/**
 * One-off script: rasterizes a simplified version of assets/img/logo.svg
 * with GD for browsers/contexts that need a PNG favicon (the app itself
 * links the SVG as the primary favicon). Run via CLI:
 *   php tools/generate_favicon.php
 */

$font = 'C:/Windows/Fonts/georgiab.ttf';
$outDir = __DIR__ . '/../assets/img/';

function drawBadge(int $size, string $font, string $outPath): void
{
    $im = imagecreatetruecolor($size, $size);
    imagesavealpha($im, true);
    $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
    imagefill($im, 0, 0, $transparent);
    imageantialias($im, true);

    $cx = $size / 2;
    $cy = $size / 2;
    $r = $size * 0.485;

    $gold = imagecolorallocate($im, 212, 175, 55);
    imagefilledellipse($im, (int)$cx, (int)$cy, (int)($r * 2), (int)($r * 2), $gold);

    $feltR = $r * 0.90;
    $felt = imagecolorallocate($im, 20, 92, 57);
    imagefilledellipse($im, (int)$cx, (int)$cy, (int)($feltR * 2), (int)($feltR * 2), $felt);

    $cream = imagecolorallocate($im, 245, 230, 180);
    $fontSize = $size * 0.34;
    $bbox = imagettfbbox($fontSize, 0, $font, '29');
    $textW = $bbox[2] - $bbox[0];
    $textH = $bbox[1] - $bbox[7];
    $tx = $cx - $textW / 2;
    $ty = $cy + $textH / 2 - $bbox[1] + ($bbox[1] - 0) * 0; // baseline adjust below
    $ty = $cy + $textH / 2;
    imagettftext($im, $fontSize, 0, (int)$tx, (int)$ty, $cream, $font, '29');

    imagepng($im, $outPath);
    imagedestroy($im);
}

foreach ([16, 32, 48, 180, 192, 512] as $size) {
    $name = $size === 180 ? 'apple-touch-icon.png' : "favicon-{$size}.png";
    drawBadge($size, $font, $outDir . $name);
    echo "wrote $name\n";
}
