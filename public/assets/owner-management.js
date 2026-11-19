document.querySelectorAll('[data-restaurant-form]').forEach(form=>{
 const button=form.querySelector('.owner-save-restaurant'),status=form.querySelector('[role=status]'),deleteButton=form.querySelector('.owner-delete-restaurant');
 const cleanup=document.createElement('div');cleanup.className='owner-upload-cleanup';
 const cleanupButton=document.createElement('button');cleanupButton.type='button';cleanupButton.textContent='Delete unused uploaded images';
 const cleanupHint=document.createElement('small');cleanupHint.textContent='Removes unreferenced food photos and logos older than one hour for this restaurant.';
 cleanup.append(cleanupButton,cleanupHint);form.after(cleanup);
 cleanupButton.addEventListener('click',async()=>{
  const restaurantName=form.elements.name.value.trim();
  if(!await window.showAdminConfirm('Delete unused uploaded images for '+restaurantName+'?'))return;
  cleanupButton.disabled=true;
  const done=window.startAjaxButtonLoading(cleanupButton);
  try{
   const payload=new FormData();payload.set('csrf',form.elements.csrf.value);payload.set('restaurant_id',form.elements.restaurant_id.value);
   const response=await fetch(base+'/api/owner/uploads/cleanup',{method:'POST',body:payload}),result=await response.json();
   if(!response.ok||result.error)throw Error(result.error||'Unable to clean unused uploads.');
   await window.showAdminPopup(result.removed+' unused image file(s) deleted for '+restaurantName+'.');
  }catch(error){await window.showAdminPopup(error.message,'error');}
  finally{done();cleanupButton.disabled=false;}
 });
 if(deleteButton)deleteButton.addEventListener('click',async()=>{
  const restaurantName=form.elements.name.value.trim()||'this restaurant';
  const confirmed=await window.showAdminConfirm('Permanently delete '+restaurantName+' and all of its accounts, menus, tables, orders, subscriptions, reports and uploaded images? This cannot be undone.');
  if(!confirmed)return;
  deleteButton.disabled=true;
  try{
   const payload=new FormData();payload.set('csrf',form.elements.csrf.value);payload.set('restaurant_id',form.elements.restaurant_id.value);
   const response=await fetch(base+'/api/owner/restaurant/delete',{method:'POST',body:payload}),result=await response.json();
   if(!response.ok||result.error)throw Error(result.error||'Unable to delete restaurant.');
   await window.showAdminPopup(result.name+' was permanently deleted.');
   location.reload();
  }catch(error){deleteButton.disabled=false;await window.showAdminPopup(error.message,'error');}
 });
 form.addEventListener('submit',async event=>{
  event.preventDefault();
  const newPassword=form.elements.admin_new_password,confirmPassword=form.elements.admin_confirm_password;
  if(newPassword&&newPassword.value!==confirmPassword.value){status.textContent='Administrator password confirmation does not match.';return;}
  button.disabled=true;status.textContent='Saving…';
  try{
   const response=await fetch(base+'/api/owner/restaurant/update',{method:'POST',body:new FormData(form)}),result=await response.json();
   if(!response.ok||result.error)throw Error(result.error||'Unable to update restaurant.');
   if(newPassword){newPassword.value='';confirmPassword.value='';}
   status.textContent='Restaurant and administrator login details updated.';
  }catch(error){status.textContent=error.message;}
  finally{button.disabled=false;}
 });
});
(()=>{
 const list=document.querySelector('#owner-restaurant-list');if(!list)return;
 const search=document.querySelector('#owner-restaurant-search'),filter=document.querySelector('#owner-restaurant-filter'),pageSize=document.querySelector('#owner-restaurant-page-size'),empty=document.querySelector('.owner-restaurant-empty'),countLabel=document.querySelector('.owner-restaurant-list-footer>span');
 const entries=[...list.querySelectorAll('[data-owner-restaurant]')];let page=0;
 const render=()=>{
  const query=(search?.value||'').trim().toLocaleLowerCase(),state=filter?.value||'all',size=Math.max(1,Number(pageSize?.value)||10);
  const matched=entries.filter(entry=>(!query||(entry.dataset.search||'').includes(query))&&(state==='all'||entry.dataset.status===state));
  const pages=Math.max(1,Math.ceil(matched.length/size));page=Math.min(page,pages-1);
  entries.forEach(entry=>{const index=matched.indexOf(entry);entry.hidden=index<0||Math.floor(index/size)!==page;});
  if(empty)empty.hidden=matched.length!==0;
  if(countLabel)countLabel.textContent=`Showing ${matched.length} restaurant${matched.length===1?'':'s'}`;
  const pager=document.querySelector('.owner-restaurant-pagination');if(pager)pager.innerHTML=`<button type="button" data-page="prev" aria-label="Previous page" ${page===0?'disabled':''}>‹</button><span>${page+1} / ${pages}</span><button type="button" data-page="next" aria-label="Next page" ${page+1>=pages?'disabled':''}>›</button>`;
 };
 search?.addEventListener('input',()=>{page=0;render();});filter?.addEventListener('change',()=>{page=0;render();});pageSize?.addEventListener('change',()=>{page=0;render();});
 document.querySelector('.owner-restaurant-pagination')?.addEventListener('click',event=>{const button=event.target.closest('[data-page]');if(!button)return;page+=button.dataset.page==='next'?1:-1;render();});
 list.addEventListener('click',event=>{
  const quickEdit=event.target.closest('.owner-quick-edit');if(quickEdit){event.preventDefault();event.stopPropagation();const entry=quickEdit.closest('details');entry.open=true;entry.querySelector('[name="name"]')?.focus();return;}
  const cancel=event.target.closest('.owner-detail-cancel');if(cancel){event.preventDefault();const entry=cancel.closest('details');entry.open=false;const status=entry.querySelector('[role="status"]');if(status)status.textContent='';return;}
 });
 render();
})();
document.querySelectorAll('.owner-empty-demo-orders').forEach(button=>button.addEventListener('click',async()=>{
 const restaurantName=button.dataset.restaurantName||'this restaurant';
 const confirmed=await window.showAdminConfirm(`Permanently clear all orders for ${restaurantName}, including pending bills, paid bills, payments, KOTs and expired table-history sessions? Restaurant settings, menu, tables and active dining sessions will remain.`);
 if(!confirmed)return;
 button.disabled=true;
 const done=window.startAjaxButtonLoading(button);
 try{
  const payload=new FormData();payload.set('csrf',button.dataset.csrf);payload.set('restaurant_id',button.dataset.restaurantId);
  const response=await fetch(base+'/api/owner/restaurant/empty-orders',{method:'POST',body:payload}),result=await response.json();
  if(!response.ok||result.error)throw Error(result.error||'Unable to clear orders for '+restaurantName+'.');
  await window.showAdminPopup(`${result.deleted} order${result.deleted===1?'':'s'} cleared for ${restaurantName}, including ${Number(result.pending_bills_deleted)||0} pending bill${Number(result.pending_bills_deleted)===1?'':'s'}, invoices, kitchen tickets and ${Number(result.expired_sessions_deleted)||0} expired table-history session${Number(result.expired_sessions_deleted)===1?'':'s'}.`);
 }catch(error){await window.showAdminPopup(error.message,'error');}
 finally{done();button.disabled=false;}
}));
document.querySelectorAll('.owner-delete-demo-customers').forEach(button=>button.addEventListener('click',async()=>{
 const confirmed=await window.showAdminConfirm('Permanently delete all customer profiles, online accounts and saved addresses for the Demo Restaurant? Guest order snapshots will stop appearing in the Customers list, but orders and their saved details remain unchanged. This cannot be undone.');
 if(!confirmed)return;
 button.disabled=true;
 const done=window.startAjaxButtonLoading(button);
 try{
  const payload=new FormData();payload.set('csrf',button.dataset.csrf);payload.set('restaurant_id',button.dataset.restaurantId);
  const response=await fetch(base+'/api/owner/demo/delete-customers',{method:'POST',body:payload}),result=await response.json();
  if(!response.ok||result.error)throw Error(result.error||'Unable to delete demo customers.');
  await window.showAdminPopup(`${result.deleted} demo customer profile${result.deleted===1?'':'s'} deleted. Guest entries are removed from the Customers list; historical orders and their saved details remain unchanged.`);
 }catch(error){await window.showAdminPopup(error.message,'error');}
 finally{done();button.disabled=false;}
}));
