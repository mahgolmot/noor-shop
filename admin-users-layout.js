(()=>{
 const page=document.querySelector('[data-page-view="customers"]'),card=page?.querySelector('.users-card');if(!card)return;
 function fit(){const active=page.classList.contains('active');document.body.classList.toggle('admin-users-view',active);if(active)card.style.setProperty('--users-list-top',Math.ceil(card.getBoundingClientRect().top)+'px')}
 new MutationObserver(fit).observe(page,{attributes:true,attributeFilter:['class']});window.addEventListener('resize',fit);fit();
})();
