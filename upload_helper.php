<?php
function is_valid_image_upload(string $temporaryPath, string $originalName, int $maxBytes = 5242880): bool {
    if (!is_uploaded_file($temporaryPath) || !is_readable($temporaryPath)) {
        return false;
    }
    if (filesize($temporaryPath) > $maxBytes) {
        return false;
    }

    $allowedMime = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
    ];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    return isset($allowedMime[$mime]) && in_array($extension, $allowedMime[$mime], true)
        && @getimagesize($temporaryPath) !== false;
}
