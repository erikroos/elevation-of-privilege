<?php
$isFacilitator = $me !== null && (int)$me['is_facilitator'] === 1;
$isMyTurn      = $trick && $me && (int)$trick['current_player_id'] === (int)$me['id'];
$gameStatus    = $game['status'];
$deckName      = $game['deck_type'] === 'stride' ? 'STRIDE' : 'LINDDUN GO';

// suit-icoon lookup uit deck-config
$deckCfg = json_decode(
    (string)file_get_contents(__DIR__ . '/../../data/cards_' . $game['deck_type'] . '.json'),
    true
);
$suitIcon = [];
$suitName = [];
foreach ($deckCfg['suits'] as $s) {
    $suitIcon[$s['key']] = $s['icon'];
    $suitName[$s['key']] = $s['name'];
}
$trumpSuit = (string)$game['trump_suit'];

// vind huidige speler-info
$current = null;
foreach ($players as $p) {
    if ($trick && (int)$p['id'] === (int)$trick['current_player_id']) { $current = $p; break; }
}

// helper: render een kaart
$renderCard = function (array $c, bool $clickable = false, ?string $extraClass = null) use ($suitIcon, $trumpSuit) {
    $isTrump = $c['suit_key'] === $trumpSuit;
    $classes = 'card-mini suit-' . htmlspecialchars($c['suit_key']);
    if ($isTrump)   $classes .= ' is-trump';
    if ($clickable) $classes .= ' clickable';
    if ($extraClass) $classes .= ' ' . $extraClass;
    $icon = $suitIcon[$c['suit_key']] ?? '';
    return [$classes, $icon];
};
?>

<h1>Tafel <span class="muted">— deck <?= e($deckName) ?></span></h1>

<?php if (!empty($error)): ?>
  <div class="error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($gameStatus === 'finished'): ?>
  <section class="card">
    <h2>Spel afgelopen</h2>
    <p>Alle kaarten zijn gespeeld. Eindscore:</p>
    <ol class="scoreboard">
      <?php
        $sorted = $players;
        usort($sorted, fn ($a, $b) => (int)$b['score'] <=> (int)$a['score']);
        foreach ($sorted as $p):
      ?>
        <li>
          <span class="player-name"><?= e($p['nickname']) ?></span>
          <span class="player-score"><?= (int)$p['score'] ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
<?php endif; ?>

<div class="play-grid">

  <section class="card">
    <h2>Huidige ronde</h2>
    <?php if ($trick): ?>
      <p class="muted">
        Trick #<?= (int)$trick['trick_number'] ?>
        <?php if ($trick['lead_suit']): ?>
          · Geleid: <strong><?= e($suitName[$trick['lead_suit']] ?? $trick['lead_suit']) ?></strong>
          <?= e($suitIcon[$trick['lead_suit']] ?? '') ?>
        <?php endif; ?>
        · Troef: <strong><?= e($suitName[$trumpSuit] ?? $trumpSuit) ?></strong>
        <?= e($suitIcon[$trumpSuit] ?? '') ?>
      </p>
      <?php if ($current): ?>
        <p>
          <?php if ($isMyTurn): ?>
            <strong>Het is jouw beurt.</strong> Kies een kaart uit je hand.
          <?php else: ?>
            Aan zet: <strong><?= e($current['nickname']) ?></strong>
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <div class="table-cards">
        <?php foreach ($plays as $p): ?>
          <?php [$cls, $icon] = $renderCard($p); ?>
          <div class="<?= $cls ?>">
            <div class="card-mini-head">
              <span class="card-rank"><?= e($p['rank_code']) ?></span>
              <span class="card-suit"><?= $icon ?></span>
            </div>
            <div class="card-mini-title"><?= e($p['title']) ?></div>
            <div class="card-mini-body"><?= e($p['description']) ?></div>
            <div class="card-mini-foot">
              <?= e($p['nickname']) ?>
              <?php if ($p['threat_description']): ?>
                <span class="badge">+1 threat</span>
              <?php endif; ?>
            </div>
            <?php if ($p['threat_description']): ?>
              <div class="threat-note"><em>“<?= e($p['threat_description']) ?>”</em></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted">Geen actieve trick.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Score</h2>
    <ul class="scoreboard">
      <?php foreach ($players as $p): ?>
        <li>
          <span class="player-name">
            <?= e($p['nickname']) ?>
            <?php if ((int)$p['is_facilitator'] === 1): ?>
              <span class="badge">facilitator</span>
            <?php endif; ?>
            <?php if ($me && (int)$p['id'] === (int)$me['id']): ?>
              <span class="badge badge-self">jij</span>
            <?php endif; ?>
            <?php if ($trick && (int)$p['id'] === (int)$trick['current_player_id']): ?>
              <span class="badge badge-turn">aan zet</span>
            <?php endif; ?>
          </span>
          <span class="player-score"><?= (int)$p['score'] ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($me && $gameStatus === 'playing'): ?>
    <section class="card play-hand">
      <h2>Jouw hand (<?= count($hand) ?>)</h2>
      <?php if (!$hand): ?>
        <p class="muted">Geen kaarten meer.</p>
      <?php else: ?>
        <form id="play-form" method="post" action="/games/<?= e($game['code']) ?>/play" class="hand-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="card_id" id="card_id" value="">
          <div class="hand-cards">
            <?php foreach ($hand as $c): ?>
              <?php [$cls, $icon] = $renderCard($c, clickable: $isMyTurn); ?>
              <button type="button"
                      class="<?= $cls ?>"
                      data-card-id="<?= (int)$c['card_id'] ?>"
                      <?= $isMyTurn ? '' : 'disabled' ?>>
                <div class="card-mini-head">
                  <span class="card-rank"><?= e($c['rank_code']) ?></span>
                  <span class="card-suit"><?= $icon ?></span>
                </div>
                <div class="card-mini-title"><?= e($c['title']) ?></div>
                <div class="card-mini-body"><?= e($c['description']) ?></div>
              </button>
            <?php endforeach; ?>
          </div>
          <div id="play-threat" class="threat-form" hidden>
            <label>
              Beschrijf de threat in jouw systeem (optioneel, +1 punt als ingevuld):
              <textarea name="threat" rows="3" placeholder="Bijv. 'De API accepteert ongeauthenticeerde requests op /admin'"></textarea>
            </label>
            <div class="threat-buttons">
              <button type="submit" class="primary">Speel kaart</button>
              <button type="button" id="cancel-play" class="secondary">Annuleren</button>
            </div>
          </div>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if (!empty($game['diagram_path'])): ?>
    <section class="card play-diagram">
      <details<?= $gameStatus === 'finished' ? '' : ' open' ?>>
        <summary>Systeemdiagram</summary>
        <?php
          $url  = '/' . ltrim($game['diagram_path'], '/');
          $mime = (string)($game['diagram_mime'] ?? '');
        ?>
        <?php if ($mime === 'application/pdf'): ?>
          <iframe class="diagram-preview" src="<?= e($url) ?>" title="Diagram"></iframe>
        <?php else: ?>
          <img class="diagram-preview" src="<?= e($url) ?>" alt="Systeemdiagram">
        <?php endif; ?>
      </details>
    </section>
  <?php endif; ?>

  <?php if (!empty($threats) || $gameStatus === 'finished'): ?>
    <section class="card play-threats">
      <details<?= $gameStatus === 'finished' ? ' open' : '' ?>>
        <summary>
          Threat-log
          <span class="muted">(<?= count($threats ?? []) ?>)</span>
          <?php if ($me && !empty($threats)): ?>
            <span class="export-links">
              <a href="/games/<?= e($game['code']) ?>/threats.csv">CSV</a>
              ·
              <a href="/games/<?= e($game['code']) ?>/threats.md">Markdown</a>
            </span>
          <?php endif; ?>
        </summary>
        <?php
          $grouped = [];
          foreach (($threats ?? []) as $t) $grouped[$t['suit_key']][] = $t;
        ?>
        <?php if (!$grouped): ?>
          <p class="muted">Nog geen threats gelogd.</p>
        <?php endif; ?>
        <?php foreach ($grouped as $suitKey => $items): ?>
          <h3 class="threat-suit">
            <?= e($suitIcon[$suitKey] ?? '') ?>
            <?= e($suitName[$suitKey] ?? $suitKey) ?>
            <span class="muted">(<?= count($items) ?>)</span>
          </h3>
          <ul class="threat-list">
            <?php foreach ($items as $t): ?>
              <li>
                <strong><?= e($t['suit_name']) ?> <?= e($t['rank_code']) ?></strong>
                — <?= e($t['player']) ?>
                <span class="muted"><?= e($t['created_at']) ?></span>
                <div class="threat-quote">“<?= e($t['description']) ?>”</div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endforeach; ?>
      </details>
    </section>
  <?php endif; ?>

</div>

<script>
(function () {
  const form   = document.getElementById('play-form');
  if (!form) return;
  const cardId = document.getElementById('card_id');
  const panel  = document.getElementById('play-threat');
  const cancel = document.getElementById('cancel-play');

  form.querySelectorAll('button.card-mini').forEach(btn => {
    if (btn.disabled) return;
    btn.addEventListener('click', () => {
      form.querySelectorAll('button.card-mini').forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');
      cardId.value = btn.dataset.cardId;
      panel.hidden = false;
      panel.querySelector('textarea').focus();
    });
  });
  cancel.addEventListener('click', () => {
    panel.hidden = true;
    cardId.value = '';
    form.querySelectorAll('button.card-mini').forEach(b => b.classList.remove('selected'));
  });
})();
</script>
