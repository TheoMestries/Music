(() => {
  const panel = document.querySelector('[data-codex-map-editor]');
  if (!panel) return;
  const editor = panel.closest('form');
  const category = editor.elements.namedItem('category');
  const upload = editor.elements.namedItem('image');
  const removeImage = editor.elements.namedItem('removeImage');
  const preview = panel.querySelector('[data-map-preview]');
  const image = preview.querySelector('img');
  const original = image.getAttribute('src');
  const rows = panel.querySelector('[data-map-pins]');
  const template = panel.querySelector('[data-map-pin-template]');
  const place = panel.querySelector('[data-map-place]');
  const add = panel.querySelector('[data-map-add-pin]');
  const status = panel.querySelector('[data-map-status]');
  const canvasPins = panel.querySelector('[data-map-live-pins]');
  const limit = Number(panel.dataset.mapLimit);
  let previewUrl;
  let placing = false;

  function updateModule() {
    const isMap = category.value.trim() === 'Cartes';
    panel.hidden = !isMap;
    panel.disabled = !isMap;
  }

  function setPlacement(active) {
    placing = active;
    preview.toggleAttribute('data-placing', active);
    place.setAttribute('aria-pressed', String(active));
    status.textContent = active ? 'Cliquez sur la carte pour placer le repère.' : '';
  }

  function updatePreview() {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = upload.files[0] ? URL.createObjectURL(upload.files[0]) : null;
    const source = previewUrl ?? (removeImage?.checked ? null : original);
    if (source) image.src = source;
    else image.removeAttribute('src');
    preview.hidden = !source;
    place.hidden = !source;
    setPlacement(false);
  }

  function updateMarkers() {
    const pins = [];
    for (const row of rows.children) {
      const x = row.querySelector('[name="pinX[]"]').valueAsNumber;
      const y = row.querySelector('[name="pinY[]"]').valueAsNumber;
      if (x < 0 || x > 100 || y < 0 || y > 100 || !Number.isFinite(x) || !Number.isFinite(y)) continue;
      const marker = document.createElement('span');
      marker.className = 'codex-map-pin';
      marker.style.setProperty('--pin-x', `${x}%`);
      marker.style.setProperty('--pin-y', `${y}%`);
      marker.textContent = String(pins.length + 1);
      pins.push(marker);
    }
    canvasPins.replaceChildren(...pins);
    const full = rows.children.length >= limit;
    place.disabled = full;
    add.disabled = full;
  }

  function appendPin(x, y) {
    if (rows.children.length >= limit) return;
    const fragment = template.content.cloneNode(true);
    fragment.querySelector('[name="pinX[]"]').value = x.toFixed(2);
    fragment.querySelector('[name="pinY[]"]').value = y.toFixed(2);
    const label = fragment.querySelector('[name="pinLabel[]"]');
    rows.append(fragment);
    label.focus();
    updateMarkers();
  }

  category.addEventListener('input', updateModule);
  upload.addEventListener('change', updatePreview);
  removeImage?.addEventListener('change', updatePreview);
  place.addEventListener('click', () => setPlacement(!placing));
  add.addEventListener('click', () => {
    setPlacement(false);
    appendPin(50, 50);
  });
  image.addEventListener('click', event => {
    if (!placing) return;
    const rectangle = image.getBoundingClientRect();
    if (!rectangle.width || !rectangle.height) return;
    const x = (event.clientX - rectangle.left) / rectangle.width * 100;
    const y = (event.clientY - rectangle.top) / rectangle.height * 100;
    setPlacement(false);
    appendPin(x, y);
  });
  rows.addEventListener('input', updateMarkers);
  rows.addEventListener('click', event => {
    const button = event.target.closest('[data-map-remove-pin]');
    if (!button) return;
    button.closest('[data-map-pin-row]').remove();
    updateMarkers();
    add.focus();
  });
  updateMarkers();
})();
