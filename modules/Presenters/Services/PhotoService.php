<?php

declare(strict_types=1);

namespace Modules\Presenters\Services;

use Modules\Core\Config\Slam as SlamConfig;
use Modules\Core\Libraries\DomainException;

/**
 * Presenter photos and slam banners. Validated by real content (finfo + image
 * decode), re-encoded with GD (drops EXIF and anything embedded), saved as a
 * display size and a thumbnail with random names under public/uploads/ where
 * PHP execution is disabled (.htaccess + nginx note in README).
 */
class PhotoService
{
    public const DIR = 'uploads/presenters';

    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @return array{photo_path: string, photo_thumb_path: string}
     */
    public function store(string $tmpPath, int $displayWidth = 800, int $thumbWidth = 200, string $dir = self::DIR): array
    {
        $max = config(SlamConfig::class)->photoMaxBytes;
        if (! is_file($tmpPath) || filesize($tmpPath) === 0) {
            throw new DomainException('upload', 'No file was uploaded.');
        }
        if (filesize($tmpPath) > $max) {
            throw new DomainException('upload', 'The photo is larger than 3 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        if (! in_array($mime, self::ALLOWED, true)) {
            throw new DomainException('upload', 'Only JPG, PNG or WebP photos are accepted.');
        }
        $info = @getimagesize($tmpPath);
        if ($info === false || $info[0] < 50 || $info[1] < 50 || $info[0] * $info[1] > 40_000_000) {
            throw new DomainException('upload', 'The image could not be read or has an unsupported size.');
        }
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png'  => @imagecreatefrompng($tmpPath),
            'image/webp' => @imagecreatefromwebp($tmpPath),
        };
        if ($src === false) {
            throw new DomainException('upload', 'The image could not be decoded.');
        }
        $src = $this->orient($src, $tmpPath, $mime);

        $abs = FCPATH . $dir;
        if (! is_dir($abs)) {
            mkdir($abs, 0755, true);
        }
        $name  = bin2hex(random_bytes(16));
        $paths = [];
        foreach (['photo_path' => [$displayWidth, ''], 'photo_thumb_path' => [$thumbWidth, '-t']] as $key => [$w, $suffix]) {
            $img  = $this->resize($src, $w);
            $file = "{$dir}/{$name}{$suffix}.jpg";
            imagejpeg($img, FCPATH . $file, 85);
            imagedestroy($img);
            $paths[$key] = $file;
        }
        imagedestroy($src);

        return $paths;
    }

    public function delete(?string ...$paths): void
    {
        foreach ($paths as $p) {
            if ($p !== null && $p !== '' && str_starts_with($p, 'uploads/') && ! str_contains($p, '..') && is_file(FCPATH . $p)) {
                @unlink(FCPATH . $p);
            }
        }
    }

    private function resize(\GdImage $src, int $maxW): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $nw = min($w, $maxW);
        $nh = (int) round($h * $nw / $w);
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // flatten transparency onto white
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }

    /** Apply the EXIF orientation of phone photos before the metadata is dropped. */
    private function orient(\GdImage $img, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $o    = (int) ($exif['Orientation'] ?? 1);
        $rot  = match ($o) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };

        return $rot !== 0 ? (imagerotate($img, $rot, 0) ?: $img) : $img;
    }
}
