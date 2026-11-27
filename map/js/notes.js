(() => {
  const root = document.querySelector('#campaign-notes-app');
  if (!root) return;

  const state = {
    notes: [], folders: [],
    activeNoteId: null,
    draft: null,
    savingDraft: false,
    saveTimer: null,
    savePromise: null,
    dirtyNoteIds: new Set(),
    editVersions: new Map(),
    conflicts: new Map(),
  };

  const campaignId = Number(root.dataset.campaignId);
  const requestedDocument = Number(new URLSearchParams(location.search).get('documentId')) || null;
  let libraryRevision = null;
  let libraryLoading = false;
  let pendingWrites = 0;
  let writeVersion = 0;
  const hostWindow = window.parent !== window ? window.parent : window;
  const registry = hostWindow.MapNoteWindows || (hostWindow.MapNoteWindows = new Map());
  const registryKey = id => String(campaignId) + ':' + String(id);
  function openIndependentNote(id, editing=false) {
    const key=registryKey(id), existing=registry.get(key);
    if(existing) { existing.focus?.(); return; }
    const frame=hostWindow.document.createElement('iframe');
    frame.hidden=true; frame.title='Document '+id;
    const url=new URL(location.href);
    url.searchParams.set('embedded','1');url.searchParams.set('documentId',String(id));
    if(editing)url.searchParams.set('edit','1');else url.searchParams.delete('edit');
    const note=state.notes.find(item=>item.id===id);
    const doc=hostWindow.document;
    const dialog=doc.createElement('dialog');dialog.className='library-modal';
    dialog.setAttribute('aria-label',note?.title || 'Document');doc.body.append(dialog);
    const preview=(hostWindow.MapWindows || window.MapWindows).enhance(dialog,note?.title || 'Document');
    const entry={frame,dialog,focus:()=>preview.open()};
    const status=doc.createElement('p');status.className='muted';status.textContent='Préparation des commandes…';preview.body.append(status);
    if(note){
      const article=doc.createElement('article');article.className='document-reading';
      if(note.options?.image){const img=doc.createElement('img');img.src=note.options.image;img.alt=note.title;article.append(img);}
      const text=doc.createElement('div');text.className='document-reading-text';
      if(note.options?.rich)text.innerHTML=note.displayContent ?? note.content;else text.textContent=note.content;
      article.append(text);
      if(canUseGmNotes && note.options?.gmNotes){
        const section=doc.createElement('section');section.className='document-gm-reading';
        const heading=doc.createElement('h3');heading.textContent='Notes du MJ (visibles uniquement par les MJ)';
        const secret=doc.createElement('div');secret.innerHTML=note.options.gmNotes;section.append(heading,secret);article.append(section);
      }
      preview.body.append(article);
    }
    entry.earlyClose=()=>{registry.delete(key);dialog.remove();frame.remove();};
    dialog.addEventListener('close',entry.earlyClose);preview.open();
    registry.set(key,entry);frame.src=url.href;hostWindow.document.body.append(frame);
    frame.addEventListener('load',()=>{
      if(!frame.contentWindow.document.querySelector('#campaign-notes-app')) {
        frame.remove();preview.body.textContent='Impossible de charger le document. Fermez cette fenêtre et réessayez.';
        setListStatus('Impossible d’ouvrir le document. Actualisez la liste et réessayez.',true);
      }
    });
  }
  const apiUrl = root.dataset.apiUrl;
  const csrfToken = root.dataset.csrfToken;
  const canUseGmNotes = root.dataset.canUseGmNotes === 'true';

  const createButton = document.querySelector('#note-create');
  const searchInput = document.querySelector('#note-search');
  const visibilityFilter = document.querySelector('#note-visibility-filter');
  const folderFilter = document.querySelector('#note-folder-filter');

  const listStatus = document.querySelector('#notes-list-status');
  const notesList = document.querySelector('#notes-list');
  const emptyEditor = document.querySelector('#note-editor-empty');
  const editorForm = document.querySelector('#note-editor-form');
  const titleInput = document.querySelector('#note-title');


  const tagsInput = document.querySelector('#note-tags');
  const contentInput = document.querySelector('#note-content');
  const saveStatus = document.querySelector('#note-save-status');
  const authorLabel = document.querySelector('#note-author');
  const deleteButton = document.querySelector('#note-delete');
  const conflictNotice = document.querySelector('#note-conflict');
  const conflictReloadButton = document.querySelector('#note-conflict-reload');
  const editorFields = [titleInput, tagsInput, contentInput];

  const readers = document.querySelector('#document-readers');
  const editors = document.querySelector('#document-editors');
  const gmNotes = document.querySelector('#document-gm');
  const imageInput = document.querySelector('#document-image');
  const imagePreview = document.querySelector('#document-image-preview');
  const imageHelp = document.querySelector('#document-image-help');
  const removeImage = document.querySelector('#document-image-remove');
  const tokenInput = document.querySelector('#character-token');
  const tokenPreview = document.querySelector('#character-token-preview');
  const tokenRemove = document.querySelector('#character-token-remove');
  const tokenDrop = document.querySelector('#character-token-drop');
  const imageTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
  const imageLimit = Number(root.dataset.imageLimit);
  const portraitLimit = Number(root.dataset.portraitLimit);
  const portraitSourceLimit = Number(root.dataset.portraitSourceLimit);
  const inMib = bytes => `${bytes / (1024 * 1024)} Mio`;
  let tokenImage = '';
  function displayToken(value) {
    tokenImage=value; tokenPreview.hidden=!value;
    if(value)tokenPreview.src=value;else tokenPreview.removeAttribute('src');
  }
  async function readToken(file) {
    const note=getActiveNote();
    if(!file || !note?.canEdit || note.options?.type!=='character')return;
    if(!imageTypes.includes(file.type) || file.size>imageLimit) {
      setSaveStatus('Choisissez une image de jeton PNG, JPEG, GIF ou WebP de 2 Mo maximum.','error');return;
    }
    const id=note.id;
    const value=await readEditorImage(file);
    if(getActiveNote()?.id!==id)return;
    displayToken(value); storeEditorChanges();
  }
  tokenInput.addEventListener('change',()=>readToken(tokenInput.files[0]).catch(()=>setSaveStatus('Lecture du jeton impossible.','error')));
  tokenRemove.addEventListener('click',()=>{displayToken('');tokenInput.value='';storeEditorChanges();});
  tokenDrop.addEventListener('dragover',event=>event.preventDefault());
  tokenDrop.addEventListener('drop',event=>{event.preventDefault();readToken(event.dataTransfer.files[0]).catch(()=>setSaveStatus('Lecture du jeton impossible.','error'));});
  let documentImage = '';
  let portraitRequest = 0;
  editorFields.push(readers, editors);
  if (gmNotes) editorFields.push(gmNotes);
  const richEditors = new Map();
  function richEditor(textarea) {
    if (!textarea) return;
    const box = document.createElement('div');
    box.className = 'document-rich-editor';
    box.contentEditable = 'true'; box.setAttribute('role','textbox'); box.setAttribute('aria-multiline','true');
    box.setAttribute('aria-label', textarea === contentInput ? 'Description et notes' : 'Notes du MJ');
    const toolbar = document.createElement('div'); toolbar.className = 'document-toolbar';
    const commands = [['Gras','strong'],['Italique','em'],['Souligné','u'],['Barré','s'],['Exposant','sup'],['Indice','sub'],['Titre','h2'],['Citation','blockquote'],['Liste','ul'],['Liste numérotée','ol'],['Séparation','hr'],['Tableau','table']];
    const sync = () => { textarea.value = box.innerHTML; handleEditorChange({target:textarea}); };
    commands.forEach(([label, tag]) => {
      const button = document.createElement('button'); button.type='button'; button.textContent=label;
      button.addEventListener('mousedown', event => event.preventDefault());
      button.addEventListener('click', () => {
        if (textarea.readOnly) return;
        box.focus();
        const doc = box.ownerDocument, selection = doc.getSelection();
        let range = selection.rangeCount ? selection.getRangeAt(0) : null;
        if (!range || !box.contains(range.commonAncestorContainer)) { range=doc.createRange(); range.selectNodeContents(box); range.collapse(false); }
        const node = doc.createElement(tag);
        if (tag==='table') {
          for(let i=0;i<2;i++) { const row=node.insertRow(); for(let j=0;j<2;j++) row.insertCell().textContent='Cellule'; }
        } else if (tag==='ul' || tag==='ol') { const li=doc.createElement('li'); li.append(range.extractContents()); if (!li.textContent) li.textContent='Élément'; node.append(li);
        } else if (tag!=='hr') { node.append(range.extractContents()); if (!node.textContent) node.textContent=label; }
        range.insertNode(node); range.selectNodeContents(node); selection.removeAllRanges(); selection.addRange(range); sync();
      });
      toolbar.append(button);
    });
    box.addEventListener('input',sync);
    box.addEventListener('drop', event => event.preventDefault());
    box.addEventListener('paste',event=>{
      event.preventDefault();
      const selection=box.ownerDocument.getSelection();
      if(!selection.rangeCount) return;
      const range=selection.getRangeAt(0); range.deleteContents(); const node=box.ownerDocument.createTextNode(event.clipboardData.getData('text/plain')); range.insertNode(node); range.setStartAfter(node); range.collapse(true); selection.removeAllRanges(); selection.addRange(range); sync();
    });
    textarea.hidden=true; textarea.before(toolbar,box); richEditors.set(textarea,box);
  }
  richEditor(contentInput); richEditor(gmNotes);
  function displayImage(value) {
    documentImage = value;
    imagePreview.hidden = !value;
    if (value) imagePreview.src = value; else imagePreview.removeAttribute('src');
  }
  async function readEditorImage(file, prepareImage = null) {
    const draft = state.draft;
    if (draft) draft.pendingImages++;
    try {
      const image = prepareImage ? await prepareImage(file) : file;
      return await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(image);
      });
    } finally {
      if (draft) draft.pendingImages--;
    }
  }
  async function compressPortrait(file, limit) {
    const bitmap = await createImageBitmap(file);
    try {
      const canvas = document.createElement('canvas');
      const context = canvas.getContext('2d');
      for (const maxSide of [1600, 1024]) {
        const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const image = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', .9));
        if (image && image.size <= limit) return image;
      }
      throw new Error('L’image reste trop volumineuse après optimisation.');
    } finally {
      bitmap.close();
    }
  }
  async function readImage(file) {
    const note = getActiveNote();
    if (!file || !note?.canEdit) return;
    const request = ++portraitRequest;
    const noteId = state.activeNoteId;
    const limit = note.codexEntryId ? imageLimit : portraitLimit;
    if (!imageTypes.includes(file.type)) {
      setSaveStatus('Choisissez une image PNG, JPEG, GIF ou WebP.', 'error');
      return;
    }
    const sourceLimit = file.type === 'image/gif' ? limit : portraitSourceLimit;
    if (file.size > sourceLimit) {
      setSaveStatus(`Choisissez une image de ${inMib(sourceLimit)} maximum pour ce format.`, 'error');
      return;
    }
    const needsCompression = file.size > limit;
    if (needsCompression) setSaveStatus('Optimisation de l’image…', 'pending');
    try {
      const value = await readEditorImage(file, needsCompression ? image => compressPortrait(image, limit) : null);
      if (request !== portraitRequest || state.activeNoteId !== noteId) return;
      displayImage(value);
      storeEditorChanges();
    } catch {
      if (request === portraitRequest && state.activeNoteId === noteId) {
        setSaveStatus(needsCompression ? 'Impossible d’optimiser cette image. Essayez un fichier plus léger.' : 'Lecture de l’image impossible.', 'error');
      }
    }
  }
  imageInput.addEventListener('change', () => readImage(imageInput.files[0]));
  removeImage.addEventListener('click', () => { portraitRequest++; displayImage(''); imageInput.value = ''; storeEditorChanges(); });
  const drop = document.querySelector('#document-drop');
  const summary=document.createElement('div');summary.className='character-summary';
  const images=document.createElement('div');images.className='character-images';
  const details=document.createElement('div');details.className='character-details';
  editorForm.prepend(summary);summary.append(images,details);
  images.append(drop,tokenDrop);
  details.append(titleInput.closest('.notes-editor-topbar'),readers.closest('.document-permissions'),tagsInput.closest('.notes-metadata-row'));
  drop.addEventListener('dragover', event => event.preventDefault());
  drop.addEventListener('drop', event => { event.preventDefault(); readImage(event.dataTransfer.files[0]); });
  createButton.addEventListener('click', createNote);
  searchInput.addEventListener('input', renderNotesList);
  visibilityFilter.addEventListener('change', renderNotesList);
  folderFilter.addEventListener('change', renderNotesList);
  editorForm.addEventListener('input', handleEditorChange);
  editorForm.addEventListener('change', handleEditorChange);
  editorForm.addEventListener('submit', (event) => event.preventDefault());
  deleteButton.addEventListener('click', deleteActiveNote);
  conflictReloadButton.addEventListener('click', reloadConflictedNote);

  window.addEventListener('beforeunload', (event) => {
    if (state.dirtyNoteIds.size === 0) return;
    event.preventDefault();
    event.returnValue = '';
  });

  async function presentDocument(event) {
    if (!state.activeNoteId) return;
    const button = event.currentTarget;
    button.disabled = true;
    try {
      await flushPendingSave();
      if (state.dirtyNoteIds.has(state.activeNoteId)) throw new Error('Enregistrez le document avant de le présenter.');
      const response = await fetch(new URL('presentation.php', new URL(apiUrl, location.href)), {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},
        body:JSON.stringify({campaignId, noteId:state.activeNoteId})
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error || 'Présentation impossible.');
      setSaveStatus('Document affiché à la table.', 'saved');
    } catch (error) { setSaveStatus(error.message, 'error'); }
    finally { button.disabled = false; }
  }
  const modalHost = window.parent !== window && window.parent.location.origin === location.origin ? window.parent.document : document;
  const loadingEntry=requestedDocument ? registry.get(registryKey(requestedDocument)) : null;
  const documentDialog = loadingEntry?.dialog || modalHost.createElement('dialog');
  if(loadingEntry) documentDialog.removeEventListener('close',loadingEntry.earlyClose);
  documentDialog.classList.add('library-modal');
  documentDialog.setAttribute('aria-label', 'Document de campagne');
  const closeDocument = modalHost.createElement('button');
  closeDocument.type = 'button';
  closeDocument.className = 'button button-secondary library-close';
  closeDocument.textContent = 'Fermer';
  documentDialog.append(closeDocument);
  closeDocument.hidden=true;
  if (!documentDialog.isConnected) modalHost.body.append(documentDialog);
  const editorSection = editorForm.closest('.notes-editor');
  editorSection.hidden = true;
  const windows = modalHost.defaultView.MapWindows || window.MapWindows;
  const floating = windows.enhance(documentDialog, 'Document');
  const reading = modalHost.createElement('article');
  reading.className = 'document-reading';
  const actions = modalHost.createElement('div'); actions.className='document-reading-actions';
  const edit = modalHost.createElement('button'); edit.type='button'; edit.className='button'; edit.textContent='Modifier';
  const share = modalHost.createElement('button'); share.type='button'; share.className='button button-secondary'; share.textContent='Afficher aux joueurs'; share.hidden=!canUseGmNotes;
  share.addEventListener('click',presentDocument);
  edit.addEventListener('click',()=>openDocumentDialog(true));
  const sheetButton=modalHost.createElement('button');sheetButton.type='button';sheetButton.className='button button-secondary';sheetButton.textContent='Fiche de personnage';
  sheetButton.addEventListener('click',()=>{
    const note=getActiveNote();if(!note || note.options?.type!=='character')return;
    const sheets=modalHost.defaultView.MapCharacterSheets || window.MapCharacterSheets;
    sheets.open({document:modalHost,windows,campaignId,noteId:note.id,title:note.title,csrfToken,apiUrl:new URL('character-sheets.php',new URL(apiUrl,location.href)).href,onBio:()=>openIndependentNote(note.id)});
  });
  actions.append(sheetButton,share,edit); reading.append(actions);
  const readingContent=modalHost.createElement('div'); reading.append(readingContent);
  const back=modalHost.createElement('button'); back.type='button'; back.className='button button-secondary'; back.textContent='Terminer et lire';
  back.addEventListener('click',async()=>{
    if (state.draft) {
      await saveDraft();
      return;
    }
    await flushPendingSave();
    if(!state.dirtyNoteIds.size)openDocumentDialog(false);
  });
  floating.body.append(reading,back,editorSection);
  function openDocumentDialog(editing=false) {
    const note=getActiveNote(); if(!note)return;
    floating.body.replaceChildren(reading,back,editorSection);
    editing=editing && note.canEdit;
    back.textContent=state.draft?'Enregistrer':'Terminer et lire';
    floating.label.textContent=note.title;
    editorSection.hidden=!editing; back.hidden=!editing; reading.hidden=editing;
    edit.hidden=!note.canEdit;
    sheetButton.hidden=note.options?.type!=='character';
    readingContent.replaceChildren();
    const options=note.options||{};
    if(options.image){const img=modalHost.createElement('img');img.src=options.image;img.alt=note.title;readingContent.append(img);}
    const text=modalHost.createElement('div'); text.className='document-reading-text';
    if(options.rich)text.innerHTML=note.displayContent ?? note.content;else text.textContent=note.content;
    readingContent.append(text);
    if(canUseGmNotes && options.gmNotes) {
      const section=modalHost.createElement('section');section.className='document-gm-reading';
      const title=modalHost.createElement('h3');title.textContent='Notes du MJ (visibles uniquement par les MJ)';
      const secret=modalHost.createElement('div');secret.innerHTML=options.gmNotes;section.append(title,secret);readingContent.append(section);
    }
    floating.open();
    if(editing)titleInput.focus();else edit.focus();
  }
  async function closeEditor(event) {
    event?.preventDefault();
    if (state.draft) {
      if (state.savingDraft) return;
      if (state.dirtyNoteIds.has(state.draft.id) && !hostWindow.confirm('Abandonner le brouillon ?')) return;
      discardDraft();
      return;
    }
    await flushPendingSave();
    if (state.dirtyNoteIds.size) {
      setSaveStatus('Enregistrement incomplet. Corrigez le problème avant de fermer.', 'error');
      return;
    }
    documentDialog.close();
  }
  closeDocument.addEventListener('click', closeEditor);
  documentDialog.addEventListener('cancel', closeEditor);
  function smallModal(title, configure) {
    const dialog = modalHost.createElement('dialog');
    dialog.className = 'library-modal library-small-modal';
    const heading = modalHost.createElement('h2');
    heading.textContent = title;
    const close = modalHost.createElement('button');
    close.className = 'button button-secondary';
    close.textContent = 'Fermer';
    close.addEventListener('click', () => dialog.close());
    dialog.append(heading);
    configure(dialog);
    dialog.append(close);
    dialog.addEventListener('close', () => dialog.remove());
    modalHost.body.append(dialog);
    windows.enhance(dialog,title).open();
  }
  document.querySelector('#note-new-folder')?.addEventListener('click', () => {
    smallModal('Créer un dossier', dialog => {
      const form = modalHost.createElement('form');
      const label = modalHost.createElement('label');
      label.textContent = 'Nom du dossier';
      const input = modalHost.createElement('input');
      input.required = true; input.maxLength = 80;
      label.append(input);
      const submit = modalHost.createElement('button');
      submit.className = 'button'; submit.textContent = 'Créer le dossier';
      form.append(label, submit); dialog.append(form);
      form.addEventListener('submit', async event => {
        event.preventDefault();
        submit.disabled = true;
        try {
          const payload = await apiRequest('POST', {action:'create_folder', campaignId, name:input.value.trim()});
          state.folders = payload.data.folders;
          renderFolderChoices(); renderNotesList(); dialog.close();
        } catch(error) { setListStatus(error.message,true); }
        finally { submit.disabled = false; }
      });
    });
  });
  document.querySelector('#note-new-character')?.addEventListener('click', () => createNote(null,'character'));
  if (requestedDocument) {
    const entry=registry.get(registryKey(requestedDocument));
    if(entry) {
      entry.focus=()=>floating.open();
      entry.prepareMove=async()=>{
        await flushPendingSave();
        return state.dirtyNoteIds.size === 0;
      };
      entry.didMove=note=>{
        const index=state.notes.findIndex(item=>item.id===note.id);
        if(index>=0) state.notes[index]=note;
        if(state.activeNoteId===note.id) populateEditor(note);
      };
    }
    documentDialog.addEventListener('close',()=>{
      registry.delete(registryKey(requestedDocument));
      documentDialog.remove();
      hostWindow.dispatchEvent(new hostWindow.CustomEvent('map-notes-changed',{detail:{campaignId}}));
      entry?.frame.remove();
    });
  } else {
    hostWindow.addEventListener('map-notes-changed',event=>{
      if(event.detail.campaignId === campaignId) loadNotes(true);
    });
    const pollLibrary = () => {
      if (!document.hidden) loadNotes(true);
    };
    const libraryTimer = window.setInterval(pollLibrary, 1500);
    document.addEventListener('visibilitychange', pollLibrary);
    window.addEventListener('pagehide', event => {
      if (!event.persisted) window.clearInterval(libraryTimer);
    });
  }
  loadNotes();

  async function loadNotes(background = false) {
    if (libraryLoading || pendingWrites || notesList.querySelector('.note-dragging')) return;
    libraryLoading = true;
    const version = writeVersion;
    if (!background) setListStatus('Chargement...');

    try {
      const payload = await apiRequest('GET', null, libraryRevision);
      if (version !== writeVersion || notesList.querySelector('.note-dragging') || !payload.data.notes) return;
      libraryRevision = payload.data.libraryRevision ?? null;
      state.notes = payload.data.notes;
      state.folders = payload.data.folders || [];
      if (requestedDocument) {
        if (!state.notes.some(note => note.id === requestedDocument)) {
          floating.label.textContent='Document inaccessible';
          floating.body.textContent='Ce document a été supprimé ou vous ne pouvez plus le consulter.';
          floating.open(); return;
        }
        selectNote(requestedDocument);
        if (new URLSearchParams(location.search).get('edit') === '1') openDocumentDialog(true);
        return;
      }
      renderFolderChoices();
      renderNotesList();

      if (!background && !state.draft) showEmptyEditor();
    } catch (error) {
      if (background) return;
      setListStatus(error.message, true);
      if (!state.draft) showEmptyEditor('Impossible de charger les notes.');
      if(requestedDocument){floating.body.textContent=error.message;floating.open();}
    } finally {
      libraryLoading = false;
    }
  }

  async function apiRequest(method, body = null, afterLibrary = null) {
    const url = method === 'GET'
      ? `${apiUrl}?campaignId=${encodeURIComponent(campaignId)}${requestedDocument ? '&noteId=' + requestedDocument : '&imageLinks=1'}${afterLibrary ? '&afterLibrary=' + encodeURIComponent(afterLibrary) : ''}`
      : apiUrl;
    const options = {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
      },
    };

    if (body !== null) {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = csrfToken;
      options.body = JSON.stringify(body);
    }

    if (method === 'POST') {
      pendingWrites++;
      writeVersion++;
    }
    try {
      return await readResponse(await fetch(url, options));
    } finally {
      if (method === 'POST') pendingWrites--;
    }
  }

  async function readResponse(response) {
    if (response.status === 413) throw new Error('Le serveur refuse cet envoi car il est trop volumineux.');
    let payload;

    try {
      payload = await response.json();
    } catch {
      throw new Error('Le serveur a renvoye une reponse illisible.');
    }

    if (!response.ok || payload.success !== true) {
      const error = new Error(payload.error || 'Une erreur est survenue.');
      error.status = response.status;
      error.payload = payload;
      throw error;
    }

    return payload;
  }

  function createNote(folderName = null, type = 'document') {
    if (state.draft) {
      floating.open();
      return;
    }
    if (typeof folderName !== 'string') folderName=null;
    state.draft = {
      id: Symbol('draft'),
      title: type === 'character' ? 'Nouveau personnage' : 'Nouveau document',
      content: '',
      folder: folderName,
      tags: [],
      visibility: 'personal',
      options: {type, rich: true},
      canEdit: true,
      canManagePermissions: true,
      canDelete: false,
      pendingImages: 0,
    };
    state.activeNoteId = state.draft.id;
    populateEditor(state.draft);
    openDocumentDialog(true);
  }

  function discardDraft() {
    state.dirtyNoteIds.delete(state.draft.id);
    state.draft = null;
    showEmptyEditor();
  }

  async function saveDraft() {
    const draft = state.draft;
    if (!draft || state.savingDraft) return;
    if (!draft.title.trim()) {
      setSaveStatus('Le titre est obligatoire.', 'error');
      return;
    }
    if (draft.pendingImages) {
      setSaveStatus('Attendez la fin du chargement des images.', 'pending');
      return;
    }
    state.savingDraft = true;
    back.disabled = true;
    editorForm.inert = true;
    setSaveStatus('Enregistrement…', 'pending');
    let savedNote;
    try {
      const payload = await apiRequest('POST', {
        action: 'create',
        campaignId,
        title: draft.title,
        type: draft.options.type,
        content: draft.content,
        options: draft.options,
        folder: draft.folder,
        tags: draft.tags,
        visibility: draft.visibility,
      });
      savedNote = payload.data.note;
    } catch (error) {
      setSaveStatus(error.message, 'error');
    } finally {
      state.savingDraft = false;
      back.disabled = false;
      editorForm.inert = false;
    }
    if (!savedNote) return;
    discardDraft();
    state.notes.unshift(savedNote);
    renderFolderChoices();
    renderNotesList();
    openIndependentNote(savedNote.id);
    hostWindow.dispatchEvent(new hostWindow.CustomEvent('map-notes-changed',{detail:{campaignId}}));
  }

  function getActiveNote() {
    return state.draft ?? state.notes.find((note) => note.id === state.activeNoteId) ?? null;
  }

  function selectNote(noteId) {
    const note = state.notes.find((item) => item.id === Number(noteId));
    if (!note) return;

    window.clearTimeout(state.saveTimer);
    state.activeNoteId = note.id;
    populateEditor(note);
    openDocumentDialog();
    renderNotesList();
  }

  function populateEditor(note) {
    emptyEditor.classList.add('hidden');
    editorForm.classList.remove('hidden');
    titleInput.value = note.title;


    tagsInput.value = note.tags.join(', ');
    contentInput.value = note.content;
    const options = note.options || {};
    const character=options.type==='character';
    titleInput.maxLength = note.codexEntryId ? 250 : 160;
    editorForm.classList.toggle('character-editor',character);
    tokenDrop.hidden=!character;
    titleInput.closest('.field-group').querySelector('label').textContent=character?'Nom':'Titre';
    editors.closest('.field-group').querySelector('label').textContent=character?'Peut être modifié & contrôlé par':'Peut être modifié par';
    drop.querySelector('label').textContent=character?'Avatar — déposer une image ou choisir un fichier':'Déposer une image ou choisir un fichier';
    const limit = inMib(note.codexEntryId ? imageLimit : portraitLimit);
    imageHelp.textContent = `PNG, JPEG ou WebP jusqu’à ${inMib(portraitSourceLimit)} avec optimisation au-delà de ${limit}. GIF jusqu’à ${limit}.`;
    contentInput.closest('.notes-content-field').querySelector('label').textContent=character?'Bio & Info':'Description & notes';
    displayToken(options.tokenImage || '');
    tokenInput.value='';tokenInput.disabled=tokenRemove.disabled=!note.canEdit;
    [readers, editors].forEach((field, i) => {
      const values = options[i ? 'editors' : 'readers'] || (!i && note.visibility === 'shared' ? ['*'] : []);
      [...field.options].forEach(option => { option.selected = values.includes(option.value); });
      field.disabled = !note.canEdit || note.canManagePermissions === false;
    });
    if (gmNotes) { gmNotes.value = options.gmNotes || ''; gmNotes.readOnly = !note.canEdit; }
    richEditors.forEach((box, field) => {
      if (options.rich || field === gmNotes) box.innerHTML = field.value;
      else box.textContent = field.value;
      box.contentEditable = note.canEdit ? 'true' : 'false';
    });
    displayImage(options.image || '');
    imageInput.value = '';
    imageInput.disabled = removeImage.disabled = !note.canEdit;
    authorLabel.textContent = state.draft ? '' :
      `${note.codexEntryId ? 'Lié au Codex · ' : ''}Auteur : ${note.author.displayName} · Mise a jour : ${formatDate(note.updatedAt)}`;

    [titleInput, tagsInput, contentInput].forEach((field) => {
      field.readOnly = !note.canEdit;
    });

    deleteButton.disabled = !note.canDelete;
    deleteButton.hidden = Boolean(state.draft);

    const conflict = state.conflicts.get(note.id);
    conflictNotice.classList.toggle('hidden', !conflict);

    if (state.draft) {
      setSaveStatus('Brouillon non enregistré.', 'pending');
    } else if (conflict) {
      setSaveStatus('Conflit de sauvegarde.', 'error');
    } else if (state.dirtyNoteIds.has(note.id)) {
      setSaveStatus('Modifications non enregistrees...', 'pending');
    } else if (!note.canEdit) {
      setSaveStatus('Lecture seule.', 'neutral');
    } else {
      setSaveStatus('Toutes les modifications sont enregistrees.', 'success');
    }
  }

  function showEmptyEditor(message = 'Choisissez une note dans la liste ou creez-en une nouvelle.') {
    state.activeNoteId = null;
    if (documentDialog.open) documentDialog.close();
    editorForm.classList.add('hidden');
    emptyEditor.classList.remove('hidden');
    const text = emptyEditor.querySelector('.muted');
    if (text) text.textContent = message;
  }

  function handleEditorChange(event) {
    if (!editorFields.includes(event.target)) return;
    storeEditorChanges();
  }

  function storeEditorChanges() {
    const note = getActiveNote();
    if (!note || !note.canEdit || state.conflicts.has(note.id)) return;

    note.title = titleInput.value;


    note.tags = parseTags(tagsInput.value);
    note.content = contentInput.value;
    note.savedImage ??= note.options?.image || '';
    note.options = {
      readers: [...readers.selectedOptions].map(option => option.value),
      editors: [...editors.selectedOptions].map(option => option.value),
      gmNotes: gmNotes?.value || '', image: documentImage, rich: true,
      type:note.options?.type || 'document', tokenImage
    };
    state.dirtyNoteIds.add(note.id);
    if (note === state.draft) {
      setSaveStatus('Brouillon non enregistré.', 'pending');
      return;
    }
    state.editVersions.set(note.id, (state.editVersions.get(note.id) ?? 0) + 1);
    setSaveStatus('Modifications non enregistrees...', 'pending');
    renderFolderChoices();
    renderNotesList();
    scheduleSave();
  }

  function parseTags(value) {
    const seen = new Set();

    return value
      .split(',')
      .map((tag) => tag.trim().replace(/\s+/g, ' '))
      .filter((tag) => {
        const key = tag.toLocaleLowerCase('fr');
        if (!tag || seen.has(key)) return false;
        seen.add(key);
        return true;
      })
      .slice(0, 20);
  }

  function scheduleSave() {
    window.clearTimeout(state.saveTimer);
    state.saveTimer = window.setTimeout(() => saveActiveNote(), 700);
  }

  async function flushPendingSave() {
    if (state.draft) return;
    window.clearTimeout(state.saveTimer);

    if (state.savePromise) {
      await state.savePromise;
    }

    if (state.activeNoteId && state.dirtyNoteIds.has(state.activeNoteId)) {
      await saveActiveNote();
    }
  }

  async function saveActiveNote() {
    if (state.draft) return;
    const note = getActiveNote();
    if (!note || !note.canEdit || !state.dirtyNoteIds.has(note.id) || state.conflicts.has(note.id)) return;

    if (state.savePromise) {
      await state.savePromise;
      if (state.dirtyNoteIds.has(note.id)) return saveActiveNote();
      return;
    }

    if (note.title.trim() === '') {
      setSaveStatus('Le titre est obligatoire.', 'error');
      return;
    }

    const noteId = note.id;
    const editVersion = state.editVersions.get(noteId) ?? 0;
    const options = {...note.options};
    if (options.image === note.savedImage) delete options.image;
    const requestBody = {
      action: 'update',
      campaignId,
      noteId,
      revision: note.revision,
      title: note.title,
      content: note.content, options,
      folder: note.folder,
      tags: note.tags,
      visibility: note.visibility,
    };

    if (state.activeNoteId === noteId) {
      setSaveStatus('Enregistrement...', 'pending');
    }

    state.savePromise = apiRequest('POST', requestBody)
      .then((payload) => {
        hostWindow.dispatchEvent(new hostWindow.CustomEvent('map-notes-changed',{detail:{campaignId}}));
        const savedNote = payload.data.note;
        const currentIndex = state.notes.findIndex((item) => item.id === noteId);
        if (currentIndex < 0) return;

        if ((state.editVersions.get(noteId) ?? 0) === editVersion) {
          state.notes[currentIndex] = savedNote;
          state.dirtyNoteIds.delete(noteId);
        } else {
          state.notes[currentIndex].revision = savedNote.revision;
          state.notes[currentIndex].updatedAt = savedNote.updatedAt;
          state.notes[currentIndex].savedImage = savedNote.options?.image || '';
          scheduleSave();
        }

        if (state.activeNoteId === noteId) {
          populateEditor(state.notes[currentIndex]);
        }
        renderFolderChoices();
        renderNotesList();
      })
      .catch((error) => {
        if (error.status === 409 && error.payload?.data?.latestNote) {
          state.conflicts.set(noteId, error.payload.data.latestNote);
          if (state.activeNoteId === noteId) {
            conflictNotice.classList.remove('hidden');
            setSaveStatus('Conflit : une version plus recente existe.', 'error');
          }
          return;
        }

        if (state.activeNoteId === noteId) {
          setSaveStatus(error.message, 'error');
        }
      })
      .finally(() => {
        state.savePromise = null;
      });

    await state.savePromise;
  }

  function reloadConflictedNote() {
    const noteId = state.activeNoteId;
    const latestNote = state.conflicts.get(noteId);
    if (!latestNote) return;

    const index = state.notes.findIndex((note) => note.id === noteId);
    if (index >= 0) state.notes[index] = latestNote;
    state.conflicts.delete(noteId);
    state.dirtyNoteIds.delete(noteId);
    state.editVersions.delete(noteId);
    populateEditor(latestNote);
    renderFolderChoices();
    renderNotesList();
  }

  async function deleteActiveNote() {
    const note = getActiveNote();
    if (!note || !note.canDelete) return;

    if (!window.confirm(`Supprimer definitivement la note « ${note.title || 'Sans titre'} » ?`)) return;

    window.clearTimeout(state.saveTimer);
    deleteButton.disabled = true;

    if (state.savePromise) await state.savePromise;

    try {
      await apiRequest('POST', {
        action: 'delete',
        campaignId,
        noteId: note.id,
      });
      state.notes = state.notes.filter((item) => item.id !== note.id);
      state.dirtyNoteIds.delete(note.id);
      state.editVersions.delete(note.id);
      state.conflicts.delete(note.id);
      renderFolderChoices();
      renderNotesList();

      if (state.notes.length > 0) {
        showEmptyEditor();
      } else {
        showEmptyEditor();
      }
    } catch (error) {
      setSaveStatus(error.message, 'error');
      deleteButton.disabled = false;
    }
  }

  function getFilteredNotes() {
    const search = searchInput.value.trim().toLocaleLowerCase('fr');
    const visibility = visibilityFilter.value;
    const folder = folderFilter.value;

    return state.notes.filter((note) => {
      if (visibility !== 'all' && note.visibility !== visibility) return false;
      if (folder !== 'all' && (note.folder ?? '') !== folder) return false;
      if (!search) return true;

      const haystack = [
        note.title,
        note.content,
        note.folder ?? '',
        note.author.displayName,
        ...note.tags,
      ].join(' ').toLocaleLowerCase('fr');

      return haystack.includes(search);
    }).sort((noteA, noteB) => {
      const dateDifference = Date.parse(noteB.updatedAt) - Date.parse(noteA.updatedAt);
      return Number.isNaN(dateDifference) || dateDifference === 0 ? noteB.id - noteA.id : dateDifference;
    });
  }

  function renderNotesList() {
    const filteredNotes = getFilteredNotes();
    notesList.replaceChildren();
    setListStatus(`${filteredNotes.length} note${filteredNotes.length > 1 ? 's' : ''}`);

    if (filteredNotes.length === 0 && state.folders.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'empty-state';
      empty.textContent = state.notes.length === 0
        ? 'Aucune note. Creez la premiere.'
        : 'Aucune note ne correspond aux filtres.';
      notesList.append(empty);
      return;
    }

    const groupedNotes = new Map([['', []]]);
    state.folders.forEach(folder=>groupedNotes.set(folder, []));
    filteredNotes.forEach((note) => {
      const folder = note.folder || '';
      if (!groupedNotes.has(folder)) groupedNotes.set(folder, []);
      groupedNotes.get(folder).push(note);
    });

    [...groupedNotes.entries()]
      .sort(([folderA], [folderB]) => {
        if (folderA === '') return -1;
        if (folderB === '') return 1;
        return folderA.localeCompare(folderB, 'fr', { sensitivity: 'base' });
      })
      .forEach(([folder, notes]) => {
        const group = document.createElement('section');
        group.className = 'notes-folder-group';
        const heading = document.createElement('h4');
        heading.textContent = folder || 'Sans dossier';
        enableFolderDrop(group,folder);
        group.append(heading);

        notes.forEach((note) => group.append(renderNoteButton(note)));
        notesList.append(group);
      });
  }

  const movingNotes=new Set();
  function enableFolderDrop(group,folder) {
    group.addEventListener('dragover',event=>{
      if(![...event.dataTransfer.types].includes('application/x-atlas-document'))return;
      event.preventDefault();event.dataTransfer.dropEffect='move';group.classList.add('notes-drop-target');
    });
    group.addEventListener('dragleave',event=>{
      if(!group.contains(event.relatedTarget))group.classList.remove('notes-drop-target');
    });
    group.addEventListener('drop',async event=>{
      event.preventDefault();event.stopPropagation();group.classList.remove('notes-drop-target');
      try {
        const data=JSON.parse(event.dataTransfer.getData('application/x-atlas-document'));
        if(data.campaignId!==campaignId)return;
        const note=state.notes.find(item=>item.id===data.noteId);
        if(!note?.canEdit || movingNotes.has(note.id) || (note.folder||'')===folder)return;
        movingNotes.add(note.id);
        try {
          const entry=registry.get(registryKey(note.id));
          if(entry && !entry.prepareMove)throw new Error('Le document est encore en cours de chargement.');
          if(entry && !(await entry.prepareMove()))throw new Error('Enregistrez les modifications du document avant de le déplacer.');
          const payload=await apiRequest('POST',{action:'move',campaignId,noteId:note.id,folder:folder||null});
          state.notes=state.notes.map(item=>item.id===note.id ? payload.data.note : item);
          state.folders=payload.data.folders;
          entry?.didMove?.(payload.data.note);
          renderFolderChoices();renderNotesList();
        } finally {movingNotes.delete(note.id);}
      } catch(error) {setListStatus(error.message || 'Déplacement impossible.',true);}
    });
  }
  function renderNoteButton(note) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'note-list-item';
    if (note.id === state.activeNoteId) button.classList.add('note-list-item-active');
    if (state.dirtyNoteIds.has(note.id)) button.classList.add('note-list-item-dirty');
    button.addEventListener('click', async () => {

      await flushPendingSave();
      openIndependentNote(note.id);
    });

    const title = document.createElement('strong');
    title.textContent = note.title || 'Sans titre';
    button.append(title);

    const thumbnail=document.createElement('span');
    thumbnail.className=note.options?.type==='character'?'note-thumbnail character-thumbnail':'note-thumbnail';
    if(note.options?.image) {
      const image=document.createElement('img'); image.src=note.options.image; image.alt='';image.loading='lazy';image.draggable=false;thumbnail.append(image);
    } else { thumbnail.textContent='▤';thumbnail.setAttribute('aria-hidden','true'); }
    button.prepend(thumbnail);
    button.draggable=note.canEdit;
    button.addEventListener('dragstart',event=>{
      if(!note.canEdit){event.preventDefault();return;}
      const payload = JSON.stringify({campaignId,noteId:note.id});
      event.dataTransfer.setData('application/x-atlas-document', payload);
      event.dataTransfer.setData('application/x-atlas-board-entity', payload);
      event.dataTransfer.effectAllowed='copyMove';button.classList.add('note-dragging');
    });
    button.addEventListener('dragend',()=>button.classList.remove('note-dragging'));
    return button;
  }

  function renderFolderChoices() {
    const currentFilter = folderFilter.value;
    const folders = [...new Set([...state.folders, ...state.notes.map((note) => note.folder).filter(Boolean)])]
      .sort((a, b) => a.localeCompare(b, 'fr', { sensitivity: 'base' }));

    folderFilter.replaceChildren(new Option('Tous', 'all'));


    folders.forEach((folder) => {
      folderFilter.append(new Option(folder, folder));

    });

    folderFilter.value = folders.includes(currentFilter) ? currentFilter : 'all';
  }

  function visibilityLabel(visibility) {
    return {
      personal: 'Personnelle',
      gm: 'MJ',
      shared: 'Partagee',
    }[visibility] ?? visibility;
  }

  function formatDate(value) {
    const normalized = value.includes('T') ? value : `${value.replace(' ', 'T')}Z`;
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('fr-FR', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    }).format(date);
  }

  function setListStatus(message, isError = false) {
    listStatus.textContent = message;
    listStatus.classList.toggle('notes-status-error', isError);
  }

  function setSaveStatus(message, status) {
    saveStatus.textContent = message;
    saveStatus.dataset.status = status;
  }


})();
