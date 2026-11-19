(() => {
 const toggle=document.querySelector('#online-ordering-toggle');
 const state=document.querySelector('#online-ordering-state');
 const feedback=document.querySelector('#online-ordering-feedback');
 if(!toggle||!state||!feedback)return;
 const paint=enabled=>{state.textContent=enabled?'ON':'OFF';};
 toggle.addEventListener('change',async()=>{
  const enabled=toggle.checked;
  toggle.disabled=true;feedback.textContent='Saving…';
  const data=new FormData();data.set('enabled',enabled?'1':'0');data.set('csrf',toggle.dataset.csrf||'');
  try{
   const response=await fetch(toggle.dataset.endpoint,{method:'POST',body:data,credentials:'same-origin'});
   const result=await response.json();
   if(!response.ok||!result.ok)throw Error(result.error||'Unable to save online ordering.');
   paint(Boolean(result.enabled));feedback.textContent=enabled?'Online ordering turned ON.':'Online ordering turned OFF. The menu remains visible.';
  }catch(error){toggle.checked=!enabled;paint(toggle.checked);feedback.textContent=error.message;}
  finally{toggle.disabled=false;}
 });
})();
