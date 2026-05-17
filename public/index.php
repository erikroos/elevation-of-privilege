<?php
declare(strict_types=1);

/**
 * Front controller. Alle requests komen hier binnen via .htaccess rewrite.
 * Routing is een simpele match-tabel — geen framework.
 */

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/../src/Game.php';
require_once __DIR__ . '/../src/Player.php';
require_once __DIR__ . '/../src/Hand.php';
require_once __DIR__ . '/../src/Trick.php';
require_once __DIR__ . '/../src/Threat.php';
require_once __DIR__ . '/../src/Event.php';
require_once __DIR__ . '/../src/Upload.php';

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $cfg = Db::config();
    Session::start();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path   = '/' . trim($path, '/');

    // ---- Routing ------------------------------------------------------------
    // GET  /                          → home
    // GET  /join                      → join-form
    // POST /join                      → join verwerken
    // POST /games                     → nieuw spel (facilitator)
    // GET  /games/{code}              → lobby
    // POST /games/{code}/diagram      → diagram upload
    // POST /games/{code}/start        → spel starten
    // GET  /play/{code}               → tafel
    // GET  /about                     → attributie

    if ($path === '/' && $method === 'GET') {
        View::render('home', ['title' => 'Elevation of Privilege']);
        return;
    }

    if ($path === '/about' && $method === 'GET') {
        View::render('about', ['title' => 'Over dit spel']);
        return;
    }

    // ---- Long-poll endpoint ------------------------------------------------
    // GET /api/state?code=NNNNNN&since=<version>
    // Geeft direct terug als state_version > since, anders wacht max
    // poll_max_wait seconden. JSON: { code, status, version }.
    // Geen sessie (zou andere requests van dezelfde browser blokkeren).
    if ($path === '/api/state' && $method === 'GET') {
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $code        = isset($_GET['code'])         ? preg_replace('/\D/', '', (string)$_GET['code']) : '';
        $since       = isset($_GET['since'])        ? max(0, (int)$_GET['since']) : -1;
        $eventSince  = isset($_GET['event_since'])  ? max(0, (int)$_GET['event_since']) : 0;
        if (!preg_match('/^\d{6}$/', $code)) {
            http_response_code(400);
            echo '{"error":"bad code"}';
            return;
        }
        $maxWait = (int)($cfg['poll_max_wait'] ?? 10);
        $deadline = microtime(true) + max(1, $maxWait);
        $stmt = Db::pdo()->prepare(
            'SELECT id, state_version, status FROM games WHERE code = ?'
        );
        while (true) {
            $stmt->execute([$code]);
            $row = $stmt->fetch();
            if (!$row) {
                http_response_code(404);
                echo '{"error":"not found"}';
                return;
            }
            $v = (int)$row['state_version'];
            $latestEventId = Event::latestId((int)$row['id']);
            if ($v > $since || $latestEventId > $eventSince || microtime(true) >= $deadline) {
                $events = Event::since((int)$row['id'], $eventSince, 20);
                echo json_encode([
                    'code'    => $code,
                    'status'  => $row['status'],
                    'version' => $v,
                    'events'  => $events,
                ]);
                return;
            }
            usleep(500_000); // 0,5s polling-tick
            if (connection_aborted()) return;
        }
    }

    if ($path === '/join' && $method === 'GET') {
        $code = isset($_GET['code']) ? strtoupper(preg_replace('/[^0-9]/', '', (string)$_GET['code'])) : '';
        View::render('join', ['title' => 'Meedoen aan een spel', 'code' => $code]);
        return;
    }

    if ($path === '/join' && $method === 'POST') {
        Csrf::check();
        $code = strtoupper(preg_replace('/[^0-9]/', '', (string)($_POST['code'] ?? '')));
        $nick = trim((string)($_POST['nickname'] ?? ''));
        if ($code === '' || $nick === '') {
            View::render('join', [
                'title' => 'Meedoen',
                'code'  => $code,
                'error' => 'Vul zowel een spelcode als een naam in.',
            ]);
            return;
        }
        if (mb_strlen($nick) > 40) {
            View::render('join', [
                'title' => 'Meedoen', 'code' => $code,
                'error' => 'Naam is te lang (max 40 tekens).',
            ]);
            return;
        }
        $game = Game::findByCode($code);
        if (!$game) {
            View::render('join', [
                'title' => 'Meedoen', 'code' => $code,
                'error' => 'Geen spel gevonden met deze code.',
            ]);
            return;
        }
        if ($game['status'] !== 'lobby') {
            View::render('join', [
                'title' => 'Meedoen', 'code' => $code,
                'error' => 'Dit spel is al gestart; je kunt niet meer instappen.',
            ]);
            return;
        }
        try {
            $player = Player::create((int)$game['id'], $nick, isFacilitator: false);
        } catch (RuntimeException $e) {
            View::render('join', [
                'title' => 'Meedoen', 'code' => $code,
                'error' => $e->getMessage(),
            ]);
            return;
        }
        Session::setPlayerToken($player['session_token']);
        header('Location: /games/' . $game['code']);
        return;
    }

    if ($path === '/games' && $method === 'POST') {
        Csrf::check();
        $deck = $_POST['deck'] ?? '';
        $nick = trim((string)($_POST['nickname'] ?? ''));
        if (!in_array($deck, ['stride', 'linddun'], true)) {
            View::render('home', ['title' => 'Elevation of Privilege', 'error' => 'Kies een geldig deck.']);
            return;
        }
        if ($nick === '') {
            View::render('home', ['title' => 'Elevation of Privilege', 'error' => 'Vul je naam in als facilitator.']);
            return;
        }
        $game   = Game::create($deck);
        $player = Player::create((int)$game['id'], $nick, isFacilitator: true);
        Session::setPlayerToken($player['session_token']);
        header('Location: /games/' . $game['code']);
        return;
    }

    if (preg_match('#^/games/(\d{6})/threats\.(csv|md)$#', $path, $m) && $method === 'GET') {
        $game = Game::findByCode($m[1]);
        $format = $m[2];
        if (!$game) {
            http_response_code(404);
            View::render('error', ['title' => 'Niet gevonden', 'message' => 'Spel niet gevonden.']);
            return;
        }
        $me = Player::currentForGame((int)$game['id']);
        if (!$me) {
            http_response_code(403);
            View::render('error', ['title' => 'Niet toegestaan', 'message' => 'Alleen spelers in dit spel kunnen exporteren.']);
            return;
        }
        $threats = Threat::listForGame((int)$game['id']);
        $filename = "threats-{$game['code']}.{$format}";
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo Threat::toCsv($threats);
        } else {
            $deckCfg = json_decode(
                (string)file_get_contents(__DIR__ . '/../data/cards_' . $game['deck_type'] . '.json'),
                true
            );
            header('Content-Type: text/markdown; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo Threat::toMarkdown($threats, $game, $deckCfg);
        }
        return;
    }

    if (preg_match('#^/games/(\d{6})/start$#', $path, $m) && $method === 'POST') {
        Csrf::check();
        $game = Game::findByCode($m[1]);
        if (!$game) {
            http_response_code(404);
            View::render('error', ['title' => 'Niet gevonden', 'message' => 'Spel niet gevonden.']);
            return;
        }
        $me = Player::currentForGame((int)$game['id']);
        if (!$me || (int)$me['is_facilitator'] !== 1) {
            http_response_code(403);
            View::render('error', ['title' => 'Niet toegestaan', 'message' => 'Alleen de facilitator kan het spel starten.']);
            return;
        }
        try {
            Game::start((int)$game['id']);
        } catch (RuntimeException $e) {
            $players = Player::listForGame((int)$game['id']);
            View::render('lobby', [
                'title'   => "Lobby {$game['code']}",
                'game'    => $game,
                'me'      => $me,
                'players' => $players,
                'error'   => $e->getMessage(),
            ]);
            return;
        }
        header('Location: /games/' . $game['code']);
        return;
    }

    if (preg_match('#^/games/(\d{6})/play$#', $path, $m) && $method === 'POST') {
        Csrf::check();
        $game = Game::findByCode($m[1]);
        if (!$game) {
            http_response_code(404);
            View::render('error', ['title' => 'Niet gevonden', 'message' => 'Spel niet gevonden.']);
            return;
        }
        $me = Player::currentForGame((int)$game['id']);
        if (!$me) {
            http_response_code(403);
            View::render('error', ['title' => 'Niet toegestaan', 'message' => 'Je zit niet in dit spel.']);
            return;
        }
        $cardId = (int)($_POST['card_id'] ?? 0);
        $threat = isset($_POST['threat']) ? (string)$_POST['threat'] : null;
        try {
            Trick::playCard((int)$game['id'], (int)$me['id'], $cardId, $threat);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        header('Location: /games/' . $game['code']);
        return;
    }

    if (preg_match('#^/games/(\d{6})/diagram$#', $path, $m) && $method === 'POST') {
        Csrf::check();
        $game = Game::findByCode($m[1]);
        if (!$game) {
            http_response_code(404);
            View::render('error', ['title' => 'Niet gevonden', 'message' => 'Spel niet gevonden.']);
            return;
        }
        $me = Player::currentForGame((int)$game['id']);
        if (!$me || (int)$me['is_facilitator'] !== 1) {
            http_response_code(403);
            View::render('error', ['title' => 'Niet toegestaan', 'message' => 'Alleen de facilitator kan een diagram uploaden.']);
            return;
        }
        if ($game['status'] !== 'lobby') {
            http_response_code(409);
            View::render('error', ['title' => 'Spel gestart', 'message' => 'Het diagram kan niet meer worden gewijzigd zodra het spel loopt.']);
            return;
        }
        try {
            $action = $_POST['action'] ?? 'upload';
            if ($action === 'remove') {
                Upload::deleteIfExists($game['diagram_path'] ?? null);
                Game::clearDiagram((int)$game['id']);
            } else {
                $result = Upload::handleDiagram($_FILES['diagram'] ?? []);
                Upload::deleteIfExists($game['diagram_path'] ?? null);
                Game::setDiagram((int)$game['id'], $result['path'], $result['mime']);
            }
        } catch (RuntimeException $e) {
            $players = Player::listForGame((int)$game['id']);
            $game = Game::findByCode($m[1]); // refresh
            View::render('lobby', [
                'title'   => "Lobby {$game['code']}",
                'game'    => $game,
                'me'      => $me,
                'players' => $players,
                'error'   => $e->getMessage(),
            ]);
            return;
        }
        header('Location: /games/' . $game['code']);
        return;
    }

    if (preg_match('#^/games/(\d{6})$#', $path, $m) && $method === 'GET') {
        $game = Game::findByCode($m[1]);
        if (!$game) {
            http_response_code(404);
            View::render('error', ['title' => 'Niet gevonden', 'message' => 'Spel niet gevonden.']);
            return;
        }
        $me = Player::currentForGame((int)$game['id']);
        $players = Player::listForGame((int)$game['id']);

        if ($game['status'] === 'lobby') {
            View::render('lobby', [
                'title'   => "Lobby {$game['code']}",
                'game'    => $game,
                'me'      => $me,
                'players' => $players,
            ]);
            return;
        }

        // playing / finished
        $trick     = Trick::current((int)$game['id']);
        $plays     = $trick ? Trick::plays((int)$trick['id']) : [];
        $hand      = $me ? Hand::forPlayer((int)$me['id']) : [];
        $threats   = Threat::listForGame((int)$game['id']);
        $flash     = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        View::render('play', [
            'title'   => "Tafel {$game['code']}",
            'game'    => $game,
            'me'      => $me,
            'players' => $players,
            'trick'   => $trick,
            'plays'   => $plays,
            'hand'    => $hand,
            'threats' => $threats,
            'error'   => $flash,
        ]);
        return;
    }

    http_response_code(404);
    View::render('error', ['title' => 'Niet gevonden', 'message' => 'Pagina niet gevonden: ' . htmlspecialchars($path)]);
} catch (Throwable $e) {
    http_response_code(500);
    $cfg = Db::config();
    $msg = !empty($cfg['debug'])
        ? $e->getMessage() . "\n\n" . $e->getTraceAsString()
        : 'Er ging iets mis. Probeer het later opnieuw.';
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
}
