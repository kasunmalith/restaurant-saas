(() => {
  const script=document.currentScript,base=script.dataset.base,csrf=script.dataset.csrf;
  const section=document.getElementById('customers');if(!section)return;
  const $=id=>document.getElementById(id),form=$('customer-editor-form');
  let customers=[],timer,offset=0,total=0,loading=false,reloadPending=false,category='online_registered';
  function appendCustomer(customer){
    const row=document.createElement('tr');
    for(const value of [customer.name||'—',customer.phone,customer.address||'—']){const cell=document.createElement('td');cell.textContent=value;row.append(cell);}
    const status=document.createElement('td');status.textContent=customer.account_status==='REGISTERED'?'REGISTERED':'GUEST';row.append(status);
    const source=document.createElement('td');source.textContent=({ONLINE_REGISTRATION:'Own registration',MANAGER:'Added by manager',POS:'Added from POS',ONLINE_GUEST:'Online guest checkout',LEGACY:'Existing record'})[customer.origin]||'Existing record';row.append(source);
    const action=document.createElement('td');if(customer.record_type==='ORDER'){action.textContent='Order snapshot';}else{const button=document.createElement('button');button.type='button';button.textContent='Edit';button.addEventListener('click',()=>open(customer));action.append(button);}row.append(action);$('customer-list').append(row);
  }
  async function load(reset=true){
    if(loading){reloadPending=reloadPending||reset;return;}loading=true;const more=$('customer-load-more');more.disabled=true;
    if(reset){offset=0;customers=[];$('customer-list').replaceChildren();}
    $('customer-list-status').textContent=reset?'Loading customers…':'Loading more customers…';
    try{
      const response=await fetch(base+'/api/pos/customers?category='+encodeURIComponent(category)+'&search='+encodeURIComponent($('customer-search').value.trim())+'&offset='+offset);
      const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to load customers.');
      const batch=Array.isArray(result.customers)?result.customers:[];customers.push(...batch);batch.forEach(appendCustomer);offset=Number(result.next_offset)||customers.length;total=Number(result.total)||customers.length;more.hidden=!result.has_more;
      $('customer-list-status').textContent=customers.length+' of '+total+' customer'+(total===1?'':'s')+' shown · Name A–Z.';
    }catch(error){$('customer-list-status').textContent=error.message;}finally{loading=false;more.disabled=false;if(reloadPending){reloadPending=false;load(true);}}
  }
  async function refreshVisibleCustomers(){
    if(loading||!section.getClientRects().length)return;
    loading=true;const query=$('customer-search').value.trim(),desiredCount=Math.max(15,customers.length),fresh=[];let nextOffset=0,latest=null;
    try{
      do{
        const response=await fetch(base+'/api/pos/customers?category='+encodeURIComponent(category)+'&search='+encodeURIComponent(query)+'&offset='+nextOffset);
        latest=await response.json();if(!response.ok||latest.error)throw Error(latest.error||'Unable to refresh customers.');
        const batch=Array.isArray(latest.customers)?latest.customers:[];fresh.push(...batch);nextOffset=Number(latest.next_offset)||fresh.length;
      }while(fresh.length<desiredCount&&latest.has_more);
      if(query!==$('customer-search').value.trim())return;
      customers=fresh;offset=nextOffset;total=Number(latest.total)||fresh.length;$('customer-list').replaceChildren();customers.forEach(appendCustomer);
      $('customer-load-more').hidden=!latest.has_more;
      $('customer-list-status').textContent=customers.length+' of '+total+' customer'+(total===1?'':'s')+' shown · Name A–Z.';
    }catch(error){$('customer-list-status').textContent=error.message;}finally{loading=false;if(reloadPending){reloadPending=false;load(true);}}
  }
  function open(customer){form.reset();form.elements.id.value=customer?.id||0;form.elements.name.value=customer?.name||'';form.elements.phone.value=customer?.phone||'';form.elements.address.value=customer?.address||'';$('customer-editor-title').textContent=customer?'Edit customer':'Add customer';$('customer-editor-status').textContent='';$('customer-editor').showModal();}
  function setCategory(value){category=value;section.querySelectorAll('[data-customer-category]').forEach(item=>{const active=item.dataset.customerCategory===value;item.classList.toggle('is-active',active);item.setAttribute('aria-selected',String(active));});}
  $('customer-new').addEventListener('click',()=>open(null));
  section.querySelectorAll('[data-customer-category]').forEach(tab=>tab.addEventListener('click',()=>{setCategory(tab.dataset.customerCategory);load(true);}));
  $('customer-cancel').addEventListener('click',()=>$('customer-editor').close());
  $('customer-search').addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>load(true),220);});
  $('customer-load-more').addEventListener('click',()=>load(false));
  form.addEventListener('submit',async event=>{
    event.preventDefault();const button=form.querySelector('[type="submit"]');button.disabled=true;
    try{const response=await fetch(base+'/api/pos/customer/save',{method:'POST',headers:{'X-CSRF-Token':csrf},body:new FormData(form)});const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to save customer.');$('customer-editor').close();setCategory('manager_registered');await load(true);}
    catch(error){$('customer-editor-status').textContent=error.message;}finally{button.disabled=false;}
  });
  load(true);
  setInterval(refreshVisibleCustomers,15000);
})();
