(() => {
  const defaults = window.MapBoardRenderer.defaults;
  const units = {feet:'ft', meters:'m', kilometers:'km', miles:'mi', inches:'po', centimeters:'cm', units:'unités', hex:'hex'};
  const rules = {euclidean:'Euclidienne', manhattan:'Manhattan', chebyshev:'Chebyshev', pathfinder:'Pathfinder / 3.5'};
  const format = new Intl.NumberFormat('fr-FR', {maximumFractionDigits:2});

  function align(point, settings, mode) {
    if (mode === 'free') return point;
    const {gridType, cellSize, offsetX, offsetY} = settings;
    if (gridType.startsWith('hex-')) {
      const center = window.MapBoardRenderer.snapPoint({...settings, snapToGrid:true, useDistanceScale:false}, point);
      if (mode === 'center') return center;
      const vertices = Array.from({length:6}, (_, index) => {
        const angle = index * Math.PI / 3 + (gridType === 'hex-v' ? Math.PI / 2 : 0);
        return {x:center.x + Math.cos(angle) * cellSize / 2, y:center.y + Math.sin(angle) * cellSize / 2};
      });
      return vertices.reduce((nearest, vertex) => Math.hypot(vertex.x-point.x, vertex.y-point.y) <
        Math.hypot(nearest.x-point.x, nearest.y-point.y) ? vertex : nearest);
    }
    const shift = mode === 'center' ? .5 : 0;
    if (gridType === 'isometric' || gridType === 'dimetric') {
      const height = cellSize * (gridType === 'dimetric' ? .5 : Math.tan(Math.PI / 6));
      const x = (point.x - offsetX) / cellSize, y = (point.y - offsetY) / height;
      const u = Math.round((x+y)/2 - shift) + shift, v = Math.round((y-x)/2 - shift) + shift;
      return {x:offsetX + (u-v)*cellSize, y:offsetY + (u+v)*height};
    }
    const height = settings.cellSizeY ?? cellSize;
    const snap = (value, offset, size) => offset + (Math.round((value-offset)/size - shift) + shift)*size;
    return {x:snap(point.x,offsetX,cellSize), y:snap(point.y,offsetY,height)};
  }

  function svg(tag, attributes) {
    const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attributes).forEach(([key,value]) => node.setAttribute(key,value));
    return node;
  }

  function renderMeasure(overlay, measurement, settings, remainingMs) {
    const [x,y] = measurement.start, [endX,endY] = measurement.end;
    const dx = endX-x, dy = endY-y, length = Math.hypot(dx,dy);
    if (!length) return null;
    const alongX = dx/length, alongY = dy/length;
    const at = (forward, lateral) => [x + alongX*forward - alongY*lateral, y + alongY*forward + alongX*lateral];
    const ink = '#ffbf62';
    const group = svg('g', {'class':'board-measure-mark'});
    const lineStyle = {stroke:ink, 'stroke-width':2, 'vector-effect':'non-scaling-stroke', 'stroke-linejoin':'round'};
    const areaStyle = {...lineStyle, fill:ink, 'fill-opacity':.22};
    const polygon = points => svg('polygon', {...areaStyle, points:points.map(point=>point.join(',')).join(' ')});
    let labelEnd = [endX,endY];
    if (measurement.shape === 'line') {
      group.append(svg('line', {...lineStyle, x1:x,y1:y,x2:endX,y2:endY}));
      const arrow = Math.min(length/3, settings.cellSize*.18);
      group.append(svg('polygon', {fill:ink, points:[at(length,0),at(length-arrow,arrow/2),at(length-arrow,-arrow/2)].map(p=>p.join(',')).join(' ')}));
    } else if (measurement.shape === 'circle') {
      const center = measurement.origin === 'edge' ? at(length/2,0) : [x,y];
      group.append(svg('circle', {...areaStyle,cx:center[0],cy:center[1],r:measurement.origin === 'edge' ? length/2 : length}));
    } else if (measurement.shape === 'square') {
      const side = Math.max(Math.abs(dx),Math.abs(dy));
      let left = x - side, top = y - side, size = side*2;
      if (measurement.origin !== 'center') {
        size = side;
        left = x - (dx < 0 ? side : 0);
        top = y - (dy < 0 ? side : 0);
        if (measurement.origin === 'edge') {
          if (Math.abs(dx) >= Math.abs(dy)) top = y-side/2;
          else left = x-side/2;
        }
      }
      group.append(svg('rect', {...areaStyle,x:left,y:top,width:size,height:size}));
      labelEnd = Math.abs(dx) >= Math.abs(dy) ? [x + Math.sign(dx)*side,y] : [x,y + Math.sign(dy)*side];
    } else if (measurement.shape === 'beam') {
      const halfWidth = measurement.beamWidth / settings.scale * settings.cellSize / 2;
      group.append(polygon([at(0,-halfWidth),at(length,-halfWidth),at(length,halfWidth),at(0,halfWidth)]));
    } else {
      const halfAngle = measurement.lock ? Math.atan(.5) : measurement.angle*Math.PI/360;
      if (measurement.coneEnd === 'round') {
        const left = at(length*Math.cos(halfAngle),-length*Math.sin(halfAngle));
        const right = at(length*Math.cos(halfAngle),length*Math.sin(halfAngle));
        group.append(svg('path', {...areaStyle,d:`M ${x},${y} L ${left.join(',')} A ${length} ${length} 0 0 1 ${right.join(',')} Z`}));
      } else {
        const halfWidth = Math.tan(halfAngle)*length;
        group.append(polygon([[x,y],at(length,-halfWidth),at(length,halfWidth)]));
      }
    }
    const distance = window.MapBoardRenderer.measure(settings,{x,y},{x:labelEnd[0],y:labelEnd[1]});
    const font = Math.max(14,settings.cellSize*.42);
    const label = svg('text',{x:endX,y:endY-font*.5,'font-size':font,'text-anchor':'middle','class':'board-measure-label'});
    label.textContent = format.format(distance)+' '+units[settings.unit]+' · '+format.format(distance/settings.scale)+' cases';
    group.append(label);
    overlay.append(group);
    if (remainingMs !== undefined) {
      group.animate([{opacity:1},{opacity:1,offset:.7},{opacity:0}], Math.max(1,remainingMs)).finished
        .then(() => group.remove(), () => group.remove());
    }
    return group;
  }

  window.renderBoardSharedMeasurements = (overlay,page) => {
    const settings = {...defaults,...page.settings};
    for (const measurement of page.measurements ?? []) renderMeasure(overlay,measurement,settings,measurement.remainingMs);
  };

  window.createBoardMeasureTools = ({root, grid, context, onSelect, publish}) => {
    const toggle = root.querySelector('#board-ruler-toggle');
    const panel = root.querySelector('#board-measure-options');
    const control = name => panel.querySelector('[name="'+name+'"]');
    const status = panel.querySelector('[data-measure-status]');
    const beamWidth = control('beamWidth');
    let active = false, draft = null, preview = null, completed = null;
    let scene = null, remote = [];
    const settingsFor = target => ({...defaults,...target.settings});
    const overlay = () => grid.querySelector('.board-measure-layer');
    const canShare = target => target && target.pageId === target.playerPageId && target.layer !== 'gm';
    function updatePanel() {
      const shape = control('shape').value;
      panel.querySelector('[data-measure-origin]').hidden = !['square','circle'].includes(shape);
      control('origin').querySelector('[value=corner]').disabled = shape === 'circle';
      if (shape === 'circle' && control('origin').value === 'corner') control('origin').value = 'edge';
      panel.querySelector('[data-measure-cone]').hidden = shape !== 'cone';
      panel.querySelector('[data-measure-beam]').hidden = shape !== 'beam';
      control('angle').disabled = control('lock').checked;
      panel.querySelectorAll('[data-angle]').forEach(button => { button.disabled = control('lock').checked; });
      const target = context();
      if (!target) return;
      const settings = settingsFor(target), unit = units[settings.unit];
      if (beamWidth.value === '') beamWidth.value = settings.scale;
      panel.querySelector('[data-beam-label]').textContent = 'Largeur du rayon ('+unit+')';
      panel.querySelectorAll('[data-beam]').forEach(button => { button.textContent = format.format(settings.scale*Number(button.dataset.beam)); });
      const rule = settings.useDistanceScale ? 'Distance continue' : settings.gridType.startsWith('hex-') ? 'Hexagones' : rules[settings.diagonal];
      panel.querySelector('[data-measure-scale]').textContent = rule+' · 1 case = '+format.format(settings.scale)+' '+unit;
      control('share').disabled = !canShare(target);
      control('share').title = canShare(target) ? '' : 'Le partage est disponible sur les calques publics de la page des joueurs.';
    }
    function clearDraft() {
      const id = draft?.pointerId;
      draft = null;
      preview?.remove();
      preview = null;
      if (id !== undefined && grid.hasPointerCapture(id)) grid.releasePointerCapture(id);
    }
    function stop() {
      active = false;
      clearDraft();
      completed?.node?.remove();
      completed = null;
      panel.hidden = true;
      toggle.setAttribute('aria-pressed','false');
      grid.classList.remove('board-measuring');
    }
    toggle.addEventListener('click', () => {
      if (!active) {
        if (!context()) return;
        onSelect();
        active = true;
        grid.classList.add('board-measuring');
        toggle.setAttribute('aria-pressed','true');
        panel.hidden = false;
        updatePanel();
      } else panel.hidden = !panel.hidden;
    });
    panel.querySelector('[data-measure-close]').addEventListener('click', () => { panel.hidden = true; toggle.focus(); });
    panel.querySelector('[data-measure-stop]').addEventListener('click', stop);
    panel.addEventListener('change', updatePanel);
    panel.querySelectorAll('[data-angle]').forEach(button => button.addEventListener('click', () => { control('angle').value = button.dataset.angle; }));
    panel.querySelectorAll('[data-beam]').forEach(button => button.addEventListener('click', () => {
      const target = context();
      if (target) beamWidth.value = settingsFor(target).scale*Number(button.dataset.beam);
    }));
    document.addEventListener('keydown', event => {
      if (active && event.key === 'Escape' && !event.defaultPrevented && !event.target.closest('dialog')) stop();
    });
    function position(event, settings, snap) {
      const box = grid.querySelector('.board-world').getBoundingClientRect();
      const point = align({x:(event.clientX-box.left)/box.width*settings.width,y:(event.clientY-box.top)/box.height*settings.height},settings,snap);
      return [point.x,point.y];
    }
    grid.addEventListener('pointerdown', event => {
      if (event.target.closest('.board-entity-resize')) return;
      if (!active || event.button !== 0 || draft || !overlay()) return;
      const target = context();
      if (!target) return;
      const settings = settingsFor(target), shape = control('shape').value;
      if (shape === 'cone' && !control('lock').checked && !control('angle').reportValidity()) return;
      if (shape === 'beam' && (!beamWidth.value || !beamWidth.reportValidity())) return;
      event.preventDefault();
      status.textContent = '';
      completed?.node?.remove();
      completed = null;
      const snap = control('snap').value;
      const start = position(event, settings, snap);
      draft = {target,settings,snap,pointerId:event.pointerId,share:control('share').checked && canShare(target),
        measurement:{shape,start,end:start,origin:control('origin').value,coneEnd:control('coneEnd').value,
          angle:shape === 'cone' && !control('lock').checked ? Number(control('angle').value) || 45 : 45,
          lock:control('lock').checked,beamWidth:shape === 'beam' ? Number(beamWidth.value) : settings.scale,
          linger:control('fade').value === 'linger'}};
      grid.setPointerCapture(event.pointerId);
    });
    function move(event) {
      if (draft?.pointerId !== event.pointerId || !overlay()) return;
      preview?.remove();
      draft.measurement.end = position(event,draft.settings,draft.snap);
      preview = renderMeasure(overlay(),draft.measurement,draft.settings);
    }
    grid.addEventListener('pointermove',move);
    grid.addEventListener('pointerup',event => {
      if (draft?.pointerId !== event.pointerId || !overlay()) return;
      move(event);
      const {target, settings, measurement, share} = draft;
      const visible = !!preview;
      clearDraft();
      if (!visible) return;
      const duration = measurement.linger ? 8000 : 900;
      completed = {pageId:target.pageId, settings, measurement, expires:performance.now()+duration,
        node:renderMeasure(overlay(),measurement,settings,duration)};
      if (share) publish(target,measurement).catch(error => { status.textContent = error.message; });
    });
    grid.addEventListener('pointercancel',clearDraft);
    grid.addEventListener('lostpointercapture',clearDraft);
    return {
      stop,
      refresh(page, updateRemote = false) {
        if (draft && (!page || page.id !== draft.target.pageId || context()?.layer !== draft.target.layer ||
            page.settingsRevision !== draft.target.settingsRevision)) clearDraft();
        updatePanel();
        if (updateRemote) {
          const now = performance.now();
          remote = (page?.measurements ?? []).map(measurement => ({measurement, expires:now+measurement.remainingMs}));
        }
        const target = overlay();
        if (!target || (scene === target && !updateRemote)) return;
        scene = target;
        target.replaceChildren();
        const settings = {...defaults,...page.settings}, now = performance.now();
        for (const entry of remote) if (entry.expires > now) renderMeasure(target,entry.measurement,settings,entry.expires-now);
        if (completed?.pageId === page.id && completed.expires > now) {
          completed.node = renderMeasure(target,completed.measurement,completed.settings,completed.expires-now);
        } else completed = null;
        if (draft) preview = renderMeasure(target,draft.measurement,draft.settings);
      },
    };
  };
})();
