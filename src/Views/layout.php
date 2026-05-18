<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title ?? 'Elevation of Privilege') ?></title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body<?php if (!empty($game['code'])): ?>
       data-game-code="<?= e($game['code']) ?>"
       data-state-version="<?= (int)($game['state_version'] ?? 0) ?>"
       data-event-since="<?= (int)Event::latestId((int)$game['id']) ?>"<?php endif; ?>>
  <div id="toast-stack" class="toast-stack" aria-live="polite"></div>
  <header class="topbar">
    <a href="/" class="brand">Elevation of Privilege</a>
    <nav>
      <a href="/join">Meedoen</a>
      <a href="/about">Over</a>
    </nav>
  </header>

  <main class="container">
    <?= $content ?>
  </main>

  <footer class="footer">
    <p>
      Gebaseerd op
      <a href="https://github.com/adamshostack/eop" rel="noopener" target="_blank">Elevation of Privilege</a>
      (Adam Shostack / Microsoft, CC BY 3.0) en
      <a href="https://linddun.org/go/" rel="noopener" target="_blank">LINDDUN GO</a>
      (DistriNet KU Leuven, CC BY 4.0).
      Deze implementatie is beschikbaar onder
      <a href="https://creativecommons.org/licenses/by/4.0/" rel="noopener" target="_blank">CC BY 4.0</a>.
      Volledige attributie: <a href="/about">/about</a>.
    </p>
    <p>
      Broncode op <a href="https://github.com/erikroos/elevation-of-privilege" rel="noopener" target="_blank">GitHub</a> —
      <a href="https://github.com/erikroos/elevation-of-privilege/issues/new" rel="noopener" target="_blank">bug melden</a> ·
      <a href="https://github.com/erikroos/elevation-of-privilege/pulls" rel="noopener" target="_blank">pull request indienen</a>.
    </p>
  </footer>
  <?php if (!empty($game['code'])): ?>
    <script src="/assets/js/poll.js" defer></script>
  <?php endif; ?>
</body>
</html>
