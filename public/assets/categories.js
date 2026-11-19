const categoryAnimations=new WeakMap(),categoryTargets=new WeakMap();
const categoryReducedMotion=window.matchMedia('(prefers-reduced-motion: reduce)');
function animateCategory(entry,open){
 const body=entry.querySelector('.admin-category-accordion-body');
 const start=body.hidden?0:body.getBoundingClientRect().height;
 categoryAnimations.get(entry)?.cancel();
 categoryTargets.set(entry,open);
 entry.querySelector('.admin-category-toggle').setAttribute('aria-expanded',String(open));
 if(open)body.hidden=false;
 const end=open?body.scrollHeight:0;
 if(categoryReducedMotion.matches||Math.abs(start-end)<1){body.hidden=!open;categoryAnimations.delete(entry);return;}
 const animation=body.animate([{height:`${start}px`,opacity:open?0:1,transform:open?'translateY(-5px)':'translateY(0)'},{height:`${end}px`,opacity:open?1:0,transform:open?'translateY(0)':'translateY(-5px)'}],{duration:240,easing:'cubic-bezier(.2,.8,.2,1)'});
 categoryAnimations.set(entry,animation);
 animation.onfinish=()=>{if(categoryAnimations.get(entry)!==animation)return;categoryAnimations.delete(entry);body.hidden=!open;};
}
document.addEventListener('click',event=>{
 const toggle=event.target.closest('.admin-category-toggle');
 if(!toggle)return;
 const accordion=toggle.closest('[data-category-accordion]');
 const body=accordion.querySelector('.admin-category-accordion-body');
 const opening=!(categoryTargets.get(accordion)??!body.hidden);
 document.querySelectorAll('[data-category-accordion]').forEach(entry=>{
  if(entry!==accordion&&(categoryTargets.get(entry)??!entry.querySelector('.admin-category-accordion-body').hidden))animateCategory(entry,false);
 });
 animateCategory(accordion,opening);
});
document.addEventListener('submit',async event=>{
 const form=event.target.closest('[data-category-edit]');
 if(!form)return;
 event.preventDefault();const action=event.submitter?.value||'save';
 if(action==='delete'){
  if(form.dataset.confirming==='1')return;
  form.dataset.confirming='1';
  const approved=await window.showAdminConfirm('Delete this empty category?');
  delete form.dataset.confirming;
  if(!approved)return;
 }
 const fields=new FormData(form);fields.set('action',action);
 const buttons=form.closest('[data-category-accordion]').querySelectorAll('button');buttons.forEach(b=>b.disabled=true);
 const uploading=action==='save'&&[...form.querySelectorAll('input[type="file"]')].some(input=>input.files.length);
 const done=uploading?window.beginImageUpload('Uploading category photo…'):action==='delete'?window.startAjaxButtonLoading(event.submitter):()=>{};
 let saved=false;
 try{const response=await fetch(base+'/api/admin/category',{method:'POST',body:fields});const data=await response.json();if(!response.ok||data.error)throw Error(data.error||'Unable to save.');saved=true;await window.refreshAdminMenu();if(action==='save')document.querySelector('[data-category-accordion="'+form.elements.id.value+'"] .admin-category-toggle')?.click();await window.showAdminPopup(action==='delete'?'Category deleted successfully.':'Category updated successfully.');}
 catch(error){form.querySelector('[role=status]').textContent='';await window.showAdminPopup(saved?'Saved, but the menu could not refresh automatically. Please refresh this page.':error.message,'error');}
 finally{done();buttons.forEach(b=>b.disabled=false);}
});
