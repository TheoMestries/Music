<?php
declare(strict_types=1);

function mapCodexCalendarDate(string $value): ?array
{
    if (!preg_match('/^(-?\d{1,6})-(\d{1,2})-(\d{1,3})$/D', trim($value), $parts)) {
        return null;
    }
    return [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
}

function mapCodexCalendarLength(mixed $value): int|false
{
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 400]]);
}

function mapCodexCalendarContains(stdClass $calendar, array $date): bool
{
    $months = is_array($calendar->months ?? null) ? $calendar->months : [];
    $month = $months[$date[1] - 1] ?? null;
    $length = mapCodexCalendarLength($month->length ?? null);
    return $length !== false && $date[2] >= 1 && $date[2] <= $length
        && ($date[0] !== 0 || empty($calendar->skip_year_zero));
}

function mapCodexCalendarReminders(PDO $pdo, int $campaignId, int $calendarId): array
{
    $query = $pdo->prepare("SELECT entry.id, reminder.value FROM campaign_codex_entries entry
        JOIN json_each(entry.details, '$.reminders') reminder
        WHERE entry.campaign_id = ? AND CASE WHEN reminder.type = 'object'
        THEN CAST(json_extract(reminder.value, '$.codex_entry_id') AS INTEGER) END = ?
        ORDER BY entry.title COLLATE NOCASE");
    $query->bindValue(1, $campaignId, PDO::PARAM_INT);
    $query->bindValue(2, $calendarId, PDO::PARAM_INT);
    $query->execute();
    $reminders = [];
    foreach ($query->fetchAll() as $row) {
        $reminder = json_decode($row['value'], false, 32, JSON_THROW_ON_ERROR);
        $date = is_string($reminder->date ?? null) ? mapCodexCalendarDate($reminder->date) : null;
        if ($date !== null && is_string($reminder->name ?? null) && empty($reminder->done)) {
            $reminders[implode('/', $date)][] = ['title' => $reminder->name, 'entry' => (int) $row['id']]
                + array_intersect_key((array) $reminder, array_flip([
                    'visibility_id', 'visibilityId', 'visibility', 'is_private', 'isPrivate', 'is_hidden', 'isHidden',
                    'private', 'hidden', 'deleted_at', 'codex_element_key', 'codex_element_private',
                ]));
        }
    }
    return $reminders;
}

function mapCodexApplyCalendar(stdClass $details, array $form): void
{
    if (!isset($form['codexCalendarSubmitted'])) {
        return;
    }
    $calendar = $form['codexCalendar'] ?? null;
    if ($form['codexCalendarSubmitted'] !== '1' || !is_array($calendar)) {
        throw new DomainException('Calendrier incomplet.');
    }
    foreach (['monthName', 'monthLength', 'monthOriginal', 'weekday'] as $key) {
        if (!is_array($calendar[$key] ?? null)) {
            throw new DomainException('Indiquez au moins un mois et un jour de la semaine.');
        }
    }
    $names = $calendar['monthName'];
    $weekdays = $calendar['weekday'];
    if (count($names) < 1 || count($names) > 40 || count($weekdays) < 1 || count($weekdays) > 16) {
        throw new DomainException('Le calendrier accepte de 1 à 40 mois et de 1 à 16 jours par semaine.');
    }
    $previous = is_array($details->months ?? null) ? $details->months : [];
    $months = [];
    foreach ($names as $index => $value) {
        $label = mapCodexText($value, 100, 'Le nom du mois', true);
        $length = mapCodexCalendarLength($calendar['monthLength'][$index] ?? null);
        if ($length === false) {
            throw new DomainException('Chaque mois doit avoir de 1 à 400 jours.');
        }
        $original = filter_var($calendar['monthOriginal'][$index] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $existing = $original === false ? null : ($previous[$original] ?? null);
        $month = $existing instanceof stdClass ? clone $existing : new stdClass();
        $month->name = $label;
        $month->length = $length;
        $months[] = $month;
    }
    $weekdays = array_map(static fn (mixed $name): string => mapCodexText($name, 100, 'Le nom du jour', true), $weekdays);
    $current = mapCodexText($calendar['date'] ?? '', 30, 'La date actuelle');
    if ($current !== '') {
        $date = mapCodexCalendarDate($current);
        if ($date === null || !isset($months[$date[1] - 1]) || $date[2] < 1 || $date[2] > $months[$date[1] - 1]->length
            || ($date[0] === 0 && !empty($details->skip_year_zero))) {
            throw new DomainException('La date actuelle doit correspondre aux mois et aux jours du calendrier.');
        }
    }
    $details->months = $months;
    $details->weekdays = array_values($weekdays);
    if ($current === '') {
        unset($details->date);
    } else {
        $details->date = $current;
    }
}

function mapRenderCodexCalendarEditor(stdClass $details, ?array $submitted = null): void
{
    $calendar = $submitted['codexCalendar'] ?? null;
    $monthRows = [];
    if (is_array($calendar)) {
        foreach ((array) ($calendar['monthName'] ?? []) as $index => $name) {
            $monthRows[] = [$name, $calendar['monthLength'][$index] ?? '', $calendar['monthOriginal'][$index] ?? ''];
        }
        $weekdays = (array) ($calendar['weekday'] ?? []);
        $date = $calendar['date'] ?? '';
    } else {
        foreach (is_array($details->months ?? null) ? $details->months : [] as $index => $month) {
            if ($month instanceof stdClass) {
                $monthRows[] = [$month->name ?? '', $month->length ?? '', (string) $index];
            }
        }
        $weekdays = is_array($details->weekdays ?? null) ? $details->weekdays : [];
        $date = $details->date ?? '';
    }
    $asText = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '';
    $renderMonth = static function (mixed $name = '', mixed $length = '30', mixed $original = '') use ($asText): void { ?>
      <div data-edit-row class="codex-link-fields">
        <input type="hidden" name="codexCalendar[monthOriginal][]" value="<?= mapEscape($asText($original)) ?>">
        <label>Nom du mois<input name="codexCalendar[monthName][]" value="<?= mapEscape($asText($name)) ?>" maxlength="100" required></label>
        <label>Nombre de jours<input type="number" name="codexCalendar[monthLength][]" value="<?= mapEscape($asText($length)) ?>" min="1" max="400" required></label>
        <button type="button" class="button button-secondary button-small" data-remove-row>Retirer</button>
      </div>
    <?php };
    $renderWeekday = static function (mixed $day = '') use ($asText): void { ?>
      <div data-edit-row class="codex-calendar-day-edit">
        <label class="field-group">Nom du jour<input name="codexCalendar[weekday][]" value="<?= mapEscape($asText($day)) ?>" maxlength="100" required></label>
        <button type="button" class="button button-secondary button-small" data-remove-row>Retirer</button>
      </div>
    <?php }; ?>
    <fieldset class="codex-fieldset" id="section-calendrier">
      <legend>Calendrier</legend>
      <label class="field-group">Date actuelle<input name="codexCalendar[date]" value="<?= mapEscape($asText($date)) ?>" maxlength="30" placeholder="AAAA-MM-JJ"></label>
      <h3>Mois</h3>
      <div id="codex-calendar-months"><?php foreach ($monthRows as $row) $renderMonth(...$row); ?></div>
      <template id="codex-calendar-month"><?php $renderMonth(); ?></template>
      <button type="button" class="button button-secondary button-small" data-add-row="codex-calendar-month" data-rows="codex-calendar-months">Ajouter un mois</button>
      <h3>Jours de la semaine</h3>
      <div id="codex-calendar-weekdays"><?php foreach ($weekdays as $day) $renderWeekday($day); ?></div>
      <template id="codex-calendar-weekday"><?php $renderWeekday(); ?></template>
      <button type="button" class="button button-secondary button-small" data-add-row="codex-calendar-weekday" data-rows="codex-calendar-weekdays">Ajouter un jour</button>
      <input type="hidden" name="codexCalendarSubmitted" value="1">
    </fieldset>
    <?php
}

function mapRenderCodexCalendar(
    stdClass $details,
    array $query,
    callable $calendarUrl,
    array $reminders = [],
    ?callable $reminderUrl = null,
): void
{
    $months = [];
    foreach (is_array($details->months ?? null) ? $details->months : [] as $month) {
        if ($month instanceof stdClass && is_string($month->name ?? null)
            && ($length = mapCodexCalendarLength($month->length ?? null)) !== false) {
            $months[] = ['name' => $month->name, 'length' => $length];
        }
    }
    $weekdays = is_array($details->weekdays ?? null) ? array_values(array_filter($details->weekdays, 'is_string')) : [];
    if (!$months || count($months) > 40 || !$weekdays || count($weekdays) > 16) {
        echo '<p class="muted">Ajoutez des mois et des jours de la semaine pour afficher le calendrier.</p>';
        return;
    }
    $date = is_string($details->date ?? null) ? mapCodexCalendarDate($details->date) : null;
    $year = filter_var($query['annee'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => -999999, 'max_range' => 999999]]);
    if ($year === false || ($year === 0 && !empty($details->skip_year_zero))) {
        $year = $date[0] ?? 1;
    }
    $month = filter_var($query['mois'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => count($months)]]);
    if ($month === false) {
        $month = min(count($months), max(1, $date[1] ?? 1));
    }
    $previous = $month === 1 ? [$year - 1, count($months)] : [$year, $month - 1];
    $next = $month === count($months) ? [$year + 1, 1] : [$year, $month + 1];
    if (!empty($details->skip_year_zero)) {
        $previous[0] = $previous[0] === 0 ? -1 : $previous[0];
        $next[0] = $next[0] === 0 ? 1 : $next[0];
    }
    $monthRecord = $months[$month - 1];
    $weekLength = count($weekdays);
    $yearLength = array_sum(array_column($months, 'length'));
    $daysInPreviousMonths = array_sum(array_column(array_slice($months, 0, $month - 1), 'length'));
    $yearCount = $year - 1 + ($year < 0 && !empty($details->skip_year_zero) ? 1 : 0);
    $offset = (int) ($details->start_offset ?? 0);
    $before = ($yearCount * $yearLength + $daysInPreviousMonths + $offset) % $weekLength;
    $before = ($before + $weekLength) % $weekLength;
    $rowCount = (int) ceil(($before + $monthRecord['length']) / $weekLength);
    $currentUrl = $calendarUrl($year, $month);
    parse_str((string) parse_url($currentUrl, PHP_URL_QUERY), $navigationQuery);
    unset($navigationQuery['annee'], $navigationQuery['mois']);
    $suffix = is_string($details->suffix ?? null) ? ' ' . $details->suffix : '';
    ?>
    <div class="codex-calendar-heading">
      <h3><?= mapEscape($monthRecord['name'] . ' ' . $year . $suffix) ?></h3>
      <nav class="codex-pagination" aria-label="Navigation du calendrier">
        <?php if ($previous[0] >= -999999): ?><a href="<?= mapEscape($calendarUrl(...$previous)) ?>">← Mois précédent</a><?php endif; ?>
        <?php if ($next[0] <= 999999): ?><a href="<?= mapEscape($calendarUrl(...$next)) ?>">Mois suivant →</a><?php endif; ?>
      </nav>
      <form method="get" action="<?= mapEscape((string) parse_url($currentUrl, PHP_URL_PATH)) ?>" class="codex-calendar-jump">
        <?php foreach ($navigationQuery as $key => $value): ?>
          <?php if (is_string($value)): ?><input type="hidden" name="<?= mapEscape((string) $key) ?>" value="<?= mapEscape($value) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <label>Mois <select name="mois">
          <?php foreach ($months as $index => $row): ?>
            <option value="<?= $index + 1 ?>" <?= $index + 1 === $month ? 'selected' : '' ?>><?= mapEscape($row['name']) ?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Année <input name="annee" type="number" min="-999999" max="999999" value="<?= $year ?>" required></label>
        <button class="button button-secondary button-small">Afficher</button>
      </form>
    </div>
    <div class="codex-calendar-scroll" tabindex="0" aria-label="Jours du mois">
      <table class="codex-calendar">
        <thead><tr><?php foreach ($weekdays as $weekday): ?><th scope="col"><?= mapEscape($weekday) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php for ($row = 0; $row < $rowCount; $row++): ?><tr>
            <?php for ($column = 0; $column < $weekLength; $column++):
                $day = $row * $weekLength + $column - $before + 1;
                $inMonth = $day > 0 && $day <= $monthRecord['length'];
                $current = $inMonth && $date === [$year, $month, $day];
            ?>
              <td<?= $current ? ' aria-current="date"' : '' ?>><?= $inMonth ? $day : '' ?>
                <?php if ($inMonth && $reminderUrl !== null): foreach ($reminders[$year . '/' . $month . '/' . $day] ?? [] as $reminder): ?>
                  <a class="codex-calendar-reminder" href="<?= mapEscape($reminderUrl($reminder['entry'])) ?>"><?= mapEscape($reminder['title']) ?></a>
                <?php endforeach; endif; ?>
              </td>
            <?php endfor; ?>
          </tr><?php endfor; ?>
        </tbody>
      </table>
    </div>
    <?php
}
