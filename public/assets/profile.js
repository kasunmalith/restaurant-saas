(() => {
  const form=document.querySelector('#profile-form');
  if(!form)return;
  const preview=document.querySelector('#profile-logo-preview');
  const image=document.querySelector('#profile-logo-image');
  const remove=preview?.querySelector('.admin-image-remove');
  const message=form.querySelector('[role=status]');
  const backgroundField=form.querySelector('[data-menu-background]');
  const backgroundInput=form.elements.menu_background;
  const backgroundPreview=backgroundField?.querySelector('figure');
  const backgroundImage=backgroundPreview?.querySelector('img');
  const backgroundRemove=form.elements.remove_menu_background;
  const backgroundRemoveLabel=backgroundField?.querySelector('.admin-menu-background-remove');
  let backgroundUrl=null;
  const clearBackgroundUrl=()=>{if(backgroundUrl){URL.revokeObjectURL(backgroundUrl);backgroundUrl=null;}};
  form.elements.menu_layout?.addEventListener('change',()=>{backgroundField.hidden=form.elements.menu_layout.value!=='2';});
  backgroundInput?.addEventListener('change',()=>{clearBackgroundUrl();const file=backgroundInput.files[0];if(!file)return;backgroundRemove.checked=false;backgroundUrl=URL.createObjectURL(file);backgroundImage.src=backgroundUrl;backgroundPreview.hidden=false;backgroundRemoveLabel.hidden=false;message.textContent='Save Profile to upload this texture.';});
  backgroundRemove?.addEventListener('change',()=>{if(backgroundRemove.checked){backgroundInput.value='';clearBackgroundUrl();backgroundPreview.hidden=true;}});
  let localUrl=null;
  const clearLocal=()=>{if(localUrl){URL.revokeObjectURL(localUrl);localUrl=null;}};
  form.elements.logo?.addEventListener('change',()=>{
    clearLocal();
    const file=form.elements.logo.files[0];
    if(!file)return;
    form.elements.remove_logo.value='0';
    localUrl=URL.createObjectURL(file);
    image.src=localUrl;
    preview.hidden=false;
    message.textContent='Save Profile to upload this logo.';
  });
  remove?.addEventListener('click',()=>{
    clearLocal();
    form.elements.logo.value='';
    form.elements.remove_logo.value='1';
    preview.hidden=true;
    image.removeAttribute('src');
    message.textContent='Logo will be removed when you save.';
  });
  form.onsubmit=async event=>{
    event.preventDefault();
    const button=form.querySelector('button[type="submit"],button:not([type])');
    button.disabled=true;
    const uploading=Boolean(form.elements.logo.files.length||backgroundInput?.files.length);
    const done=uploading?window.beginImageUpload('Uploading restaurant images…'):()=>{};
    try{
      const response=await fetch(base+'/api/admin/profile',{method:'POST',body:new FormData(form)});
      const result=await response.json();
      if(!response.ok||result.error)throw Error(result.error||'Unable to save.');
      done();clearLocal();clearBackgroundUrl();
      form.elements.logo.value='';form.elements.remove_logo.value='0';
      backgroundInput.value='';backgroundRemove.checked=false;
      if(result.logo_url){image.src=result.logo_url;preview.hidden=false;}
      else{image.removeAttribute('src');preview.hidden=true;}
      if(result.background_url){backgroundImage.src=result.background_url;backgroundPreview.hidden=false;backgroundRemoveLabel.hidden=false;}
      else{backgroundImage.removeAttribute('src');backgroundPreview.hidden=true;backgroundRemoveLabel.hidden=true;}
      message.textContent='';
      await window.showAdminPopup('Restaurant profile saved successfully.');
      await window.refreshAdminMenu?.();
    }catch(error){done();message.textContent='';await window.showAdminPopup(error.message,'error');}
    finally{done();button.disabled=false;}
  };
})();
