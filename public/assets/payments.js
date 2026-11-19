document.querySelectorAll('[data-payment-form]').forEach(form=>form.addEventListener('submit',async event=>{
 event.preventDefault();const button=form.querySelector('button'),status=form.querySelector('[role=status]');button.disabled=true;
 try{const response=await fetch(base+'/api/owner/payment',{method:'POST',body:new FormData(form)});const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to record payment.');status.textContent='Payment recorded. Refresh to view history.';form.querySelector('[name=amount]').value='';form.querySelector('[name=reference]').value='';}catch(error){status.textContent=error.message;}finally{button.disabled=false;}
}));
