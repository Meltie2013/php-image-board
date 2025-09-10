<?php

/**
* A helper class for resizing images using Imagick while preserving
* quality, handling upscaling, transparency, and sharpening.
*/
use Imagick;
use ImagickException;

class ImageHelper
{
    /**
    * Resizes an image while preserving aspect ratio and handling
    * transparency for PNG/WebP formats.
    *
    * Upscales images ≤600x600 by 2x but never exceeds 1280x1280.
    * Maintains maximum image quality and optionally sharpens upscaled images.
    *
    * @param string $src Path to source image
    * @param string $dest Destination relative path for resized image
    * @param int $maxWidth Maximum width constraint
    * @param int $maxHeight Maximum height constraint
    *
    * @return void
    *
    * @throws ImagickException
    */
    public static function resize($src, $dest, $maxWidth = 1280, $maxHeight = 1280)
    {
        $srcPath = $src;
        $destPath = $dest;

        /** @var Imagick $image */
        $image = new Imagick($srcPath);

        // Safe ICC profile handling to preserve colors
        $iccProfile = null;
        try
        {
            $iccProfile = $image->getImageProfile('icc');
        }
        catch (ImagickException $e)
        {
            // No ICC profile present, safe to continue
        }

        if ($iccProfile)
        {
            $image->profileImage('icc', $iccProfile);
        }
        else
        {
            // No profile, convert to standard sRGB
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }

        // Strip other metadata but keep colors intact
        $image->stripImage();

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();

        // Determine scale factor
        if ($width <= 600 && $height <= 600)
        {
            // Upscale by 2x but ensure it does not exceed max dimensions, preserving aspect ratio
            $scale = min(2, $maxWidth / $width, $maxHeight / $height);
        }
        else
        {
            // Downscale larger images while preserving aspect ratio
            $scale = min($maxWidth / $width, $maxHeight / $height, 1);
        }

        $newWidth = (int)($width * $scale);
        $newHeight = (int)($height * $scale);

        // Resize with high-quality filter
        $image->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);

        $mimeType = mime_content_type($srcPath);

        // Optional: sharpen upscaled JPEG/WebP images for better clarity
        if ($scale > 1 && in_array($mimeType, ['image/jpeg', 'image/webp']))
        {
            $image->sharpenImage(0, 1);
        }

        // Set format and compression
        switch ($mimeType)
        {
            case 'image/jpeg':
                $image->setImageFormat('jpeg');
                $image->setImageCompression(Imagick::COMPRESSION_JPEG);
                $image->setImageCompressionQuality(95);
                break;

            case 'image/png':
                $image->setImageFormat('png');
                $image->setImageCompression(Imagick::COMPRESSION_ZIP);
                $image->setImageCompressionQuality(0); // lossless
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
                break;

            case 'image/webp':
                $image->setImageFormat('webp');
                $image->setImageCompressionQuality(95);
                break;

            default:
                $image->setImageFormat('jpeg');
                $image->setImageCompression(Imagick::COMPRESSION_JPEG);
                $image->setImageCompressionQuality(95);
        }

        $image->writeImage($destPath);
        unset($image);
    }
}
