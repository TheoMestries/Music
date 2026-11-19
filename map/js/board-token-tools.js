(() => {
  const colors = ['#c95a51','#6eb581','#719bc8'];
  const markers = [
    ['mort','Mort','☠'],['poison','Empoisonné','⚗'],['blesse','Blessé','♥'],['feu','En feu','🔥'],
    ['ralenti','Ralenti','🐌'],['protege','Protégé','⛨'],['entrave','Entravé','⛓'],['endormi','Endormi','☾'],
    ['aveugle','Aveuglé','◉'],['effraye','Effrayé','⚠'],['etourdi','Étourdi','✷'],['invisible','Invisible','◌'],
    ['benediction','Bénédiction','✧'],['froid','Gelé','❄'],['silence','Silence','♫'],['desarme','Désarmé','⚔'],
    ['concentration','Concentration','◎'],['attente','En attente','⌛'],['combat','En combat','✦'],['soin','Soin','✚'],
    ['dot-red','Rouge','●','#d9564f'],['dot-blue','Bleu','●','#5486db'],['dot-green','Vert','●','#77ba56'],
    ['dot-orange','Orange','●','#e59948'],['dot-purple','Violet','●','#9c6adc'],['dot-pink','Rose','●','#d878c2'],['dot-yellow','Jaune','●','#d2c958'],
  ];
  const catalog = new Map(markers.map(entry=>[entry[0],entry]));
  function node(tag,text,className) {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
  }
  function button(text,label,handler,className = 'button button-secondary button-small') {
    const element = node('button',text,className);
    element.type = 'button';
    element.title = label;
    element.setAttribute('aria-label',label);
    element.addEventListener('click',handler);
    return element;
  }
  function markerIcon(id) {
    const [,label,symbol,color] = catalog.get(id);
    const icon = node('span',symbol,'board-token-marker');
    icon.title = label;
    if (color) icon.style.color = color;
    return icon;
  }
  function decorate(item,visual,entity) {
    const token = entity.token;
    visual.style.opacity = token.opacity/100;
    item.removeAttribute('title');
    if (token.tint) {
      const tint = node('span',undefined,'board-token-tint');
      tint.style.backgroundColor = token.tint;
      visual.append(tint);
    }
    if (token.showTooltip && token.tooltip) {
      visual.title = token.tooltip;
      visual.classList.add('board-token-tooltip');
    }
    if (token.showName) item.append(node('span',token.name || entity.name,'board-token-name'));
    const marks = node('div',undefined,'board-token-markers');
    for (const id of token.markers) if (catalog.has(id)) marks.append(markerIcon(id));
    if (marks.childElementCount) item.append(marks);
    const bars = node('div',undefined,'board-token-bars '+token.barPosition+' '+token.barStyle);
    token.bars.forEach((bar,index) => {
      if (!bar.show || bar.value === null || !bar.max) return;
      const barTrack = node('div',undefined,'board-token-bar-track');
      const fill = node('span');
      fill.style.width = Math.max(0,Math.min(100,bar.value/bar.max*100))+'%';
      fill.style.background = colors[index];
      barTrack.append(fill);
      barTrack.setAttribute('aria-label',bar.value+' sur '+bar.max);
      bars.append(barTrack);
    });
    if (bars.childElementCount) item.append(bars);
  }

  function create({grid,root,action,onError}) {
    let current = null, requestInFlight = false;
    function selected(entity,target) {
      const item = entity && grid.querySelector('.board-entity[data-entity-id="'+entity.id+'"]');
      const existing = grid.querySelector('.board-token-controls');
      if (existing && existing.parentElement !== item) existing.remove();
      current = item && entity.token ? {entity,target,item} : null;
      if (!current || item.querySelector('.board-token-controls')) return;
      const controls = node('div',undefined,'board-token-controls');
      const values = node('div',undefined,'board-token-values');
      const tools = node('div',undefined,'board-token-actions');
      entity.token.bars.forEach((bar,index) => {
        const control = button(bar.value ?? '–','Modifier la barre '+(index+1),event=>quickBar(index,event.currentTarget),'board-token-circle');
        control.style.setProperty('--token-accent',colors[index]);
        values.append(control);
      });
      tools.append(button('⚙','Paramètres du jeton',event=>settings(event.currentTarget),'board-token-circle'),
        button('◎','Marqueurs d’état',event=>markerPicker(event.currentTarget),'board-token-circle'));
      controls.append(values,tools);
      item.append(controls);
    }
    const payloadFor = entry => ({pageId:entry.target.page.id,layer:entry.target.layer,entityId:entry.entity.id});
    function popup(title,anchor,css) {
      const bounds = anchor.getBoundingClientRect();
      const dialog = node('dialog',undefined,'library-modal '+css);
      dialog.setAttribute('aria-label',title);
      document.body.append(dialog);
      const enhanced = window.MapWindows.enhance(dialog,title);
      enhanced.open();
      const frame = dialog.getBoundingClientRect();
      dialog.style.left = Math.max(8,Math.min(bounds.left,innerWidth-frame.width-8))+'px';
      dialog.style.top = Math.max(8,Math.min(bounds.bottom+8,innerHeight-frame.height-8))+'px';
      dialog.addEventListener('close',()=>dialog.remove(),{once:true});
      return {dialog,body:enhanced.body};
    }
    function field(parent,label,input) {
      const wrapper = node('label',label);
      wrapper.append(input);
      parent.append(wrapper);
      return input;
    }
    function input(type,value) {
      const control = node('input');
      control.type = type;
      if (type === 'checkbox') control.checked = Boolean(value);
      else control.value = value ?? '';
      return control;
    }
    function select(choices,value) {
      const control = node('select');
      for (const [id,label] of choices) control.add(new Option(label,id));
      control.value = value;
      return control;
    }
    async function save(entry,change,extra,status) {
      if (requestInFlight) return false;
      requestInFlight = true;
      try {
        await action('configure_token',{...payloadFor(entry),change,...extra});
        if (status) status.textContent = '';
        return true;
      } catch (error) {
        if (status) status.textContent = error.message;
        else onError(error);
        return false;
      } finally {
        requestInFlight = false;
      }
    }
    function quickBar(index,anchor) {
      const entry = current;
      if (!entry) return;
      const {dialog,body} = popup('Barre '+(index+1),anchor,'board-token-mini');
      const form = node('form',undefined,'board-token-form');
      const value = field(form,'Valeur',input('number',entry.entity.token.bars[index].value));
      value.step = 'any';
      const apply = node('button','Enregistrer','button button-small');
      const status = node('output');
      form.append(apply,status);
      body.append(form);
      form.addEventListener('submit',async event => {
        event.preventDefault();
        if (await save(entry,'bar',{index,value:value.value === '' ? null : value.valueAsNumber},status)) dialog.close();
      });
      value.select();
    }
    function markerPicker(anchor) {
      const entry = current;
      if (!entry) return;
      const {body} = popup('Marqueurs d’état',anchor,'board-token-picker');
      const search = input('search','');
      search.placeholder = 'Rechercher un marqueur';
      search.setAttribute('aria-label','Rechercher un marqueur');
      const list = node('div',undefined,'board-token-marker-list');
      const status = node('output');
      let marked = new Set(entry.entity.token.markers);
      async function update(next) {
        if (await save(entry,'markers',{markers:[...next]},status)) {
          marked = next;
          render();
        }
      }
      function render() {
        const query = search.value.trim().toLocaleLowerCase('fr');
        list.replaceChildren();
        for (const [id,label] of markers) {
          if (!label.toLocaleLowerCase('fr').includes(query)) continue;
          const choice = button('',label,()=>{
            const next = new Set(marked);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            update(next);
          });
          choice.setAttribute('aria-pressed',String(marked.has(id)));
          choice.append(markerIcon(id));
          list.append(choice);
        }
      }
      search.addEventListener('input',render);
      body.append(search,button('Tout effacer','Retirer tous les marqueurs',()=>update(new Set())),list,status);
      render();
      search.focus();
    }
    async function settings(anchor) {
      const entry = current;
      if (!entry) return;
      const {dialog,body} = popup('Paramètres du jeton',anchor,'board-token-settings');
      const status = node('output','Chargement…');
      status.setAttribute('aria-live','polite');
      body.append(status);
      try {
        const url = new URL(root.dataset.boardApi,location.href);
        url.search = new URLSearchParams({...payloadFor(entry),tokenSettings:entry.entity.id,campaignId:root.dataset.campaignId});
        const response = await fetch(url,{cache:'no-store'});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Paramètres indisponibles.');
        if (!dialog.open) return;
        status.textContent = '';
        settingsForm(entry,result.data.token,dialog,body,status);
      } catch (error) {
        status.textContent = error.message;
      }
    }
    function settingsForm(entry,data,dialog,body,status) {
      const {options} = data;
      const form = node('form',undefined,'board-token-form');
      const general = node('section');
      general.append(node('h3','Général'));
      const characters = new Map(data.characters.map(item=>[String(item.id),item]));
      if (!characters.has(String(data.noteId))) characters.set(String(data.noteId),{name:data.name,fields:[]});
      const character = field(general,'Représente un personnage',select([...characters].map(([id,item])=>[id,item.name]),data.noteId));
      const name = field(general,'Nom',input('text',options.name));
      name.maxLength = 160;
      name.placeholder = data.name;
      const showName = field(general,'Afficher le nom',input('checkbox',options.showName));
      general.append(node('small','Contrôle défini par les droits du personnage.','muted'));
      const opacity = field(general,'Opacité de base',input('range',options.opacity));
      opacity.min = 0; opacity.max = 100;
      const opacityLabel = node('output',opacity.value+' %');
      general.append(opacityLabel);
      opacity.addEventListener('input',()=>{opacityLabel.textContent = opacity.value+' %';});
      const tint = field(general,'Teinte',input('color',options.tint || '#ffffff'));
      const hasTint = field(general,'Appliquer la teinte',input('checkbox',Boolean(options.tint)));
      tint.addEventListener('input',()=>{hasTint.checked = true;});
      const sheetButton = button('Ouvrir la fiche de personnage','Ouvrir la fiche de personnage',()=>{
        openSheet(Number(character.value),characters.get(character.value).name);
      });
      general.append(sheetButton);
      const bars = node('section');
      bars.append(node('h3','Barres de jeton'));
      const barInputs = options.bars.map((bar,index)=>{
        const box = node('fieldset',undefined,'board-token-bar-settings');
        const legend = node('legend','Barre '+(index+1));
        legend.style.color = colors[index];
        box.append(legend);
        const value = field(box,'Valeur',input('number',bar.value));
        const max = field(box,'Maximum',input('number',bar.max));
        value.step = max.step = 'any';
        max.min = 0;
        const link = field(box,'Caractéristique',select([],''));
        const visible = field(box,'Afficher',input('checkbox',bar.show));
        bars.append(box);
        return {value,max,link,visible};
      });
      function syncFields(useOriginal) {
        const fields = characters.get(character.value)?.fields ?? [];
        barInputs.forEach(({link,value},index)=>{
          link.replaceChildren(new Option('Aucune',''),...fields.map(item=>new Option(item.label,item.id)));
          if (useOriginal && fields.some(item=>item.id===options.bars[index].field)) link.value = options.bars[index].field;
          value.readOnly = Boolean(link.value);
        });
        openSheet.disabled = character.value === String(data.noteId) && !data.canAccessSheet;
      }
      barInputs.forEach(({link,value})=>link.addEventListener('change',()=>{value.readOnly = Boolean(link.value);}));
      character.addEventListener('change',()=>syncFields(false));
      syncFields(true);
      const appearance = node('section');
      appearance.append(node('h3','Infobulle et affichage'));
      const tooltip = field(appearance,'Infobulle',node('textarea'));
      tooltip.maxLength = 150;
      tooltip.value = options.tooltip;
      const showTooltip = field(appearance,'Afficher l’infobulle',input('checkbox',options.showTooltip));
      const gmTooltip = field(appearance,'MJ uniquement',input('checkbox',options.gmTooltip));
      gmTooltip.disabled = !data.canManage;
      if (!data.canManage && options.gmTooltip) tooltip.disabled = showTooltip.disabled = true;
      const barPosition = field(appearance,'Position des barres',select([['above','Au-dessus'],['below','En dessous']],options.barPosition));
      const barStyle = field(appearance,'Style des barres',select([['standard','Standard'],['compact','Compact']],options.barStyle));
      function configuration() {
        return {name:name.value,showName:showName.checked,opacity:Number(opacity.value),tint:hasTint.checked ? tint.value : '',
          tooltip:tooltip.value,showTooltip:showTooltip.checked,gmTooltip:gmTooltip.checked,barPosition:barPosition.value,barStyle:barStyle.value,
          bars:barInputs.map(({value,max,link,visible})=>({value:value.value || null,max:max.value || null,field:link.value,show:visible.checked}))};
      }
      const actions = node('div',undefined,'board-token-form-actions');
      const saveDefault = button('Mettre à jour le jeton par défaut','Appliquer ces réglages aux futurs jetons de ce personnage',async()=>{
        if (!form.reportValidity()) return;
        if (await save(entry,'configure',{noteId:Number(character.value),options:configuration(),saveDefault:true},status)) {
          status.textContent = 'Jeton par défaut enregistré.';
        }
      });
      saveDefault.disabled = !data.canSaveDefault;
      const submit = node('button','Enregistrer','button');
      actions.append(saveDefault,submit);
      form.append(general,bars,appearance,actions,status);
      body.append(form);
      form.addEventListener('submit',async event=>{
        event.preventDefault();
        if (await save(entry,'configure',{noteId:Number(character.value),options:configuration()},status)) dialog.close();
      });
    }
    function openSheet(noteId,title) {
      const url = new URL('character-sheets.php',new URL(root.dataset.boardApi,location.href));
      window.MapCharacterSheets.open({noteId,campaignId:Number(root.dataset.campaignId),title,
        apiUrl:url.href,csrfToken:root.dataset.csrfToken,windows:window.MapWindows});
    }

    return {selected,openSheet};
  }
  window.MapBoardTokens = {decorate,create};
})();
