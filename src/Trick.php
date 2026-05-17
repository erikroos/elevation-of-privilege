<?php
declare(strict_types=1);

final class Trick
{
    /**
     * Welke speler leidt de eerste trick? Conventie: de speler die de
     * "starter-kaart" in handen heeft. STRIDE: 3 of Tampering (volgens
     * de originele EoP-regels). LINDDUN: 2 of Identifying (eerste niet-trump
     * suit, laagste rank in onze mapping).
     */
    public static function firstLeader(int $gameId): int
    {
        $game = Game::findById($gameId)
            ?? throw new RuntimeException("Spel $gameId niet gevonden.");

        $deck = $game['deck_type'];
        // (deck_type, suit_key, rank_code) van de starter-kaart
        [$starterSuit, $starterRank] = match ($deck) {
            'stride'  => ['T', '3'],
            'linddun' => ['I', '2'],
            default   => throw new RuntimeException("Onbekend deck $deck"),
        };

        $stmt = Db::pdo()->prepare(
            'SELECT h.player_id
               FROM hands h
               JOIN cards c ON c.id = h.card_id
              WHERE h.game_id = ?
                AND c.deck_type = ?
                AND c.suit_key = ?
                AND c.rank_code = ?
              LIMIT 1'
        );
        $stmt->execute([$gameId, $deck, $starterSuit, $starterRank]);
        $pid = $stmt->fetchColumn();
        if (!$pid) {
            // Fallback: speler met de laagste kaart van de starter-suit.
            $stmt = Db::pdo()->prepare(
                'SELECT h.player_id
                   FROM hands h
                   JOIN cards c ON c.id = h.card_id
                  WHERE h.game_id = ?
                    AND c.suit_key = ?
                  ORDER BY c.rank_value ASC
                  LIMIT 1'
            );
            $stmt->execute([$gameId, $starterSuit]);
            $pid = $stmt->fetchColumn();
        }
        if (!$pid) {
            // Absolute fallback: speler met de laagste kaart sowieso.
            $stmt = Db::pdo()->prepare(
                'SELECT h.player_id FROM hands h
                   JOIN cards c ON c.id = h.card_id
                  WHERE h.game_id = ?
                  ORDER BY c.rank_value ASC LIMIT 1'
            );
            $stmt->execute([$gameId]);
            $pid = $stmt->fetchColumn();
        }
        if (!$pid) {
            throw new RuntimeException('Geen speler met startkaart gevonden.');
        }
        return (int)$pid;
    }

    /** Maak een nieuwe trick aan en zet 'm als de huidige van het spel. */
    public static function open(int $gameId, int $trickNumber, int $leadPlayerId): int
    {
        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO tricks
               (game_id, trick_number, lead_player_id, current_player_id)
             VALUES (?, ?, ?, ?)'
        )->execute([$gameId, $trickNumber, $leadPlayerId, $leadPlayerId]);

        $trickId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE games SET current_trick_id = ? WHERE id = ?')
            ->execute([$trickId, $gameId]);
        Game::bumpVersion($gameId);
        return $trickId;
    }

    public static function current(int $gameId): ?array
    {
        $game = Game::findById($gameId);
        if (!$game || !$game['current_trick_id']) return null;
        $stmt = Db::pdo()->prepare('SELECT * FROM tricks WHERE id = ?');
        $stmt->execute([$game['current_trick_id']]);
        $t = $stmt->fetch();
        return $t ?: null;
    }

    public static function plays(int $trickId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT p.*, c.suit_key, c.suit_name, c.rank_code, c.rank_value,
                    c.title, c.description, pl.nickname, pl.seat_order
               FROM plays p
               JOIN cards   c  ON c.id  = p.card_id
               JOIN players pl ON pl.id = p.player_id
              WHERE p.trick_id = ?
              ORDER BY p.play_order'
        );
        $stmt->execute([$trickId]);
        return $stmt->fetchAll();
    }

    /**
     * Speel een kaart in de huidige trick. Valideert:
     *  - het is de beurt van deze speler
     *  - kaart zit nog in zijn hand
     *  - als de trick al een lead-suit heeft en de speler heeft die suit,
     *    moet hij die volgen
     * Insert de play, markeert kaart als gespeeld, en triggert eventueel
     * trick-resolution + game-completion.
     */
    public static function playCard(
        int $gameId,
        int $playerId,
        int $cardId,
        ?string $threatDescription
    ): void {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $game = Game::findById($gameId)
                ?? throw new RuntimeException('Spel niet gevonden.');
            if ($game['status'] !== 'playing') {
                throw new RuntimeException('Het spel loopt niet.');
            }
            $trick = self::current($gameId)
                ?? throw new RuntimeException('Geen actieve trick.');
            if ((int)$trick['current_player_id'] !== $playerId) {
                throw new RuntimeException('Het is niet jouw beurt.');
            }

            $card = self::cardById($cardId)
                ?? throw new RuntimeException('Kaart niet gevonden.');

            // Suit-volgen-regel
            if ($trick['lead_suit'] !== null
                && $card['suit_key'] !== $trick['lead_suit']
                && Hand::hasSuit($playerId, $trick['lead_suit'])) {
                throw new RuntimeException(
                    "Je moet de geleide suit ({$trick['lead_suit']}) volgen — je hebt er nog kaarten van."
                );
            }

            // Volgnummer in deze trick
            $orderStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(play_order), 0) + 1 FROM plays WHERE trick_id = ?'
            );
            $orderStmt->execute([$trick['id']]);
            $playOrder = (int)$orderStmt->fetchColumn();

            // Insert play
            $threat = $threatDescription !== null ? trim($threatDescription) : null;
            if ($threat === '') $threat = null;

            $pdo->prepare(
                'INSERT INTO plays
                   (trick_id, player_id, card_id, play_order,
                    threat_description, threat_skipped)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $trick['id'], $playerId, $cardId, $playOrder,
                $threat, $threat === null ? 1 : 0,
            ]);
            $playId = (int)$pdo->lastInsertId();

            // Hand kaart als gespeeld markeren
            Hand::markPlayed($playerId, $cardId);

            // Lead-suit pinnen op de eerste play
            if ($playOrder === 1) {
                $pdo->prepare('UPDATE tricks SET lead_suit = ? WHERE id = ?')
                    ->execute([$card['suit_key'], $trick['id']]);
                $trick['lead_suit'] = $card['suit_key'];
            }

            // Nickname voor events
            $player    = Player::findById($playerId);
            $nickname  = $player['nickname'] ?? 'Onbekend';
            $cardLabel = "{$card['suit_name']} {$card['rank_code']}";
            Event::record(
                $gameId,
                Event::T_PLAYED,
                "{$nickname} speelde {$cardLabel}."
            );

            // Threat → score + log-entry
            if ($threat !== null) {
                $pdo->prepare(
                    'UPDATE players SET score = score + 1 WHERE id = ?'
                )->execute([$playerId]);
                $pdo->prepare(
                    'INSERT INTO threats
                       (game_id, play_id, player_id, card_id, description)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$gameId, $playId, $playerId, $cardId, $threat]);
                Event::record(
                    $gameId,
                    Event::T_THREAT,
                    "{$nickname} beschreef een threat (+1 punt)."
                );
            }

            // Volgende speler bepalen of trick afronden
            $players = Player::listForGame($gameId);
            $byId    = [];
            foreach ($players as $p) $byId[(int)$p['id']] = $p;

            if ($playOrder >= count($players)) {
                self::resolve($gameId, (int)$trick['id'], $trick['lead_suit'], $players);
            } else {
                $nextSeat = self::nextSeat((int)$byId[$playerId]['seat_order'], count($players));
                $nextPlayer = null;
                foreach ($players as $p) {
                    if ((int)$p['seat_order'] === $nextSeat) { $nextPlayer = $p; break; }
                }
                if (!$nextPlayer) {
                    throw new RuntimeException('Volgende speler niet gevonden.');
                }
                $pdo->prepare('UPDATE tricks SET current_player_id = ? WHERE id = ?')
                    ->execute([$nextPlayer['id'], $trick['id']]);
            }
            Game::bumpVersion($gameId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Bepaal de winnaar van een complete trick, ken een punt toe en open
     * de volgende trick (of zet het spel op 'finished' als alle handen leeg
     * zijn). Wordt aangeroepen vanuit playCard binnen de transactie.
     */
    private static function resolve(int $gameId, int $trickId, ?string $leadSuit, array $players): void
    {
        $pdo = Db::pdo();
        $game = Game::findById($gameId)
            ?? throw new RuntimeException('Spel weg tijdens resolutie.');

        // Trump = trump_suit van het spel. Standaard uit deck-config.
        $deckMeta = self::deckMeta($game['deck_type']);
        $trump = $deckMeta['trump_suit'];

        $allPlays = self::plays($trickId);

        $trumpPlays = array_filter($allPlays, fn ($p) => $p['suit_key'] === $trump);
        $eligible = $trumpPlays ?: array_filter($allPlays, fn ($p) => $p['suit_key'] === $leadSuit);

        usort($eligible, fn ($a, $b) => $b['rank_value'] <=> $a['rank_value']);
        $winnerPlay = $eligible[0] ?? null;
        if (!$winnerPlay) {
            throw new RuntimeException('Kan winnaar niet bepalen — geen geldige kaarten.');
        }

        $winnerId = (int)$winnerPlay['player_id'];

        $pdo->prepare(
            'UPDATE tricks SET winner_player_id = ?, completed_at = NOW() WHERE id = ?'
        )->execute([$winnerId, $trickId]);
        $pdo->prepare('UPDATE players SET score = score + 1 WHERE id = ?')
            ->execute([$winnerId]);

        $winner = Player::findById($winnerId);
        $winnerNick = $winner['nickname'] ?? 'Onbekend';
        $winLabel = "{$winnerPlay['suit_name']} {$winnerPlay['rank_code']}";
        Event::record(
            $gameId,
            Event::T_TRICK_WON,
            "{$winnerNick} wint de trick met {$winLabel} (+1 punt)."
        );

        if (Hand::totalUnplayed($gameId) === 0) {
            $pdo->prepare(
                'UPDATE games SET status = ?, current_trick_id = NULL WHERE id = ?'
            )->execute(['finished', $gameId]);
            Event::record($gameId, Event::T_FINISHED, 'Het spel is afgelopen — alle kaarten zijn gespeeld.');
        } else {
            $nextTrickNumber = (int)$game['current_trick_id']
                ? (int)$pdo->query("SELECT MAX(trick_number) FROM tricks WHERE game_id = $gameId")->fetchColumn() + 1
                : 1;
            self::open($gameId, $nextTrickNumber, $winnerId);
        }
    }

    private static function nextSeat(int $seat, int $totalPlayers): int
    {
        return ($seat % $totalPlayers) + 1; // seats zijn 1-based
    }

    private static function cardById(int $cardId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM cards WHERE id = ?');
        $stmt->execute([$cardId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function deckMeta(string $deckType): array
    {
        $path = __DIR__ . '/../data/cards_' . $deckType . '.json';
        $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return [
            'trump_suit' => $data['trump_suit'],
            'suits'      => $data['suits'],
        ];
    }
}
