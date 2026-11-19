(()=>{
 const select=document.querySelector('#owner-inventory-restaurant');
 const panel=document.querySelector('#owner-inventory-state');
 if(!select||!panel)return;
 const state=panel.querySelector('[data-portal-state]'),manager=panel.querySelector('[data-manager-email]'),message=panel.querySelector('[data-portal-message]');
 const enable=panel.querySelector('[data-portal-enable]'),regenerate=panel.querySelector('[data-portal-regenerate]'),disable=panel.querySelector('[data-portal-disable]');
 const secret=panel.querySelector('[data-portal-secret]'),password=panel.querySelector('[data-portal-password]');
 const newPassword=panel.querySelector('[data-portal-new-password]'),togglePassword=panel.querySelector('[data-portal-toggle-password]');
 const hideSecret=()=>{secret.hidden=true;password.value='';};
 const selected=()=>select.selectedOptions[0];
 const sync=()=>{
  hideSecret();
  const option=selected(),valid=Boolean(option?.value);
  panel.hidden=!valid;
  if(!valid)return;
  const enabled=option.dataset.enabled==='1',restaurantActive=option.dataset.restaurantActive==='1',managerActive=option.dataset.managerActive==='1';
  const hasManager=Boolean(option.dataset.managerEmail);
  state.textContent=enabled?'Enabled':'Disabled';
  state.classList.toggle('is-enabled',enabled);
  manager.textContent=(option.dataset.managerEmail||'No Manager account found')+(hasManager&&!managerActive?' (inactive)':'');
  enable.hidden=enabled;regenerate.hidden=!enabled;disable.hidden=!enabled;
  enable.disabled=!restaurantActive||!managerActive;
  regenerate.disabled=!restaurantActive||!managerActive;
  disable.disabled=false;
  if(!restaurantActive)message.textContent='This restaurant is suspended. Activate it before enabling the portal.';
  else if(!hasManager)message.textContent='This restaurant has no Manager login account.';
  else if(!managerActive)message.textContent='The Manager account is inactive. Activate it before enabling the portal.';
  else message.textContent=enabled?'The separate portal password is active. Set a new one or leave the field blank to generate a replacement.':'Enable the portal and choose a separate password. The Manager SaaS password will not change.';
 };
 select.addEventListener('change',()=>{newPassword.value='';newPassword.type='password';togglePassword.textContent='Show';sync();});
 const perform=async(action,button)=>{
  const option=selected();if(!option?.value)return;
  const requestedPassword=newPassword.value;
  if(action!=='disable'&&requestedPassword&&Array.from(requestedPassword).length<6){message.textContent='Use at least 6 characters for the Inventory Portal password.';newPassword.focus();return;}
  if(action!=='disable'&&requestedPassword&&new TextEncoder().encode(requestedPassword).length>72){message.textContent='The Inventory Portal password cannot exceed 72 bytes.';newPassword.focus();return;}
  if(action==='regenerate'&&!await window.showAdminConfirm((requestedPassword?'Set a new':'Generate a new')+' Inventory Portal password for '+option.textContent+'? The current portal password will stop working.'))return;
  if(action==='disable'&&!await window.showAdminConfirm('Disable the Inventory & Profit Portal for '+option.textContent+'? Portal sign-ins and current sessions will be blocked.'))return;
  const oldText=button.textContent;button.disabled=true;message.textContent='Updating portal access…';
  try{
   const payload=new FormData();payload.set('csrf',select.dataset.csrf||'');payload.set('restaurant_id',option.value);payload.set('action',action==='enable'?'enable_generate':action==='regenerate'?'regenerate':'disable');payload.set('password',requestedPassword);
   const response=await fetch(base+'/api/owner/inventory-portal',{method:'POST',body:payload}),result=await response.json();
   if(!response.ok||result.error||!result.ok)throw Error(result.error||'Unable to update Inventory Portal access.');
   if(action==='disable'){
    option.dataset.enabled='0';newPassword.value='';sync();message.textContent='Inventory & Profit Portal is disabled for '+option.textContent+'.';return;
   }
   option.dataset.enabled='1';option.dataset.managerEmail=result.manager_email||option.dataset.managerEmail;newPassword.value='';sync();
   password.value=result.password||'';secret.hidden=false;message.textContent='Portal enabled for '+(result.restaurant_name||option.textContent)+'. The active Manager has been granted portal Owner access.';
  }catch(error){message.textContent=error.message;}
  finally{button.disabled=false;button.textContent=oldText;}
 };
 enable.addEventListener('click',()=>perform('enable',enable));
 regenerate.addEventListener('click',()=>perform('regenerate',regenerate));
 disable.addEventListener('click',()=>perform('disable',disable));
 panel.querySelector('[data-portal-hide]').addEventListener('click',hideSecret);
 togglePassword.addEventListener('click',()=>{const showing=newPassword.type==='text';newPassword.type=showing?'password':'text';togglePassword.textContent=showing?'Show':'Hide';});
 panel.querySelector('[data-portal-copy]').addEventListener('click',async event=>{
  const button=event.currentTarget;
  try{await navigator.clipboard.writeText(password.value);button.textContent='Copied';setTimeout(()=>button.textContent='Copy Password',1600);}
  catch{password.focus();password.select();message.textContent='Password selected. Press Ctrl+C to copy it.';}
 });
 sync();
})();
