(() => {
  const hero=document.querySelector('.hero'),menu=document.querySelector('.menu');
  if(!window.APP||!hero||!menu)return;
  document.body.classList.add('customer-menu-page');
  const picker=menu.querySelector('.category-picker'),search=menu.querySelector('#search');
  if(picker&&search){
    const tools=document.createElement('div');
    tools.className='customer-menu-tools';
    picker.before(tools);
    tools.append(search,picker);
    const updateCategoryVisibility=()=>document.body.classList.toggle('menu-has-scrolled',hero.getBoundingClientRect().bottom < -64);
    window.addEventListener('scroll',updateCategoryVisibility,{passive:true});
    updateCategoryVisibility();
  }
  const revealTargets=[...menu.querySelectorAll('section[id^="cat"],.food')];
  if('IntersectionObserver' in window&&!matchMedia('(prefers-reduced-motion: reduce)').matches){
    const observer=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting){entry.target.classList.add('customer-revealed');observer.unobserve(entry.target);}}),{threshold:.08,rootMargin:'0px 0px -30px'});
    revealTargets.forEach((target,index)=>{target.style.setProperty('--customer-reveal-delay',`${(index%6)*45}ms`);target.classList.add('customer-reveal');observer.observe(target);});
  }else revealTargets.forEach(target=>target.classList.add('customer-revealed'));
})();
