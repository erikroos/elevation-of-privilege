<?php
declare(strict_types=1);

/**
 * Mini view-renderer. Templates zijn gewone PHP-files in src/Views/.
 * Elke template wordt gerenderd binnen layout.php.
 */
final class View
{
    public static function render(string $template, array $data = []): void
    {
        $path = __DIR__ . '/Views/' . $template . '.php';
        if (!is_file($path)) {
            throw new RuntimeException("Template niet gevonden: $template");
        }
        $title = $data['title'] ?? 'Elevation of Privilege';

        // Inhoud naar buffer; layout pikt 'm op als $content.
        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        $content = ob_get_clean();

        require __DIR__ . '/Views/layout.php';
    }

    public static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// Korte alias voor in templates.
function e(string $s): string { return View::escape($s); }
