(() => {
  window.createBoardTurns = ({root, context, action, selectedEntity, entityImageUrl}) => {
    const toggle = root.querySelector('#board-turn-toggle');
    const panel = root.querySelector('#board-turn-panel');
    const body = root.querySelector('#board-turn-body');
    const settings = root.querySelector('#board-turn-settings');
    const minimize = panel.querySelector('[data-turn-minimize]');
    const settingsButton = panel.querySelector('[data-turn-settings]');
    const management = panel.querySelector('[data-turn-management]');
    const list = panel.querySelector('[data-turn-list]');
    const count = panel.querySelector('[data-turn-count]');
    const empty = panel.querySelector('[data-turn-empty]');
    const error = panel.querySelector('[data-turn-error]');
    const commands = [...panel.querySelectorAll('[data-turn-command]')];
    const sorts = [...settings.querySelectorAll('[data-turn-sort]')];
    const auto = settings.querySelector('[data-turn-auto]');
    const custom = settings.querySelector('[data-turn-custom]');
    const clear = settings.querySelector('[data-turn-clear]');
    let rendered = '';
    let pending = false;

    function showSettings(open) {
      settings.hidden = !open;
      settingsButton.setAttribute('aria-expanded',String(open));
    }
    function minimizePanel(collapsed) {
      body.hidden = collapsed;
      minimize.textContent = collapsed ? '+' : '−';
      minimize.setAttribute('aria-expanded',String(!collapsed));
      minimize.setAttribute('aria-label',collapsed ? 'Développer l’ordre des tours' : 'Réduire l’ordre des tours');
      if (collapsed) showSettings(false);
    }
    function show(open) {
      panel.hidden = !open;
      toggle.setAttribute('aria-expanded',String(open));
      if (open) minimizePanel(false);
      else showSettings(false);
    }
    toggle.addEventListener('click',() => show(panel.hidden));
    panel.querySelector('[data-turn-close]').addEventListener('click',() => { show(false); toggle.focus(); });
    minimize.addEventListener('click',() => minimizePanel(!body.hidden));
    settingsButton.addEventListener('click',() => showSettings(settings.hidden));
    settings.querySelector('[data-turn-close-settings]').addEventListener('click',() => {
      showSettings(false);
      settingsButton.focus();
    });

    async function send(command, payload = {}) {
      const target = context();
      if (!target || pending) return false;
      error.textContent = '';
      pending = true;
      try {
        await action(command,{pageId:target.page.id,...payload});
        return true;
      } catch (failure) {
        error.textContent = failure.message;
        return false;
      } finally {
        pending = false;
      }
    }
    async function add(entity) {
      show(true);
      await send('turn_add_entity',{entityId:entity.id,layer:entity.layer});
    }
    document.addEventListener('keydown',event => {
      if (event.defaultPrevented || !event.ctrlKey || event.altKey || event.metaKey || event.shiftKey
        || event.key.toLowerCase() !== 'u' || event.target.closest('input, textarea, select, dialog, [contenteditable]')) return;
      const entity = selectedEntity();
      if (!entity || !context()) return;
      event.preventDefault();
      if (!event.repeat) add(entity);
    });
    for (const button of commands) button.addEventListener('click',() => send(button.dataset.turnCommand));
    for (const button of sorts) button.addEventListener('click',() => send('turn_sort',{mode:button.dataset.turnSort}));
    auto.addEventListener('change',async () => {
      if (!await send('turn_auto_sort',{enabled:auto.checked})) auto.checked = !!context()?.page.turns?.autoSort;
    });
    custom.addEventListener('submit',async event => {
      event.preventDefault();
      const {label,increment} = custom.elements;
      if (await send('turn_add_custom',{label:label.value,increment:increment.valueAsNumber || 0})) custom.reset();
    });
    clear.addEventListener('click',() => {
      if (window.confirm('Effacer tous les tours de cette page ?')) send('turn_clear');
    });

    function renderEntry(entry, active, canManage) {
      const row = document.createElement('li');
      row.dataset.turnEntry = entry.id;
      if (active) row.setAttribute('aria-current','step');
      if (entry.hasImage) {
        const image = document.createElement('img');
        image.alt = '';
        image.src = entityImageUrl(entry.entityId);
        row.append(image);
      }
      const name = document.createElement('span');
      name.className = 'board-turn-name';
      name.textContent = entry.name;
      row.append(name);
      if (entry.canEdit) {
        const score = document.createElement('input');
        score.type = 'number';
        score.step = '.01';
        score.value = entry.initiative;
        score.setAttribute('aria-label','Initiative de '+entry.name);
        score.addEventListener('change',async () => {
          if (!Number.isFinite(score.valueAsNumber)
            || !await send('turn_update',{entryId:entry.id,initiative:score.valueAsNumber})) {
            score.value = entry.initiative;
          }
        });
        score.addEventListener('keydown',event => {
          if (event.key === 'Enter') { event.preventDefault(); score.blur(); }
        });
        row.append(score);
      } else {
        const score = document.createElement('strong');
        score.textContent = entry.initiative;
        row.append(score);
      }
      if (canManage) {
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = '×';
        remove.setAttribute('aria-label','Retirer '+entry.name+' de l’ordre des tours');
        remove.addEventListener('click',() => send('turn_remove',{entryId:entry.id}));
        row.append(remove);
      }
      return row;
    }

    function refresh(page, canManage) {
      const turns = page.turns;
      if (!turns) return;
      const signature = JSON.stringify([page.id,canManage,turns]);
      if (signature === rendered) return;
      rendered = signature;
      management.hidden = !canManage;
      if (!canManage) showSettings(false);
      for (const command of commands) command.disabled = turns.entries.length === 0;
      for (const sort of sorts) sort.setAttribute('aria-pressed',String(sort.dataset.turnSort === turns.sortMode));
      auto.checked = turns.autoSort;
      clear.disabled = turns.entries.length === 0;
      empty.hidden = turns.entries.length !== 0;
      count.textContent = turns.entries.length + (turns.entries.length === 1 ? ' entrée' : ' entrées');
      list.replaceChildren(...turns.entries.map(entry => renderEntry(entry,entry.id === turns.activeId,canManage)));
    }

    return {add,refresh};
  };
})();
