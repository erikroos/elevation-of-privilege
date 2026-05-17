<h1>Meedoen aan een spel</h1>

<?php if (!empty($error)): ?>
  <div class="error"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" action="/join" class="card form-narrow">
  <?= Csrf::field() ?>
  <label>
    Spelcode (6 cijfers)
    <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}"
           maxlength="6" value="<?= e($code ?? '') ?>" required autofocus>
  </label>
  <label>
    Jouw naam
    <input type="text" name="nickname" maxlength="40" required>
  </label>
  <button type="submit" class="primary">Meedoen</button>
</form>
