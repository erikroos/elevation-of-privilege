<?php
declare(strict_types=1);

final class Threat
{
    /**
     * Alle threats van één spel, met player + card info, gesorteerd op
     * (suit_key, rank_value) zodat de exports per kleur gegroepeerd
     * blijven. Voor chronologische volgorde: sorteer op created_at.
     */
    public static function listForGame(int $gameId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT t.id, t.description, t.created_at,
                    pl.nickname AS player,
                    c.suit_key, c.suit_name, c.rank_code, c.rank_value,
                    c.title  AS card_title,
                    c.description AS card_description
               FROM threats t
               JOIN players pl ON pl.id = t.player_id
               JOIN cards   c  ON c.id  = t.card_id
              WHERE t.game_id = ?
              ORDER BY c.suit_key, c.rank_value, t.created_at'
        );
        $stmt->execute([$gameId]);
        return $stmt->fetchAll();
    }

    /**
     * Render als CSV. Eerste regel = header. Gebruikt RFC-4180-quoting
     * via fputcsv naar een memory-handle, BOM erbij zodat Excel UTF-8
     * direct juist toont.
     */
    public static function toCsv(array $threats): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new RuntimeException('Kan geen CSV-buffer openen.');
        }
        fputcsv($fh, [
            'tijdstip', 'speler', 'kleur', 'rank',
            'kaart_titel', 'kaart_omschrijving', 'threat'
        ], ',', '"', '');
        foreach ($threats as $t) {
            fputcsv($fh, [
                $t['created_at'],
                $t['player'],
                $t['suit_name'],
                $t['rank_code'],
                $t['card_title'],
                $t['card_description'],
                $t['description'],
            ], ',', '"', '');
        }
        rewind($fh);
        $out = (string)stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $out;     // UTF-8 BOM voor Excel
    }

    /**
     * Render als markdown-rapport, gegroepeerd per suit met een
     * eenvoudige H1 + per-suit H2 + per-threat bullet.
     */
    public static function toMarkdown(array $threats, array $game, array $deckCfg): string
    {
        $suitName = [];
        foreach ($deckCfg['suits'] as $s) $suitName[$s['key']] = $s['name'];

        $deckLabel = $game['deck_type'] === 'stride' ? 'STRIDE (Elevation of Privilege)' : 'LINDDUN GO';

        $out  = "# Threat-log — spel {$game['code']}\n\n";
        $out .= "- **Deck:** {$deckLabel}\n";
        $out .= "- **Gespeeld op:** {$game['created_at']}\n";
        $out .= "- **Aantal threats:** " . count($threats) . "\n\n";

        if (!$threats) {
            $out .= "_Er zijn nog geen threats gelogd._\n";
            return $out;
        }

        $grouped = [];
        foreach ($threats as $t) {
            $grouped[$t['suit_key']][] = $t;
        }

        foreach ($grouped as $suitKey => $items) {
            $h = $suitName[$suitKey] ?? $suitKey;
            $out .= "## {$h}\n\n";
            foreach ($items as $t) {
                $card = "{$t['suit_name']} {$t['rank_code']}";
                $out .= "- **{$card}** — {$t['player']} ({$t['created_at']})\n";
                $out .= "  > {$t['description']}\n";
                $out .= "  _Kaart: {$t['card_description']}_\n\n";
            }
        }
        return $out;
    }

    /** Lichtgewicht helper: hoeveel threats heeft een spel? */
    public static function countForGame(int $gameId): int
    {
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) FROM threats WHERE game_id = ?');
        $stmt->execute([$gameId]);
        return (int)$stmt->fetchColumn();
    }
}
