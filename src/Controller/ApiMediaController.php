<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/media')]
final class ApiMediaController extends AbstractController
{
    private const MAX_WIDTH = 480;

    #[Route('/thumb/{filename}', name: 'api_media_thumb', requirements: ['filename' => '.+'], methods: ['GET'])]
    public function thumb(string $filename): Response
    {
        $safeName = basename($filename);
        $path = $this->getParameter('uploads_directory') . '/images/' . $safeName;

        if (!is_file($path)) {
            throw $this->createNotFoundException('Image not found.');
        }

        if (!function_exists('imagecreatefromstring')) {
            return $this->file($path, $safeName, ResponseHeaderBag::DISPOSITION_INLINE);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw $this->createNotFoundException('Image not found.');
        }

        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            return $this->file($path, $safeName, ResponseHeaderBag::DISPOSITION_INLINE);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($source);

            return $this->file($path, $safeName, ResponseHeaderBag::DISPOSITION_INLINE);
        }

        if ($width <= self::MAX_WIDTH) {
            imagedestroy($source);

            return $this->file($path, $safeName, ResponseHeaderBag::DISPOSITION_INLINE);
        }

        $newWidth = self::MAX_WIDTH;
        $newHeight = (int) round($height * ($newWidth / $width));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if ($resized === false) {
            imagedestroy($source);

            return $this->file($path, $safeName, ResponseHeaderBag::DISPOSITION_INLINE);
        }

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        $mime = match (strtolower(pathinfo($safeName, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        match ($mime) {
            'image/png' => imagepng($resized),
            'image/gif' => imagegif($resized),
            'image/webp' => function_exists('imagewebp') ? imagewebp($resized, null, 85) : imagejpeg($resized, null, 85),
            default => imagejpeg($resized, null, 85),
        };
        imagedestroy($resized);
        $binary = ob_get_clean();

        $response = new Response($binary !== false ? $binary : '');
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }
}
