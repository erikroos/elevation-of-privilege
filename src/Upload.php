<?php
declare(strict_types=1);

/**
 * Veilige diagram-upload.
 *
 * - Valideert size, error-status, mime (via finfo, niet vertrouwen op
 *   $_FILES['type']) en magic bytes per type.
 * - Hernoemt naar een random hex-naam zodat we niets uit user input
 *   teruggeven op het filesystem (geen path traversal, geen XSS via
 *   filename, geen "shell.php.jpg"-trucs).
 * - Slaat op onder public/uploads/diagrams/ met restrictieve .htaccess
 *   (geen PHP-execute, SVG forced naar image/svg+xml + CSP).
 *
 * Throws RuntimeException bij elke validatiefout met nederlandstalige
 * boodschap voor in de UI.
 */
final class Upload
{
    private const EXT_BY_MIME = [
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
    ];

    /**
     * Verwerk een $_FILES-entry. Retourneert ['path' => 'uploads/diagrams/<name>',
     *                                          'mime' => '...'].
     */
    public static function handleDiagram(array $file): array
    {
        $cfg = Db::config();

        // Stille post_max_size-overschrijding: PHP gooit $_POST en $_FILES
        // dan leeg weg zonder error. Detecteer dit aan Content-Length.
        if (empty($file) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $cl = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            $pms = self::bytes(ini_get('post_max_size') ?: '0');
            if ($cl > 0 && $pms > 0 && $cl > $pms) {
                $mb = (int)round($pms / (1024 * 1024));
                throw new RuntimeException(
                    "Verzoek is groter dan de server toestaat (post_max_size = {$mb} MB). "
                  . "Verhoog deze waarde of upload een kleiner bestand."
                );
            }
            throw new RuntimeException('Geen bestand ontvangen.');
        }
        if (!is_array($file) || !isset($file['error'])) {
            throw new RuntimeException('Geen bestand ontvangen.');
        }
        self::checkUploadError((int)$file['error']);

        $size = (int)($file['size'] ?? 0);
        $max  = (int)$cfg['upload_max_bytes'];
        if ($size <= 0) {
            throw new RuntimeException('Het bestand is leeg.');
        }
        if ($size > $max) {
            $mb = (int)round($max / (1024 * 1024));
            throw new RuntimeException("Bestand is groter dan toegestaan ({$mb} MB).");
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Upload niet correct ontvangen.');
        }

        $detectedMime = self::detectMime($tmp);
        $allowed      = (array)$cfg['upload_allowed_mimes'];
        if (!in_array($detectedMime, $allowed, true)) {
            throw new RuntimeException(
                'Bestandstype niet toegestaan. Alleen PNG, JPG, SVG of PDF.'
            );
        }
        if (!self::verifyMagicBytes($tmp, $detectedMime)) {
            throw new RuntimeException(
                'Bestandsinhoud komt niet overeen met het type. Upload geweigerd.'
            );
        }

        // SVG: extra check tegen ingebedde scripts. Wordt al door CSP in
        // .htaccess geblokkeerd in de browser, maar we willen ze er
        // helemaal niet in.
        if ($detectedMime === 'image/svg+xml' && self::svgHasScripting($tmp)) {
            throw new RuntimeException(
                'SVG bevat scripts of externe verwijzingen. Verwijder die en upload opnieuw.'
            );
        }

        $ext  = self::EXT_BY_MIME[$detectedMime];
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dir  = rtrim((string)$cfg['upload_dir'], '/');

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Upload-directory ontbreekt en kon niet worden aangemaakt.');
        }
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Verplaatsen van geüpload bestand mislukte.');
        }
        @chmod($dest, 0644);

        return [
            'path' => 'uploads/diagrams/' . $name,
            'mime' => $detectedMime,
        ];
    }

    /** Verwijder eerder geüpload diagram (best effort). */
    public static function deleteIfExists(?string $relativePath): void
    {
        if (!$relativePath) return;
        // Alleen onder uploads/diagrams/ accepteren.
        if (!str_starts_with($relativePath, 'uploads/diagrams/')) return;
        if (str_contains($relativePath, '..')) return;
        $abs = __DIR__ . '/../public/' . $relativePath;
        if (is_file($abs)) @unlink($abs);
    }

    private static function checkUploadError(int $code): void
    {
        if ($code === UPLOAD_ERR_OK) return;
        if ($code === UPLOAD_ERR_INI_SIZE) {
            $umf = self::bytes(ini_get('upload_max_filesize') ?: '0');
            $mb  = (int)round($umf / (1024 * 1024));
            throw new RuntimeException(
                "Bestand is groter dan de server toestaat "
              . "(upload_max_filesize = {$mb} MB). "
              . "Vraag de beheerder deze waarde te verhogen."
            );
        }
        $msg = match ($code) {
            UPLOAD_ERR_FORM_SIZE  => 'Bestand is groter dan in het formulier toegestaan.',
            UPLOAD_ERR_PARTIAL    => 'Upload werd onderbroken.',
            UPLOAD_ERR_NO_FILE    => 'Geen bestand gekozen.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server-tempdir ontbreekt.',
            UPLOAD_ERR_CANT_WRITE => 'Server kon niet schrijven naar disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload geblokkeerd door PHP-extensie.',
            default               => 'Onbekende uploadfout.',
        };
        throw new RuntimeException($msg);
    }

    /** Vertaal "8M" / "512K" / "2G" naar bytes. */
    private static function bytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') return 0;
        $unit = strtolower(substr($val, -1));
        $num  = (int)$val;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int)$val,
        };
    }

    private static function detectMime(string $path): string
    {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if ($f === false) {
            throw new RuntimeException('finfo niet beschikbaar.');
        }
        $mime = finfo_file($f, $path);
        // PHP 8.5+: finfo_close() is deprecated; finfo objects worden
        // automatisch vrijgegeven wanneer $f buiten scope gaat.
        if ($mime === false) {
            throw new RuntimeException('Bestandstype niet te bepalen.');
        }
        // finfo herkent SVG soms als image/svg of text/xml — normaliseer.
        if (in_array($mime, ['image/svg', 'text/xml', 'application/xml'], true)
            && self::looksLikeSvg($path)) {
            return 'image/svg+xml';
        }
        return $mime;
    }

    private static function looksLikeSvg(string $path): bool
    {
        $head = (string)file_get_contents($path, false, null, 0, 1024);
        return str_contains($head, '<svg');
    }

    private static function verifyMagicBytes(string $path, string $mime): bool
    {
        $head = (string)file_get_contents($path, false, null, 0, 8);
        return match ($mime) {
            'image/png'       => str_starts_with($head, "\x89PNG\r\n\x1a\n"),
            'image/jpeg'      => str_starts_with($head, "\xFF\xD8\xFF"),
            'application/pdf' => str_starts_with($head, "%PDF-"),
            'image/svg+xml'   => self::looksLikeSvg($path),
            default           => false,
        };
    }

    private static function svgHasScripting(string $path): bool
    {
        $contents = (string)file_get_contents($path);
        // Trivially-detectable risicopatronen.
        $patterns = [
            '/<script\b/i',
            '/\son[a-z]+\s*=/i',           // on-event attributes
            '/<foreignObject\b/i',
            '/xlink:href\s*=\s*["\']?\s*(?:javascript:|data:text\/html)/i',
            '/href\s*=\s*["\']?\s*(?:javascript:|data:text\/html)/i',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $contents)) return true;
        }
        return false;
    }
}
