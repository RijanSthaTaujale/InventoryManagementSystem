<?php
// config/uploads.php
// Shared helpers for validating and saving uploaded product images.

const UPLOAD_MAX_BYTES     = 5 * 1024 * 1024; // 5MB
const UPLOAD_ALLOWED_EXT   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const UPLOAD_MAX_DIMENSION = 1200; // px, longest side after resize

// Strips a filename down to a safe basename with only alphanumerics/-/_ in the name part.
function sanitizeFilename(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base) ?: 'file';
    return $ext ? "$base.$ext" : $base;
}

// Validates an uploaded file ($_FILES[...] entry) is really an image within
// size/extension limits. Returns ['ok'=>bool, 'ext'=>string, 'message'=>string].
function validateImageUpload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'ext' => '', 'message' => 'No file uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'ext' => '', 'message' => 'Upload failed.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'ext' => '', 'message' => 'Invalid upload.'];
    }
    if ($file['size'] > UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'ext' => '', 'message' => 'Image must be under 5MB.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, UPLOAD_ALLOWED_EXT, true)) {
        return ['ok' => false, 'ext' => '', 'message' => 'Only JPG, PNG, GIF, or WEBP images are allowed.'];
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return ['ok' => false, 'ext' => '', 'message' => 'File is not a valid image.'];
    }
    return ['ok' => true, 'ext' => $ext, 'message' => ''];
}

// Validates and moves an uploaded file into $destDir with a random safe name.
// Returns the saved filename (not full path) on success, or null on failure.
function saveUploadedImage(array $file, string $destDir): ?string {
    $check = validateImageUpload($file);
    if (!$check['ok']) return null;

    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    $filename = 'img_' . bin2hex(random_bytes(8)) . '.' . $check['ext'];
    $destPath = rtrim($destDir, '/') . '/' . $filename;

    // Downscale/re-compress before storing — a photo straight off a phone
    // camera can be several MB and thousands of px wide, which is wasted
    // everywhere it's shown as a 36–40px thumbnail (Products, Inventory,
    // order search results). Falls back to storing the original untouched
    // if GD isn't available or the resize fails for any reason.
    if (!resizeAndSaveImage($file['tmp_name'], $destPath, $check['ext'])) {
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return null;
        }
    }
    return $filename;
}

// Resizes an image to fit within UPLOAD_MAX_DIMENSION (longest side) and
// re-encodes it at a reasonable quality. Returns false if it can't be
// processed (GD missing, corrupt file, unsupported format) so the caller
// falls back to storing the original upload as-is.
function resizeAndSaveImage(string $srcPath, string $destPath, string $ext): bool {
    if (!extension_loaded('gd')) return false;

    $data = @file_get_contents($srcPath);
    $src  = $data !== false ? @imagecreatefromstring($data) : false;
    if (!$src) return false;

    $width  = imagesx($src);
    $height = imagesy($src);
    $scale  = min(1, UPLOAD_MAX_DIMENSION / max($width, $height));
    $newW   = max(1, (int)round($width  * $scale));
    $newH   = max(1, (int)round($height * $scale));

    $dst = imagecreatetruecolor($newW, $newH);
    if (in_array($ext, ['png', 'gif', 'webp'], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);

    $ok = match ($ext) {
        'jpg', 'jpeg' => imagejpeg($dst, $destPath, 82),
        'png'         => imagepng($dst, $destPath, 6),
        'gif'         => imagegif($dst, $destPath),
        'webp'        => function_exists('imagewebp') ? imagewebp($dst, $destPath, 82) : false,
        default       => false,
    };

    imagedestroy($src);
    imagedestroy($dst);
    return $ok;
}
