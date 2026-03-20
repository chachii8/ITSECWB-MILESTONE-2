<?php
/**
 * File upload type detection (content-based validation)
 * Validates uploads by actual file content (magic bytes / MIME), not just extension,
 * to prevent malicious files renamed as .jpg/.png from being accepted.
 */

// Allowed image types: extension => MIME types (content-based detection)
define('UPLOAD_ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png']);
define('UPLOAD_ALLOWED_MIMES', [
    'image/jpeg',
    'image/png',
]);

// Extension must match one of these MIMEs (prevents .exe renamed to .jpg)
define('UPLOAD_EXTENSION_MIME_MAP', [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
]);

/**
 * Detect MIME type from file content using FileInfo (magic bytes).
 * @param string $tmp_path Path to uploaded temp file
 * @return string|null MIME type or null on failure
 */
function get_upload_content_mime($tmp_path) {
    if (!is_readable($tmp_path) || !filesize($tmp_path)) {
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return null;
    }
    $mime = finfo_file($finfo, $tmp_path);
    finfo_close($finfo);
    return $mime ?: null;
}

/**
 * Validate an uploaded image file by extension and content (MIME) type.
 *
 * @param array $file $_FILES['field_name'] (name, tmp_name, error, size)
 * @return array ['valid' => bool, 'error' => string|null, 'mime' => string|null]
 */
function validate_uploaded_image_type($file) {
    $result = ['valid' => false, 'error' => null, 'mime' => null];

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        $result['error'] = 'No file uploaded or invalid upload.';
        return $result;
    }
    if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
        $result['error'] = 'Upload error (code ' . (int)$file['error'] . ').';
        return $result;
    }

    $name = $file['name'] ?? '';
    $tmp_path = $file['tmp_name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!in_array($ext, UPLOAD_ALLOWED_EXTENSIONS, true)) {
        $result['error'] = 'Invalid file type. Only JPG, JPEG, and PNG are allowed.';
        return $result;
    }

    $detected_mime = get_upload_content_mime($tmp_path);
    $result['mime'] = $detected_mime;

    if ($detected_mime === null) {
        $result['error'] = 'Could not detect file type. Upload may be empty or corrupted.';
        return $result;
    }
    if (!in_array($detected_mime, UPLOAD_ALLOWED_MIMES, true)) {
        $result['error'] = 'File content does not match an allowed image type (detected: ' . htmlspecialchars($detected_mime) . '). Only JPG and PNG images are allowed.';
        return $result;
    }
    // Ensure extension matches content (e.g. .png file must be image/png)
    $allowed_mimes_for_ext = UPLOAD_EXTENSION_MIME_MAP[$ext] ?? [];
    if (!in_array($detected_mime, $allowed_mimes_for_ext, true)) {
        $result['error'] = 'File extension does not match file content. Please upload a valid ' . strtoupper($ext) . ' image.';
        return $result;
    }

    $result['valid'] = true;
    $result['error'] = null;
    return $result;
}
