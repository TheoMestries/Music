(() => {
  const characterSheet = document.querySelector('[data-codex-character-sheet]');
  if (characterSheet && window.MapCharacterSheets) {
    window.MapCharacterSheets.open({
      container: characterSheet,
      windows: window.MapWindows,
      entryId: Number(characterSheet.dataset.entryId),
      campaignId: Number(characterSheet.dataset.campaignId),
      apiUrl: characterSheet.dataset.apiUrl,
      csrfToken: characterSheet.dataset.csrfToken,
    });
  }

  const rail = document.querySelector('[data-codex-rail]');
  if (rail) {
    const compactRail = window.matchMedia('(max-width: 760px)');
    const updateRail = () => { rail.open = !compactRail.matches; };
    updateRail();
    compactRail.addEventListener('change', updateRail);
  }

  const tabs = document.querySelector('.codex-tabs');
  const currentTab = tabs?.querySelector('[aria-current="page"]');
  if (currentTab) {
    const strip = tabs.getBoundingClientRect();
    const active = currentTab.getBoundingClientRect();
    if (active.right > strip.right) tabs.scrollLeft += active.right - strip.right + 8;
  }

  const notes = document.getElementById('codex-notes');
  if (notes) {
    const openLinkedNote = () => {
      const anchor = window.location.hash.slice(1);
      if (!anchor.startsWith('codex-note-')) return;
      const card = document.getElementById(anchor);
      const story = card?.querySelector('.codex-story');
      if (story) story.open = true;
    };
    openLinkedNote();
    window.addEventListener('hashchange', openLinkedNote);
    const status = notes.querySelector('[data-note-status]');
    document.addEventListener('click', async (event) => {
      const copy = event.target.closest('[data-copy-note-link]');
      for (const menu of notes.querySelectorAll('.codex-note-menu[open]')) {
        if (!menu.contains(event.target)) menu.open = false;
      }
      if (!copy || !navigator.clipboard?.writeText) return;
      event.preventDefault();
      try {
        await navigator.clipboard.writeText(copy.href);
        const menu = copy.closest('.codex-note-menu');
        menu.open = false;
        menu.querySelector('summary').focus();
        status.textContent = 'Lien de la note copié.';
      } catch {
        window.location.assign(copy.href);
      }
    });
    notes.addEventListener('keydown', (event) => {
      const menu = event.target.closest('.codex-note-menu[open]');
      if (event.key !== 'Escape' || !menu) return;
      menu.open = false;
      menu.querySelector('summary').focus();
    });
  }

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-add-row], [data-remove-row], [data-move-row]');
    if (!button) return;
    if (button.hasAttribute('data-remove-row')) {
      button.closest('[data-edit-row]').remove();
      return;
    }
    if (button.hasAttribute('data-move-row')) {
      const row = button.closest('[data-edit-row]');
      const sibling = button.dataset.moveRow === '-1' ? row.previousElementSibling : row.nextElementSibling;
      if (sibling) {
        if (button.dataset.moveRow === '-1') sibling.before(row);
        else sibling.after(row);
        button.focus();
      }
      return;
    }
    const template = document.getElementById(button.dataset.addRow);
    const rows = document.getElementById(button.dataset.rows);
    const fragment = template.content.cloneNode(true);
    if (rows.hasAttribute('data-next-row')) {
      for (const field of fragment.querySelectorAll('[name]')) {
        field.name = field.name.replace('__row__', rows.dataset.nextRow);
      }
      rows.dataset.nextRow = String(Number(rows.dataset.nextRow) + 1);
    }
    rows.append(fragment);
    rows.lastElementChild.querySelector('input:not([type=hidden]), select, textarea').focus();
  });

  document.querySelector('[data-delete-entry]')?.addEventListener('submit', (event) => {
    if (!window.confirm('Supprimer cette fiche du codex ?')) event.preventDefault();
  });

  const editor = document.querySelector('[data-codex-editor]');
  if (editor) {
    const category = editor.elements.namedItem('category');
    const moduleFields = JSON.parse(editor.dataset.moduleFields);
    const fieldPanel = editor.querySelector('[data-module-panel]');
    const inputs = editor.querySelector('[data-module-inputs]');
    const remembered = new Map();
    let active = category.value.trim();
    category.addEventListener('change', () => {
      const selected = category.value.trim();
      if (active === selected) return;
      for (const input of inputs.querySelectorAll('[data-field]')) remembered.set(input.dataset.field, input.value);
      for (const row of editor.querySelectorAll('#codex-fields [data-edit-row]')) {
        const name = row.querySelector('input').value.trim();
        if (!remembered.has(name)) remembered.set(name, row.querySelector('textarea').value);
      }
      const definitions = Object.hasOwn(moduleFields, selected) ? Object.entries(moduleFields[selected]) : [];
      inputs.replaceChildren();
      for (const [key, label] of definitions) {
        const field = document.createElement('label');
        field.className = 'field-group';
        field.textContent = label;
        const input = document.createElement('input');
        input.name = `moduleFields[${key}]`;
        input.dataset.field = key;
        input.maxLength = 5000;
        input.value = remembered.get(key) ?? '';
        field.append(input);
        inputs.append(field);
      }
      fieldPanel.hidden = definitions.length === 0;
      active = selected;
    });
  }

  const rich = document.querySelector('[data-codex-rich]');
  if (rich && typeof document.execCommand === 'function') {
    const fallback = rich.querySelector('textarea');
    const content = rich.querySelector('[role=textbox]');
    const controls = rich.querySelector('[data-formatting]');
    const insertMention = rich.querySelector('[data-mention-insert]');
    const output = document.createElement('input');
    output.type = 'hidden';
    output.name = 'body_html';
    rich.append(output);
    fallback.hidden = true;
    content.contentEditable = 'true';
    content.hidden = false;
    controls.hidden = false;
    if (insertMention) insertMention.dataset.mentionTarget = content.id;

    controls.addEventListener('pointerdown', (event) => event.preventDefault());
    controls.addEventListener('click', (event) => {
      const button = event.target.closest('[data-format]');
      if (!button) return;
      content.focus();
      document.execCommand(button.dataset.format, false, button.dataset.value || null);
    });
    content.addEventListener('paste', (event) => {
      event.preventDefault();
      document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') || '');
    });
    content.addEventListener('drop', (event) => event.preventDefault());
    editor.addEventListener('submit', () => {
      output.value = window.mapCodexSerializeMentions?.(content) ?? content.innerHTML;
      fallback.disabled = true;
    });
  }

  const form = document.getElementById('codex-import-form');
  if (!form) return;
  const input = document.getElementById('codex-archive');
  const progress = document.getElementById('codex-import-progress');
  const status = document.getElementById('codex-import-status');
  const submit = form.querySelector('button[type=submit]');
  submit.disabled = false;

  async function request(action, params, body = null) {
    const url = new URL(form.dataset.endpoint, document.baseURI);
    url.searchParams.set('action', action);
    for (const [key, value] of Object.entries(params)) url.searchParams.set(key, value);
    const response = await fetch(url, {
      method: 'POST',
      headers: {'X-CSRF-Token': form.dataset.token},
      body,
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) {
      throw new Error(payload?.error || 'Le serveur n’a pas pu traiter cette étape de l’import.');
    }
    return payload.data;
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const archive = input.files[0];
    if (!archive || !archive.name.toLowerCase().endsWith('.zip') || !archive.size || archive.size > Number(form.dataset.max)) {
      status.textContent = 'Choisissez une archive ZIP de 768 Mio maximum.';
      return;
    }

    submit.disabled = true;
    input.disabled = true;
    progress.hidden = false;
    progress.value = 0;
    status.textContent = 'Envoi de l’archive…';
    try {
      const { token, chunkBytes } = await request('start', { size: archive.size });
      for (let offset = 0; offset < archive.size; offset += chunkBytes) {
        const end = Math.min(offset + chunkBytes, archive.size);
        await request('append', { token, offset }, archive.slice(offset, end, 'application/octet-stream'));
        progress.value = Math.floor(end * 100 / archive.size);
        status.textContent = `Envoi de l’archive… ${progress.value} %`;
      }
      progress.removeAttribute('value');
      status.textContent = 'Import des fiches, des relations et des images…';
      const { created, skipped, missingImages } = await request('finish', { token });
      const added = created > 1 ? `${created} fiches ajoutées` : `${created} fiche ajoutée`;
      const existing = skipped > 1 ? `${skipped} fiches déjà présentes` : `${skipped} fiche déjà présente`;
      const warning = missingImages > 0 ? ` Illustrations manquantes ou illisibles (${missingImages}).` : '';
      status.textContent = `Import terminé. ${added}, ${existing}.${warning}`;
      input.value = '';
    } catch (error) {
      status.textContent = error.message || 'L’import a échoué.';
    } finally {
      progress.hidden = true;
      submit.disabled = false;
      input.disabled = false;
    }
  });
})();
