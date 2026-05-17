<?php
declare(strict_types=1);

/**
 * CSRF helper. Embed Csrf::field() in elke <form>, en roep Csrf::check()
 * aan vóór state-changing POST-handling.
 */
final class Csrf
{
    public static function field(): string
    {
        $t = Session::csrf();
        return '<input type="hidden" name="csrf" value="' . htmlspecialchars($t, ENT_QUOTES) . '">';
    }

    public static function check(): void
    {
        $given    = (string)($_POST['csrf'] ?? '');
        $expected = Session::csrf();
        if ($expected === '' || !hash_equals($expected, $given)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Ongeldig of verlopen formulier. Ga terug en probeer opnieuw.";
            exit;
        }
    }
}
