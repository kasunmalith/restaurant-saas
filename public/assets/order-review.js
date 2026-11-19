(() => {
 const online=APP.order_mode==='ONLINE';
 const storage=online?'ee-review-online-'+APP.restaurant_id+'-'+APP.slug:'ee-review-'+APP.table_id+'-'+APP.dining_session.id;
 let browserStorageAvailable=true,storageWarningShown=false;
 try{const probe='ee-storage-probe-'+Date.now();localStorage.setItem(probe,'1');localStorage.removeItem(probe);}catch{browserStorageAvailable=false;}
 function showStorageWarning(){
  if(storageWarningShown)return;storageWarningShown=true;
  const warning=document.createElement('p');warning.className='customer-storage-warning';warning.setAttribute('role','status');warning.textContent=online?'This browser blocks local storage. Guest order tracking will work only until this page closes. Sign in to keep your order history.':'This browser blocks local storage. Order tracking will work only until this page closes.';
  document.querySelector('.hero')?.after(warning);
 }
 function storageGet(key){if(!browserStorageAvailable)return null;try{return localStorage.getItem(key);}catch{browserStorageAvailable=false;showStorageWarning();return null;}}
 function storageSet(key,value){if(!browserStorageAvailable){showStorageWarning();return false;}try{localStorage.setItem(key,value);return true;}catch{browserStorageAvailable=false;showStorageWarning();return false;}}
 if(!browserStorageAvailable)queueMicrotask(showStorageWarning);
 let state;try{state=JSON.parse(storageGet(storage));}catch{}
 state=state||{items:[],note:'',key:crypto.randomUUID()};
 const dialog=document.createElement('dialog');dialog.className='customer-order-dialog';dialog.style.cssText='border:0;border-radius:18px;padding:28px;width:min(90vw,600px)';document.body.append(dialog);
 let pageScrollY=0,bodyScrollStyles=null;
 function lockPageScroll(){
  if(bodyScrollStyles)return;
  pageScrollY=window.scrollY||window.pageYOffset||0;
  bodyScrollStyles={position:document.body.style.position,top:document.body.style.top,left:document.body.style.left,right:document.body.style.right,width:document.body.style.width,overflow:document.body.style.overflow};
  document.body.style.position='fixed';document.body.style.top=`-${pageScrollY}px`;document.body.style.left='0';document.body.style.right='0';document.body.style.width='100%';document.body.style.overflow='hidden';
 }
 function unlockPageScroll(){
  if(!bodyScrollStyles)return;
  for(const [property,value] of Object.entries(bodyScrollStyles))document.body.style[property]=value;
  bodyScrollStyles=null;window.scrollTo(0,pageScrollY);
 }
 const el=(tag,text)=>{const n=document.createElement(tag);n.textContent=text;return n;};
 const accountToggle=online?document.querySelector('#online-account-toggle'):null,accountDialog=online?document.querySelector('#online-account-dialog'):null,accountPanel=online?document.querySelector('#online-account-panel'):null,accountHistory=online?document.querySelector('#online-account-history'):null;
 const accountLoginTab=online?document.querySelector('#online-account-tab-login'):null,accountRegisterTab=online?document.querySelector('#online-account-tab-register'):null;
 let returnToCheckout=false;
 const accountBackButton=online?el('button','Back to Order'):null;
 if(accountBackButton){accountBackButton.type='button';accountBackButton.className='customer-account-back-to-order';accountBackButton.hidden=true;document.querySelector('#online-account-summary')?.after(accountBackButton);accountBackButton.addEventListener('click',()=>{if(accountDialog?.open){returnToCheckout=true;accountDialog.close();}});}
 function restoreAccountPanel(){if(accountPanel)accountPanel.hidden=false;}
 dialog.addEventListener('close',()=>{restoreAccountPanel();unlockPageScroll();});
 let signedInCustomer=null;
 const accountApi=APP.base+'/api/online-customer?slug='+encodeURIComponent(APP.slug);
 async function loadOrderHistory(){
  if(!accountHistory)return;accountHistory.replaceChildren();
  if(signedInCustomer){
   const addressSection=document.createElement('section');addressSection.className='customer-saved-addresses';addressSection.append(el('h3','Your saved addresses'));
   const addresses=Array.isArray(signedInCustomer.addresses)?signedInCustomer.addresses:[];
   if(!addresses.length)addressSection.append(el('p','No saved addresses yet.'));
   for(const address of addresses){const card=document.createElement('article');card.className='customer-saved-address';const header=document.createElement('div');header.className='customer-saved-address-heading';header.append(el('strong',address.label||'Saved address'));if(Number(address.is_default)===1){const badge=el('span','Preferred');badge.className='customer-saved-address-badge';header.append(badge);}card.append(header,el('p',address.address));if(Number(address.is_default)!==1){const choose=el('button','Make preferred');choose.type='button';choose.className='customer-saved-address-action';choose.addEventListener('click',async()=>{choose.disabled=true;try{const response=await fetch(accountApi,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify({action:'set-default-address',address_id:Number(address.id)})});const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to update your preferred address.');setAccount(result.customer,true);}catch(error){const message=el('p',error.message);message.className='customer-saved-address-error';card.append(message);choose.disabled=false;}});card.append(choose);}addressSection.append(card);}
   const form=document.createElement('form');form.className='customer-saved-address-form';form.append(el('h4','Add another address'));
   const label=document.createElement('label');label.append(document.createTextNode('Address label (optional)'));const labelInput=document.createElement('input');labelInput.name='label';labelInput.maxLength=80;labelInput.placeholder='Home, Work, etc.';label.append(labelInput);
   const addressLabel=document.createElement('label');addressLabel.append(document.createTextNode('Address'));const addressInput=document.createElement('textarea');addressInput.name='address';addressInput.maxLength=500;addressInput.required=true;addressInput.placeholder='Enter your delivery address';addressLabel.append(addressInput);
   const preferredLabel=document.createElement('label');preferredLabel.className='customer-saved-address-preferred';const preferred=document.createElement('input');preferred.type='checkbox';preferred.name='make_default';preferred.checked=!addresses.some(item=>Number(item.is_default)===1);preferredLabel.append(preferred,document.createTextNode(' Make this my preferred address'));
   const saveAddress=el('button','Save address');saveAddress.type='submit';const addressStatus=el('p','');addressStatus.className='customer-saved-address-error';addressStatus.setAttribute('role','status');form.append(label,addressLabel,preferredLabel,saveAddress,addressStatus);
   form.addEventListener('submit',async event=>{event.preventDefault();if(!addressInput.value.trim()){addressInput.focus();return;}saveAddress.disabled=true;addressStatus.textContent='';try{const response=await fetch(accountApi,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify({action:'add-address',label:labelInput.value,address:addressInput.value,make_default:preferred.checked})});const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to save the address.');setAccount(result.customer,preferred.checked);}catch(error){addressStatus.textContent=error.message;saveAddress.disabled=false;}});
   addressSection.append(form);accountHistory.append(addressSection);
  }
  accountHistory.append(el('h3','Your orders'));
  try{const response=await fetch(APP.base+'/api/online-customer-history?slug='+encodeURIComponent(APP.slug));const result=await response.json();if(!response.ok)throw Error(result.error||'Unable to load order history.');if(!result.orders.length){accountHistory.append(el('p','No online orders yet.'));return;}
   for(const order of result.orders){const card=document.createElement('article');card.className='online-history-order';const heading=document.createElement('div');heading.append(el('strong',order.order_number),el('span',orderStatusLabel(order,order.status)));card.append(heading,el('p',(order.order_type==='DELIVERY'?'Delivery':'Pickup')+' · '+new Date(order.created_at.replace(' ','T')+'Z').toLocaleString()));if(order.order_type==='DELIVERY'&&order.delivery_address)card.append(el('p','Deliver to: '+order.delivery_address));for(const item of order.items||[])card.append(el('small',item.quantity+' × '+item.item_name+(item.portion?' ('+item.portion+')':'')+' · LKR '+Number(item.subtotal).toFixed(2)));card.append(el('b','LKR '+Number(order.total).toFixed(2)));accountHistory.append(card);}
  }catch(error){accountHistory.append(el('p',error.message));}
 }
 function setAccount(customer,preferDefault=false){
  signedInCustomer=customer||null;const summary=document.querySelector('#online-account-summary');
  if(summary)summary.textContent=signedInCustomer?'Signed in as '+signedInCustomer.name+' · '+signedInCustomer.phone+'. View your saved addresses and order history below.':'Sign in or create an account to save your orders and enjoy a faster checkout.';
  if(accountToggle){accountToggle.classList.toggle('is-signed-in',Boolean(signedInCustomer));accountToggle.setAttribute('aria-label',signedInCustomer?'Open account and order history':'Sign in or register');accountToggle.title=signedInCustomer?'Account & Order History':'Sign in / Register';}
  const modalAccountToggle=dialog.querySelector('.customer-order-account-toggle');if(modalAccountToggle)modalAccountToggle.textContent=signedInCustomer?'Account & Order History':'Sign in / Register';
  accountDialog?.classList.toggle('is-signed-in',Boolean(signedInCustomer));
  const tabs=accountPanel?.querySelector('.customer-account-tabs');if(tabs)tabs.hidden=Boolean(signedInCustomer);
  const forms=accountPanel?.querySelector('.online-account-forms');if(forms)forms.hidden=Boolean(signedInCustomer);
  let logout=document.querySelector('#online-customer-logout');
  if(signedInCustomer&&!logout){logout=el('button','Sign out');logout.type='button';logout.id='online-customer-logout';logout.addEventListener('click',async()=>{try{const response=await fetch(accountApi,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify({action:'logout'})});if(!response.ok)throw Error('Unable to sign out.');setAccount(null);}catch(error){if(accountHistory)accountHistory.append(el('p',error.message));}});accountPanel?.prepend(logout);}
  if(!signedInCustomer)logout?.remove();
  if(signedInCustomer){state.customer_name=signedInCustomer.name;state.customer_phone=signedInCustomer.phone;state.account_address=signedInCustomer.address||'';const addresses=Array.isArray(signedInCustomer.addresses)?signedInCustomer.addresses:[];const selected=(preferDefault?addresses.find(item=>Number(item.is_default)===1):addresses.find(item=>Number(item.id)===Number(state.delivery_address_id)))||addresses.find(item=>Number(item.is_default)===1)||addresses[0];if(selected){state.delivery_address_id=Number(selected.id);state.delivery_address=selected.address;}else if(!state.delivery_address)state.delivery_address=signedInCustomer.address||'';state.key=crypto.randomUUID();save();loadOrderHistory();}
  else if(accountHistory)accountHistory.replaceChildren();
 }
 function showAccountTab(tab){
  const login=document.querySelector('#online-login-form'),register=document.querySelector('#online-register-form'),forgot=document.querySelector('#online-forgot-form'),reset=document.querySelector('#online-reset-form'),tabs=accountPanel?.querySelector('.customer-account-tabs'),showRegister=tab==='register';
  if(login)login.hidden=showRegister;if(register)register.hidden=!showRegister;
  if(forgot)forgot.hidden=true;if(reset)reset.hidden=true;if(tabs)tabs.hidden=Boolean(signedInCustomer);
  accountLoginTab?.classList.toggle('is-active',!showRegister);accountRegisterTab?.classList.toggle('is-active',showRegister);
  accountLoginTab?.setAttribute('aria-selected',String(!showRegister));accountRegisterTab?.setAttribute('aria-selected',String(showRegister));
 }
 function showAccountDialog(){
  restoreAccountPanel();showAccountTab('login');lockPageScroll();
  try{if(!accountDialog.open)accountDialog.showModal();if(signedInCustomer)loadOrderHistory();}
  catch(error){unlockPageScroll();throw error;}
 }
 function openAccountAfterCheckout(){
  if(dialog.open){returnToCheckout=true;if(accountBackButton)accountBackButton.hidden=false;dialog.addEventListener('close',showAccountDialog,{once:true});dialog.close();return;}
  returnToCheckout=false;if(accountBackButton)accountBackButton.hidden=true;
  showAccountDialog();
 }
 if(online){
  accountPanel?.addEventListener('click',event=>{const tab=event.target.closest('[data-account-tab]');if(!tab||!accountPanel.contains(tab))return;event.preventDefault();showAccountTab(tab.dataset.accountTab);});
  accountToggle?.addEventListener('click',openAccountAfterCheckout);
  accountDialog?.querySelector('.customer-account-close')?.addEventListener('click',()=>{returnToCheckout=false;if(accountBackButton)accountBackButton.hidden=true;accountDialog.close();});
  accountDialog?.addEventListener('close',()=>{unlockPageScroll();if(!returnToCheckout)return;returnToCheckout=false;if(accountBackButton)accountBackButton.hidden=true;review();lockPageScroll();try{dialog.showModal();}catch(error){unlockPageScroll();throw error;}});
  for(const [formId,action] of [['online-login-form','login'],['online-register-form','register']]){const form=document.getElementById(formId);form?.addEventListener('submit',async event=>{event.preventDefault();const button=form.querySelector('button[type="submit"]'),status=form.querySelector('[role="status"]');button.disabled=true;status.textContent='';const fields=Object.fromEntries(new FormData(form));try{const response=await fetch(accountApi,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify({action,...fields})});const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to sign in.');setAccount(result.customer,true);form.reset();status.textContent='Signed in successfully.';}catch(error){status.textContent=error.message;}finally{button.disabled=false;}});}
  const loginForm=document.querySelector('#online-login-form'),forgotForm=document.querySelector('#online-forgot-form'),resetForm=document.querySelector('#online-reset-form'),accountTabs=accountPanel?.querySelector('.customer-account-tabs');
  document.querySelector('#online-account-forgot')?.addEventListener('click',()=>{if(loginForm&&forgotForm){forgotForm.elements.phone.value=loginForm.elements.phone.value;loginForm.hidden=true;document.querySelector('#online-register-form').hidden=true;forgotForm.hidden=false;resetForm.hidden=true;if(accountTabs)accountTabs.hidden=true;forgotForm.querySelector('[role="status"]').textContent='';}});
  accountPanel?.querySelectorAll('[data-account-back-login]').forEach(button=>button.addEventListener('click',()=>showAccountTab('login')));
  forgotForm?.addEventListener('submit',async event=>{event.preventDefault();const button=forgotForm.querySelector('[type="submit"]'),status=forgotForm.querySelector('[role="status"]');button.disabled=true;status.textContent='';const fields=Object.fromEntries(new FormData(forgotForm));try{const response=await fetch(accountApi,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify({action:'forgot-password',...fields})});const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to request a password reset.');if(!result.available){status.textContent=result.message||'Password recovery email is available on the live production site only.';return;}resetForm.elements.phone.value=fields.phone;forgotForm.hidden=true;resetForm.hidden=false;resetForm.querySelector('[role="status"]').textContent=result.message||'If the phone number and email match an account, a temporary password will be sent.';}catch(error){status.textContent=error.message;}finally{button.disabled=false;}});
  resetForm?.addEventListener('submit',async event=>{event.preventDefault();const button=resetForm.querySelector('[type="submit"]'),status=resetForm.querySelector('[role="status"]');button.disabled=true;status.textContent='';const fields=Object.fromEntries(new FormData(resetForm));try{const response=await fetch(accountApi,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify({action:'reset-password',...fields})});const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to update password.');const phone=fields.phone;resetForm.reset();showAccountTab('login');loginForm.elements.phone.value=phone;loginForm.querySelector('[role="status"]').textContent=result.message||'Password updated. You can now sign in.';}catch(error){status.textContent=error.message;}finally{button.disabled=false;}});
  const refreshAccount=async()=>{try{const response=await fetch(accountApi);const result=await response.json();if(result.logged_in)setAccount(result.customer,!dialog.open);return result;}catch{return null;}};
  refreshAccount();
  window.refreshOnlineCustomerAccount=refreshAccount;
 }
 const total=()=>state.items.reduce((n,i)=>n+Math.round(i.price*100)*i.qty,0)/100;
 const servicePercent=()=>online?0:Math.max(0,Math.min(100,Number(APP.service_charge_percent)||0));
 const serviceCharge=()=>Math.round(Math.round(total()*100)*Math.round(servicePercent()*100)/10000)/100;
 const grandTotal=()=>total()+serviceCharge();
 const trackingKey=online?'ee-tracking-online-'+APP.restaurant_id+'-'+APP.slug:'ee-tracking-'+APP.restaurant_id+'-'+APP.table_id+'-'+APP.dining_session.id;
 let tracked=[];try{const value=JSON.parse(storageGet(trackingKey)||'[]');if(Array.isArray(value))tracked=value.filter(o=>o&&o.tracking_token&&Date.now()-o.savedAt<86400000).slice(-20);}catch{}
 const tracking=document.createElement('section');tracking.className='customer-order-tracking shell';tracking.setAttribute('aria-live','polite');document.querySelector('.hero').after(tracking);
 const trackingDetailDialog=document.createElement('dialog');trackingDetailDialog.className='customer-tracking-detail-dialog';document.body.append(trackingDetailDialog);
 trackingDetailDialog.addEventListener('close',unlockPageScroll);
 const paymentLabel=value=>({CASH_ON_DELIVERY:'Cash on Delivery',PAY_AT_PICKUP:'Pay at Pickup',ONLINE_PAYMENT:'Online Payment'}[value]||'Not selected');
 const orderStatusLabel=(order,status)=>status==='SERVED'?(order.order_type==='DELIVERY'?'SENT':order.order_type==='TAKEAWAY'?'PICKED UP':'SERVED'):String(status||'PENDING').replaceAll('_',' ');
 async function openTrackingDetails(order){
  trackingDetailDialog.replaceChildren(el('p','Loading order details...'));lockPageScroll();try{if(!trackingDetailDialog.open)trackingDetailDialog.showModal();const response=await fetch(APP.base+'/api/status?id='+order.id+'&token='+encodeURIComponent(order.tracking_token)+'&details=1');const result=await response.json();if(!response.ok)throw Error(result.error||'Unable to load order details.');
   const close=el('button','×');close.type='button';close.className='customer-tracking-detail-close';close.setAttribute('aria-label','Close order details');close.onclick=()=>trackingDetailDialog.close();
   const heading=el('h2',result.order_number);const status=el('span',orderStatusLabel(result,result.status));status.className='customer-tracking-status status-'+String(result.status||'PENDING').toLowerCase();
   const top=document.createElement('div');top.className='customer-tracking-detail-head';top.append(heading,status);
   const meta=el('p',(result.order_type==='DELIVERY'?'Delivery':'Pickup')+' · '+paymentLabel(result.payment_method));meta.className='customer-tracking-detail-meta';
   const items=document.createElement('div');items.className='customer-tracking-detail-items';for(const item of result.items||[]){const row=document.createElement('div');const copy=document.createElement('span');copy.append(el('strong',item.item_name+(item.portion?' ('+item.portion+')':'')),el('small',item.quantity+' × LKR '+Number(item.unit_price).toFixed(2)));row.append(copy,el('b','LKR '+Number(item.subtotal).toFixed(2)));items.append(row);}
   const totalRow=document.createElement('div');totalRow.className='customer-tracking-detail-total';totalRow.append(el('strong','Total'),el('strong','LKR '+Number(result.total).toFixed(2)));
   trackingDetailDialog.replaceChildren(close,top,meta,items,totalRow);
   if(result.delivery_address)trackingDetailDialog.append(el('p','Deliver to: '+result.delivery_address));
   if(result.delivery_notes)trackingDetailDialog.append(el('p','Delivery notes: '+result.delivery_notes));
   if(result.special_request)trackingDetailDialog.append(el('p','Special request: '+result.special_request));
  }catch(error){trackingDetailDialog.replaceChildren(el('h2','Order details'),el('p',error.message));const close=el('button','Close');close.type='button';close.onclick=()=>trackingDetailDialog.close();trackingDetailDialog.append(close);}
 }
 let trackingExpanded=false,rejectedNoticeSeen=false;
 function showTracking(){
  tracking.replaceChildren();
  if(!tracked.length){tracking.hidden=true;return;}
  tracking.hidden=false;
  const rejectedCount=tracked.filter(order=>order.status==='REJECTED').length;
  const panel=document.createElement('details');panel.className='customer-tracking-panel'+(rejectedCount?' has-rejected':'');panel.open=trackingExpanded||(rejectedCount>0&&!rejectedNoticeSeen);
  panel.addEventListener('toggle',()=>{trackingExpanded=panel.open;if(rejectedCount&&!panel.open)rejectedNoticeSeen=true;});
  const head=document.createElement('summary');head.className='customer-tracking-head';
  const copy=document.createElement('div');copy.append(el('span',rejectedCount?'ORDER UPDATE':'LIVE ORDER TRACKING'),el('h2',rejectedCount?'An order was rejected':'Follow your order'));
  const meta=document.createElement('div');meta.className='customer-tracking-meta';const arrow=el('i','');arrow.setAttribute('aria-hidden','true');meta.append(el('span',rejectedCount?rejectedCount+' rejected':tracked.length+' order'+(tracked.length===1?'':'s')),arrow);
  head.append(copy,meta);panel.append(head);
  const list=document.createElement('div');list.className='customer-tracking-list';
  [...tracked].reverse().forEach(o=>{
   const rejected=o.status==='REJECTED';
   const card=document.createElement('button');card.type='button';card.className='customer-tracking-card'+(rejected?' is-rejected':'');card.dataset.orderId=String(o.id);card.setAttribute('aria-label','View details for order '+o.order_number);card.addEventListener('click',()=>openTrackingDetails(o));
   const icon=el('span',rejected?'×':'✓');icon.className='customer-tracking-icon';
   const details=document.createElement('div');details.append(el('small','ORDER NUMBER'),el('strong',o.order_number));
   if(rejected){const message=el('p','This order was rejected by the restaurant. Please contact a staff member if you need assistance.');message.className='customer-tracking-message';details.append(message);}
   const status=el('span',orderStatusLabel(o,o.status));status.className='customer-tracking-status status-'+String(o.status||'PENDING').toLowerCase();
   card.append(icon,details,status);list.append(card);
  });
  panel.append(list);tracking.append(panel);
 }
 function syncTracking(){
  if(!tracked.length){showTracking();return;}
  const panel=tracking.querySelector('.customer-tracking-panel');if(!panel){showTracking();return;}
  const rejectedCount=tracked.filter(o=>o.status==='REJECTED').length;
  panel.classList.toggle('has-rejected',rejectedCount>0);
  panel.querySelector('.customer-tracking-head>div:first-child>span').textContent=rejectedCount?'ORDER UPDATE':'LIVE ORDER TRACKING';
  panel.querySelector('.customer-tracking-head h2').textContent=rejectedCount?'An order was rejected':'Follow your order';
  panel.querySelector('.customer-tracking-meta>span').textContent=rejectedCount?rejectedCount+' rejected':tracked.length+' order'+(tracked.length===1?'':'s');
  if(rejectedCount&&!rejectedNoticeSeen){trackingExpanded=true;panel.open=true;}
  const cards=new Map([...panel.querySelectorAll('.customer-tracking-card')].map(card=>[card.dataset.orderId,card]));
  const activeIds=new Set(tracked.map(o=>String(o.id)));
  cards.forEach((card,id)=>{if(!activeIds.has(id))card.remove();});
  tracked.forEach(o=>{const card=cards.get(String(o.id));if(!card)return;const rejected=o.status==='REJECTED';card.classList.toggle('is-rejected',rejected);const icon=card.querySelector('.customer-tracking-icon');icon.textContent=rejected?'×':'✓';const details=card.querySelector('.customer-tracking-card small').parentElement;let message=details.querySelector('.customer-tracking-message');if(rejected&&!message){message=el('p','This order was rejected by the restaurant. Please contact a staff member if you need assistance.');message.className='customer-tracking-message';details.append(message);}else if(!rejected&&message)message.remove();const status=card.querySelector('.customer-tracking-status');status.textContent=orderStatusLabel(o,o.status);status.className='customer-tracking-status status-'+String(o.status||'PENDING').toLowerCase();});
 }
 function rememberOrder(order){order={...order,order_type:order.order_type||state.order_type||(online?'TAKEAWAY':'DINE_IN')};tracked=tracked.filter(o=>String(o.id)!==String(order.id));tracked.push({...order,savedAt:Date.now()});tracked=tracked.slice(-20);storageSet(trackingKey,JSON.stringify(tracked));showTracking();}
 let trackingBusy=false;
 async function refreshTracking(){if(trackingBusy)return;trackingBusy=true;try{const missing=new Set();let changed=false;for(const o of tracked){if(['SERVED','REJECTED'].includes(o.status))continue;try{const r=await fetch(APP.base+'/api/status?id='+o.id+'&token='+encodeURIComponent(o.tracking_token));if(r.status===404){missing.add(String(o.id));continue;}if(r.ok){const result=await r.json();if(result.status&&result.status!==o.status){if(result.status==='REJECTED'&&o.status!=='REJECTED'){rejectedNoticeSeen=false;trackingExpanded=true;}o.status=result.status;changed=true;}}}catch{}}if(missing.size){tracked=tracked.filter(o=>!missing.has(String(o.id)));changed=true;}if(changed){storageSet(trackingKey,JSON.stringify(tracked));syncTracking();}}finally{trackingBusy=false;}}
 showTracking();refreshTracking();setInterval(refreshTracking,4000);
 const controls=new Map();
 const selectionKey=(id,portion)=>`${id}:${portion===null?'\u0000':portion}`;
 const sizeDialog=document.createElement('dialog');sizeDialog.className='theme-two-size-dialog';document.body.append(sizeDialog);let activeSizeButton=null;
 const supportedKeys=new Set();
 function openSizePicker(button){
  if(button.disabled)return;
  activeSizeButton=button;
  sizeDialog.replaceChildren();
  const title=el('h2',button.dataset.name);const subtitle=el('p','Select size and quantity');sizeDialog.append(title,subtitle);
  const choices=document.createElement('div');choices.className='theme-two-size-choices';let chosen=null,qty=1;
  const submit=el('button','Add to Order');submit.type='button';submit.className='theme-two-size-submit';submit.disabled=true;
  const options=JSON.parse(button.dataset.options||'[]');
  options.forEach(size=>{const option=el('button',`${size.name} · LKR ${Number(size.price).toFixed(2)}`);option.type='button';option.className='theme-two-size-choice';option.onclick=()=>{chosen=size;choices.querySelectorAll('button').forEach(n=>n.setAttribute('aria-pressed',String(n===option)));submit.disabled=false;};option.setAttribute('aria-pressed','false');choices.append(option);});
  const quantity=document.createElement('div');quantity.className='theme-two-size-quantity';const minus=el('button','−'),count=el('span','1'),plus=el('button','+');minus.className='theme-two-qty-minus';plus.className='theme-two-qty-plus';minus.type=plus.type='button';minus.setAttribute('aria-label','Decrease quantity');plus.setAttribute('aria-label','Increase quantity');minus.onclick=()=>{qty=Math.max(1,qty-1);count.textContent=String(qty);};plus.onclick=()=>{qty=Math.min(99,qty+1);count.textContent=String(qty);};quantity.append(el('span','Quantity'),minus,count,plus);
  const cancel=el('button','Cancel');cancel.type='button';cancel.className='theme-two-size-cancel';cancel.onclick=()=>sizeDialog.close();
  submit.onclick=()=>{if(!chosen||button.disabled)return;const id=Number(button.dataset.id),key=selectionKey(id,chosen.portion),price=Number(chosen.price);let item=state.items.find(i=>selectionKey(i.id,i.portion)===key);if(item)item.qty=Math.min(99,item.qty+qty);else state.items.push({id,portion:chosen.portion,name:button.dataset.name,price,qty});state.key=crypto.randomUUID();save();sizeDialog.close();};
  const actions=document.createElement('div');actions.className='theme-two-size-actions';actions.append(cancel,submit);sizeDialog.append(choices,quantity,actions);sizeDialog.showModal();
 }
 function renderQuantities(){controls.forEach(({add,minus,count,plus,group},key)=>{const item=state.items.find(i=>selectionKey(i.id,i.portion)===key);const qty=item?.qty||0;add.hidden=qty>0;group.hidden=qty===0;group.style.display=qty?'inline-flex':'none';count.textContent=String(qty);plus.disabled=add.disabled||qty>=99;minus.disabled=qty===0;});}
 function save(){storageSet(storage,JSON.stringify(state));document.querySelector('#view span').textContent=`${state.items.reduce((n,i)=>n+i.qty,0)} items · LKR ${grandTotal().toFixed(2)}`;renderQuantities();}
 document.querySelectorAll('.add').forEach(b=>b.onclick=()=>{if(b.dataset.sizePicker==='1'){openSizePicker(b);return;}const id=Number(b.dataset.id),portion=b.dataset.portion||null;let i=state.items.find(i=>selectionKey(i.id,i.portion)===selectionKey(id,portion));if(i)i.qty=Math.min(99,i.qty+1);else state.items.push({id,portion,name:b.dataset.name,price:Number(b.dataset.price),qty:1});state.key=crypto.randomUUID();save();});
 document.querySelectorAll('.add').forEach(add=>{
  if(add.dataset.sizePicker==='1'){for(const size of JSON.parse(add.dataset.options||'[]'))supportedKeys.add(selectionKey(Number(add.dataset.id),size.portion));return;}
  if(add.classList.contains('theme-two-add')){supportedKeys.add(selectionKey(Number(add.dataset.id),null));return;}
  const id=Number(add.dataset.id),portion=add.dataset.portion||null,key=selectionKey(id,portion),group=document.createElement('span'),minus=el('button','−'),count=el('span','0'),plus=el('button','+');
  group.style.cssText='display:inline-flex;align-items:center;gap:12px';group.hidden=true;
  minus.type=plus.type='button';const label=add.dataset.name+(portion?' '+portion:'');minus.setAttribute('aria-label','Remove one '+label);plus.setAttribute('aria-label','Add one '+label);count.setAttribute('aria-live','polite');
  minus.onclick=()=>{const item=state.items.find(i=>selectionKey(i.id,i.portion)===key);if(item)item.qty--;state.items=state.items.filter(i=>i.qty>0);state.key=crypto.randomUUID();save();};plus.onclick=()=>add.click();
  group.append(minus,count,plus);add.after(group);controls.set(key,{add,minus,count,plus,group});supportedKeys.add(key);
 });
 state.items=state.items.filter(i=>supportedKeys.has(selectionKey(i.id,i.portion)));
 document.querySelector('#search').oninput=e=>document.querySelectorAll('.food').forEach(n=>n.style.display=n.dataset.name.includes(e.target.value.toLowerCase())?'flex':'none');
 let paymentSavePromise=Promise.resolve(true);
 function review(){
  dialog.replaceChildren(el('h2','Your Order'),el('p',document.querySelector('.hero h1')?.textContent||''),el('p',document.querySelector('.hero .eyebrow')?.textContent||''));
  if(online&&accountToggle&&accountPanel){const accountEntry=document.createElement('section');accountEntry.className='customer-order-account-entry';const toggle=el('button',signedInCustomer?'Account & Order History':'Sign in / Register');toggle.type='button';toggle.className='customer-order-account-toggle';toggle.addEventListener('click',openAccountAfterCheckout);const summary=el('p',document.querySelector('#online-account-summary')?.textContent||'');accountEntry.append(toggle,summary);dialog.append(accountEntry);}
  state.items.forEach(i=>{const row=document.createElement('div');row.className='customer-order-item';const details=document.createElement('div');details.className='customer-order-item-details';const label=i.name+(i.portion?' ('+i.portion+')':'');details.append(el('strong',label),el('span',`${i.qty} × LKR ${i.price.toFixed(2)}`));const controls=document.createElement('div');controls.className='customer-order-qty';[-1,1].forEach(delta=>{const b=el('button',delta<0?'−':'+');b.type='button';b.setAttribute('aria-label',(delta<0?'Remove one ':'Add one ')+label);b.onclick=()=>{i.qty=Math.min(99,i.qty+delta);state.items=state.items.filter(x=>x.qty>0);state.key=crypto.randomUUID();save();review();};controls.append(b);});row.append(details,controls);dialog.append(row);});
  const charges=document.createElement('div');charges.className='customer-order-charges';
  const chargeRow=(label,amount)=>{const row=document.createElement('div');row.append(el('span',label),el('strong',`LKR ${amount.toFixed(2)}`));charges.append(row);};
  chargeRow('Subtotal',total());if(!online)chargeRow(`Service charge (${servicePercent().toFixed(2)}%)`,serviceCharge());chargeRow('Total',grandTotal());dialog.append(charges);
  if(online){
   const customer=document.createElement('section');customer.className='customer-online-details';customer.append(el('h3','Customer details'));
   const typeLabel=el('label','Order type');const type=document.createElement('select');type.required=true;[['TAKEAWAY','Pickup'],['DELIVERY','Delivery']].forEach(([value,title])=>{const option=el('option',title);option.value=value;type.append(option);});type.value=state.order_type||'TAKEAWAY';typeLabel.append(type);customer.append(typeLabel);
   const paymentLabel=el('label','Payment option');const payment=document.createElement('select');payment.required=true;paymentLabel.append(payment);customer.append(paymentLabel);
   const paymentHint=el('small','Online payment records your preferred option; payment processing is not connected yet.');paymentHint.className='online-payment-hint';customer.append(paymentHint);
   const field=(title,key,options={})=>{const label=el('label',title),input=document.createElement(options.multiline?'textarea':'input');if(!options.multiline)input.type=options.type||'text';input.value=state[key]||'';input.maxLength=options.maxLength||120;input.required=!!options.required;if(options.pattern)input.pattern=options.pattern;if(options.placeholder)input.placeholder=options.placeholder;label.append(input);input.addEventListener('input',()=>{state[key]=input.value;state.key=crypto.randomUUID();save();});customer.append(label);return label;};
   if(signedInCustomer){const accountDetails=el('p','Signed in as '+signedInCustomer.name+' · '+signedInCustomer.phone);accountDetails.className='customer-online-account-details';customer.append(accountDetails);state.customer_name=signedInCustomer.name;state.customer_phone=signedInCustomer.phone;}
   else{
    field('Full name','customer_name',{required:true,maxLength:120});
    const phoneLabel=field('Mobile number (10 digits)','customer_phone',{required:true,type:'tel',maxLength:10,placeholder:'0712345678',pattern:'0[0-9]{9}'}),phoneInput=phoneLabel.querySelector('input');phoneInput.inputMode='numeric';phoneInput.autocomplete='tel-national';
    const phonePrompt=document.createElement('section');phonePrompt.className='online-phone-account-prompt';phonePrompt.hidden=true;const phonePromptText=el('p','This mobile number already has an online account. Sign in, or choose Not now to continue as a guest.');phonePromptText.setAttribute('role','status');phonePromptText.setAttribute('aria-live','polite');const promptActions=document.createElement('div'),signInButton=el('button','Sign in'),notNowButton=el('button','Not now');signInButton.type=notNowButton.type='button';signInButton.className='online-phone-account-sign-in';notNowButton.className='online-phone-account-not-now';promptActions.append(signInButton,notNowButton);phonePrompt.append(phonePromptText,promptActions);customer.append(phonePrompt);
    let phoneCheckTimer=0,phoneCheckSequence=0,notNowPhone='';
    const checkPhone=async()=>{const phone=phoneInput.value.trim();if(!/^0[0-9]{9}$/.test(phone)){phonePrompt.hidden=true;notNowPhone='';return;}if(phone===notNowPhone)return;const sequence=++phoneCheckSequence;try{const response=await fetch(accountApi,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify({action:'check-phone',phone})});const result=await response.json();if(sequence!==phoneCheckSequence||phoneInput.value.trim()!==phone)return;if(!response.ok||result.error)throw Error(result.error||'Unable to check this number.');phonePromptText.textContent=result.registered?'This mobile number already has an online account. Sign in, or choose Not now to continue as a guest.':'This number has no online account. You can continue as a guest.';signInButton.hidden=!result.registered;phonePrompt.hidden=!result.registered;}catch(error){phonePromptText.textContent=error.message+' You can continue as a guest.';signInButton.hidden=true;phonePrompt.hidden=false;}};
    phoneInput.addEventListener('input',()=>{phonePrompt.hidden=true;clearTimeout(phoneCheckTimer);phoneCheckTimer=setTimeout(checkPhone,250);});phoneInput.addEventListener('blur',()=>{clearTimeout(phoneCheckTimer);checkPhone();});
    signInButton.addEventListener('click',()=>{const loginPhone=document.querySelector('#online-login-form [name="phone"]');if(loginPhone)loginPhone.value=phoneInput.value;phonePrompt.hidden=true;openAccountAfterCheckout();});
    notNowButton.addEventListener('click',()=>{notNowPhone=phoneInput.value.trim();phonePrompt.hidden=true;});
   }
   const savedAddresses=Array.isArray(signedInCustomer?.addresses)?signedInCustomer.addresses:[];let addressChoiceLabel=null,addressChoice=null;
   if(savedAddresses.length){addressChoiceLabel=el('label','Delivery address');addressChoice=document.createElement('select');for(const saved of savedAddresses){const option=el('option',(saved.label?saved.label+' — ':'')+saved.address);option.value=String(saved.id);addressChoice.append(option);}const newOption=el('option','Use a new address');newOption.value='NEW';addressChoice.append(newOption);addressChoiceLabel.append(addressChoice);customer.append(addressChoiceLabel);const validSelected=savedAddresses.some(saved=>Number(saved.id)===Number(state.delivery_address_id));addressChoice.value=validSelected?String(state.delivery_address_id):String((savedAddresses.find(saved=>Number(saved.is_default)===1)||savedAddresses[0]).id);}
   const address=field(savedAddresses.length?'New delivery address':'Delivery address','delivery_address',{required:type.value==='DELIVERY',maxLength:500,multiline:true,placeholder:'Enter the delivery address'});
   const saveAddressLabel=document.createElement('label');saveAddressLabel.className='online-save-address';const saveAddress=document.createElement('input');saveAddress.type='checkbox';saveAddress.checked=Boolean(state.save_delivery_address);saveAddressLabel.append(saveAddress,document.createTextNode(' Save this address for future orders'));customer.append(saveAddressLabel);saveAddress.addEventListener('change',()=>{state.save_delivery_address=saveAddress.checked;save();});
   const deliveryNotes=field('Delivery notes (optional)','delivery_notes',{maxLength:500,multiline:true,placeholder:'Landmark or delivery instructions'});
   const minimumNotice=el('p','');minimumNotice.className='online-delivery-minimum';minimumNotice.setAttribute('role','status');customer.append(minimumNotice);
   const updateMinimum=()=>{const minimum=Number.isFinite(Number(APP.delivery_minimum))?Number(APP.delivery_minimum):1000;minimumNotice.hidden=type.value!=='DELIVERY';minimumNotice.textContent=type.value==='DELIVERY'?'Minimum delivery subtotal: LKR '+minimum.toFixed(2)+'. Current subtotal: LKR '+total().toFixed(2)+'.':'';const send=dialog.querySelector('.customer-order-send');if(send){send.disabled=!state.items.length||(APP.accepting_orders===false)||(type.value==='DELIVERY'&&total()<minimum);}minimumNotice.classList.toggle('is-below-minimum',type.value==='DELIVERY'&&total()<minimum);};
   const savePayment=()=>{const send=dialog.querySelector('.customer-order-send');if(send)send.disabled=true;paymentSavePromise=fetch(accountApi,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':APP.csrf},body:JSON.stringify({action:'select-payment',payment_method:state.payment_method})}).then(async response=>{const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to save payment option.');updateMinimum();return true;}).catch(error=>{paymentHint.textContent=error.message;return false;});};
   const updateAddress=()=>{const delivery=type.value==='DELIVERY',usingSaved=delivery&&addressChoice&&addressChoice.value!=='NEW';if(addressChoiceLabel)addressChoiceLabel.hidden=!delivery;if(usingSaved){const selected=savedAddresses.find(saved=>String(saved.id)===addressChoice.value);state.delivery_address_id=Number(selected.id);state.delivery_address=selected.address;address.querySelector('textarea').value=selected.address;}else state.delivery_address_id=null;address.hidden=!delivery||Boolean(usingSaved);const addressInput=address.querySelector('textarea');addressInput.required=delivery&&!usingSaved;saveAddressLabel.hidden=!delivery||Boolean(usingSaved)||!signedInCustomer;if(saveAddressLabel.hidden){saveAddress.checked=false;state.save_delivery_address=false;}state.key=crypto.randomUUID();save();};
   if(addressChoice)addressChoice.addEventListener('change',()=>{if(addressChoice.value==='NEW'){state.delivery_address='';address.querySelector('textarea').value='';}updateAddress();});
   const updateType=()=>{state.order_type=type.value;deliveryNotes.hidden=type.value!=='DELIVERY';updateAddress();const methods=type.value==='DELIVERY'?[['CASH_ON_DELIVERY','Cash on Delivery'],['ONLINE_PAYMENT','Online Payment']]:[['PAY_AT_PICKUP','Pay at Pickup'],['ONLINE_PAYMENT','Online Payment']];payment.replaceChildren(...methods.map(([value,title])=>{const option=el('option',title);option.value=value;return option;}));if(!methods.some(([value])=>value===state.payment_method))state.payment_method=methods[0][0];payment.value=state.payment_method;payment.onchange=()=>{state.payment_method=payment.value;state.key=crypto.randomUUID();save();savePayment();};state.key=crypto.randomUUID();save();savePayment();updateMinimum();};
   type.addEventListener('change',updateType);updateType();dialog.append(customer);
  }
  const label=el('label','Special requests (optional)');const note=document.createElement('textarea');note.value=state.note;note.maxLength=1000;note.style.width='100%';label.append(note);dialog.append(label);
  note.oninput=()=>{state.note=note.value;state.key=crypto.randomUUID();save();};
  const error=el('p','');error.setAttribute('role','alert');dialog.append(error);
  const actions=document.createElement('div');actions.className='customer-order-actions';
  const back=el('button','Back to Menu');back.className='customer-order-back';back.onclick=()=>dialog.close();
  const send=el('button','Send Order');send.className='customer-order-send';send.disabled=!state.items.length||(online&&APP.accepting_orders===false)||(online&&state.order_type==='DELIVERY'&&total()<(Number.isFinite(Number(APP.delivery_minimum))?Number(APP.delivery_minimum):1000));
  actions.append(back,send);dialog.append(actions);
  send.onclick=async()=>{
   if(online){if(!(await paymentSavePromise)){error.textContent='Unable to save payment option. Please select it again and retry.';return;}const required=dialog.querySelectorAll('.customer-online-details [required]');for(const input of required){if(!input.checkValidity()){input.reportValidity();return;}}}
   dialog.querySelectorAll('button,textarea,select,input').forEach(b=>b.disabled=true);
   const spinner=document.createElement('img');spinner.className='customer-ajax-loading-gif';spinner.src=APP.base+'/assets/uploading.gif';spinner.alt='';spinner.setAttribute('aria-hidden','true');send.append(spinner);send.classList.add('customer-ajax-loading');
   try{const payload=online?{slug:APP.slug,order_type:state.order_type||'TAKEAWAY',customer_name:state.customer_name,customer_phone:state.customer_phone,delivery_address:state.order_type==='DELIVERY'?state.delivery_address:'',delivery_address_id:state.order_type==='DELIVERY'?(Number(state.delivery_address_id)||null):null,save_delivery_address:state.order_type==='DELIVERY'&&Boolean(state.save_delivery_address),delivery_notes:state.order_type==='DELIVERY'?state.delivery_notes:'',items:state.items,special_request:state.note,idempotency_key:state.key}:{slug:APP.slug,token:APP.token,dining_session_id:APP.dining_session.id,dining_session_proof:APP.dining_session.proof,client_token:APP.device_token,items:state.items,special_request:state.note,idempotency_key:state.key};const r=await fetch(APP.base+(online?'/api/online-order':'/api/order'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});const x=await r.json();if(!r.ok||x.error)throw Error(x.error||'Unable to send order.');rememberOrder(x.order);const completedState=state;state={items:[],note:'',key:crypto.randomUUID(),order_type:completedState.order_type,customer_name:completedState.customer_name,customer_phone:completedState.customer_phone,delivery_address:completedState.delivery_address,delivery_address_id:completedState.delivery_address_id,delivery_notes:'',payment_method:completedState.payment_method,save_delivery_address:false};save();if(online&&completedState.save_delivery_address&&window.refreshOnlineCustomerAccount)window.refreshOnlineCustomerAccount();dialog.replaceChildren(el('h2','Order Sent Successfully!'),el('p',x.order.order_number+' — received by the system.'));const status=el('p',x.order.status);actions.replaceChildren(back);dialog.append(status,actions);back.disabled=false;const timer=setInterval(async()=>{try{const r=await fetch(APP.base+'/api/status?id='+x.order.id+'&token='+x.order.tracking_token);const s=await r.json();if(r.ok){status.textContent=s.status==='REJECTED'?'This order was rejected by the restaurant.':s.status;status.className=s.status==='REJECTED'?'customer-order-rejected-message':'';}if(['SERVED','REJECTED'].includes(s.status))clearInterval(timer);}catch{}},4000);
   }catch(e){error.textContent=e.message+' Your selections are saved; you can retry.';dialog.querySelectorAll('button,textarea,select,input').forEach(b=>b.disabled=false);}
   finally{spinner.remove();send.classList.remove('customer-ajax-loading');}
  };
 }
 document.querySelector('#view').onclick=()=>{review();lockPageScroll();try{dialog.showModal();}catch(error){restoreAccountPanel();unlockPageScroll();throw error;}};
 async function refreshMenu(){
  try{
   const r=await fetch(APP.base+(online?'/api/online-menu?slug='+encodeURIComponent(APP.slug):'/api/menu?slug='+encodeURIComponent(APP.slug)+'&token='+encodeURIComponent(APP.token)));
   const result=await r.json();if(!r.ok)throw Error(result.error||'Menu unavailable.');
   if(!online)APP.service_charge_percent=result.service_charge_percent;else{APP.accepting_orders=Boolean(result.accepting_orders);const updatedMinimum=Number(result.delivery_minimum);APP.delivery_minimum=Number.isFinite(updatedMinimum)&&updatedMinimum>=0?updatedMinimum:1000;}
   const orderingStatus=document.querySelector('#ordering-status');orderingStatus.textContent=online&&!result.accepting_orders?'Online ordering is currently unavailable.':result.accepting_orders?'Accepting orders':'Ordering is currently paused';orderingStatus.classList.toggle('ordering-unavailable',online&&!result.accepting_orders);
   document.querySelector('#view').disabled=!result.accepting_orders;
   const sendButton=dialog.querySelector('.customer-order-send');
   if(dialog.open&&online&&sendButton){const minimum=Number.isFinite(Number(APP.delivery_minimum))?Number(APP.delivery_minimum):1000;sendButton.disabled=!result.accepting_orders||!state.items.length||(state.order_type==='DELIVERY'&&total()<minimum);let notice=dialog.querySelector('.online-order-unavailable');if(!result.accepting_orders&&!notice){notice=el('p','Online ordering is currently unavailable.');notice.className='online-order-unavailable';notice.setAttribute('role','status');dialog.insertBefore(notice,dialog.querySelector('.customer-order-actions'));}else if(result.accepting_orders&&notice)notice.remove();const minimumNotice=dialog.querySelector('.online-delivery-minimum');if(minimumNotice&&state.order_type==='DELIVERY')minimumNotice.textContent='Minimum delivery subtotal: LKR '+minimum.toFixed(2)+'. Current subtotal: LKR '+total().toFixed(2)+'.';}
   for(const item of result.items){
    const options=item.options?.length?item.options.map(size=>({name:size.name,portion:size.name,price:size.price})):
      Number(item.is_portioned)?[{name:'Half',portion:'HALF',price:item.half_price},{name:'Full',portion:'FULL',price:item.full_price}]:[];
    for(const button of document.querySelectorAll('.add[data-id="'+item.id+'"]')){
     const portion=button.dataset.portion||null,isPicker=button.dataset.sizePicker==='1';
     const option=options.find(size=>size.portion===portion);
     const valid=isPicker?options.length>0:options.length?!!option:portion===null;
     button.disabled=!result.accepting_orders||!Number(item.is_available)||!valid;
     button.textContent=button.classList.contains('theme-two-add')?'+':!Number(item.is_available)?'Unavailable':'Add +';
     if(!valid)continue;
     if(isPicker)button.dataset.options=JSON.stringify(options);
     else button.dataset.price=option?.price??item.price;
    }
    for(const saved of state.items.filter(saved=>Number(saved.id)===Number(item.id))){
     const option=options.find(size=>size.portion===saved.portion);
     if(option||(!options.length&&saved.portion===null))saved.price=Number(option?.price??item.price);
    }
   }
   if(sizeDialog.open&&activeSizeButton?.disabled)sizeDialog.close();
   save();const charges=dialog.querySelector('.customer-order-charges');
   if(dialog.open&&charges){const rows=charges.children;if(online)rows[1].lastChild.textContent=`LKR ${grandTotal().toFixed(2)}`;else{rows[1].firstChild.textContent=`Service charge (${servicePercent().toFixed(2)}%)`;rows[1].lastChild.textContent=`LKR ${serviceCharge().toFixed(2)}`;rows[2].lastChild.textContent=`LKR ${grandTotal().toFixed(2)}`;}}
  }catch(error){document.querySelector('#ordering-status').textContent=error.message;document.querySelector('#view').disabled=true;}
 }
 refreshMenu();setInterval(refreshMenu,4000);save();
})();
