(() => {
  const selector = '[data-select-kind][data-select-id]';
  const keyFor = node => node.dataset.selectKind + ':' + node.dataset.selectId;
  const clamp = (value,min,max) => Math.max(min,Math.min(max,value));

  window.createBoardSelectionTools = ({root,grid,context,action,onSelect,onError,onTokenSelection = () => {}}) => {
    const toggle = root.querySelector('#board-select-toggle');
    const selected = new Set();
    let enabled = false, gesture = null, scope = null, lastClick = null, saving = false;
    let pendingMove = null;
    const marquee = document.createElement('div');
    marquee.className = 'board-selection-marquee';
    marquee.setAttribute('aria-hidden','true');

    function candidates() {
      const layer = context()?.layer;
      return [...grid.querySelectorAll(selector)].filter(node => node.closest('.board-content-layer')?.dataset.layer === layer);
    }
    function highlight() {
      for (const node of grid.querySelectorAll(selector)) {
        node.classList.toggle('board-selected',enabled && selected.has(keyFor(node)));
      }
      const target = context();
      const key = enabled && selected.size === 1 ? [...selected][0] : null;
      const item = key?.startsWith('entity:') && target ? model(target,key) : null;
      onTokenSelection(item?.character ? item : null,target);
    }
    function resetPreview() {
      marquee.remove();
      for (const node of candidates()) {
        if (node.dataset.selectKind === 'drawing') node.removeAttribute('transform');
        else node.style.translate = '';
      }
    }
    function endGesture(keepPreview = false) {
      if (!gesture) return;
      const {pointerId} = gesture;
      gesture = null;
      if (!keepPreview) resetPreview();
      if (grid.hasPointerCapture(pointerId)) grid.releasePointerCapture(pointerId);
    }
    const cancelGesture = () => endGesture();
    const itemsForSelection = () => [...selected].map(key => {
      const [kind,id] = key.split(':');
      return {kind,id:Number(id)};
    });
    function stop() {
      enabled = false;
      cancelGesture();
      selected.clear();
      lastClick = null;
      grid.classList.remove('board-selecting');
      toggle.setAttribute('aria-pressed','false');
      highlight();
    }
    toggle.addEventListener('click', () => {
      if (enabled) { stop(); return; }
      if (!context()) return;
      onSelect();
      enabled = true;
      grid.classList.add('board-selecting');
      toggle.setAttribute('aria-pressed','true');
    });
    document.addEventListener('keydown',async event => {
      if (!enabled || event.defaultPrevented || event.target.closest('dialog,input,textarea,select,[contenteditable="true"]')) return;
      if (event.key === 'Escape') {
        cancelGesture();
        selected.clear();
        highlight();
      }
      if (event.key !== 'Delete' || saving || gesture || !selected.size) return;
      const target = context();
      const items = itemsForSelection();
      if (!target) return;
      event.preventDefault();
      saving = true;
      try {
        await action('delete_board_selection',{pageId:target.page.id,layer:target.layer,items});
      } catch (error) {
        onError(error);
      } finally {
        saving = false;
      }
    });
    function model(target, key) {
      const [kind,id] = key.split(':');
      const items = kind === 'drawing' ? target.page.drawings?.[target.layer] : target.page.entities;
      return items?.find(item => item.id === Number(id) && (kind === 'drawing' ? item.canEdit : item.canResize && item.layer === target.layer));
    }
    const anchor = item => item.points?.[0] ?? [item.x,item.y];
    function translate(node,[x,y]) {
      if (node.dataset.selectKind === 'drawing') node.setAttribute('transform','translate('+x+' '+y+')');
      else node.style.translate = x+'px '+y+'px';
    }
    function restorePendingPreview(target) {
      if (!pendingMove || target?.page.id !== pendingMove.pageId || target.layer !== pendingMove.layer) return;
      for (const node of candidates()) {
        const key = keyFor(node);
        const destination = pendingMove.destinations.get(key);
        const item = model(target,key);
        if (!destination || !item) continue;
        const [x,y] = anchor(item);
        translate(node,[destination[0]-x,destination[1]-y]);
      }
    }
    function editText(target,key,point) {
      const drawing = model(target,key);
      if (!drawing || !key.startsWith('drawing:') || drawing.type !== 'text') return;
      const dialog = document.createElement('dialog');
      dialog.className = 'library-modal library-small-modal';
      dialog.setAttribute('aria-label','Modifier le texte');
      document.body.append(dialog);
      const window = globalThis.MapWindows.enhance(dialog,'Modifier le texte');
      const form = document.createElement('form');
      form.className = 'board-page-form';
      const input = document.createElement('textarea');
      input.setAttribute('aria-label','Texte');
      input.required = true;
      input.maxLength = 500;
      input.rows = 4;
      input.value = drawing.text;
      const submit = document.createElement('button');
      submit.className = 'button';
      submit.textContent = 'Enregistrer';
      const errorOutput = document.createElement('output');
      errorOutput.setAttribute('aria-live','polite');
      form.append(input,submit,errorOutput);
      window.body.append(form);
      dialog.addEventListener('close',()=>dialog.remove());
      form.addEventListener('submit',async event => {
        event.preventDefault();
        if (submit.disabled || !input.value.trim()) return;
        submit.disabled = true;
        try {
          await action('edit_board_text',{pageId:target.page.id,layer:target.layer,drawingId:drawing.id,text:input.value});
          dialog.close();
        } catch (error) {
          errorOutput.textContent = error.message;
        } finally {
          submit.disabled = false;
        }
      });
      window.open();
      const bounds = dialog.getBoundingClientRect();
      dialog.style.left = clamp(point.x,8,Math.max(8,innerWidth-bounds.width-8))+'px';
      dialog.style.top = clamp(point.y,8,Math.max(8,innerHeight-bounds.height-8))+'px';
      input.focus();
    }
    function allowedDelta(target,dx,dy) {
      const settings = {...window.MapBoardRenderer.defaults,...target.page.settings};
      let minX=Infinity,minY=Infinity,maxX=-Infinity,maxY=-Infinity;
      for (const key of selected) {
        const item = model(target,key);
        if (!item) continue;
        for (const [x,y] of item.points ?? [[item.x,item.y]]) {
          minX=Math.min(minX,x); minY=Math.min(minY,y);
          maxX=Math.max(maxX,x); maxY=Math.max(maxY,y);
        }
      }
      return [clamp(dx,-minX,settings.width-maxX),clamp(dy,-minY,settings.height-maxY)];
    }
    grid.addEventListener('pointerdown',event => {
      if (!enabled || saving || gesture || event.button !== 0 || event.target.closest('.board-entity-resize,.board-token-controls')) return;
      const target = context();
      const world = grid.querySelector('.board-world');
      if (!target || !world) return;
      const hit = event.target.closest(selector);
      const key = hit && hit.closest('.board-content-layer')?.dataset.layer === target.layer ? keyFor(hit) : null;
      event.preventDefault();
      if (key) {
        if (event.shiftKey) {
          if (selected.has(key)) selected.delete(key);
          else selected.add(key);
          highlight();
          return;
        }
        if (!selected.has(key)) { selected.clear(); selected.add(key); }
      } else if (!event.shiftKey) selected.clear();
      highlight();
      const scale = world.getBoundingClientRect().width / world.offsetWidth;
      if (!scale) return;
      gesture = {target,key,scale,pointerId:event.pointerId,x:event.clientX,y:event.clientY,
        moved:false,delta:[0,0],initial:new Set(selected)};
      grid.setPointerCapture(event.pointerId);
    });
    function move(event) {
      if (gesture?.pointerId !== event.pointerId) return;
      const dx = event.clientX-gesture.x, dy = event.clientY-gesture.y;
      if (!gesture.moved && Math.hypot(dx,dy)<4) return;
      gesture.moved = true;
      if (gesture.key) {
        const delta = allowedDelta(gesture.target,dx/gesture.scale,dy/gesture.scale);
        gesture.delta = delta;
        for (const node of candidates()) {
          if (!selected.has(keyFor(node))) continue;
          translate(node,delta);
        }
      } else {
        const left=Math.min(gesture.x,event.clientX), top=Math.min(gesture.y,event.clientY);
        const right=Math.max(gesture.x,event.clientX), bottom=Math.max(gesture.y,event.clientY);
        const area=grid.getBoundingClientRect();
        Object.assign(marquee.style,{left:(left-area.left)+'px',top:(top-area.top)+'px',width:(right-left)+'px',height:(bottom-top)+'px'});
        if (!marquee.isConnected) grid.append(marquee);
        selected.clear();
        gesture.initial.forEach(key=>selected.add(key));
        for (const node of candidates()) {
          const rect=node.getBoundingClientRect();
          if (rect.left<=right && rect.right>=left && rect.top<=bottom && rect.bottom>=top) selected.add(keyFor(node));
        }
        highlight();
      }
    }
    grid.addEventListener('pointermove',move);
    grid.addEventListener('pointerup',async event => {
      if (gesture?.pointerId !== event.pointerId) return;
      move(event);
      const {key,target,moved,delta} = gesture;
      const committing = key && moved && delta.some(value=>Math.abs(value)>=.01);
      endGesture(committing);
      if (committing) {
        lastClick = null;
        const items = itemsForSelection();
        const destinations = new Map();
        selected.forEach(key => {
          const item = model(target,key);
          if (item) destinations.set(key,anchor(item).map((value,index)=>value+delta[index]));
        });
        pendingMove = {pageId:target.page.id,layer:target.layer,destinations};
        saving = true;
        try {
          await action('move_board_selection',{pageId:target.page.id,layer:target.layer,items,delta});
        } catch (error) {
          onError(error);
        } finally {
          pendingMove = null;
          resetPreview();
          saving = false;
        }
      } else if (key && !moved) {
        const now = performance.now();
        if (lastClick?.key === key && now-lastClick.time < 400) {
          lastClick = null;
          editText(target,key,{x:event.clientX,y:event.clientY});
        } else lastClick = {key,time:now};
      } else lastClick = null;
    });
    grid.addEventListener('pointercancel',cancelGesture);
    grid.addEventListener('lostpointercapture',cancelGesture);
    return {
      stop,
      selectedEntity() {
        if (!enabled || gesture || saving || selected.size !== 1) return null;
        const [key] = selected;
        const target = context();
        return target && key.startsWith('entity:') ? model(target,key) : null;
      },
      refresh() {
        const target = context();
        const next = target ? target.page.id+':'+target.layer : null;
        if (scope !== next) { selected.clear(); lastClick = null; scope = next; }
        if (gesture) cancelGesture();
        for (const key of selected) if (!target || !model(target,key)) selected.delete(key);
        highlight();
        restorePendingPreview(target);
      },
    };
  };
})();
