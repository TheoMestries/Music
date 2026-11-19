(() => {
 let top = 1000; let cascade = 0;
 window.MapWindows = {
  enhance(dialog, title) {
   dialog.classList.add('floating-window');
   if (dialog.dataset.floating) return dialog._window;
   dialog.dataset.floating = 'true'; dialog.classList.add('floating-window');
   const doc=dialog.ownerDocument, win=doc.defaultView;
   const bar=doc.createElement('header'); bar.className='floating-titlebar';
   const label=doc.createElement('strong'); label.textContent=title; bar.append(label);
   const controls=doc.createElement('div'); controls.className='floating-controls'; bar.append(controls);
   const body=doc.createElement('div'); body.className='floating-body';
   while(dialog.firstChild) body.append(dialog.firstChild);
   dialog.append(bar,body);
   function button(text,description,action) {
    const b=doc.createElement('button'); b.type='button'; b.textContent=text; b.title=description; b.setAttribute('aria-label',description); b.addEventListener('click',action); controls.append(b); return b;
   }
   let saved=null;
   const min=button('−','Réduire / restaurer',()=>{
    dialog.classList.toggle('window-minimized'); min.setAttribute('aria-expanded',String(!dialog.classList.contains('window-minimized')));
   });
   button('□','Agrandir / restaurer',()=>{
    dialog.classList.remove('window-minimized');
    if(saved) { Object.assign(dialog.style,saved); saved=null; }
    else { saved={left:dialog.style.left,top:dialog.style.top,width:dialog.style.width,height:dialog.style.height}; Object.assign(dialog.style,{left:'8px',top:'8px',width:(win.innerWidth-16)+'px',height:(win.innerHeight-16)+'px'}); }
   });
   button('×','Fermer',()=>{
    const event=new win.Event('cancel',{cancelable:true});
    if(dialog.dispatchEvent(event)) dialog.close();
   });
   dialog.addEventListener('pointerdown',()=>dialog.style.zIndex=String(++top));
   bar.tabIndex=0; bar.setAttribute('aria-label','Déplacer la fenêtre avec les flèches');
   function move(x,y) { dialog.style.left=Math.max(0,Math.min(win.innerWidth-100,x))+'px'; dialog.style.top=Math.max(0,Math.min(win.innerHeight-48,y))+'px'; }
   bar.addEventListener('keydown',e=>{
    if(e.target!==bar || !['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;
    e.preventDefault(); const r=dialog.getBoundingClientRect(); move(r.left+(e.key==='ArrowRight'?20:e.key==='ArrowLeft'?-20:0),r.top+(e.key==='ArrowDown'?20:e.key==='ArrowUp'?-20:0));
   });
   bar.addEventListener('pointerdown',e=>{
    if(e.target.closest('button') || e.button!==0)return;
    e.preventDefault(); const r=dialog.getBoundingClientRect(), x=e.clientX,y=e.clientY;
    bar.setPointerCapture(e.pointerId);
    const drag=ev=>move(r.left+ev.clientX-x,r.top+ev.clientY-y);
    const end=()=>{bar.removeEventListener('pointermove',drag);bar.removeEventListener('pointerup',end);bar.removeEventListener('pointercancel',end);};
    bar.addEventListener('pointermove',drag);bar.addEventListener('pointerup',end);bar.addEventListener('pointercancel',end);
   });
   const api={body,label,open(){
    if(!dialog.open)dialog.show();
    dialog.classList.remove('window-minimized'); dialog.style.zIndex=String(++top);
    if(!dialog.style.left){const r=dialog.getBoundingClientRect();const offset=(cascade++ % 8)*24; move((win.innerWidth-r.width)/2+offset,Math.max(16,(win.innerHeight-r.height)/3)+offset);}
   }};
   dialog._window=api; return api;
  }
 };
})();
