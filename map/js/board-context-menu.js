(() => {
  window.createBoardContextMenu = ({grid, context, action, selectedEntity, openSheet, addTurn, onError}) => {
    const menu = document.createElement('div');
    menu.className = 'board-context-menu';
    menu.setAttribute('role', 'group');
    menu.setAttribute('aria-label', 'Actions du plateau');
    menu.hidden = true;
    let target = null;
    let pending = false;
    let drawingDefaultsSet = false;
    const root = grid.closest('#table-workspace');
    let hasClipboard = root.dataset.boardClipboard === '1';

    function close(restoreFocus = false) {
      if (menu.hidden) return;
      menu.hidden = true;
      target = null;
      if (restoreFocus) grid.focus({preventScroll:true});
    }

    function addButton(parent, label, run) {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
      button.addEventListener('click', async () => {
        if (!target || pending) return;
        const current = target;
        close(true);
        pending = true;
        try {
          await run(current);
        } catch (error) {
          onError(error);
        } finally {
          pending = false;
        }
      });
      parent.append(button);
      return button;
    }
    const entityPayload = ({pageId,entity}) => ({pageId,layer:entity.layer,entityId:entity.id});
    const ping = addButton(menu,'Envoyer un signal à tout le monde',({pageId,point}) => action('ping',{pageId,point}));
    const copy = addButton(menu,'Copier',async current => {
      await action('copy_entity',entityPayload(current));
      hasClipboard = true;
    });
    const paste = addButton(menu,'Coller',({pageId,layer,point}) => action('paste_entity',{pageId,layer,point}));
    const undo = addButton(menu,'Annuler',({pageId}) => action('undo',{pageId}));
    const redo = addButton(menu,'Rétablir',({pageId}) => action('redo',{pageId}));
    const sheet = addButton(menu,'Fiche de personnage',({entity}) => openSheet(entity.sheetNoteId,entity.name));
    const turn = addButton(menu,'Ajouter un tour',({entity}) => addTurn(entity));
    const layers = document.createElement('details');
    const layerSummary = document.createElement('summary');
    layerSummary.textContent = 'Changer de calque';
    layers.append(layerSummary);
    menu.append(layers);
    const layerOptions = Object.entries({players:'Joueurs',map:'Carte',gm:'MJ'}).map(([layer,label]) => [
      layer, addButton(layers,label,current => action('change_entity_layer',{...entityPayload(current),destinationLayer:layer})),
    ]);
    const front = addButton(menu,'Mettre au premier plan',current => action('order_entity',{...entityPayload(current),direction:'front'}));
    const back = addButton(menu,'Mettre en arrière',current => action('order_entity',{...entityPayload(current),direction:'back'}));
    const remove = addButton(menu,'Supprimer',({pageId,entity}) => action('delete_board_selection',{
      pageId,layer:entity.layer,items:[{kind:'entity',id:entity.id}],
    }));
    remove.classList.add('board-context-delete');
    document.body.append(menu);
    grid.tabIndex = 0;

    function positionMenu(x,y) {
      menu.style.left = Math.max(4, Math.min(x, document.documentElement.clientWidth - menu.offsetWidth - 4)) + 'px';
      menu.style.top = Math.max(4, Math.min(y, document.documentElement.clientHeight - menu.offsetHeight - 4)) + 'px';
    }
    layers.addEventListener('toggle', () => {
      if (target) positionMenu(...target.screen);
    });

    grid.addEventListener('contextmenu', event => {
      const view = context();
      const world = grid.querySelector('.board-world');
      if (!view || !world || event.target.closest('input, textarea, button, select, [contenteditable]')) return;
      const {page,canManage,layer} = view;
      event.preventDefault();
      const box = world.getBoundingClientRect();
      if (!box.width || !box.height || pending) return;
      const gridBox = grid.getBoundingClientRect();
      const x = event.button === 2 ? event.clientX : gridBox.left + gridBox.width / 2;
      const y = event.button === 2 ? event.clientY : gridBox.top + gridBox.height / 2;
      const entity = selectedEntity();
      const point = [
        (x - box.left) * world.offsetWidth / box.width,
        (y - box.top) * world.offsetHeight / box.height,
      ];
      target = {pageId:page.id,layer,entity,canManage,point,screen:[x,y]};
      copy.hidden = remove.hidden = !entity;
      paste.hidden = !entity && !hasClipboard;
      paste.disabled = !hasClipboard || point[0] < 0 || point[0] > world.offsetWidth
        || point[1] < 0 || point[1] > world.offsetHeight;
      sheet.hidden = !entity?.sheetNoteId;
      turn.hidden = !entity;
      turn.disabled = !!entity && !!page.turns?.entries.some(entry => entry.entityId === entity.id);
      layers.hidden = front.hidden = back.hidden = !entity || !canManage;
      layers.open = false;
      for (const [destination,button] of layerOptions) button.disabled = destination === entity?.layer;
      undo.disabled = !page.history?.undo;
      redo.disabled = !page.history?.redo;
      menu.hidden = false;
      positionMenu(x,y);
      ping.focus({preventScroll:true});
    });
    document.addEventListener('pointerdown', event => {
      if (!menu.contains(event.target)) close();
    });
    document.addEventListener('focusin', event => {
      if (!menu.contains(event.target)) close();
    });
    menu.addEventListener('keydown', event => {
      if (event.key !== 'Escape') return;
      event.stopPropagation();
      close(true);
    });
    window.addEventListener('resize', () => close());
    document.addEventListener('scroll', event => {
      if (!menu.contains(event.target)) close();
    }, true);

    function refresh(page, participantColor) {
      if (!drawingDefaultsSet && participantColor) {
        for (const input of root.querySelectorAll('#board-drawing-stroke, #board-drawing-fill')) {
          if (input.value === input.defaultValue) input.value = participantColor;
        }
        drawingDefaultsSet = true;
      }
      const view = context();
      const entity = selectedEntity();
      if (target && (!view || target.pageId !== page.id || target.layer !== view.layer || target.canManage !== view.canManage
          || target.entity?.id !== entity?.id || target.entity?.sheetNoteId !== entity?.sheetNoteId)) close(true);
      undo.disabled = !page.history?.undo;
      redo.disabled = !page.history?.redo;
      turn.disabled = !!entity && !!page.turns?.entries.some(entry => entry.entityId === entity.id);

    }

    return {refresh};
  };
})();
