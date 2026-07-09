<?php
// config/uploads.php
// Shared helpers for validating and saving uploaded product images.

const UPLOAD_MAX_BYTES   = 5 * 1024 * 1024; // 5MB
const UPLOAD_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

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
    if (!move_uploaded_file($file['tmp_name'], rtrim($destDir, '/') . '/' . $filename)) {
        return null;
    }
    return $filename;
}
