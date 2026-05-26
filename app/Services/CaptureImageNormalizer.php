<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use ImagickException;
use RuntimeException;

class CaptureImageNormalizer
{
    private const MAX_DIMENSION = 1800;

    private const TARGET_BYTES = 2_400_000;

    /**
     * @return array{path: string, filename: string}
     */
    public function normalize(UploadedFile $file): array
    {
        try {
            $image = $this->readImage($file);
            $blob = $this->jpegBlob($image);
        } catch (ImagickException $exception) {
            throw new RuntimeException('This photo format could not be read. Try choosing the original photo or retaking it as a standard camera image.', 0, $exception);
        }

        if (! $blob) {
            throw new RuntimeException('This photo could not be converted for upload.');
        }

        $path = 'captures/'.now()->format('Y/m').'/'.Str::uuid().'-lead-capture.jpg';
        Storage::disk('local')->put($path, $blob);

        return [
            'path' => $path,
            'filename' => $this->normalizedFilename($file),
        ];
    }

    /**
     * @throws ImagickException
     */
    private function readImage(UploadedFile $file): Imagick
    {
        $image = new Imagick;
        $image->readImage($file->getRealPath());

        if ($image->getNumberImages() > 1) {
            $image->setIteratorIndex(0);
            $image = $image->getImage();
        }

        $this->autoOrient($image);
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $image->setImageBackgroundColor('white');

        if ($image->getImageAlphaChannel()) {
            $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        }

        $image->stripImage();

        return $image;
    }

    /**
     * @throws ImagickException
     */
    private function jpegBlob(Imagick $image): string
    {
        $this->resizeToMaxDimension($image, self::MAX_DIMENSION);

        $blob = '';
        foreach ([82, 74, 66, 58, 50] as $quality) {
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality($quality);
            $blob = $image->getImagesBlob();

            if (strlen($blob) <= self::TARGET_BYTES) {
                return $blob;
            }
        }

        foreach ([1500, 1250, 1000] as $dimension) {
            $this->resizeToMaxDimension($image, $dimension);
            $image->setImageCompressionQuality(70);
            $blob = $image->getImagesBlob();

            if (strlen($blob) <= self::TARGET_BYTES) {
                return $blob;
            }
        }

        return $blob;
    }

    /**
     * @throws ImagickException
     */
    private function resizeToMaxDimension(Imagick $image, int $maxDimension): void
    {
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();

        if (max($width, $height) <= $maxDimension) {
            return;
        }

        $scale = $maxDimension / max($width, $height);
        $image->resizeImage(
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
            Imagick::FILTER_LANCZOS,
            1,
        );
    }

    /**
     * @throws ImagickException
     */
    private function autoOrient(Imagick $image): void
    {
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();

            return;
        }

        if (method_exists($image, 'autoOrientImage')) {
            $image->autoOrientImage();
        }
    }

    private function normalizedFilename(UploadedFile $file): string
    {
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'capture';
        $slug = Str::slug($name) ?: 'capture';

        return $slug.'-lead-capture.jpg';
    }
}
