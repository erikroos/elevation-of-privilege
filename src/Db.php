<?php
declare(strict_types=1);

/**
 * Db: dunne wrapper rond PDO. Singleton zodat er per request maar één
 * connectie is. Configuratie komt uit config/config.php.
 */
final class Db
{
    private static ?PDO $instance = null;
    private static ?array $config = null;

    public static function pdo(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $cfg = self::config()['db'];
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['name'],
            $cfg['charset'] ?? 'utf8mb4'
        );

        self::$instance = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return self::$instance;
    }

    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        $path = __DIR__ . '/../config/config.php';
        if (!is_file($path)) {
            throw new RuntimeException(
                'config/config.php ontbreekt. Kopieer config/config.example.php naar config/config.php en vul de waarden in.'
            );
        }
        $cfg = require $path;
        if (!is_array($cfg)) {
            throw new RuntimeException('config/config.php moet een array returnen.');
        }
        self::$config = $cfg;
        return $cfg;
    }
}
