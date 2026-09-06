<?php
/**
 * Image upload helpers: decode base64 image data, resize, and save under a
 * data/uploads/<folder> path. $folder is always a full path (UPLOAD_DIR . '/xxx').
 */

function saveImageFromBase64(string $base64Data, string $folder, int $maxWidth = 800, int $maxHeight = 600, int $quality = 80): string {
    if (empty($base64Data)) return '';

    $base64Data = trim($base64Data);
    if (strpos($base64Data, 'base64,') !== false) {
        $parts = explode('base64,', $base64Data);
        $base64Data = $parts[1] ?? '';
    }

    $base64Data = str_replace(' ', '+', $base64Data);
    $base64Data = str_replace("\n", '', $base64Data);
    $base64Data = str_replace("\r", '', $base64Data);

    $imageData = base64_decode($base64Data, true);
    if ($imageData === false || $imageData === '') return '';

    $image = @imagecreatefromstring($imageData);
    if (!$image) return '';

    $mimeType = 'image/jpeg';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_buffer($finfo, $imageData);
            if ($detected) $mimeType = $detected;
            finfo_close($finfo);
        }
    }

    $extension = 'jpg';
    if (strpos($mimeType, 'png') !== false) $extension = 'png';
    elseif (strpos($mimeType, 'gif') !== false) $extension = 'gif';
    elseif (strpos($mimeType, 'webp') !== false) $extension = 'webp';

    $width = imagesx($image);
    $height = imagesy($image);

    if ($width > $maxWidth || $height > $maxHeight) {
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int)round($width * $ratio);
        $newHeight = (int)round($height * $ratio);
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }

    $newImage = imagecreatetruecolor($newWidth, $newHeight);

    if ($extension === 'png') {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
        imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
    }

    imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $filename = uniqid() . '.' . $extension;
    $path = $folder . '/' . $filename;

    if ($extension === 'png') imagepng($newImage, $path, 9);
    elseif ($extension === 'gif') imagegif($newImage, $path);
    elseif ($extension === 'webp') imagewebp($newImage, $path, $quality);
    else imagejpeg($newImage, $path, $quality);

    imagedestroy($image);
    imagedestroy($newImage);
    return $filename;
}

/**
 * Safe extension for a MIME type already verified via mime_content_type().
 * Never trust a client-supplied filename's extension for this - a file whose
 * real content is a valid image can still carry a ".php" name, and saving it
 * under that name into a web-accessible uploads folder would let it execute.
 */
function extensionForMime(string $mime): ?string {
    $map = [
        'image/png' => 'png', 'image/jpeg' => 'jpg',
        'image/webp' => 'webp', 'image/gif' => 'gif',
    ];
    return $map[$mime] ?? null;
}

function deleteImageFile(?string $filename, string $folder = 'products'): bool {
    if (empty($filename)) return true;
    $path = UPLOAD_DIR . '/' . $folder . '/' . $filename;
    if (file_exists($path)) return unlink($path);
    return true;
}

function getImageData(string $fieldName = 'image'): string {
    global $input;

    if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$fieldName];
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            error_log("❌ فشل قراءة الملف: " . $file['tmp_name']);
            return '';
        }
        return base64_encode($content);
    }

    if (isset($input[$fieldName]) && !empty($input[$fieldName])) {
        $data = $input[$fieldName];

        if (is_string($data) && strpos($data, 'data:image') === 0) {
            $parts = explode(',', $data);
            return $parts[1] ?? '';
        }

        if (is_string($data) && preg_match('/^[a-zA-Z0-9\/\+]+=*$/', $data)) {
            return $data;
        }

        if (is_string($data)) {
            $clean = preg_replace('/^data:image\/[a-zA-Z]+;base64,/', '', $data);
            $clean = str_replace(' ', '+', $clean);
            if (base64_decode($clean, true) !== false) {
                return $clean;
            }
        }
    }

    return '';
}
