<?php

declare(strict_types=1);

namespace Food\Controller;

use Food\Auth;
use Food\Http\HttpException;
use Food\Http\Request;
use Food\Http\Response;
use Food\Support;

final class UploadController
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_DIMENSION = 1600;

    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function store(Request $request): Response
    {
        Auth::requireUser();

        $file = $request->files['photo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw HttpException::badRequest('Aucun fichier reçu.');
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw HttpException::badRequest('Image trop volumineuse (8 Mo maximum).');
        }

        $info = @getimagesize($file['tmp_name']);
        $mime = $info['mime'] ?? '';
        if (!isset(self::ALLOWED[$mime])) {
            throw HttpException::badRequest('Format non supporté : utilisez JPEG, PNG ou WebP.');
        }

        if (!is_dir(FOOD_UPLOAD_DIR) && !mkdir(FOOD_UPLOAD_DIR, 0775, true) && !is_dir(FOOD_UPLOAD_DIR)) {
            throw new HttpException("Impossible d'enregistrer l'image.", 500);
        }

        $name = Support::uuid() . '.' . self::ALLOWED[$mime];
        $target = FOOD_UPLOAD_DIR . DIRECTORY_SEPARATOR . $name;

        if (!$this->resizeAndSave($file['tmp_name'], $target, $mime)) {
            if (!move_uploaded_file($file['tmp_name'], $target) && !rename($file['tmp_name'], $target)) {
                throw new HttpException("Impossible d'enregistrer l'image.", 500);
            }
        }

        return Response::json(['url' => '/uploads/' . $name], 201);
    }

    /** Redimensionne l'image si GD est disponible ; retourne false sinon. */
    private function resizeAndSave(string $source, string $target, string $mime): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/webp' => @imagecreatefromwebp($source),
            default => false,
        };
        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, self::MAX_DIMENSION / max($width, $height));

        if ($scale < 1.0) {
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, $target, 82),
            'image/png' => imagepng($image, $target, 6),
            'image/webp' => imagewebp($image, $target, 82),
            default => false,
        };
        imagedestroy($image);

        return $ok;
    }
}
