(() => {
  const root=document.querySelector('#table-workspace');if(!root)return;
  const diagnostics = window.MapTableDiagnostics;
  const toggle=root.querySelector('#board-pages-toggle'),menu=root.querySelector('#board-pages-menu');
  const list=root.querySelector('#board-pages-list'),menuStatus=root.querySelector('#board-menu-status');
  const grid=root.querySelector('#table-grid'),title=root.querySelector('#board-page-name'),status=root.querySelector('#board-display-status');
  const campaignId = Number(root.dataset.campaignId);
  function entityImageUrl(entityId, playerPreview=false) {
    const url = new URL(root.dataset.boardApi,location.href);
    url.searchParams.set('campaignId',campaignId);
    url.searchParams.set('entityImage',entityId);
    if (playerPreview) url.searchParams.set('playerPreview','1');
    return url.href;
  }
  const renderer=window.MapBoardRenderer.create(grid,entityImageUrl),toolbar=root.querySelector('#board-page-toolbar');
  const navigation = window.createBoardNavigation({grid,controls:root.querySelector('#board-view-controls'),renderer});
  const playerPreview = document.querySelector('#board-player-preview');
  const previewToggle = root.querySelector('#board-player-preview-toggle');
  const previewGrid = playerPreview?.querySelector('[data-preview-grid]');
  const previewRenderer = previewGrid && window.MapBoardRenderer.create(previewGrid,id=>entityImageUrl(id,true));
  let previewRequest = null, refreshPreviewAgain = false, previewRevision = null;
  if (playerPreview) {
    window.createBoardNavigation({
      grid:previewGrid,controls:playerPreview.querySelector('[data-preview-controls]'),renderer:previewRenderer,dragByDefault:true,
    });
    previewToggle.addEventListener('click',()=>{
      if (!board?.canManage) return;
      closeMenu();
      previewGrid.replaceChildren();
      playerPreview.querySelector('[data-preview-page-name]').textContent = '';
      playerPreview.querySelector('[data-preview-status]').textContent = 'Chargement…';
      previewRevision = null;
      previewRenderer.resetCamera();
      playerPreview.showModal();
      refreshPlayerPreview();
    });
    playerPreview.addEventListener('close',()=>{
      previewRequest?.abort();
      previewRequest = null;
      refreshPreviewAgain = false;
    });
  }
  async function refreshPlayerPreview() {
    if (!playerPreview?.open) return;
    if (previewRequest) { refreshPreviewAgain = true; return; }
    const controller = new AbortController();
    previewRequest = controller;
    const url = new URL(root.dataset.boardApi,location.href);
    url.searchParams.set('campaignId',campaignId);
    url.searchParams.set('playerPreview','1');
    const status = playerPreview.querySelector('[data-preview-status]');
    try {
      const {response, payload:result} = await diagnostics.json('board.preview',url,{signal:controller.signal,cache:'no-store'});
      if (!response.ok || !result.success) throw new Error(result.error || 'Aperçu indisponible.');
      if (!playerPreview.open || controller.signal.aborted) return;
      const {activePage,revision} = result.data.board;
      previewRevision = revision;
      previewRenderer.render(activePage,false,true);
      previewRenderer.refreshPings(activePage);
      window.renderBoardSharedMeasurements(previewGrid.querySelector('.board-measure-layer'),activePage);
      playerPreview.querySelector('[data-preview-page-name]').textContent = activePage.name;
      status.textContent = '';
    } catch (error) {
      if (!controller.signal.aborted) {
        previewGrid.replaceChildren();
        status.textContent = error.message;
      }
    } finally {
      if (previewRequest === controller) {
        previewRequest = null;
        if (refreshPreviewAgain) {
          refreshPreviewAgain = false;
          if (previewRevision !== board?.revision) refreshPlayerPreview();
        }
      }
    }
  }
  let alignmentPageId = null;
  let closeAlignment = null;
  let activeLayer = 'players';
  const layerButtons = [...root.querySelectorAll('[data-board-layer]')];
  const canEditMap = () => board?.canManage && activeLayer === 'map';
  function updateLayerControls() {
    renderer.setActiveLayer(activeLayer);
    for (const button of layerButtons) {
      const layer = button.dataset.boardLayer;
      button.hidden = layer !== 'players' && !board?.canManage;
      button.setAttribute('aria-pressed', String(layer === activeLayer));
    }
    if (toolbar) toolbar.hidden = !canEditMap() || alignmentPageId !== null;
    drawingTools.refresh();
    entityTools.refresh();
    selectionTools.refresh();
  }
  for (const button of layerButtons) {
    button.addEventListener('click', () => {
      const layer = button.dataset.boardLayer;
      if (layer === activeLayer || (layer !== 'players' && !board?.canManage)) return;
      if (activeLayer === 'map' && !window.MapBoardSettings.closeAll()) return;
      activeLayer = layer;
      closeAlignment?.();
      updateLayerControls();
      measureTools.refresh(board?.activePage);
      renderMenu();
    });
  }
  let board=null,busy=false,draggingPlayers=false,suppressClickUntil=0;
  const drawingTools = window.createBoardDrawingTools({
    root, grid, renderer,
    context: () => board && alignmentPageId === null && ({
      pageId: board.activePage.id,
      layer: activeLayer,
      canManage: board.canManage,
      settings: board.activePage.settings,
      settingsRevision: board.activePage.settingsRevision,
    }),
    action: (name, {pageId, layer, drawing}) => action(name, {pageId, layer, drawing}),
    onSelect: () => { navigation.releaseHand(); measureTools.stop(); selectionTools.stop(); },
    onError: error => { status.textContent = error.message; },
  });
  const measureTools = window.createBoardMeasureTools({
    root, grid,
    context: () => board && alignmentPageId === null && ({
      pageId: board.activePage.id, playerPageId: board.playerPageId, layer: activeLayer,
      settings: board.activePage.settings, settingsRevision: board.activePage.settingsRevision,
    }),
    onSelect: () => { navigation.releaseHand(); drawingTools.stop(); selectionTools.stop(); },
    publish: ({pageId,layer}, measurement) => action('measure',{pageId,layer,measurement}),
  });
  const entityTools = window.createBoardEntityTools({
    grid, renderer, campaignId, action,
    context: () => board && alignmentPageId === null && ({page:board.activePage,layer:activeLayer}),
    onError: error => { status.textContent = error.message; },
  });
  const tokenTools = window.MapBoardTokens.create({grid,root,action,onError:error=>{ status.textContent = error.message; }});
  const selectionTools = window.createBoardSelectionTools({
    root, grid, action,
    context: () => board && alignmentPageId === null && ({page:board.activePage,layer:activeLayer}),
    onSelect: () => { navigation.releaseHand(); drawingTools.stop(); measureTools.stop(); },
    onTokenSelection: tokenTools.selected,
    onError: error => { status.textContent = error.message; },
  });
  const turnContext = () => board && alignmentPageId === null ? {page:board.activePage,canManage:board.canManage,layer:activeLayer} : null;
  const turns = window.createBoardTurns({
    root,action,context:turnContext,selectedEntity:selectionTools.selectedEntity,entityImageUrl,
  });
  const contextMenu = window.createBoardContextMenu({
    grid, action,
    context: turnContext,
    selectedEntity: selectionTools.selectedEntity,
    openSheet: tokenTools.openSheet,
    addTurn: turns.add,
    onError: error => { status.textContent = error.message; },
  });
  const playersDragType='application/x-atlas-player-page';
  function clearDrag(){
    draggingPlayers=false;suppressClickUntil=performance.now()+250;
    list?.querySelectorAll('.board-page-drop-target').forEach(card=>card.classList.remove('board-page-drop-target'));
  }
  const make=(tag,text,css)=>{const n=document.createElement(tag);if(text)n.textContent=text;if(css)n.className=css;return n;};
  const button=(text,callback)=>{const n=make('button',text,'button button-secondary button-small');n.type='button';n.addEventListener('click',callback);return n;};
  function closeMenu(){if(!menu)return;menu.hidden=true;toggle.setAttribute('aria-expanded','false');}
  toggle?.addEventListener('click',()=>{menu.hidden=!menu.hidden;toggle.setAttribute('aria-expanded',String(!menu.hidden));});
  document.addEventListener('click',event=>{if(menu&&!menu.hidden&&!event.composedPath().includes(menu)&&!event.composedPath().includes(toggle))closeMenu();});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&menu&&!menu.hidden){closeMenu();toggle.focus();}});
  async function action(action,payload={}){
    if(busy)throw new Error('Une action est déjà en cours.');
    busy=true;renderMenu();
    try{
      const label = action === 'draw' ? 'board.draw.' + payload.drawing.type : 'board.' + action;
      const {response, payload:result} = await diagnostics.json(label,root.dataset.boardApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrfToken},body:JSON.stringify({action,campaignId:Number(root.dataset.campaignId),...payload})});
      if(!response.ok||!result.success)throw new Error(result.error||'Action impossible.');
      if (result.data.board) diagnostics.measure(label, () => apply(result.data.board,true), {revision:result.data.board.revision});
      return result.data.board;
    }finally{busy=false;renderMenu();}
  }
  async function run(task){try{await task();}catch(error){if(menuStatus)menuStatus.textContent=error.message;status.textContent=error.message;}}
  function apply(next,force=false){
    if(board && (next.revision<board.revision || (!force&&next.revision===board.revision)))return;
    board=next;window.MapBoard.revision=next.revision;
    if (previewToggle) previewToggle.hidden = !next.canManage;
    if (playerPreview?.open) {
      if (!next.canManage) playerPreview.close();
      else if (previewRevision !== next.revision) refreshPlayerPreview();
    }
    if (alignmentPageId !== null && (alignmentPageId !== next.activePage.id || !next.canManage)) closeAlignment?.();
    title.textContent=next.activePage.name;
    const playerPage=next.pages.find(page=>page.id===next.playerPageId);
    title.title=next.canManage?'Joueurs : '+(playerPage?.name||next.activePage.name):next.activePage.name;
    if (!next.canManage) activeLayer = 'players';
    if (alignmentPageId !== next.activePage.id) {
      renderer.render(next.activePage, next.canManage);
      renderer.refreshPings(next.activePage);
    }
    contextMenu.refresh(next.activePage, next.participantColor);
    updateLayerControls();
    turns.refresh(next.activePage,next.canManage);
    measureTools.refresh(next.activePage, true);
    status.textContent='';
    renderMenu();
  }
  function renderMenu(){
    if(!list||!board||draggingPlayers)return;
    list.replaceChildren();
    menuStatus.textContent='Cliquez sur une page pour la consulter. Glissez le repère jaune sur une page pour y envoyer les joueurs.';
    root.querySelector('#board-create-page').disabled=busy;
    let lastFolder=null;
    [...board.pages].sort((a,b)=>(a.folder||'').localeCompare(b.folder||'','fr')||a.id-b.id).forEach(page=>{
      const folder=page.folder||'';
      if(folder!==lastFolder){const heading=make('h4',folder||'Toutes les pages','board-page-folder');list.append(heading);lastFolder=folder;}
      const card=make('article',null,'board-page-card');card.classList.toggle('board-page-current',page.id===board.activePage.id);
      const preview=make('button',null,'board-page-preview');preview.type='button';preview.disabled=busy;preview.setAttribute('aria-label','Ouvrir la page '+page.name);preview.setAttribute('aria-pressed',String(page.id===board.activePage.id));
      if(page.thumbnail){const img=make('img');img.src=page.thumbnail;img.alt='';img.loading='lazy';img.draggable=false;preview.append(img);}else preview.append(make('span','▦','board-page-placeholder'));
      if(page.id===board.playerPageId){
        const ribbon=make('span','⚑ Joueurs','board-player-ribbon');
        ribbon.title='Glisser sur une autre page pour y envoyer les joueurs';ribbon.draggable=!busy;
        // The marker sits beside the navigation button so it is independently focusable.
        ribbon.tabIndex=busy?-1:0;ribbon.setAttribute('role','button');ribbon.setAttribute('aria-label','Page des joueurs : '+page.name+'. Glisser sur une page, ou utiliser les flèches gauche et droite pour déplacer les joueurs.');
        ribbon.addEventListener('click',event=>event.stopPropagation());
        ribbon.addEventListener('dragstart',event=>{
          if(busy){event.preventDefault();return;}
          draggingPlayers=true;event.stopPropagation();event.dataTransfer.effectAllowed='move';
          event.dataTransfer.setData(playersDragType,JSON.stringify({campaignId:Number(root.dataset.campaignId),sourcePageId:page.id}));
        });
        ribbon.addEventListener('dragend',()=>{clearDrag();renderMenu();});
        ribbon.addEventListener('keydown',event=>{
          if(busy||!['ArrowLeft','ArrowRight'].includes(event.key))return;
          event.preventDefault();event.stopPropagation();
          const index=board.pages.findIndex(p=>p.id===board.playerPageId),target=board.pages[index+(event.key==='ArrowRight'?1:-1)];
          if(target)run(async()=>{await action('send',{pageId:target.id});list.querySelector('.board-player-ribbon')?.focus();});
        });
        card.append(ribbon);
      }
      card.addEventListener('click',event=>{
        if(busy||performance.now()<suppressClickUntil||event.target.closest('.board-player-ribbon,.board-page-actions'))return;
        if(page.id!==board.activePage.id)run(()=>action('go',{pageId:page.id}));
      });
      card.addEventListener('dragover',event=>{
        if(busy||![...event.dataTransfer.types].includes(playersDragType))return;
        event.preventDefault();event.dataTransfer.dropEffect='move';card.classList.add('board-page-drop-target');
      });
      card.addEventListener('dragleave',event=>{if(!card.contains(event.relatedTarget))card.classList.remove('board-page-drop-target');});
      card.addEventListener('drop',event=>{
        if(![...event.dataTransfer.types].includes(playersDragType))return;
        event.preventDefault();event.stopPropagation();clearDrag();
        try{
          const data=JSON.parse(event.dataTransfer.getData(playersDragType));
          if(data.campaignId!==Number(root.dataset.campaignId)||busy||page.id===board.playerPageId){renderMenu();return;}
          run(()=>action('send',{pageId:page.id}));
        }catch(_){renderMenu();}
      });
      card.append(preview,make('strong',page.name));
      const actions=make('div',null,'board-page-actions');
      const remove=button('Supprimer',()=>{
        const warning=page.id===board.playerPageId?' Les joueurs seront dirigés vers une autre page.':'';
        if(window.confirm('Supprimer la page « '+page.name+' » ?'+warning))run(()=>action('delete',{pageId:page.id}));
      });remove.disabled=busy||board.pages.length<=1;remove.classList.add('board-delete-page');
      const settings=button('⚙ Paramètres',()=>openSettings(page.id,'all'));settings.disabled=busy;
      if (canEditMap()) actions.append(settings);
      actions.append(remove);card.append(actions);list.append(card);
    });
  }
  root.querySelector('#board-create-page')?.addEventListener('click',()=>{
    const dialog=make('dialog',null,'library-modal library-small-modal');document.body.append(dialog);
    const floating=window.MapWindows.enhance(dialog,'Créer une page');
    const form=make('form',null,'board-page-form');
    const nameLabel=make('label','Nom de la page'),name=make('input');name.required=true;name.maxLength=160;name.value='Nouvelle page';nameLabel.append(name);
    const imageLabel=make('label','Carte de fond (facultatif)'),image=make('input');image.type='file';image.accept='image/png,image/jpeg,image/gif,image/webp,image/avif';imageLabel.append(image);
    const help=make('p','Choisissez une image pour la carte, ou créez une page vide.','muted');
    const message=make('p',null,'board-form-status');message.setAttribute('aria-live','polite');
    const submit=make('button','Créer la page','button');submit.type='submit';form.append(nameLabel,imageLabel,help,message,submit);floating.body.append(form);
    let creating=false;
    dialog.addEventListener('cancel',event=>{if(creating)event.preventDefault();});dialog.addEventListener('close',()=>dialog.remove());
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(creating)return;creating=true;submit.disabled=true;
      try{
        let background='',thumbnail='',dominantColor='#eee9da';const file=image.files[0];
        if(file){
          message.textContent='Préparation de la carte…';
          const result=await window.MapBoardImages.read(file);background=result.image;thumbnail=result.thumbnail;dominantColor=result.dominantColor;
        }
        message.textContent='Enregistrement…';await action('create',{name:name.value.trim(),image:background,thumbnail,dominantColor});dialog.close();
      }catch(error){message.textContent=error.message;}
      finally{creating=false;submit.disabled=false;}
    });
    floating.open();name.focus();name.select();
  });
  async function openSettings(pageId,section){
    if(!canEditMap()||busy)return;
    await run(async()=>{
      let page=board.activePage;
      if(page.id!==pageId){const url=new URL(root.dataset.boardApi,location.href);url.searchParams.set('campaignId',root.dataset.campaignId);url.searchParams.set('pageId',pageId);const response=await fetch(url,{credentials:'same-origin',cache:'no-store'});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.error||'Page inaccessible.');page=result.data.page;}
      if (!canEditMap()) return;
      window.MapBoardSettings.open({page,section,folders:[...new Set(board.pages.map(p=>p.folder).filter(Boolean))],onSave:payload=>{
        if (!canEditMap()) throw new Error('Sélectionnez le calque Carte pour modifier la page.');
        return action('configure',payload);
      },onPreview:draft=>{if(canEditMap() && board.activePage.id===draft.id && alignmentPageId===null)renderer.render(draft,true);},onClose:()=>{if(board.activePage.id===page.id && alignmentPageId===null)renderer.render(board.activePage,board.canManage);}});
    });
  }
  function openAlignment(trigger) {
    if (!canEditMap() || alignmentPageId !== null) return;
    drawingTools.stop();
    measureTools.stop();
    selectionTools.stop();
    const page = board.activePage;
    navigation.setEnabled(false);
    renderer.render(page, true);
    closeMenu();
    alignmentPageId = page.id;
    const opened = window.openBoardAlignment({
      viewport: grid,
      page,
      save: settings => action('configure', { pageId: page.id, settingsRevision: page.settingsRevision, settings }),
      close: () => {
        alignmentPageId = null;
        closeAlignment = null;
        navigation.setEnabled(true);
        updateLayerControls();
        renderer.render(board.activePage, board.canManage);
        if (canEditMap()) trigger.focus();
      },
    });
    if (opened) {
      closeAlignment = opened;
      toolbar.hidden = true;
    }
    else {
      alignmentPageId = null;
      navigation.setEnabled(true);
      status.textContent = 'Affichez une image de fond sur cette page pour aligner la grille.';
    }
  }
  toolbar?.querySelectorAll('[data-board-tool]').forEach(button => button.addEventListener('click', () => {
    if (!board) return;
    if (button.dataset.boardTool === 'align') openAlignment(button);
    else openSettings(board.activePage.id, button.dataset.boardTool);
  }));
  const handle=toolbar?.querySelector('.board-toolbar-handle');
  handle?.addEventListener('pointerdown',event=>{
    if(event.button!==0)return;event.preventDefault();const parent=toolbar.parentElement,outer=parent.getBoundingClientRect(),rect=toolbar.getBoundingClientRect(),x=event.clientX,y=event.clientY;
    toolbar.style.left=(rect.left-outer.left)+'px';toolbar.style.top=(rect.top-outer.top)+'px';toolbar.style.bottom='auto';toolbar.style.transform='none';handle.setPointerCapture(event.pointerId);
    const move=e=>{toolbar.style.left=Math.max(0,Math.min(parent.clientWidth-toolbar.offsetWidth,rect.left-outer.left+e.clientX-x))+'px';toolbar.style.top=Math.max(0,Math.min(parent.clientHeight-toolbar.offsetHeight,rect.top-outer.top+e.clientY-y))+'px';};
    const end=()=>{handle.removeEventListener('pointermove',move);handle.removeEventListener('pointerup',end);handle.removeEventListener('pointercancel',end);};handle.addEventListener('pointermove',move);handle.addEventListener('pointerup',end);handle.addEventListener('pointercancel',end);
  });
  window.MapBoard={revision:null,apply,openSettings};
  updateLayerControls();
})();
