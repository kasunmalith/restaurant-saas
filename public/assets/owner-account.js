const ownerAccountForm=document.querySelector('#owner-account-form');
if(ownerAccountForm)ownerAccountForm.onsubmit=async event=>{
 event.preventDefault();
 const form=event.currentTarget,button=form.querySelector('button'),message=form.querySelector('[role=status]');
 const newPassword=form.elements.new_password.value,confirmation=form.elements.confirm_password.value;
 if(newPassword!==confirmation){message.textContent='New password and confirmation do not match.';return;}
 button.disabled=true;message.textContent='';
 try{
  const response=await fetch(base+'/api/owner/account',{method:'POST',body:new FormData(form)});
  const result=await response.json();
  if(!response.ok||result.error)throw Error(result.error||'Unable to update login details.');
  form.elements.current_password.value='';
  form.elements.new_password.value='';
  form.elements.confirm_password.value='';
  message.textContent='Login details updated successfully.';
 }catch(error){message.textContent=error.message;}
 finally{button.disabled=false;}
};
