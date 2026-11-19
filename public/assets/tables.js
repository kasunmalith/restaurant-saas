async function addAdminTable(event){
  event.preventDefault();
  const form=event.currentTarget;
  const button=event.submitter||form.querySelector('button');
  const status=document.querySelector('#table-result');
  if(button)button.disabled=true;
  try{
    const response=await fetch(base+'/api/admin/table',{method:'POST',body:new FormData(form)});
    const result=await response.json();
    if(!response.ok||result.error)throw Error(result.error||'Unable to add table.');
    form.reset();
    const pageResponse=await fetch(location.pathname+location.search,{credentials:'same-origin',cache:'no-store'});
    if(!pageResponse.ok)throw Error('Table created, but the list could not update. Refresh the page to see it.');
    const page=new DOMParser().parseFromString(await pageResponse.text(),'text/html');
    const freshForms=[...page.querySelectorAll('#tables [data-table-edit]')];
    const newIndex=freshForms.findIndex(row=>row.querySelector('[name=id]')?.value===String(result.id));
    if(newIndex<0)throw Error('Table created, but the list could not update. Refresh the page to see it.');
    const section=document.querySelector('#tables');
    const newRow=document.importNode(freshForms[newIndex],true);
    let nextRow=null;
    for(const row of freshForms.slice(newIndex+1)){
      const id=row.querySelector('[name=id]')?.value;
      nextRow=[...section.querySelectorAll('[data-table-edit]')].find(current=>current.querySelector('[name=id]')?.value===id);
      if(nextRow)break;
    }
    section.insertBefore(newRow,nextRow);
    if(status)status.textContent='';
    await window.showAdminPopup('Table added successfully.');
  }catch(error){
    if(status)status.textContent='';
    await window.showAdminPopup(error.message||'Unable to add table.','error');
  }finally{
    if(button)button.disabled=false;
  }
}

document.addEventListener('submit',async event=>{
  const form=event.target;
  if(!(form instanceof HTMLFormElement)||!form.matches('[data-table-edit]'))return;
  event.preventDefault();
  const button=event.submitter,status=form.querySelector('[role=status]');
  form.querySelectorAll('button').forEach(item=>item.disabled=true);
  try{
    const data=new FormData(form),route=button?.value==='regenerate'?'/api/admin/table/regenerate':'/api/admin/table/update';
    const response=await fetch(base+route,{method:'POST',body:data}),result=await response.json();
    if(!response.ok||result.error)throw Error(result.error||'Unable to save the table.');
    if(result.qr_url){status.innerHTML='New QR created. <a target="_blank" rel="noopener" href="'+result.qr_url+'">PNG</a> · <a target="_blank" rel="noopener" href="'+result.pdf_url+'">PDF</a> · <a target="_blank" rel="noopener" href="'+result.menu_url+'">Open menu</a>';await window.showAdminPopup('A new QR code was created for this table.');}
    else {status.textContent='';await window.showAdminPopup('Table details updated successfully.');}
  }catch(error){status.textContent='';await window.showAdminPopup(error.message,'error');}
  finally{form.querySelectorAll('button').forEach(item=>item.disabled=false);}
});
