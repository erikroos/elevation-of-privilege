<?php
declare(strict_types=1);

/**
 * Kleine activiteits-log voor flash-toasts. We slaan een pre-rendered
 * Nederlandse zin op zodat de poller geen joins hoeft te doen om de
 * toast aan te kunnen bieden — alleen WHERE game_id = ? AND id > ?.
 *
 * Events bumpen state_version NIET zelf; ze worden altijd binnen een
 * mutatie geschreven die zelf al bumpt (Player::create, Trick::playCard,
 * Game::start, ...). Op die manier komen toasts in dezelfde polling-tick
 * mee als de bijbehorende state-update.
 */
final class Event
{
    public const T_JOINED       = 'player_joined';
    public const T_STARTED      = 'game_started';
    public const T_PLAYED       = 'card_played';
    public const T_THREAT       = 'threat_logged';
    public const T_TRICK_WON    = 'trick_won';
    public const T_FINISHED     = 'game_finished';
    public const T_DIAGRAM_SET  = 'diagram_set';
    public const T_DIAGRAM_CLEAR = 'diagram_cleared';

    public static function record(int $gameId, string $type, string $message): void
    {
        $msg = mb_substr($message, 0, 255);
        Db::pdo()->prepare(
            'INSERT INTO events (game_id, type, message) VALUES (?, ?, ?)'
        )->execute([$gameId, $type, $msg]);
    }

    /** Events sinds $sinceId, oudst eerst, gecapt op $limit (anti-flood). */
    public static function since(int $gameId, int $sinceId, int $limit = 20): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, type, message, created_at
               FROM events
              WHERE game_id = ? AND id > ?
              ORDER BY id ASC
              LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute([$gameId, $sinceId]);
        return $stmt->fetchAll();
    }

    /** Hoogste event-id voor een spel (voor initiele sync vanuit views). */
    public static function latestId(int $gameId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COALESCE(MAX(id), 0) FROM events WHERE game_id = ?'
        );
        $stmt->execute([$gameId]);
        return (int)$stmt->fetchColumn();
    }
}
