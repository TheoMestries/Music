(() => {
  window.createBoardDrawingTools = ({root, grid, renderer, context, action, onSelect, onError}) => {
    const toggle = root.querySelector('#board-drawing-toggle');
    if (!toggle) return {stop() {}, refresh() {}};
    const menu = root.querySelector('#board-drawing-menu');
    const options = root.querySelector('#board-drawing-options');
    const toolButtons = [...menu.querySelectorAll('[data-drawing-tool]')];
    const stroke = root.querySelector('#board-drawing-stroke');
    const fill = root.querySelector('#board-drawing-fill');
    const transparent = root.querySelector('#board-drawing-transparent');
    const opacity = root.querySelector('#board-drawing-opacity');
    const opacityValue = root.querySelector('#board-drawing-opacity-value');
    const width = root.querySelector('#board-drawing-width');
    const textToggle = root.querySelector('#board-text-toggle');
    const textOptions = root.querySelector('#board-text-options');
    const textStyle = {bold:false, italic:false};
    let textDraft = null;
    const textEditor = document.createElement('form');
    textEditor.className = 'board-text-editor';
    textEditor.hidden = true;
    const textInput = document.createElement('textarea');
    textInput.setAttribute('aria-label', 'Texte à placer');
    textInput.placeholder = 'Saisir le texte…';
    textInput.maxLength = 500;
    textInput.required = true;
    const place = document.createElement('button');
    place.className = 'button button-small';
    place.textContent = 'Placer';
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'button button-secondary button-small';
    cancel.textContent = 'Annuler';
    textEditor.append(textInput, place, cancel);
    grid.closest('.table-stage').append(textEditor);

    function closeText() {
      textDraft = null;
      textEditor.hidden = true;
      renderer.previewDrawing(null);
    }
    function textDrawing() {
      return {type:'text', points:[textDraft.point], text:textInput.value.trim(),
        font:root.querySelector('#board-text-font').value,
        size:Number(root.querySelector('#board-text-size').value),
        fill:root.querySelector('#board-text-color').value,
        stroke:root.querySelector('#board-text-outline').value, ...textStyle};
    }
    function previewText() {
      if (!textDraft) return;
      const drawing = textDrawing();
      renderer.previewDrawing(drawing.text && drawing.size >= 8 && drawing.size <= 160 ? drawing : null, textDraft.target.layer);
    }
    cancel.addEventListener('click', closeText);
    textInput.addEventListener('input', previewText);
    textOptions.addEventListener('input', previewText);
    textOptions.querySelectorAll('[data-text-style]').forEach(button => button.addEventListener('click', () => {
      const key = button.dataset.textStyle;
      textStyle[key] = !textStyle[key];
      button.setAttribute('aria-pressed', String(textStyle[key]));
      previewText();
    }));
    textEditor.addEventListener('submit', async event => {
      event.preventDefault();
      if (!textDraft || saving || !root.querySelector('#board-text-size').reportValidity()) return;
      const drawing = textDrawing();
      if (!drawing.text) return;
      const draft = textDraft;
      place.disabled = true;
      if (await submit('draw', {...draft.target, drawing}) && textDraft === draft) closeText();
      place.disabled = false;
    });
    let tool = null, drag = null, saving = false;

    function showMenu(open) {
      menu.hidden = !open;
      toggle.setAttribute('aria-expanded', String(open));
    }
    function select(next) {
      cancelStroke();
      closeText();
      tool = next;
      options.hidden = !tool || tool === 'text';
      textOptions.hidden = tool !== 'text';
      textToggle.setAttribute('aria-pressed', String(tool === 'text'));
      grid.classList.toggle('board-drawing-active', Boolean(tool));
      toolButtons.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.drawingTool === tool)));
      showMenu(false);
      updateFillControls();
      if (tool) onSelect();
    }
    function cancelStroke() {
      const pointerId = drag?.pointerId;
      drag = null;
      renderer.previewDrawing(null);
      if (pointerId !== undefined && grid.hasPointerCapture(pointerId)) grid.releasePointerCapture(pointerId);
    }
    toggle.addEventListener('click', () => showMenu(menu.hidden));
    textToggle.addEventListener('click', () => select(tool === 'text' ? null : 'text'));
    toolButtons.forEach(button => button.addEventListener('click', () => select(button.dataset.drawingTool)));
    root.querySelector('#board-drawing-stop').addEventListener('click', () => select(null));
    function updateFillControls() {
      const supportsFill = tool !== 'line';
      fill.disabled = transparent.disabled = !supportsFill;
      opacity.disabled = !supportsFill || transparent.checked;
      opacityValue.textContent = opacity.value + ' %';
    }
    fill.addEventListener('input', () => {
      transparent.checked = false;
      updateFillControls();
    });
    transparent.addEventListener('change', updateFillControls);
    opacity.addEventListener('input', updateFillControls);
    document.addEventListener('click', event => {
      if (!toggle.contains(event.target) && !menu.contains(event.target)) showMenu(false);
    });
    document.addEventListener('keydown', event => {
      if (textDraft && textEditor.contains(event.target)) {
        if (event.key === 'Escape') { event.preventDefault(); closeText(); textToggle.focus(); }
        if (event.target === textInput && event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
          event.preventDefault();
          textEditor.requestSubmit();
        }
        return;
      }
      if (event.key !== 'Escape' || event.defaultPrevented || event.target.closest('dialog')) return;
      if (!menu.hidden) {
        showMenu(false);
        toggle.focus();
      } else if (tool) {
        select(null);
        toggle.focus();
      }
    });

    async function submit(name, payload) {
      if (saving) return;
      saving = true;
      try {
        await action(name, payload);
        return true;
      } catch (error) {
        onError(error);
        return false;
      } finally {
        saving = false;
      }
    }
    root.querySelector('#board-drawing-clear').addEventListener('click', () => {
      const target = context();
      if (!target || saving) return;
      const message = target.canManage
        ? 'Effacer tous les dessins et textes du calque actif ?'
        : 'Effacer vos dessins et textes du calque Joueurs ?';
      if (!window.confirm(message)) return;
      cancelStroke();
      closeText();
      showMenu(false);
      submit('clear_drawings', target);
    });

    function point(event, world, settings) {
      const rect = world.getBoundingClientRect();
      const x = (event.clientX - rect.left) / rect.width * settings.width;
      const y = (event.clientY - rect.top) / rect.height * settings.height;
      return [Math.max(0, Math.min(settings.width, x)), Math.max(0, Math.min(settings.height, y))];
    }
    grid.addEventListener('pointerdown', event => {
      if (event.target.closest('.board-entity-resize')) return;
      if (!tool || saving || drag || event.button !== 0) return;
      const world = event.target.closest('.board-world');
      const target = context();
      if (!world || !target) return;
      event.preventDefault();
      const settings = {...window.MapBoardRenderer.defaults, ...target.settings};
      const start = point(event, world, settings);
      if (tool === 'text') {
        if (textDraft) { textInput.focus(); return; }
        textDraft = {target, point:start};
        textInput.value = '';
        textEditor.hidden = false;
        const area = textEditor.parentElement.getBoundingClientRect();
        textEditor.style.left = Math.max(4, Math.min(event.clientX - area.left, area.width - textEditor.offsetWidth - 4)) + 'px';
        textEditor.style.top = Math.max(4, Math.min(event.clientY - area.top + 12, area.height - textEditor.offsetHeight - 4)) + 'px';
        textInput.focus();
        return;
      }
      const filled = tool !== 'line' && !transparent.checked && Number(opacity.value) > 0;
      drag = {target, settings, pointerId:event.pointerId, drawing:{
        type:tool, points:[start, start], stroke:stroke.value,
        fill:filled ? fill.value : 'transparent',
        fillOpacity:Number(opacity.value),
        width:Number(width.value),
      }};
      grid.setPointerCapture(event.pointerId);
      renderer.previewDrawing(drag.drawing, target.layer);
    }, true);
    function move(event) {
      if (drag?.pointerId !== event.pointerId) return;
      const world = grid.querySelector('.board-world');
      if (!world) return;
      const current = point(event, world, drag.settings);
      const drawing = drag.drawing;
      if (drawing.type === 'freehand') {
        const [x, y] = drawing.points.at(-1);
        if (Math.hypot(current[0]-x, current[1]-y) < 1) return;
        if (drawing.points.length >= 1024) drawing.points = drawing.points.filter((_, index) => index % 2 === 0);
        drawing.points.push(current);
      } else {
        drawing.points[1] = current;
      }
      renderer.previewDrawing(drawing, drag.target.layer);
    }
    grid.addEventListener('pointermove', move);
    grid.addEventListener('pointerup', event => {
      if (drag?.pointerId !== event.pointerId) return;
      move(event);
      const {drawing, target} = drag;
      cancelStroke();
      const [x, y] = drawing.points[0];
      if (!drawing.points.some(([px, py]) => px !== x || py !== y)) return;
      submit('draw', {...target, drawing});
    });
    grid.addEventListener('pointercancel', cancelStroke);
    grid.addEventListener('lostpointercapture', cancelStroke);
    return {
      stop: () => select(null),
      refresh() {
        if (textDraft) {
          const target = context();
          if (!target || textDraft.target.pageId !== target.pageId || textDraft.target.layer !== target.layer ||
              textDraft.target.settingsRevision !== target.settingsRevision) closeText();
          else if (!saving) previewText();
        }
        if (!drag) return;
        const target = context();
        if (!target || drag.target.pageId !== target.pageId || drag.target.layer !== target.layer ||
            drag.target.settingsRevision !== target.settingsRevision) cancelStroke();
        else renderer.previewDrawing(drag.drawing, target.layer);
      }
    };
  };
})();
