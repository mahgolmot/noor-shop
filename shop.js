const $=s=>document.querySelector(s),fa=n=>new Intl.NumberFormat('fa-IR').format(n||0);
const products=window.DEMO_PRODUCTS||[], labels={power:'پاوربانک',charger:'شارژر',audio:'صوتی',cable:'کابل',accessories:'لوازم جانبی'};
let category=new URLSearchParams(location.search).get('category')||'all';
const grid=$('#shop-products');
function render(){const list=products.filter(p=>category==='all'||p.category===category);grid.innerHTML=list.map(p=>`<article class="product"><a class="product-link" href="product.html?id=${p.id}"><div class="product-media"><img class="product-photo" src="${p.image_path}" alt="${p.name}"><span class="badge">موجود</span></div><div class="product-info"><div><h3>${p.name}</h3><small>${labels[p.category]||'لوازم دیجیتال'}</small><p>${p.description}</p></div><strong>${fa(p.price)} <small>تومان</small></strong></div></a></article>`).join('')||'<p class="empty">محصولی در این دسته وجود ندارد.</p>'}
document.querySelectorAll('[data-cat]').forEach(b=>{b.classList.toggle('active',b.dataset.cat===category);b.onclick=()=>{document.querySelectorAll('[data-cat]').forEach(x=>x.classList.remove('active'));b.classList.add('active');category=b.dataset.cat;render()}});
render();
