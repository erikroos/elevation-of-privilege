<?php
declare(strict_types=1);

/**
 * Seed het master-deck (cards-tabel) vanuit data/cards_stride.json en
 * data/cards_linddun.json.
 *
 * Gebruik:
 *   - Via CLI:  php sql/seed_cards.php
 *   - Via web:  open public/setup.php (zie aparte file) en geef het token mee.
 *
 * Het script is idempotent: bestaande kaarten worden vervangen op basis van
 * (deck_type, suit_key, rank_code).
 */

require_once __DIR__ . '/../src/Db.php';

// Rank-ordering volgens de officiële EoP-spelregels: Ace is HIGH
// (cards.yaml lijst de rank_order als [2..10, J, Q, K, A]).
const RANK_VALUES = [
    '2' =>  2, '3' =>  3, '4' =>  4, '5' =>  5, '6' =>  6,
    '7' =>  7, '8' =>  8, '9' =>  9, 'T' => 10,
    'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14,
];

function seed_deck(PDO $pdo, string $jsonPath): int
{
    $raw = file_get_contents($jsonPath);
    if ($raw === false) {
        throw new RuntimeException("Kan $jsonPath niet lezen");
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    $deckType = $data['deck_type'];
    $attr     = $data['attribution'];
    $suitMap  = [];
    foreach ($data['suits'] as $s) {
        $suitMap[$s['key']] = $s['name'];
    }

    $source  = sprintf(
        '%s — %s (%s)',
        $attr['name'],
        $attr['authors'],
        $attr['source_url']
    );
    $license = $attr['license'];

    $sql = "INSERT INTO cards
              (deck_type, suit_key, suit_name, rank_code, rank_value,
               title, description, source, license)
            VALUES
              (:deck_type, :suit_key, :suit_name, :rank_code, :rank_value,
               :title, :description, :source, :license)
            ON DUPLICATE KEY UPDATE
              suit_name   = VALUES(suit_name),
              rank_value  = VALUES(rank_value),
              title       = VALUES(title),
              description = VALUES(description),
              source      = VALUES(source),
              license     = VALUES(license)";
    $stmt = $pdo->prepare($sql);

    $count = 0;
    foreach ($data['cards'] as $card) {
        $suitKey = $card['suit'];
        $rank    = $card['rank'];
        if (!isset($suitMap[$suitKey])) {
            throw new RuntimeException("Onbekende suit '$suitKey' in $jsonPath");
        }
        if (!isset(RANK_VALUES[$rank])) {
            throw new RuntimeException("Onbekende rank '$rank' in $jsonPath");
        }
        $stmt->execute([
            ':deck_type'   => $deckType,
            ':suit_key'    => $suitKey,
            ':suit_name'   => $suitMap[$suitKey],
            ':rank_code'   => $rank,
            ':rank_value'  => RANK_VALUES[$rank],
            ':title'       => $card['title'],
            ':description' => $card['description'],
            ':source'      => $source,
            ':license'     => $license,
        ]);
        $count++;
    }
    return $count;
}

function run_seed(): array
{
    $pdo = Db::pdo();
    $totals = [];
    foreach (['stride', 'linddun'] as $deck) {
        $path = __DIR__ . "/../data/cards_$deck.json";
        $totals[$deck] = seed_deck($pdo, $path);
    }
    return $totals;
}

// CLI-modus: direct uitvoeren.
if (PHP_SAPI === 'cli') {
    try {
        $totals = run_seed();
        foreach ($totals as $deck => $n) {
            echo "Seeded $n kaarten voor deck '$deck'\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "FOUT: " . $e->getMessage() . "\n");
        exit(1);
    }
}
