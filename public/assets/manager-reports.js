(() => {
  const form=document.querySelector('#reports .manager-report-filter');
  if(!form)return;
  const period=form.elements.range,from=form.elements.from,to=form.elements.to;
  period.addEventListener('change',()=>{
    const choice=period.selectedOptions[0];
    if(period.value==='custom')return;
    from.value=choice.dataset.from;
    to.value=choice.dataset.to;
  });
  for(const input of [from,to])input.addEventListener('change',()=>{period.value='custom';});
})();
