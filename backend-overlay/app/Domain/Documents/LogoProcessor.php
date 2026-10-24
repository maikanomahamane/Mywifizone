<?php

namespace App\Domain\Documents;

use InvalidArgumentException;

/**
 * Logo téléversé -> PNG sûr. L'image est DÉCODÉE puis RÉ-ENCODÉE par GD : métadonnées, commentaires, scripts ou
 * données ajoutées après l'image disparaissent. Le type est déterminé par le contenu (jamais par le nom ni le
 * Content-Type déclaré), la taille et les dimensions sont bornées AVANT décodage (bombe de décompression).
 * Codes d'erreur stables : logo_too_large, logo_invalid, logo_unsupported_type, logo_dimensions.
 */
final class LogoProcessor
{
    public const MAX_BYTES = 524288;       // 512 Kio

    public const MAX_INPUT_DIM = 4000;     // px, par côté

    public const MAX_OUTPUT_DIM = 512;     // px, plus grand côté

    private const TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG];

    /** @return array{png: string, width: int, height: int} */
    public static function process(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('logo_too_large');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new InvalidArgumentException('logo_invalid');
        }
        if (! in_array($info[2], self::TYPES, true)) {
            throw new InvalidArgumentException('logo_unsupported_type');
        }
        [$w, $h] = $info;
        if ($w < 16 || $h < 16 || $w > self::MAX_INPUT_DIM || $h > self::MAX_INPUT_DIM) {
            throw new InvalidArgumentException('logo_dimensions');
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new InvalidArgumentException('logo_invalid');
        }

        $scale = min(1.0, self::MAX_OUTPUT_DIM / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagepng($dst, null, 6);
        $png = (string) ob_get_clean();

        return ['png' => $png, 'width' => $nw, 'height' => $nh];
    }
}
