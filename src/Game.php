<?php
declare(strict_types=1);

final class Game
{
    /**
     * Maak een nieuw spel aan met een unieke 6-cijferige code.
     * Bumpt state_version niet — er is nog niemand om te pollen.
     */
    public static function create(string $deckType): array
    {
        if (!in_array($deckType, ['stride', 'linddun'], true)) {
            throw new InvalidArgumentException('Ongeldig deck.');
        }
        $pdo = Db::pdo();

        // Probeer max 10x een unieke code te vinden.
        for ($i = 0; $i < 10; $i++) {
            $code = self::generateCode();
            try {
                $pdo->prepare(
                    'INSERT INTO games (code, deck_type, facilitator_token)
                     VALUES (?, ?, ?)'
                )->execute([
                    $code,
                    $deckType,
                    bin2hex(random_bytes(32)),
                ]);
                return self::findByCode($code) ?? throw new RuntimeException('Race bij aanmaken spel.');
            } catch (PDOException $e) {
                // Dubbele code? Probeer opnieuw. Anders: gooi door.
                if ($e->getCode() !== '23000') throw $e;
            }
        }
        throw new RuntimeException('Kon geen unieke spelcode genereren — probeer opnieuw.');
    }

    public static function findByCode(string $code): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM games WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM games WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Vermenigvuldig state_version zodat pollers een nieuwe versie zien. */
    public static function bumpVersion(int $gameId): void
    {
        Db::pdo()->prepare(
            'UPDATE games SET state_version = state_version + 1 WHERE id = ?'
        )->execute([$gameId]);
    }

    /**
     * Start het spel: deal kaarten, bepaal de eerste leider, open de
     * eerste trick. Zet status op 'playing'. Alleen aan te roepen door
     * de facilitator van het spel (auth check in route).
     */
    public static function start(int $gameId): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $game = self::findById($gameId)
                ?? throw new RuntimeException("Spel $gameId niet gevonden.");
            if ($game['status'] !== 'lobby') {
                throw new RuntimeException('Het spel is al gestart.');
            }

            $players = Player::listForGame($gameId);
            if (count($players) < 2) {
                throw new RuntimeException('Minimaal 2 spelers nodig om te starten.');
            }

            // trump_suit uit deck-config in de game-row pinnen
            $deckPath = __DIR__ . '/../data/cards_' . $game['deck_type'] . '.json';
            $deck = json_decode((string)file_get_contents($deckPath), true, 512, JSON_THROW_ON_ERROR);
            $trump = (string)$deck['trump_suit'];

            Hand::deal($gameId);
            $leaderId = Trick::firstLeader($gameId);

            $pdo->prepare(
                'UPDATE games SET status = ?, trump_suit = ? WHERE id = ?'
            )->execute(['playing', $trump, $gameId]);

            Trick::open($gameId, 1, $leaderId);

            $deckLabel = $game['deck_type'] === 'stride' ? 'STRIDE' : 'LINDDUN GO';
            Event::record(
                $gameId,
                Event::T_STARTED,
                "Het spel is gestart — deck {$deckLabel}, " . count($players) . ' spelers.'
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function setDiagram(int $gameId, string $path, string $mime): void
    {
        Db::pdo()->prepare(
            'UPDATE games SET diagram_path = ?, diagram_mime = ? WHERE id = ?'
        )->execute([$path, $mime, $gameId]);
        Event::record($gameId, Event::T_DIAGRAM_SET, 'De facilitator heeft een systeemdiagram geüpload.');
        self::bumpVersion($gameId);
    }

    public static function clearDiagram(int $gameId): void
    {
        Db::pdo()->prepare(
            'UPDATE games SET diagram_path = NULL, diagram_mime = NULL WHERE id = ?'
        )->execute([$gameId]);
        Event::record($gameId, Event::T_DIAGRAM_CLEAR, 'De facilitator heeft het diagram verwijderd.');
        self::bumpVersion($gameId);
    }

    /**
     * 6-cijferige code zonder voorloop-nul (zodat hij altijd 6 tekens lang
     * blijft als string en niet per ongeluk als int wordt geparsed).
     */
    private static function generateCode(): string
    {
        return (string)random_int(100000, 999999);
    }
}
