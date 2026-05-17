<?php
$deckName = $game['deck_type'] === 'stride' ? 'STRIDE (Elevation of Privilege)' : 'LINDDUN GO';
$isFacilitator = $me !== null && (int)$me['is_facilitator'] === 1;
$hasDiagram = !empty($game['diagram_path']);
?>

<h1>Lobby</h1>

<?php if (!empty($error)): ?>
  <div class="error"><?= e($error) ?></div>
<?php endif; ?>

<div class="lobby-grid">
  <section class="card">
    <?php if ($isFacilitator): ?>
      <h2>Spelcode</h2>
      <p class="game-code"><?= e($game['code']) ?></p>
      <p>Deel deze code met je spelers. Ze kunnen joinen via
         <code><?= e(($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') ?>://<?= e($_SERVER['HTTP_HOST'] ?? '') ?>/join</code>.
      </p>
    <?php else: ?>
      <h2>Wachten op start</h2>
      <p>Je zit in de lobby. De facilitator start het spel zodra iedereen er is.</p>
    <?php endif; ?>
    <p><strong>Deck:</strong> <?= e($deckName) ?></p>
  </section>

  <section class="card">
    <h2>Spelers (<?= count($players) ?>)</h2>
    <ul class="player-list">
      <?php foreach ($players as $p): ?>
        <li>
          <?= e($p['nickname']) ?>
          <?php if ((int)$p['is_facilitator'] === 1): ?>
            <span class="badge">facilitator</span>
          <?php endif; ?>
          <?php if ($me && (int)$p['id'] === (int)$me['id']): ?>
            <span class="badge badge-self">jij</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="muted">Deze lijst ververst automatisch zodra er iemand bijkomt.</p>
  </section>

  <section class="card lobby-diagram">
    <h2>Systeemdiagram</h2>
    <?php if ($hasDiagram): ?>
      <?php
        $url  = '/' . ltrim($game['diagram_path'], '/');
        $mime = (string)($game['diagram_mime'] ?? '');
      ?>
      <?php if ($mime === 'application/pdf'): ?>
        <iframe class="diagram-preview" src="<?= e($url) ?>"
                title="Geüpload diagram (PDF)"></iframe>
        <p><a href="<?= e($url) ?>" target="_blank" rel="noopener">PDF in nieuw tabblad</a></p>
      <?php else: ?>
        <img class="diagram-preview" src="<?= e($url) ?>"
             alt="Geüpload systeemdiagram">
      <?php endif; ?>

      <?php if ($isFacilitator): ?>
        <form method="post" action="/games/<?= e($game['code']) ?>/diagram"
              enctype="multipart/form-data" class="stacked-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="upload">
          <label>
            Vervang door nieuw bestand
            <input type="file" name="diagram"
                   accept="image/png,image/jpeg,image/svg+xml,application/pdf" required>
          </label>
          <button type="submit" class="primary">Vervangen</button>
        </form>
        <form method="post" action="/games/<?= e($game['code']) ?>/diagram"
              onsubmit="return confirm('Diagram verwijderen?');" class="stacked-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="remove">
          <button type="submit" class="secondary">Verwijderen</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <?php if ($isFacilitator): ?>
        <p>Upload een PNG, JPG, SVG of PDF (max 10 MB) van het systeem dat je
           gaat modelleren. Spelers zien dit straks aan tafel.</p>
        <form method="post" action="/games/<?= e($game['code']) ?>/diagram"
              enctype="multipart/form-data" class="stacked-form">
          <?= Csrf::field() ?>
          <label>
            Diagram-bestand
            <input type="file" name="diagram"
                   accept="image/png,image/jpeg,image/svg+xml,application/pdf" required>
          </label>
          <button type="submit" class="primary">Uploaden</button>
        </form>
      <?php else: ?>
        <p class="muted">De facilitator heeft nog geen diagram geüpload.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <?php if ($isFacilitator): ?>
    <section class="card">
      <h2>Facilitator-acties</h2>
      <p>Klaar? Klik op start en het spel begint. Minimaal 2 spelers nodig.</p>
      <form method="post" action="/games/<?= e($game['code']) ?>/start">
        <?= Csrf::field() ?>
        <button type="submit" class="primary"<?= count($players) < 2 ? ' disabled' : '' ?>>
          Spel starten
        </button>
      </form>
    </section>
  <?php endif; ?>

  <?php if (!$me): ?>
    <section class="card">
      <h2>Nog niet ingelogd in dit spel?</h2>
      <p>Je bekijkt deze lobby als gast.
         <a href="/join?code=<?= e($game['code']) ?>">Doe mee als speler</a>.
      </p>
    </section>
  <?php endif; ?>
</div>
