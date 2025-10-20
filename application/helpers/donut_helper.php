<?php defined('BASEPATH') or exit('No direct script access allowed');

if (!function_exists('donut_image_data_url')) {
    /**
     * Render a donut progress indicator as base64 PNG data URL.
     *
     * @param float $progress 0.0 - 1.0
     * @param int $size Output image size in pixels (square)
     * @param int $thickness Ring thickness in pixels
     * @param array{background?:string, foreground?:string} $colors Hex color overrides
     *
     * @return string|null
     */
    function donut_image_data_url(float $progress, int $size = 96, int $thickness = 14, array $colors = []): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $progress = max(0.0, min(1.0, $progress));
        $size = max(16, $size);
        $thickness = max(2, min((int) floor($size / 2), $thickness));

        $backgroundHex = $colors['background'] ?? '#e9eef5';
        $foregroundHex = $colors['foreground'] ?? '#2e7d32';

        $image = imagecreatetruecolor($size, $size);

        if ($image === false) {
            return null;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        imageantialias($image, true);

        [$bgR, $bgG, $bgB] = donut_hex_to_rgb($backgroundHex);
        [$fgR, $fgG, $fgB] = donut_hex_to_rgb($foregroundHex);

        $backgroundColor = imagecolorallocatealpha($image, $bgR, $bgG, $bgB, 0);
        $foregroundColor = imagecolorallocatealpha($image, $fgR, $fgG, $fgB, 0);

        if ($backgroundColor === false || $foregroundColor === false) {
            imagedestroy($image);

            return null;
        }

        $center = (int) floor($size / 2);
        $diameter = $size - 4;

        imagefilledellipse($image, $center, $center, $diameter, $diameter, $backgroundColor);

        if ($progress >= 0.999) {
            imagefilledellipse($image, $center, $center, $diameter, $diameter, $foregroundColor);
        } elseif ($progress > 0.0) {
            $startAngle = 270;
            $endAngle = $startAngle + 360 * $progress;
            imagefilledarc(
                $image,
                $center,
                $center,
                $diameter,
                $diameter,
                $startAngle,
                $endAngle,
                $foregroundColor,
                IMG_ARC_PIE,
            );
        }

        $innerDiameter = max(0, $diameter - $thickness * 2);

        if ($innerDiameter > 0) {
            imagefilledellipse($image, $center, $center, $innerDiameter, $innerDiameter, $transparent);
        }

        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        if ($binary === false) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($binary);
    }
}

if (!function_exists('donut_hex_to_rgb')) {
    /**
     * @param string $hex
     *
     * @return array{0:int,1:int,2:int}
     */
    function donut_hex_to_rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $value = str_pad($hex, 6, '0', STR_PAD_RIGHT);

        return [hexdec(substr($value, 0, 2)), hexdec(substr($value, 2, 2)), hexdec(substr($value, 4, 2))];
    }
}
