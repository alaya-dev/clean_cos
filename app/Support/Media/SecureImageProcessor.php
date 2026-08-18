<?php

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SecureImageProcessor
{
    /** @return array{path: string, mime: string, size: int, width: int, height: int} */
    public function store(UploadedFile $file, string $disk, string $directory, int $maxMegapixels = 25, int $maxDimension = 8000): array
    {
        $contents = $file->get();
        if (! is_string($contents)) {
            throw ValidationException::withMessages(['attachment' => 'L’image ne peut pas être lue.']);
        }
        $dimensions = getimagesizefromstring($contents);
        if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > $maxDimension || $dimensions[1] > $maxDimension || $maxMegapixels * 1_000_000 < $dimensions[0] * $dimensions[1]) {
            throw ValidationException::withMessages(['attachment' => 'Les dimensions de l’image sont trop importantes.']);
        }
        $source = imagecreatefromstring($contents);
        if ($source === false) {
            throw ValidationException::withMessages(['attachment' => 'L’image ne peut pas être décodée.']);
        }
        $targetWidth = min(2000, $dimensions[0]);
        $targetHeight = max(1, (int) round($dimensions[1] * ($targetWidth / $dimensions[0])));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $dimensions[0], $dimensions[1]);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'pc-safe-image-');
        if ($temporaryPath === false || ! imagewebp($canvas, $temporaryPath, 82)) {
            imagedestroy($canvas);
            imagedestroy($source);
            throw ValidationException::withMessages(['attachment' => 'L’image ne peut pas être traitée.']);
        }
        imagedestroy($canvas);
        imagedestroy($source);
        $encoded = file_get_contents($temporaryPath);
        unlink($temporaryPath);
        if ($encoded === false) {
            throw ValidationException::withMessages(['attachment' => 'L’image ne peut pas être traitée.']);
        }
        $path = trim($directory, '/').'/'.str()->ulid().'.webp';
        if (! Storage::disk($disk)->put($path, $encoded)) {
            throw ValidationException::withMessages(['attachment' => 'L’image ne peut pas être enregistrée.']);
        }

        return ['path' => $path, 'mime' => 'image/webp', 'size' => strlen($encoded), 'width' => $targetWidth, 'height' => $targetHeight];
    }
}
