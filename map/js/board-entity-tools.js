(() => {
  const dragType = 'application/x-atlas-board-entity';
  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

  window.createBoardEntityTools = ({grid, renderer, campaignId, context, action, onError}) => {
    let resizing = null;
    const settingsFor = ({page}) => ({...window.MapBoardRenderer.defaults, ...page.settings});
    const world = () => grid.querySelector('.board-world');
    const itemFor = id => grid.querySelector('.board-entity[data-entity-id="'+id+'"]');

    function locate(event, settings) {
      const rect = world().getBoundingClientRect();
      const x = (event.clientX-rect.left)/rect.width*settings.width;
      const y = (event.clientY-rect.top)/rect.height*settings.height;
      const snapped = window.MapBoardRenderer.snapPoint(settings,{x,y});
      return [clamp(snapped.x,0,settings.width),clamp(snapped.y,0,settings.height)];
    }
    function accepts(event) {
      return context() && world()?.contains(event.target) && event.dataTransfer?.types.includes(dragType);
    }
    grid.addEventListener('dragover', event => {
      if (!accepts(event)) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = 'copy';
    });
    grid.addEventListener('drop', async event => {
      if (!accepts(event)) return;
      event.preventDefault();
      const target = context();
      try {
        const {noteId,campaignId:source} = JSON.parse(event.dataTransfer.getData(dragType));
        if (source !== campaignId || !Number.isInteger(noteId) || noteId < 1) return;
        await action('place_entity',{noteId,pageId:target.page.id,layer:target.layer,point:locate(event,settingsFor(target))});
      } catch (error) {
        onError(error);
      }
    });

    function resolve(handle) {
      const target = context();
      if (!target || !handle || handle.closest('[inert]')) return null;
      const item = handle.closest('.board-entity');
      const entity = target.page.entities?.find(entry => entry.id === Number(item.dataset.entityId));
      return entity?.canResize && entity.layer === target.layer ? {target,entity,item} : null;
    }
    async function save(target, entity, cells) {
      try {
        await action('resize_entity',{pageId:target.page.id,layer:target.layer,entityId:entity.id,cells});
        itemFor(entity.id)?.querySelector('button')?.focus({preventScroll:true});
      } catch (error) {
        const item = itemFor(entity.id);
        if (item) renderer.setEntitySize(item,entity.cells);
        onError(error);
      }
    }
    grid.addEventListener('pointerdown', event => {
      if (event.button !== 0 || resizing) return;
      const hit = resolve(event.target.closest('.board-entity-resize'));
      if (!hit) return;
      event.preventDefault();
      const rect = hit.item.getBoundingClientRect();
      resizing = {...hit, pointerId:event.pointerId, x:event.clientX,y:event.clientY,
        width:rect.width,height:rect.height,cells:hit.entity.cells};
      grid.setPointerCapture(event.pointerId);
    });
    function move(event) {
      if (resizing?.pointerId !== event.pointerId) return;
      const deltaX = (event.clientX-resizing.x)/resizing.width;
      const deltaY = (event.clientY-resizing.y)/resizing.height;
      const delta = Math.abs(deltaX) > Math.abs(deltaY) ? deltaX : deltaY;
      resizing.cells = clamp(Math.round(resizing.entity.cells*(1+2*delta)*100)/100,.25,100);
      const item = itemFor(resizing.entity.id);
      if (item) renderer.setEntitySize(item,resizing.cells);
    }
    function clear() {
      if (!resizing) return;
      const {pointerId,entity} = resizing;
      resizing = null;
      if (grid.hasPointerCapture(pointerId)) grid.releasePointerCapture(pointerId);
      const item = itemFor(entity.id);
      if (item) renderer.setEntitySize(item,entity.cells);
    }
    grid.addEventListener('pointermove',move);
    grid.addEventListener('pointerup',event => {
      if (resizing?.pointerId !== event.pointerId) return;
      move(event);
      const {target,entity,cells} = resizing;
      resizing = null;
      if (grid.hasPointerCapture(event.pointerId)) grid.releasePointerCapture(event.pointerId);
      if (cells !== entity.cells) save(target,entity,cells);
    });
    grid.addEventListener('pointercancel',clear);
    grid.addEventListener('lostpointercapture',clear);
    grid.addEventListener('keydown',event => {
      if (!['ArrowRight','ArrowDown','ArrowLeft','ArrowUp'].includes(event.key)) return;
      const hit = resolve(event.target.closest('.board-entity-resize'));
      if (!hit) return;
      event.preventDefault();
      const step = event.shiftKey ? 1 : .25;
      const direction = ['ArrowRight','ArrowUp'].includes(event.key) ? 1 : -1;
      save(hit.target,hit.entity,clamp(hit.entity.cells+direction*step,.25,100));
    });
    return {
      refresh() {
        if (resizing) clear();
      },
    };
  };
})();
