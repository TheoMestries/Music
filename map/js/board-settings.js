(() => {
  const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
  function dominant(canvas){
    const sample=document.createElement('canvas');sample.width=sample.height=32;const ctx=sample.getContext('2d');ctx.drawImage(canvas,0,0,32,32);const pixels=ctx.getImageData(0,0,32,32).data,buckets=new Map();
    for(let i=0;i<pixels.length;i+=4){if(pixels[i+3]<128)continue;const key=(pixels[i]>>4)+','+(pixels[i+1]>>4)+','+(pixels[i+2]>>4),b=buckets.get(key)||[0,0,0,0];b[0]++;b[1]+=pixels[i];b[2]+=pixels[i+1];b[3]+=pixels[i+2];buckets.set(key,b);}
    const b=[...buckets.values()].sort((a,b)=>b[0]-a[0])[0];return b?'#'+b.slice(1).map(v=>Math.round(v/b[0]).toString(16).padStart(2,'0')).join(''):'#eee9da';
  }
  window.MapBoardImages={async read(file){
    if(!file||file.size>25000000||!['image/png','image/jpeg','image/gif','image/webp','image/avif'].includes(file.type))throw new Error('Choisissez une image PNG, JPEG, GIF, WebP ou AVIF de moins de 25 Mo.');
    const bitmap=await createImageBitmap(file);
    try{
      const canvas=document.createElement('canvas'),scale=Math.min(1,2400/Math.max(bitmap.width,bitmap.height));canvas.width=Math.max(1,Math.round(bitmap.width*scale));canvas.height=Math.max(1,Math.round(bitmap.height*scale));canvas.getContext('2d').drawImage(bitmap,0,0,canvas.width,canvas.height);
      const image=canvas.toDataURL('image/webp',.9);if(image.length>2796200)throw new Error('L’image reste trop volumineuse. Choisissez une image plus légère.');
      const thumb=document.createElement('canvas'),factor=220/Math.max(canvas.width,canvas.height);thumb.width=Math.max(1,Math.round(canvas.width*factor));thumb.height=Math.max(1,Math.round(canvas.height*factor));thumb.getContext('2d').drawImage(canvas,0,0,thumb.width,thumb.height);
      return {image,thumbnail:thumb.toDataURL('image/webp',.75),width:canvas.width,height:canvas.height,dominantColor:dominant(canvas)};
    }finally{bitmap.close();}
  }};
  const openWindows=new Map();
  window.MapBoardSettings={
  closeAll(){
    for(const {dialog} of openWindows.values()){
      if(!dialog.open)continue;
      if(!dialog.dispatchEvent(new Event('cancel',{cancelable:true})))return false;
      dialog.close();
    }
    return true;
  },
  open(config){
    const key=config.page.id;if(openWindows.has(key)){openWindows.get(key).floating.open();return;}
    const page=structuredClone(config.page),s={...window.MapBoardRenderer.defaults,...page.settings,crop:{x:0,y:0,w:1,h:1,...page.settings?.crop}};page.settings=s;
    const make=(tag,text,css)=>{const n=document.createElement(tag);if(text)n.textContent=text;if(css)n.className=css;return n;};
    const button=(text,fn)=>{const b=make('button',text,'button button-secondary');b.type='button';b.addEventListener('click',fn);return b;};
    const dialog=make('dialog',null,'library-modal board-settings-window');document.body.append(dialog);
    const sectionNames={image:'Image de fond',crop:'Recadrage',grid:'Paramètres de la grille',appearance:'Personnalisation de la page',dimensions:'Dimensions de la page',scale:'Échelle et mesures',all:'Paramètres de page'};
    const floating=window.MapWindows.enhance(dialog,sectionNames[config.section]||sectionNames.all);openWindows.set(key,{floating,dialog});
    const form=make('form',null,'board-settings-form'),status=make('p',null,'board-settings-status');status.setAttribute('aria-live','polite');
    const inputs=new Map(),syncCallbacks=[];let changed=false,busy=false,imageChanged=false;
    const get=key=>key.startsWith('crop.')?s.crop[key.slice(5)]:key==='cellSizeY'?(s.cellSizeY??s.cellSize):s[key];
    function sync(){inputs.forEach((controls,key)=>controls.forEach(input=>{if(document.activeElement===input)return;const value=get(key);if(input.type==='checkbox')input.checked=value;else input.value=value;}));syncCallbacks.forEach(fn=>fn());}
    function preview(){changed=true;sync();config.onPreview?.(page);}
    function set(key,value){if(key==='width'||key==='height')value=Number(value.toFixed(2));if(key.startsWith('crop.'))s.crop[key.slice(5)]=value;else s[key]=value;preview();}
    function bind(key,input){input.dataset.setting=key;if(!inputs.has(key))inputs.set(key,[]);inputs.get(key).push(input);return input;}
    function group(key){const section=make('section',null,'board-settings-group');section.dataset.section=key;section.append(make('h3',sectionNames[key]));section.hidden=config.section!=='all'&&config.section!==key;form.append(section);return section;}
    const groups={};['image','crop','dimensions','grid','appearance','scale'].forEach(key=>groups[key]=group(key));
    function field(parent,label,input){const wrap=make('label',label,'board-setting-field');wrap.append(input);parent.append(wrap);return input;}
    function numeric(parent,label,key,min,max,step=1){const n=bind(key,make('input'));n.type='number';n.min=min;n.max=max;n.step=step;n.value=get(key);field(parent,label,n);n.addEventListener('input',()=>{if(n.value!==''&&n.validity.valid)set(key,Number(n.value));});return n;}
    function toggle(parent,label,key){const n=bind(key,make('input'));n.type='checkbox';n.checked=get(key);field(parent,label,n).closest('label').classList.add('board-setting-toggle');n.addEventListener('change',()=>{set(key,n.checked);if(key==='autoDominant'&&n.checked)detectDominant();});return n;}
    function select(parent,label,key,values){const n=bind(key,make('select'));Object.entries(values).forEach(([value,text])=>n.append(new Option(text,value)));n.value=get(key);field(parent,label,n);n.addEventListener('change',()=>set(key,n.value));return n;}
    function color(parent,label,key,palette){const wrap=make('div',null,'board-color-field');wrap.append(make('strong',label));const row=make('div',null,'board-color-palette');palette.forEach(value=>{const b=button('',()=>set(key,value));b.style.backgroundColor=value;b.title=value;b.setAttribute('aria-label',label+' '+value);row.append(b);});const picker=bind(key,make('input'));picker.type='color';picker.value=get(key);picker.setAttribute('aria-label',label);picker.addEventListener('input',()=>set(key,picker.value));row.append(picker);wrap.append(row);parent.append(wrap);if(key==='backgroundColor')syncCallbacks.push(()=>{row.querySelectorAll('button,input').forEach(n=>n.disabled=s.autoDominant);});}
    const metadata=make('div',null,'board-page-metadata');form.prepend(metadata);
    const name=make('input');name.value=page.name;name.maxLength=160;name.required=true;field(metadata,'Nom de la page',name);name.addEventListener('input',()=>{page.name=name.value;changed=true;});
    const folder=bind('folder',make('input'));folder.value=s.folder;folder.maxLength=80;folder.placeholder='Toutes les pages';field(metadata,'Dossier',folder);folder.addEventListener('input',()=>{s.folder=folder.value;changed=true;});
    const folderOptions=make('datalist');folderOptions.id='board-folders-'+page.id;(config.folders||[]).forEach(value=>{const option=make('option');option.value=value;folderOptions.append(option);});folder.setAttribute('list',folderOptions.id);metadata.append(folderOptions);
    if(config.section!=='all')metadata.hidden=true;
    const upload=make('input');upload.type='file';upload.accept='image/png,image/jpeg,image/gif,image/webp,image/avif';field(groups.image,'Importer une image de fond',upload);
    const drop=make('div','Déposez une image ici ou choisissez un fichier ci-dessus.','board-image-drop');drop.tabIndex=0;groups.image.append(drop);drop.addEventListener('click',()=>upload.click());drop.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();upload.click();}});
    const adapt=make('input');adapt.type='checkbox';adapt.checked=true;field(groups.image,'Adapter les dimensions de la page à l’image',adapt).closest('label').classList.add('board-setting-toggle');
    groups.image.append(button('Retirer l’image',()=>{page.image='';page.thumbnail='';imageChanged=true;s.backgroundMode='color';s.crop={x:0,y:0,w:1,h:1};renderCrop();preview();}));
    async function importImage(file){if(!file||busy)return;busy=true;save.disabled=true;status.textContent='Préparation de l’image…';try{const result=await window.MapBoardImages.read(file);page.image=result.image;page.thumbnail=result.thumbnail;s.dominantColor=result.dominantColor;s.backgroundMode='image';s.crop={x:0,y:0,w:1,h:1};if(adapt.checked){s.width=Math.max(50,result.width);s.height=Math.max(50,result.height);}imageChanged=true;renderCrop();preview();status.textContent='Image prête. Enregistrez les paramètres pour la partager avec la table.';}catch(error){status.textContent=error.message;}finally{busy=false;save.disabled=false;upload.value='';}}
    upload.addEventListener('change',()=>importImage(upload.files[0]));drop.addEventListener('dragover',e=>{e.preventDefault();drop.classList.add('drag-over');});drop.addEventListener('dragleave',()=>drop.classList.remove('drag-over'));drop.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('drag-over');importImage(e.dataTransfer.files[0]);});
    const cropHelp=make('p','Tracez un rectangle sur l’image pour définir la zone affichée. Déplacez le cadre ou tirez son coin inférieur droit.','muted');
    const cropStage=make('div',null,'board-crop-stage'),cropImage=make('img'),cropBox=make('div',null,'board-crop-box'),cropHandle=make('span','↘','board-crop-resize');cropImage.draggable=false;cropImage.alt='Image à recadrer';cropBox.append(cropHandle);cropStage.append(cropImage,cropBox);groups.crop.append(cropHelp,cropStage);
    function updateCropBox(){Object.assign(cropBox.style,{left:s.crop.x*100+'%',top:s.crop.y*100+'%',width:s.crop.w*100+'%',height:s.crop.h*100+'%'});}
    function renderCrop(){cropStage.hidden=!page.image;cropHelp.textContent=page.image?'Tracez un rectangle sur l’image, déplacez-le ou tirez son coin inférieur droit.':'Importez une image avant de la recadrer.';if(page.image)cropImage.src=page.image;else cropImage.removeAttribute('src');updateCropBox();}
    cropStage.addEventListener('pointerdown',event=>{
      if(busy||event.button!==0)return;event.preventDefault();const rect=cropStage.getBoundingClientRect();if(!rect.width||!rect.height)return;
      if(cropNumbers.contains(document.activeElement))document.activeElement.blur();
      const point=e=>({x:clamp((e.clientX-rect.left)/rect.width,0,1),y:clamp((e.clientY-rect.top)/rect.height,0,1)}),start=point(event),original={...s.crop};
      const kind=event.target===cropHandle?'resize':event.target.closest('.board-crop-box')?'move':'draw';cropStage.setPointerCapture(event.pointerId);
      const move=e=>{const p=point(e);if(kind==='move'){s.crop.x=clamp(original.x+p.x-start.x,0,1-original.w);s.crop.y=clamp(original.y+p.y-start.y,0,1-original.h);}else if(kind==='resize'){s.crop.w=clamp(original.w+p.x-start.x,.005,1-original.x);s.crop.h=clamp(original.h+p.y-start.y,.005,1-original.y);}else{let x=Math.min(start.x,p.x),y=Math.min(start.y,p.y);s.crop={x:Math.min(x,.995),y:Math.min(y,.995),w:Math.max(.005,Math.abs(start.x-p.x)),h:Math.max(.005,Math.abs(start.y-p.y))};s.crop.w=Math.min(s.crop.w,1-s.crop.x);s.crop.h=Math.min(s.crop.h,1-s.crop.y);}updateCropBox();preview();};
      const end=()=>{cropStage.removeEventListener('pointermove',move);cropStage.removeEventListener('pointerup',end);cropStage.removeEventListener('pointercancel',cancel);};const cancel=()=>{s.crop=original;updateCropBox();preview();end();};cropStage.addEventListener('pointermove',move);cropStage.addEventListener('pointerup',end);cropStage.addEventListener('pointercancel',cancel);
    });
    const cropNumbers=make('div',null,'board-number-row');groups.crop.append(cropNumbers);
    ['x','y','w','h'].forEach((key,index)=>{
      const input=make('input');input.type='number';input.min=key==='w'||key==='h'?.5:0;input.max=100;input.step='any';
      field(cropNumbers,['X (%)','Y (%)','Largeur (%)','Hauteur (%)'][index],input);
      const showValue=()=>{input.value=Number((s.crop[key]*100).toFixed(2));};
      syncCallbacks.push(()=>{if(document.activeElement!==input)showValue();});
      input.addEventListener('blur',()=>{if(input.validity.valid)showValue();});
      input.addEventListener('input',()=>{
        if(!input.validity.valid||input.value==='')return;
        const v=input.valueAsNumber/100;
        if(key==='x')s.crop.x=clamp(v,0,1-s.crop.w);
        if(key==='y')s.crop.y=clamp(v,0,1-s.crop.h);
        if(key==='w')s.crop.w=clamp(v,.005,1-s.crop.x);
        if(key==='h')s.crop.h=clamp(v,.005,1-s.crop.y);
        preview();
      });
    });
    syncCallbacks.push(updateCropBox);groups.crop.append(button('Réinitialiser le recadrage',()=>{s.crop={x:0,y:0,w:1,h:1};updateCropBox();preview();}));
    const sizeRow=make('div',null,'board-number-row');groups.dimensions.append(sizeRow);numeric(sizeRow,'Largeur de la case (px)','cellSize',5,350,'any');
    const cellHeight=numeric(sizeRow,'Hauteur de la case (px)','cellSizeY',5,350,'any').closest('label');
    syncCallbacks.push(()=>{cellHeight.hidden=s.gridType!=='square';});
    const multiplier=make('input');multiplier.type='number';multiplier.min=5/70;multiplier.max=5;multiplier.step='any';field(sizeRow,'Taille de la case (× 70 px)',multiplier);multiplier.addEventListener('input',()=>{if(multiplier.value&&multiplier.validity.valid)set('cellSize',Number(multiplier.value)*70);});syncCallbacks.push(()=>{if(document.activeElement!==multiplier)multiplier.value=Number((s.cellSize/70).toFixed(5));});
    ['width','height'].forEach((key,index)=>{
      const row=make('div',null,'board-number-row');
      const cells=make('input');cells.type='number';cells.min=.01;cells.step='any';
      const label=index?'Hauteur':'Largeur';
      field(row,label+' (cases)',cells);
      numeric(row,label+' (px)',key,50,20000,'any');
      const equation=make('small');row.append(equation);
      const size=()=>index&&s.gridType==='square'?(s.cellSizeY??s.cellSize):s.cellSize;
      syncCallbacks.push(()=>{
        if(document.activeElement!==cells)cells.value=Number((s[key]/size()).toFixed(5));
        equation.textContent='× '+Number(size().toFixed(3))+' px/case = '+Number(s[key].toFixed(3))+' px';
      });
      cells.addEventListener('input',()=>{
        const value=Number(cells.value)*size();
        if(cells.value&&value>=50&&value<=20000)set(key,value);
      });
      groups.dimensions.append(row);
    });
    toggle(groups.grid,'Afficher la grille','gridVisible');
    select(groups.grid,'Type de grille','gridType',{'square':'Carré','hex-v':'Hexagonal vertical','hex-h':'Hexagonal horizontal','isometric':'Isométrique','dimetric':'Dimétrique'});
    color(groups.grid,'Couleur de la grille','gridColor',['#ffffff','#000000','#9d2874','#c86b2a','#a6291e','#d7a13b']);
    const opacity=make('div',null,'board-number-row');numeric(opacity,'Opacité des lignes (%)','gridOpacity',0,100);const slider=bind('gridOpacity',make('input'));slider.type='range';slider.min=0;slider.max=100;slider.value=s.gridOpacity;slider.setAttribute('aria-label','Opacité des lignes de la grille');slider.addEventListener('input',()=>set('gridOpacity',Number(slider.value)));opacity.append(slider);groups.grid.append(opacity);
    const offsets=make('div',null,'board-number-row');numeric(offsets,'Décalage axe X (px)','offsetX',-20000,20000,.1);numeric(offsets,'Décalage axe Y (px)','offsetY',-20000,20000,.1);groups.grid.append(offsets);toggle(groups.grid,'Aligner sur les coins et centres des cellules','snapToGrid');
    color(groups.appearance,'Couleur du plateau','boardColor',['#ffffff','#000000','#444444','#0e0e0e','#ddd4ba','#f6f3ec']);
    color(groups.appearance,'Couleur de la grille','gridColor',['#ffffff','#000000','#9d2874','#c86b2a','#a6291e','#d7a13b']);
    toggle(groups.appearance,'Masquer l’image qui dépasse de la page','cropOutside');
    select(groups.appearance,'Fond de la page','backgroundMode',{color:'Couleur',image:'Image'});
    toggle(groups.appearance,'Détecter la couleur dominante de l’image','autoDominant');
    color(groups.appearance,'Couleur du fond','backgroundColor',['#ffffff','#808080','#b3b3b3','#dddddd','#eee9da','#555555']);
    async function detectDominant(markChanged=true){if(!page.image)return;try{const image=new Image();image.src=page.image;await image.decode();if(dialog.isConnected&&s.autoDominant){s.dominantColor=dominant(image);if(markChanged)preview();else{sync();config.onPreview?.(page);}}}catch(_){status.textContent='La couleur dominante n’a pas pu être détectée.';}}
    select(groups.scale,'Mesure diagonale','diagonal',{euclidean:'Euclidienne',manhattan:'Manhattan (addition des axes)',chebyshev:'D&D 5 / Chebyshev (diagonale = 1)',pathfinder:'Pathfinder / 3.5 (diagonales 1 puis 2)'});
    numeric(groups.scale,'Distance pour une case','scale',.0001,1000000000,'any');
    select(groups.scale,'Unité','unit',{feet:'Pieds',meters:'Mètres',kilometers:'Kilomètres',miles:'Miles',inches:'Pouces',centimeters:'Centimètres',units:'Unités',hex:'Hex'});
    toggle(groups.scale,'Utiliser une distance continue, sans compter les cases','useDistanceScale');
    groups.scale.append(make('p','La règle du plateau utilise ces paramètres. Pour les grilles hexagonales, le comptage se fait en hexagones.','muted'));
    const footer=make('div',null,'board-settings-footer'),save=make('button','Enregistrer les paramètres','button');save.type='submit';footer.append(button('Annuler',()=>{if(!busy)dialog.close();}),save);form.append(status,footer);floating.body.append(form);
    form.addEventListener('submit',async event=>{event.preventDefault();if(busy||!form.reportValidity())return;busy=true;save.disabled=true;status.textContent='Enregistrement…';try{const payload={pageId:page.id,name:page.name,settings:s,settingsRevision:page.settingsRevision};if(imageChanged){payload.image=page.image;payload.thumbnail=page.thumbnail;}await config.onSave(payload);changed=false;dialog.close();}catch(error){status.textContent=error.message;}finally{busy=false;save.disabled=false;}});
    dialog.addEventListener('cancel',event=>{if(busy||(changed&&!window.confirm('Fermer sans enregistrer les paramètres ?')))event.preventDefault();});
    dialog.addEventListener('close',()=>{openWindows.delete(key);config.onClose?.();dialog.remove();});
    renderCrop();sync();floating.open();
    if(s.autoDominant&&page.image)detectDominant(false);
  }};
})();
