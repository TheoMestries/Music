(() => {
  const defaults={width:1400,height:1000,cellSize:70,folder:'',boardColor:'#252d2b',backgroundColor:'#eee9da',dominantColor:'#eee9da',autoDominant:true,backgroundMode:'image',cropOutside:true,gridVisible:true,gridType:'square',gridColor:'#000000',gridOpacity:35,offsetX:0,offsetY:0,snapToGrid:true,scale:5,unit:'feet',diagonal:'euclidean',useDistanceScale:false,crop:{x:0,y:0,w:1,h:1}};
  const ns='http://www.w3.org/2000/svg';let sequence=0;
  const svg=(tag,attributes={})=>{const n=document.createElementNS(ns,tag);Object.entries(attributes).forEach(([key,value])=>n.setAttribute(key,String(value)));return n;};
  function drawingShape(drawing) {
    const {points, type, stroke, fill, width, fillOpacity = 100} = drawing;
    const [x1, y1] = points[0], [x2, y2] = points.at(-1);
    if (type === 'text') {
      const text = svg('text', {x:x1, y:y1, fill, stroke, 'stroke-width':drawing.size / 8,
        'paint-order':'stroke fill', 'stroke-linejoin':'round', 'dominant-baseline':'hanging',
        'font-family':drawing.font, 'font-size':drawing.size,
        'font-weight':drawing.bold ? 'bold' : 'normal', 'font-style':drawing.italic ? 'italic' : 'normal'});
      drawing.text.split(/\r\n?|\n/).forEach((line, index) => {
        const span = svg('tspan', {x:x1, dy:index ? '1.2em' : '0'});
        span.textContent = line || '\u00a0';
        text.append(span);
      });
      return text;
    }
    const left = Math.min(x1, x2), top = Math.min(y1, y2);
    const w = Math.abs(x2 - x1), h = Math.abs(y2 - y1);
    const attributes = {stroke, fill, 'fill-opacity': fillOpacity / 100, 'stroke-width': width, 'stroke-linecap': 'round', 'stroke-linejoin': 'round'};
    if (type === 'rectangle') return svg('rect', {...attributes, x:left, y:top, width:w, height:h});
    if (type === 'ellipse') return svg('ellipse', {...attributes, cx:left+w/2, cy:top+h/2, rx:w/2, ry:h/2});
    if (type === 'line') return svg('line', {...attributes, x1, y1, x2, y2});
    const shape = fill === 'transparent' ? 'polyline' : 'polygon';
    return svg(shape, {...attributes, points:points.map(point=>point.join(',')).join(' ')});
  }
  function markPrivateDrawing(drawing, shape) {
    const x = Math.min(...drawing.points.map(point=>point[0]));
    const y = Math.min(...drawing.points.map(point=>point[1]));
    const group = svg('g');
    const anchor = svg('g', {transform:'translate('+x+' '+y+')',class:'board-private-anchor'});
    const badge = svg('g', {class:'board-private-drawing'});
    const label = svg('text', {x:11,y:-4,'text-anchor':'middle'});
    label.textContent = 'MJ';
    badge.append(svg('rect',{x:0,y:-16,width:22,height:16,rx:4}),label);
    anchor.append(badge);
    group.append(shape,anchor);
    return group;
  }
  function renderPings(world, pings) {
    for (const ping of pings ?? []) {
      const marker = document.createElement('span');
      marker.className = 'board-ping';
      marker.setAttribute('aria-hidden','true');
      marker.style.left = ping.x+'px';
      marker.style.top = ping.y+'px';
      marker.style.setProperty('--ping-color',ping.color);
      for (let index=0;index<3;index++) {
        const ring = document.createElement('span');
        ring.style.animationDelay = (index*520-ping.age)+'ms';
        marker.append(ring);
      }
      world.append(marker);
      setTimeout(()=>marker.remove(),Math.max(0,3500-ping.age));
    }
  }
  function gridPattern(s){
    const size=s.cellSize,id='board-pattern-'+(++sequence),pattern=svg('pattern',{id,patternUnits:'userSpaceOnUse',width:size,height:size,patternTransform:'translate('+s.offsetX+' '+s.offsetY+')'});
    const path=svg('path',{fill:'none',stroke:s.gridColor,'stroke-width':1,opacity:s.gridOpacity/100});
    if(s.gridType==='square'){
      const height=s.cellSizeY ?? size;
      pattern.setAttribute('height',height);
      path.setAttribute('d','M '+size+' 0 H 0 V '+height);
    }
    else if(s.gridType==='hex-h'||s.gridType==='hex-v'){
      const r=size/2,h=Math.sqrt(3)*r;
      pattern.setAttribute('width',3*r);pattern.setAttribute('height',h);
      pattern.setAttribute('patternTransform','translate('+s.offsetX+' '+s.offsetY+')'+(s.gridType==='hex-v'?' rotate(90)':''));
      const hex=(cx,cy)=>Array.from({length:6},(_,i)=>{const a=i*Math.PI/3;return (i?'L':'M')+(cx+r*Math.cos(a))+' '+(cy+r*Math.sin(a));}).join(' ')+' Z';
      path.setAttribute('d',hex(r,h/2)+' '+hex(2.5*r,0)+' '+hex(2.5*r,h));
    }else{
      const h=size*(s.gridType==='isometric'?Math.tan(Math.PI/6):.5);
      pattern.setAttribute('width',2*size);pattern.setAttribute('height',2*h);
      path.setAttribute('d','M 0 '+h+' L '+size+' 0 L '+(2*size)+' '+h+' L '+size+' '+(2*h)+' Z');
    }
    pattern.append(path);return {id,pattern};
  }
  function hexCoordinates(s,p){
    let x=p.x-s.offsetX,y=p.y-s.offsetY;if(s.gridType==='hex-v')[x,y]=[y,-x];const radius=s.cellSize/2;x-=radius;y-=Math.sqrt(3)*radius/2;
    const q=2*x/(3*radius),r=(-x/3+Math.sqrt(3)*y/3)/radius;let rq=Math.round(q),rr=Math.round(r),rt=Math.round(-q-r);
    const dq=Math.abs(rq-q),dr=Math.abs(rr-r),dt=Math.abs(rt+q+r);if(dq>dr&&dq>dt)rq=-rr-rt;else if(dr>dt)rr=-rq-rt;return {q:rq,r:rr};
  }
  function snapPoint(s,p){
    if(!s.snapToGrid||s.useDistanceScale)return p;
    if(s.gridType.startsWith('hex-')){
      const a=hexCoordinates(s,p),r=s.cellSize/2;let x=1.5*r*a.q+r,y=Math.sqrt(3)*r*(a.r+a.q/2)+Math.sqrt(3)*r/2;
      if(s.gridType==='hex-v')[x,y]=[-y,x];return {x:x+s.offsetX,y:y+s.offsetY};
    }
    if(s.gridType==='isometric'||s.gridType==='dimetric'){
      const h=s.cellSize*(s.gridType==='isometric'?Math.tan(Math.PI/6):.5),x=p.x-s.offsetX,y=p.y-s.offsetY;
      const u=Math.round(x/s.cellSize+y/h)/2,v=Math.round(-x/s.cellSize+y/h)/2;
      return {x:s.cellSize*(u-v)+s.offsetX,y:h*(u+v)+s.offsetY};
    }
    const height=s.cellSizeY ?? s.cellSize;
    return {x:Math.round((p.x-s.offsetX)/s.cellSize*2)/2*s.cellSize+s.offsetX,y:Math.round((p.y-s.offsetY)/height*2)/2*height+s.offsetY};
  }
  function measure(s,a,b){
    const height=s.gridType==='square' ? (s.cellSizeY ?? s.cellSize) : s.cellSize;
    let dx=Math.abs(b.x-a.x)/s.cellSize,dy=Math.abs(b.y-a.y)/height;
    if(!s.useDistanceScale&&(s.gridType==='isometric'||s.gridType==='dimetric')){
      const h=s.cellSize*(s.gridType==='isometric'?Math.tan(Math.PI/6):.5),x=b.x-a.x,y=b.y-a.y;
      dx=Math.abs((x/s.cellSize+y/h)/2);dy=Math.abs((-x/s.cellSize+y/h)/2);
    }
    let distance;
    if(!s.useDistanceScale&&s.gridType.startsWith('hex-')){
      const p=hexCoordinates(s,a),q=hexCoordinates(s,b);distance=(Math.abs(p.q-q.q)+Math.abs(p.r-q.r)+Math.abs(p.q+p.r-q.q-q.r))/2;
    }else if(s.useDistanceScale||s.diagonal==='euclidean')distance=Math.hypot(dx,dy);
    else if(s.diagonal==='manhattan')distance=dx+dy;
    else if(s.diagonal==='pathfinder')distance=Math.max(dx,dy)+Math.floor(Math.min(dx,dy)/2);
    else distance=Math.max(dx,dy);
    return distance*s.scale;
  }
  window.MapBoardRenderer={defaults,measure,snapPoint,create(viewport, entityImageUrl){
    let current=null,world=null,observer=null;
    let activeLayer = 'players';
    let zoom = 1, panX = 0, panY = 0, cameraEnabled = true;
    const clamp = (value,min,max) => Math.max(min,Math.min(max,value));
    function setActiveLayer(name) {
      activeLayer = name;
      world?.querySelectorAll('.board-content-layer').forEach(layer => {
        layer.inert = layer.dataset.layer !== activeLayer;
      });
    }
    function layout(){
      if(!world||!current)return;
      const s=current.settings;
      const width = viewport.clientWidth, height = viewport.clientHeight;
      const fit = Math.max(.005,Math.min((width-36)/s.width,(height-36)/s.height));
      const scale = fit * (cameraEnabled ? zoom : 1);
      const limitX = Math.max(0,(width+s.width*scale)/2-Math.min(48,width/2));
      const limitY = Math.max(0,(height+s.height*scale)/2-Math.min(48,height/2));
      if (cameraEnabled && width && height) {
        panX = clamp(panX,-limitX,limitX);
        panY = clamp(panY,-limitY,limitY);
      }
      const x = cameraEnabled ? panX : 0, y = cameraEnabled ? panY : 0;
      world.style.transform='translate('+x+'px, '+y+'px) translate(-50%, -50%) scale('+scale+')';
      world.style.setProperty('--board-screen-unit',1/scale+'px');
      world.style.setProperty('--board-inverse-scale',String(1/scale));
    }
    function changeZoom(multiplier,clientX,clientY) {
      if (!world || !cameraEnabled) return;
      const next = clamp(zoom*multiplier,.5,8);
      if (next === zoom) return;
      const rect = viewport.getBoundingClientRect();
      const x = clientX === undefined ? 0 : clientX-rect.left-rect.width/2;
      const y = clientY === undefined ? 0 : clientY-rect.top-rect.height/2;
      const ratio = next/zoom;
      panX = x+(panX-x)*ratio;
      panY = y+(panY-y)*ratio;
      zoom = next;
      layout();
      viewport.dispatchEvent(new Event('board-zoom-change'));
    }
    function resetCamera() {
      zoom = 1; panX = 0; panY = 0;
      layout();
      viewport.dispatchEvent(new Event('board-zoom-change'));
    }
    function render(page, canManage = false, readOnly = false){
      const s={...defaults,...page.settings,crop:{...defaults.crop,...page.settings?.crop}};
      const previousImage = world?.querySelector('.board-background-image');
      if (current?.id !== page.id) resetCamera();
      current={...page,settings:s};viewport.replaceChildren();viewport.classList.remove('board-has-image','without-grid');viewport.style.backgroundColor=s.boardColor;
      world=document.createElement('div');world.className='board-world';Object.assign(world.style,{width:s.width+'px',height:s.height+'px',backgroundColor:s.autoDominant&&page.image?s.dominantColor:s.backgroundColor});
      const mapLayer = document.createElement('div');
      mapLayer.className = 'board-content-layer';
      mapLayer.dataset.layer = 'map';
      world.append(mapLayer);
      const clip=document.createElement('div');clip.className='board-page-clip';clip.style.overflow=s.cropOutside?'hidden':'visible';mapLayer.append(clip);
      if(page.image&&s.backgroundMode==='image'){
        const image = previousImage?.getAttribute('src') === page.image ? previousImage : document.createElement('img');
        if (image !== previousImage) image.src = page.image;
        image.alt=page.name;image.draggable=false;image.className='board-background-image';
        Object.assign(image.style,{left:(-s.crop.x/s.crop.w*100)+'%',top:(-s.crop.y/s.crop.h*100)+'%',width:(100/s.crop.w)+'%',height:(100/s.crop.h)+'%'});clip.append(image);
      }
      if(s.gridVisible){const layer=svg('svg',{width:s.width,height:s.height,viewBox:'0 0 '+s.width+' '+s.height,'aria-hidden':'true'});layer.classList.add('board-grid-layer');const {id,pattern}=gridPattern(s),defs=svg('defs');defs.append(pattern);layer.append(defs,svg('rect',{width:s.width,height:s.height,fill:'url(#'+id+')'}));mapLayer.append(layer);}
      for (const name of canManage ? ['players', 'gm'] : ['players']) {
        const layer = document.createElement('div');
        layer.className = 'board-content-layer';
        layer.dataset.layer = name;
        world.append(layer);
      }
      for (const layer of world.querySelectorAll('.board-content-layer')) {
        const drawings = svg('svg', {viewBox:'0 0 '+s.width+' '+s.height, 'aria-hidden':'true'});
        drawings.classList.add('board-drawings');
        for (const drawing of page.drawings?.[layer.dataset.layer] ?? []) {
          const shape = drawingShape(drawing);
          const item = layer.dataset.layer === 'gm' ? markPrivateDrawing(drawing,shape) : shape;
          if (drawing.canEdit && !readOnly) {
            item.dataset.selectKind = 'drawing';
            item.dataset.selectId = drawing.id;
            if (drawing.fill === 'transparent') item.classList.add('board-stroke-hit');
          }
          drawings.append(item);
        }
        layer.append(drawings);
      }
      for (const entity of page.entities ?? []) {
        const layer = world.querySelector('.board-content-layer[data-layer="'+entity.layer+'"]');
        if (!layer) continue;
        const item = document.createElement('div');
        item.className = entity.character ? 'board-entity board-entity-character' : 'board-entity';
        item.classList.toggle('board-entity-active-turn',entity.id === page.turns?.activeEntityId);
        item.dataset.entityId = entity.id;
        if (entity.canResize && !readOnly) {
          item.dataset.selectKind = 'entity';
          item.dataset.selectId = entity.id;
        }
        item.title = entity.name;
        item.style.left = entity.x+'px';
        item.style.top = entity.y+'px';
        setEntitySize(item, entity.cells, s);
        const visual = document.createElement('span');
        visual.className = 'board-entity-visual';
        visual.textContent = entity.name;
        if (entity.hasImage && entityImageUrl) {
          const image = document.createElement('img');
          image.alt = entity.name;
          image.draggable = false;
          image.addEventListener('load', () => visual.classList.add('board-entity-has-image'), {once:true});
          image.addEventListener('error', () => image.remove(), {once:true});
          image.src = entityImageUrl(entity.id);
          visual.append(image);
        }
        item.append(visual);
        if (entity.layer === 'gm') {
          const badge = document.createElement('span');
          badge.className = 'board-private-entity';
          badge.textContent = 'MJ';
          badge.title = 'Visible uniquement par le MJ';
          item.append(badge);
        }
        if (entity.token) window.MapBoardTokens?.decorate(item,visual,entity);
        if (entity.canResize && !readOnly) {
          const handle = document.createElement('button');
          handle.type = 'button';
          handle.className = 'board-entity-resize';
          handle.textContent = '↘';
          handle.setAttribute('aria-label','Redimensionner '+entity.name);
          handle.title = 'Glisser ou utiliser les flèches pour redimensionner';
          item.append(handle);
        }
        layer.append(item);
      }
      setActiveLayer(activeLayer);
      const overlay = svg('svg',{width:s.width,height:s.height,viewBox:'0 0 '+s.width+' '+s.height,'aria-hidden':'true'});
      overlay.classList.add('board-measure-layer');
      world.append(overlay);
      viewport.append(world);
      layout();
    }
    observer=new ResizeObserver(layout);observer.observe(viewport);
    function previewDrawing(drawing, layerName) {
      world?.querySelector('.board-drawing-preview')?.remove();
      if (!drawing || !world) return;
      const layer = [...world.querySelectorAll('.board-content-layer')].find(node => node.dataset.layer === layerName);
      if (!layer) return;
      const preview = drawingShape(drawing);
      preview.classList.add('board-drawing-preview');
      layer.querySelector('.board-drawings').append(preview);
    }
    function setEntitySize(item, cells, settings = current.settings) {
      item.style.width = cells * settings.cellSize+'px';
      const height = settings.gridType === 'square' ? (settings.cellSizeY ?? settings.cellSize) : settings.cellSize;
      item.style.height = cells * height+'px';
    }
    return {render,setActiveLayer,previewDrawing,setEntitySize,changeZoom,resetCamera,
      refreshPings(page) { if (world) renderPings(world,page.pings); },
      getZoom:()=>zoom,
      pan(dx,dy) { if (world && cameraEnabled) { panX+=dx; panY+=dy; layout(); } },
      setCameraEnabled(enabled) { cameraEnabled = enabled; layout(); },
      destroy(){observer.disconnect();}};
  }};
})();
