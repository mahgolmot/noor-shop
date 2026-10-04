(()=>{
 const profile=document.querySelector('.admin-profile'),toggle=profile?.querySelector('button');if(!toggle)return;
 toggle.setAttribute('aria-label','منوی حساب مدیر');toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-controls','admin-profile-menu');profile.insertAdjacentHTML('beforeend','<section id="admin-profile-menu" class="admin-profile-menu" hidden><button type="button" data-admin-logout>خروج از حساب</button></section>');const menu=profile.querySelector('.admin-profile-menu');
 function close(){menu.hidden=true;toggle.setAttribute('aria-expanded','false')}
 toggle.onclick=()=>{menu.hidden=!menu.hidden;toggle.setAttribute('aria-expanded',String(!menu.hidden));if(!menu.hidden)menu.querySelector('button').focus()};document.addEventListener('click',e=>{if(!profile.contains(e.target))close()});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!menu.hidden){close();toggle.focus()}});
 menu.querySelector('button').onclick=async function(){this.disabled=true;try{if(!csrf)csrf=(await api('/csrf')).csrf;await api('/auth/logout',{method:'POST',body:'{}'});location.replace('account.html')}catch(error){toast(error.message);this.disabled=false}};
})();
document.head.insertAdjacentHTML('beforeend','<link rel="stylesheet" href="admin-catalog.css?v=1">');
const productCatalogScript=document.createElement('script');productCatalogScript.src='admin-catalog.js?v=1';productCatalogScript.defer=true;document.body.appendChild(productCatalogScript);
