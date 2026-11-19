const ownerCreateForm=document.querySelector('#owner-create');
if(ownerCreateForm){
 const restaurantName=ownerCreateForm.elements.name,restaurantSlug=ownerCreateForm.elements.slug;let slugWasEdited=false;
 const makeSlug=value=>value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[\u0027\u2019\u0060]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
 restaurantName.addEventListener('input',()=>{if(!slugWasEdited)restaurantSlug.value=makeSlug(restaurantName.value);});
 restaurantSlug.addEventListener('input',()=>{slugWasEdited=true;});
 restaurantSlug.addEventListener('blur',()=>{restaurantSlug.value=makeSlug(restaurantSlug.value);});
 ownerCreateForm.onsubmit=async event=>{
  event.preventDefault();const form=event.target,button=form.querySelector('button'),message=form.querySelector('[role=status]');restaurantSlug.value=makeSlug(restaurantSlug.value||restaurantName.value);if(!restaurantSlug.value){message.textContent='Enter a restaurant name that can be converted to an English-letter or number URL slug.';restaurantSlug.focus();return;}button.disabled=true;
  try {const response=await fetch(base+'/api/owner/restaurant',{method:'POST',body:new FormData(form)});const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to save restaurant.');form.reset();slugWasEdited=false;message.textContent='Restaurant and administrator created successfully.';}catch(error){message.textContent=error.message;}finally{button.disabled=false;}
 };
}
