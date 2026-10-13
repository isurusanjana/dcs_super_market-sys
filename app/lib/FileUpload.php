<?php
class FileUpload {
    /** Validates and stores an uploaded image. Returns stored file name, null if none uploaded. Throws RuntimeException on invalid. */
    static function image(string $field, string $dir = 'products'): ?string {
        if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
        $f = $_FILES[$field];
        if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Image upload failed (code ' . $f['error'] . ').');
        if ($f['size'] > cfg('upload_max_bytes', 2097152)) throw new RuntimeException('Image is too large (max ' . round(cfg('upload_max_bytes', 2097152) / 1048576) . ' MB).');
        if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Invalid upload.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
        if (!$ext || @getimagesize($f['tmp_name']) === false) throw new RuntimeException('Only JPG, PNG, WEBP or GIF images are allowed.');
        $target = APP_ROOT . "/uploads/$dir"; if (!is_dir($target)) mkdir($target, 0755, true);
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], "$target/$name")) throw new RuntimeException('Could not save the image.');
        return $name;
    }
    static function remove(?string $name, string $dir = 'products'): void {
        if ($name && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $name)) @unlink(APP_ROOT . "/uploads/$dir/$name");
    }
}
