(function(){
  const safe=v=>String(v==null?'':v).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  function enhance(select){
    if(select.dataset.nouraSelect||select.multiple||select.size>1)return;
    select.dataset.nouraSelect='1';select.classList.add('noura-native-select');
    const ui=document.createElement('div');ui.className='noura-select';
    const trigger=document.createElement('button');trigger.type='button';trigger.className='noura-select-trigger';trigger.setAttribute('aria-haspopup','listbox');trigger.setAttribute('aria-expanded','false');
    const menu=document.createElement('div');menu.className='noura-select-menu';menu.setAttribute('role','listbox');
    const close=()=>{ui.classList.remove('open');trigger.setAttribute('aria-expanded','false')};
    const render=()=>{const current=select.options[select.selectedIndex]||select.options[0];if(!current)return;trigger.innerHTML=`<span>${safe(current.textContent)}</span><i></i>`;menu.innerHTML=[...select.options].map((o,i)=>`<button type="button" role="option" data-index="${i}" aria-selected="${o.selected}" ${o.disabled?'disabled':''}><span>${safe(o.textContent)}</span><b>✓</b></button>`).join('');menu.querySelectorAll('button').forEach(item=>item.onclick=e=>{e.stopPropagation();select.selectedIndex=+item.dataset.index;select.dispatchEvent(new Event('change',{bubbles:true}));render();close()})};
    trigger.onclick=e=>{e.stopPropagation();document.querySelectorAll('.noura-select.open').forEach(x=>x!==ui&&x.classList.remove('open'));const open=ui.classList.toggle('open');trigger.setAttribute('aria-expanded',String(open));if(open)menu.querySelector('[aria-selected=true]')&&menu.querySelector('[aria-selected=true]').focus()};
    trigger.onkeydown=e=>{if(e.key==='Escape')close();if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();ui.classList.add('open');const items=[...menu.querySelectorAll('button:not(:disabled)')],at=Math.max(0,items.findIndex(x=>x===document.activeElement)),next=e.key==='ArrowDown'?Math.min(items.length-1,at+1):Math.max(0,at-1);items[next]&&items[next].focus()}};
    menu.onkeydown=e=>{const items=[...menu.querySelectorAll('button:not(:disabled)')],at=items.indexOf(document.activeElement);if(e.key==='Escape'){close();trigger.focus()}if(e.key==='ArrowDown'){e.preventDefault();const next=items[Math.min(items.length-1,at+1)];if(next)next.focus()}if(e.key==='ArrowUp'){e.preventDefault();const previous=items[Math.max(0,at-1)];if(previous)previous.focus()}};
    select.insertAdjacentElement('afterend',ui);ui.append(trigger,menu);select.addEventListener('change',render);select._nouraRefresh=render;render();
  }
  const scan=root=>{if(root.nodeType!==1&&root!==document)return;root.matches&&root.matches('select')&&enhance(root);root.querySelectorAll&&root.querySelectorAll('select').forEach(enhance)};
  scan(document);new MutationObserver(changes=>changes.forEach(change=>{if(change.target.tagName==='SELECT'&&change.target._nouraRefresh)change.target._nouraRefresh();change.addedNodes.forEach(scan)})).observe(document.body,{childList:true,subtree:true});
  document.addEventListener('click',()=>document.querySelectorAll('.noura-select.open').forEach(x=>{x.classList.remove('open');x.querySelector('.noura-select-trigger')&&x.querySelector('.noura-select-trigger').setAttribute('aria-expanded','false')}));
})();
