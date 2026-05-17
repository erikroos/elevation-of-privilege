<?php
declare(strict_types=1);

/**
 * Lichtgewicht sessiebeheer. We gebruiken één cookie 'eop' die een
 * (server-side gegenereerde) sessie-id bevat. Verdere state staat in
 * $_SESSION (PHP file sessions — prima op shared hosting).
 *
 * Voor de identiteit van een speler binnen een spel slaan we het
 * `session_token` uit de players-tabel op in $_SESSION['player_token'].
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('eop');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
    }

    public static function csrf(): string
    {
        return (string)($_SESSION['csrf'] ?? '');
    }

    public static function setPlayerToken(string $token): void
    {
        $_SESSION['player_token'] = $token;
    }

    public static function playerToken(): ?string
    {
        $t = $_SESSION['player_token'] ?? null;
        return is_string($t) && $t !== '' ? $t : null;
    }

    public static function clearPlayerToken(): void
    {
        unset($_SESSION['player_token']);
    }
}
