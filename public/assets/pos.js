const posConfig = JSON.parse(document.getElementById('pos-config').textContent);
const posState = {items: [], popularIds: [], visibleCount: 12, orderView: 'pos', orderResults: [], orderHasMore: false, cart: new Map(), order: null, invoice: null, activePackaging: 0, activeDelivery: 0, submissionKey: crypto.randomUUID(), paymentKey: crypto.randomUUID()};
let posPopupPortal=null;
if(window.parent!==window){try{const parentDocument=window.parent.document;posPopupPortal=parentDocument.querySelector('[data-pos-popup-portal]');if(!posPopupPortal){posPopupPortal=parentDocument.createElement('div');posPopupPortal.className='pos-app pos-popup-portal-host';posPopupPortal.dataset.posPopupPortal='true';parentDocument.body.append(posPopupPortal);}const posStylesheet=[...document.querySelectorAll('link[rel="stylesheet"]')].find(link=>link.href.includes('/assets/pos.css'));if(posStylesheet&&!parentDocument.querySelector('[data-pos-popup-styles]')){const rules=posStylesheet.sheet?.cssRules;if(rules){const popupStyles=parentDocument.createElement('style');popupStyles.dataset.posPopupStyles='true';popupStyles.textContent=[...rules].map(rule=>rule.cssText).join('\n').replaceAll('body:has(.pos-app)','body:has(.pos-app:not([data-pos-popup-portal]))').replaceAll('html:has(.pos-app)','html:has(.pos-app:not([data-pos-popup-portal]))');parentDocument.head.append(popupStyles);}else{const popupStyles=parentDocument.createElement('link');popupStyles.rel='stylesheet';popupStyles.href=posStylesheet.href;popupStyles.dataset.posPopupStyles='true';parentDocument.head.append(popupStyles);}}document.querySelectorAll('.pos-app dialog,.pos-app [popover]').forEach(popup=>posPopupPortal.append(popup));}catch(error){posPopupPortal=null;}}
const nativeWebView=window.parent!==window&&window.parent.chrome?.webview?.postMessage?window.parent.chrome.webview:window.chrome?.webview;
const nativeHardwareBridge=Boolean(nativeWebView?.postMessage);
const nativeBridgePending=new Map();
if(nativeHardwareBridge)nativeWebView.addEventListener('message',event=>{const packet=event.data;if(packet?.type!=='RESPONSE'||!packet.requestId)return;const pending=nativeBridgePending.get(packet.requestId);if(!pending)return;clearTimeout(pending.timer);nativeBridgePending.delete(packet.requestId);pending.resolve(packet.reply||{ok:false,code:'INVALID_RESPONSE',message:'No response from the desktop app.'});});
function nativeHardwareCommand(action,data={}){if(!nativeHardwareBridge)return Promise.resolve({ok:false,code:'NATIVE_BRIDGE_NOT_AVAILABLE',message:'This feature requires Energetic Edge Restaurant POS.'});const requestId=crypto.randomUUID();return new Promise(resolve=>{const timer=setTimeout(()=>{nativeBridgePending.delete(requestId);resolve({ok:false,code:'BRIDGE_TIMEOUT',message:'The desktop hardware service did not respond.'});},20000);nativeBridgePending.set(requestId,{resolve,timer});try{nativeWebView.postMessage({action,requestId,data});}catch(error){clearTimeout(timer);nativeBridgePending.delete(requestId);resolve({ok:false,code:'BRIDGE_ERROR',message:'Unable to contact the desktop hardware service.'});}});}
async function fetchPrintData(kind,id){const response=await fetch(posConfig.base+'/api/pos/'+kind+'-data?id='+encodeURIComponent(id));const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to prepare print data.');return result;}
async function printReceiptOnDesktop(invoiceId,width=configuredPrintWidth){const data=await fetchPrintData('receipt',invoiceId);const receiptUrl=new URL(posConfig.base+'/api/pos/receipt?id='+encodeURIComponent(invoiceId)+'&width='+encodeURIComponent(width)+'&embed=1',location.origin);const response=await fetch(receiptUrl.href);if(!response.ok)throw Error('Unable to load the bill preview for printing.');let html=await response.text();if(!/<html[\s>]/i.test(html))throw Error('The bill preview could not be prepared for printing.');html=html.replace(/<head([\s>])/i,'<head$1<base href="'+receiptUrl.href.replace(/&/g,'&amp;')+'">');data.receiptHtml=html;data.receiptPaperWidthMm=Number(width);const result=await nativeHardwareCommand('PRINT_RECEIPT',data);if(!result.ok)throw Error(result.message||result.code||'Receipt print failed.');return result;}
async function printKotOnDesktop(kotId){const data=await fetchPrintData('kot',kotId);const result=await nativeHardwareCommand('PRINT_KOT',data);if(!result.ok)throw Error(result.message||result.code||'KOT print failed.');return result;}
async function requireKitchenPrinter(){if(!nativeHardwareBridge)throw Error('KOT printing requires the Energetic Edge Restaurant POS desktop app.');const result=await nativeHardwareCommand('CHECK_DEVICE_STATUS');if(!result.ok)throw Error(result.message||result.code||'Unable to check the kitchen printer.');const printer=result.data?.kitchenPrinter;if(!printer?.enabled)throw Error('Kitchen printer is disabled. Enable it in Maintenance.');if(printer.status!=='online')throw Error('Kitchen printer is offline or not configured. Check the selected printer in Maintenance.');}
async function openDrawerAfterCashPayment(entries,result,invoice){
  if(!nativeHardwareBridge||result.already_recorded||!entries.some(entry=>entry.method==='CASH'))return null;
  return nativeHardwareCommand('OPEN_CASH_DRAWER',{reason:'cash_payment_recorded',invoiceNo:invoice.invoice_number||'',webUserId:String(posConfig.userId||'')});
}
const $ = id => document.getElementById(id)||posPopupPortal?.querySelector('#'+CSS.escape(id));
function showPosModal(dialog){if(posPopupPortal&&dialog.ownerDocument!==posPopupPortal.ownerDocument)posPopupPortal.append(dialog);if(!dialog.open)dialog.showModal();if(window.parent!==window)window.parent.postMessage({type:'restaurant-pos-dialog',open:true},location.origin);}
function closeBackdropFromEvent(event,canClose=()=>true){const dialog=event.currentTarget;if(event.target!==dialog)return;const rect=dialog.getBoundingClientRect(),outside=event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom;if(outside&&canClose())dialog.close();}
for(const [id,label] of [['pos-order-from','Start date'],['pos-order-to','End date']]){const input=$(id);input.type='text';input.classList.add('pos-any-date');input.placeholder='Any date';input.setAttribute('aria-label',label+' (Any date by default)');input.addEventListener('focus',()=>{if(input.type==='text'){input.type='date';try{input.showPicker?.();}catch(error){}}});input.addEventListener('blur',()=>{if(!input.value)input.type='text';});}
const configuredPrintWidth = ['58', '80'].includes(String(posConfig.receiptWidthMm)) ? String(posConfig.receiptWidthMm) : '80';
if ($('pos-print-width')) $('pos-print-width').value = configuredPrintWidth;
let posNoticeTimer;
const posMessage = value => {
  $('pos-message').textContent = value || '';
  const notice = $('pos-notice');
  if (!notice) return;
  clearTimeout(posNoticeTimer);
  if (!value || value.endsWith('…')) {notice.hidden = true; return;}
  const successNotice=['Customer added.','Ready for a new order.'].includes(value)||value.startsWith('KOT sent automatically');
  const noticeTitle=successNotice?'Customer added':(/error|unable|failed|select at least|enter a phone|maximum|not available/i.test(value) ? 'Please check' : 'POS update');
  if(window.parent!==window){try{window.parent.postMessage({type:'restaurant-pos-notice',title:noticeTitle,message:value,kind:successNotice?'success':noticeTitle==='Please check'?'error':'info'},location.origin);notice.hidden=true;return;}catch(error){}}
  notice.classList.toggle('pos-notice-success',successNotice);
  $('pos-notice-title').textContent = noticeTitle;
  $('pos-notice-text').textContent = value;
  notice.hidden = false;
  posNoticeTimer = setTimeout(() => {notice.hidden = true;}, 6000);
};
function posAlert(title, description) {
  $('pos-alert-title').textContent = title;
  $('pos-alert-text').textContent = description;
  $('pos-alert-orders').hidden=title!=='Order created';
  const dialog = $('pos-alert');
  showPosModal(dialog);
}
const money = cents => 'LKR ' + (cents / 100).toLocaleString('en-LK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
const serviceCents = (subtotal, orderType = $('pos-type')?.value || posState.activeOrderType || 'DINE_IN') => orderType === 'DINE_IN' ? Math.round(subtotal * Number(posConfig.serviceChargePercent ?? 10) / 100) : 0;
const cents = value => {const match = String(value).match(/^(?:0|[1-9]\d{0,9})(?:\.(\d{1,2}))?$/); return match ? Math.round(Number(value) * 100) : null;};
const itemOptions=item=>item.options?.length?item.options.map(option=>({name:option.name,portion:option.name,price:option.price})):
  Number(item.is_portioned)?[{name:'Half',portion:'HALF',price:item.half_price},{name:'Full',portion:'FULL',price:item.full_price}]:[];
const unitCents = (item, portion) => {const options=itemOptions(item);return cents(options.length?options.find(option=>option.portion===portion)?.price:item.price)||0;};
async function posRequest(path, data) {
  const body = new FormData();
  for (const [key, value] of Object.entries(data)) body.set(key, value);
  const response = await fetch(posConfig.base + '/api/pos/' + path, {method: 'POST', headers: {'X-CSRF-Token': posConfig.csrf}, body});
  const result = await response.json();
  if (!response.ok || result.error) throw Error(result.error || 'Unable to complete action.');
  return result;
}
function renderItems() {
  const search = $('pos-search').value.trim().toLowerCase(), category = $('pos-category').value;
  const byId = new Map(posState.items.map(item => [Number(item.id), item]));
  const visible = search ? posState.items.filter(item => item.name.toLowerCase().includes(search))
    : category === 'popular' ? posState.popularIds.map(id => byId.get(Number(id))).filter(Boolean)
    : category ? posState.items.filter(item => String(item.category_id) === category)
    : posState.items.slice(0, posState.visibleCount);
  $('pos-items').replaceChildren();
  $('pos-load-more').hidden = Boolean(search || category || posState.visibleCount >= posState.items.length);
  if (!visible.length) {
    const empty = document.createElement('p'); empty.className = 'pos-items-empty';
    empty.textContent = search ? 'No food items match your search.' : category === 'popular' ? 'No sales yet. Open All items to start an order.' : 'No food items in this category.';
    $('pos-items').append(empty);
    return;
  }
  for (const item of visible) {
    const button = document.createElement('button'); button.type = 'button'; button.className = 'pos-item'; button.disabled = !Number(item.is_available) || !!posState.invoice;
    if (item.image_path) {const img = document.createElement('img'); img.src = posConfig.assetBase + '/' + item.image_path; img.alt = ''; img.loading = 'lazy'; button.append(img);}
    else {const placeholder = document.createElement('div'); placeholder.className = 'pos-item-placeholder'; placeholder.innerHTML = '<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M8 31h32M11 31c1-11 6-18 13-18s12 7 13 18M24 13v-3M17 8c1-2 1-3 0-5M25 8c1-2 1-3 0-5M33 8c1-2 1-3 0-5M6 35h36"/></svg>'; button.append(placeholder);}
    const name = document.createElement('strong'); name.textContent = item.name; button.append(name);
    const price = document.createElement('small');const options=itemOptions(item);price.textContent = options.length ? 'From '+money(Math.min(...options.map(option=>unitCents(item,option.portion))))+(options.length>1?' · '+options.length+' sizes':'') : money(unitCents(item, null)); button.append(price);
    button.addEventListener('click', () => addItem(item)); $('pos-items').append(button);
  }
}
async function addItem(item) {
  let portion = null;
  if (itemOptions(item).length) {
    portion = await choosePortion(item);
    if (!portion) return;
  }
  if (posState.order) {
    const details = await chooseActiveItemDetails(item);
    if (!details) return;
    try {
      await posRequest('order/item', {order_id: posState.order, action: 'ADD', menu_item_id: item.id, portion: portion || '', quantity: details.quantity, special_instruction: ''});
      await loadActiveOrder(posState.order); await loadPos(); posMessage(item.name + ' added to the order.');
    } catch (error) {posMessage(error.message);}
    return;
  }
  const key = item.id + ':' + (portion === null ? '\u0000' : portion);
  const current = posState.cart.get(key);
  if (current && current.qty >= 99) {posMessage('Maximum quantity is 99.'); return;}
  posState.cart.set(key, current ? {...current, qty: current.qty + 1} : {id: Number(item.id), item, portion, qty: 1});
  renderCart();
}
function chooseActiveItemDetails(item) {
  return new Promise(resolve => {
    const dialog = document.createElement('dialog'); dialog.className = 'pos-choice';
    const header = choiceHeader(item, 'ADD TO ORDER');
    const quantityLabel = document.createElement('label'); quantityLabel.textContent = 'Quantity';
    const quantity = document.createElement('input'); quantity.type = 'number'; quantity.min = '1'; quantity.max = '99'; quantity.value = '1'; quantityLabel.append(quantity);
    const actions = document.createElement('div'); actions.className = 'pos-choice-footer';
    const add = document.createElement('button'); add.type = 'button'; add.className = 'pos-choice-primary'; add.textContent = 'Add to order';
    add.onclick = () => {if (!quantity.reportValidity()) return; dialog.close(); resolve({quantity: Number(quantity.value)});};
    const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'pos-choice-cancel'; cancel.textContent = 'Cancel'; cancel.onclick = () => {dialog.close(); resolve(null);};
    dialog.oncancel = () => resolve(null);
    actions.append(cancel, add); dialog.append(header, quantityLabel, actions); showPosChoice(dialog);
  });
}
function choosePortion(item) {
  return new Promise(resolve => {
    const dialog = document.createElement('dialog'); dialog.className = 'pos-choice';
    const header = choiceHeader(item, 'CHOOSE A PORTION');
    const choices = document.createElement('div'); choices.className = 'pos-choice-options';
    for (const option of itemOptions(item)) {
      const portion=option.portion;
      const button = document.createElement('button'); button.type = 'button'; button.className = 'pos-choice-option';
      const name = document.createElement('span'); name.textContent = option.name;
      const price = document.createElement('strong'); price.textContent = money(unitCents(item, portion));
      button.append(name, price);
      button.onclick = () => {dialog.close(); resolve(portion);}; choices.append(button);
    }
    const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'pos-choice-cancel'; cancel.textContent = 'Cancel';
    cancel.onclick = () => {dialog.close(); resolve(null);};
    dialog.oncancel = () => resolve(null);
    dialog.append(header, choices, cancel); showPosChoice(dialog);
  });
}
function choiceHeader(item, eyebrow) {
  const header = document.createElement('div'); header.className = 'pos-choice-header';
  if (item.image_path) {const image = document.createElement('img'); image.src = posConfig.assetBase + '/' + item.image_path; image.alt = ''; header.append(image);}
  const copy = document.createElement('div'); const label = document.createElement('small'); label.textContent = eyebrow;
  const title = document.createElement('h3'); title.textContent = item.name;
  copy.append(label, title); header.append(copy); return header;
}
function showPosChoice(dialog) {
  dialog.addEventListener('close', () => {
    if (window.parent !== window) window.parent.postMessage({type:'restaurant-pos-dialog',open:false},location.origin);
    dialog.remove();
  }, {once:true});
  showPosModal(dialog);
}
function renderCart() {
  $('pos-cart').replaceChildren(); let total = 0, itemCount = 0;
  for (const [key, line] of posState.cart) {
    const unit = unitCents(line.item, line.portion);
    total += unit * line.qty;
    itemCount += line.qty;
    const row = document.createElement('div'); row.className = 'pos-cart-row';
    const thumb = document.createElement(line.item.image_path ? 'img' : 'span'); thumb.className = 'pos-cart-thumb';
    if (line.item.image_path) {thumb.src = posConfig.assetBase + '/' + line.item.image_path; thumb.alt = ''; thumb.loading = 'lazy';} else {thumb.textContent = '🍽';}
    const name = document.createElement('div'); name.textContent = line.item.name;
    const detail = document.createElement('small'); detail.textContent = (line.portion ? line.portion + ' · ' : '') + money(unit) + ' each × ' + line.qty;
    const lineTotal = document.createElement('strong'); lineTotal.className = 'pos-cart-line-total'; lineTotal.textContent = money(unit * line.qty);
    name.append(detail, lineTotal);
    const controls = document.createElement('div'); controls.className = 'pos-qty';
    const minus = document.createElement('button'); minus.type = 'button'; minus.textContent = '−'; minus.setAttribute('aria-label', 'Decrease ' + line.item.name);
    minus.onclick = () => {line.qty--; if (line.qty < 1) posState.cart.delete(key); renderCart();};
    const count = document.createElement('span'); count.textContent = line.qty;
    const plus = document.createElement('button'); plus.type = 'button'; plus.textContent = '+'; plus.setAttribute('aria-label', 'Increase ' + line.item.name);
    plus.onclick = () => {if (line.qty < 99) {line.qty++; renderCart();}};
    controls.append(minus, count, plus); row.append(thumb, name, controls);
    $('pos-cart').append(row);
  }
  const discount = cents($('pos-draft-discount').value);
  const orderType=$('pos-type').value,packaging=orderType!=='DINE_IN'?cents($('pos-packaging-charge').value):0,delivery=orderType==='DELIVERY'?cents($('pos-delivery-charge').value):0,service=serviceCents(total,orderType);
  $('pos-item-count').textContent = itemCount;
  $('pos-subtotal').textContent = money(total);
  $('pos-service-estimate').textContent = money(service);
  $('pos-service-estimate').closest('.pos-estimate').hidden=orderType!=='DINE_IN';
  $('pos-discount-estimate').textContent = discount === null || discount > total ? 'Check discount' : '− ' + money(discount);
  $('pos-packaging-estimate-row').hidden = orderType==='DINE_IN';
  $('pos-packaging-estimate').textContent = packaging === null ? 'Check charge' : money(packaging);
  $('pos-delivery-estimate-row').hidden = orderType!=='DELIVERY';
  $('pos-delivery-estimate').textContent = delivery === null ? 'Check charge' : money(delivery);
  $('pos-grand-estimate').textContent = discount === null || discount > total || packaging === null || delivery === null ? 'Check amount' : money(total + service - discount + packaging + delivery);
  if (!posState.cart.size) {const empty = document.createElement('p'); empty.className = 'pos-cart-empty'; empty.textContent = 'Select a menu item to start this order.'; $('pos-cart').append(empty);}
}
function syncType() {
  const dine = $('pos-type').value === 'DINE_IN', delivery = $('pos-type').value === 'DELIVERY';
  $('pos-table-wrap').hidden = !dine; $('pos-guest-wrap').hidden = !dine;
  $('pos-dine-fields').hidden = !dine;
  $('pos-customer-details').querySelector('.pos-customer-subtitle').textContent = delivery ? 'Customer details optional · add an address for delivery' : dine ? 'Optional for dine-in' : 'Optional for pickup';
  $('pos-customer-phone').required=false;
  $('pos-customer-phone').closest('.pos-phone-field').querySelector('label span').hidden=true;
  $('pos-type').closest('.pos-order').dataset.orderType = $('pos-type').value;
  document.querySelectorAll('.pos-delivery').forEach(el => el.hidden = !delivery);
  $('pos-package-charge').hidden=dine;
  if(delivery)$('pos-customer-details').open=true;
  document.querySelectorAll('[data-pos-order-type]').forEach(button => {
    const active = button.dataset.posOrderType === $('pos-type').value;
    button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', String(active));
  });
  renderCart();
}
function renderCategoryList(categories) {
  const list = $('pos-category-list'); list.replaceChildren();
  for (const category of [{id:'popular', name:'Most Selling'}, {id:'', name:'All items'}, ...categories]) {
    const button = document.createElement('button'); button.type = 'button'; button.textContent = category.name;
    const active = !$('pos-search').value.trim() && String(category.id) === $('pos-category').value;
    button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', String(active));
    button.onclick = () => {$('pos-search').value = ''; $('pos-category').value = String(category.id); posState.visibleCount = 12; renderCategoryList(categories); renderItems();};
    list.append(button);
  }
}
async function loadPos() {
  const response = await fetch(posConfig.base + '/api/pos/bootstrap');
  const data = await response.json(); if (!response.ok || data.error) throw Error(data.error || 'Unable to load POS.');
  posState.items = data.items;
  posState.popularIds = data.popular_ids || [];
  const selectedCategory = $('pos-category').value;
  $('pos-category').innerHTML = '<option value="popular">Most Selling</option><option value="">All items</option>';
  for (const category of data.categories) {$('pos-category').add(new Option(category.name, category.id));}
  if ([...$('pos-category').options].some(option => option.value === selectedCategory)) $('pos-category').value = selectedCategory;
  renderCategoryList(data.categories);
  $('pos-table').replaceChildren(); for (const table of data.tables) $('pos-table').add(new Option('Table ' + table.table_number, table.id));
  renderItems(); if(posState.orderView==='pos') renderOrders(data.orders);
}
let menuAvailabilityRefreshBusy=false,lastMenuAvailabilityRefresh=Date.now();
async function refreshPosMenuAvailability(){
  if(menuAvailabilityRefreshBusy||Date.now()-lastMenuAvailabilityRefresh<5000)return;
  menuAvailabilityRefreshBusy=true;lastMenuAvailabilityRefresh=Date.now();
  try{const response=await fetch(posConfig.base+'/api/pos/bootstrap?menu_only=1');const data=await response.json();if(!response.ok||data.error)throw Error(data.error||'Unable to refresh menu availability.');posState.items=data.items;posState.popularIds=data.popular_ids||[];renderItems();}
  catch(error){console.warn('POS menu availability refresh failed:',error.message);}
  finally{menuAvailabilityRefreshBusy=false;}
}
window.addEventListener('focus',refreshPosMenuAvailability);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshPosMenuAvailability();});
const isUnbilledReadyOrder=order=>order.status==='READY'||(order.order_type==='DINE_IN'&&order.status==='SERVED');
function renderOrders(orders) {
  const view=posState.orderView;
  if(view==='invoices') orders=orders.filter(order=>order.payment_status==='PAID');
  if(view==='payments') orders=orders.filter(order=>(Boolean(order.invoice_id)&&['UNPAID','PARTIALLY_PAID'].includes(order.payment_status))||(!order.invoice_id&&isUnbilledReadyOrder(order)));
  $('pos-order-status').hidden=view==='payments';$('pos-order-payment').hidden=view==='payments';
  $('pos-order-sort').hidden=view!=='payments';
  $('pos-orders-section').querySelector('h2').textContent=({kot:'Kitchen Order Tickets (KOT)',invoices:'Paid Bills',payments:'Pending Bills'}[view]||'Orders');
  $('pos-orders-description').textContent=({kot:'Kitchen tickets and order progress for the kitchen team.',invoices:'These are orders whose bills have been fully paid. Open an order to review payment details or print its receipt.',payments:'This list includes unpaid or partially paid invoices, plus unbilled Dine-In orders at Ready or Served and unbilled Pickup/Delivery orders at Ready. Open any order here to generate its bill and collect payment.'}[view]||'Search and review dine-in, pickup and delivery orders.');
  const wrap = document.createElement('div'); wrap.className = 'pos-order-cards';
  $('pos-order-count').textContent = orders.length + ' shown';
  for (const order of orders) {
    const card = document.createElement('article'); card.className = 'pos-order-card';
    const head = document.createElement('div'); head.className = 'pos-order-card-head';
    const number = document.createElement('strong'); number.textContent = order.order_number;
    const badge = document.createElement('span'); badge.className = 'pos-status';
    badge.textContent = order.invoice_number ? order.payment_status.replaceAll('_', ' ') : order.status.replaceAll('_', ' ');
    badge.classList.toggle('is-paid', order.payment_status === 'PAID');
    badge.classList.toggle('is-closed', ['COMPLETED','CANCELLED','REJECTED'].includes(order.status));
    head.append(number, badge);
    const meta = document.createElement('p'); meta.className = 'pos-order-card-meta';
    meta.textContent = [order.table_number ? 'Table ' + order.table_number : order.order_type.replaceAll('_',' '), order.source, order.invoice_number || 'Not billed', order.created_at].join(' · ');
    const foot = document.createElement('div'); foot.className = 'pos-order-card-foot';
    const total = document.createElement('b'); total.textContent = money(Math.round(Number(order.invoice_total || order.total) * 100));
    const open = document.createElement('button'); open.type = 'button'; open.textContent = 'Open order';
    open.onclick = () => (view==='payments'?openOrderInPos(order.id):openOrderDialog(order.id)).catch(error => posMessage(error.message));
    foot.append(total, open); card.append(head, meta, foot); wrap.append(card);
  }
  if (!orders.length) {const empty = document.createElement('p'); empty.textContent = 'No orders match these filters.'; wrap.append(empty);}
  $('pos-orders').replaceChildren(wrap);
  $('pos-orders-more').hidden = view!=='kot' || !posState.orderHasMore;
}
async function openOrderInPos(id){
  await loadActiveOrder(id);
  setPosScreen('pos');
  posMessage('Order loaded. Review it, then select Receive Payment to generate its bill and collect payment.');
}
async function openOrderDialog(id) {
  const response = await fetch(posConfig.base + '/api/pos/order-details?id=' + encodeURIComponent(id));
  const result = await response.json(); if(response.status===404){posState.orderResults=posState.orderResults.filter(order=>Number(order.id)!==Number(id));renderOrders(posState.orderResults);await filterOrders();throw Error('This order no longer exists and was removed from the list.');}if (!response.ok || result.error) throw Error(result.error || 'Unable to load order.');
  const order = result.order, dialog = $('pos-order-dialog'), content = $('pos-order-dialog-content');
  $('pos-order-dialog-title').textContent = order.order_number;
  content.replaceChildren();
  const summary = document.createElement('p'); summary.className = 'pos-dialog-summary';
  summary.textContent = [order.table_number ? 'Table ' + order.table_number : order.order_type.replaceAll('_',' '), order.status.replaceAll('_',' '), order.invoice_number || 'Not billed', order.payment_status || '', order.created_at].filter(Boolean).join(' · ');
  content.append(summary);
  for (const item of order.items) {
    const row=document.createElement('div');row.className='pos-dialog-item';
    if(item.image_path){const img=document.createElement('img');img.src=posConfig.assetBase+'/'+item.image_path;img.alt='';img.loading='lazy';row.append(img);}
    else {const placeholder=document.createElement('span');placeholder.className='pos-dialog-placeholder';placeholder.textContent='🍽';row.append(placeholder);}
    const details=document.createElement('div'), name=document.createElement('strong'), meta=document.createElement('small');
    name.textContent=item.item_name+(item.portion?' ('+item.portion+')':'');meta.textContent=item.quantity+' × '+money(Math.round(Number(item.unit_price)*100));details.append(name,meta);
    const amount=document.createElement('b');amount.textContent=money(Math.round(Number(item.subtotal)*100));row.append(details,amount);content.append(row);
  }
  const totals=document.createElement('div');totals.className='pos-dialog-total';
  const label=document.createElement('span');label.textContent=order.invoice_id?'Bill total':'Order total';
  const value=document.createElement('strong');value.textContent=money(Math.round(Number(order.invoice_total||order.total)*100));totals.append(label,value);content.append(totals);
  if(order.invoice_id){const payment=document.createElement('p');payment.className='pos-dialog-payment';payment.textContent='Paid: '+money(Math.round(Number(order.paid_total||0)*100))+' · Balance: '+money(Math.max(0,Math.round((Number(order.invoice_total)-Number(order.paid_total||0))*100)));content.append(payment);}
  if(order.invoice_id&&order.payment_status==='PAID'&&posState.orderView==='invoices'&&['RESTAURANT_ADMIN','CASHIER'].includes(posConfig.role)){
    const tipPanel=document.createElement('section');tipPanel.className='pos-dialog-tip';
    const tipHeader=document.createElement('div');tipHeader.className='pos-dialog-tip-heading';
    const tipTitle=document.createElement('strong');tipTitle.textContent='Staff tip';
    const tipValue=document.createElement('b');tipValue.textContent=money(Math.round(Number(order.tip_amount||0)*100));tipHeader.append(tipTitle,tipValue);
    const tipButton=document.createElement('button');tipButton.type='button';tipButton.className='pos-dialog-tip-action';tipButton.textContent=Number(order.tip_amount||0)>0?'Update tip':'Add a tip';
    const tipForm=document.createElement('div');tipForm.className='pos-dialog-tip-form';tipForm.hidden=true;
    const tipLabel=document.createElement('label');tipLabel.textContent='Tip amount (LKR)';const tipInput=document.createElement('input');tipInput.type='number';tipInput.min='0';tipInput.max='9999999999.99';tipInput.step='0.01';tipInput.inputMode='decimal';tipInput.value=Number(order.tip_amount||0).toFixed(2);tipLabel.append(tipInput);
    const tipActions=document.createElement('div');tipActions.className='pos-dialog-tip-actions';const tipSave=document.createElement('button');tipSave.type='button';tipSave.className='pos-dialog-tip-save';tipSave.textContent='Save tip';const tipCancel=document.createElement('button');tipCancel.type='button';tipCancel.className='pos-dialog-tip-cancel';tipCancel.textContent='Cancel';const tipMessage=document.createElement('small');tipMessage.className='pos-dialog-tip-message';tipMessage.setAttribute('role','status');tipActions.append(tipSave,tipCancel);tipForm.append(tipLabel,tipActions);
    tipButton.addEventListener('click',()=>{tipMessage.textContent='';tipForm.hidden=false;tipButton.hidden=true;tipInput.focus();});tipCancel.addEventListener('click',()=>{tipForm.hidden=true;tipButton.hidden=false;tipInput.value=Number(order.tip_amount||0).toFixed(2);tipMessage.textContent='';});
    tipSave.addEventListener('click',async()=>{tipSave.disabled=true;tipMessage.textContent='';try{const saved=await posRequest('tip',{invoice_id:order.invoice_id,amount:tipInput.value});order.tip_amount=saved.tip_amount;tipValue.textContent=money(Math.round(Number(saved.tip_amount)*100));tipButton.textContent=Number(saved.tip_amount)>0?'Update tip':'Add a tip';tipForm.hidden=true;tipButton.hidden=false;tipMessage.textContent='Tip saved separately from the bill total.';}catch(error){tipMessage.textContent=error.message;}finally{tipSave.disabled=false;}});
    tipPanel.append(tipHeader,tipButton,tipForm,tipMessage);content.append(tipPanel);
  }
  const actions=document.createElement('div');actions.className='pos-dialog-actions';
  if(order.source==='QR'&&!order.table_id&&['TAKEAWAY','DELIVERY'].includes(order.order_type)){
    const customerButton=document.createElement('button');customerButton.type='button';customerButton.className='pos-customer-details-button';customerButton.textContent='View customer details';
    customerButton.addEventListener('click',()=>{
      let customerDialog=document.querySelector('#pos-online-customer-dialog');
      if(!customerDialog){customerDialog=document.createElement('dialog');customerDialog.id='pos-online-customer-dialog';customerDialog.className='pos-order-dialog pos-online-customer-dialog';document.body.append(customerDialog);}
      customerDialog.replaceChildren();
      const header=document.createElement('div');header.className='pos-dialog-head';const titleWrap=document.createElement('div'),eyebrow=document.createElement('small'),title=document.createElement('h2');eyebrow.textContent='ONLINE ORDER';title.textContent='Customer details';titleWrap.append(eyebrow,title);const close=document.createElement('button');close.type='button';close.setAttribute('aria-label','Close customer details');close.textContent='×';close.addEventListener('click',()=>customerDialog.close());header.append(titleWrap,close);customerDialog.append(header);
      const rows=[['Name',order.customer_name],['Phone',order.customer_phone],['Order type',order.order_type==='DELIVERY'?'Delivery':'Pickup'],['Payment option',({CASH_ON_DELIVERY:'Cash on Delivery',PAY_AT_PICKUP:'Pay at Pickup',ONLINE_PAYMENT:'Online Payment'})[order.online_payment_method]||'Not selected'],['Account',order.online_account_id?'Registered customer':'Guest checkout']];
      if(order.delivery_address)rows.push(['Delivery address',order.delivery_address]);if(order.delivery_notes)rows.push(['Delivery notes',order.delivery_notes]);if(order.special_request)rows.push(['Special requests',order.special_request]);
      for(const [labelText,valueText] of rows){const row=document.createElement('div');row.className='pos-customer-detail-row';const label=document.createElement('span'),value=document.createElement('strong');label.textContent=labelText;value.textContent=valueText||'—';row.append(label,value);customerDialog.append(row);}
      if(!customerDialog.open)customerDialog.showModal();
    });actions.append(customerButton);
  }
  if(posState.orderView!=='invoices')for(const kot of order.kots){const link=document.createElement('a');link.href=posConfig.base+'/api/pos/kot-print?id='+kot.id+'&width='+$('pos-print-width').value+'&autoprint=1';link.target='_blank';link.rel='noopener';link.textContent='Print '+kot.kot_number;actions.append(link);}
  if(order.invoice_id&&posState.orderView!=='kot'){const receipt=document.createElement('a');receipt.href=posConfig.base+'/api/pos/receipt?id='+order.invoice_id+'&width='+$('pos-print-width').value+'&autoprint=1';receipt.target='_blank';receipt.rel='noopener';receipt.textContent='Print / Reprint Bill';actions.append(receipt);}
  content.append(actions);
  if(order.invoice_id&&order.payment_status!=='PAID') {
    const receive=document.createElement('button');receive.type='button';receive.className='pos-dialog-receive';receive.textContent='Receive Payment';
    receive.addEventListener('click',()=>{
      receive.disabled=true;
      dialog.addEventListener('close',async()=>{
        try{await loadActiveOrder(Number(order.id));openPaymentDialog();}
        catch(error){posMessage(error.message);}
      },{once:true});
      dialog.close();
    });
    content.append(receive);
  }
  if(!order.invoice_id&&isUnbilledReadyOrder(order)){
    const billAndPay=document.createElement('button');billAndPay.type='button';billAndPay.className='pos-dialog-receive';billAndPay.textContent='Generate Bill & Receive Payment';
    billAndPay.addEventListener('click',()=>{billAndPay.disabled=true;dialog.addEventListener('close',async()=>{try{await loadActiveOrder(Number(order.id));collectPayment();}catch(error){posMessage(error.message);}},{once:true});dialog.close();});content.append(billAndPay);
  }
  showPosModal(dialog);
}
async function filterOrders(loadMore=false) {
  const view=['kot','invoices','payments'].includes(posState.orderView)?posState.orderView:'orders';
  const from=$('pos-order-from').value,to=$('pos-order-to').value;if(Boolean(from)!==Boolean(to)){posMessage('Choose both start and end dates, or leave the range as Any date.');return;}
  const params = new URLSearchParams({view,offset:loadMore?posState.orderResults.length:0,search: $('pos-order-search').value, status: $('pos-order-status').value, type: $('pos-order-type').value, source: $('pos-order-source').value, payment: $('pos-order-payment').value, from, to,sort:$('pos-order-sort').value||'newest'});
  try {if(view==='kot'&&!loadMore)await posRequest('kot/reconcile',{});const response = await fetch(posConfig.base + '/api/pos/orders?' + params);const result = await response.json();if (!response.ok || result.error) throw Error(result.error || 'Unable to filter orders.');posState.orderResults=loadMore?posState.orderResults.concat(result.orders):result.orders;posState.orderHasMore=Boolean(result.has_more);renderOrders(posState.orderResults);} catch (error) {posMessage(error.message);}
}
function setPosScreen(screen) {
  const current = screen === 'report' && !['RESTAURANT_ADMIN','CASHIER'].includes(posConfig.role) ? 'pos' : ['orders','report'].includes(screen) ? screen : 'pos';
  if(current==='pos')posState.orderView='pos';
  const heading = document.querySelector('.pos-header h1');
  const descriptions={pos:'Create orders, prepare bills and receive payments.',report:'Review sales and payment totals for your selected period.',orders:'Search and review dine-in, pickup and delivery orders.'};
  if(current==='orders'){
    const viewTitles={kot:'Kitchen Order Tickets',invoices:'Paid Bills',payments:'Pending Bills'};
    heading.textContent=viewTitles[posState.orderView]||'Orders';
    $('pos-header-description').textContent=({kot:'Review kitchen tickets and track order preparation.',invoices:'View orders with fully paid bills.',payments:'See unpaid bills and ready orders waiting for a bill.'}[posState.orderView]||descriptions.orders);
  }else{
    if(heading)heading.textContent=current==='report'?'POS Sales Report':'Point of Sale';
    $('pos-header-description').textContent=descriptions[current];
  }
  if(window.parent!==window)window.parent.postMessage({type:'restaurant-pos-heading',title:heading?.textContent||'Point of Sale',description:$('pos-header-description').textContent},location.origin);
  $('pos-workspace').hidden = current !== 'pos';
  $('pos-orders-section').hidden = current !== 'orders';
  $('pos-report').hidden = current !== 'report' || !['RESTAURANT_ADMIN','CASHIER'].includes(posConfig.role);
  document.querySelectorAll('.pos-sidebar-nav a').forEach(link => {
    const selected = link.hash === (current === 'orders' ? '#pos-orders-section' : current === 'report' ? '#pos-report' : '#pos-workspace');
    link.classList.toggle('is-current', selected);
    if (selected) link.setAttribute('aria-current','page'); else link.removeAttribute('aria-current');
  });
  if (current === 'report' && ['RESTAURANT_ADMIN','CASHIER'].includes(posConfig.role) && !$('pos-report-results').childElementCount) loadReport();
}
function screenFromLocation() {
  const requested = new URLSearchParams(location.search).get('view');
  if (location.hash === '#pos-workspace') return 'pos';
  if (location.hash === '#pos-report') return 'report';
  if (location.hash === '#pos-orders-section') return 'orders';
  return requested === 'report' ? 'report' : requested === 'orders' ? 'orders' : 'pos';
}
window.addEventListener('hashchange', () => {const screen=screenFromLocation();setPosScreen(screen);if(screen==='orders')filterOrders();});
window.addEventListener('message',event=>{
  if(event.origin!==location.origin||event.source!==window.parent||event.data?.type!=='restaurant-pos-view')return;
  const view=['pos','kot','invoices','payments'].includes(event.data.view)?event.data.view:'pos';
  posState.orderView=view;
  for(const id of ['pos-order-search','pos-order-status','pos-order-type','pos-order-source','pos-order-payment']) $(id).value='';for(const id of ['pos-order-from','pos-order-to']){$(id).value='';$(id).type='text';}$('pos-order-sort').value='newest';
  if(view==='pos'){setPosScreen('pos');filterOrders();return;}
  setPosScreen('orders');
  filterOrders();
});
window.addEventListener('message',async event=>{
  if(event.origin!==location.origin||event.source!==window.parent||event.data?.type!=='restaurant-pos-features')return;
  posConfig.manager_bill_edit_enabled=Number(event.data.manager_bill_edit_enabled)===1?1:0;
  if(posState.order){try{await loadActiveOrder(posState.order);return;}catch(error){posMessage(error.message);}}
  const canEdit=Boolean(posState.invoice)&&['RESTAURANT_ADMIN','CASHIER'].includes(posConfig.role)&&Boolean(posConfig.manager_bill_edit_enabled)&&posState.invoice.payment_status!=='PAID';
  if(!canEdit&&$('pos-bill-edit').open)$('pos-bill-edit').close();
  if(canEdit){try{await loadEditLines();}catch(error){posMessage(error.message);}}
  $('pos-bill-edit').hidden=!canEdit;$('pos-bill-edit-open').hidden=!canEdit;
  $('pos-audit-panel').hidden=!posState.invoice||!['RESTAURANT_ADMIN','CASHIER'].includes(posConfig.role)||!posConfig.manager_bill_edit_enabled;
});
window.addEventListener('message',async event=>{
  if(event.origin!==location.origin||event.source!==window.parent||event.data?.type!=='restaurant-pos-open-order')return;
  const id=Number(event.data.id);if(!Number.isSafeInteger(id)||id<1)return;
  try{await openOrderInPos(id);}
  catch(error){posMessage(error.message);}
});
let customerSearchTimer,customerSearchSeq=0,selectedCustomerId=null;
function positionCustomerSuggestions(){
  const list=$('pos-customer-suggestions');if(!list.matches(':popover-open'))return;
  const inputRect=$('pos-customer-phone').getBoundingClientRect();let rect=inputRect,viewportHeight=window.innerHeight,viewportWidth=window.innerWidth;
  if(posPopupPortal){try{const frameRect=window.frameElement.getBoundingClientRect();rect={left:inputRect.left+frameRect.left,right:inputRect.right+frameRect.left,top:inputRect.top+frameRect.top,bottom:inputRect.bottom+frameRect.top,width:inputRect.width};viewportHeight=window.parent.innerHeight;viewportWidth=window.parent.innerWidth;}catch(error){}}
  const below=viewportHeight-rect.bottom-10,above=rect.top-10,openAbove=below<140&&above>below;
  const height=Math.max(65,Math.min(215,openAbove?above:below));
  list.style.left=Math.max(8,Math.min(viewportWidth-rect.width-8,rect.left))+'px';list.style.width=Math.min(rect.width,viewportWidth-16)+'px';
  list.style.maxHeight=height+'px';list.style.top=(openAbove?Math.max(8,rect.top-height-5):rect.bottom+5)+'px';
}
function closeCustomerSuggestions(){customerSearchSeq++;const list=$('pos-customer-suggestions');if(list.matches(':popover-open'))list.hidePopover();list.replaceChildren();$('pos-customer-phone').setAttribute('aria-expanded','false');}
async function suggestCustomers(){
  const seq=++customerSearchSeq,phone=$('pos-customer-phone').value.replace(/\D/g,'');
  try{
    const response=await fetch(posConfig.base+'/api/pos/customer-suggest?phone='+encodeURIComponent(phone));
    const data=await response.json();if(seq!==customerSearchSeq)return;
    if(!response.ok||data.error)throw Error(data.error||'Unable to search customers.');
    const list=$('pos-customer-suggestions');list.replaceChildren();
    for(const customer of data.customers){
      const option=document.createElement('button');option.type='button';option.setAttribute('role','option');
      option.textContent=customer.phone+(customer.name?' · '+customer.name:'');
      option.addEventListener('pointerdown',event=>event.preventDefault());
      option.addEventListener('click',()=>{
        selectedCustomerId=Number(customer.id);$('pos-customer-phone').value=customer.phone;
        $('pos-customer-name').value=customer.name||'';$('pos-address').value=customer.address||'';
        $('pos-customer-find').textContent='Customer selected';$('pos-customer-find').disabled=true;
        $('pos-customer-feedback').textContent='Existing customer selected.';closeCustomerSuggestions();
      });list.append(option);
    }
    if(data.customers.length){if(!list.matches(':popover-open'))list.showPopover();positionCustomerSuggestions();}
    else if(list.matches(':popover-open'))list.hidePopover();
    $('pos-customer-phone').setAttribute('aria-expanded',String(!!data.customers.length));
  }catch(error){if(seq===customerSearchSeq)$('pos-customer-feedback').textContent=error.message;}
}
async function addCustomer(){
  if(selectedCustomerId){$('pos-customer-feedback').textContent='This customer is already selected.';return;}
  if(!/^\d{10}$/.test($('pos-customer-phone').value)){$('pos-customer-feedback').textContent='Enter a 10-digit phone number.';$('pos-customer-phone').focus();return;}
  const button=$('pos-customer-find');button.disabled=true;
  try{const result=await posRequest('customer/save',{name:$('pos-customer-name').value,phone:$('pos-customer-phone').value,address:$('pos-address').value});selectedCustomerId=Number(result.customer.id);$('pos-customer-phone').value=result.customer.phone;closeCustomerSuggestions();$('pos-customer-feedback').textContent='';button.textContent='Customer added';posMessage('Customer added.');}
  catch(error){$('pos-customer-feedback').textContent=error.message;}
  finally{button.disabled=!!selectedCustomerId;}
}
async function createOrder() {
  if (!posState.cart.size) {posMessage('Select at least one item.'); return;}
  const customerPhone=$('pos-customer-phone').value;
  if(customerPhone!==''&&!/^\d{10}$/.test(customerPhone)){$('pos-customer-details').open=true;posMessage('Enter a 10-digit customer phone number.');$('pos-customer-phone').focus();return;}
  const subtotal = [...posState.cart.values()].reduce((sum,line)=>sum+unitCents(line.item,line.portion)*line.qty,0);
  const draftDiscount = cents($('pos-draft-discount').value);
  if(draftDiscount===null||draftDiscount>subtotal){posMessage('Enter a valid discount no greater than the subtotal.');return;}
  const button = $('pos-create'); button.disabled = true; posMessage('Creating order…');
  try {
    const type = $('pos-type').value;
    const payload = {order_type: type, table_id: $('pos-table').value, guest_count: $('pos-guests').value,
      customer_name: $('pos-customer-name').value, customer_phone: $('pos-customer-phone').value,
      delivery_address: $('pos-address').value, delivery_notes: $('pos-delivery-notes').value,
      packaging_charge: $('pos-packaging-charge').value, delivery_charge: $('pos-delivery-charge').value, special_request: $('pos-note').value,
      idempotency_key: posState.submissionKey,
      items: JSON.stringify([...posState.cart.values()].map(line => ({id: line.id, qty: line.qty, portion: line.portion, note: ''})))};
    const result = await posRequest('order', payload);
    resetOrderComposer();
    await loadPos();
    posMessage(result.kot_id ? 'KOT sent automatically and is available in Kitchen Order Tickets.' : 'Order created.');
    posAlert('Order created', 'Order ' + (result.order_number || '#' + result.order_id) + ' has been created and its KOT is available in Kitchen Order Tickets. POS is ready for your next order; open the created order from Orders when you need to continue billing.');
  } catch (error) {posMessage(error.message); button.disabled = false;}
}
function resetOrderComposer(){
  posState.order=null;posState.invoice=null;posState.cart.clear();posState.submissionKey=crypto.randomUUID();posState.paymentKey=crypto.randomUUID();posState.activeSubtotal=0;posState.activePackaging=0;posState.activeDelivery=0;posState.billBreakdown=null;
  selectedCustomerId=null;clearTimeout(customerSearchTimer);closeCustomerSuggestions();
  $('pos-customer-name').value='';$('pos-customer-phone').value='';$('pos-address').value='';$('pos-delivery-notes').value='';$('pos-packaging-charge').value='0.00';$('pos-delivery-charge').value='0.00';
  $('pos-customer-find').disabled=false;$('pos-customer-find').textContent='Add customer';$('pos-customer-feedback').textContent='';
  $('pos-guests').value='1';$('pos-note').value='';$('pos-draft-discount').value='0.00';$('pos-bill-discount').value='0.00';
  $('pos-active').hidden=true;$('pos-active').closest('.pos-order').classList.remove('is-active-order');$('pos-payment').hidden=true;$('pos-create').disabled=false;
  $('pos-active-items').replaceChildren();$('pos-kot-history').replaceChildren();$('pos-kot-feedback').hidden=true;
  $('pos-customer-details').open=false;syncType();renderItems();posMessage('Ready for a new order.');
}
async function action(type, printAfter = false, openPayment = false) {
  if (!posState.order) return;
  const button = $(type === 'bill' ? 'pos-bill-payment' : 'pos-' + type); button.disabled = true; posMessage('Processing…');
  const printWindow = printAfter&&(type!=='kot'||!nativeHardwareBridge) ? window.open('', '_blank') : null;
  if (printWindow) printWindow.opener = null;
  let requestSaved=false,printFailed=false;
  try {
    if(type==='kot'&&printAfter){if(nativeHardwareBridge)await requireKitchenPrinter();else if(!printWindow)throw Error('Allow pop-ups to open the browser KOT print window.');}
    const result = await posRequest(type, {order_id: posState.order, discount: $('pos-bill-discount').value, service_charge: $('pos-bill-service').value, tax: $('pos-bill-tax').value});
    requestSaved=true;
    const printWidth = $('pos-print-width').value;
    if(type==='kot'&&printAfter){
      const printableKotId = result.print_kot_id || result.kot_id;
      if(printableKotId){try{if(nativeHardwareBridge)await printKotOnDesktop(printableKotId);else if(printWindow&&!printWindow.closed)printWindow.location=posConfig.base+'/api/pos/kot-print?id='+encodeURIComponent(printableKotId)+'&width='+encodeURIComponent(printWidth)+'&autoprint=1';else throw Error('The browser KOT print window was closed.');}catch(error){printFailed=true;throw error;}}
      else if(printWindow)printWindow.close();
    }
    if (type === 'bill') {posState.invoice = result.invoice; updateDue(); if (printWindow) printWindow.location = posConfig.base + '/api/pos/receipt?id=' + result.invoice.id + '&width=' + printWidth;}
    if(type==='kot'){
      const feedback=$('pos-kot-feedback');
      feedback.textContent=printAfter?(nativeHardwareBridge?(result.kot_id?'KOT sent to kitchen and printed.':result.print_kot_id?'Existing KOT printed again.':'No items to print.'):(result.kot_id?'KOT sent to kitchen. Browser print window opened.':result.print_kot_id?'Existing KOT browser print window opened.':'No items to print.')):result.kot_id?'KOT sent to kitchen.':result.print_kot_id?'KOT already sent; no new kitchen changes.':'No items to send to kitchen.';
      feedback.hidden=false;
    }
    posMessage(type==='kot'?(printAfter?(nativeHardwareBridge?(result.kot_id?'KOT sent to kitchen and printed.':result.print_kot_id?'Existing KOT printed again.':'No items to print.'):(result.kot_id?'KOT sent to kitchen. Browser print window opened.':result.print_kot_id?'Existing KOT browser print window opened.':'No items to print.')):result.message):'Bill generated: '+result.invoice.invoice_number);
    await loadActiveOrder(posState.order); await loadPos();
    if (openPayment && type === 'bill') openPaymentDialog();
  } catch (error) {if(type==='kot'){$('pos-kot-feedback').textContent=requestSaved&&printFailed?'KOT saved, but kitchen printing failed: '+error.message:error.message;$('pos-kot-feedback').hidden=false;}if (printWindow) printWindow.close(); posMessage(type==='kot'&&requestSaved&&printFailed?'KOT saved, but kitchen printing failed: '+error.message:error.message);} finally {button.disabled = false;}
}
function collectPayment(){
  if(posState.invoice){openPaymentDialog();return;}
  action('bill',false,true);
}
const paymentMethodLabels={CASH:'Cash',CARD:'Card',BANK_TRANSFER:'Bank Transfer',ONLINE_PAYMENT:'Online',OTHER:'Other'};
const paymentMethodIcons={
  CASH:'<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>',
  CARD:'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/>',
  BANK_TRANSFER:'<path d="m3 9 9-5 9 5M4 9h16M6 10v8m4-8v8m4-8v8m4-8v8M3 20h18"/>',
  ONLINE_PAYMENT:'<path d="M10 13a5 5 0 0 0 7.1 0l2-2a5 5 0 0 0-7.1-7.1l-1 1M14 11a5 5 0 0 0-7.1 0l-2 2a5 5 0 0 0 7.1 7.1l1-1"/>',
  OTHER:'<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>'
};
let paymentRows=[],paymentDialogKey=crypto.randomUUID(),paymentDialogSaved=false;
function paymentDueCents(){return posState.invoice?Math.max(0,Math.round((Number(posState.invoice.total)-Number(posState.invoice.paid_total))*100)):0;}
function paymentError(message){const el=$('pos-payment-error');el.textContent=message;el.hidden=!message;}
function paymentResult(message,retryPrint=false){$('pos-payment-result-text').textContent=message;$('pos-payment-retry-print').hidden=!retryPrint;$('pos-payment-result').hidden=!message;}
function positionPaymentDialog(){
  const dialog=$('pos-payment-dialog');
  if(posPopupPortal){const viewport=window.parent;const top=Math.max(8,Math.round(viewport.innerHeight*.05));const height=Math.max(220,Math.min(960,viewport.innerHeight-top-8));document.documentElement.classList.add('pos-payment-open');dialog.style.setProperty('--pos-payment-top',top+'px');dialog.style.setProperty('--pos-payment-left',Math.round(viewport.innerWidth/2)+'px');dialog.style.setProperty('--pos-payment-height',height+'px');return;}
  let frameTop=0,visibleTop=0,visibleBottom=window.innerHeight,centerX=window.innerWidth/2;
  if(window.parent!==window){
    try{const rect=window.frameElement.getBoundingClientRect();frameTop=rect.top;visibleTop=Math.max(0,rect.top);visibleBottom=Math.min(window.parent.innerHeight,rect.bottom);centerX=window.parent.innerWidth/2-rect.left;}catch(error){}
  }
  const halfWidth=Math.min(390,window.innerWidth-24)/2;
  centerX=Math.max(halfWidth+12,Math.min(window.innerWidth-halfWidth-12,centerX));
  const available=Math.max(220,visibleBottom-visibleTop);
  const topMargin=Math.max(8,Math.round(available*.05));
  const height=Math.min(960,Math.max(220,available-topMargin-8));
  const top=Math.max(10,Math.round(visibleTop+topMargin-frameTop));
  document.documentElement.classList.add('pos-payment-open');
  dialog.style.setProperty('--pos-payment-top',top+'px');
  dialog.style.setProperty('--pos-payment-left',Math.round(centerX)+'px');
  dialog.style.setProperty('--pos-payment-height',height+'px');
}
function refreshPaymentRow(row){
  row.section.querySelectorAll('[data-method]').forEach(button=>{const active=button.dataset.method===row.method;button.classList.toggle('is-active',active);button.setAttribute('aria-pressed',String(active));});
  const rowNumber=paymentRows.indexOf(row)+1;row.section.querySelector('.pos-payment-entry-head h3').textContent='Payment '+rowNumber+' · '+paymentMethodLabels[row.method];
  row.section.querySelector('.pos-payment-details h3').textContent='Payment Details ('+paymentMethodLabels[row.method]+')';
  row.cashFields.hidden=row.method!=='CASH';row.referenceField.hidden=row.method==='CASH';
  const amount=cents(row.amountInput.value),received=cents(row.receivedInput.value),change=row.changeValue;
  change.textContent=amount===null||received===null?'Enter cash amounts':received<amount?'Insufficient cash received':money(received-amount);
  row.changeBox.classList.toggle('is-invalid',amount===null||received===null||received<amount);
  const remaining=paymentDueCents(),allocated=paymentRows.reduce((sum,current)=>sum+(cents(current.amountInput.value)||0),0);
  const left=remaining-allocated;
  $('pos-payment-selected-total').textContent=money(allocated);
  $('pos-payment-selected-remaining').textContent=left<0?'Exceeds Amount Due':money(left);
  $('pos-payment-selected-remaining').classList.toggle('is-invalid',left<0);
  $('pos-payment-projected').textContent=left<0?'Exceeds Amount Due':money(left);
  const isPaid=paymentDialogSaved&&posState.invoice?.payment_status==='PAID';
  const selectedBadge=allocated>0&&allocated<remaining?'Partial Payment':posState.invoice?.payment_status==='PARTIALLY_PAID'?'Partially Paid':'Unpaid';
  $('pos-payment-status-badge').textContent=isPaid?'Paid':selectedBadge;
  $('pos-payment-status-text').textContent=isPaid?'Payment recorded successfully.':left<0?'Payments exceed Amount Due.':allocated===remaining?'Ready to record full payment.':allocated>0?'Partial payment selected.':'Enter the payment amount to continue.';
  $('pos-payment-status').classList.toggle('is-paid',isPaid);
  $('pos-payment-status').classList.toggle('is-partial',!isPaid&&allocated>0&&allocated<remaining);
  $('pos-payment-add').disabled=paymentRows.length>=3||(allocated>=remaining&&paymentRows.length>1);
}
function balanceSplitPaymentAmounts(changedRow){
  if(paymentRows.length!==2)return;
  const due=paymentDueCents(),changed=cents(changedRow.amountInput.value);
  if(changed===null)return;
  const other=paymentRows.find(row=>row!==changedRow);
  const otherAmount=Math.max(0,due-changed);
  other.amountInput.value=(otherAmount/100).toFixed(2);
  if(other.method==='CASH')other.receivedInput.value=other.amountInput.value;
}
function addPaymentRow(amountValue,method='CASH'){
  const index=paymentRows.length+1,section=document.createElement('section');section.className='pos-payment-entry';
  section.innerHTML='<div class="pos-payment-entry-head"><h3>Payment Method</h3></div><div class="pos-payment-method-grid"></div><div class="pos-payment-details"><h3></h3><label>Payment Amount (LKR)<input class="pos-payment-amount" inputmode="decimal" autocomplete="off"></label><div class="pos-payment-cash"><div class="pos-payment-presets"><button type="button" data-preset="exact">Exact</button><button type="button" data-preset="500000">LKR 5,000</button><button type="button" data-preset="1000000">LKR 10,000</button></div><label>Cash Received (LKR)<input class="pos-payment-received" inputmode="decimal" autocomplete="off"></label><div class="pos-payment-change"><span>Cash change due</span><strong></strong></div></div><label class="pos-payment-reference">Reference (optional)<input maxlength="120" autocomplete="off"></label></div>';
  if(index>1){const remove=document.createElement('button');remove.type='button';remove.className='pos-payment-remove';remove.textContent='Remove';remove.addEventListener('click',()=>{paymentRows=paymentRows.filter(row=>row.section!==section);section.remove();paymentDialogKey=crypto.randomUUID();paymentRows.forEach(refreshPaymentRow);});section.querySelector('.pos-payment-entry-head').append(remove);}
  const row={section,method,amountInput:section.querySelector('.pos-payment-amount'),receivedInput:section.querySelector('.pos-payment-received'),cashFields:section.querySelector('.pos-payment-cash'),referenceField:section.querySelector('.pos-payment-reference'),changeBox:section.querySelector('.pos-payment-change'),changeValue:section.querySelector('.pos-payment-change strong'),referenceInput:section.querySelector('.pos-payment-reference input'),key:crypto.randomUUID()};
  row.amountInput.value=(amountValue/100).toFixed(2);row.receivedInput.value=row.amountInput.value;
  const methods=section.querySelector('.pos-payment-method-grid');
  for(const [value,label] of Object.entries(paymentMethodLabels)){
    const button=document.createElement('button');button.type='button';button.dataset.method=value;button.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+paymentMethodIcons[value]+'</svg><span>'+label+'</span>';
    button.addEventListener('click',()=>{row.method=value;paymentDialogKey=crypto.randomUUID();refreshPaymentRow(row);});methods.append(button);
  }
  row.amountInput.addEventListener('input',()=>{paymentError('');paymentDialogKey=crypto.randomUUID();balanceSplitPaymentAmounts(row);paymentRows.forEach(refreshPaymentRow);});
  row.receivedInput.addEventListener('input',()=>{paymentError('');paymentDialogKey=crypto.randomUUID();refreshPaymentRow(row);});
  row.referenceInput.addEventListener('input',()=>{paymentDialogKey=crypto.randomUUID();});
  section.querySelectorAll('[data-preset]').forEach(button=>button.addEventListener('click',()=>{row.receivedInput.value=button.dataset.preset==='exact'?row.amountInput.value:(Number(button.dataset.preset)/100).toFixed(2);paymentDialogKey=crypto.randomUUID();paymentRows.forEach(refreshPaymentRow);}));
  paymentRows.push(row);$('pos-payment-methods').append(section);if(paymentRows.length===2)balanceSplitPaymentAmounts(row);refreshPaymentRow(row);return row;
}
function openPaymentDialog(){
  if(!posState.invoice)return;
  const due=paymentDueCents();if(due<1){posMessage('This bill has no remaining balance.');return;}
  const dialog=$('pos-payment-dialog'),invoice=posState.invoice;
  $('pos-payment-invoice').textContent=invoice.invoice_number;
  $('pos-payment-order-type').textContent=$('pos-active-status').textContent||'In progress';
  $('pos-payment-total').textContent=money(Math.round(Number(invoice.total)*100));$('pos-payment-paid').textContent=money(Math.round(Number(invoice.paid_total)*100));$('pos-payment-remaining').textContent=money(due);
  $('pos-payment-status-badge').textContent=invoice.payment_status==='PARTIALLY_PAID'?'Partially Paid':'Unpaid';$('pos-payment-status').classList.remove('is-paid','is-partial');paymentError('');paymentResult('');
  paymentRows=[];paymentDialogKey=crypto.randomUUID();paymentDialogSaved=false;$('pos-payment-methods').hidden=false;$('pos-payment-methods').replaceChildren();
  $('pos-payment-cancel').textContent='Cancel';$('pos-payment-record').hidden=false;$('pos-payment-record').textContent='Record Payment';$('pos-payment-record').disabled=false;$('pos-payment-print-label').textContent='Pay & Print';$('pos-payment-print').disabled=false;$('pos-payment-split').hidden=false;
  addPaymentRow(due);
  positionPaymentDialog();showPosModal(dialog);
  const scroll=dialog.querySelector('.pos-payment-scroll');scroll.scrollTop=0;
  requestAnimationFrame(()=>{if(dialog.open)scroll.scrollTop=0;});
  if(window.parent!==window)window.parent.postMessage({type:'restaurant-pos-dialog',open:true},location.origin);
}
async function submitPaymentDialog(printAfter=false){
  if(!posState.invoice)return;
  if(paymentDialogSaved){
    if(printAfter)await retrySavedPaymentReceipt();
    return;
  }
  const due=paymentDueCents(),entries=[];let total=0;
  for(const row of paymentRows){
    const amount=cents(row.amountInput.value),received=row.method==='CASH'?cents(row.receivedInput.value):null;
    if(amount===null||amount<1){paymentError('Enter a valid payment amount for every method.');row.amountInput.focus();return;}
    if(row.method==='CASH'&&(received===null||received<amount)){paymentError('Insufficient cash received.');row.receivedInput.focus();return;}
    entries.push({method:row.method,amount:row.amountInput.value,amount_received:row.method==='CASH'?row.receivedInput.value:'',reference:row.referenceInput.value});total+=amount;
  }
  if(total>due){paymentError('Payments exceed the remaining balance.');return;}
  const record=$('pos-payment-record'),print=$('pos-payment-print');record.disabled=print.disabled=true;paymentError('');
  const printWindow=printAfter&&!nativeHardwareBridge?window.open('','_blank'):null;
  if(printWindow)printWindow.opener=null;
  let result;
  try{result=entries.length===1?await posRequest('payment',{invoice_id:posState.invoice.id,...entries[0],idempotency_key:paymentRows[0].key}):await posRequest('payment/batch',{invoice_id:posState.invoice.id,payments:JSON.stringify(entries),idempotency_key:paymentDialogKey});}
  catch(error){if(printWindow)printWindow.close();paymentError(error.message);record.disabled=print.disabled=false;return;}
  const invoiceId=posState.invoice.id;posState.invoice.paid_total=result.paid_total;posState.invoice.payment_status=result.payment_status;
  let printFailure='';
  if(printAfter){
    if(nativeHardwareBridge){try{await printReceiptOnDesktop(invoiceId);}catch(error){printFailure=error.message;}}
    else if(printWindow&&!printWindow.closed){printWindow.location=posConfig.base+'/api/pos/receipt?id='+invoiceId+'&width='+$('pos-print-width').value+'&autoprint=1';}
    else printFailure='The receipt window was blocked.';
  }
  paymentDialogSaved=result.payment_status==='PAID';
  const hardwareErrors=[];
  try{const drawer=await openDrawerAfterCashPayment(entries,result,posState.invoice);if(drawer&&!drawer.ok)hardwareErrors.push('Drawer: '+(drawer.message||drawer.code||'open failed'));}
  catch(error){hardwareErrors.push('Drawer: '+error.message);}
  $('pos-payment-paid').textContent=money(Math.round(Number(result.paid_total)*100));
  $('pos-payment-remaining').textContent=money(Math.round(Number(result.balance)*100));
  $('pos-payment-projected').textContent=money(Math.round(Number(result.balance)*100));
  $('pos-payment-order-type').textContent=String(result.order_status||'').replaceAll('_',' ').toLowerCase().replace(/\b\w/g,letter=>letter.toUpperCase());
  $('pos-payment-status-badge').textContent=result.payment_status==='PAID'?'Paid':'Partially Paid';
  $('pos-payment-status').classList.toggle('is-paid',result.payment_status==='PAID');
  $('pos-payment-status').classList.toggle('is-partial',result.payment_status!=='PAID');
  const balanceText=money(Math.round(Number(result.balance)*100));
  $('pos-payment-status-text').textContent=result.payment_status==='PAID'?'Payment recorded successfully.':'Partial payment recorded. Remaining balance: '+balanceText+'.';
  $('pos-payment-cancel').textContent=result.payment_status==='PAID'?'Close':'Cancel';
  $('pos-payment-record').hidden=result.payment_status==='PAID';
  $('pos-payment-record').textContent='Record Payment';$('pos-payment-record').disabled=false;
  $('pos-payment-print-label').textContent=result.payment_status==='PAID'?'Print Receipt':'Pay & Print';$('pos-payment-print').disabled=false;
  $('pos-payment-split').hidden=result.payment_status==='PAID';
  if(result.payment_status==='PAID'){
    $('pos-payment-methods').hidden=true;
    $('pos-payment-methods').querySelectorAll('button,input').forEach(control=>control.disabled=true);
    $('pos-payment-add').disabled=true;
    $('pos-payment-selected-total').textContent=money(total);
    $('pos-payment-selected-remaining').textContent=balanceText;
    $('pos-payment-selected-remaining').classList.remove('is-invalid');
  }else{
    paymentRows=[];paymentDialogKey=crypto.randomUUID();$('pos-payment-methods').hidden=false;$('pos-payment-methods').replaceChildren();addPaymentRow(Math.round(Number(result.balance)*100));
  }
  try{await loadActiveOrder(posState.order);await loadPos();await filterOrders();}catch(error){posMessage('Payment recorded. Refresh the order to update its details.');}
  if(result.payment_status==='PAID')$('pos-payment-status-badge').textContent='Paid';
  if(printFailure){paymentResult('Payment recorded successfully, but receipt printing failed.',true);}
  else{if(printAfter)$('pos-payment-print-label').textContent=result.payment_status==='PAID'?'Reprint Receipt':'Pay & Print';paymentResult((result.payment_status==='PAID'?'Payment recorded successfully.':'Partial payment recorded. Remaining balance: '+balanceText+'.')+(printAfter?' Receipt printing started.':''));}
  if(hardwareErrors.length)paymentError('Payment saved. '+hardwareErrors.join(' · '));
}
async function retrySavedPaymentReceipt(){
  const button=$('pos-payment-retry-print');button.disabled=true;
  try{
    if(nativeHardwareBridge)await printReceiptOnDesktop(posState.invoice.id);
    else{const receiptWindow=window.open(posConfig.base+'/api/pos/receipt?id='+posState.invoice.id+'&width='+$('pos-print-width').value+'&autoprint=1','_blank');if(!receiptWindow)throw Error('The receipt window was blocked.');receiptWindow.opener=null;}
    paymentError('');paymentResult('Receipt printing started.');$('pos-payment-print-label').textContent='Reprint Receipt';
  }catch(error){paymentResult('Payment recorded successfully, but receipt printing failed.',true);paymentError(error.message);}
  finally{button.disabled=false;}
}
function updateDue() {
  if (!posState.invoice) return;
  const due = Math.max(0, Math.round((Number(posState.invoice.total) - Number(posState.invoice.paid_total)) * 100));
  $('pos-due').textContent = 'Remaining: ' + money(due) + ' · ' + posState.invoice.payment_status;
  $('pos-pay-amount').value = (due / 100).toFixed(2);
  $('pos-pay').disabled = posState.invoice.payment_status === 'PAID';
}
async function previewBill() {
  if (!posState.order) return;
  if(posState.invoice){showActualBill(posState.invoice);return;}
  await generateBillInPreview();
}
function compactBillFrame(frame){
  try{
    const style=frame.contentDocument.createElement('style');
    style.textContent='body{font-size:10.5px;line-height:1.3;padding:4px}header p{margin:3px 0}h1{font-size:15px;margin:2px 0}.logo{max-width:36px;max-height:36px}.rule{margin:6px 0}body>p{margin:6px 0}th,td{padding:2px 1px}.total{margin:2px 0}.thanks{margin-top:9px}';
    frame.contentDocument.head.append(style);
  }catch(error){}
  $('pos-preview-print').disabled=false;
}
function showActualBill(invoice) {
  const dialog=$('pos-preview-dialog');
  if(posPopupPortal){
    const viewport=window.parent;dialog.style.setProperty('--pos-preview-top',Math.round(viewport.innerHeight*.05)+'px');
    dialog.style.setProperty('--pos-preview-height',Math.min(900,Math.round(viewport.innerHeight*.94))+'px');
    dialog.style.setProperty('--pos-receipt-height',Math.min(480,Math.round(viewport.innerHeight*.52))+'px');
  }else if(window.parent!==window){
    try{
      const frameTop=window.frameElement.getBoundingClientRect().top;
      dialog.style.setProperty('--pos-preview-top',Math.max(12,Math.round(window.parent.innerHeight*.05-frameTop))+'px');
      dialog.style.setProperty('--pos-preview-height',Math.min(900,Math.round(window.parent.innerHeight*.94))+'px');
      dialog.style.setProperty('--pos-receipt-height',Math.min(480,Math.round(window.parent.innerHeight*.52))+'px');
    }catch(error){}
  }
  $('pos-preview-title').textContent='Bill · '+invoice.invoice_number;
  $('pos-preview-print').disabled=true;
  $('pos-preview-print').textContent='Print bill';
  const frame=document.createElement('iframe');frame.className='pos-receipt-frame';frame.title='Generated bill '+invoice.invoice_number;
  frame.addEventListener('load',()=>compactBillFrame(frame));
  frame.src=posConfig.base+'/api/pos/receipt?id='+encodeURIComponent(invoice.id)+'&width='+encodeURIComponent($('pos-print-width').value)+'&embed=1';
  $('pos-preview-content').replaceChildren(frame);
  showPosModal(dialog);
}
async function generateBillInPreview() {
  const button=$('pos-preview');button.disabled=true;posMessage('Generating bill…');
  try {
    const result=await posRequest('bill',{order_id:posState.order,discount:$('pos-bill-discount').value,service_charge:$('pos-bill-service').value,tax:$('pos-bill-tax').value});
    posState.invoice=result.invoice;updateDue();
    showActualBill(result.invoice);
    await loadActiveOrder(posState.order);await loadPos();
    posMessage('Bill generated: '+result.invoice.invoice_number);
  }catch(error){posMessage(error.message);}finally{button.disabled=false;}
}
function printBillPreview() {
  const frame=$('pos-preview-content').querySelector('iframe');
  if(!frame||!frame.contentWindow)return;
  if(nativeHardwareBridge&&posState.invoice){$('pos-preview-print').disabled=true;printReceiptOnDesktop(posState.invoice.id,$('pos-print-width')?.value||configuredPrintWidth).then(()=>posMessage('Receipt printed.')).catch(error=>posMessage('Bill is saved, but printing failed: '+error.message)).finally(()=>$('pos-preview-print').disabled=false);return;}
  frame.contentWindow.focus();frame.contentWindow.print();
}
async function pay() {
  if (!posState.invoice) return;
  const button = $('pos-pay'); button.disabled = true; posMessage('Recording payment…');
  try {
    const result = await posRequest('payment', {invoice_id: posState.invoice.id, method: $('pos-method').value,
      amount: $('pos-pay-amount').value, amount_received: $('pos-cash-received').value,
      reference: $('pos-reference').value, idempotency_key: posState.paymentKey});
    posState.paymentKey = crypto.randomUUID();
    posState.invoice.paid_total = result.paid_total; posState.invoice.payment_status = result.payment_status;
    updateDue(); posMessage('Payment recorded. Balance ' + money(Math.round(Number(result.balance) * 100)) + '. Change ' + money(Math.round(Number(result.change) * 100)) + '.');
    if($('pos-method').value==='CASH'&&nativeHardwareBridge&&!result.already_recorded){const drawer=await nativeHardwareCommand('OPEN_CASH_DRAWER',{reason:'cash_payment_recorded',invoiceNo:posState.invoice.invoice_number||'',webUserId:String(posConfig.userId||'')});if(!drawer.ok)posMessage('Payment recorded, but the cash drawer did not open: '+(drawer.message||drawer.code));}
    await loadActiveOrder(posState.order); await loadPos();
  } catch (error) {posMessage(error.message);} finally {button.disabled = false;}
}
async function loadActiveOrder(id) {
  const response = await fetch(posConfig.base + '/api/pos/order-details?id=' + encodeURIComponent(id));
  const result = await response.json(); if (!response.ok || result.error) throw Error(result.error || 'Unable to load order.');
  const order = result.order;
  if(order.manager_bill_edit_enabled!==undefined)posConfig.manager_bill_edit_enabled=Number(order.manager_bill_edit_enabled)===1?1:0;
  const finalStageLabel=order=>order.order_type==='DELIVERY'?'Sent':order.order_type==='TAKEAWAY'?'Picked up':'Served';
  const progressSteps=[['PENDING','Placed'],['ACCEPTED','Accepted'],['PREPARING','Preparing'],['READY','Ready'],['SERVED',finalStageLabel(order)]];
  const statusIndex=progressSteps.findIndex(([status])=>status===order.status);
  const statusList=$('pos-active-status-steps');statusList.replaceChildren();
  progressSteps.forEach(([status,label],index)=>{const step=document.createElement('li');step.className='pos-status-step';if(index<statusIndex||order.status==='COMPLETED')step.classList.add('is-complete');if(index===statusIndex)step.classList.add('is-current');const dot=document.createElement('span');dot.className='pos-status-dot';dot.textContent=index<=statusIndex||order.status==='COMPLETED'?'✓':'';const caption=document.createElement('span');caption.className='pos-status-label';caption.textContent=label;step.append(dot,caption);statusList.append(step);});
  const nextStatus={PENDING:['ACCEPTED','Accept order'],ACCEPTED:['PREPARING','Start preparing'],PREPARING:['READY','Mark ready']}[order.status]||(order.status==='READY'?['SERVED','Mark '+finalStageLabel(order).toLowerCase()]:null);
  const statusButton=$('pos-active-status-action');statusButton.hidden=!nextStatus;if(nextStatus){statusButton.textContent=nextStatus[1];statusButton.dataset.expectedStatus=order.status;statusButton.dataset.nextStatus=nextStatus[0];statusButton.disabled=false;}
  if(posState.kotFeedbackOrder!==Number(order.id)){$('pos-kot-feedback').hidden=true;posState.kotFeedbackOrder=Number(order.id);}
  const hasKot=Array.isArray(order.kots)&&order.kots.length>0;
  const preparingStarted=['PREPARING','READY','SERVED','COMPLETED'].includes(order.status);
  const kotAlreadySent=preparingStarted||hasKot;
  const kotPending=Boolean(order.kot_pending);
  const orderClosed=['REJECTED','CANCELLED','COMPLETED'].includes(order.status);
  if(kotAlreadySent&&hasKot){$('pos-kot-feedback').textContent='KOT already sent. View or print it from Kitchen Order Tickets.';$('pos-kot-feedback').hidden=false;}
  else if(posState.kotFeedbackOrder===Number(order.id))$('pos-kot-feedback').hidden=true;
  if (posState.order !== Number(order.id)) $('pos-bill-discount').value = '0.00';
  if (posState.invoice && Number(posState.invoice.id) !== Number(order.invoice_id)) posState.paymentKey = crypto.randomUUID();
  posState.order = Number(order.id);
  posState.activeOrderType=order.order_type;
  posState.invoice = order.invoice_id ? {id: Number(order.invoice_id), invoice_number: order.invoice_number, total: order.invoice_total, paid_total: order.paid_total, payment_status: order.payment_status} : null;
  const orderTypeLabel=order.order_type==='DELIVERY'?'Delivery':order.order_type==='TAKEAWAY'?'Pick up':order.source==='QR'?'QR · Dining':'Waiter ordered · Dining';
  $('pos-active').hidden = false; $('pos-active-title').textContent = order.order_number + ' · ' + orderTypeLabel;
  $('pos-active-status').textContent=order.status==='SERVED'?finalStageLabel(order):order.status.replaceAll('_',' ').toLowerCase().replace(/\b\w/g,letter=>letter.toUpperCase());
  let customerPanel=$('pos-active-customer');if(!customerPanel){customerPanel=document.createElement('section');customerPanel.id='pos-active-customer';customerPanel.className='pos-active-customer';$('pos-active-order-status').after(customerPanel);}customerPanel.replaceChildren();
  if(order.source==='QR'&&!order.table_id&&['DELIVERY','TAKEAWAY'].includes(order.order_type)){
    const heading=document.createElement('div');heading.className='pos-active-customer-heading';const title=document.createElement('strong');title.textContent='Online customer';const badge=document.createElement('span');badge.textContent=order.order_type==='DELIVERY'?'Delivery':'Pickup';heading.append(title,badge);customerPanel.append(heading);
    const details=[['Customer',order.customer_name],['Phone',order.customer_phone],...(order.order_type==='DELIVERY'?[['Delivery address',order.delivery_address],['Delivery notes',order.delivery_notes]]:[['Pickup',"Customer will collect this order"]]),['Payment option',String(order.online_payment_method||'').replaceAll('_',' ')]];
    details.filter(([,value])=>String(value||'').trim()).forEach(([label,value])=>{const row=document.createElement('div');row.className='pos-active-customer-row';const key=document.createElement('span');key.textContent=label;const val=document.createElement('strong');val.textContent=String(value);row.append(key,val);customerPanel.append(row);});
  }
  $('pos-active').closest('.pos-order').classList.add('is-active-order');
  posState.activeSubtotal = 0;
  posState.activePackaging = Math.round(Number(order.packaging_charge || 0) * 100);
  posState.activeDelivery = Math.round(Number(order.delivery_charge || 0) * 100);
  posState.billBreakdown = null;
  $('pos-active-items').replaceChildren();
  let activeSubtotal = 0;
  for (const item of order.items) {
    activeSubtotal += Math.round(Number(item.subtotal) * 100);
    const row = document.createElement('div'); row.className = 'pos-cart-row';
    const thumb = document.createElement(item.image_path ? 'img' : 'span'); thumb.className = 'pos-cart-thumb';
    if (item.image_path) {thumb.src = posConfig.assetBase + '/' + item.image_path; thumb.alt = ''; thumb.loading = 'lazy';} else {thumb.textContent = '🍽';}
    const label = document.createElement('div'); label.textContent = item.item_name + (item.portion ? ' (' + item.portion + ')' : '');
    const detail = document.createElement('small'); detail.textContent = item.quantity + ' × ' + money(Math.round(Number(item.unit_price) * 100)) + ' = ' + money(Math.round(Number(item.subtotal) * 100)); label.append(detail); row.append(thumb,label);
    if (!posState.invoice) {
      const controls = document.createElement('div'); controls.className = 'pos-qty';
      const minus = document.createElement('button'); minus.textContent = '−'; minus.title = 'Decrease / remove'; minus.onclick = () => changeActiveItem(item, Number(item.quantity) - 1);
      const plus = document.createElement('button'); plus.textContent = '+'; plus.title = 'Increase'; plus.onclick = () => changeActiveItem(item, Number(item.quantity) + 1);
      const count=document.createElement('span');count.textContent=item.quantity;controls.append(minus,count,plus); row.append(controls);
    }
    $('pos-active-items').append(row);
  }
  $('pos-active').querySelector('.pos-line-head span').textContent = 'ITEMS (' + order.items.reduce((count,item)=>count+Number(item.quantity),0) + ')';
  $('pos-kot-history').replaceChildren();
  const kotLinks=order.kots.map(kot=>{const link=document.createElement('a');link.href=posConfig.base+'/api/pos/kot-print?id='+kot.id+'&width='+$('pos-print-width').value+'&autoprint=1';link.target='_blank';link.rel='noopener';link.textContent='Print '+kot.kot_number;return link;});
  if(kotLinks.length>1){const history=document.createElement('details');history.className='pos-kot-history-details';const summary=document.createElement('summary');summary.textContent='Print KOT history ('+kotLinks.length+')';const list=document.createElement('div');list.className='pos-kot-history-links';list.append(...kotLinks);history.append(summary,list);$('pos-kot-history').append(history);}
  else if(kotLinks.length)$('pos-kot-history').append(kotLinks[0]);
  $('pos-payment').hidden = true; if (posState.invoice) updateDue();
  posState.activeSubtotal = activeSubtotal;
  if (posState.invoice) {
    try {const billed = await fetch(posConfig.base + '/api/pos/bill-details?id=' + posState.invoice.id);const detail = await billed.json();if (billed.ok && detail.invoice) {posState.billBreakdown = detail.invoice;$('pos-bill-discount').value = Number(detail.invoice.discount).toFixed(2);}} catch (_) {}
  }
  renderActiveTotals();
  if (!posState.invoice) $('pos-bill-service').value = (serviceCents(activeSubtotal,order.order_type) / 100).toFixed(2);
  $('pos-bill-service').closest('label').hidden=order.order_type!=='DINE_IN';
  $('pos-bill-payment').disabled = !!posState.invoice && posState.invoice.payment_status === 'PAID';
  for (const id of ['pos-bill-discount','pos-bill-service','pos-bill-tax']) $(id).disabled = !!posState.invoice;
  $('pos-bill-edit').hidden = !posState.invoice || !['RESTAURANT_ADMIN','CASHIER'].includes(posConfig.role) || !posConfig.manager_bill_edit_enabled || posState.invoice.payment_status === 'PAID';
  $('pos-bill-edit-open').hidden = $('pos-bill-edit').hidden;
  $('pos-audit-panel').hidden = !posState.invoice || !['RESTAURANT_ADMIN','CASHIER'].includes(posConfig.role) || !posConfig.manager_bill_edit_enabled;
  $('pos-kot').disabled=orderClosed;
  const preparingDone=['READY','SERVED','COMPLETED'].includes(order.status);
  $('pos-kot-only').textContent=kotPending?(hasKot?'Send KOT Update':'Send KOT'):preparingStarted?(preparingDone?'Preparing Done':'Preparing Started'):hasKot?'KOT Already Sent':'Send KOT';
  $('pos-kot-only').disabled=orderClosed||(!kotPending&&kotAlreadySent);
  $('pos-create').disabled = true; renderItems();
  if (posState.invoice && !$('pos-bill-edit').hidden) await loadEditLines();
}
async function changeActiveItem(item, quantity) {
  if (quantity < 0 || quantity > 99) return;
  if (quantity === 0 && !confirm('Remove ' + item.item_name + ' from this order?')) return;
  try {
    const result = await posRequest('order/item', {order_id: posState.order, action: quantity === 0 ? 'REMOVE' : 'QUANTITY', order_item_id: item.id, quantity});
    await loadActiveOrder(posState.order); await loadPos();
    posMessage('Order updated.' + (result.kot_id ? ' Kitchen update recorded.' : result.kot_pending ? ' Select Send KOT Update when ready to send the change to the kitchen.' : ''));
  } catch (error) {posMessage(error.message);}
}
async function loadEditLines() {
  const response = await fetch(posConfig.base + '/api/pos/bill-details?id=' + posState.invoice.id);
  const result = await response.json(); if (!response.ok || result.error) throw Error(result.error || 'Unable to load bill.');
  $('pos-edit-line').replaceChildren();
  for (const line of result.invoice.items) $('pos-edit-line').add(new Option(line.item_name + ' · ' + line.quantity + ' × ' + line.unit_price, line.id));
  $('pos-edit-menu').replaceChildren();
  for (const item of posState.items.filter(item => Number(item.is_available))) $('pos-edit-menu').add(new Option(item.name, item.id));
  syncEditPortions();
  syncEditForm();
}
function syncEditPortions(){
  const item=posState.items.find(entry=>String(entry.id)===$('pos-edit-menu').value),select=$('pos-edit-portion');
  select.replaceChildren();
  const options=item?itemOptions(item):[];
  if(!options.length)select.add(new Option('Regular',''));
  else for(const option of options)select.add(new Option(option.name,option.portion));
}
function syncEditForm() {
  const action = $('pos-edit-action').value;
  $('pos-edit-line').closest('label').hidden = action !== 'ITEM_REMOVED';
  $('pos-edit-menu').closest('label').hidden = action !== 'ITEM_ADDED';
  $('pos-edit-portion').closest('label').hidden = action !== 'ITEM_ADDED' || $('pos-edit-portion').options.length===1 && $('pos-edit-portion').value==='';
  $('pos-edit-quantity').closest('label').hidden = action !== 'ITEM_ADDED';
}
async function saveBillEdit() {
  const action = $('pos-edit-action').value;
  if (action === 'ITEM_REMOVED' && !confirm('Remove this billed item and send a kitchen cancellation if needed?')) return;
  const button = $('pos-edit-save'); button.disabled = true;
  try {
    const result = await posRequest('bill/edit', {invoice_id: posState.invoice.id, action,
      invoice_item_id: $('pos-edit-line').value, menu_item_id: $('pos-edit-menu').value,
      portion: $('pos-edit-portion').value, quantity: $('pos-edit-quantity').value,
      reason: $('pos-edit-reason').value});
    $('pos-edit-reason').value = ''; $('pos-bill-edit').close(); await loadActiveOrder(posState.order); await loadPos();
    posMessage('Bill correction saved and audited.' + (result.kot_id ? ' Kitchen update recorded.' : ''));
  } catch (error) {posMessage(error.message);} finally {button.disabled = false;}
}
async function showAudit() {
  if (!posState.invoice) return;
  const response = await fetch(posConfig.base + '/api/pos/audit?invoice_id=' + posState.invoice.id);
  const result = await response.json(); if (!response.ok || result.error) {posMessage(result.error || 'Unable to load audit history.'); return;}
  $('pos-audit-results').replaceChildren();
  if (!result.edits.length) {$('pos-audit-results').textContent = 'No bill edits yet.'; return;}
  for (const edit of result.edits) {
    const row = document.createElement('p'); row.textContent = edit.created_at + ' · ' + edit.user_name + ' · ' + edit.action_type + ' · Previous: ' + edit.previous_value + ' · New: ' + edit.new_value + (edit.reason ? ' · Reason: ' + edit.reason : '');
    $('pos-audit-results').append(row);
  }
}
async function loadReport() {
  const from = $('pos-report-from').value, to = $('pos-report-to').value;
  const resultBox = $('pos-report-results'); resultBox.textContent = 'Loading report…';
  try {
    const response = await fetch(posConfig.base + '/api/pos/reports?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to));
    const report = await response.json(); if (!response.ok || report.error) throw Error(report.error || 'Unable to load report.');
    const amount=value=>Number(value||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
    const count=value=>Number(value||0).toLocaleString('en-LK');
    const icons={
      invoices:'<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 12h7M9 16h7"/>',
      sales:'<path d="M4 19V9m6 10V5m6 14v-7m5 7V3"/>',
      discount:'<path d="M20 13 12 21l-9-9V4h8z"/><circle cx="7.5" cy="7.5" r="1"/><path d="m9 15 6-6"/>',
      service:'<circle cx="12" cy="12" r="8"/><path d="M12 7v10M15 9H10.8a1.8 1.8 0 0 0 0 3.6h2.4a1.8 1.8 0 0 1 0 3.6H9"/>',
      tax:'<circle cx="12" cy="12" r="9"/><path d="m8 16 8-8"/><circle cx="8.5" cy="8.5" r="1"/><circle cx="15.5" cy="15.5" r="1"/>',
      packaging:'<path d="m4 7 8-4 8 4v10l-8 4-8-4z"/><path d="m4 7 8 4 8-4M12 11v10M8 5l8 4"/>',
      delivery:'<path d="M3 7h11v10H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
      billed:'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h3"/>',
      tip:'<path d="M12 3v18M17 7.5c0-1.9-2.2-3.5-5-3.5S7 5.1 7 7s2.2 3 5 3 5 1.3 5 3-2.2 4-5 4-5-1.6-5-3.5"/>',
      payment:'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18"/>',
      orders:'<path d="M4 20V9m5 11V4m5 16v-7m5 7V6"/>',
      trend:'<path d="M3 18h18M5 15l5-5 4 3 6-7"/><path d="M16 6h4v4"/>'
    };
    const svgIcon=(name)=>{const icon=document.createElement('span');icon.className='pos-report-icon';icon.setAttribute('aria-hidden','true');icon.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'+(icons[name]||icons.sales)+'</svg>';return icon;};
    const sectionHeading=(title,iconName)=>{const head=document.createElement('div');head.className='pos-report-panel-heading';head.append(svgIcon(iconName));const text=document.createElement('strong');text.textContent=title;head.append(text);const arrow=document.createElement('span');arrow.className='pos-report-panel-arrow';arrow.setAttribute('aria-hidden','true');arrow.textContent='›';head.append(arrow);return head;};
    const makeTable=(columns,rows,totalRow)=>{const table=document.createElement('table');table.className='pos-report-table';const thead=document.createElement('thead'),head=document.createElement('tr');columns.forEach(column=>{const th=document.createElement('th');th.textContent=column.label;head.append(th);});thead.append(head);table.append(thead);const tbody=document.createElement('tbody');if(!rows.length){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=columns.length;td.className='pos-report-empty';td.textContent='No data for this period';tr.append(td);tbody.append(tr);}rows.forEach(row=>{const tr=document.createElement('tr');columns.forEach(column=>{const td=document.createElement('td');td.textContent=column.value(row);tr.append(td);});tbody.append(tr);});if(totalRow){const tr=document.createElement('tr');tr.className='pos-report-total-row';totalRow.forEach(value=>{const td=document.createElement('td');td.textContent=value;tr.append(td);});tbody.append(tr);}table.append(tbody);return table;};
    const methods=report.payment_methods||[],mix=report.by_type||[],daily=report.daily||[],monthly=report.monthly||[];
    const methodTotal=methods.reduce((sum,row)=>sum+Number(row.amount||0),0),orderTotal=mix.reduce((sum,row)=>sum+Number(row.order_count||0),0);
    const colors=['#2ab67c','#4285ed','#f5ad27','#8b71e8','#e56e86','#32a7b8'];
    const donut=(items,valueField,total,label,formatter)=>{const wrap=document.createElement('div');wrap.className='pos-report-donut-wrap';const chart=document.createElement('div');chart.className='pos-report-donut';let cursor=0;const stops=items.map((item,index)=>{const part=total?Math.max(0,Number(item[valueField]||0))/total*100:0;const stop=`${colors[index%colors.length]} ${cursor}% ${cursor+part}%`;cursor+=part;return stop;});chart.style.background=stops.length&&cursor?`conic-gradient(${stops.join(',')})`:'conic-gradient(#dfe9e3 0% 100%)';const center=document.createElement('div');center.className='pos-report-donut-center';const strong=document.createElement('strong');strong.textContent=formatter(total);const small=document.createElement('small');small.textContent=label;center.append(strong,small);chart.append(center);wrap.append(chart);const legend=document.createElement('ul');legend.className='pos-report-legend';items.forEach((item,index)=>{const li=document.createElement('li'),mark=document.createElement('i');mark.style.background=colors[index%colors.length];const copy=document.createElement('span');copy.className='pos-report-legend-copy';const name=document.createElement('strong');name.textContent=item.label;const pct=document.createElement('small');const value=Number(item[valueField]||0),share=total?value/total*100:0;pct.textContent=`${share.toFixed(1)}%`;copy.append(name,pct);const amountText=document.createElement('b');amountText.textContent=formatter(value);li.append(mark,copy,amountText);legend.append(li);});if(!items.length){const li=document.createElement('li');li.className='pos-report-empty';li.textContent='No payments recorded';legend.append(li);}wrap.append(legend);return wrap;};
    const lineChart=rows=>{const ns='http://www.w3.org/2000/svg',svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox','0 0 360 190');svg.setAttribute('role','img');svg.setAttribute('aria-label','Daily sales trend');svg.classList.add('pos-report-line-chart');const values=rows.map(row=>Number(row.total||0)),max=Math.max(1,...values),left=39,right=350,top=14,bottom=151,width=right-left,height=bottom-top;for(let i=0;i<=4;i++){const y=top+height*i/4,value=max*(1-i/4),line=document.createElementNS(ns,'line');line.setAttribute('x1',left);line.setAttribute('x2',right);line.setAttribute('y1',y);line.setAttribute('y2',y);line.setAttribute('class','pos-report-gridline');svg.append(line);const text=document.createElementNS(ns,'text');text.setAttribute('x','1');text.setAttribute('y',y+4);text.setAttribute('class','pos-report-axis');text.textContent=value>=1000?`${Math.round(value/1000)}K`:Math.round(value);svg.append(text);}const points=rows.map((row,index)=>({x:rows.length<2?left+width/2:left+width*index/(rows.length-1),y:bottom-height*Number(row.total||0)/max,row}));if(points.length){const area=document.createElementNS(ns,'polygon');area.setAttribute('points',`${left},${bottom} ${points.map(point=>`${point.x},${point.y}`).join(' ')} ${right},${bottom}`);area.setAttribute('class','pos-report-chart-area');svg.append(area);const polyline=document.createElementNS(ns,'polyline');polyline.setAttribute('points',points.map(point=>`${point.x},${point.y}`).join(' '));polyline.setAttribute('class','pos-report-chart-line');svg.append(polyline);points.forEach((point,index)=>{const dot=document.createElementNS(ns,'circle');dot.setAttribute('cx',point.x);dot.setAttribute('cy',point.y);dot.setAttribute('r','3.5');dot.setAttribute('class','pos-report-chart-dot');svg.append(dot);if(index===0||index===points.length-1||index===Math.floor(points.length/2)){const text=document.createElementNS(ns,'text');text.setAttribute('x',point.x);text.setAttribute('y','178');text.setAttribute('text-anchor','middle');text.setAttribute('class','pos-report-axis');const date=String(point.row.sale_date||'');text.textContent=date.length>=10?date.slice(5):date;svg.append(text);}});}else{const empty=document.createElementNS(ns,'text');empty.setAttribute('x','180');empty.setAttribute('y','90');empty.setAttribute('text-anchor','middle');empty.setAttribute('class','pos-report-axis');empty.textContent='No sales data';svg.append(empty);}return svg;};
    const metricSpecs=[['Invoices',count(report.summary.invoice_count),'invoices','blue',null],['Gross sales',amount(report.summary.gross_sales),'sales','green',null],['Tips',amount(report.summary.tips),'tip','green',null],['Discounts',amount(report.summary.discounts),'discount','red',null],['Service charges',amount(report.summary.service_charges),'service','teal',null],['Tax',amount(report.summary.tax),'tax','sky',null],['Packaging charges',amount(report.summary.packaging_charges),'packaging','violet',null],['Delivery charges',amount(report.summary.delivery_charges),'delivery','violet',null],['Net billed',amount(report.summary.billed_total),'billed','amber',null]];
    resultBox.replaceChildren();resultBox.className='pos-report-results';
    const metrics=document.createElement('div');metrics.className='pos-report-metrics';metricSpecs.forEach(([label,value,icon,color])=>{const card=document.createElement('article');card.className=`pos-report-metric is-${color}`;card.append(svgIcon(icon));const text=document.createElement('div'),small=document.createElement('small'),strong=document.createElement('strong');small.textContent=label;strong.textContent=value;text.append(small,strong);card.append(text);metrics.append(card);});resultBox.append(metrics);
    const meta=document.createElement('div');meta.className='pos-report-meta';meta.textContent=`Collected ${amount(report.summary.collected)}  ·  Cancelled orders ${count(report.cancelled_orders)}  ·  Manager-edited bills ${count(report.manager_edited_bills)}`;resultBox.append(meta);
    const charts=document.createElement('div');charts.className='pos-report-chart-grid';
    const paymentPanel=document.createElement('article');paymentPanel.className='pos-report-panel';paymentPanel.append(sectionHeading('Payment methods','payment'));paymentPanel.append(donut(methods.map(row=>({...row,label:String(row.method||'Other')})),'amount',methodTotal,amount(methodTotal),amount));charts.append(paymentPanel);
    const orderPanel=document.createElement('article');orderPanel.className='pos-report-panel';orderPanel.append(sectionHeading('Order type / source','orders'));orderPanel.append(donut(mix.map(row=>({...row,label:`${String(row.order_type||'').replaceAll('_',' ')} (${row.source})`})),'order_count',orderTotal,`${count(orderTotal)} Orders`,count));charts.append(orderPanel);
    const trendPanel=document.createElement('article');trendPanel.className='pos-report-panel';trendPanel.append(sectionHeading('Sales trend (Daily)','trend'));trendPanel.append(lineChart(daily));charts.append(trendPanel);resultBox.append(charts);
    const details=document.createElement('div');details.className='pos-report-detail-grid';
    const paymentDetails=document.createElement('article');paymentDetails.className='pos-report-panel';paymentDetails.append(sectionHeading('Payment methods details','payment'));paymentDetails.append(makeTable([{label:'Method',value:row=>String(row.method||'').replaceAll('_',' ')},{label:'Amount',value:row=>amount(row.amount)},{label:'Share',value:row=>`${methodTotal?(Number(row.amount||0)/methodTotal*100).toFixed(1):'0.0'}%`}],methods,methods.length?['Total',amount(methodTotal),'100%']:null));details.append(paymentDetails);
    const orderDetails=document.createElement('article');orderDetails.className='pos-report-panel';orderDetails.append(sectionHeading('Order type / source details','orders'));orderDetails.append(makeTable([{label:'Order type',value:row=>String(row.order_type||'').replaceAll('_',' ')},{label:'Source',value:row=>row.source},{label:'Orders',value:row=>count(row.order_count)},{label:'Billed total',value:row=>amount(row.billed_total)}],mix,mix.length?['Total','',count(orderTotal),amount(report.summary.billed_total)]:null));details.append(orderDetails);
    const salesDetails=document.createElement('div');salesDetails.className='pos-report-sales-tables';const dailyPanel=document.createElement('article');dailyPanel.className='pos-report-panel';dailyPanel.append(sectionHeading('Daily sales','trend'));dailyPanel.append(makeTable([{label:'Date',value:row=>row.sale_date},{label:'Orders',value:row=>count(row.order_count)},{label:'Total',value:row=>amount(row.total)}],daily));const monthlyPanel=document.createElement('article');monthlyPanel.className='pos-report-panel';monthlyPanel.append(sectionHeading('Monthly sales','sales'));monthlyPanel.append(makeTable([{label:'Month',value:row=>row.month},{label:'Orders',value:row=>count(row.order_count)},{label:'Total',value:row=>amount(row.total)}],monthly));salesDetails.append(dailyPanel,monthlyPanel);details.append(salesDetails);resultBox.append(details);
  } catch (error) {resultBox.textContent = error.message;}
}
function renderActiveTotals() {
  const summary = $('pos-active-totals'); if (!summary || !posState.order) return;
  const billed = posState.billBreakdown;
  const subtotal = billed ? Math.round(Number(billed.subtotal) * 100) : posState.activeSubtotal || 0;
  const orderType=posState.activeOrderType||'DINE_IN';
  const service = billed ? Math.round(Number(billed.service_charge) * 100) : serviceCents(subtotal,orderType);
  const discount = billed ? Math.round(Number(billed.discount) * 100) : cents($('pos-bill-discount').value);
  const tax = billed ? Math.round(Number(billed.tax) * 100) : cents($('pos-bill-tax').value);
  const packaging = billed ? Math.round(Number(billed.packaging_charge || 0) * 100) : posState.activePackaging || 0;
  const delivery = billed ? Math.round(Number(billed.delivery_charge) * 100) : posState.activeDelivery || 0;
  const grand = billed ? Math.round(Number(billed.total) * 100) : discount === null || tax === null ? null : subtotal + service - discount + tax + packaging + delivery;
  const values = [['Subtotal', subtotal], ...(orderType==='DINE_IN'?[['Service charge (' + posConfig.serviceChargePercent + '%)', service]]:[]), ['Discount', discount], ['Tax', tax], ...(packaging>0?[['Packaging charge',packaging]]:[]), ...(delivery>0?[['Delivery charge',delivery]]:[]), [billed ? 'Billed total' : 'Estimated total', grand]];
  summary.replaceChildren();
  for (const [label, value] of values) {
    const row = document.createElement('div'); row.className = 'pos-summary-row' + (label.includes('total') ? ' is-grand' : '');
    const name = document.createElement('span'); name.textContent = label;
    const amount = document.createElement('strong'); amount.textContent = value === null ? 'Check amount' : money(value);
    row.append(name, amount); summary.append(row);
  }
}
function organizePosActions() {
  const bar = $('pos-kot-only').closest('.pos-actions');
  const orderPanel = bar.closest('.pos-order');
  const primary = document.createElement('div'); primary.className = 'pos-primary-actions';
  primary.append($('pos-kot-only'), $('pos-bill-payment'));
  const billTools=document.createElement('div');billTools.className='pos-bill-tools';billTools.append($('pos-bill-edit-open'),$('pos-audit'));
  const auditPanel=$('pos-audit-panel');auditPanel.prepend(billTools);primary.append(auditPanel);
  const kotFeedback=document.createElement('p');kotFeedback.id='pos-kot-feedback';kotFeedback.className='pos-kot-feedback';kotFeedback.setAttribute('role','status');kotFeedback.hidden=true;primary.append(kotFeedback);
  const secondary = document.createElement('div'); secondary.className = 'pos-secondary-actions';
  for (const id of ['pos-kot','pos-preview']) secondary.append($(id));
  document.querySelector('.pos-menu .pos-toolbar')?.prepend($('pos-new'));
  bar.replaceChildren(primary,secondary);
  const totals = document.createElement('div'); totals.id = 'pos-active-totals'; totals.className = 'pos-active-totals';
  bar.before(totals);
  for (const id of ['pos-bill-discount','pos-bill-tax']) $(id).addEventListener('input', renderActiveTotals);
  const draftAction = document.createElement('div'); draftAction.className = 'pos-draft-action'; draftAction.append($('pos-create'));
  const message = $('pos-message');
  const scroll = document.createElement('div'); scroll.className = 'pos-order-scroll';
  while (orderPanel.firstChild) scroll.append(orderPanel.firstChild);
  scroll.removeChild(message);
  orderPanel.append(scroll,message,draftAction,primary);
}
organizePosActions();
function enhanceOrderForm() {
  const icons={DINE_IN:'<path d="M5 3v8m4-8v8M7 3v18M5 11h4M16 3c-3 4-3 8 0 10h3V3M19 13v8"/>',DELIVERY:'<circle cx="6" cy="17" r="2"/><circle cx="18" cy="17" r="2"/><path d="M3 17H2l2-7h9l3 7h-3M10 10V7h5l3 4h3l2 6h-3M6 10h5"/>',TAKEAWAY:'<path d="M4 8h16l-1 13H5L4 8ZM9 9V6a3 3 0 0 1 6 0v3"/>'};
  for(const button of document.querySelectorAll('[data-pos-order-type]')){
    const icon=document.createElement('span');icon.className='pos-type-icon';icon.setAttribute('aria-hidden','true');icon.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'+icons[button.dataset.posOrderType]+'</svg>';
    const label=document.createElement('span');label.textContent=button.textContent;button.replaceChildren(icon,label);
  }
  const dineFields=document.createElement('div');dineFields.id='pos-dine-fields';dineFields.className='pos-dine-fields';
  $('pos-table-wrap').before(dineFields);dineFields.append($('pos-table-wrap'),$('pos-guest-wrap'));
  const guests=$('pos-guests'), controls=document.createElement('div');controls.className='pos-guest-controls';
  const minus=document.createElement('button');minus.type='button';minus.textContent='−';minus.setAttribute('aria-label','Remove guest');
  const plus=document.createElement('button');plus.type='button';plus.textContent='+';plus.setAttribute('aria-label','Add guest');
  minus.onclick=()=>{guests.value=Math.max(1,Number(guests.value||1)-1);};plus.onclick=()=>{guests.value=Math.min(99,Number(guests.value||1)+1);};
  guests.before(controls);controls.append(minus,guests,plus);
  const summary=$('pos-customer-details').querySelector('summary');
  const icon=document.createElement('span');icon.className='pos-customer-icon';icon.setAttribute('aria-hidden','true');icon.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>';
  const copy=document.createElement('span');copy.className='pos-customer-copy';
  const title=document.createElement('strong');title.textContent='Customer details';
  const subtitle=document.createElement('small');subtitle.className='pos-customer-subtitle';subtitle.textContent='Optional for dine-in';copy.append(title,subtitle);summary.replaceChildren(icon,copy);
  for(const [inputId,svg] of [['pos-customer-name','<circle cx="12" cy="8" r="3"/><path d="M5 20a7 7 0 0 1 14 0"/>'],['pos-customer-phone','<path d="M6 3h4l1 4-2 2a14 14 0 0 0 6 6l2-2 4 1v4a3 3 0 0 1-3 3C9.7 21 3 14.3 3 6a3 3 0 0 1 3-3Z"/>']]){
    const input=$(inputId),wrap=document.createElement('div');wrap.className='pos-field-with-icon';input.before(wrap);wrap.append(input);
    const mark=document.createElement('span');mark.setAttribute('aria-hidden','true');mark.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'+svg+'</svg>';wrap.prepend(mark);
  }
  $('pos-customer-name').placeholder='Enter customer name';$('pos-customer-phone').placeholder='Enter phone number';$('pos-address').placeholder='Enter full delivery address';$('pos-delivery-notes').placeholder='Delivery instructions, landmark or gate code';
}
enhanceOrderForm();
for(const summary of document.querySelectorAll('.pos-customer-details>summary,.pos-order-instructions>summary')){
  const chevron=document.createElement('span');chevron.className='pos-chevron';chevron.setAttribute('aria-hidden','true');chevron.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';summary.append(chevron);
}
if(posConfig.role==='CASHIER')document.querySelector('.pos-sidebar-nav')?.insertAdjacentHTML('beforeend','<a href="#pos-report"><span>06</span>Reports</a>');
$('pos-report').hidden = true;
setPosScreen(screenFromLocation());
$('pos-search').addEventListener('input', () => {renderCategoryList([...$('pos-category').options].slice(2).map(option => ({id:option.value,name:option.textContent}))); renderItems();}); $('pos-category').addEventListener('change', () => {$('pos-search').value = ''; posState.visibleCount = 12; renderCategoryList([...$('pos-category').options].slice(2).map(option => ({id:option.value,name:option.textContent}))); renderItems();});
$('pos-load-more').addEventListener('click', () => {posState.visibleCount += 12; renderItems();});
$('pos-type').addEventListener('change', syncType); $('pos-create').addEventListener('click', createOrder);
$('pos-draft-discount').addEventListener('input',renderCart);
$('pos-packaging-charge').addEventListener('input',renderCart);
$('pos-delivery-charge').addEventListener('input',renderCart);
document.querySelectorAll('[data-pos-order-type]').forEach(button => button.addEventListener('click', () => {$('pos-type').value = button.dataset.posOrderType; syncType();}));
const setPosTheme = theme => {document.documentElement.dataset.adminTheme = theme;document.querySelectorAll('[data-pos-theme]').forEach(button => button.classList.toggle('is-active', button.dataset.posTheme === theme));};
let savedPosTheme = 'light'; try {savedPosTheme = localStorage.getItem('restaurant-admin-theme') || 'light';} catch(error) {}
setPosTheme(['light','dim','dark'].includes(savedPosTheme) ? savedPosTheme : 'light');
window.addEventListener('message',event=>{if(event.origin!==location.origin||event.source!==window.parent||event.data?.type!=='restaurant-pos-theme')return;if(['light','dim','dark'].includes(event.data.theme))setPosTheme(event.data.theme);});
window.addEventListener('storage',event=>{if(event.key==='restaurant-admin-theme'&&['light','dim','dark'].includes(event.newValue))setPosTheme(event.newValue);});
$('pos-notice-close').addEventListener('click',()=>{$('pos-notice').hidden=true;clearTimeout(posNoticeTimer);});
$('pos-alert-close').addEventListener('click',()=>$('pos-alert').close());
$('pos-alert').addEventListener('click',event=>closeBackdropFromEvent(event));
$('pos-alert-orders').addEventListener('click',()=>{$('pos-alert').close();if(window.parent!==window)window.parent.location.hash='orders';else location.href=posConfig.base+'/dashboard#orders';});
$('pos-alert').addEventListener('close',()=>{if(window.parent!==window)window.parent.postMessage({type:'restaurant-pos-dialog',open:false},location.origin);});
document.querySelectorAll('[data-pos-theme]').forEach(button => button.addEventListener('click', () => {setPosTheme(button.dataset.posTheme);try {localStorage.setItem('restaurant-admin-theme',button.dataset.posTheme);} catch(error) {}}));
$('pos-kot-only').addEventListener('click', () => action('kot')); $('pos-kot').addEventListener('click', () => action('kot', true));
$('pos-active-status-action').addEventListener('click',async event=>{const button=event.currentTarget,expected=button.dataset.expectedStatus,next=button.dataset.nextStatus;if(!expected||!next||button.disabled)return;button.disabled=true;try{const result=await posRequest('status',{order_id:posState.order,expected_status:expected,status:next});await loadActiveOrder(posState.order);await loadPos();posMessage('Order status updated: '+result.status.replaceAll('_',' ').toLowerCase().replace(/\b\w/g,letter=>letter.toUpperCase())+'.'+(result.kot_id?' KOT sent to kitchen.':''));}catch(error){posMessage(error.message);button.disabled=false;}});
$('pos-preview').addEventListener('click', previewBill);
$('pos-bill-payment').addEventListener('click', collectPayment);
$('pos-payment-back').addEventListener('click',()=> $('pos-payment-dialog').close());
$('pos-payment-close').addEventListener('click',()=> $('pos-payment-dialog').close());
$('pos-payment-cancel').addEventListener('click',()=> $('pos-payment-dialog').close());
$('pos-payment-dialog').addEventListener('close',()=>{document.documentElement.classList.remove('pos-payment-open');const settled=paymentDialogSaved&&posState.invoice?.payment_status==='PAID';$('pos-payment-dialog').querySelector('.pos-payment-scroll').scrollTop=0;if(settled)resetOrderComposer();if(window.parent!==window)window.parent.postMessage({type:'restaurant-pos-dialog',open:false},location.origin);});
$('pos-payment-add').addEventListener('click',()=>{
  if(paymentRows.length>=3)return;
  const due=paymentDueCents(),allocated=paymentRows.reduce((sum,row)=>sum+(cents(row.amountInput.value)||0),0);
  if(paymentRows.length===1&&allocated===due){const first=Math.ceil(due/2);paymentRows[0].amountInput.value=(first/100).toFixed(2);addPaymentRow(due-first,'CARD');}
  else addPaymentRow(Math.max(0,due-allocated),'CARD');
  paymentDialogKey=crypto.randomUUID();paymentRows.forEach(refreshPaymentRow);
});
$('pos-payment-record').addEventListener('click',()=>submitPaymentDialog());
$('pos-payment-print').addEventListener('click',()=>submitPaymentDialog(true));
$('pos-payment-retry-print').addEventListener('click',()=>retrySavedPaymentReceipt());
$('pos-pay').addEventListener('click', pay);
$('pos-new').addEventListener('click', resetOrderComposer);
$('pos-edit-action').addEventListener('change', syncEditForm); $('pos-edit-save').addEventListener('click', saveBillEdit); $('pos-audit').addEventListener('click', showAudit);
$('pos-edit-menu').addEventListener('change',()=>{syncEditPortions();syncEditForm();});
$('pos-report-load').addEventListener('click', loadReport);
$('pos-report-export').addEventListener('click',()=>window.print());
$('pos-order-filter').addEventListener('click', () => filterOrders());
$('pos-order-sort').addEventListener('change', () => filterOrders());
$('pos-orders-more').addEventListener('click', () => filterOrders(true));
$('pos-order-dialog-close').addEventListener('click', () => $('pos-order-dialog').close());
$('pos-order-dialog').addEventListener('close', () => {if(window.parent!==window)window.parent.postMessage({type:'restaurant-pos-dialog',open:false},location.origin);});
$('pos-bill-edit-open').addEventListener('click',()=>{if($('pos-bill-edit').hidden||$('pos-bill-edit').open)return;$('pos-edit-action').value='ITEM_ADDED';$('pos-edit-quantity').value='1';syncEditForm();showPosModal($('pos-bill-edit'));});
$('pos-edit-close').addEventListener('click',()=>$('pos-bill-edit').close());
$('pos-bill-edit').addEventListener('close',()=>{if(window.parent!==window)window.parent.postMessage({type:'restaurant-pos-dialog',open:false},location.origin);});
$('pos-preview-close').addEventListener('click', () => $('pos-preview-dialog').close());
$('pos-preview-done').addEventListener('click', () => $('pos-preview-dialog').close());
$('pos-preview-print').addEventListener('click', printBillPreview);
$('pos-preview-dialog').addEventListener('close', () => {if(window.parent!==window)window.parent.postMessage({type:'restaurant-pos-dialog',open:false},location.origin);});
$('pos-customer-find').addEventListener('click',addCustomer);
$('pos-customer-phone').addEventListener('focus',suggestCustomers);
$('pos-customer-phone').addEventListener('click',()=>{if(!$('pos-customer-suggestions').matches(':popover-open'))suggestCustomers();});
$('pos-customer-phone').addEventListener('input',()=>{$('pos-customer-phone').value=$('pos-customer-phone').value.replace(/\D/g,'').slice(0,10);selectedCustomerId=null;$('pos-customer-find').disabled=false;$('pos-customer-find').textContent='Add customer';$('pos-customer-feedback').textContent='';clearTimeout(customerSearchTimer);customerSearchTimer=setTimeout(suggestCustomers,180);});
$('pos-customer-phone').addEventListener('keydown',event=>{if(event.key==='Escape')closeCustomerSuggestions();if(event.key==='ArrowDown'&&$('pos-customer-suggestions').matches(':popover-open')){event.preventDefault();$('pos-customer-suggestions').querySelector('button')?.focus();}});
$('pos-customer-suggestions').addEventListener('keydown',event=>{if(event.key==='Escape'){closeCustomerSuggestions();$('pos-customer-phone').focus();}});
document.addEventListener('pointerdown',event=>{if(!event.target.closest('.pos-phone-field,#pos-customer-suggestions'))closeCustomerSuggestions();});
window.addEventListener('resize',positionCustomerSuggestions);
document.addEventListener('scroll',positionCustomerSuggestions,true);
if(posPopupPortal){window.parent.addEventListener('resize',positionCustomerSuggestions);window.parent.addEventListener('scroll',positionCustomerSuggestions,true);window.parent.document.addEventListener('pointerdown',event=>{if(!event.target.closest('[data-pos-popup-portal] #pos-customer-suggestions')&&!event.target.closest('#dashboard-pos-frame'))closeCustomerSuggestions();});}
$('pos-order-search').addEventListener('keydown', event => {if (event.key === 'Enter') filterOrders();});
$('pos-method').addEventListener('change', () => {$('pos-cash-received').closest('label').hidden = $('pos-method').value !== 'CASH';});
syncType(); $('pos-method').dispatchEvent(new Event('change')); loadPos().catch(error => posMessage(error.message));
