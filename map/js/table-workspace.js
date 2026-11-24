(() => {
  const root = document.querySelector('#table-workspace');
  if (!root) return;
  const entryNotices = root.querySelector('.board-entry-notices');
  if (entryNotices?.childElementCount) setTimeout(() => entryNotices.remove(),5000);
  const sidebar = root.querySelector('#table-sidebar');
  const sidebarToggle = root.querySelector('#table-sidebar-toggle');
  function showSidebar(visible) {
    sidebar.hidden = !visible;
    sidebarToggle.setAttribute('aria-expanded', String(visible));
  }
  const compact = matchMedia('(max-width: 760px)');
  showSidebar(!compact.matches);
  compact.addEventListener('change', event => showSidebar(!event.matches));
  sidebarToggle.addEventListener('click', () => showSidebar(sidebar.hidden));

  const navigation = root.querySelector('#table-navigation');
  document.addEventListener('click', event => {
    if (navigation.open && (!navigation.contains(event.target) || event.target.closest('a'))) navigation.open = false;
  });
  navigation.addEventListener('keydown', event => {
    if (event.key === 'Escape' && navigation.open) {
      event.stopPropagation();
      navigation.open = false;
      navigation.querySelector('summary').focus();
    }
  });
  const tabs = [...root.querySelectorAll('[role="tab"]')];
  function activate(tab) {
    showSidebar(true);
    tabs.forEach(item => {
      const active = item === tab;
      item.setAttribute('aria-selected', String(active));
      item.tabIndex = active ? 0 : -1;
      document.getElementById(item.dataset.panel).hidden = !active;
    });
    const frame = document.getElementById(tab.dataset.panel).querySelector('iframe[data-src]');
    if (frame) { frame.src = frame.dataset.src; delete frame.dataset.src; }
  }
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activate(tab));
    tab.addEventListener('keydown', event => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
      activate(tabs[next]); tabs[next].focus();
    });
  });
})();

(() => {
 const root = document.querySelector('#table-workspace');
 if (!root) return;
 document.querySelector('#table-document')?.remove();
 // The last share at page render belongs to the previous viewing session.
 let last = root.dataset.initialPresentationId || null;
 let stopped = false;
 const controller = new AbortController();
 window.addEventListener('pagehide', () => { stopped = true; controller.abort(); });
 window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
 async function poll() {
  let retryDelay = 750;
  try {
   const boardCursor = window.MapBoard?.revision ? '&afterBoard=' + window.MapBoard.revision : '';
   const cursor = '&after=' + encodeURIComponent(last || '') + boardCursor;
   const diagnostics = window.MapTableDiagnostics;
   const {response, payload} = await diagnostics.json('board.sync',root.dataset.presentationApi + '?campaignId=' + root.dataset.campaignId + cursor, {cache:'no-store', signal:controller.signal});
   if ([401,403,404].includes(response.status)) { stopped = true; return; }
   if (!response.ok || !payload.success) throw new Error();
   if (payload.data.board) diagnostics.measure('board.sync', () => window.MapBoard?.apply(payload.data.board), {revision:payload.data.board.revision});
   const doc = payload.data.presentation;
   if (doc && doc.event_id !== last) {
    last = doc.event_id;
    const dialog=document.createElement('dialog');
    dialog.className='library-modal';
    dialog.setAttribute('aria-label',doc.title);
    const heading=document.createElement('h2'); heading.textContent=doc.title;
    const content=document.createElement('div'); content.className='document-reading-text';
    dialog.append(heading,content); document.body.append(dialog);
    const floating=window.MapWindows.enhance(dialog,doc.title);
    dialog.addEventListener('close',()=>dialog.remove());

    content.replaceChildren();
    if (doc.image) { const img=document.createElement('img'); img.src=doc.image; img.alt=doc.title; img.style.maxWidth='100%'; img.style.maxHeight='400px'; content.append(img); }
    const text=document.createElement('div');
    if (Number(doc.rich)) text.innerHTML=doc.content; else text.textContent=doc.content;
    content.append(text);
    floating.label.textContent=doc.title;
    floating.open();
   }
  } catch (_) { retryDelay = 2000; }
  if (!stopped) setTimeout(poll, retryDelay);
 }
 poll();
})();
