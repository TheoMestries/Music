(() => {
  const libraryBase = new URL('../vendor/pdfjs/', document.currentScript.src);
  const opened = new Map();
  const visibilityLabels = {private: 'Privé', campaign: 'Cette campagne', public: 'Public'};
  let pdfLibrary;
  const clamp=(n,min,max)=>Math.max(min,Math.min(max,n));
  window.MapCharacterSheets = {
    open(config) {
      const target = config.entryId === undefined ? {noteId: config.noteId} : {entryId: config.entryId};
      const key = config.campaignId + ':' + (target.entryId === undefined ? 'note:' + target.noteId : 'codex:' + target.entryId);
      if(opened.has(key)){opened.get(key).open();return;}
      const doc=config.document || document;
      const make=(tag,text,className)=>{const n=doc.createElement(tag);if(text)n.textContent=text;if(className)n.className=className;return n;};
      const button=(text,fn)=>{const n=make('button',text,'button button-secondary');n.type='button';n.addEventListener('click',fn);return n;};
      const dialog = config.container ? null : make('dialog', null, 'library-modal sheet-window');
      if (dialog) doc.body.append(dialog);
      const floating = dialog ? config.windows.enhance(dialog,'Fiche — '+config.title) : {body: config.container, open() {}};
      opened.set(key,floating);
      let sheet=null, permissions={}, saving=false, dirty=false, editVersion=0, conflict=false;
      const tools=make('div',null,'sheet-toolbar');
      const status=make('p','Chargement…','sheet-status');status.setAttribute('aria-live','polite');
      const stage=make('div',null,'sheet-pages');
      const chooser=make('select');chooser.setAttribute('aria-label','Modèle de fiche');
      const attach=button('Utiliser ce modèle',()=>manageTemplate(async()=>{
        if(sheet && Number(chooser.value)!==sheet.template.id && !doc.defaultView.confirm('Changer de modèle remplacera les valeurs de cette fiche. Continuer ?'))return;
        const data=await request('assign',{templateId:Number(chooser.value)});
        sheet=data.sheet;dirty=false;conflict=false;renderSheet();
      }));
      const create=button('Créer un modèle',()=>designer());
      const edit=button('Modifier le modèle',()=>manageTemplate(async()=>{
        const {template}=await request(null,{templateId:chooser.value});
        designer(template);
      }));
      const copy=button('Copier le modèle',()=>manageTemplate(async()=>{
        const requiresCopy = selectedTemplate().requiresCopy;
        const {template}=await request('copy_template',{templateId:Number(chooser.value)});
        rememberTemplate(template);
        if (requiresCopy) {
          status.classList.remove('sheet-error');
          status.textContent = 'Modèle copié dans cette campagne. Cliquez sur « Utiliser ce modèle » pour l’attribuer à la fiche.';
        } else {
          designer(template);
        }
      }));
      const removeTemplate=button('Supprimer le modèle',()=>manageTemplate(async()=>{
        const selected=selectedTemplate();
        if(!doc.defaultView.confirm('Supprimer « '+selected.name+' » des modèles disponibles ? Les fiches déjà attribuées seront conservées.'))return;
        await request('delete_template',{templateId:selected.id});
        templates(templateList.filter(item=>item.id!==selected.id),sheet?.template.id);
        status.classList.remove('sheet-error');
        status.textContent='Modèle supprimé.';
      }));
      const visibilityControls = make('div', null, 'sheet-template-visibility');
      const visibility = make('select');
      visibility.append(new Option('Privé · créateur', 'private'), new Option('Cette campagne', 'campaign'), new Option('Public · toutes les campagnes', 'public'));
      const visibilityLabel = make('label', 'Visibilité du modèle');
      visibilityLabel.append(visibility);
      const applyVisibility = button('Appliquer', () => {
        const nextVisibility = visibility.value;
        manageTemplate(async () => {
          const selected = selectedTemplate();
          await request('template_visibility', {templateId: selected.id, visibility: nextVisibility});
          selected.visibility = nextVisibility;
          templates(templateList, selected.id);
          status.classList.remove('sheet-error');
          status.textContent = 'Visibilité du modèle enregistrée.';
        });
      });
      visibility.addEventListener('change', () => {
        applyVisibility.disabled = visibility.value === selectedTemplate()?.visibility;
      });
      visibilityControls.hidden = true;
      visibilityControls.append(visibilityLabel, applyVisibility);
      const save=button('Enregistrer la fiche',()=>saveValues());
      chooser.hidden=attach.hidden=create.hidden=edit.hidden=copy.hidden=removeTemplate.hidden=save.hidden=true;
      if (dialog) tools.append(button('Bio & infos',()=>{if(closeAllowed()){dialog.close();config.onBio?.();}}));
      tools.append(chooser,attach,create,edit,copy,removeTemplate,save);
      floating.body.replaceChildren(tools,visibilityControls,status,stage);
      function closeAllowed(){return !saving && !managing && !editorOpen && (!dirty || doc.defaultView.confirm('Les modifications de cette fiche ne sont pas enregistrées. Fermer ?'));}
      dialog?.addEventListener('cancel',event=>{if(!closeAllowed())event.preventDefault();});
      dialog?.addEventListener('close',()=>{opened.delete(key);dialog.remove();});
      const beforeUnload=event=>{if(dirty){event.preventDefault();event.returnValue='';}};
      doc.defaultView.addEventListener('beforeunload',beforeUnload);
      dialog?.addEventListener('close',()=>doc.defaultView.removeEventListener('beforeunload',beforeUnload));
      async function request(action,body={}) {
        const url=new URL(config.apiUrl,doc.location.href);
        const options={credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
        if(action){options.method='POST';options.headers['Content-Type']='application/json';options.headers['X-CSRF-Token']=config.csrfToken;options.body=JSON.stringify({action,campaignId:config.campaignId,...target,...body});}
        else {url.searchParams.set('campaignId',config.campaignId);for(const [name,id] of Object.entries(target))url.searchParams.set(name,id);if(body.templateId)url.searchParams.set('templateId',body.templateId);}
        const response=await fetch(url,options);const payload=await response.json();
        if(!response.ok || !payload.success){const error=new Error(payload.error||'Impossible de charger la fiche.');error.status=response.status;throw error;}
        return payload.data;
      }
      async function run(task){try{await task();}catch(error){status.textContent=error.message;status.classList.add('sheet-error');}}
      let templateList=[], managing=false, editorOpen=false;
      function selectedTemplate(){return templateList.find(item=>String(item.id)===chooser.value);}
      function templateActions(){
        const selected=selectedTemplate();
        const ownsLocalTemplate = selected?.isOwner && !selected.requiresCopy;
        chooser.hidden=!permissions.canManage && !templateList.length;
        attach.hidden=create.hidden=!permissions.canManage;
        edit.hidden=removeTemplate.hidden=visibilityControls.hidden=!ownsLocalTemplate;
        copy.hidden=!selected || ownsLocalTemplate;
        copy.textContent=selected?.requiresCopy ? 'Copier dans cette campagne' : 'Copier le modèle';
        chooser.disabled=managing || editorOpen;
        create.disabled=edit.disabled=copy.disabled=removeTemplate.disabled=chooser.disabled;
        attach.disabled=saving || chooser.disabled || !selected || selected.requiresCopy;
        visibility.value = selected?.visibility ?? 'private';
        visibility.disabled = chooser.disabled;
        applyVisibility.disabled = true;
      }
      chooser.addEventListener('change',templateActions);
      async function manageTemplate(task){
        if(managing || editorOpen || !selectedTemplate())return;
        managing=true;templateActions();
        try{await run(task);}
        finally{managing=false;templateActions();}
      }
      function templates(list,selected){
        templateList=[...list].sort((a,b)=>a.name.localeCompare(b.name) || a.id-b.id);
        chooser.replaceChildren(new Option('Choisir un modèle…',''));
        const local = make('optgroup');
        local.label = 'Modèles de cette campagne';
        const reusable = make('optgroup');
        reusable.label = 'Modèles à copier dans cette campagne';
        for (const template of templateList) {
          const label = visibilityLabels[template.visibility];
          const option = new Option(label ? template.name + ' · ' + label : template.name, String(template.id));
          (template.requiresCopy ? reusable : local).append(option);
        }
        for (const group of [local, reusable]) {
          if (group.children.length) chooser.append(group);
        }
        chooser.value=templateList.some(item=>item.id===selected)?String(selected):'';
        templateActions();
      }
      function rememberTemplate(template) {
        const {id, name, visibility} = template;
        templates([...templateList.filter(item => item.id !== id), {id, name, visibility, isOwner: true, requiresCopy: false}], id);
      }
      async function load(){await run(async()=>{
        const data=await request();sheet=data.sheet;permissions=data;
        templates(data.templates,sheet?.template.id);save.hidden=!data.canEdit;
        renderSheet();
      });}
      function renderSheet(){
        stage.replaceChildren();
        status.classList.remove('sheet-error');
        if(!sheet){status.textContent=permissions.canManage?'Choisissez un modèle existant ou créez-en un à partir d’une image ou d’un PDF.':'Le MJ n’a pas encore choisi de modèle pour ce personnage.';save.disabled=true;return;}
        save.disabled=!permissions.canEdit;
        status.textContent=permissions.canEdit?'Remplissez la fiche, puis enregistrez-la.':'Fiche en lecture seule.';
        sheet.template.pages.forEach((image,index)=>{
          const page=make('div',null,'sheet-page');const img=make('img');img.src=image;img.alt='Page '+(index+1);img.draggable=false;page.append(img);
          sheet.template.fields.filter(field=>field.page===index).forEach(field=>{
            const isCheckbox = field.type === 'checkbox';
            const input = make(isCheckbox ? 'input' : 'textarea', null, isCheckbox ? null : 'sheet-value');
            const zone = isCheckbox ? make('label', null, 'sheet-checkbox') : input;
            input.setAttribute('aria-label', field.label);
            zone.title = field.label;
            if (isCheckbox) {
              input.type = 'checkbox';
              input.checked = sheet.values[field.id] === true;
              input.disabled = !permissions.canEdit;
              const mark = make('span', '✓');
              mark.setAttribute('aria-hidden', 'true');
              zone.append(input, mark);
            } else {
              input.value = sheet.values[field.id] || '';
              input.readOnly = !permissions.canEdit;
              input.maxLength = 10000;
              input.spellcheck = false;
              input.style.fontSize = (field.font * 100) + 'cqw';
            }
            position(zone, field);
            input.addEventListener('input', () => {
              sheet.values[field.id] = isCheckbox ? input.checked : input.value;
              dirty = true;
              editVersion++;
              status.textContent = 'Modifications non enregistrées.';
            });
            page.append(zone);
          });stage.append(page);
        });
      }
      function position(node,f){Object.assign(node.style,{left:(f.x*100)+'%',top:(f.y*100)+'%',width:(f.w*100)+'%',height:(f.h*100)+'%'});}
      async function saveValues(){
        if(!sheet || saving || conflict || !permissions.canEdit)return;
        saving=true;save.disabled=true;attach.disabled=true;const version=editVersion;
        try {const result=await request('save',{revision:sheet.revision,values:{...sheet.values}});sheet.revision=result.revision;if(version===editVersion)dirty=false;status.textContent=dirty?'Certaines modifications restent à enregistrer.':'Fiche enregistrée.';}
        catch(error){conflict=error.status===409;status.textContent=error.message;status.classList.add('sheet-error');}
        finally{saving=false;save.disabled=conflict;templateActions();}
      }
      function designer(template=null){
        if(editorOpen)return;
        editorOpen=true;templateActions();
        const editor=make('dialog',null,'library-modal sheet-window sheet-designer');doc.body.append(editor);
        const view=config.windows.enhance(editor,template?'Modifier un modèle de fiche':'Créer un modèle de fiche');
        let pages=template?.pages ?? [],fields=template?.fields ?? [],selected=null,drawing=true,changed=false,busy=false;
        const name=make('input');name.placeholder='Nom du modèle';name.maxLength=160;name.setAttribute('aria-label','Nom du modèle');
        name.value=template?.name ?? '';
        const upload=make('input');upload.type='file';upload.accept='application/pdf,image/png,image/jpeg,image/gif,image/webp,image/avif';upload.multiple=true;upload.setAttribute('aria-label','Importer les pages du modèle');
        const info=make('p',template?'Sélectionnez une zone pour la modifier ou tracez une nouvelle zone.':'Importez une image ou un PDF, puis tracez les zones de texte et les cases à cocher sur les pages.','sheet-status');info.setAttribute('aria-live','polite');
        const mode=button('Tracer des zones : activé',()=>{drawing=!drawing;mode.textContent='Tracer des zones : '+(drawing?'activé':'désactivé');mode.setAttribute('aria-pressed',String(drawing));});mode.setAttribute('aria-pressed','true');
        const drawType = make('select');
        drawType.append(new Option('Texte', 'text'), new Option('Case à cocher', 'checkbox'));
        const drawTypeLabel = make('label', 'Type des nouvelles zones');
        drawTypeLabel.append(drawType);
        const label=make('input');label.placeholder='Nom de la zone sélectionnée';label.setAttribute('aria-label','Nom de la zone');label.maxLength=160;
        const font=make('input');font.type='number';font.min='5';font.max='80';font.value='15';font.setAttribute('aria-label','Taille du texte pour une page de 1000 pixels');
        const remove=button('Supprimer la zone',()=>{fields=fields.filter(f=>f.id!==selected);selected=null;changed=true;renderDesigner();});
        const nameLabel=make('label','Nom du modèle');nameLabel.append(name);
        const zoneLabel=make('label','Zone sélectionnée');zoneLabel.append(label);
        const fontLabel=make('label','Taille du texte');fontLabel.append(font);
        const controls=make('div',null,'sheet-designer-controls');
        const saveTemplate=button('Enregistrer le modèle',async()=>{
          if(busy)return;
          if(template && template.id===sheet?.template.id && (dirty || saving)){
            info.textContent='Enregistrez les modifications de la fiche avant de modifier son modèle.';
            info.classList.add('sheet-error');
            return;
          }
          busy=true;saveTemplate.disabled=true;upload.disabled=true;
          try{
            const data=await request(template?'update_template':'create_template',{templateId:template?.id,name:name.value.trim(),pages,fields});
            const saved=data.template;
            rememberTemplate(saved);
            changed=false;editor.close();
            await run(async()=>{
              if(sheet?.template.id===saved.id && !dirty && !saving){
                const current=await request();
                if(!dirty && !saving){sheet=current.sheet;conflict=false;renderSheet();}
              }
              status.classList.remove('sheet-error');
              status.textContent=sheet?.template.id===saved.id || !permissions.canManage
                ? 'Modèle enregistré.' : 'Modèle enregistré. Cliquez sur « Utiliser ce modèle » pour l’attribuer au personnage.';
            });
          }catch(error){info.textContent=error.message;info.classList.add('sheet-error');}
          finally{busy=false;saveTemplate.disabled=false;upload.disabled=false;}
        });
        controls.append(nameLabel,upload,mode,drawTypeLabel,zoneLabel,fontLabel,remove,saveTemplate);
        const canvas=make('div',null,'sheet-pages');view.body.append(controls,info,canvas);
        name.addEventListener('input',()=>changed=true);
        function selectedField(){return fields.find(f=>f.id===selected);}
        function describeZone(zone, field) {
          zone.firstChild.textContent = field.type === 'checkbox' ? '✓' : field.label;
          zone.title = field.label;
          zone.setAttribute('aria-label', field.label + ' — flèches pour déplacer, Maj + flèches pour redimensionner');
        }
        label.addEventListener('input', () => {
          const field = selectedField();
          if (!field) return;
          field.label = label.value;
          changed = true;
          const zone = canvas.querySelector('[data-field="' + field.id + '"]');
          if (zone) describeZone(zone, field);
        });
        font.addEventListener('change',()=>{const f=selectedField();if(f){f.font=clamp(Number(font.value)||15,5,80)/1000;changed=true;}});
        function select(id) {
          selected = id;
          const field = selectedField();
          label.value = field?.label || '';
          font.value = field ? Math.round(field.font * 1000) : 15;
          label.disabled = remove.disabled = !field;
          fontLabel.hidden = field?.type === 'checkbox';
          font.disabled = !field || fontLabel.hidden;
          canvas.querySelectorAll('.sheet-zone').forEach(zone => zone.classList.toggle('selected', zone.dataset.field === id));
        }
        function renderDesigner(){
          canvas.replaceChildren();pages.forEach((image,index)=>{
            const page=make('div',null,'sheet-page sheet-edit-page');const img=make('img');img.src=image;img.alt='Page '+(index+1);img.draggable=false;page.append(img);canvas.append(page);
            fields.filter(f=>f.page===index).forEach(f=>{
              const zone=make('div',null,'sheet-zone');
              zone.dataset.field=f.id;
              zone.dataset.type=f.type || 'text';
              position(zone,f);
              zone.append(make('span'));
              describeZone(zone,f);
              const handle=make('span','↘','sheet-zone-resize');handle.setAttribute('aria-hidden','true');zone.append(handle);
              zone.tabIndex=0;
              zone.addEventListener('focus',()=>select(f.id));
              zone.addEventListener('keydown',event=>{
                if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Delete'].includes(event.key))return;
                event.preventDefault();select(f.id);
                if(event.key==='Delete'){remove.click();return;}
                const dx=event.key==='ArrowLeft'?-.002:event.key==='ArrowRight'?.002:0,dy=event.key==='ArrowUp'?-.002:event.key==='ArrowDown'?.002:0;
                if(event.shiftKey){f.w=clamp(f.w+dx,.005,1-f.x);f.h=clamp(f.h+dy,.005,1-f.y);}else{f.x=clamp(f.x+dx,0,1-f.w);f.y=clamp(f.y+dy,0,1-f.h);}
                changed=true;position(zone,f);
              });page.append(zone);
            });
            page.addEventListener('pointerdown',event=>{
              if(busy || event.button!==0)return;
              const rect=page.getBoundingClientRect();if(!rect.width || !rect.height)return;
              const point=e=>({x:clamp((e.clientX-rect.left)/rect.width,0,1),y:clamp((e.clientY-rect.top)/rect.height,0,1)});
              const start=point(event),target=event.target.closest('.sheet-zone');
              let f,node,origin,kind;
              if(target){f=fields.find(v=>v.id===target.dataset.field);node=target;kind=event.target.closest('.sheet-zone-resize')?'resize':'move';select(f.id);origin={...f};}
              else{
                if(!drawing || fields.length>=300)return;
                const type = drawType.value;
                const defaultLabel = (type === 'checkbox' ? 'Case ' : 'Zone ') + (fields.length + 1);
                f={id:'f_'+crypto.randomUUID().replaceAll('-',''),page:index,type,label:defaultLabel,x:start.x,y:start.y,w:0,h:0,font:.015};
                kind='draw';
                node=make('div',null,'sheet-zone selected');
                node.dataset.type=type;
                node.append(make('span'));
                describeZone(node,f);
                page.append(node);
                position(node,f);
              }
              event.preventDefault();page.setPointerCapture(event.pointerId);
              function drag(e){const p=point(e);if(kind==='move'){f.x=clamp(origin.x+p.x-start.x,0,1-f.w);f.y=clamp(origin.y+p.y-start.y,0,1-f.h);}else if(kind==='resize'){f.w=clamp(origin.w+p.x-start.x,.005,1-f.x);f.h=clamp(origin.h+p.y-start.y,.005,1-f.y);}else{f.x=Math.min(start.x,p.x);f.y=Math.min(start.y,p.y);f.w=Math.abs(p.x-start.x);f.h=Math.abs(p.y-start.y);}position(node,f);}
              function end(e){page.removeEventListener('pointermove',drag);page.removeEventListener('pointerup',end);page.removeEventListener('pointercancel',cancel);if(page.hasPointerCapture(event.pointerId))page.releasePointerCapture(event.pointerId);if(kind==='draw'){if(f.w>=.005&&f.h>=.005){fields.push(f);selected=f.id;changed=true;}}else changed=true;renderDesigner();}
              function cancel(e){if(origin)Object.assign(f,origin);page.removeEventListener('pointermove',drag);page.removeEventListener('pointerup',end);page.removeEventListener('pointercancel',cancel);renderDesigner();}
              page.addEventListener('pointermove',drag);page.addEventListener('pointerup',end);page.addEventListener('pointercancel',cancel);
            });
          });select(selected);
        }
        async function imagePage(file){
          const bitmap=await createImageBitmap(file);
          const scale=Math.min(1,1600/Math.max(bitmap.width,bitmap.height));
          const c=doc.createElement('canvas');c.width=Math.round(bitmap.width*scale);c.height=Math.round(bitmap.height*scale);
          const context=c.getContext('2d');context.fillStyle='white';context.fillRect(0,0,c.width,c.height);context.drawImage(bitmap,0,0,c.width,c.height);bitmap.close();return c.toDataURL('image/jpeg',.88);
        }
        upload.addEventListener('change',async()=>{
          if(!upload.files.length || busy)return;
          busy=true;upload.disabled=saveTemplate.disabled=true;info.classList.remove('sheet-error');
          try{
            const incoming=[];
            for(const file of upload.files){
              if(file.size>25000000)throw new Error('Chaque fichier source doit faire moins de 25 Mo.');
              if(file.type==='application/pdf' || /\.pdf$/i.test(file.name)){
                info.textContent='Conversion du PDF en pages…';
                pdfLibrary=pdfLibrary || import(new URL('build/pdf.js',libraryBase).href);
                const pdfjs=await pdfLibrary;pdfjs.GlobalWorkerOptions.workerSrc=new URL('build/pdf.worker.js',libraryBase).href;
                const task=pdfjs.getDocument({data:new Uint8Array(await file.arrayBuffer()),cMapUrl:new URL('cmaps/',libraryBase).href,cMapPacked:true,standardFontDataUrl:new URL('standard_fonts/',libraryBase).href,wasmUrl:new URL('wasm/',libraryBase).href,isEvalSupported:false});
                const pdf=await task.promise;
                try{
                  if(pages.length+incoming.length+pdf.numPages>12)throw new Error('Un modèle est limité à 12 pages.');
                  for(let i=1;i<=pdf.numPages;i++){
                    info.textContent='Conversion de la page '+i+' / '+pdf.numPages+'…';const page=await pdf.getPage(i);const base=page.getViewport({scale:1});const viewport=page.getViewport({scale:1600/Math.max(base.width,base.height)});
                    const c=doc.createElement('canvas');c.width=Math.ceil(viewport.width);c.height=Math.ceil(viewport.height);
                    await page.render({canvasContext:c.getContext('2d'),viewport}).promise;incoming.push(c.toDataURL('image/jpeg',.88));page.cleanup?.();
                  }
                }finally{await task.destroy();}
              }else if(['image/png','image/jpeg','image/gif','image/webp','image/avif'].includes(file.type)){incoming.push(await imagePage(file));}
              else throw new Error('Choisissez une image ou un PDF.');
            }
            if(pages.length+incoming.length>12)throw new Error('Un modèle est limité à 12 pages.');
            if([...pages,...incoming].reduce((sum,p)=>sum+p.length,0)>6000000)throw new Error('Les pages converties dépassent 6 Mo. Importez moins de pages.');
            pages.push(...incoming);changed=true;if(!name.value)name.value=upload.files[0].name.replace(/\.[^.]+$/,'');renderDesigner();info.textContent='Tracez les zones. Cliquez sur une zone pour la nommer, déplacez-la ou utilisez son coin inférieur droit pour la redimensionner. Les flèches déplacent la sélection, Maj + flèches la redimensionnent.';
          }catch(error){info.textContent=error.message;info.classList.add('sheet-error');}
          finally{busy=false;upload.disabled=saveTemplate.disabled=false;upload.value='';}
        });
        editor.addEventListener('cancel',event=>{if(busy || (changed&&!doc.defaultView.confirm('Quitter sans enregistrer ce modèle ?')))event.preventDefault();});
        editor.addEventListener('close',()=>{editorOpen=false;templateActions();editor.remove();});
        view.open();renderDesigner();
      }
      floating.open();load();
    }
  };
})();
