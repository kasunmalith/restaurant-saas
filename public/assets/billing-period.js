document.querySelectorAll('form').forEach(form=>{
  const period=form.querySelector('select[name="plan"]');
  const label=form.querySelector('[data-fee-label]');
  if(!period||!label)return;
  const update=()=>{label.textContent=(period.value==='Monthly'?'Monthly':'Annual')+' fee (LKR)';};
  const start=form.querySelector('[name="starts_on"]');
  const expiry=form.querySelector('[name="expires_on"]');
  const updateExpiry=()=>{
    if(!start||!expiry||!start.value)return;
    const [year,month,day]=start.value.split('-').map(Number);
    const targetMonth=month-1+(period.value==='Monthly'?1:12);
    const targetYear=year+Math.floor(targetMonth/12);
    const normalizedMonth=targetMonth%12;
    const lastDay=new Date(targetYear,normalizedMonth+1,0).getDate();
    expiry.value=[targetYear,String(normalizedMonth+1).padStart(2,'0'),String(Math.min(day,lastDay)).padStart(2,'0')].join('-');
  };
  period.addEventListener('change',update);
  period.addEventListener('change',updateExpiry);
  start?.addEventListener('change',updateExpiry);
  update();
});
