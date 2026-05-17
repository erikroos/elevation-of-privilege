<h1>Elevation of Privilege</h1>
<p class="lead">
  Een serious card game voor threat modeling. Geschikt voor cursussen
  cybersecurity en privacy. Kies een deck, deel de spelcode met je studenten
  en speel direct in de browser — niets te installeren.
</p>

<?php if (!empty($error)): ?>
  <div class="error"><?= e($error) ?></div>
<?php endif; ?>

<div class="cards-row">
  <section class="card">
    <h2>Nieuw spel starten</h2>
    <p>Jij wordt facilitator. Spelers joinen straks via de spelcode.</p>
    <form method="post" action="/games">
      <?= Csrf::field() ?>
      <label>
        Jouw naam (facilitator)
        <input type="text" name="nickname" maxlength="40" required autofocus>
      </label>

      <fieldset>
        <legend>Welk deck?</legend>
        <label class="radio">
          <input type="radio" name="deck" value="stride" checked>
          <strong>STRIDE</strong> — Elevation of Privilege.
          Klassiek voor security threat modeling
          (Spoofing, Tampering, Repudiation, Information disclosure,
          Denial of service, Elevation of privilege).
        </label>
        <label class="radio">
          <input type="radio" name="deck" value="linddun">
          <strong>LINDDUN</strong> — LINDDUN GO.
          Voor privacy threat modeling
          (Linking, Identifying, Non-repudiation, Detecting,
          Data disclosure, Unawareness, Non-compliance).
        </label>
      </fieldset>

      <button type="submit" class="primary">Spel aanmaken</button>
    </form>
  </section>

  <section class="card">
    <h2>Meedoen aan een spel</h2>
    <p>Je facilitator heeft je een 6-cijferige spelcode gegeven.</p>
    <a href="/join" class="button">Naar het join-scherm</a>
  </section>
</div>
