<?php
declare(strict_types=1);

final class Hand
{
    /**
     * Verdeel het volledige deck van het juiste type round-robin over de
     * spelers in seat_order. Schuddet met random_bytes-gebaseerde Fisher-Yates.
     * Idempotent: gooit RuntimeException als er al kaarten zijn uitgedeeld.
     */
    /**
     * Verdeel het volledige deck round-robin over de spelers in seat_order.
     * Verwacht aangeroepen te worden binnen een al-geopende transactie
     * (zie Game::start).
     */
    public static function deal(int $gameId): void
    {
        $pdo = Db::pdo();

        $count = $pdo->prepare('SELECT COUNT(*) FROM hands WHERE game_id = ?');
        $count->execute([$gameId]);
        if ((int)$count->fetchColumn() > 0) {
            throw new RuntimeException('Er zijn al kaarten uitgedeeld voor dit spel.');
        }

        $game = Game::findById($gameId)
            ?? throw new RuntimeException("Spel $gameId niet gevonden.");

        $players = Player::listForGame($gameId);
        if (count($players) < 2) {
            throw new RuntimeException('Minimaal 2 spelers nodig om te starten.');
        }

        $cardsStmt = $pdo->prepare(
            'SELECT id FROM cards WHERE deck_type = ? ORDER BY id'
        );
        $cardsStmt->execute([$game['deck_type']]);
        $cardIds = array_map(static fn ($r) => (int)$r['id'], $cardsStmt->fetchAll());
        if (!$cardIds) {
            throw new RuntimeException('Geen kaarten geseeded voor dit deck — voer seed_cards.php uit.');
        }

        self::shuffle($cardIds);

        $insert = $pdo->prepare(
            'INSERT INTO hands (game_id, player_id, card_id) VALUES (?, ?, ?)'
        );
        foreach ($cardIds as $i => $cardId) {
            $player = $players[$i % count($players)];
            $insert->execute([$gameId, $player['id'], $cardId]);
        }
    }

    /**
     * Geef alle kaarten in de hand van een speler. Standaard alleen
     * de nog niet gespeelde.
     */
    public static function forPlayer(int $playerId, bool $onlyUnplayed = true): array
    {
        $sql = 'SELECT h.id AS hand_id, h.played_at,
                       c.id AS card_id, c.suit_key, c.suit_name, c.rank_code,
                       c.rank_value, c.title, c.description
                  FROM hands h
                  JOIN cards c ON c.id = h.card_id
                 WHERE h.player_id = ?';
        if ($onlyUnplayed) $sql .= ' AND h.played_at IS NULL';
        $sql .= ' ORDER BY c.suit_key, c.rank_value';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([$playerId]);
        return $stmt->fetchAll();
    }

    /** Heeft deze speler nog (ongespeelde) kaarten van deze suit? */
    public static function hasSuit(int $playerId, string $suitKey): bool
    {
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM hands h
               JOIN cards c ON c.id = h.card_id
              WHERE h.player_id = ?
                AND h.played_at IS NULL
                AND c.suit_key = ?
              LIMIT 1'
        );
        $stmt->execute([$playerId, $suitKey]);
        return (bool)$stmt->fetchColumn();
    }

    /** Markeer een kaart als gespeeld in de hand van een speler. */
    public static function markPlayed(int $playerId, int $cardId): void
    {
        $stmt = Db::pdo()->prepare(
            'UPDATE hands
                SET played_at = NOW()
              WHERE player_id = ?
                AND card_id   = ?
                AND played_at IS NULL'
        );
        $stmt->execute([$playerId, $cardId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Kaart staat niet meer in je hand.');
        }
    }

    public static function totalUnplayed(int $gameId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM hands WHERE game_id = ? AND played_at IS NULL'
        );
        $stmt->execute([$gameId]);
        return (int)$stmt->fetchColumn();
    }

    /** Cryptografisch-random Fisher-Yates shuffle. */
    private static function shuffle(array &$arr): void
    {
        for ($i = count($arr) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
        }
    }
}
