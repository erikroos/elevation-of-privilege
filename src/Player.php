<?php
declare(strict_types=1);

final class Player
{
    public const MAX_PLAYERS = 10;

    /**
     * Maak een speler aan. Genereert een uniek session_token.
     * Gooit RuntimeException als spel vol zit of nickname al in gebruik.
     */
    public static function create(int $gameId, string $nickname, bool $isFacilitator): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // Lock + tel huidige spelers.
            $stmt = $pdo->prepare('SELECT id, nickname, seat_order FROM players WHERE game_id = ? FOR UPDATE');
            $stmt->execute([$gameId]);
            $current = $stmt->fetchAll();

            if (count($current) >= self::MAX_PLAYERS) {
                throw new RuntimeException('Dit spel zit vol.');
            }
            foreach ($current as $p) {
                if (mb_strtolower($p['nickname']) === mb_strtolower($nickname)) {
                    throw new RuntimeException('Die naam is al in gebruik in dit spel — kies een andere.');
                }
            }

            $seat = count($current) + 1;
            $token = bin2hex(random_bytes(32));

            $pdo->prepare(
                'INSERT INTO players
                   (game_id, nickname, seat_order, is_facilitator, session_token)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$gameId, $nickname, $seat, $isFacilitator ? 1 : 0, $token]);

            $id = (int)$pdo->lastInsertId();

            $label = $isFacilitator ? ' (facilitator)' : '';
            Event::record(
                $gameId,
                Event::T_JOINED,
                "{$nickname}{$label} is binnengekomen."
            );

            $pdo->commit();

            Game::bumpVersion($gameId);

            return self::findById($id) ?? throw new RuntimeException('Speler verdween direct na insert.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function findById(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM players WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByToken(string $token): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM players WHERE session_token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Speler in de huidige sessie — null als geen, of als andere game. */
    public static function currentForGame(int $gameId): ?array
    {
        $token = Session::playerToken();
        if (!$token) return null;
        $p = self::findByToken($token);
        if (!$p || (int)$p['game_id'] !== $gameId) return null;
        return $p;
    }

    public static function listForGame(int $gameId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, nickname, seat_order, score, is_facilitator
               FROM players
              WHERE game_id = ?
              ORDER BY seat_order'
        );
        $stmt->execute([$gameId]);
        return $stmt->fetchAll();
    }
}
