<?php
declare(strict_types=1);
require __DIR__ . '/../lib/app.php';

$setup = needs_setup();
$authed = !$setup && is_authed();
$v = fn(string $f) => $f . '?v=' . filemtime(__DIR__ . '/' . $f);
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en" data-theme="dusk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#1a1614">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Sleep Tight</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<link rel="stylesheet" href="<?= $v('assets/app.css') ?>">
<script>try { document.documentElement.dataset.theme = localStorage.theme || 'dusk' } catch (e) {}</script>
</head>
<body>

<!-- TEMP: design direction picker, removed once a direction is chosen -->
<div class="themes" id="themes">
  <button data-t="dusk">A · Dusk</button>
  <button data-t="nocturne">B · Nocturne</button>
  <button data-t="graphite">C · Graphite</button>
</div>

<?php if (!$authed): ?>
<main class="auth">
  <form id="auth" data-mode="<?= $setup ? 'setup' : 'login' ?>" class="card">
    <h1>Sleep Tight</h1>
    <p class="muted"><?= $setup ? 'First run: choose a password (min. 8 characters).' : 'Enter your password.' ?></p>
    <input type="password" id="pw" autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" placeholder="Password" required autofocus>
    <button class="primary"><?= $setup ? 'Set password' : 'Log in' ?></button>
    <p class="error" id="authErr" role="alert"></p>
  </form>
</main>
<?php else: ?>

<main id="view-log">
  <header class="datebar">
    <button id="prev" class="icon" aria-label="Previous night">‹</button>
    <label class="datebtn">
      <span id="dateTitle"></span>
      <small id="dateSub"></small>
      <input type="date" id="datePick" aria-label="Pick date">
    </label>
    <button id="next" class="icon" aria-label="Next night">›</button>
  </header>

  <section class="card">
    <div class="row">
      <h2>Sleep</h2>
      <output class="big" id="hoursOut"></output>
    </div>
    <div class="slide">
      <button class="step" data-step="hours" data-d="-0.25" aria-label="15 minutes less">−</button>
      <input type="range" id="hours" min="0" max="14" step="0.25" aria-label="Hours of sleep">
      <button class="step" data-step="hours" data-d="0.25" aria-label="15 minutes more">+</button>
    </div>
  </section>

  <section class="card">
    <div class="row">
      <h2>Quality</h2>
      <output class="big" id="qualityOut"></output>
    </div>
    <div class="slide">
      <button class="step" data-step="quality" data-d="-5" aria-label="5 percent less">−</button>
      <input type="range" id="quality" min="1" max="100" step="1" aria-label="Perceived sleep quality">
      <button class="step" data-step="quality" data-d="5" aria-label="5 percent more">+</button>
    </div>
  </section>

  <section class="card">
    <div class="row">
      <h2>Breaks</h2>
      <div class="counter">
        <button class="step" data-step="breaks" data-d="-1" aria-label="One break less">−</button>
        <output class="big" id="breaksOut"></output>
        <button class="step" data-step="breaks" data-d="1" aria-label="One break more">+</button>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Clothing</h2><div class="chips" data-group="clothing"></div>
    <h2>Day before</h2><div class="chips" data-group="day"></div>
    <h2>Evening before</h2><div class="chips" data-group="evening"></div>
    <h2>Specifics</h2><div class="chips" data-group="specifics"></div>
  </section>

  <section class="card">
    <h2><label for="note">Note</label></h2>
    <textarea id="note" rows="2" maxlength="2000" placeholder="Anything else…"></textarea>
  </section>

  <button id="del" class="link danger" hidden>Delete this night</button>
</main>

<main id="view-stats" hidden>
  <header class="datebar"><h1>Stats</h1></header>

  <section class="tiles" id="tiles"></section>

  <section class="card">
    <div class="row">
      <h2>Nights</h2>
      <div class="seg" id="range">
        <button data-n="14">14d</button><button data-n="30">30d</button><button data-n="90">90d</button>
      </div>
    </div>
    <div class="readout" id="readout"></div>
    <div id="chart"></div>
  </section>

  <section class="card">
    <h2>What moves your quality</h2>
    <p class="muted small">Average quality on nights with a tag vs. nights without it, in points.</p>
    <div id="impact"></div>
  </section>

  <section class="card more">
    <a class="link" href="api.php?a=export">Export CSV</a>
    <button class="link" id="showToken">Sync token</button>
    <button class="link" id="logout">Log out</button>
    <code id="token" hidden></code>
  </section>
</main>

<div class="bottom">
  <button id="save" class="primary">Save</button>
  <nav class="tabs">
    <button data-view="log" class="on">Log</button>
    <button data-view="stats">Stats</button>
  </nav>
</div>
<?php endif ?>

<script>window.TAGS = <?= json_encode(TAGS) ?>;</script>
<script src="<?= $v('assets/app.js') ?>"></script>
</body>
</html>
