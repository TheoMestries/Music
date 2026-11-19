(() => {
  window.createBoardNavigation = ({grid,controls,renderer,dragByDefault=false}) => {
    const indicator = controls.querySelector('[data-board-zoom-current]');
    const zoomIn = controls.querySelector('[data-board-zoom-in]');
    const zoomOut = controls.querySelector('[data-board-zoom-out]');
    const hand = controls.querySelector('[data-board-pan]');
    const reset = controls.querySelector('[data-board-reset-view]');
    let enabled = true, dragging = null;
    let handActive = dragByDefault;

    function update() {
      const zoom = renderer.getZoom();
      indicator.textContent = Math.round(zoom*100)+' %';
      zoomIn.disabled = zoom >= 8;
      zoomOut.disabled = zoom <= .5;
      grid.classList.toggle('board-pan-ready', enabled && handActive);
      hand?.setAttribute('aria-pressed',String(handActive));
    }
    grid.addEventListener('board-zoom-change',update);
    reset.addEventListener('click',renderer.resetCamera);
    zoomIn.addEventListener('click',()=>renderer.changeZoom(1.25));
    zoomOut.addEventListener('click',()=>renderer.changeZoom(1/1.25));
    hand?.addEventListener('click',()=>{
      handActive = !handActive;
      update();
    });
    grid.addEventListener('wheel',event=>{
      if (!enabled || !event.deltaY || event.buttons || event.target.closest('button,input,select,textarea')) return;
      event.preventDefault();
      const delta = event.deltaY * (event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? grid.clientHeight : 1);
      renderer.changeZoom(Math.exp(-Math.max(-160,Math.min(160,delta))*.002),event.clientX,event.clientY);
    },{passive:false});
    grid.addEventListener('auxclick',event=>{
      if (enabled && event.button === 1) event.preventDefault();
    });
    grid.addEventListener('pointerdown',event=>{
      if (!enabled || !event.isPrimary || dragging || event.target.closest('button,input,select,textarea')
        || !grid.querySelector('.board-world') || (event.button !== 1 && !(event.button === 0 && handActive))) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      dragging = {id:event.pointerId,x:event.clientX,y:event.clientY};
      grid.setPointerCapture(event.pointerId);
      grid.classList.add('board-panning');
    },true);
    grid.addEventListener('pointermove',event=>{
      if (event.pointerId !== dragging?.id) return;
      renderer.pan(event.clientX-dragging.x,event.clientY-dragging.y);
      dragging.x = event.clientX;
      dragging.y = event.clientY;
    });
    function stopDrag() {
      if (!dragging) return;
      const {id} = dragging;
      dragging = null;
      grid.classList.remove('board-panning');
      if (grid.hasPointerCapture(id)) grid.releasePointerCapture(id);
    }
    for (const eventName of ['pointerup','pointercancel','lostpointercapture']) {
      grid.addEventListener(eventName,event=>{
        if (event.pointerId === dragging?.id) stopDrag();
      });
    }
    update();
    return {
      releaseHand() {
        handActive = false;
        update();
      },
      setEnabled(next) {
        if (enabled === next) return;
        enabled = next;
        controls.hidden = !enabled;
        if (!enabled) stopDrag();
        renderer.setCameraEnabled(enabled);
        update();
      },
    };
  };
})();
