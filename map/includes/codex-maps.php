<?php
declare(strict_types=1);

const MAP_CODEX_PIN_LIMIT = 100;

function mapCodexPinPosition(mixed $value): ?float
{
    if ((!is_string($value) && !is_float($value) && !is_int($value)) || !is_numeric($value)) {
        return null;
    }
    $position = (float) $value;
    return $position >= 0 && $position <= 100 ? round($position, 2) : null;
}

function mapCodexApplyMapPins(PDO $pdo, int $campaignId, string $category, stdClass $details, array $form, ?int $entryId = null): void
{
    if ($category !== 'Cartes' || !isset($form['editMapPins'])) {
        return;
    }
    $labels = $form['pinLabel'] ?? [];
    $positionsX = $form['pinX'] ?? [];
    $positionsY = $form['pinY'] ?? [];
    $targets = $form['pinEntry'] ?? [];
    if (!is_array($labels) || !is_array($positionsX) || !is_array($positionsY) || !is_array($targets)
        || count($labels) > MAP_CODEX_PIN_LIMIT || count($labels) !== count($positionsX) || count($labels) !== count($positionsY)
        || count($labels) !== count($targets)) {
        throw new DomainException('La liste des repères de carte est invalide.');
    }

    $pins = [];
    $targetExists = $pdo->prepare('SELECT 1 FROM campaign_codex_entries WHERE campaign_id = ? AND id = ?');
    foreach ($labels as $index => $value) {
        $label = mapCodexText($value, 180, 'Le nom du repère', true);
        $x = mapCodexPinPosition($positionsX[$index] ?? null);
        $y = mapCodexPinPosition($positionsY[$index] ?? null);
        if ($x === null || $y === null) {
            throw new DomainException('Les coordonnées d’un repère doivent être comprises entre 0 et 100 %.');
        }
        $pin = ['label' => $label, 'x' => $x, 'y' => $y];
        $target = $targets[$index] ?? '';
        if ($target !== '') {
            $targetId = mapValidatePositiveInt($target, 'Fiche liée au repère');
            $targetExists->execute([$campaignId, $targetId]);
            $found = $targetExists->fetchColumn() !== false;
            $targetExists->closeCursor();
            if (!$found || $targetId === $entryId) {
                throw new DomainException('Choisissez une autre fiche de cette campagne pour ce repère.');
            }
            $pin['target_id'] = $targetId;
        }
        $pins[] = $pin;
    }

    if ($pins === []) {
        unset($details->codex_pins);
        return;
    }
    $details->codex_pins = $pins;
}

function mapRenderCodexMap(?string $imageUrl, string $title, mixed $pins, array $entries, callable $url): void
{
    if ($imageUrl === null) {
        ?><p class="muted">Ajoutez une image à cette fiche pour afficher la carte et ses repères.</p><?php
        return;
    }
    $markers = [];
    foreach (is_array($pins) ? $pins : [] as $pin) {
        $pin = (array) $pin;
        $x = mapCodexPinPosition($pin['x'] ?? null);
        $y = mapCodexPinPosition($pin['y'] ?? null);
        if ($x !== null && $y !== null && is_string($pin['label'] ?? null) && $pin['label'] !== '') {
            $target = filter_var($pin['target_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $markers[] = [
                'x' => $x, 'y' => $y, 'label' => $pin['label'],
                'link' => $target !== false && isset($entries[$target]) ? $url(['fiche' => $target]) : null,
                'target' => $target !== false ? ($entries[$target]['title'] ?? null) : null,
            ];
        }
    }
    ?>
    <div class="codex-map">
      <div class="codex-map-toolbar">
        <a href="<?= mapEscape($imageUrl) ?>" target="_blank" rel="noopener">Ouvrir l’image originale</a>
      </div>
      <div class="codex-map-picture">
        <img src="<?= mapEscape($imageUrl) ?>" alt="<?= mapEscape($title) ?>">
        <?php foreach ($markers as $index => $pin): ?>
          <a class="codex-map-pin" href="<?= mapEscape($pin['link'] ?? '#codex-map-pin-' . $index) ?>"
            style="--pin-x:<?= $pin['x'] ?>%;--pin-y:<?= $pin['y'] ?>%" aria-label="<?= mapEscape($pin['label']) ?>"
            title="<?= mapEscape($pin['label']) ?>"><?= $index + 1 ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($markers !== []): ?>
        <ol class="codex-map-legend">
          <?php foreach ($markers as $index => $pin): ?>
            <li id="codex-map-pin-<?= $index ?>">
              <?php if ($pin['link'] === null): ?>
                <?= mapEscape($pin['label']) ?>
              <?php else: ?>
                <a href="<?= mapEscape($pin['link']) ?>"><?= mapEscape($pin['label']) ?></a>
                <?php if ($pin['target'] !== $pin['label']): ?><small><?= mapEscape($pin['target']) ?></small><?php endif; ?>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </div>
    <?php
}

function mapRenderCodexMapEditor(string $category, ?string $imageUrl, mixed $pins, array $entries, ?int $entryId, array $form = []): void
{
    $renderPin = static function (array $pin) use ($entries, $entryId): void {
        $label = is_string($pin['label'] ?? null) ? $pin['label'] : '';
        $x = is_scalar($pin['x'] ?? null) ? (string) $pin['x'] : '50';
        $y = is_scalar($pin['y'] ?? null) ? (string) $pin['y'] : '50';
        $target = filter_var($pin['target_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        ?>
        <div class="codex-map-pin-fields" data-map-pin-row>
          <label class="field-group">Nom du repère
            <input name="pinLabel[]" value="<?= mapEscape($label) ?>" required maxlength="180">
          </label>
          <label class="field-group">Fiche liée
            <select name="pinEntry[]">
              <option value="">Aucune</option>
              <?php foreach ($entries as $id => $entry): if ((int) $id === $entryId) continue; ?>
                <option value="<?= (int) $id ?>" <?= (int) $id === $target ? 'selected' : '' ?>>
                  <?= mapEscape($entry['title'] . ' · ' . $entry['category']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field-group">Horizontal (%)
            <input type="number" name="pinX[]" min="0" max="100" step="any" value="<?= mapEscape($x) ?>" required>
          </label>
          <label class="field-group">Vertical (%)
            <input type="number" name="pinY[]" min="0" max="100" step="any" value="<?= mapEscape($y) ?>" required>
          </label>
          <button class="button button-secondary button-small" type="button" data-map-remove-pin>Retirer</button>
        </div>
        <?php
    };
    if (isset($form['editMapPins'])) {
        $pins = [];
        foreach ((array) ($form['pinLabel'] ?? []) as $index => $label) {
            $pins[] = [
                'label' => $label, 'x' => $form['pinX'][$index] ?? '', 'y' => $form['pinY'][$index] ?? '',
                'target_id' => $form['pinEntry'][$index] ?? null,
            ];
        }
    }
    $active = $category === 'Cartes';
    $hasPreview = $imageUrl !== null && !isset($form['removeImage']);
    ?>
    <fieldset class="codex-fieldset codex-map-editor" data-codex-map-editor data-map-limit="<?= MAP_CODEX_PIN_LIMIT ?>"
      <?= $active ? '' : 'hidden disabled' ?>>
      <legend>Repères de carte</legend>
      <input type="hidden" name="editMapPins" value="1">
      <div class="codex-map-picture codex-map-editor-picture" data-map-preview <?= $hasPreview ? '' : 'hidden' ?>>
        <img <?= $imageUrl === null ? '' : 'src="' . mapEscape($imageUrl) . '"' ?> alt="Aperçu de la carte">
        <div class="codex-map-live-pins" data-map-live-pins aria-hidden="true"></div>
      </div>
      <div class="codex-map-toolbar">
        <button class="button button-secondary button-small" type="button" data-map-place aria-pressed="false" <?= $hasPreview ? '' : 'hidden' ?>>Placer sur la carte</button>
        <button class="button button-secondary button-small" type="button" data-map-add-pin>Ajouter avec des coordonnées</button>
      </div>
      <p class="muted" data-map-status aria-live="polite"></p>
      <div data-map-pins>
        <?php foreach (is_array($pins) ? $pins : [] as $pin) $renderPin((array) $pin); ?>
      </div>
      <template data-map-pin-template><?php $renderPin([]); ?></template>
    </fieldset>
    <?php
}
