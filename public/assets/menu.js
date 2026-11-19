const orderingScript = document.createElement('script');
const orderReviewVersion = document.querySelector('meta[name="asset-order-review-version"]')?.content || '1';
orderingScript.src = APP.base + '/assets/order-review.js?v=' + encodeURIComponent(orderReviewVersion);
if(APP.order_mode==='ONLINE'){
 document.head.append(orderingScript);
}else{
 const deviceKey='ee-device-'+APP.restaurant_id+'-'+APP.table_id;
 let deviceToken=localStorage.getItem(deviceKey);
 if(!deviceToken){deviceToken=crypto.randomUUID();localStorage.setItem(deviceKey,deviceToken);}
 fetch(APP.base+'/api/table-session',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({slug:APP.slug,token:APP.token,client_token:deviceToken})})
  .then(async response=>{const result=await response.json();if(!response.ok||!result.session)throw Error(result.error||'Unable to start table session.');APP.dining_session=result.session;APP.device_token=deviceToken;document.head.append(orderingScript);const expires=Date.parse(String(result.session.expires_at).replace(' ','T')+'Z');const remaining=expires-Date.now();if(remaining>0)setTimeout(()=>location.reload(),Math.min(remaining+1000,2147483647));})
  .catch(error=>{const status=document.querySelector('#ordering-status');if(status)status.textContent=error.message;document.querySelector('#view')?.setAttribute('disabled','disabled');});
}

const categoryNav = document.querySelector('.chips');
if (categoryNav) {
 const picker = document.createElement('label');
 picker.className = 'category-picker';
 const label = document.createElement('span');
 label.textContent = 'Browse menu category';
 const select = document.createElement('select');
 select.id = 'category-select';
 select.setAttribute('aria-label', 'Browse menu category');
 const placeholder = document.createElement('option');
 placeholder.value = '';
 placeholder.textContent = 'Select a category';
 select.append(placeholder);
 categoryNav.querySelectorAll('a').forEach(link => {
  const option = document.createElement('option');
  option.value = link.getAttribute('href') || '';
  option.textContent = link.textContent.trim();
  select.append(option);
 });
 select.addEventListener('change', () => {
  if (!select.value) return;
  document.querySelector(select.value)?.scrollIntoView({behavior: 'smooth', block: 'start'});
 });
 picker.append(label, select);
 categoryNav.replaceWith(picker);
}
