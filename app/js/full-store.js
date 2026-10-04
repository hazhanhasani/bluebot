(()=>{"use strict";
const API="/api/miniapp.php",rootId="bluebot-full-store";

const esc=s=>String(s??"").replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"}[m]));
const safeGet=(storageName,key)=>{try{const storage=window[storageName];return storage&&storage.getItem(key)||""}catch(_){return""}};
const token=()=>typeof window.__BLUEBOT_SESSION_TOKEN__==="string"?window.__BLUEBOT_SESSION_TOKEN__:safeGet("localStorage","token")||safeGet("sessionStorage","token")||"";
const telegramInitData=()=>window.Telegram&&window.Telegram.WebApp&&typeof window.Telegram.WebApp.initData==="string"?window.Telegram.WebApp.initData:"";
async function waitForAuth(timeout=5000){
 if(token()||telegramInitData())return true;
 return await new Promise(resolve=>{
  let done=false;
  const finish=()=>{if(done)return;done=true;clearTimeout(timer);window.removeEventListener("bluebot:session-ready",onReady);resolve(Boolean(token()||telegramInitData()))};
  const onReady=()=>finish();
  const timer=setTimeout(finish,timeout);
  window.addEventListener("bluebot:session-ready",onReady,{once:true});
 });
}
async function call(actions,method="GET",body={}){
 await waitForAuth();
 const sessionToken=token(),initData=telegramInitData();
 if(!sessionToken&&!initData)throw new Error("ورود تلگرام در دسترس نیست. مینی‌اپ را از داخل ربات باز کنید.");
 const headers={"Content-Type":"application/json"};
 if(sessionToken)headers.Authorization="Bearer "+sessionToken;
 if(initData)headers["X-Telegram-Init-Data"]=initData;
 const opt={method,headers,cache:"no-store",credentials:"same-origin"};
 let url=API;
 if(method==="GET"){const q=new URLSearchParams({actions,...body,_ts:String(Date.now())});url+="?"+q.toString()}else opt.body=JSON.stringify({actions,...body});
 const r=await fetch(url,opt);const raw=await r.text();let j=null;try{j=raw?JSON.parse(raw):null}catch(_){j=null}if(!j||typeof j!=="object"){throw new Error("پاسخ سرویس قابل پردازش نیست. لطفاً دوباره تلاش کنید.")}if(!r.ok||j.status===false)throw new Error(j.msg||"خطا");return j.obj??j;
}
const labels={telegram:"تلگرام",instagram:"اینستاگرام",premium:"تلگرام پرمیوم",stars:"استارز تلگرام",virtual_number:"شماره مجازی",other:"سایر خدمات"};
function css(){if(document.getElementById("bluebot-store-css"))return;const s=document.createElement("style");s.id="bluebot-store-css";s.textContent=`
#${rootId}{position:fixed;inset:0;z-index:45;display:none;overflow-y:auto;overscroll-behavior:contain;direction:rtl;box-sizing:border-box;padding:16px 16px 110px;background:var(--background,#111);color:var(--foreground,#fff);font-family:inherit}#${rootId}.bbs-open{display:block}.bbs-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:8px 0 18px}.bbs-title{font-size:22px;font-weight:900}.bbs-sub{font-size:12px;opacity:.65;margin-top:4px}.bbs-tabs{display:flex;gap:8px;overflow:auto;padding:2px 0 12px;scrollbar-width:none}.bbs-chip{white-space:nowrap;border:1px solid rgba(127,127,127,.25);background:rgba(127,127,127,.08);color:inherit;padding:9px 12px;border-radius:14px;font-weight:700}.bbs-chip.on{background:#1687ff;color:#fff;border-color:#1687ff}.bbs-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.bbs-card{border:1px solid rgba(127,127,127,.18);background:rgba(127,127,127,.07);border-radius:18px;padding:14px;min-height:132px;display:flex;flex-direction:column;justify-content:space-between}.bbs-name{font-weight:850;line-height:1.65;font-size:14px}.bbs-meta{font-size:11px;opacity:.62;margin-top:5px}.bbs-price{font-size:14px;font-weight:900;margin-top:10px}.bbs-btn{border:0;border-radius:12px;background:#1687ff;color:#fff;padding:9px 10px;font-weight:800;margin-top:10px}.bbs-empty{text-align:center;padding:48px 12px;opacity:.65}.bbs-modal{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);display:flex;align-items:flex-end}.bbs-sheet{width:100%;max-height:88vh;overflow:auto;background:#171717;color:#fff;border-radius:24px 24px 0 0;padding:20px 18px calc(20px + env(safe-area-inset-bottom))}.bbs-sheet h3{margin:0 0 8px}.bbs-input{width:100%;box-sizing:border-box;border:1px solid #444;background:#222;color:#fff;border-radius:13px;padding:13px;margin:7px 0}.bbs-row{display:flex;gap:9px}.bbs-row>*{flex:1}.bbs-muted{font-size:12px;opacity:.65;line-height:1.7}.bbs-order{padding:12px;border-bottom:1px solid rgba(127,127,127,.2)}.bbs-launcher{position:fixed;right:16px;bottom:104px;z-index:49;border:0;border-radius:999px;background:#1687ff;color:#fff;padding:11px 15px;font-weight:850;box-shadow:0 10px 28px rgba(0,0,0,.32)}.bbs-nav-button{position:relative;display:flex;height:100%;min-width:0;flex:1;flex-direction:column;align-items:center;justify-content:center;border:0;background:transparent;color:inherit;padding:6px 5px;font:inherit}.bbs-nav-button .bbs-nav-icon{font-size:18px;line-height:1}.bbs-nav-button .bbs-nav-label{margin-top:4px;font-size:11px;font-weight:700;white-space:nowrap}.bbs-nav-button.is-active{color:#1687ff}@media(min-width:640px){.bbs-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.bbs-sheet{max-width:520px;margin:auto;border-radius:24px 24px 0 0}}`;document.head.appendChild(s)}
let state={products:[],cat:"all",open:false,loaded:false,loading:false};
let navObserver=null;
function host(){let el=document.getElementById(rootId);if(!el){el=document.createElement("section");el.id=rootId;el.setAttribute("aria-label","فروش خدمات");document.body.appendChild(el)}return el}
function findMainNav(){
 const buttons=[...document.querySelectorAll("button")];
 const account=buttons.find(b=>/حساب/.test((b.textContent||"").trim()));
 if(!account||!account.parentElement)return null;
 const nav=account.parentElement,text=nav.textContent||"";
 return /خرید سرویس/.test(text)&&/سرویس/.test(text)?nav:null;
}
function syncNav(){const b=document.getElementById("bluebot-digital-nav");if(b)b.classList.toggle("is-active",state.open)}
function bindMainNav(nav){
 [...nav.querySelectorAll("button")].forEach(button=>{
  if(button.id==="bluebot-digital-nav"||button.dataset.bluebotStoreBound==="1")return;
  button.dataset.bluebotStoreBound="1";
  button.addEventListener("click",()=>{if(state.open)closeStore()},{passive:true});
 });
}
function ensureNavButton(){
 if(document.getElementById("bluebot-digital-nav")){const launcher=document.querySelector(".bbs-launcher");if(launcher)launcher.remove();return true}
 const nav=findMainNav();if(!nav)return false;
 bindMainNav(nav);
 const button=document.createElement("button");
 button.id="bluebot-digital-nav";button.type="button";button.className="bbs-nav-button";button.setAttribute("aria-label","فروش خدمات");
 button.innerHTML='<span class="bbs-nav-icon" aria-hidden="true">🛍</span><span class="bbs-nav-label">فروش خدمات</span>';
 button.onclick=openStore;nav.appendChild(button);
 const launcher=document.querySelector(".bbs-launcher");if(launcher)launcher.remove();
 syncNav();return true;
}
function ensureLauncher(){
 if(document.getElementById("bluebot-digital-nav")||document.querySelector(".bbs-launcher"))return;
 const button=document.createElement("button");button.type="button";button.className="bbs-launcher";button.textContent="🛍 فروش خدمات";button.onclick=openStore;document.body.appendChild(button);
}
function openStore(){state.open=true;host().classList.add("bbs-open");syncNav();if(state.loaded)render();else loadCatalog()}
function closeStore(){state.open=false;host().classList.remove("bbs-open");syncNav()}
async function loadCatalog(force=false){
 if(state.loading)return;
 if(state.loaded&&!force){render();return}
 state.loading=true;
 const el=host();el.classList.add("bbs-open");el.innerHTML='<div class="bbs-empty">در حال دریافت محصولات فروش خدمات…</div>';
 try{const r=await call("digital_catalog");state.products=Array.isArray(r.products)?r.products:[];state.loaded=true;render()}
 catch(e){el.innerHTML='<div class="bbs-empty">❌ '+esc(e.message)+'<br><button class="bbs-btn" data-retry>تلاش دوباره</button></div>';const retry=el.querySelector("[data-retry]");if(retry)retry.onclick=()=>loadCatalog(true)}
 finally{state.loading=false}
}
function render(){const el=host(),cats=["all",...new Set(state.products.map(x=>x.category))],products=state.cat==="all"?state.products:state.products.filter(x=>x.category===state.cat);
el.innerHTML=`<div class="bbs-head"><div><div class="bbs-title">💠 Blue Panel</div><div class="bbs-sub">مرکز خرید و مدیریت همه خدمات</div></div><button class="bbs-chip" data-orders>سفارش‌های من</button></div>
<div class="bbs-tabs"><button class="bbs-chip" data-vpn>🌐 سرویس اینترنت</button><button class="bbs-chip on">🛍 فروش خدمات</button></div>
<div class="bbs-tabs">${cats.map(x=>`<button class="bbs-chip ${x===state.cat?"on":""}" data-cat="${esc(x)}">${x==="all"?"همه":esc(labels[x]||x)}</button>`).join("")}</div>
<div class="bbs-grid">${products.map(p=>`<article class="bbs-card"><div><div class="bbs-name">${esc(p.name)}</div><div class="bbs-meta">${esc(labels[p.category]||p.category)} · ${esc(p.provider)}</div></div><div><div class="bbs-price">${Number(p.price||0).toLocaleString("fa-IR")} تومان</div><button class="bbs-btn" data-buy="${p.id}">سفارش</button></div></article>`).join("")||'<div class="bbs-empty">محصول فعالی در این دسته نیست.</div>'}</div>`;
el.querySelector("[data-vpn]").onclick=closeStore;el.querySelectorAll("[data-cat]").forEach(b=>b.onclick=()=>{state.cat=b.dataset.cat;render()});el.querySelectorAll("[data-buy]").forEach(b=>b.onclick=()=>openProduct(Number(b.dataset.buy)));el.querySelector("[data-orders]").onclick=openOrders;syncNav();
}
function modal(html){const d=document.createElement("div");d.className="bbs-modal";d.innerHTML='<div class="bbs-sheet">'+html+'</div>';d.onclick=e=>{if(e.target===d)d.remove()};document.body.appendChild(d);return d}
function openProduct(id){const p=state.products.find(x=>x.id===id);if(!p)return;const q=p.quantity||{},d=modal(`<h3>${esc(p.name)}</h3><div class="bbs-muted">${esc(p.target_prompt||"مقصد سفارش را وارد کنید.")}</div><input class="bbs-input" name="target" placeholder="مقصد / یوزرنیم / لینک">${q.variable?`<input class="bbs-input" name="quantity" type="number" min="${q.min}" max="${q.max}" value="${q.min}" placeholder="تعداد">`:""}<div class="bbs-row"><button class="bbs-btn" data-quote>بررسی قیمت</button><button class="bbs-chip" data-close>بستن</button></div><div data-result class="bbs-muted"></div>`);
d.querySelector("[data-close]").onclick=()=>d.remove();d.querySelector("[data-quote]").onclick=async()=>{const target=d.querySelector('[name=target]').value,quantity=q.variable?Number(d.querySelector('[name=quantity]').value):q.fixed;const out=d.querySelector("[data-result]");try{out.textContent="در حال بررسی…";const quote=await call("digital_quote","POST",{product_id:p.id,target,quantity});out.innerHTML=`<p>مبلغ نهایی: <b>${Number(quote.amount).toLocaleString("fa-IR")} تومان</b></p><button class="bbs-btn" data-confirm>تأیید و ثبت سفارش</button>`;out.querySelector("[data-confirm]").onclick=async()=>{try{out.textContent="در حال ثبت سفارش…";const res=await call("digital_purchase","POST",{product_id:p.id,target:quote.target,quantity:quote.quantity});out.innerHTML=`<p>✅ سفارش ثبت شد.</p><p>کد: <b>${esc(res.order?.order_code||"")}</b></p>`}catch(e){out.textContent="❌ "+e.message}}}catch(e){out.textContent="❌ "+e.message}}}
async function openOrders(){const d=modal("<h3>📋 سفارش‌های من</h3><div data-orders>در حال دریافت…</div>");try{const r=await call("digital_orders","GET",{page:1});d.querySelector("[data-orders]").innerHTML=(r.orders||[]).map(o=>`<div class="bbs-order"><b>${esc(o.service_name)}</b><br><span class="bbs-muted">${esc(o.order_code)} · ${esc(o.status)} · ${Number(o.amount||0).toLocaleString("fa-IR")} تومان</span></div>`).join("")||'<div class="bbs-empty">هنوز سفارشی ندارید.</div>'}catch(e){d.querySelector("[data-orders]").textContent="❌ "+e.message}}
function init(){css();host();if(!ensureNavButton())ensureLauncher();if(navObserver)navObserver.disconnect();navObserver=new MutationObserver(()=>{if(!ensureNavButton())ensureLauncher()});navObserver.observe(document.body,{childList:true,subtree:true});window.setTimeout(()=>{if(!ensureNavButton())ensureLauncher()},1200)}
function setSessionToken(value){if(typeof value==="string"){window.__BLUEBOT_SESSION_TOKEN__=value;window.dispatchEvent(new CustomEvent("bluebot:session-ready",{detail:{token:value}}))}}
window.BlueBotFullStore={init,setSessionToken,open:openStore,close:closeStore,refresh:()=>loadCatalog(true)};})();
