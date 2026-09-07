<?php
/**
 * PWA icon, generated on the fly so it always matches the admin's uploaded
 * logo. Falls back to a simple brand-colored glyph when no logo is set.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$size = (int)($_GET['size'] ?? 192);
$size = max(48, min(1024, $size));

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

$canvas = imagecreatetruecolor($size, $size);
$bg = imagecolorallocate($canvas, 15, 61, 28);
imagefilledrectangle($canvas, 0, 0, $size, $size, $bg);

$logoFile = getSiteLogoFile();
$logoPath = $logoFile ? UPLOAD_DIR . '/site/' . $logoFile : null;
$logo = ($logoPath && file_exists($logoPath)) ? @imagecreatefromstring(file_get_contents($logoPath)) : false;

if ($logo) {
    $srcW = imagesx($logo);
    $srcH = imagesy($logo);
    // Cover-fit: crop to a centered square from the source before scaling up,
    // so a non-square logo fills the icon instead of squashing/letterboxing.
    $cropSize = min($srcW, $srcH);
    $srcX = (int)(($srcW - $cropSize) / 2);
    $srcY = (int)(($srcH - $cropSize) / 2);
    imagecopyresampled($canvas, $logo, 0, 0, $srcX, $srcY, $size, $size, $cropSize, $cropSize);
    imagedestroy($logo);
} else {
    // Simple bag glyph so the icon still reads as "a store" with no logo set.
    $white = imagecolorallocate($canvas, 255, 255, 255);
    $u = $size / 100;
    imagefilledpolygon($canvas, [
        28 * $u, 40 * $u,
        72 * $u, 40 * $u,
        80 * $u, 85 * $u,
        20 * $u, 85 * $u,
    ], $white);
    imagesetthickness($canvas, max(2, (int)(3 * $u)));
    imagearc($canvas, (int)(50 * $u), (int)(38 * $u), (int)(30 * $u), (int)(30 * $u), 200, 340, $white);
}

imagepng($canvas);
imagedestroy($canvas);
