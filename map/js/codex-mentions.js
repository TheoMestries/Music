(() => {
  const tokenPattern = /\[\[fiche:([1-9][0-9]{0,18})\]\]/g;
  const token = (id) => '[[fiche:' + id + ']]';
  const description = document.getElementById('codex-description-editor');
  const titles = new Map();
  for (const option of document.querySelectorAll('[data-codex-rich] [data-mention-select] option[value]')) {
    titles.set(option.value, option.dataset.mentionLabel || option.textContent.trim());
  }

  function createChip(id) {
    const chip = document.createElement('span');
    chip.className = 'codex-mention-token';
    chip.dataset.mentionId = id;
    chip.contentEditable = 'false';
    chip.textContent = titles.get(id) || 'Fiche indisponible';
    return chip;
  }

  if (description?.isContentEditable) {
    const walker = document.createTreeWalker(description, NodeFilter.SHOW_TEXT);
    const textNodes = [];
    while (walker.nextNode()) textNodes.push(walker.currentNode);
    for (const text of textNodes) {
      const matches = [...text.data.matchAll(tokenPattern)];
      if (!matches.length) continue;
      const fragment = document.createDocumentFragment();
      let offset = 0;
      for (const match of matches) {
        fragment.append(text.data.slice(offset, match.index), createChip(match[1]));
        offset = match.index + match[0].length;
      }
      fragment.append(text.data.slice(offset));
      text.replaceWith(fragment);
    }
  }

  window.mapCodexSerializeMentions = (editor) => {
    const copy = editor.cloneNode(true);
    for (const chip of copy.querySelectorAll('.codex-mention-token[data-mention-id]')) {
      chip.replaceWith(token(chip.dataset.mentionId));
    }
    return copy.innerHTML;
  };

  let savedRange = null;
  document.addEventListener('selectionchange', () => {
    const selection = getSelection();
    const anchor = selection.anchorNode;
    const element = anchor instanceof Element ? anchor : anchor?.parentElement;
    if (selection.rangeCount && element?.closest('[contenteditable="true"]')) {
      savedRange = selection.getRangeAt(0).cloneRange();
    }
  });

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-mention-insert]');
    if (!button) return;
    const select = button.closest('[data-mention-control]')?.querySelector('[data-mention-select]');
    const target = document.getElementById(button.dataset.mentionTarget);
    if (!select?.value || !target || !/^[1-9][0-9]*$/.test(select.value)) return;

    if (target instanceof HTMLTextAreaElement) {
      target.setRangeText(token(select.value), target.selectionStart, target.selectionEnd, 'end');
      target.focus();
    } else if (target.isContentEditable) {
      const range = savedRange && target.contains(savedRange.commonAncestorContainer)
        ? savedRange
        : document.createRange();
      if (range !== savedRange) {
        range.selectNodeContents(target);
        range.collapse(false);
      }
      const chip = createChip(select.value);
      range.deleteContents();
      range.insertNode(chip);
      range.setStartAfter(chip);
      range.collapse(true);
      target.focus();
      const selection = getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    }
    target.dispatchEvent(new Event('input', { bubbles: true }));
  });
})();
