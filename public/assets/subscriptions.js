document.querySelectorAll('[data-subscription-form]').forEach(form=>form.onsubmit=async event=>{
 event.preventDefault();const button=form.querySelector('button'),message=form.querySelector('[role=status]');button.disabled=true;
 try{const r=await fetch(base+'/api/admin/subscription',{method:'POST',body:new FormData(form)});const result=await r.json();if(!r.ok||result.error)throw Error(result.error||'Unable to save.');message.textContent='Subscription saved. Existing table QR codes are unchanged.';}catch(error){message.textContent=error.message;}finally{button.disabled=false;}
});
