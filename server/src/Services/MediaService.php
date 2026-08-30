<?php

declare(strict_types=1);

namespace FamilyQuiz\Services;

use FamilyQuiz\Db\Connections;
use FamilyQuiz\Repo\MediaRepo;
use FamilyQuiz\Support\Id;
use RuntimeException;

final class MediaService
{
    private const ALLOWED = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif',
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/mp4',
        'video/mp4', 'video/webm',
    ];

    public function __construct(
        private MediaRepo $media,
        private array $config,
    ) {}

    public function upload(string $projectId, array $file, ?string $uploadedBy): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('UPLOAD_FAILED');
        }
        $maxBytes = ((int) ($this->config['max_upload_mb'] ?? 50)) * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxBytes) {
            throw new RuntimeException('TOO_LARGE');
        }

        $tmp = $file['tmp_name'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: 'application/octet-stream';
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new RuntimeException('INVALID_MIME');
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => 'bin',
        };

        $id = Id::uuid();
        $storedRel = 'uploads/' . $id . '.' . $ext;
        $dest = Connections::projectDir($projectId) . '/' . $storedRel;

        $width = null;
        $height = null;
        if (str_starts_with($mime, 'image/')) {
            [$destMime, $width, $height, $bytes] = $this->processImage($tmp, $dest, $mime);
            $mime = $destMime;
        } else {
            if (!move_uploaded_file($tmp, $dest) && !rename($tmp, $dest)) {
                throw new RuntimeException('UPLOAD_FAILED');
            }
            $bytes = filesize($dest) ?: 0;
        }

        return $this->media->create($projectId, [
            'id' => $id,
            'filename' => (string) ($file['name'] ?? $id . '.' . $ext),
            'stored_path' => $storedRel,
            'mime' => $mime,
            'bytes' => $bytes,
            'width' => $width,
            'height' => $height,
            'uploaded_by' => $uploadedBy,
            'created_at' => Id::now(),
        ]);
    }

    /** @return array{0: string, 1: ?int, 2: ?int, 3: int} */
    private function processImage(string $tmp, string $dest, string $mime): array
    {
        $info = @getimagesize($tmp);
        if ($info === false) {
            throw new RuntimeException('INVALID_IMAGE');
        }
        [$w, $h] = $info;
        $max = 1920;
        $scale = 1.0;
        if (max($w, $h) > $max) {
            $scale = $max / max($w, $h);
        }
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png' => @imagecreatefrompng($tmp),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            'image/gif' => @imagecreatefromgif($tmp),
            default => false,
        };
        if ($src === false) {
            // Fallback: copy as-is for formats GD can't handle (avif)
            if (!move_uploaded_file($tmp, $dest) && !rename($tmp, $dest)) {
                throw new RuntimeException('UPLOAD_FAILED');
            }
            return [$mime, $w, $h, filesize($dest) ?: 0];
        }

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $outMime = 'image/jpeg';
        if ($mime === 'image/png' || $mime === 'image/webp') {
            $outMime = $mime;
        }
        $ok = match ($outMime) {
            'image/png' => imagepng($dst, $dest, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($dst, $dest, 85) : imagejpeg($dst, $dest, 85),
            default => imagejpeg($dst, $dest, 85),
        };
        imagedestroy($src);
        imagedestroy($dst);
        if (!$ok) {
            throw new RuntimeException('UPLOAD_FAILED');
        }
        if ($outMime === 'image/webp' && !function_exists('imagewebp')) {
            $outMime = 'image/jpeg';
        }
        return [$outMime, $nw, $nh, filesize($dest) ?: 0];
    }
}
