window.refreshAdminMenu=async()=>{
 const response=await fetch(location.pathname+location.search,{credentials:'same-origin',cache:'no-store',headers:{Accept:'text/html'}});
 if(!response.ok)throw Error('The updated menu could not be loaded.');
 const next=new DOMParser().parseFromString(await response.text(),'text/html');
 const categories=next.querySelector('#categories'),categoryList=next.querySelector('[data-category-list]');
 const menu=next.querySelector('#menu'),status=next.querySelector('#item-status'),availability=next.querySelector('#availability'),menuData=next.querySelector('#menu-data');
 if(!categories||!categoryList||!menu||!status||!availability||!menuData)throw Error('The updated menu could not be loaded. Please sign in again.');
 const subsection=next.createElement('div');subsection.className='backend-subsection';
 while(categoryList.firstChild)subsection.append(categoryList.firstChild);
 categories.append(subsection);
 const statusSubsection=next.createElement('div');statusSubsection.className='backend-subsection';
 while(status.firstChild)statusSubsection.append(status.firstChild);
 availability.querySelector('h2').textContent='Menu Management';
 availability.append(statusSubsection);
 const sections=[['categories',categories],['menu',menu],['availability',availability]];
 const targets=sections.map(([id])=>document.getElementById(id));
 if(targets.some(target=>!target))throw Error('The menu is no longer available on this page.');
 const scrollTop=window.scrollY;
 document.querySelector('.admin-edit-dialog')?.remove();
 document.querySelector('.admin-photo-crop-dialog')?.remove();
 sections.forEach(([,source],index)=>targets[index].replaceChildren(...[...source.childNodes].map(node=>document.importNode(node,true))));
 document.querySelector('#menu-data').textContent=menuData.textContent;
 window.initAdminMenu();
 window.scrollTo(0,scrollTop);
};
window.initAdminMenu=()=>{
 const form=document.querySelector('#item-form');
 if(!form)return;
 const canAddMenuItem=form.dataset.canAddMenuItem!=='0',canEditMenuItem=form.dataset.canEditMenuItem!=='0',canDeleteMenuItem=form.dataset.canDeleteMenuItem!=='0';
 const data=JSON.parse(document.querySelector('#menu-data').textContent);
 const categoryLayout=form.dataset.menuLayout==='2';
 const rows=document.querySelectorAll('#menu table tr');
 const statusForms=[...document.querySelectorAll('[data-item-status]')];
 const editDialog=document.createElement('dialog');
 editDialog.className='admin-edit-dialog';
editDialog.innerHTML='<div class="admin-edit-dialog-head"><div><small>MENU MANAGEMENT</small><h2>Edit Food Item</h2><p>Update item details, photos and pricing options.</p></div><button type="button" class="admin-dialog-close" aria-label="Close edit popup">×</button></div><form class="admin-edit-form"><input type="hidden" name="csrf"><input type="hidden" name="id"><input type="hidden" name="remove_photo" value="0"><input type="hidden" name="price"><div class="admin-edit-media-row"><label class="admin-edit-photo">Food photo <span>Optional — leave empty to keep the current photo</span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label><figure class="admin-current-image admin-edit-current-image" id="edit-photo-preview" hidden><div class="admin-current-image-frame"><img id="edit-photo-image" alt="Current food photo"><button type="button" class="admin-image-remove" aria-label="Remove food photo" title="Remove photo">×</button></div><figcaption>Current food photo</figcaption></figure></div><label>Category<select name="category_id" required></select></label><label>Item name<input name="name" maxlength="150" required></label><section class="admin-size-options"><div class="admin-size-head"><div><h3>Portion / Size options</h3><p>Add custom sizes and individual prices for this item.</p></div><button type="button" class="admin-add-size">+ Add Option</button></div><div class="admin-size-list"></div></section><label class="admin-edit-description">Description<textarea name="description" maxlength="2000"></textarea></label><div class="admin-edit-actions"><button type="button" class="admin-dialog-cancel">Cancel</button><button type="submit">Save Changes</button></div><p role="status"></p></form>';
 document.body.append(editDialog);
 const editForm=editDialog.querySelector('form');
 if(categoryLayout){editDialog.querySelector('.admin-edit-media-row').style.display='none';editForm.elements.photo.disabled=true;editForm.elements.remove_photo.disabled=true;}
 editForm.elements.csrf.value=form.elements.csrf.value;
 editForm.elements.category_id.replaceChildren(...[...form.elements.category_id.options].map(option=>option.cloneNode(true)));
 const syncPortions=target=>{
  const enabled=target.elements.is_portioned.checked;
  const single=target.querySelector('.admin-single-price');
  if(single)single.hidden=enabled;
  else target.elements.price.hidden=enabled;
  target.elements.price.disabled=enabled;target.elements.price.required=!enabled;
  const split=target.querySelector('.admin-portion-prices');split.hidden=!enabled;
  for(const name of ['half_price','full_price']){target.elements[name].disabled=!enabled;target.elements[name].required=enabled;}
 };
 form.elements.is_portioned.addEventListener('change',()=>syncPortions(form));syncPortions(form);
 const sizeList=editForm.querySelector('.admin-size-list');
 const addSize=(name='',price='',isDefault=false)=>{
   if(sizeList.children.length>=8)return;
   const row=document.createElement('div');row.className='admin-size-row';
   const move=document.createElement('button');move.type='button';move.className='admin-size-move';move.title='Move option up';move.setAttribute('aria-label','Move option up');move.textContent='⋮⋮';move.onclick=()=>{const before=row.previousElementSibling;if(before)sizeList.insertBefore(row,before);};
   const label=document.createElement('input');label.type='text';label.className='admin-size-name';label.maxLength=40;label.placeholder='Size name';label.required=true;label.value=name;label.setAttribute('aria-label','Size name');
   const priceWrap=document.createElement('div');priceWrap.className='admin-size-price';const currency=document.createElement('span');currency.textContent='LKR';const amount=document.createElement('input');amount.type='number';amount.step='0.01';amount.min='0.01';amount.required=true;amount.placeholder='0.00';amount.value=price;amount.setAttribute('aria-label','Option price in LKR');priceWrap.append(currency,amount);
   const defaultLabel=document.createElement('label');defaultLabel.className='admin-size-default';const radio=document.createElement('input');radio.type='radio';radio.name='size_default';radio.value='1';radio.checked=isDefault||sizeList.children.length===0;defaultLabel.append(radio,document.createTextNode('Default'));
   const remove=document.createElement('button');remove.type='button';remove.className='admin-size-remove';remove.setAttribute('aria-label','Remove option');remove.innerHTML='<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 4h4m-8 3 1 13h10l1-13M10 11v6m4-6v6"/></svg>';
   remove.onclick=()=>{const selected=radio.checked;row.remove();editForm.querySelector('.admin-add-size').disabled=false;if(selected)sizeList.querySelector('input[type="radio"]')?.click();};
   row.append(move,label,priceWrap,defaultLabel,remove);sizeList.append(row);editForm.querySelector('.admin-add-size').disabled=sizeList.children.length>=8;
 };
 editForm.querySelector('.admin-add-size').onclick=()=>{addSize('', '', false);sizeList.lastElementChild?.querySelector('.admin-size-name')?.focus();};
 const loadSizes=item=>{
   sizeList.replaceChildren();
   const options=item?.options?.length?item.options.map(option=>({name:option.name,price:option.price,is_default:Number(option.is_default)===1})):
     Number(item?.is_portioned)?[{name:'Half',price:item.half_price,is_default:false},{name:'Full',price:item.full_price,is_default:true}]:
     [{name:'Regular',price:item?.price??'',is_default:true}];
   options.forEach(option=>addSize(option.name,option.price,option.is_default));
   const defaultIndex=options.findIndex(option=>option.is_default);
   if(defaultIndex>=0)sizeList.children[defaultIndex]?.querySelector('.admin-size-default input')?.click();
 };
 const dialogTitle=editDialog.querySelector('h2'),dialogSubmit=editForm.querySelector('button[type="submit"]'),photoHint=editDialog.querySelector('.admin-edit-photo span');
 const photoPreview=editDialog.querySelector('#edit-photo-preview'),photoImage=editDialog.querySelector('#edit-photo-image');
 let localPhotoUrl=null,croppedPhotoFile=null,savedPhotoSrc='';
 const clearLocalPhoto=()=>{if(localPhotoUrl){URL.revokeObjectURL(localPhotoUrl);localPhotoUrl=null;}};
 const showPhoto=src=>{if(src){photoImage.src=src;photoPreview.hidden=false;}else{photoImage.removeAttribute('src');photoPreview.hidden=true;}};
 const serverPhoto=path=>path?base+'/'+String(path).replace(/^\/+/, '')+'?v='+Date.now():'';
 editForm.elements.photo.addEventListener('change',async()=>{
   clearLocalPhoto();croppedPhotoFile=null;
   const file=editForm.elements.photo.files[0];
   if(!file)return;
   try{
     const choice=await window.cropFoodPhoto(file);
     if(choice.action==='cancel'){
       editForm.elements.photo.value='';editForm.elements.remove_photo.value='0';showPhoto(savedPhotoSrc);
       editForm.querySelector('[role=status]').textContent='Photo selection cancelled.';
       return;
     }
     croppedPhotoFile=choice.action==='crop'?choice.file:null;
     editForm.elements.remove_photo.value='0';
     localPhotoUrl=URL.createObjectURL(croppedPhotoFile||file);
     showPhoto(localPhotoUrl);
     editForm.querySelector('[role=status]').textContent=croppedPhotoFile?'400 × 400 crop ready. Save Changes to upload.':'Original photo ready. Save Changes to upload.';
   }catch(error){editForm.elements.photo.value='';editForm.elements.remove_photo.value='0';showPhoto(savedPhotoSrc);editForm.querySelector('[role=status]').textContent=error.message;}
 });
 photoPreview.querySelector('.admin-image-remove').addEventListener('click',()=>{
   clearLocalPhoto();croppedPhotoFile=null;editForm.elements.photo.value='';editForm.elements.remove_photo.value='1';showPhoto('');
   editForm.querySelector('[role=status]').textContent='Photo will be removed when you save.';
 });
 editDialog.addEventListener('close',clearLocalPhoto);
 const closeEdit=()=>editDialog.close();
 editDialog.querySelector('.admin-dialog-close').onclick=closeEdit;
 editDialog.querySelector('.admin-dialog-cancel').onclick=closeEdit;
 const addBar=document.createElement('div');addBar.className='admin-menu-toolbar';
 const sortLabel=document.createElement('label');sortLabel.className='admin-menu-sort';sortLabel.textContent='Sort items';
 const sortSelect=document.createElement('select');sortSelect.setAttribute('aria-label','Sort menu items');
 for(const [value,text] of [['added','Added order (oldest first)'],['name','Name: A–Z'],['price','Price: High to Low']]){const option=document.createElement('option');option.value=value;option.textContent=text;sortSelect.append(option);}
 sortSelect.value=['added','name','price'].includes(window.adminMenuSort)?window.adminMenuSort:'added';
 sortSelect.title='Items with size options are sorted by their default option price.';
 sortLabel.append(sortSelect);addBar.append(sortLabel);
 const searchLabel=document.createElement('label');searchLabel.className='admin-menu-search';searchLabel.textContent='Search menu items';
 const searchInput=document.createElement('input');searchInput.type='search';searchInput.placeholder='Search by item or category';searchInput.setAttribute('aria-label','Search menu items by name or category');searchLabel.append(searchInput);addBar.append(searchLabel);
 const addTrigger=document.createElement('button');addTrigger.type='button';addTrigger.textContent='+ Add Food Item';if(canAddMenuItem)addBar.append(addTrigger);form.before(addBar);form.hidden=true;
 if(canAddMenuItem)addTrigger.onclick=()=>{
   editForm.reset();editForm.elements.csrf.value=form.elements.csrf.value;editForm.elements.id.value='';
   clearLocalPhoto();croppedPhotoFile=null;savedPhotoSrc='';showPhoto('');editForm.elements.remove_photo.value='0';
   loadSizes(null);
   dialogTitle.textContent='Add Food Item';dialogSubmit.textContent='Add Food Item';photoHint.textContent='Optional — JPEG, PNG or WebP up to 5 MB';editForm.querySelector('[role=status]').textContent='';
   editDialog.showModal();editForm.elements.category_id.focus();
 };
 const media=item=>{
   const wrap=document.createElement('span');wrap.className='admin-menu-item';
   const thumb=document.createElement('span');thumb.className='admin-menu-thumb';
   const placeholder=()=>{thumb.replaceChildren();thumb.classList.add('is-placeholder');thumb.textContent='🍽';};
   if(item.image_path){const image=document.createElement('img');image.src=base+'/'+String(item.image_path).replace(/^\/+/, '');image.alt=item.name;image.loading='lazy';image.onerror=placeholder;thumb.append(image);}else placeholder();
   const name=document.createElement('strong');name.textContent=item.name;
   if(!categoryLayout)wrap.append(thumb);wrap.append(name);return wrap;
 };
 data.forEach((item,index)=>{
   const itemCell=rows[index+1]?.cells[0];
   if(itemCell)itemCell.replaceChildren(media(item));
   const cell=document.createElement('td'),edit=document.createElement('button');
   edit.type='button';edit.textContent='Edit';
   edit.onclick=()=>{for(const field of ['id','category_id','name','price','description'])editForm.elements[field].value=item[field]??'';loadSizes(item);clearLocalPhoto();croppedPhotoFile=null;editForm.elements.photo.value='';editForm.elements.remove_photo.value='0';savedPhotoSrc=serverPhoto(item.image_path);showPhoto(savedPhotoSrc);dialogTitle.textContent='Edit Food Item';dialogSubmit.textContent='Save Changes';photoHint.textContent='Optional — leave empty to keep the current photo';editForm.querySelector('[role=status]').textContent='';editDialog.showModal();editForm.elements.name.focus();};
   if(canEditMenuItem){cell.append(edit);rows[index+1]?.append(cell);}
 });
 const menuTable=document.querySelector('#menu table');
 if(menuTable&&data.length){
   const grid=document.createElement('div');grid.className='admin-menu-management-grid';
   data.forEach((item,index)=>{
     const row=rows[index+1];if(!row)return;
     const card=document.createElement('article');card.className='admin-product-card';card.dataset.itemId=item.id;
     card.append(media(item));
     const category=document.createElement('span');category.className='admin-product-category';category.textContent=item.category_name;
     const price=document.createElement('strong');price.className='admin-product-price';const format=value=>'LKR '+Number(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});price.textContent=item.options?.length?item.options.map(option=>option.name+' '+format(option.price)).join(' · '):Number(item.is_portioned)?'Half '+format(item.half_price)+' · Full '+format(item.full_price):format(item.price);
     const status=document.createElement('span');status.className='admin-product-status '+(Number(item.is_available)?'':'is-off');status.textContent=Number(item.is_available)?'Available':'Unavailable';
     const statusForm=statusForms[index];
     statusForm?.querySelector('[data-item-name]')?.remove();
     const edit=row.querySelector('button'),actions=document.createElement('div'),remove=document.createElement('button');
     actions.className='admin-product-actions';remove.type='button';remove.className='admin-item-delete';remove.textContent='Delete';
     remove.onclick=async()=>{
       if(!await window.showAdminConfirm(`Permanently delete ${item.name}? Existing order history will be kept.`))return;
       remove.disabled=true;const done=window.startAjaxButtonLoading(remove);
       try{
         const payload=new FormData();payload.append('csrf',form.elements.csrf.value);payload.append('id',item.id);
         const response=await fetch(base+'/api/admin/item/delete',{method:'POST',body:payload}),result=await response.json();
         if(!response.ok||result.error)throw Error(result.error||'Unable to delete food item.');
         done();await window.refreshAdminMenu();await window.showAdminPopup(`${item.name} was permanently deleted.`);
       }catch(error){done();remove.disabled=false;await window.showAdminPopup(error.message,'error');}
     };
     if(edit)actions.append(edit);if(canDeleteMenuItem)actions.append(remove);card.append(category,price,status);if(statusForm)card.append(statusForm);if(actions.children.length)card.append(actions);grid.append(card);
   });
   const itemsById=new Map(data.map(item=>[String(item.id),item]));
   const groups=document.createElement('div');groups.className='admin-menu-category-groups';
   const categoryGrids=[];
   for(const option of form.elements.category_id.options){
     if(!option.value)continue;
     const cards=[...grid.children].filter(card=>String(itemsById.get(card.dataset.itemId).category_id)===option.value);
     if(!cards.length)continue;
     const section=document.createElement('section');section.className='admin-menu-category-group';
     const heading=document.createElement('h3');heading.className='admin-menu-category-heading';heading.textContent=option.textContent;
     const count=document.createElement('span');count.textContent=String(cards.length);heading.append(count);
     const categoryGrid=document.createElement('div');categoryGrid.className='admin-menu-management-grid';categoryGrid.append(...cards);
     section.append(heading,categoryGrid);groups.append(section);categoryGrids.push(categoryGrid);
   }
   menuTable.before(groups);menuTable.hidden=true;
   const filterCards=()=>{
     const query=searchInput.value.trim().toLocaleLowerCase();let visible=0;
     groups.querySelectorAll('.admin-menu-category-group').forEach(section=>{
       const categoryVisible=[...section.querySelectorAll('.admin-product-card')].filter(card=>{
         const item=itemsById.get(card.dataset.itemId);
         const matches=!query||String(item?.name||'').toLocaleLowerCase().includes(query)||String(item?.category_name||'').toLocaleLowerCase().includes(query);
         card.hidden=!matches;if(matches)visible++;return matches;
       }).length;
       section.hidden=categoryVisible===0;
     });
     searchInput.setAttribute('aria-description',query?`${visible} matching menu item${visible===1?'':'s'}`:'All menu items shown');
   };
   searchInput.addEventListener('input',filterCards);
   const sortCards=()=>{
     const mode=sortSelect.value;
     const byAdded=(a,b)=>Number(a.id)-Number(b.id);
     const priceOf=item=>Number(item.options?.length?item.options.find(option=>Number(option.is_default)===1)?.price??item.options[0].price:Number(item.is_portioned)?item.full_price:item.price);
     categoryGrids.forEach(categoryGrid=>{
       const cards=[...categoryGrid.children];
       cards.sort((left,right)=>{const a=itemsById.get(left.dataset.itemId),b=itemsById.get(right.dataset.itemId);return (mode==='name'?a.name.localeCompare(b.name,undefined,{sensitivity:'base',numeric:true}):mode==='price'?priceOf(b)-priceOf(a):0)||byAdded(a,b);});
       categoryGrid.append(...cards);
     });
     window.adminMenuSort=mode;
   };
   sortSelect.addEventListener('change',sortCards);sortCards();
 }
 document.querySelectorAll('.availability label').forEach((label,index)=>{if(data[index])label.prepend(media(data[index]));});
 document.querySelector('#item-status')?.remove();
 const updateState=(state,isOn,onText,offText)=>{state.textContent=isOn?onText:offText;state.classList.toggle('is-off',!isOn);};
 document.querySelectorAll('[data-item-availability-toggle]').forEach(toggle=>toggle.addEventListener('change',async()=>{
   const form=toggle.closest('[data-item-status]'),state=form?.querySelector('[data-availability-state]');if(!form||!state)return;
   const available=toggle.checked?1:0;toggle.disabled=true;
   const silent=toggle.dataset.silent==='1';delete toggle.dataset.silent;
   try{const payload=new FormData();payload.append('csrf',form.elements.csrf.value);payload.append('item_id',toggle.dataset.itemId);payload.append('available',String(available));const response=await fetch(base+'/api/cashier/availability',{method:'POST',body:payload}),result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to update availability.');updateState(state,Boolean(available),'Available','Unavailable');const card=document.querySelector('.admin-menu-management-grid [data-item-id="'+CSS.escape(toggle.dataset.itemId)+'"] .admin-product-status');if(card)updateState(card,Boolean(available),'Available','Unavailable');if(!silent)await window.showAdminPopup('Ordering availability updated.');}
   catch(error){toggle.checked=!toggle.checked;state.textContent=error.message;state.classList.add('is-off');if(!silent)await window.showAdminPopup(error.message,'error');}
   finally{toggle.disabled=false;}
 }));
 document.querySelectorAll('[data-item-visibility-toggle]').forEach(toggle=>toggle.addEventListener('change',async()=>{
   const form=toggle.closest('[data-item-status]'),state=form?.querySelector('[data-visibility-state]'),availableToggle=form?.querySelector('[data-item-availability-toggle]'),availabilityState=form?.querySelector('[data-availability-state]');if(!form||!state)return;
   const active=toggle.checked?1:0;toggle.disabled=true;
   try{const payload=new FormData();payload.append('csrf',form.elements.csrf.value);payload.append('id',form.elements.id.value);payload.append('is_active',String(active));const response=await fetch(base+'/api/admin/item/status',{method:'POST',body:payload}),result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to update menu visibility.');updateState(state,Boolean(active),'Visible','Hidden');if(!active&&availableToggle){availableToggle.checked=false;availableToggle.dataset.silent='1';availableToggle.dispatchEvent(new Event('change',{bubbles:true}));}else if(!active&&availabilityState)updateState(availabilityState,false,'Available','Unavailable');await window.showAdminPopup(active?'Item is visible on the customer menu.':'Item is hidden from the customer menu.');}
   catch(error){toggle.checked=!toggle.checked;state.textContent=error.message;state.classList.add('is-off');await window.showAdminPopup(error.message,'error');}
   finally{toggle.disabled=false;}
 }));
 async function submit(target,url,result){
   const button=target.querySelector('button[type="submit"]')||target.querySelector('button:not([type])');
   const editing=target===editForm&&Boolean(target.elements.id.value);
   const uploading=Boolean(target.querySelector('input[type="file"]')?.files.length||target===editForm&&croppedPhotoFile);
   const done=uploading?window.beginImageUpload('Uploading food photo…'):()=>{};
   button.disabled=true;result.textContent=uploading?'Uploading photo…':'Saving…';
   let saved=false;
   try{
     const payload=new FormData(target);
     if(target===editForm){
       const options=[...sizeList.children].map(row=>({name:row.querySelector('.admin-size-name').value.trim(),price:row.querySelector('.admin-size-price input').value,default:row.querySelector('.admin-size-default input').checked}));
       if(!options.length||!options.some(option=>option.default))throw Error('Add at least one size and choose a default.');
       payload.set('price',options.find(option=>option.default).price);
       payload.set('options',JSON.stringify(options));
     }
     if(target===editForm&&croppedPhotoFile)payload.set('photo',croppedPhotoFile);
     const response=await fetch(url,{method:'POST',body:payload});
     const value=await response.json();
     if(!response.ok||value.error)throw Error(value.error||'Unable to save.');
     saved=true;
     done();
     result.textContent='';
     if(target===editForm)editDialog.close();
     await window.refreshAdminMenu();
     await window.showAdminPopup(target===category?'Category added successfully.':editing?'Food item updated successfully.':'Food item added successfully.');
   }catch(error){done();result.textContent='';await window.showAdminPopup(saved?'Saved, but the menu could not refresh automatically. Please refresh this page.':error.message,'error');}
   finally{done();button.disabled=false;}
 }
 editForm.onsubmit=event=>{event.preventDefault();submit(editForm,base+'/api/admin/item',editForm.querySelector('[role=status]'));};
 form.onsubmit=event=>{event.preventDefault();submit(form,base+'/api/admin/item',document.querySelector('#item-result'));};
 const category=document.querySelector('#category-form');
 if(category)category.onsubmit=event=>{event.preventDefault();submit(category,base+'/api/admin/category',category.querySelector('[role=status]'));};
};
window.initAdminMenu();
