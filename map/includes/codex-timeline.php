<?php
declare(strict_types=1);

function mapCodexApplyTimeline(PDO $pdo, int $campaignId, string $category, ?int $entryId, stdClass $details, array $form): void
{
    if ($category !== 'Chronologies' || ($form['editTimeline'] ?? null) !== '1') {
        return;
    }
    $rows = $form['timeline'] ?? [];
    if (!is_array($rows) || count($rows) > 120) {
        throw new DomainException('Une chronologie peut contenir jusqu’à 120 événements.');
    }
    $lookup = $pdo->prepare('SELECT 1 FROM campaign_codex_entries WHERE campaign_id = ? AND id = ?');
    $events = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new DomainException('Événement invalide.');
        }
        $era = mapCodexText($row['era'] ?? '', 150, 'L’ère');
        $date = mapCodexText($row['date'] ?? '', 100, 'La date');
        $title = mapCodexText($row['title'] ?? '', 250, 'Le titre de l’événement');
        $description = mapCodexText($row['description'] ?? '', 10000, 'La description');
        $target = $row['target'] ?? '';
        if ($title === '' && $era === '' && $date === '' && $description === '' && $target === '') {
            continue;
        }
        if ($title === '') {
            throw new DomainException('Chaque événement doit avoir un titre.');
        }
        $targetId = null;
        if ($target !== '') {
            $targetId = mapValidatePositiveInt($target, 'Fiche liée');
            $lookup->execute([$campaignId, $targetId]);
            $available = (bool) $lookup->fetchColumn();
            $lookup->closeCursor();
            if (!$available || $targetId === $entryId) {
                throw new DomainException('La fiche liée à cet événement est indisponible.');
            }
        }
        $events[] = ['era' => $era, 'date' => $date, 'title' => $title, 'description' => $description, 'target' => $targetId];
    }
    $details->codex_timeline = $events;
}

function mapRenderCodexTimelineEditor(stdClass $details, array $entries, ?int $entryId, ?array $submitted): void
{
    $events = $submitted['timeline'] ?? ($details->codex_timeline ?? []);
    $rows = is_array($events) ? array_values($events) : [];
    $renderRow = static function (array $row, string $index) use ($entries, $entryId): void {
        $value = static fn (string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
        ?>
        <div class="codex-post-fields" data-edit-row>
          <div class="codex-form-grid">
            <label class="field-group">Ère<input name="timeline[<?= $index ?>][era]" maxlength="150" value="<?= mapEscape($value('era')) ?>"></label>
            <label class="field-group">Date<input name="timeline[<?= $index ?>][date]" maxlength="100" value="<?= mapEscape($value('date')) ?>"></label>
          </div>
          <label class="field-group">Titre<input name="timeline[<?= $index ?>][title]" maxlength="250" value="<?= mapEscape($value('title')) ?>"></label>
          <label class="field-group">Description<textarea name="timeline[<?= $index ?>][description]" maxlength="10000" rows="3"><?= mapEscape($value('description')) ?></textarea></label>
          <label class="field-group">Fiche liée<select name="timeline[<?= $index ?>][target]"><option value="">Aucune</option>
            <?php foreach ($entries as $id => $entry): if ($id === $entryId) continue; ?>
              <option value="<?= $id ?>" <?= $value('target') === (string) $id ? 'selected' : '' ?>><?= mapEscape($entry['title'] . ' · ' . $entry['category']) ?></option>
            <?php endforeach; ?>
          </select></label>
          <div class="button-row">
            <button type="button" class="button button-secondary button-small" data-move-row="-1">Remonter</button>
            <button type="button" class="button button-secondary button-small" data-move-row="1">Descendre</button>
            <button type="button" class="button button-secondary button-small" data-remove-row>Retirer</button>
          </div>
        </div>
        <?php
    };
    ?>
    <input type="hidden" name="editTimeline" value="1">
    <div id="codex-timeline-rows" data-next-row="<?= count($rows) ?>">
      <?php foreach ($rows as $index => $row) $renderRow((array) $row, (string) $index); ?>
    </div>
    <template id="codex-timeline-row"><?php $renderRow([], '__row__'); ?></template>
    <button type="button" class="button button-secondary button-small" data-add-row="codex-timeline-row" data-rows="codex-timeline-rows">Ajouter un événement</button>
    <?php
}

function mapRenderCodexTimeline(stdClass $details, array $entries, callable $url): void
{
    $eras = [];
    foreach (is_array($details->codex_timeline ?? null) ? $details->codex_timeline : [] as $event) {
        if ($event instanceof stdClass && is_string($event->title ?? null)) {
            $era = is_string($event->era ?? null) ? $event->era : '';
            $eras[$era][] = $event;
        }
    }
    if ($eras === []) {
        echo '<p class="muted">Aucun événement dans cette chronologie.</p>';
        return;
    }
    foreach ($eras as $era => $events): ?>
      <section class="codex-era">
        <?php if ($era !== ''): ?><h3><?= mapEscape((string) $era) ?></h3><?php endif; ?>
        <ol class="codex-timeline">
        <?php foreach ($events as $event):
          $target = is_int($event->target ?? null) ? ($entries[$event->target] ?? null) : null;
          ?>
          <li>
            <small><?= mapEscape(is_string($event->date ?? null) ? $event->date : '') ?></small>
            <h4><?= mapEscape($event->title) ?></h4>
            <?php if (is_string($event->description ?? null) && $event->description !== ''): ?><p class="codex-body"><?= mapEscape($event->description) ?></p><?php endif; ?>
            <?php if ($target !== null): ?><a href="<?= mapEscape($url(['fiche' => $event->target])) ?>"><?= mapEscape($target['title']) ?></a><?php endif; ?>
          </li>
        <?php endforeach; ?>
        </ol>
      </section>
    <?php endforeach;
}
