(() => {
  const loadingButtons=new WeakMap();
  window.startAjaxButtonLoading=button=>{
    if(!(button instanceof HTMLButtonElement))return()=>{};
    let state=loadingButtons.get(button);
    if(!state){
      const wasDisabled=button.disabled;
      const image=document.createElement('img');
      image.className='admin-ajax-loading-gif';
      image.src=base+'/assets/uploading.gif';
      image.alt='';
      image.setAttribute('aria-hidden','true');
      button.append(image);
      button.disabled=true;
      button.classList.add('admin-ajax-loading');
      button.setAttribute('aria-busy','true');
      state={count:0,image,wasDisabled};
      loadingButtons.set(button,state);
    }
    state.count++;
    let released=false;
    return()=>{
      if(released)return;
      released=true;
      if(--state.count>0)return;
      state.image.remove();
      button.classList.remove('admin-ajax-loading');
      button.removeAttribute('aria-busy');
      button.disabled=state.wasDisabled;
      loadingButtons.delete(button);
    };
  };
  let actionButton=null,actionSequence=0;
  const rememberButton=button=>{
    actionButton=button;
    const sequence=++actionSequence;
    queueMicrotask(()=>{if(actionSequence===sequence)actionButton=null;});
  };
  document.addEventListener('click',event=>{
    const button=event.target instanceof Element?event.target.closest('button'):null;
    if(button)rememberButton(button);
  },true);
  document.addEventListener('submit',event=>{
    const button=event.submitter||event.target.querySelector('button[type="submit"],button:not([type])');
    if(button)rememberButton(button);
  },true);
  const nativeFetch=window.fetch;
  window.fetch=function(...args){
    const request=args[0],options=args[1];
    const method=String(options?.method||request?.method||'GET').toUpperCase();
    const requestUrl=typeof request==='string'?request:request?.url;
    let release=null;
    try{
      const url=new URL(requestUrl,location.href);
      if(actionButton&&method!=='GET'&&url.origin===location.origin&&url.pathname.startsWith(base+'/api/'))release=window.startAjaxButtonLoading(actionButton);
      const result=nativeFetch.apply(this,args);
      if(release)result.then(release,release);
      return result;
    }catch(error){release?.();throw error;}
  };
  let activeUploads=0;
  window.beginImageUpload = message => {
    let dialog=document.querySelector('#admin-upload-dialog');
    if(!dialog){
      dialog=document.createElement('dialog');
      dialog.id='admin-upload-dialog';
      dialog.className='admin-upload-dialog';
      dialog.setAttribute('aria-label','Image upload in progress');
      const spinner=document.createElement('img');
      spinner.src=base+'/assets/uploading.gif';
      spinner.alt='';
      const label=document.createElement('strong');
      label.className='admin-upload-message';
      dialog.append(spinner,label);
      dialog.addEventListener('cancel',event=>event.preventDefault());
      document.body.append(dialog);
    }
    dialog.querySelector('.admin-upload-message').textContent=message||'Uploading image…';
    activeUploads++;
    if(!dialog.open)dialog.showModal();
    document.body.setAttribute('aria-busy','true');
    let released=false;
    return()=>{
      if(released)return;
      released=true;activeUploads=Math.max(0,activeUploads-1);
      if(activeUploads===0){dialog.close();document.body.removeAttribute('aria-busy');}
    };
  };
  let popupQueue = Promise.resolve();
  window.showAdminPopup = (message, type = 'success') => {
    popupQueue = popupQueue.then(() => new Promise(resolve => {
      if (type !== 'error') {
        const toast = document.createElement('div');
        toast.className = 'admin-feedback-toast';
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.innerHTML = '<span class="admin-feedback-toast-mark" aria-hidden="true">✓</span><div><strong>Saved</strong><p></p></div><button type="button" aria-label="Dismiss notification">×</button>';
        toast.querySelector('p').textContent = String(message || 'Your changes have been saved.');
        document.body.append(toast);
        let timer;
        const dismiss = () => { clearTimeout(timer); toast.remove(); resolve(); };
        toast.querySelector('button').addEventListener('click', dismiss, { once: true });
        timer = setTimeout(dismiss, 2800);
        return;
      }
      let dialog = document.querySelector('#admin-feedback-dialog');
      if (!dialog) {
        dialog = document.createElement('dialog');
        dialog.id = 'admin-feedback-dialog';
        dialog.className = 'admin-feedback-dialog';
        dialog.setAttribute('aria-labelledby', 'admin-feedback-title');
        dialog.innerHTML = '<div class="admin-feedback-mark" aria-hidden="true"></div><h2 id="admin-feedback-title"></h2><p class="admin-feedback-message"></p><button type="button" class="admin-feedback-close">Done</button>';
        document.body.append(dialog);
        dialog.querySelector('.admin-feedback-close').addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => { dialog.removeAttribute('data-kind'); resolve(); });
      }
      dialog.dataset.kind = type === 'error' ? 'error' : 'success';
      dialog.querySelector('#admin-feedback-title').textContent = type === 'error' ? 'Unable to save' : 'Changes saved';
      dialog.querySelector('.admin-feedback-message').textContent = String(message || (type === 'error' ? 'Please try again.' : 'Your changes have been saved.'));
      dialog.showModal();
      dialog.querySelector('.admin-feedback-close').focus();
    }));
    return popupQueue;
  };
  let confirmQueue = Promise.resolve();
  window.showAdminConfirm = (message, options = {}) => {
    confirmQueue = confirmQueue.then(() => new Promise(resolve => {
      const previousFocus = document.activeElement;
      const panel = document.createElement('div');
      panel.className = 'admin-confirm-panel';
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-labelledby', 'admin-confirm-title');
      panel.setAttribute('aria-describedby', 'admin-confirm-message');
      panel.innerHTML = '<strong id="admin-confirm-title">Confirm deletion</strong><p id="admin-confirm-message"></p><div class="admin-confirm-actions"><button type="button" data-confirm="cancel">Cancel</button><button type="button" data-confirm="delete">Delete</button></div>';
      panel.querySelector('#admin-confirm-title').textContent = options.title || 'Confirm deletion';
      panel.querySelector('#admin-confirm-message').textContent = String(message);
      panel.querySelector('[data-confirm="cancel"]').textContent = options.cancelText || 'Cancel';
      panel.querySelector('[data-confirm="delete"]').textContent = options.confirmText || 'Delete';
      const finish = confirmed => {
        document.removeEventListener('keydown', onKeydown);
        panel.remove();
        if (previousFocus?.isConnected) previousFocus.focus();
        resolve(confirmed);
      };
      const onKeydown = event => {
        if (event.key === 'Escape') { event.preventDefault(); finish(false); }
      };
      panel.querySelector('[data-confirm="cancel"]').addEventListener('click', () => finish(false));
      panel.querySelector('[data-confirm="delete"]').addEventListener('click', () => finish(true));
      document.body.append(panel);
      document.addEventListener('keydown', onKeydown);
      panel.querySelector('[data-confirm="cancel"]').focus();
    }));
    return confirmQueue;
  };
  const savedTheme=localStorage.getItem('restaurant-admin-theme');
  document.documentElement.dataset.adminTheme=['light','dim','dark'].includes(savedTheme)?savedTheme:'light';
  const main = document.querySelector('.main');
  const side = document.querySelector('.side');
  const nav = side?.querySelector('nav');
  if (!main || !side || !nav) return;
  let cashierMenuPermissions=[];
  try{cashierMenuPermissions=JSON.parse(side.dataset.cashierMenuPermissions||'[]')}catch{}
  const cashierMenuEnabled=key=>side.dataset.accountRole!=='Cashier'||cashierMenuPermissions.includes(key);
  const sectionElements = [...main.children].filter(element => element.matches('section'));
  sectionElements.forEach((section, index) => {
    if (section.id) return;
    const heading = section.querySelector(':scope > h1, :scope > h2');
    const slug = (heading?.textContent || `section-${index}`).trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    section.id = `admin-${slug || index}`;
  });
  const mergeSections = (primaryId, secondaryId, combinedTitle) => {
    const primary = main.querySelector(`:scope > #${primaryId}`);
    const secondary = main.querySelector(`#${CSS.escape(secondaryId)}`);
    if (!primary || !secondary) return;
    const primaryHeading = primary.querySelector(':scope > h1, :scope > h2');
    if (primaryHeading) primaryHeading.textContent = combinedTitle;
    const subsection = document.createElement('div');
    subsection.className = 'backend-subsection';
    while (secondary.firstChild) subsection.append(secondary.firstChild);
    primary.append(subsection);
    secondary.remove();
  };
  mergeSections('profile', 'settings', 'Restaurant Profile & Settings');
  mergeSections('categories', 'admin-edit-categories', 'Menu Categories');
  mergeSections('availability', 'item-status', 'Menu Management');
  if(side.dataset.accountRole==='Cashier'){
    const sectionPermissions={orders:['orders-all','orders-dine-in','orders-takeaway','orders-delivery'],pos:['pos-main','pos-kot','pos-paid-bills','pos-pending-bills'],menu:['menu-items'],categories:['menu-categories'],'online-menu-link':['online-menu-link'],tables:['tables'],'qr-codes':['qr-codes'],'table-history':['table-history'],customers:['customers'],settings:['settings']};
    for(const [id,permissions] of Object.entries(sectionPermissions)){const section=main.querySelector(':scope > #'+CSS.escape(id));if(section&&!permissions.some(cashierMenuEnabled))section.hidden=true;}
  }
  if (['Manager','Cashier'].includes(side.dataset.accountRole)) {
    const overview = main.querySelector(':scope > #overview');
    const profile = main.querySelector(':scope > #profile');
    if (overview && profile) {
      const summary = document.createElement('div');
      summary.className = 'manager-settings-overview';
      while (overview.firstChild) summary.append(overview.firstChild);
      profile.prepend(summary);
      overview.remove();
    }
  }
  const sections = sectionElements.filter(section => section.isConnected&&!section.hidden);
  if (!sections.length) return;
  sections.forEach(section => section.classList.add('backend-section'));
  const labelFor = section => {
    if (section.dataset.sectionTitle) return section.dataset.sectionTitle;
    const heading = section.querySelector(':scope > h1, :scope > h2');
    return heading ? heading.textContent.trim() : section.id.replaceAll('-', ' ');
  };
  const iconFor = id => ({overview:'⌂',pos:'▣',orders:'◷','table-history':'◴','qr-codes':'▣','restaurant-reports':'↗','online-menu-link':'↗',menu:'▦',categories:'≡',profile:'◉',staff:'♙',customers:'<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>',settings:'⚙',reports:'↗',tables:'⌗',subscription:'◇','subscription-details':'◇','subscription-management':'◇',payments:'$ ',availability:'✓','item-status':'◎','create-restaurant':'+','restaurant-management':'▤'}[id] || '•');
  [...nav.querySelectorAll('a[href^="#"]')].forEach(link => {
    if (!sections.some(section => `#${section.id}` === link.getAttribute('href'))) link.remove();
  });
  sections.forEach(section => {
    const existing = nav.querySelector(`a[href="#${CSS.escape(section.id)}"]`);
    if (existing) { existing.textContent = labelFor(section); return; }
    const link = document.createElement('a');
    link.href = `#${section.id}`;
    link.textContent = labelFor(section);
    nav.append(link);
  });
  if(['Manager','Cashier'].includes(side.dataset.accountRole)){
    const exists=id=>sections.some(section=>section.id===id);
    const direct=(label,id,key)=>{if(!exists(id)||!cashierMenuEnabled(key))return null;const link=document.createElement('a');link.href=`#${id}`;link.dataset.menuKey=key;link.textContent=label;return link;};
    const group=(label,items)=>{
      const available=items.filter(([,id,section,key])=>exists(section||id)&&cashierMenuEnabled(key));
      if(!available.length)return null;
      const details=document.createElement('details');details.className='backend-nav-group';
      if(label==='Orders'||label==='POS / Billing')details.open=true;
      const summary=document.createElement('summary');
      const icon=document.createElement('span');icon.className='nav-icon';icon.setAttribute('aria-hidden','true');icon.textContent=iconFor(available[0][2]||available[0][1]);
      const name=document.createElement('span');name.className='nav-label';name.textContent=label;
      summary.append(icon,name);
      const arrow=document.createElementNS('http://www.w3.org/2000/svg','svg');arrow.setAttribute('viewBox','0 0 24 24');arrow.setAttribute('aria-hidden','true');arrow.classList.add('backend-nav-chevron');
      const path=document.createElementNS('http://www.w3.org/2000/svg','path');path.setAttribute('d','m6 9 6 6 6-6');arrow.append(path);summary.append(arrow);details.append(summary);
      const itemList=document.createElement('div');itemList.className='backend-nav-items';
      for(const [name,id,,key] of available){const link=document.createElement('a');link.href=`#${id}`;link.dataset.menuKey=key;link.className='backend-nav-subitem';link.textContent=name;itemList.append(link);}
      details.append(itemList);
      return details;
    };
    nav.replaceChildren(...[
      group('Orders',[['All Orders','orders','orders','orders-all'],['Dine-In','orders-dine-in','orders','orders-dine-in'],['Takeaway','orders-takeaway','orders','orders-takeaway'],['Delivery','orders-delivery','orders','orders-delivery']]),
      group('POS / Billing',[['POS','pos','pos','pos-main'],['Kitchen Order Tickets (KOT)','pos-kot','pos','pos-kot'],['Paid Bills','pos-invoices','pos','pos-paid-bills'],['Pending Bills','pos-payments','pos','pos-pending-bills']]),
      group('Menu Management',[['Online Menu Link','online-menu-link','online-menu-link','online-menu-link'],['Menu Items','menu','menu','menu-items'],['Menu Categories','categories','categories','menu-categories']]),
      group('Tables & QR Codes',[['Tables','tables','tables','tables'],['QR Codes','qr-codes','qr-codes','qr-codes'],['Table History','table-history','table-history','table-history']]),
      direct('Customers','customers','customers'),
      ...(side.dataset.accountRole==='Manager'?[direct('Cashier Accounts','staff')]:[]),
      ...(side.dataset.accountRole==='Manager'?[group('Reports',[['Order Reports','reports'],['POS Sales & Payment Report','pos-sales-report']])]:[]),
      ...(side.dataset.accountRole==='Manager'?[direct('Profile & Settings','profile')]:side.dataset.accountRole==='Cashier'?[direct('Settings','settings','settings')]:[]),
      ...(side.dataset.accountRole==='Manager'?[direct('Subscription Information','subscription-details')]:[])
    ].filter(Boolean));
  }
  if (main.querySelector('#owner-account')) {
    const overviewLink = nav.querySelector('a[href="#overview"]');
    const createLink = nav.querySelector('a[href="#create-restaurant"]');
    const loginLink = nav.querySelector('a[href="#owner-account"]');
    if (overviewLink && createLink) overviewLink.after(createLink);
    if (loginLink) { loginLink.textContent = 'Admin Login'; nav.append(loginLink); }
  }
  const links = [...nav.querySelectorAll('a[href^="#"]')];
  nav.querySelectorAll('.backend-nav-group').forEach(group=>{
    if(group.querySelector(':scope > .backend-nav-items'))return;
    const items=document.createElement('div');items.className='backend-nav-items';
    [...group.children].filter(child=>child.tagName!=='SUMMARY').forEach(child=>items.append(child));
    group.append(items);
  });
  const navGroups=[...nav.querySelectorAll('.backend-nav-group')];
  const navAnimations=new WeakMap(),navTargets=new WeakMap();
  const reducedMotion=window.matchMedia('(prefers-reduced-motion: reduce)');
  const animateNavGroup=(group,open)=>{
    const items=group.querySelector('.backend-nav-items');
    const start=group.open?items.getBoundingClientRect().height:0;
    navAnimations.get(group)?.cancel();
    navTargets.set(group,open);
    group.classList.toggle('is-collapsing',!open);
    if(open)group.open=true;
    const end=open?items.scrollHeight:0;
    if(reducedMotion.matches||Math.abs(start-end)<1){group.open=open;group.classList.remove('is-collapsing');navAnimations.delete(group);return;}
    const animation=items.animate([{height:`${start}px`,opacity:open?0:1,transform:open?'translateY(-5px)':'translateY(0)'},{height:`${end}px`,opacity:open?1:0,transform:open?'translateY(0)':'translateY(-5px)'}],{duration:240,easing:'cubic-bezier(.2,.8,.2,1)'});
    navAnimations.set(group,animation);
    animation.onfinish=()=>{if(navAnimations.get(group)!==animation)return;navAnimations.delete(group);group.open=open;group.classList.remove('is-collapsing');};
  };
  navGroups.forEach(group=>group.querySelector('summary').addEventListener('click',event=>{
    event.preventDefault();
    const open=!(navTargets.get(group)??group.open);
    animateNavGroup(group,open);
  }));
  links.forEach(link => {
    const id = link.hash.slice(1);
    const label = link.textContent.trim();
    link.innerHTML = `<span class="nav-icon" aria-hidden="true">${iconFor(id)}</span><span class="nav-label">${label}</span>`;
  });
  const posFrame=document.getElementById('dashboard-pos-frame');
  const posReportFrame=document.getElementById('dashboard-pos-report-frame');
  if(posReportFrame)window.addEventListener('message',event=>{
    if(event.origin!==location.origin||event.source!==posReportFrame.contentWindow||event.data?.type!=='restaurant-pos-height')return;
    const height=Number(event.data.height);
    if(Number.isFinite(height)&&height>0)posReportFrame.style.height=`${Math.max(760,Math.ceil(height)+8)}px`;
  });
  if(posFrame){
    let activePosNotice=null,queuedPosNotice=null,posNoticeTimer=0;
    const showPosNotice=noticeData=>{
      activePosNotice?.remove();clearTimeout(posNoticeTimer);
      const toast=document.createElement('div');toast.className='admin-feedback-toast'+(noticeData.kind==='error'?' is-error':'');toast.setAttribute('role',noticeData.kind==='error'?'alert':'status');toast.setAttribute('aria-live',noticeData.kind==='error'?'assertive':'polite');
      const mark=document.createElement('span');mark.className='admin-feedback-toast-mark';mark.setAttribute('aria-hidden','true');mark.textContent=noticeData.kind==='error'?'!':'✓';
      const copy=document.createElement('div'),title=document.createElement('strong'),message=document.createElement('p');title.textContent=String(noticeData.title||'POS update').slice(0,80);message.textContent=String(noticeData.message||'').slice(0,500);copy.append(title,message);
      const close=document.createElement('button');close.type='button';close.setAttribute('aria-label','Dismiss notification');close.textContent='×';close.addEventListener('click',()=>{toast.remove();if(activePosNotice===toast)activePosNotice=null;clearTimeout(posNoticeTimer);});toast.append(mark,copy,close);toast.noticeData=noticeData;document.body.append(toast);activePosNotice=toast;posNoticeTimer=setTimeout(()=>{toast.remove();if(activePosNotice===toast)activePosNotice=null;},6000);
    };
    window.addEventListener('message',event=>{
      if(event.origin!==location.origin||event.source!==posFrame.contentWindow)return;
      if(event.data?.type==='restaurant-pos-heading'){
        const title=String(event.data.title||'Point of Sale').slice(0,80),description=String(event.data.description||'').slice(0,300);
        const section=posFrame.closest('.backend-pos-section'),heading=section?.querySelector('.backend-pos-heading');
        const titleElement=heading?.querySelector('h2'),descriptionElement=heading?.querySelector('p:last-child');
        if(titleElement)titleElement.textContent=title;
        if(descriptionElement)descriptionElement.textContent=description;
        if(section)section.dataset.sectionTitle=title;
        return;
      }
      if(event.data?.type==='restaurant-pos-dialog'){
        const dialogOpen=event.data.open===true;
        document.body.classList.toggle('backend-pos-dialog-open',dialogOpen);
        if(dialogOpen&&activePosNotice){queuedPosNotice=activePosNotice.noticeData;activePosNotice.remove();activePosNotice=null;clearTimeout(posNoticeTimer);}
        else if(!dialogOpen&&queuedPosNotice){const noticeData=queuedPosNotice;queuedPosNotice=null;showPosNotice(noticeData);}
        return;
      }
      if(event.data?.type==='restaurant-pos-notice'){
        if(document.body.classList.contains('backend-pos-dialog-open'))queuedPosNotice=event.data;
        else showPosNotice(event.data);
        return;
      }
      if(event.data?.type!=='restaurant-pos-height')return;
      const height=Number(event.data.height);
      if(Number.isFinite(height)&&height>0)posFrame.style.height=`${Math.max(760,Math.ceil(height)+8)}px`;
    });
  }
  const accountName = side.dataset.accountName || side.querySelector(':scope > small')?.textContent.trim() || 'Account';
  const roleLabel = side.dataset.accountRole || 'Account';
  const restaurantName = side.dataset.restaurantName || '';
  const initials = accountName.split(/\s+/).map(word => word[0]).join('').slice(0, 2).toUpperCase();
  const topbar = document.createElement('header');
  topbar.className = 'backend-topbar';
  topbar.innerHTML = '<div class="backend-topbar-copy"><p>Management workspace</p><h1></h1></div><div class="backend-topbar-tools"><div class="admin-theme-switcher" aria-label="Dashboard theme"><button type="button" data-theme="light">Light</button><button type="button" data-theme="dim">Dim</button><button type="button" data-theme="dark">Dark</button></div><div class="backend-user"><span class="backend-avatar"></span><div><strong></strong><small></small></div></div></div>';
  topbar.querySelector('.backend-avatar').textContent = initials;
  topbar.querySelector('.backend-user strong').textContent = accountName;
  topbar.querySelector('.backend-user strong').title = accountName;
  topbar.querySelector('.backend-user').setAttribute('aria-label', `${accountName} · ${roleLabel}`);
  topbar.querySelector('.backend-user small').textContent = roleLabel;
  if (['Manager','Cashier'].includes(roleLabel)) {
    const orderingControl = main.querySelector('#online-menu-link .online-menu-ordering-toggle');
    const orderingFeedback = main.querySelector('#online-ordering-feedback');
    const themeSwitcher = topbar.querySelector('.admin-theme-switcher');
    if (orderingControl && themeSwitcher) {
      orderingControl.classList.add('backend-online-ordering-control');
      if (orderingFeedback) orderingControl.append(orderingFeedback);
      themeSwitcher.after(orderingControl);
    }
  }
  if (roleLabel === 'Super Admin') {
    const portalRoot=base.replace(/\/(?:s-admin|r-owner|cashier)$/,'');
    const portalLinks=document.createElement('nav');
    portalLinks.className='backend-portal-links';
    portalLinks.setAttribute('aria-label','Staff login portals');
    [['Manager Login','r-owner'],['Cashier Login','cashier']].forEach(([label,portal])=>{
      const link=document.createElement('a');
      link.href=`${portalRoot}/${portal}/login`;
      link.target='_blank';
      link.rel='noopener noreferrer';
      link.textContent=label;
      portalLinks.append(link);
    });
    topbar.querySelector('.backend-topbar-tools').before(portalLinks);
  }
  main.prepend(topbar);
  const themeButtons=[...topbar.querySelectorAll('[data-theme]')];
  const applyTheme=theme=>{
    document.documentElement.dataset.adminTheme=theme;
    localStorage.setItem('restaurant-admin-theme',theme);
    themeButtons.forEach(button=>button.classList.toggle('active',button.dataset.theme===theme));
    if(posFrame?.contentWindow)posFrame.contentWindow.postMessage({type:'restaurant-pos-theme',theme},location.origin);
    if(posReportFrame?.contentWindow)posReportFrame.contentWindow.postMessage({type:'restaurant-pos-theme',theme},location.origin);
  };
  themeButtons.forEach(button=>button.addEventListener('click',()=>applyTheme(button.dataset.theme)));
  applyTheme(document.documentElement.dataset.adminTheme);
  let pendingPosOrderId=null;
  const routeMenuPermissions={'orders':'orders-all','orders-dine-in':'orders-dine-in','orders-takeaway':'orders-takeaway','orders-delivery':'orders-delivery','pos':'pos-main','pos-kot':'pos-kot','pos-invoices':'pos-paid-bills','pos-payments':'pos-pending-bills','online-menu-link':'online-menu-link','menu':'menu-items','categories':'menu-categories','tables':'tables','qr-codes':'qr-codes','table-history':'table-history','customers':'customers','settings':'settings'};
  let selectedLink=null;
  const show = requestedId => {
    let id=requestedId;
    const requestedPermission=routeMenuPermissions[id];
    if(requestedPermission&&!cashierMenuEnabled(requestedPermission)){
      const firstAvailable=links.find(link=>cashierMenuEnabled(link.dataset.menuKey));
      if(!firstAvailable)return;
      id=firstAvailable.hash.slice(1);
      history.replaceState(null,'',firstAvailable.hash);
    }
    const aliases={'orders-dine-in':'orders','orders-takeaway':'orders','orders-delivery':'orders','pos-kot':'pos','pos-invoices':'pos','pos-payments':'pos','pos-history':'pos'};
    const target = sections.find(section => section.id === (aliases[id]||id)) || sections.find(section => section.id === (['Manager','Cashier'].includes(roleLabel) ? 'profile' : 'overview')) || sections[0];
    if(target.id!=='pos')document.body.classList.remove('backend-pos-dialog-open');
    if(target.id==='orders')window.dispatchEvent(new CustomEvent('restaurant-order-view',{detail:{type:({'orders-dine-in':'DINE_IN','orders-takeaway':'TAKEAWAY','orders-delivery':'DELIVERY'}[id]||'')}}));
    if(target.id==='pos'&&posFrame){
      const view=({'pos-kot':'kot','pos-invoices':'invoices','pos-payments':'payments','pos-history':'invoices'}[id]||'pos');
      const notify=()=>{posFrame.contentWindow?.postMessage({type:'restaurant-pos-theme',theme:document.documentElement.dataset.adminTheme||'light'},location.origin);posFrame.contentWindow?.postMessage({type:'restaurant-pos-view',view},location.origin);if(pendingPosOrderId){posFrame.contentWindow?.postMessage({type:'restaurant-pos-open-order',id:pendingPosOrderId},location.origin);pendingPosOrderId=null;}};
      if(!posFrame.getAttribute('src')){posFrame.addEventListener('load',notify,{once:true});posFrame.src=posFrame.dataset.src;}
      else if(posFrame.contentDocument?.readyState==='complete')notify();else posFrame.addEventListener('load',notify,{once:true});
    }
    if(target.id==='pos-sales-report'&&posReportFrame){
      const notify=()=>posReportFrame.contentWindow?.postMessage({type:'restaurant-pos-theme',theme:document.documentElement.dataset.adminTheme||'light'},location.origin);
      if(!posReportFrame.getAttribute('src')){posReportFrame.addEventListener('load',notify,{once:true});posReportFrame.src=posReportFrame.dataset.src;}
      else if(posReportFrame.contentDocument?.readyState==='complete')notify();else posReportFrame.addEventListener('load',notify,{once:true});
    }
    sections.forEach(section => section.classList.toggle('backend-section-active', section === target));
    links.forEach(link => {
      const active = link.getAttribute('href') === `#${id}`;
      link.classList.toggle('active', active);
      if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
    });
    selectedLink=links.find(link=>link.getAttribute('href')===`#${id}`)||links.find(link=>link.getAttribute('href')===`#${target.id}`)||null;
    const label = selectedLink?.querySelector('.nav-label')?.textContent || labelFor(target);
    const shouldFocusSelectedLink=Boolean(selectedLink&&!document.body.classList.contains('mobile-navigation-open'));
    topbar.querySelector('h1').textContent = label;
    document.title = restaurantName ? `${roleLabel} · ${restaurantName}` : roleLabel;
    const resetPageScroll=()=>{window.scrollTo(0,0);if(document.scrollingElement)document.scrollingElement.scrollTop=0;main.scrollTop=0;target.scrollTop=0;};
    resetPageScroll();
    requestAnimationFrame(()=>{if(shouldFocusSelectedLink)selectedLink?.focus({preventScroll:true});resetPageScroll();requestAnimationFrame(resetPageScroll);});
  };
  window.addEventListener('restaurant-open-pos-order',event=>{const id=Number(event.detail?.id);if(!Number.isSafeInteger(id)||id<1||!posFrame)return;pendingPosOrderId=id;if(location.hash==='#pos')show('pos');else location.hash='pos';});
  links.forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    const id = link.hash.slice(1);
    selectedLink=link;
    if (location.hash !== link.hash) history.pushState(null, '', link.hash);
    show(id);
  }));
  if(nav.children.length){
    const mobileMenuTrigger=document.createElement('button');
    mobileMenuTrigger.type='button';
    mobileMenuTrigger.className='mobile-menu-trigger';
    mobileMenuTrigger.setAttribute('aria-label','Open navigation menu');
    mobileMenuTrigger.setAttribute('aria-controls','mobile-dashboard-navigation');
    mobileMenuTrigger.setAttribute('aria-expanded','false');
    mobileMenuTrigger.innerHTML='<span class="mobile-menu-trigger-icon" aria-hidden="true"><i></i><i></i><i></i></span><span>Menu</span>';
    side.prepend(mobileMenuTrigger);
    const mobileNavigation=document.createElement('div');
    mobileNavigation.className='mobile-navigation';
    mobileNavigation.id='mobile-dashboard-navigation';
    mobileNavigation.setAttribute('role','dialog');
    mobileNavigation.setAttribute('aria-modal','true');
    mobileNavigation.setAttribute('aria-label','Dashboard menu');
    mobileNavigation.hidden=true;
    const mobileMainHeader=document.createElement('div');
    mobileMainHeader.className='mobile-navigation-header';
    const mobileMainTitle=document.createElement('h2');
    mobileMainTitle.textContent='Menu';
    const makeCloseButton=()=>{
      const close=document.createElement('button');
      close.type='button';
      close.className='mobile-navigation-close';
      close.setAttribute('aria-label','Close navigation menu');
      close.textContent='×';
      return close;
    };
    const mobileMainClose=makeCloseButton();
    mobileMainHeader.append(mobileMainTitle,mobileMainClose);
    const mobileHome=document.createElement('div');
    mobileHome.className='mobile-navigation-home';
    const mobileSubmenu=document.createElement('section');
    mobileSubmenu.className='mobile-navigation-submenu';
    mobileSubmenu.hidden=true;
    mobileSubmenu.setAttribute('aria-live','polite');
    const mobileSubmenuHeader=document.createElement('div');
    mobileSubmenuHeader.className='mobile-navigation-submenu-header';
    const mobileBack=document.createElement('button');
    mobileBack.type='button';
    mobileBack.className='mobile-navigation-back';
    mobileBack.setAttribute('aria-label','Back to main menu');
    mobileBack.innerHTML='<span aria-hidden="true">←</span><span>Back</span>';
    const mobileSubmenuTitle=document.createElement('h3');
    const mobileSubmenuClose=makeCloseButton();
    mobileSubmenuHeader.append(mobileBack,mobileSubmenuTitle,mobileSubmenuClose);
    const mobileSubmenuItems=document.createElement('div');
    mobileSubmenuItems.className='mobile-navigation-items';
    mobileSubmenu.append(mobileSubmenuHeader,mobileSubmenuItems);
    let drawerScrollY=0;
    const openDrawer=()=>{
      drawerScrollY=window.scrollY;
      mobileNavigation.hidden=false;
      mobileNavigation.dataset.view='home';
      mobileMainHeader.hidden=false;
      mobileHome.hidden=false;
      mobileSubmenu.hidden=true;
      document.body.classList.add('mobile-navigation-open');
      mobileMenuTrigger.setAttribute('aria-expanded','true');
      mobileMenuTrigger.setAttribute('aria-label','Close navigation menu');
      mobileMainClose.focus({preventScroll:true});
    };
    const closeDrawer=({restoreFocus=true,restoreScroll=true}={})=>{
      if(mobileNavigation.hidden)return;
      mobileNavigation.hidden=true;
      document.body.classList.remove('mobile-navigation-open');
      mobileMenuTrigger.setAttribute('aria-expanded','false');
      mobileMenuTrigger.setAttribute('aria-label','Open navigation menu');
      if(restoreScroll)window.scrollTo({top:drawerScrollY,behavior:'instant'});
      if(restoreFocus)mobileMenuTrigger.focus({preventScroll:true});
    };
    mobileMenuTrigger.addEventListener('click',()=>mobileNavigation.hidden?openDrawer():closeDrawer());
    mobileMainClose.addEventListener('click',()=>closeDrawer());
    mobileSubmenuClose.addEventListener('click',()=>closeDrawer());
    document.addEventListener('keydown',event=>{
      if(event.key==='Escape'&&!mobileNavigation.hidden)closeDrawer();
    });
    mobileNavigation.addEventListener('keydown',event=>{
      if(event.key!=='Tab')return;
      const focusable=[...mobileNavigation.querySelectorAll('button:not([disabled]),a[href]')].filter(element=>element.getClientRects().length&&!element.closest('[hidden]'));
      if(!focusable.length)return;
      const first=focusable[0],last=focusable[focusable.length-1];
      if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus({preventScroll:true});}
      else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus({preventScroll:true});}
    });
    const mobileIcon=(key)=>{
      const icon=document.createElement('span');
      icon.className='mobile-navigation-icon';
      icon.setAttribute('aria-hidden','true');
      icon.innerHTML=iconFor(key);
      return icon;
    };
    const mobileEntry=(label,key,{href=null,submenu=null,source=null}={})=>{
      const entry=href?document.createElement('a'):document.createElement('button');
      if(!href)entry.type='button';
      entry.className='mobile-navigation-entry';
      if(label==='Show Online Menu')entry.classList.add('mobile-navigation-online-menu');
      entry.append(mobileIcon(key));
      const text=document.createElement('span');
      text.className='mobile-navigation-label';
      text.textContent=label;
      entry.append(text);
      const chevron=document.createElement('span');
      chevron.className='mobile-navigation-chevron';
      chevron.setAttribute('aria-hidden','true');
      chevron.textContent='›';
      entry.append(chevron);
      if(href){
        entry.href=href;
        if(source?.target)entry.target=source.target;
        if(source?.rel)entry.rel=source.rel;
        if(source?.dataset.menuKey)entry.dataset.menuKey=source.dataset.menuKey;
      }else{
        entry.dataset.mobileTargets=submenu.map(item=>item.getAttribute('href')).join(' ');
        entry.setAttribute('aria-haspopup','true');
        entry.setAttribute('aria-expanded','false');
        entry.addEventListener('click',()=>openSubmenu(label,submenu,entry));
      }
      return entry;
    };
    const sourceGroups=[...nav.querySelectorAll(':scope > .backend-nav-group')];
    const sourceDirect=[...nav.querySelectorAll(':scope > a[href^="#"]')];
    const groupByLabel=label=>sourceGroups.find(group=>group.querySelector(':scope > summary .nav-label')?.textContent.trim()===label);
    const directByLabel=label=>sourceDirect.find(link=>link.querySelector('.nav-label')?.textContent.trim()===label);
    const submenuLinks=source=>source?[...source.querySelectorAll('a[href]')]:[];
    const onlineMenu=side.querySelector(':scope > .sidebar-show-menu');
    const openSubmenu=(label,items,trigger)=>{
      if(!items?.length)return;
      mobileHome.querySelectorAll('[aria-haspopup="true"]').forEach(button=>button.setAttribute('aria-expanded',String(button===trigger)));
      mobileSubmenuTitle.textContent=label;
      mobileSubmenuItems.replaceChildren(...items.map(link=>mobileEntry(link.querySelector('.nav-label')?.textContent.trim()||link.textContent.trim(),link.hash.slice(1),{href:link.getAttribute('href'),source:link})));
      mobileMainHeader.hidden=true;
      mobileHome.hidden=true;
      mobileSubmenu.hidden=false;
      mobileNavigation.dataset.view='submenu';
      mobileBack.focus({preventScroll:true});
      updateMobileActive();
    };
    const addGroup=(label,key,group=groupByLabel(label))=>{
      const items=submenuLinks(group);
      if(!items.length)return;
      mobileHome.append(mobileEntry(label,key,{submenu:items}));
    };
    const addDirect=(source,label,key)=>{
      if(!source)return;
      mobileHome.append(mobileEntry(label,key,{href:source.getAttribute('href'),source}));
    };
    if(onlineMenu)mobileHome.append(mobileEntry('Show Online Menu','online-menu-link',{href:onlineMenu.getAttribute('href'),source:onlineMenu}));
    if(['Manager','Cashier'].includes(roleLabel)){
      addGroup('Orders','orders');
      addGroup('POS / Billing','pos');
      addGroup('Menu Management','menu');
      addGroup('Tables & QR Codes','tables');
      addDirect(directByLabel('Customers'),'Customers','customers');
      addDirect(directByLabel('Cashier Accounts'),'Cashier Accounts','staff');
      addGroup('Reports','reports');
      const profileLink=directByLabel('Profile & Settings')||directByLabel('Settings');
      const subscriptionLink=directByLabel('Subscription Information');
      if(profileLink&&subscriptionLink){
        mobileHome.append(mobileEntry('Settings','settings',{submenu:[profileLink,subscriptionLink]}));
      }else addDirect(profileLink,'Settings','settings');
    }else{
      for(const source of [...nav.children]){
        if(source.tagName==='DETAILS'){
          const title=source.querySelector(':scope > summary .nav-label')?.textContent.trim()||source.querySelector(':scope > summary')?.textContent.trim();
          if(title)addGroup(title,submenuLinks(source)[0]?.hash.slice(1)||'overview',source);
          continue;
        }
        if(source.matches('a[href]')){
          const label=source.querySelector('.nav-label')?.textContent.trim()||source.textContent.trim();
          const key=source.hash?.slice(1)||'online-menu-link';
          mobileHome.append(mobileEntry(label,key,{href:source.getAttribute('href'),source}));
        }
      }
    }
    const updateMobileActive=()=>{
      mobileNavigation.querySelectorAll('.mobile-navigation-entry[href^="#"]').forEach(entry=>{
        const active=entry.getAttribute('href')===location.hash;
        entry.classList.toggle('active',active);
        if(active)entry.setAttribute('aria-current','page');else entry.removeAttribute('aria-current');
      });
      mobileNavigation.querySelectorAll('.mobile-navigation-entry[data-mobile-targets]').forEach(entry=>{
        const targets=entry.dataset.mobileTargets.split(/\s+/);
        entry.classList.toggle('active',targets.includes(location.hash));
      });
    };
    mobileNavigation.addEventListener('click',event=>{
      const link=event.target instanceof Element?event.target.closest('a.mobile-navigation-entry[href^="#"]'):null;
      if(!link){
        if(event.target instanceof Element&&event.target.closest('a.mobile-navigation-entry'))closeDrawer({restoreFocus:false,restoreScroll:false});
        return;
      }
      event.preventDefault();
      const id=link.hash.slice(1);
      selectedLink=links.find(original=>original.hash===link.hash)||null;
      if(location.hash!==link.hash)history.pushState(null,'',link.hash);
      show(id);
      updateMobileActive();
      closeDrawer({restoreFocus:false,restoreScroll:false});
    });
    mobileBack.addEventListener('click',()=>{
      mobileSubmenu.hidden=true;
      mobileHome.hidden=false;
      mobileMainHeader.hidden=false;
      mobileNavigation.dataset.view='home';
      mobileHome.querySelectorAll('[aria-haspopup="true"]').forEach(button=>button.setAttribute('aria-expanded','false'));
      mobileHome.querySelector('.mobile-navigation-entry')?.focus({preventScroll:true});
    });
    mobileNavigation.dataset.view='home';
    mobileNavigation.append(mobileMainHeader,mobileHome,mobileSubmenu);
    nav.after(mobileNavigation);
    side.classList.add('has-mobile-navigation');
    window.addEventListener('hashchange',updateMobileActive);
    window.addEventListener('popstate',updateMobileActive);
    updateMobileActive();
  }
  window.addEventListener('hashchange', () => show(location.hash.slice(1)));
  const initialSection=location.hash.slice(1)||(['Manager','Cashier'].includes(roleLabel)&&posFrame?'pos':'');
  if(!location.hash&&['Manager','Cashier'].includes(roleLabel)&&posFrame)history.replaceState(null,'','#pos');
  show(initialSection);
})();
