<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diffusion musicale</title>
    <link rel="stylesheet" href="styles.css?v=diffusion-1">
  </head>
  <body>
    <main class="diffusion-shell">
      <section class="diffusion-panel" aria-live="polite">
        <div class="brand diffusion-brand">
          <div class="brand-mark" aria-hidden="true">â™ª</div>
          <div>
            <h1>SuSZic</h1>
            <p>Diffusion en direct</p>
          </div>
        </div>

        <div class="diffusion-status">
          <span id="diffusion-live-dot" class="broadcast-dot" aria-hidden="true"></span>
          <strong id="diffusion-status-text">Diffusion hors ligne</strong>
        </div>

        <div class="diffusion-now">
          <p class="eyebrow">En cours</p>
          <h2 id="diffusion-title">Aucune musique</h2>
          <span id="diffusion-time">00:00</span>
        </div>

        <div class="diffusion-controls">
          <button id="diffusion-play" type="button" class="secondary-button">Ecouter</button>
          <label class="diffusion-volume" for="diffusion-volume">
            <span>Volume</span>
            <input id="diffusion-volume" type="range" min="0" max="1" step="0.01" value="0.8">
          </label>
        </div>
      </section>
    </main>

    <script src="theme.js?v=1"></script>
    <script src="diffusion.js?v=diffusion-1"></script>
  </body>
</html>
