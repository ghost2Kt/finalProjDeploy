<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

final class ProductImageUploader
{
    public function __construct(
        private readonly string $uploadsDirectory,
    ) {
    }

    /**
     * @throws FileException when the file cannot be stored
     */
    public function upload(UploadedFile $file, SluggerInterface $slugger): string
    {
        $imagesDir = $this->uploadsDirectory.'/images';
        if (!is_dir($imagesDir) && !mkdir($imagesDir, 0775, true) && !is_dir($imagesDir)) {
            throw new FileException(sprintf('Upload directory "%s" could not be created.', $imagesDir));
        }

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = (string) $slugger->slug($originalFilename !== '' ? $originalFilename : 'product');
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg';
        $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'jpg');
        $newFilename = $safeFilename.'-'.uniqid().'.'.$extension;

        $file->move($imagesDir, $newFilename);

        return $newFilename;
    }

    public function delete(?string $filename): void
    {
        if ($filename === null || $filename === '') {
            return;
        }

        $path = $this->uploadsDirectory.'/images/'.basename($filename);
        if (is_file($path)) {
            unlink($path);
        }
    }
}
