(() => {
  const root = document.querySelector('#table-workspace');
  const chat = root?.querySelector('#panel-chat');
  if (!chat) return;
  const diagnostics = window.MapTableDiagnostics;
  const campaignId = Number(root.dataset.campaignId);
  const tab = root.querySelector('#tab-chat');
  const toggle = root.querySelector('#board-dice-toggle');
  const dialog = document.querySelector('#board-dice-panel');
  const floating = window.MapWindows.enhance(dialog,'Lanceur de dés');
  const feed = chat.querySelector('#board-chat-feed');
  const chatForm = chat.querySelector('#board-chat-form');
  const message = chat.querySelector('#board-chat-message');
  const chatStatus = chat.querySelector('#board-chat-status');
  const diceStatus = dialog.querySelector('#board-dice-status');
  const history = dialog.querySelector('#board-dice-history');
  const advanced = dialog.querySelector('#board-dice-advanced');
  const advancedToggle = dialog.querySelector('#board-dice-advanced-toggle');
  const dice = [['4','D4'],['6','D6'],['8','D8'],['10','D10'],['12','D12'],['20','D20'],['100','D100'],['F','Fudge']];
  const visibilityNames = {public:'Tous',gm:'MJ',secret:'Secret'};
  let busy = false, requestVersion = 0, feedKey = '', historyKey = '', stopped = false;

  function node(tag, text, className) {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
  }
  function button(text, handler, className = 'button button-secondary button-small') {
    const element = node('button',text,className);
    element.type = 'button';
    element.addEventListener('click',handler);
    return element;
  }
  async function request(endpoint, payload) {
    const url = new URL(endpoint,location.href);
    url.searchParams.set('campaignId',campaignId);
    const options = {cache:'no-store'};
    if (payload) {
      options.method = 'POST';
      options.headers = {'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrfToken};
      options.body = JSON.stringify({campaignId,...payload});
    }
    const label = endpoint === chat.dataset.diceApi ? 'dice.roll' : payload ? 'chat.send' : 'chat.refresh';
    const {response, payload:result} = await diagnostics.json(label,url,options);
    if (!response.ok || !result.success) throw new Error(result.error || 'La requête a échoué.');
    return result.data;
  }
  const visibility = () => dialog.querySelector('[name="roll-visibility"]:checked').value;
  async function roll(expression, mode = 'normal', audience = visibility(), success = null) {
    if (busy) throw new Error('Un jet est déjà en cours.');
    busy = true;
    diceStatus.textContent = 'Jet en cours…';
    try {
      await request(chat.dataset.diceApi,{action:'roll',expression,mode,visibility:audience,success});
      tab.click();
      await refresh(true);
      diceStatus.textContent = '';
    } finally {
      busy = false;
    }
  }
  function runRoll(...args) {
    roll(...args).catch(error => { diceStatus.textContent = error.message; });
  }
  toggle.addEventListener('click', () => {
    if (dialog.open && !dialog.classList.contains('window-minimized')) { dialog.close(); return; }
    const firstOpen = !dialog.style.left;
    floating.open();
    if (firstOpen) {
      const anchor = toggle.getBoundingClientRect();
      const panel = dialog.getBoundingClientRect();
      dialog.style.left = Math.max(8,Math.min(anchor.right+10,window.innerWidth-panel.width-8))+'px';
      dialog.style.top = Math.max(8,Math.min(anchor.top,window.innerHeight-panel.height-8))+'px';
    }
    toggle.setAttribute('aria-expanded','true');
    refresh();
  });
  dialog.addEventListener('close', () => {
    toggle.setAttribute('aria-expanded','false');
    toggle.focus();
  });
  advancedToggle.addEventListener('click', () => {
    advanced.hidden = !advanced.hidden;
    dialog.classList.toggle('board-dice-is-advanced',!advanced.hidden);
    advancedToggle.setAttribute('aria-expanded',String(!advanced.hidden));
  });
  const quick = dialog.querySelector('#board-dice-grid');
  const dieSelect = advanced.elements.namedItem('die');
  for (const [value,label] of dice) {
    dieSelect.append(new Option(label,value,value==='20',value==='20'));
    const row = node('div',undefined,'board-dice-row');
    for (let number=1; number<=6; number++) {
      const die = button(number===1 ? label : String(number), () => runRoll(number+'d'+value));
      die.setAttribute('aria-label','Lancer '+number+' '+label);
      row.append(die);
    }
    quick.append(row);
  }
  const field = name => advanced.elements.namedItem(name);
  field('countSuccess').addEventListener('change', () => {
    const enabled = field('countSuccess').checked;
    advanced.querySelector('.board-dice-threshold').hidden = !enabled;
    field('target').disabled = !enabled;
  });
  field('target').disabled = true;
  advanced.addEventListener('submit', event => {
    event.preventDefault();
    const modifier = Number(field('modifier').value);
    const generated = field('quantity').value+'d'+field('die').value+(modifier ? (modifier>0 ? '+' : '')+modifier : '');
    const success = field('countSuccess').checked ? {operator:field('operator').value,target:Number(field('target').value)} : null;
    runRoll(field('formula').value.trim() || generated,field('mode').value,visibility(),success);
  });

  function renderRoll(roll) {
    const card = node('article',undefined,'board-chat-roll');
    card.append(node('strong',roll.roller.displayName),node('span',visibilityNames[roll.visibility],'board-chat-audience'));
    card.append(node('div',roll.canonicalExpression,'board-chat-formula'));
    for (const term of roll.result.terms) {
      const row = node('div',undefined,'board-chat-dice-values');
      row.append(node('span',term.sign < 0 ? '−' : '+'));
      if (term.type === 'constant') row.append(node('span',String(term.value)));
      else for (const die of term.rolls) row.append(node('span',String(die.value),die.kept ? 'board-chat-die' : 'board-chat-die discarded'));
      card.append(row);
    }
    const success = roll.result.success;
    const result = success
      ? success.count+' réussite'+(success.count===1?'':'s')+' ('+success.operator+' '+success.target+') · Total '+roll.total
      : 'Total '+roll.total;
    card.append(node('strong',result,'board-chat-total'));
    return card;
  }
  function renderFeed(data, follow) {
    const entries = [
      ...data.messages.map(item=>({item,kind:'message'})),
      ...data.rolls.map(item=>({item,kind:'roll'})),
    ].sort((a,b)=>a.item.createdAt.localeCompare(b.item.createdAt) ||
      a.kind.localeCompare(b.kind) || a.item.id-b.item.id).slice(-100);
    const key = entries.map(({kind,item})=>kind+item.id).join(',');
    if (key === feedKey && feed.childNodes.length) {
      if (follow) feed.scrollTop = feed.scrollHeight;
      return;
    }
    const atEnd = feed.scrollHeight-feed.scrollTop-feed.clientHeight < 50;
    const previousScroll = feed.scrollTop;
    feedKey = key;
    const fragment = document.createDocumentFragment();
    for (const {item,kind} of entries) {
      if (kind === 'roll') fragment.append(renderRoll(item));
      else {
        const card = node('article',undefined,'board-chat-message');
        card.append(node('strong',item.author),node('p',item.body));
        fragment.append(card);
      }
    }
    if (!entries.length) fragment.append(node('p','Aucun message pour le moment.','muted'));
    feed.replaceChildren(fragment);
    feed.scrollTop = follow || atEnd ? feed.scrollHeight : previousScroll;
  }
  function renderHistory(rolls) {
    const key = rolls.map(roll=>roll.id).join(',');
    if (key === historyKey && history.childNodes.length) return;
    historyKey = key;
    history.replaceChildren();
    if (!rolls.length) history.append(node('p','Aucun jet pour le moment.','muted'));
    for (const item of rolls) {
      const row = node('div',undefined,'board-dice-history-row');
      row.append(node('span',item.canonicalExpression+' · '+visibilityNames[item.visibility]));
      row.append(button('Relancer', () => runRoll(item.expression,item.mode,item.visibility,item.result.success ?? null)));
      history.append(row);
    }
  }
  async function refresh(follow = false) {
    const version = ++requestVersion;
    try {
      const data = await request(chat.dataset.chatApi);
      if (version !== requestVersion) return;
      diagnostics.measure('chat.refresh', () => {
        renderFeed(data,follow);
        renderHistory(data.history);
      });
    } catch (error) {
      if (version === requestVersion) chatStatus.textContent = error.message;
    }
  }
  tab.addEventListener('click', () => { if (!busy) refresh(true); });
  chatForm.addEventListener('submit', async event => {
    event.preventDefault();
    const text = message.value.trim();
    const send = chatForm.querySelector('button[type=submit]');
    if (!text || send.disabled) return;
    send.disabled = true;
    chatStatus.textContent = '';
    try {
      const command = text.match(/^\/(roll|r|gmroll|gr|secretroll)\s+(.+)$/is);
      if (command) {
        const name = command[1].toLowerCase();
        const threshold = command[2].match(/^(.+?)\s*(>=|<=|>|<|=)\s*(-?\d+)$/);
        const success = threshold ? {operator:threshold[2],target:Number(threshold[3])} : null;
        await roll(threshold?.[1] ?? command[2],'normal',name==='secretroll' ? 'secret' : ['gmroll','gr'].includes(name) ? 'gm' : 'public',success);
      } else {
        await request(chat.dataset.chatApi,{body:text});
        await refresh(true);
      }
      message.value = '';
    } catch (error) {
      chatStatus.textContent = error.message;
    } finally {
      send.disabled = false;
    }
  });
  message.addEventListener('keydown',event => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      chatForm.requestSubmit();
    }
  });
  window.addEventListener('pagehide',() => { stopped = true; });
  window.addEventListener('pageshow',event => { if (event.persisted && stopped) { stopped = false; poll(); } });
  async function poll() {
    if (stopped) return;
    if (!document.hidden && (!chat.hidden || dialog.open)) await refresh();
    if (!stopped) setTimeout(poll,1800);
  }
  poll();
})();
