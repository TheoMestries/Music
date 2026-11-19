(() => {
  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

  window.openBoardAlignment = ({ viewport, page, save, close }) => {
    const world = viewport.querySelector('.board-world');
    const image = world?.querySelector('.board-background-image');
    if (!image) return false;

    const settings = { ...window.MapBoardRenderer.defaults, ...page.settings };
    const draft = {
      x: settings.width / 4,
      y: settings.height / 4,
      width: settings.cellSize,
      height: settings.gridType === 'square' ? (settings.cellSizeY ?? settings.cellSize) : settings.cellSize,
    };
    const initial = { ...draft };
    let step = 0;
    let saving = false;
    let closed = false;
    let drag = null;

    function element(tag, className, text) {
      const node = document.createElement(tag);
      node.className = className;
      if (text) node.textContent = text;
      return node;
    }

    const layer = element('div', 'board-alignment-layer');
    const surface = element('div', 'board-alignment-surface');
    surface.tabIndex = 0;
    surface.setAttribute('aria-label', 'Aperçu de l’alignement. Utilisez les flèches pour régler le repère.');
    const marker = element('span', 'board-alignment-marker');
    const lens = element('span', 'board-alignment-lens');
    const guide = element('div', 'board-alignment-guide');
    const sample = element('div', 'board-alignment-sample');
    const grip = element('span', 'board-alignment-grip');
    sample.append(grip);
    surface.append(guide, sample, marker, lens);
    const controls = element('section', 'board-alignment-controls');
    controls.setAttribute('aria-label', 'Aligner sur la grille');
    const title = element('strong', '');
    const help = element('p', '');
    const readout = element('output', '');
    const status = element('p', '');
    status.setAttribute('role', 'status');
    const actions = element('div', 'board-alignment-actions');
    function button(label, handler) {
      const node = element('button', 'button button-secondary button-small', label);
      node.type = 'button';
      node.addEventListener('click', handler);
      return node;
    }
    const colorLabel = element('label', '', 'Couleur du repère ');
    const color = element('input', '');
    color.type = 'color';
    color.value = '#e440b0';
    color.addEventListener('input', render);
    colorLabel.append(color);
    const adjust = element('div', 'board-alignment-actions');
    for (const [label, key, amount] of [['X −', 'width', -1], ['X +', 'width', 1], ['Y −', 'height', -1], ['Y +', 'height', 1]]) {
      adjust.append(button(label, () => {
        draft[key] = clamp(draft[key] + amount, 5, 350);
        render();
      }));
    }
    adjust.append(button('Réinitialiser', () => {
      Object.assign(draft, initial);
      render();
    }));
    const cancel = button('Annuler', finish);
    const back = button('Retour', () => changeStep(step - 1));
    const next = button('Suivant', async () => {
      if (step < 2) {
        changeStep(step + 1);
        return;
      }
      if (saving) return;
      saving = true;
      next.disabled = back.disabled = cancel.disabled = true;
      status.textContent = 'Enregistrement…';
      // Reduce the anchor to its equivalent offset within one cell.
      const offset = (value, size) => ((value % size) + size) % size;
      try {
        await save({
          ...settings,
          gridType: 'square',
          gridVisible: true,
          cellSize: draft.width,
          cellSizeY: draft.height,
          offsetX: offset(draft.x, draft.width),
          offsetY: offset(draft.y, draft.height),
        });
        saving = false;
        finish();
      } catch (error) {
        status.textContent = error.message;
      } finally {
        saving = false;
        next.disabled = back.disabled = cancel.disabled = false;
      }
    });
    actions.append(colorLabel, cancel, back, next);
    controls.append(title, help, readout, adjust, actions, status);
    layer.append(surface, controls);
    viewport.append(layer);

    function geometry() {
      const area = surface.getBoundingClientRect();
      const bounds = world.getBoundingClientRect();
      return {
        area,
        x: bounds.left - area.left,
        y: bounds.top - area.top,
        sx: bounds.width / settings.width,
        sy: bounds.height / settings.height,
        width: bounds.width,
        height: bounds.height,
      };
    }
    function render() {
      const g = geometry();
      const x = g.x + draft.x * g.sx, y = g.y + draft.y * g.sy;
      const width = draft.width * g.sx, height = draft.height * g.sy;
      const format = value => value.toLocaleString('fr-FR', { maximumFractionDigits: 2 });
      layer.style.setProperty('--alignment-color', color.value);
      layer.dataset.step = step;
      title.textContent = ['1 sur 3 · Ancrage', '2 sur 3 · Échelle', '3 sur 3 · Ajustement'][step];
      help.textContent = [
        'Clique sur une intersection de la carte, puis affine avec les flèches. Maj multiplie le pas par dix.',
        'Tire la poignée du cadre pour couvrir 3 × 3 cases de la carte. Les flèches règlent la taille.',
        'Fais glisser la grille pour la déplacer. Utilise X et Y ou les flèches pour affiner la taille sur chaque axe. La grille carrée sera appliquée à cette page.',
      ][step];
      readout.textContent = step === 0
        ? 'Ancrage · X ' + format(draft.x) + ' px · Y ' + format(draft.y) + ' px'
        : 'Taille d’une case · X ' + format(draft.width) + ' px · Y ' + format(draft.height) + ' px';
      back.hidden = step === 0;
      adjust.hidden = step !== 2;
      next.textContent = step === 2 ? 'Terminer' : 'Suivant';
      Object.assign(marker.style, { left: x + 'px', top: y + 'px' });
      const signX = x > g.area.width / 2 ? -1 : 1;
      const signY = y > (g.area.height - controls.offsetHeight) / 2 ? -1 : 1;
      Object.assign(sample.style, {
        left: (signX < 0 ? x - width * 3 : x) + 'px',
        top: (signY < 0 ? y - height * 3 : y) + 'px',
        width: width * 3 + 'px', height: height * 3 + 'px',
        backgroundSize: width + 'px ' + height + 'px',
      });
      // Keep the handle reachable even when the sample extends beyond the viewport.
      grip.style.left = clamp(x + signX * width * 3, 12, Math.max(12, g.area.width - 12)) - (signX < 0 ? x - width * 3 : x) + 'px';
      grip.style.top = clamp(y + signY * height * 3, 12, Math.max(12, g.area.height - controls.offsetHeight - 24)) - (signY < 0 ? y - height * 3 : y) + 'px';
      sample.dataset.directionX = signX;
      sample.dataset.directionY = signY;
      Object.assign(guide.style, {
        left: g.x + 'px', top: g.y + 'px', width: g.width + 'px', height: g.height + 'px',
        backgroundSize: width + 'px ' + height + 'px',
        backgroundPosition: draft.x * g.sx + 'px ' + draft.y * g.sy + 'px',
      });
      if (step !== 0) return;
      const bounds = image.getBoundingClientRect();
      const zoom = 3;
      const lensX = clamp(x + 90, 75, Math.max(75, g.area.width - 75));
      const lensY = clamp(y - 90, 75, Math.max(75, g.area.height - 75));
      Object.assign(lens.style, {
        left: lensX + 'px', top: lensY + 'px',
        backgroundImage: 'url("' + page.image + '")',
        backgroundSize: bounds.width * zoom + 'px ' + bounds.height * zoom + 'px',
        backgroundPosition: 75 - (g.area.left + x - bounds.left) * zoom + 'px ' + (75 - (g.area.top + y - bounds.top) * zoom) + 'px',
      });
    }
    function changeStep(value) {
      step = value;
      status.textContent = '';
      render();
      surface.focus({ preventScroll: true });
    }
    function finish() {
      if (saving || closed) return;
      dispose();
    }
    function dispose() {
      if (closed) return;
      closed = true;
      observer.disconnect();
      layer.remove();
      world.classList.remove('board-aligning');
      close();
    }
    surface.addEventListener('pointerdown', event => {
      if (event.button !== 0 || saving || drag) return;
      if (step === 1 && event.target !== grip) return;
      event.preventDefault();
      surface.focus({ preventScroll: true });
      surface.setPointerCapture(event.pointerId);
      drag = { id: event.pointerId, x: event.clientX, y: event.clientY, anchorX: draft.x, anchorY: draft.y, width: draft.width, height: draft.height, signX: Number(sample.dataset.directionX), signY: Number(sample.dataset.directionY) };
      if (step === 0) move(event);
    });
    function move(event) {
      if (drag?.id !== event.pointerId) return;
      const g = geometry();
      const dx = (event.clientX - drag.x) / g.sx, dy = (event.clientY - drag.y) / g.sy;
      if (step === 0) {
        draft.x = clamp((event.clientX - g.area.left - g.x) / g.sx, 0, settings.width);
        draft.y = clamp((event.clientY - g.area.top - g.y) / g.sy, 0, settings.height);
      } else if (step === 1) {
        draft.width = clamp(drag.width + dx * drag.signX / 3, 5, 350);
        draft.height = clamp(drag.height + dy * drag.signY / 3, 5, 350);
      } else {
        draft.x = clamp(drag.anchorX + dx, -20000, 20000);
        draft.y = clamp(drag.anchorY + dy, -20000, 20000);
      }
      render();
    }
    surface.addEventListener('pointermove', move);
    surface.addEventListener('lostpointercapture', () => { drag = null; });
    layer.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        event.preventDefault();
        finish();
      }
      if (saving || event.target !== surface || !event.key.startsWith('Arrow')) return;
      event.preventDefault();
      const horizontal = event.key === 'ArrowLeft' || event.key === 'ArrowRight';
      const delta = (event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 1) * (event.shiftKey ? 10 : 1);
      const key = step === 0 ? (horizontal ? 'x' : 'y') : (horizontal ? 'width' : 'height');
      draft[key] = step === 0
        ? clamp(draft[key] + delta, 0, horizontal ? settings.width : settings.height)
        : clamp(draft[key] + delta, 5, 350);
      render();
    });
    const observer = new ResizeObserver(render);
    observer.observe(surface);
    world.classList.add('board-aligning');
    changeStep(0);
    return dispose;
  };
})();
