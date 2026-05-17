<?php
declare(strict_types=1);

/**
 * Eenmalig setup-script: seedt de cards-tabel.
 *
 * Gebruik (in browser):
 *   /setup.php?token=<APP_SECRET>
 *
 * VERWIJDER DIT BESTAND NA GEBRUIK.
 */

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../sql/seed_cards.php';

$cfg = Db::config();
$token = $_GET['token'] ?? '';

if (!is_string($token) || !hash_equals((string)$cfg['app_secret'], $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Verboden. Geef ?token=APP_SECRET mee.\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

try {
    $totals = run_seed();
    echo "Seed klaar.\n";
    foreach ($totals as $deck => $n) {
        echo "- $deck: $n kaarten\n";
    }
    echo "\nBELANGRIJK: verwijder dit bestand (public/setup.php) nu het werk gedaan is.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "FOUT: " . $e->getMessage() . "\n";
    if (!empty($cfg['debug'])) {
        echo $e->getTraceAsString() . "\n";
    }
}
