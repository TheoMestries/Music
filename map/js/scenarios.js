(() => {
  'use strict';

  const workspace = document.querySelector('#scenario-editor');
  if (!workspace) return;

  const data = JSON.parse(document.querySelector('#scenario-data').textContent);
  const title = workspace.querySelector('#scenario-title');
  const status = workspace.querySelector('[data-scenario-status]');
  const list = workspace.querySelector('[data-scenario-blocks]');
  const readLink = workspace.querySelector('[data-scenario-read]');
  const template = document.querySelector('#scenario-block-template');
  const sidebarTitle = document.querySelector('[data-scenario-current]');
  const normalize = text => text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
  const entries = data.entries.map(entry => ({ ...entry, search: normalize(entry.title) }));
  const entriesById = new Map(entries.map(entry => [entry.id, entry]));
  let revision = data.revision;
  let edits = 0;
  let savedEdits = 0;
  let saving = false;
  let conflict = false;
  let pendingImages = 0;
  let openReaderAfterSave = false;
  let timer;

  function showStatus(message, error = false) {
    status.textContent = message;
    status.classList.toggle('scenario-error', error);
  }

  async function request(url, body, contentType) {
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': contentType, 'X-CSRF-Token': data.csrf },
      body,
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result?.success) {
      const error = new Error(result?.error || 'La requête a échoué. Réessayez dans un instant.');
      error.status = response.status;
      throw error;
    }
    return result.data;
  }

  function serialize(editor) {
    const copy = editor.cloneNode(true);
    copy.querySelectorAll('[data-scenario-ref]').forEach(element => {
      element.replaceWith(document.createTextNode(`[[fiche:${element.dataset.scenarioRef}]]`));
    });
    copy.querySelectorAll('[data-scenario-image]').forEach(element => element.removeAttribute('src'));
    copy.querySelectorAll('[data-image-pending]').forEach(element => element.remove());
    return copy.innerHTML;
  }

  async function save() {
    clearTimeout(timer);
    if (saving || conflict || pendingImages) return;
    if (edits === savedEdits) {
      if (openReaderAfterSave) {
        openReaderAfterSave = false;
        window.location.assign(readLink.href);
      }
      return;
    }
    if (!title.value.trim()) {
      openReaderAfterSave = false;
      showStatus('Renseignez le nom du scénario pour l’enregistrer.', true);
      return;
    }
    saving = true;
    const currentEdits = edits;
    const scenarioTitle = title.value.trim();
    const blocks = Array.from(list.children, block => ({
      title: block.querySelector('[data-block-title]').value,
      html: serialize(block.querySelector('[data-block-editor]')),
      collapsed: !block.open,
    }));
    showStatus('Enregistrement…');
    try {
      const result = await request(data.saveUrl, JSON.stringify({ title: scenarioTitle, revision, blocks }), 'application/json');
      revision = result.revision;
      savedEdits = currentEdits;
      sidebarTitle.textContent = scenarioTitle;
      showStatus(edits === savedEdits ? 'Enregistré' : 'Modifications en attente…');
    } catch (error) {
      openReaderAfterSave = false;
      conflict = error.status === 409;
      showStatus(error.message, true);
    } finally {
      saving = false;
      if (openReaderAfterSave) save();
      else if (!conflict && edits !== currentEdits) scheduleSave();
    }
  }

  function scheduleSave() {
    clearTimeout(timer);
    timer = setTimeout(save, 650);
  }

  function changed(immediate = false) {
    edits += 1;
    if (conflict) return;
    if (pendingImages === 0) showStatus('Modifications en attente…');
    if (immediate) save();
    else scheduleSave();
  }

  function mention(id) {
    const entry = entriesById.get(id);
    const element = document.createElement(entry ? 'a' : 'span');
    element.dataset.scenarioRef = id;
    element.className = 'scenario-reference';
    element.contentEditable = 'false';
    element.textContent = entry?.title ?? 'Fiche indisponible';
    if (entry) {
      element.href = entry.url;
      element.target = '_blank';
      element.rel = 'noopener';
    }
    return element;
  }

  function restoreMentions(editor) {
    const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    for (const node of nodes) {
      if (node.parentElement.closest('a') || !node.data.includes('[[fiche:')) continue;
      const fragment = document.createDocumentFragment();
      let offset = 0;
      for (const match of node.data.matchAll(/\[\[fiche:([1-9][0-9]{0,18})\]\]/g)) {
        fragment.append(node.data.slice(offset, match.index), mention(match[1]));
        offset = match.index + match[0].length;
      }
      if (offset > 0) {
        fragment.append(node.data.slice(offset));
        node.replaceWith(fragment);
      }
    }
  }

  function selectionIn(editor) {
    const selection = document.getSelection();
    if (!selection.rangeCount) return null;
    const range = selection.getRangeAt(0);
    return editor.contains(range.commonAncestorContainer) ? range : null;
  }

  function updateFormatting(editor, hasSelection = Boolean(selectionIn(editor))) {
    const toolbar = editor.closest('.scenario-block').querySelector('.scenario-toolbar');
    for (const button of toolbar.querySelectorAll('button[aria-pressed]')) {
      button.setAttribute('aria-pressed', String(hasSelection && document.queryCommandState(button.dataset.command)));
    }
    if (hasSelection) {
      const style = toolbar.querySelector('[data-format="formatBlock"]');
      const block = document.queryCommandValue('formatBlock').toLowerCase();
      style.value = Array.from(style.options).some(option => option.value === block) ? block : 'p';
    }
  }

  function placeSelection(editor, range) {
    editor.focus();
    if (!range || !editor.contains(range.commonAncestorContainer)) {
      range = document.createRange();
      range.selectNodeContents(editor);
      range.collapse(false);
    }
    const selection = document.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  }

  function mentionRange(editor) {
    const caret = selectionIn(editor);
    if (!caret?.collapsed || caret.startContainer.nodeType !== Node.TEXT_NODE) return null;
    const node = caret.startContainer;
    if (node.parentElement.closest('a, [contenteditable="false"]')) return null;
    const prefix = node.data.slice(0, caret.startOffset);
    const match = /(?:^|[\s(\[{])#([^#\r\n]{0,100})$/u.exec(prefix);
    if (!match) return null;
    const range = caret.cloneRange();
    range.setStart(node, prefix.length - match[1].length - 1);
    return { range, search: normalize(match[1].trim()) };
  }

  function configureEditor(block, editor) {
    const toolbar = block.querySelector('.scenario-toolbar');
    const dropdown = block.querySelector('[data-suggestions]');
    const filePicker = block.querySelector('[data-image-file]');
    const imageError = block.querySelector('[data-image-error]');
    let matches = [];
    let selected = 0;
    let savedRange = null;

    function closeSuggestions() {
      matches = [];
      dropdown.hidden = true;
    }

    function pick(entry) {
      const match = mentionRange(editor);
      if (!match) return;
      placeSelection(editor, match.range);
      document.execCommand('insertHTML', false, mention(entry.id).outerHTML + '&nbsp;');
      closeSuggestions();
      changed();
    }

    function highlight() {
      Array.from(dropdown.children).forEach((option, index) => option.classList.toggle('is-selected', index === selected));
      dropdown.children[selected]?.scrollIntoView({ block: 'nearest' });
    }

    function searchMentions() {
      const match = mentionRange(editor);
      if (!match) {
        closeSuggestions();
        return;
      }
      const exact = [];
      const partial = [];
      for (const entry of entries) {
        if (entry.search.startsWith(match.search)) exact.push(entry);
        else if (entry.search.includes(match.search)) partial.push(entry);
      }
      matches = exact.concat(partial).slice(0, 8);
      selected = 0;
      dropdown.replaceChildren();
      for (const entry of matches) {
        const option = document.createElement('button');
        const category = document.createElement('small');
        option.type = 'button';
        option.textContent = entry.title;
        category.textContent = entry.category;
        option.append(category);
        option.addEventListener('mousedown', event => event.preventDefault());
        option.addEventListener('click', () => pick(entry));
        dropdown.append(option);
      }
      if (matches.length === 0) dropdown.textContent = 'Aucune fiche trouvée';
      dropdown.hidden = false;
      const caret = match.range.getBoundingClientRect();
      const writing = editor.parentElement.getBoundingClientRect();
      dropdown.style.top = `${caret.bottom - writing.top + 4}px`;
      dropdown.style.left = `${Math.max(0, Math.min(caret.left - writing.left, writing.width - dropdown.offsetWidth))}px`;
      dropdown.firstElementChild?.classList.add('is-selected');
    }

    async function insertImage(file) {
      imageError.hidden = true;
      if (file.size > data.imageLimit) {
        imageError.textContent = 'Cette image dépasse la limite de 8 Mio.';
        imageError.hidden = false;
        return;
      }
      closeSuggestions();
      pendingImages += 1;
      const marker = document.createElement('span');
      marker.dataset.imagePending = crypto.getRandomValues(new Uint32Array(2)).join('-');
      marker.contentEditable = 'false';
      marker.textContent = 'Image en cours d’ajout…';
      document.execCommand('insertHTML', false, marker.outerHTML + '&nbsp;');
      const placeholder = editor.querySelector(`[data-image-pending="${marker.dataset.imagePending}"]`);
      if (!conflict) showStatus('Ajout de l’image…');
      try {
        const result = await request(data.uploadUrl, file, 'application/octet-stream');
        if (placeholder?.isConnected) {
          const image = document.createElement('img');
          image.dataset.scenarioImage = result.id;
          image.src = result.url;
          image.alt = '';
          const caret = selectionIn(editor);
          const moveCaret = caret?.collapsed && (caret.startContainer === placeholder.parentNode
            && caret.startOffset === Array.from(placeholder.parentNode.childNodes).indexOf(placeholder) + 1);
          placeholder.replaceWith(image);
          if (moveCaret) {
            caret.setStartAfter(image);
            caret.collapse(true);
            placeSelection(editor, caret);
          }
          changed();
        }
      } catch (error) {
        openReaderAfterSave = false;
        placeholder?.remove();
        imageError.textContent = error.message;
        imageError.hidden = false;
      } finally {
        pendingImages -= 1;
        if (pendingImages === 0 && !conflict) {
          if (openReaderAfterSave) save();
          else if (savedEdits === edits) showStatus('Enregistré');
          else scheduleSave();
        }
      }
    }

    function rememberSelection() {
      const range = selectionIn(editor);
      if (range) savedRange = range.cloneRange();
    }

    function format(command, value = null) {
      closeSuggestions();
      if (document.activeElement !== editor || !selectionIn(editor)) {
        placeSelection(editor, savedRange);
      }
      document.execCommand('styleWithCSS', false, false);
      document.execCommand(command, false, value);
      rememberSelection();
      updateFormatting(editor);
      changed();
    }

    editor.addEventListener('input', event => {
      rememberSelection();
      if (!event.isComposing) searchMentions();
      changed();
    });
    editor.addEventListener('compositionend', searchMentions);
    editor.addEventListener('mouseup', () => { rememberSelection(); closeSuggestions(); });
    editor.addEventListener('focusout', event => {
      if (!dropdown.contains(event.relatedTarget)) closeSuggestions();
    });
    editor.addEventListener('keydown', event => {
      if (event.isComposing || dropdown.hidden) return;
      if (event.key === 'Escape' || event.key === 'Tab') closeSuggestions();
      if (matches.length === 0) return;
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        selected = (selected + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length;
        highlight();
      } else if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        pick(matches[selected]);
      } else if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        closeSuggestions();
      }
    });
    editor.addEventListener('focus', () => updateFormatting(editor));
    editor.addEventListener('keyup', () => {
      rememberSelection();
      updateFormatting(editor);
    });
    editor.addEventListener('click', event => {
      const link = event.target.closest('a');
      if (!link || !editor.contains(link)) return;
      event.preventDefault();
      window.open(link.href, '_blank', 'noopener,noreferrer');
    });
    editor.addEventListener('paste', event => {
      event.preventDefault();
      const image = Array.from(event.clipboardData?.items ?? []).find(item => item.kind === 'file' && item.type.startsWith('image/'))?.getAsFile();
      if (image) {
        insertImage(image);
      } else {
        document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') ?? '');
      }
    });
    editor.addEventListener('drop', event => event.preventDefault());

    block.addEventListener('focusout', event => {
      if (event.relatedTarget !== editor && !toolbar.contains(event.relatedTarget) && !dropdown.contains(event.relatedTarget)) {
        updateFormatting(editor, false);
      }
    });
    toolbar.addEventListener('mousedown', event => {
      if (event.target.closest('button')) event.preventDefault();
      rememberSelection();
    });
    toolbar.addEventListener('click', event => {
      const button = event.target.closest('button');
      if (!button) return;
      if (button.dataset.command) {
        format(button.dataset.command);
      } else if (button.hasAttribute('data-table')) {
        format('insertHTML', '<table><thead><tr><th>Colonne 1</th><th>Colonne 2</th></tr></thead><tbody><tr><td>Cellule</td><td>Cellule</td></tr></tbody></table><p><br></p>');
      } else if (button.hasAttribute('data-link')) {
        const url = window.prompt('Adresse du lien (https://…)')?.trim();
        if (!url) return;
        if (!/^(?:https?:\/\/|mailto:)[^\p{C}\s]+$/iu.test(url)) {
          showStatus('Utilisez une adresse commençant par https://, http:// ou mailto:.', true);
          return;
        }
        if (!savedRange || savedRange.collapsed) {
          const link = document.createElement('a');
          link.href = url;
          link.textContent = url;
          format('insertHTML', link.outerHTML + '&nbsp;');
        } else {
          format('createLink', url);
        }
      } else if (button.hasAttribute('data-image')) {
        filePicker.click();
      }
    });
    toolbar.addEventListener('change', event => {
      const control = event.target;
      if (control === filePicker && control.files[0]) {
        placeSelection(editor, savedRange);
        insertImage(control.files[0]);
        control.value = '';
      } else if (control.dataset.format === 'formatBlock') {
        format('formatBlock', control.value);
      } else if (control.dataset.format === 'alignment' && control.value) {
        format(control.value);
        control.value = '';
      }
    });
  }

  function updateMoves() {
    Array.from(list.children).forEach((block, index) => {
      block.querySelector('[data-move="-1"]').disabled = index === 0;
      block.querySelector('[data-move="1"]').disabled = index === list.children.length - 1;
      if (!block.querySelector('[data-block-title]').value.trim()) {
        block.querySelector('[data-block-label]').textContent = `Bloc de texte ${index + 1}`;
      }
    });
  }

  function addBlock(blockData) {
    const block = template.content.firstElementChild.cloneNode(true);
    const blockTitle = block.querySelector('[data-block-title]');
    const label = block.querySelector('[data-block-label]');
    const editor = block.querySelector('[data-block-editor]');
    blockTitle.value = blockData.title;
    if (blockData.title) label.textContent = blockData.title;
    block.open = !blockData.collapsed;
    let lastOpen = block.open;
    editor.innerHTML = blockData.html;
    restoreMentions(editor);
    list.append(block);
    configureEditor(block, editor);

    blockTitle.addEventListener('input', () => {
      label.textContent = blockTitle.value.trim();
      updateMoves();
      changed();
    });
    block.addEventListener('toggle', () => {
      if (block.open !== lastOpen) {
        lastOpen = block.open;
        changed();
      }
    });
    block.querySelector('.scenario-block-actions').addEventListener('click', event => {
      const button = event.target.closest('button');
      if (!button) return;
      event.preventDefault();
      if (button.hasAttribute('data-remove-block')) {
        if (!window.confirm('Supprimer ce bloc de texte ?')) return;
        (block.nextElementSibling || block.previousElementSibling)?.querySelector('summary').focus();
        block.remove();
      } else {
        const goingUp = button.dataset.move === '-1';
        const neighbor = goingUp ? block.previousElementSibling : block.nextElementSibling;
        if (!neighbor) return;
        list.insertBefore(block, goingUp ? neighbor : neighbor.nextElementSibling);
        block.querySelector('summary').focus();
      }
      updateMoves();
      changed(true);
    });
    return editor;
  }

  data.blocks.forEach(addBlock);
  updateMoves();
  document.addEventListener('selectionchange', () => {
    const editor = document.activeElement;
    if (list.contains(editor) && editor.matches('[data-block-editor]')) updateFormatting(editor);
  });
  title.addEventListener('input', () => changed());
  workspace.querySelector('[data-scenario-save]').addEventListener('click', save);
  readLink?.addEventListener('click', event => {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || (edits === savedEdits && !pendingImages)) return;
    event.preventDefault();
    if (conflict) return;
    openReaderAfterSave = true;
    save();
  });
  workspace.querySelector('[data-scenario-add]').addEventListener('click', () => {
    if (list.children.length >= 250) {
      showStatus('Ce scénario contient déjà 250 blocs.', true);
      return;
    }
    addBlock({ title: '', html: '', collapsed: false }).focus();
    updateMoves();
    changed(true);
  });
  window.addEventListener('beforeunload', event => {
    if (edits !== savedEdits || pendingImages) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
})();
