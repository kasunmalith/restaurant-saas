<?php
declare(strict_types=1);
if ($path !== '/pos' && !str_starts_with($path, '/api/pos/')) return;
require_once __DIR__.'/pos-core.php';
$account = $db->prepare("SELECT u.id,u.restaurant_id,u.role,u.name,r.name restaurant_name,r.logo_path,r.timezone,r.service_charge_percent,r.is_active,p.pos_enabled,p.manager_bill_edit_enabled,p.receipt_width_mm,p.print_logo_on_receipt FROM users u JOIN restaurants r ON r.id=u.restaurant_id LEFT JOIN restaurant_pos_settings p ON p.restaurant_id=r.id WHERE u.id=? AND u.is_active=1 AND u.role IN ('CASHIER','RESTAURANT_ADMIN')");
$account->execute([(int)($_SESSION['user']['id'] ?? 0)]);
$staff = $account->fetch();
if (!$staff || !(int)$staff['is_active'] || !(int)$staff['pos_enabled']) {
    if ($path === '/pos') { http_response_code(403); layout('POS unavailable', '<main class="shell"><h1>POS unavailable</h1><p>Ask your Super Admin to enable POS / Billing for this restaurant.</p></main>'); }
    order_fail('POS / Billing is not enabled for this restaurant.', 403);
}
$rid = (int)$staff['restaurant_id'];
$userId = (int)$staff['id'];
if ($path === '/pos') {
    $payload = json_encode(['base' => base(), 'assetBase' => site_base(), 'csrf' => csrf(), 'restaurant' => $staff['restaurant_name'], 'restaurantId' => $rid, 'userId' => $userId, 'receiptWidthMm' => (int)($staff['receipt_width_mm'] ?? 80), 'role' => $staff['role'], 'manager_bill_edit_enabled' => (int)$staff['manager_bill_edit_enabled'], 'serviceChargePercent' => $staff['service_charge_percent']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $body = '<main class="pos-app"><header class="pos-header"><div><p class="eyebrow">RESTAURANT OPERATIONS</p><h1>POS / Billing</h1><p>'.e($staff['restaurant_name']).' · '.e($staff['role']==='CASHIER'?'Cashier':'Manager').'</p></div><a href="'.e(base()).'/dashboard">← Dashboard</a></header><div class="pos-layout"><section class="pos-menu"><div class="pos-toolbar"><input id="pos-search" type="search" placeholder="Search food items" aria-label="Search food items"><select id="pos-category" aria-label="Category"><option value="">All categories</option></select></div><div id="pos-items" class="pos-grid"></div></section><aside class="pos-order"><h2>Current Order</h2><label>Order type<select id="pos-type"><option value="DINE_IN">Dine-In</option><option value="TAKEAWAY">Takeaway / Pickup</option><option value="DELIVERY">Direct Delivery</option></select></label><label id="pos-table-wrap">Table<select id="pos-table"></select></label><label id="pos-guest-wrap">Guests<input id="pos-guests" type="number" min="1" max="99" value="1"></label><div class="pos-customer"><label>Customer name<input id="pos-customer-name" maxlength="120"></label><label>Phone<input id="pos-customer-phone" maxlength="40"></label><label class="pos-delivery">Address<input id="pos-address" maxlength="500"></label><label class="pos-delivery">Delivery notes<input id="pos-delivery-notes" maxlength="500"></label><label class="pos-delivery">Delivery charge (LKR)<input id="pos-delivery-charge" inputmode="decimal" value="0.00"></label></div><div id="pos-cart"></div><label>Order instructions<textarea id="pos-note" maxlength="1000"></textarea></label><div class="pos-total">Items <strong id="pos-subtotal">LKR 0.00</strong></div><button id="pos-create" type="button" class="pos-primary">Create Order</button><p id="pos-message" role="status" aria-live="polite"></p><section id="pos-active" hidden><h3 id="pos-active-title"></h3><div class="pos-actions"><button type="button" id="pos-kot">Send KOT</button><button type="button" id="pos-bill">Generate Bill</button><button type="button" id="pos-receipt">Print / Reprint</button></div><div id="pos-payment" hidden><h3>Receive Payment</h3><p id="pos-due"></p><label>Method<select id="pos-method"><option value="CASH">Cash</option><option value="CARD">Card</option><option value="BANK_TRANSFER">Bank Transfer</option><option value="ONLINE_PAYMENT">Online Payment</option><option value="OTHER">Other</option></select></label><label>Payment amount<input id="pos-pay-amount" inputmode="decimal"></label><label>Cash received<input id="pos-cash-received" inputmode="decimal"></label><label>Reference<input id="pos-reference" maxlength="120"></label><button type="button" id="pos-pay">Record Payment</button></div></section></aside></div><section class="pos-history"><h2>Recent Orders</h2><div id="pos-orders"></div></section></main><script type="application/json" id="pos-config">'.$payload.'</script><link rel="stylesheet" href="'.e(base()).'/assets/pos.css?v=2"><script defer src="'.e(base()).'/assets/pos.js?v=2"></script>';
    $body=str_replace('<h3 id="pos-active-title"></h3><div class="pos-actions">','<h3 id="pos-active-title"></h3><div id="pos-active-order-status"><span>Order status</span><strong id="pos-active-status"></strong></div><ol id="pos-active-status-steps" aria-label="Order status progress"></ol><button type="button" id="pos-active-status-action" hidden></button><div id="pos-active-items"></div><div id="pos-kot-history"></div><div class="pos-actions">',$body);
    $body=str_replace('<button type="button" id="pos-receipt">Print / Reprint</button>','<button type="button" id="pos-new">New Order</button>',$body);
    $body=str_replace('<div class="pos-actions"><button type="button" id="pos-kot">','<div class="pos-bill-charges"><label>Discount (LKR)<input id="pos-bill-discount" inputmode="decimal" value="0.00"></label><label>Service charge (LKR)<input id="pos-bill-service" inputmode="decimal" value="0.00"></label><label>Tax (LKR)<input id="pos-bill-tax" inputmode="decimal" value="0.00"></label></div><div class="pos-actions"><button type="button" id="pos-kot">',$body);
    $body=str_replace('<button type="button" id="pos-kot">Send KOT</button><button type="button" id="pos-bill">Generate Bill</button>','<button type="button" id="pos-kot-only">Send KOT</button><button type="button" id="pos-kot">KOT Print</button><button type="button" id="pos-preview">Bill Preview & Print</button><button type="button" id="pos-bill-payment">Receive Payment</button><button type="button" id="pos-bill-edit-open" hidden>Edit Bill</button>',$body);
    $body=str_replace('<div id="pos-kot-history"></div>','<div id="pos-kot-history"></div><input type="hidden" id="pos-print-width" value="80">',$body);
    $body=str_replace('Service charge (LKR)<input id="pos-bill-service" inputmode="decimal" value="0.00">','Service charge ('.e($staff['service_charge_percent']).'%)<input id="pos-bill-service" inputmode="decimal" value="0.00" readonly>',$body);
    $body=str_replace('<div class="pos-total">Items <strong id="pos-subtotal">LKR 0.00</strong></div>','<div class="pos-estimate pos-item-count-row"><span>Total items</span><strong id="pos-item-count">0</strong></div><div class="pos-total">Subtotal <strong id="pos-subtotal">LKR 0.00</strong></div><div class="pos-estimate"><span>Service charge ('.e($staff['service_charge_percent']).'%)</span><strong id="pos-service-estimate">LKR 0.00</strong></div><div class="pos-draft-discount"><label for="pos-draft-discount">Discount (LKR)</label><input id="pos-draft-discount" inputmode="decimal" value="0.00" aria-describedby="pos-draft-discount-help"><small id="pos-draft-discount-help">Applied when the bill is generated.</small></div><div class="pos-estimate"><span>Discount</span><strong id="pos-discount-estimate">LKR 0.00</strong></div><div class="pos-estimate" id="pos-delivery-estimate-row" hidden><span>Delivery charge</span><strong id="pos-delivery-estimate">LKR 0.00</strong></div><div class="pos-estimate pos-estimate-total"><span>Estimated total</span><strong id="pos-grand-estimate">LKR 0.00</strong></div>',$body);
    $body=str_replace('<div class="pos-estimate" id="pos-delivery-estimate-row" hidden><span>Delivery charge</span><strong id="pos-delivery-estimate">LKR 0.00</strong></div>','<div class="pos-estimate" id="pos-packaging-estimate-row" hidden><span>Packaging charge</span><strong id="pos-packaging-estimate">LKR 0.00</strong></div><div class="pos-estimate" id="pos-delivery-estimate-row" hidden><span>Delivery charge</span><strong id="pos-delivery-estimate">LKR 0.00</strong></div>',$body);
    $body=str_replace('<label class="pos-delivery">Delivery charge (LKR)<input id="pos-delivery-charge"','<label id="pos-package-charge">Packaging charge (LKR)<input id="pos-packaging-charge"',$body);
    $body=str_replace('</label></div><div id="pos-cart">','</label><label id="pos-delivery-charge-wrap" class="pos-delivery">Delivery charge (LKR)<input id="pos-delivery-charge" inputmode="decimal" value="0.00"></label></div><div id="pos-cart">',$body);
    $body=str_replace('<label>Phone<input id="pos-customer-phone" maxlength="40"></label>','<div class="pos-phone-field"><label for="pos-customer-phone">Phone <span aria-hidden="true" hidden>*</span></label><input id="pos-customer-phone" type="tel" inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10" autocomplete="off" aria-autocomplete="list" aria-controls="pos-customer-suggestions" aria-expanded="false" placeholder="10-digit phone number"><div id="pos-customer-suggestions" class="pos-customer-suggestions" role="listbox" popover="manual"></div><button type="button" id="pos-customer-find">Add customer</button><p id="pos-customer-feedback" role="status" aria-live="polite"></p></div>',$body);
    $body=str_replace('</section></aside></div><section class="pos-history">','<dialog id="pos-bill-edit" class="pos-order-dialog pos-bill-edit-dialog" aria-labelledby="pos-bill-edit-title" hidden><div class="pos-dialog-head"><div><small>MANAGER BILL CORRECTION</small><h2 id="pos-bill-edit-title">Edit Bill</h2></div><button type="button" id="pos-edit-close" aria-label="Close edit bill">×</button></div><p class="pos-bill-edit-help">Add an item to or remove an item from this bill.</p><label>Action<select id="pos-edit-action"><option value="ITEM_ADDED">Add item</option><option value="ITEM_REMOVED">Remove item</option></select></label><label>Bill item<select id="pos-edit-line"></select></label><label>Menu item<select id="pos-edit-menu"></select></label><label>Portion<select id="pos-edit-portion"><option value="">Regular</option><option value="HALF">Half</option><option value="FULL">Full</option></select></label><label>Quantity<input id="pos-edit-quantity" type="number" min="1" max="99" value="1"></label><label>Reason (optional)<input id="pos-edit-reason" maxlength="500"></label><button type="button" id="pos-edit-save">Save Correction</button></dialog><div id="pos-audit-panel" hidden><button type="button" id="pos-audit">Bill Edit History</button><div id="pos-audit-results"></div></div></section></aside></div><section class="pos-history">',$body);
    $body=str_replace('<h2>Recent Orders</h2><div id="pos-orders">','<h2>Order History</h2><div class="pos-toolbar pos-order-filters"><input id="pos-order-search" type="search" placeholder="Order, table or customer"><select id="pos-order-status"><option value="">All statuses</option><option>PENDING</option><option>ACCEPTED</option><option>PREPARING</option><option>READY</option><option>SERVED</option><option>COMPLETED</option><option>REJECTED</option><option>CANCELLED</option></select><select id="pos-order-type"><option value="">All types</option><option value="DINE_IN">Dine-In</option><option value="TAKEAWAY">Takeaway</option><option value="DELIVERY">Delivery</option></select><select id="pos-order-source"><option value="">QR & POS</option><option value="QR">QR</option><option value="POS">POS</option></select><select id="pos-order-payment"><option value="">Any payment</option><option value="UNPAID">Unpaid</option><option value="PARTIALLY_PAID">Partially paid</option><option value="PAID">Paid</option></select><select id="pos-order-sort" aria-label="Sort pending bills"><option value="newest">New first</option><option value="oldest">Old first</option></select><input id="pos-order-from" type="date" aria-label="From date"><input id="pos-order-to" type="date" aria-label="To date"><button type="button" id="pos-order-filter">Filter</button></div><div id="pos-orders">',$body);
    $body=str_replace(['QR & POS','<option value="POS">POS</option>'],['QR or Waiter','<option value="POS">Waiter</option>'],$body);
    $body=str_replace('</main><script type="application/json" id="pos-config">','<section class="pos-report-window" id="pos-report"'.($staff['role']==='RESTAURANT_ADMIN'?'':' hidden').'><div class="pos-report-dashboard"><div class="pos-report-toolbar"><div class="pos-report-date"><label for="pos-report-from">From</label><input id="pos-report-from" type="date" value="'.date('Y-m-d').'"></div><div class="pos-report-date"><label for="pos-report-to">To</label><input id="pos-report-to" type="date" value="'.date('Y-m-d').'"></div><button type="button" id="pos-report-load"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.4"/><path d="m16 16 4.2 4.2"/></svg>View report</button><button type="button" id="pos-report-export"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11m0 0 4-4m-4 4-4-4M5 16v4h14v-4"/></svg>Export PDF</button></div><div id="pos-report-results" aria-live="polite"></div></div></section></main><script type="application/json" id="pos-config">',$body);
    $body=str_replace('</main><script type="application/json" id="pos-config">','<div class="pos-notice" id="pos-notice" hidden><div><strong id="pos-notice-title">POS update</strong><p id="pos-notice-text"></p></div><button type="button" id="pos-notice-close" aria-label="Dismiss notification">×</button></div><dialog class="pos-alert" id="pos-alert" aria-labelledby="pos-alert-title" aria-describedby="pos-alert-text"><div class="pos-alert-icon" aria-hidden="true">✓</div><h2 id="pos-alert-title"></h2><p id="pos-alert-text"></p><div class="pos-alert-actions"><button type="button" id="pos-alert-close">Continue to order</button><button type="button" id="pos-alert-orders" hidden>Open all orders</button></div></dialog></main><script type="application/json" id="pos-config">',$body);
    $initial=e(mb_strtoupper(mb_substr((string)$staff['restaurant_name'],0,1)));
    $brandMark=$staff['logo_path']?'<img src="'.e(site_base().'/'.ltrim((string)$staff['logo_path'],'/')).'" alt="">':$initial;
    $sidebar='<aside class="pos-sidebar" aria-label="POS navigation"><div class="pos-brand"><span class="pos-brand-mark">'.$brandMark.'</span><div><strong>'.e($staff['restaurant_name']).'</strong><small>Restaurant workspace</small></div></div><nav class="pos-sidebar-nav"><a href="'.e(base()).'/dashboard"><span>01</span>Dashboard</a><a href="#pos-workspace" class="is-current" aria-current="page"><span>02</span>Point of Sale</a><a href="#pos-orders-section"><span>03</span>Orders</a><a href="'.e(base()).'/dashboard#menu"><span>04</span>Menu</a><a href="'.e(base()).'/dashboard#tables"><span>05</span>Tables & QR</a>'.($staff['role']==='RESTAURANT_ADMIN'?'<a href="#pos-report"><span>06</span>Reports</a>':'').'</nav><div class="pos-sidebar-footer"><p>Workspace theme</p><div class="pos-theme-switch" aria-label="Workspace theme"><button type="button" data-pos-theme="light">Light</button><button type="button" data-pos-theme="dim">Dim</button><button type="button" data-pos-theme="dark">Dark</button></div></div></aside>';
    $body=str_replace('<main class="pos-app">','<main class="pos-app">'.$sidebar,$body);
    if (($_GET['embed']??'')==='1') {
        $body=str_replace('<main class="pos-app">','<main class="pos-app pos-embedded">',$body);
        $body.='<script>if(window.parent!==window){const page=document.querySelector(".pos-app");let disclosureAnimating=false,disclosureTimer=0;const postHeight=height=>{const value=Math.ceil(height??page.getBoundingClientRect().bottom);try{const frame=window.parent.document.getElementById("dashboard-pos-frame");if(frame&&frame.contentWindow===window){frame.style.height=Math.max(760,value+8)+"px";return}}catch(error){}window.parent.postMessage({type:"restaurant-pos-height",height:value},location.origin)};const sendHeight=()=>{if(!disclosureAnimating)postHeight()};new ResizeObserver(sendHeight).observe(page);const finishDisclosure=()=>{clearTimeout(disclosureTimer);disclosureTimer=setTimeout(()=>{disclosureAnimating=false;postHeight()},360)};for(const details of page.querySelectorAll(".pos-customer-details,.pos-order-instructions")){const summary=details.querySelector(":scope > summary");summary.addEventListener("click",event=>{event.preventDefault();disclosureAnimating=true;clearTimeout(disclosureTimer);if(details.open){details.open=false;finishDisclosure();return}details.classList.add("pos-measuring-open");details.open=true;const targetHeight=page.getBoundingClientRect().bottom;details.open=false;void details.offsetHeight;details.classList.remove("pos-measuring-open");postHeight(targetHeight);details.open=true;finishDisclosure()})}window.addEventListener("load",()=>postHeight());postHeight()}</script>';
    }
    $body=str_replace('<h1>POS / Billing</h1>','<h1>Point of Sale</h1>',$body);
    $headerDescription='<p>'.e($staff['restaurant_name']).' · '.e($staff['role']==='CASHIER'?'Cashier':'Manager').'</p>';
    $body=str_replace($headerDescription,'<p id="pos-header-description"></p>',$body);
    $body=str_replace('<div class="pos-layout">','<div class="pos-layout" id="pos-workspace">',$body);
    $body=str_replace('<div id="pos-items" class="pos-grid"></div>','<nav id="pos-category-list" class="pos-category-list" aria-label="Menu categories"></nav><div id="pos-items" class="pos-grid"></div><button id="pos-load-more" type="button" hidden>Load 12 more</button>',$body);
    $body=str_replace('<option value="">All categories</option>','<option value="popular">Most Selling</option><option value="">All items</option>',$body);
    $body=str_replace('<h2>Current Order</h2><label>Order type<select id="pos-type">','<div class="pos-order-head"><span>LIVE BILLING</span><h2>Order</h2><p>Build the order, send KOT, then bill and receive payment.</p><p class="pos-order-hint">Tap or click a menu item to add it to this order.</p></div><div class="pos-type-tabs" role="group" aria-label="Order type"><button type="button" data-pos-order-type="DINE_IN">Dine in</button><button type="button" data-pos-order-type="DELIVERY">Delivery</button><button type="button" data-pos-order-type="TAKEAWAY">Pickup</button></div><label class="pos-native-type">Order type<select id="pos-type">',$body);
    $body=str_replace('<div class="pos-customer">','<details id="pos-customer-details" class="pos-customer-details"><summary>Customer details <span>Optional for dine-in</span></summary><div class="pos-customer">',$body);
    $body=str_replace('</div><div id="pos-cart">','</div></details><div id="pos-cart">',$body);
    $packageField='<label id="pos-package-charge">Packaging charge (LKR)<input id="pos-packaging-charge" inputmode="decimal" value="0.00"></label>';
    $deliveryField='<label id="pos-delivery-charge-wrap" class="pos-delivery">Delivery charge (LKR)<input id="pos-delivery-charge" inputmode="decimal" value="0.00"></label>';
    $body=str_replace($packageField.$deliveryField.'</div></details><div id="pos-cart">','</div></details><div class="pos-customer pos-order-charges">'.$packageField.$deliveryField.'</div><div id="pos-cart">',$body);
    $body=str_replace('<label>Order instructions<textarea id="pos-note" maxlength="1000"></textarea></label>','<details class="pos-order-instructions"><summary>Order instructions <span>Optional</span></summary><label for="pos-note">Special requests</label><textarea id="pos-note" maxlength="1000"></textarea></details>',$body);
    $body=str_replace('<section class="pos-history"><h2>Order History</h2>','<section class="pos-history" id="pos-orders-section"><div class="pos-section-title"><div><small>ORDER MANAGEMENT</small><h2>Orders</h2><p id="pos-orders-description"></p></div><span id="pos-order-count"></span></div>',$body);
    $body=str_replace('<div id="pos-active-items"></div>','<div class="pos-line-head"><span>ITEM</span><span>QTY / AMOUNT</span></div><div id="pos-active-items"></div>',$body);
    $body=str_replace('href="#pos-orders-section"><span>03</span>Orders</a>','href="'.e(base()).'/dashboard#orders"><span>03</span>Orders</a>',$body);
    $body=str_replace('</main><script type="application/json" id="pos-config">','<dialog id="pos-order-dialog" class="pos-order-dialog" aria-labelledby="pos-order-dialog-title"><div class="pos-dialog-head"><div><small>ORDER DETAILS</small><h2 id="pos-order-dialog-title"></h2></div><button type="button" id="pos-order-dialog-close" aria-label="Close order details">✕</button></div><div id="pos-order-dialog-content"></div></dialog><dialog id="pos-preview-dialog" class="pos-order-dialog pos-preview-dialog" aria-labelledby="pos-preview-title"><div class="pos-dialog-head"><div><small>BILL PREVIEW</small><h2 id="pos-preview-title">Bill Preview</h2></div><button type="button" id="pos-preview-close" aria-label="Close bill preview">✕</button></div><div id="pos-preview-content"></div><div class="pos-preview-actions"><button type="button" id="pos-preview-print">Print preview</button><button type="button" id="pos-preview-done">Close</button></div></dialog></main><script type="application/json" id="pos-config">',$body);
    $paymentDialog=<<<'HTML'
<dialog id="pos-payment-dialog" class="pos-payment-dialog" aria-labelledby="pos-payment-title">
  <div class="pos-payment-shell">
    <header class="pos-payment-header"><button type="button" id="pos-payment-back" aria-label="Close payment window"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg></button><div><h2 id="pos-payment-title">Receive Payment</h2><p>Take payment for this order and print the bill.</p></div><button type="button" id="pos-payment-close" aria-label="Close payment window"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 5 19 19M19 5 5 19"/></svg></button></header>
    <div class="pos-payment-scroll">
      <section class="pos-payment-summary" aria-label="Bill summary"><div class="pos-payment-meta"><div><small>Invoice No.</small><strong id="pos-payment-invoice"></strong></div><div><small>Order Status</small><span id="pos-payment-order-type" class="pos-payment-type"></span></div></div><div class="pos-payment-figures"><div><span>Total Bill</span><strong id="pos-payment-total"></strong></div><div><span>Already Paid</span><strong id="pos-payment-paid"></strong></div><div class="is-due"><span>Amount Due</span><strong id="pos-payment-remaining"></strong></div></div><div class="pos-payment-projection"><span>Remaining after payment</span><strong id="pos-payment-projected"></strong></div><div id="pos-payment-status" class="pos-payment-status"><div><strong>Payment Status</strong><small id="pos-payment-status-text"></small></div><span id="pos-payment-status-badge"></span></div></section>
      <div id="pos-payment-methods"></div>
      <div class="pos-payment-allocation" id="pos-payment-allocation"><div><span>Selected Payments</span><strong id="pos-payment-selected-total"></strong></div><div><span>Remaining</span><strong id="pos-payment-selected-remaining"></strong></div></div>
      <section id="pos-payment-split" class="pos-payment-split"><div><strong>Split payment <small>(Optional)</small></strong><p>Add another payment method for this order.</p></div><button type="button" id="pos-payment-add"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add Payment</button></section>
      <p id="pos-payment-error" class="pos-payment-error" role="alert" hidden></p>
    </div>
    <footer class="pos-payment-footer"><p id="pos-payment-result" class="pos-payment-result" role="status" hidden><span id="pos-payment-result-text"></span><button type="button" id="pos-payment-retry-print" hidden>Retry Print</button></p><button type="button" id="pos-payment-cancel">Cancel</button><button type="button" id="pos-payment-record">Record Payment</button><button type="button" id="pos-payment-print"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V3h12v6M6 17H4a2 2 0 0 1-2-2v-4a2 2 0 0 1-2-2h-2M6 14h12v7H6z"/></svg><span id="pos-payment-print-label">Pay &amp; Print</span></button></footer>
  </div>
</dialog>
HTML;
    $body=str_replace('</main><script type="application/json" id="pos-config">',$paymentDialog.'</main><script type="application/json" id="pos-config">',$body);
    $body=str_replace('<div id="pos-orders"></div>','<div id="pos-orders"></div><button id="pos-orders-more" type="button" hidden>Load 18 more</button>',$body);
    $body=str_replace(['/assets/pos.js?v=2','/assets/pos.css?v=2'],['/assets/pos.js?v=80','/assets/pos.css?v=60'],$body);
    $body=str_replace(['/assets/pos.js?v=39','/assets/pos.js?v=59','/assets/pos.js?v=60','/assets/pos.js?v=61','/assets/pos.js?v=62','/assets/pos.js?v=63','/assets/pos.js?v=64','/assets/pos.js?v=65','/assets/pos.js?v=66','/assets/pos.js?v=67','/assets/pos.js?v=68','/assets/pos.js?v=69','/assets/pos.js?v=70','/assets/pos.js?v=71','/assets/pos.js?v=72','/assets/pos.js?v=73','/assets/pos.js?v=74','/assets/pos.js?v=75','/assets/pos.js?v=76','/assets/pos.js?v=77','/assets/pos.js?v=78','/assets/pos.js?v=79','/assets/pos.js?v=80','/assets/pos.js?v=81','/assets/pos.js?v=82','/assets/pos.js?v=83','/assets/pos.js?v=84'],'/assets/pos.js?v=85',$body);
    $body=str_replace(['/assets/pos.css?v=51','/assets/pos.css?v=52','/assets/pos.css?v=53','/assets/pos.css?v=54','/assets/pos.css?v=55','/assets/pos.css?v=56','/assets/pos.css?v=57','/assets/pos.css?v=58','/assets/pos.css?v=59'],'/assets/pos.css?v=60',$body);
    $body=str_replace('/assets/pos.js?v=85','/assets/pos.js?v=86',$body);
    $body=str_replace('/assets/pos.js?v=86','/assets/pos.js?v=88',$body);
    $body=str_replace('/assets/pos.css?v=60','/assets/pos.css?v=62',$body);
    layout('POS / Billing', $body);
}
if ($path === '/api/pos/bootstrap') {
    $q = $db->prepare('SELECT id,name FROM menu_categories WHERE restaurant_id=? ORDER BY sort_order,id'); $q->execute([$rid]); $categories = $q->fetchAll();
    $q = $db->prepare('SELECT id,category_id,name,price,is_portioned,half_price,full_price,image_path,is_available FROM menu_items WHERE restaurant_id=? AND is_active=1 AND is_available=1 ORDER BY category_id,name'); $q->execute([$rid]); $items = $q->fetchAll();require_once __DIR__.'/menu-options.php';menu_attach_options($db,(int)$rid,$items);
    $q = $db->prepare("SELECT oi.menu_item_id FROM orders o JOIN order_items oi ON oi.order_id=o.id JOIN menu_items m ON m.id=oi.menu_item_id AND m.restaurant_id=o.restaurant_id WHERE o.restaurant_id=? AND o.status NOT IN ('REJECTED','CANCELLED') AND oi.quantity>0 AND m.is_active=1 AND m.is_available=1 GROUP BY oi.menu_item_id ORDER BY SUM(oi.quantity) DESC, MAX(o.created_at) DESC, oi.menu_item_id DESC LIMIT 12"); $q->execute([$rid]); $popularIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    if((string)($_GET['menu_only']??'')==='1')json_out(['categories'=>$categories,'items'=>$items,'popular_ids'=>$popularIds]);
    $q = $db->prepare('SELECT id,table_number FROM restaurant_tables WHERE restaurant_id=? AND is_active=1 ORDER BY table_number'); $q->execute([$rid]); $tables = $q->fetchAll();
    $q = $db->prepare('SELECT o.id,o.order_number,o.order_type,o.delivery_status,o.source,o.status,o.total,o.created_at,t.table_number,i.id invoice_id,i.invoice_number,i.total invoice_total,i.paid_total,i.payment_status FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id LEFT JOIN pos_invoices i ON i.order_id=o.id AND i.restaurant_id=o.restaurant_id WHERE o.restaurant_id=? ORDER BY o.id DESC LIMIT 50'); $q->execute([$rid]); $orders = $q->fetchAll();
    json_out(['categories'=>$categories,'items'=>$items,'popular_ids'=>$popularIds,'tables'=>$tables,'orders'=>$orders]);
}
if ($path === '/api/pos/customer') {
    $phone=preg_replace('/\D/','',(string)($_GET['phone']??''))??'';
    if(strlen($phone)<5||strlen($phone)>20)order_fail('Enter a valid phone number.');
    $q=$db->prepare('SELECT name,address FROM restaurant_customers WHERE restaurant_id=? AND phone=? LIMIT 1');$q->execute([$rid,$phone]);
    json_out(['customer'=>$q->fetch()?:null]);
}
if ($path === '/api/pos/customer-suggest') {
    $query=preg_replace('/\D/','',(string)($_GET['phone']??''))??'';
    if(strlen($query)>20)order_fail('Phone search is too long.');
    $sql="SELECT c.id,c.name,c.phone,c.address FROM restaurant_customers c WHERE c.restaurant_id=? AND (c.origin IN ('MANAGER','POS','LEGACY') OR EXISTS(SELECT 1 FROM online_customer_accounts a WHERE a.restaurant_id=c.restaurant_id AND a.customer_id=c.id))".($query!==''?' AND c.phone LIKE ?':'').' ORDER BY c.updated_at DESC,c.id DESC LIMIT 8';
    $q=$db->prepare($sql);$q->execute($query!==''?[$rid,$query.'%']:[$rid]);
    json_out(['customers'=>$q->fetchAll()]);
}
if ($path === '/api/pos/customers') {
    if (!in_array($staff['role'],['RESTAURANT_ADMIN','CASHIER'],true))order_fail('Restaurant staff access required.',403);
    $search=trim((string)($_GET['search']??''));
    $offset=filter_var($_GET['offset']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100000]]);
    if($offset===false)order_fail('Invalid customer offset.');
    if(mb_strlen($search)>100)order_fail('Search is too long.');
    $category=(string)($_GET['category']??'manager_registered');if(!in_array($category,['online_registered','manager_registered','guest'],true))order_fail('Invalid customer category.');
    if($category==='online_registered'){
        $baseSql="SELECT c.restaurant_id,c.id,c.name,c.phone,c.address,c.origin,c.created_at,c.updated_at,'REGISTERED' account_status,'PROFILE' record_type FROM restaurant_customers c JOIN online_customer_accounts a ON a.restaurant_id=c.restaurant_id AND a.customer_id=c.id WHERE c.restaurant_id=?";$params=[$rid];
    }elseif($category==='manager_registered'){
        $baseSql="SELECT c.restaurant_id,c.id,c.name,c.phone,c.address,c.origin,c.created_at,c.updated_at,'REGISTERED' account_status,'PROFILE' record_type FROM restaurant_customers c WHERE c.restaurant_id=? AND c.origin IN ('MANAGER','POS','LEGACY')";$params=[$rid];
    }else{
        $baseSql="SELECT o.restaurant_id,-o.id id,o.customer_name name,o.customer_phone phone,o.delivery_address address,'ONLINE_GUEST' origin,o.created_at,o.created_at updated_at,'GUEST' account_status,'ORDER' record_type FROM orders o JOIN (SELECT order_rows.restaurant_id,order_rows.customer_phone,MAX(order_rows.id) id FROM orders order_rows WHERE order_rows.restaurant_id=? AND order_rows.source='QR' AND order_rows.table_id IS NULL AND order_rows.customer_phone IS NOT NULL AND EXISTS(SELECT 1 FROM restaurant_customers profile WHERE profile.restaurant_id=order_rows.restaurant_id AND profile.phone=order_rows.customer_phone) AND (order_rows.customer_id IS NULL OR NOT EXISTS(SELECT 1 FROM online_customer_accounts a WHERE a.restaurant_id=order_rows.restaurant_id AND a.customer_id=order_rows.customer_id)) GROUP BY order_rows.restaurant_id,order_rows.customer_phone) latest ON latest.id=o.id WHERE o.restaurant_id=?";$params=[$rid,$rid];
    }
    $sql='SELECT * FROM ('.$baseSql.') customer_rows';$where='';
    if($search!==''){$where=' WHERE (customer_rows.name LIKE ? OR customer_rows.phone LIKE ?)';$like='%'.str_replace(['%','_'],['\\%','\\_'],$search).'%';$params[]=$like;$params[]=$like;}
    $count=$db->prepare('SELECT COUNT(*) FROM ('.$baseSql.') customer_rows'.$where);$count->execute($params);$total=(int)$count->fetchColumn();
    $q=$db->prepare($sql.$where.' ORDER BY COALESCE(NULLIF(TRIM(customer_rows.name),\'\'),customer_rows.phone) ASC,customer_rows.id ASC LIMIT 15 OFFSET '.(int)$offset);$q->execute($params);$customers=$q->fetchAll();
    json_out(['customers'=>$customers,'total'=>$total,'has_more'=>$offset+count($customers)<$total,'next_offset'=>$offset+count($customers)]);
}
if ($path === '/api/pos/orders') {
    $view=(string)($_GET['view']??'orders');
    if(!in_array($view,['orders','kot','invoices','payments'],true))order_fail('Invalid order view.');
    $offset=filter_var($_GET['offset']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000]]);
    if($offset===false)order_fail('Invalid page offset.');
    $status=(string)($_GET['status']??'');$type=(string)($_GET['type']??'');$source=(string)($_GET['source']??'');$payment=(string)($_GET['payment']??'');$search=trim((string)($_GET['search']??''));
    $from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');$sort=(string)($_GET['sort']??'newest');
    if(!in_array($sort,['newest','oldest'],true))order_fail('Invalid order sort.');
    if($status!==''&&!in_array($status,['PENDING','ACCEPTED','PREPARING','READY','SERVED','COMPLETED','REJECTED','CANCELLED'],true))order_fail('Invalid status filter.');
    if($type!==''&&!in_array($type,['DINE_IN','TAKEAWAY','DELIVERY'],true))order_fail('Invalid order type filter.');
    if($source!==''&&!in_array($source,['QR','POS'],true))order_fail('Invalid source filter.');
    if($payment!==''&&!in_array($payment,['UNPAID','PARTIALLY_PAID','PAID'],true))order_fail('Invalid payment filter.');
    if(mb_strlen($search)>100)order_fail('Search is too long.');
    if($from!==''||$to!==''){
        $start=DateTimeImmutable::createFromFormat('!Y-m-d',$from);$end=DateTimeImmutable::createFromFormat('!Y-m-d',$to);
        if(!$start||!$end||$start->format('Y-m-d')!==$from||$end->format('Y-m-d')!==$to||$to<$from||$end->diff($start)->days>366)order_fail('Choose a valid date range of up to 366 days.');
    }
    $where=['o.restaurant_id=?'];$params=[$rid];
    if($view==='kot')$where[]='EXISTS (SELECT 1 FROM pos_kots k WHERE k.restaurant_id=o.restaurant_id AND k.order_id=o.id) AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.quantity>0)';
    if($view==='invoices')$where[]="i.payment_status='PAID'";
    if($view==='payments')$where[]="((i.id IS NOT NULL AND i.payment_status IN ('UNPAID','PARTIALLY_PAID')) OR (i.id IS NULL AND (o.status='READY' OR (o.order_type='DINE_IN' AND o.status='SERVED'))))";
    foreach([['o.status',$status],['o.order_type',$type],['o.source',$source],['i.payment_status',$payment]] as [$field,$value]){if($value!==''){$where[]=$field.'=?';$params[]=$value;}}
    if($search!==''){$where[]='(o.order_number LIKE ? OR t.table_number LIKE ? OR o.customer_name LIKE ?)';$like='%'.str_replace(['%','_'],['\\%','\\_'],$search).'%';array_push($params,$like,$like,$like);}
    if($from!==''&&$to!==''){
        try{$zone=new DateTimeZone((string)$staff['timezone']);}catch(Throwable $error){$zone=new DateTimeZone('Asia/Colombo');}
        $where[]='o.created_at>=? AND o.created_at<?';
        $params[]=(new DateTimeImmutable($from.' 00:00:00',$zone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $params[]=(new DateTimeImmutable($to.' 00:00:00',$zone))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    $limit=$view==='kot'?19:100;
    $q=$db->prepare('SELECT o.id,o.order_number,o.order_type,o.delivery_status,o.source,o.status,o.total,o.created_at,t.table_number,i.id invoice_id,i.invoice_number,i.total invoice_total,i.paid_total,i.payment_status FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id LEFT JOIN pos_invoices i ON i.order_id=o.id AND i.restaurant_id=o.restaurant_id WHERE '.implode(' AND ',$where).' ORDER BY o.id '.($sort==='oldest'?'ASC':'DESC').' LIMIT '.$limit.' OFFSET '.(int)$offset);
    $q->execute($params);$orders=$q->fetchAll();$hasMore=$view==='kot'&&count($orders)>18;if($hasMore)array_pop($orders);json_out(['orders'=>$orders,'has_more'=>$hasMore]);
}
if ($path === '/api/pos/audit') {
    if (!in_array($staff['role'],['RESTAURANT_ADMIN','CASHIER'],true)) order_fail('Restaurant staff access required.',403);
    if (!(int)$staff['manager_bill_edit_enabled']) order_fail('Bill edit history is not enabled for this restaurant.',403);
    $invoiceId=(int)($_GET['invoice_id']??0);
    $q=$db->prepare('SELECT action_type,item_id,previous_value,new_value,reason,user_name,user_role,ip_address,created_at FROM pos_bill_edit_audit WHERE restaurant_id=? AND invoice_id=? ORDER BY id DESC LIMIT 100');
    $q->execute([$rid,$invoiceId]); json_out(['edits'=>$q->fetchAll()]);
}
if ($path === '/api/pos/order-details') {
    $orderId=(int)($_GET['id']??0);
    $q=$db->prepare('SELECT o.*,t.table_number,i.id invoice_id,i.invoice_number,i.total invoice_total,i.paid_total,i.payment_status,COALESCE(it.amount,0) tip_amount,oca.id online_account_id FROM orders o LEFT JOIN restaurant_tables t ON t.id=o.table_id LEFT JOIN pos_invoices i ON i.order_id=o.id AND i.restaurant_id=o.restaurant_id LEFT JOIN pos_invoice_tips it ON it.invoice_id=i.id AND it.restaurant_id=i.restaurant_id LEFT JOIN online_customer_accounts oca ON oca.restaurant_id=o.restaurant_id AND oca.customer_id=o.customer_id WHERE o.id=? AND o.restaurant_id=?');$q->execute([$orderId,$rid]);$order=$q->fetch();
    if(!$order)order_fail('Order not found.',404);
    $order['manager_bill_edit_enabled']=(int)$staff['manager_bill_edit_enabled'];
    unset($order['idempotency_key']);
    $q=$db->prepare('SELECT oi.id,oi.menu_item_id,oi.item_name,oi.portion,oi.unit_price,oi.quantity,oi.subtotal,oi.special_instruction,m.image_path FROM order_items oi LEFT JOIN menu_items m ON m.id=oi.menu_item_id AND m.restaurant_id=? WHERE oi.order_id=? AND oi.quantity>0 ORDER BY oi.id');$q->execute([$rid,$orderId]);$order['items']=$q->fetchAll();
    $q=$db->prepare('SELECT id,kot_number,created_at FROM pos_kots WHERE restaurant_id=? AND order_id=? ORDER BY id DESC');$q->execute([$rid,$orderId]);$order['kots']=$q->fetchAll();$order['kot_pending']=pos_kot_pending($db,$orderId,(int)$rid);
    json_out(['order'=>$order]);
}
if ($path === '/api/pos/bill-details') {
    $invoiceId=(int)($_GET['id']??0);
    $q=$db->prepare('SELECT * FROM pos_invoices WHERE id=? AND restaurant_id=?');$q->execute([$invoiceId,$rid]);$invoice=$q->fetch();
    if(!$invoice)order_fail('Bill not found.',404);
    $q=$db->prepare('SELECT id,menu_item_id,item_name,portion,quantity,unit_price,discount,line_total FROM pos_invoice_items WHERE invoice_id=? ORDER BY id');$q->execute([$invoiceId]);$invoice['items']=$q->fetchAll();
    json_out(['invoice'=>$invoice]);
}
if ($path === '/api/pos/bill-preview') {
    $orderId=(int)($_GET['order_id']??0);
    $q=$db->prepare('SELECT id,order_number,order_type,packaging_charge,delivery_charge FROM orders WHERE id=? AND restaurant_id=?');$q->execute([$orderId,$rid]);$order=$q->fetch();if(!$order)order_fail('Order not found.',404);
    try{$discount=pos_cents((string)($_GET['discount']??'0'));$tax=pos_cents((string)($_GET['tax']??'0'));}catch(InvalidArgumentException $error){order_fail($error->getMessage());}
    $q=$db->prepare('SELECT subtotal FROM order_items WHERE order_id=? AND quantity>0');$q->execute([$orderId]);$subtotal=0;foreach($q as $line)$subtotal+=pos_cents($line['subtotal']);
    if($discount>$subtotal)order_fail('Discount cannot exceed item subtotal.');
    $service=$order['order_type']==='DINE_IN'?pos_percent_charge($subtotal,(string)$staff['service_charge_percent']):0;
    $packaging=pos_cents($order['packaging_charge']);$delivery=pos_cents($order['delivery_charge']);$total=$subtotal-$discount+$service+$tax+$packaging+$delivery;
    if($total>999999999999)order_fail('Bill total exceeds the supported currency limit.');
    json_out(['order_number'=>$order['order_number'],'subtotal'=>pos_money($subtotal),'discount'=>pos_money($discount),'service_charge'=>pos_money($service),'service_charge_percent'=>$staff['service_charge_percent'],'tax'=>pos_money($tax),'packaging_charge'=>pos_money($packaging),'delivery_charge'=>pos_money($delivery),'total'=>pos_money($total)]);
}
if ($path === '/api/pos/reports') {
    if (!in_array($staff['role'],['RESTAURANT_ADMIN','CASHIER'],true)) order_fail('Restaurant staff access required.',403);
    $from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
    $start=DateTimeImmutable::createFromFormat('!Y-m-d',$from);
    $end=DateTimeImmutable::createFromFormat('!Y-m-d',$to);
    if(!$start||!$end||$start->format('Y-m-d')!==$from||$end->format('Y-m-d')!==$to||$to<$from||$end->diff($start)->days>366)order_fail('Choose a valid date range of up to 366 days.');
    try{$zone=new DateTimeZone((string)$staff['timezone']);}catch(Throwable $error){$zone=new DateTimeZone('Asia/Colombo');}
    $utcFrom=(new DateTimeImmutable($from.' 00:00:00',$zone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $utcTo=(new DateTimeImmutable($to.' 00:00:00',$zone))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $q=$db->prepare('SELECT COUNT(*) invoice_count,COALESCE(SUM(subtotal),0) gross_sales,COALESCE(SUM(discount),0) discounts,COALESCE(SUM(service_charge),0) service_charges,COALESCE(SUM(tax),0) tax,COALESCE(SUM(packaging_charge),0) packaging_charges,COALESCE(SUM(delivery_charge),0) delivery_charges,COALESCE(SUM(total),0) billed_total,COALESCE(SUM(paid_total),0) collected FROM pos_invoices WHERE restaurant_id=? AND created_at>=? AND created_at<?');$q->execute([$rid,$utcFrom,$utcTo]);$summary=$q->fetch();
    $q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM pos_invoice_tips WHERE restaurant_id=? AND updated_at>=? AND updated_at<?');$q->execute([$rid,$utcFrom,$utcTo]);$summary['tips']=$q->fetchColumn();
    $q=$db->prepare('SELECT p.method,COALESCE(SUM(p.amount),0) amount FROM pos_payments p WHERE p.restaurant_id=? AND p.created_at>=? AND p.created_at<? GROUP BY p.method');$q->execute([$rid,$utcFrom,$utcTo]);$methods=$q->fetchAll();
    $q=$db->prepare('SELECT o.order_type,o.source,COUNT(*) order_count,COALESCE(SUM(i.total),0) billed_total FROM pos_invoices i JOIN orders o ON o.id=i.order_id AND o.restaurant_id=i.restaurant_id WHERE i.restaurant_id=? AND i.created_at>=? AND i.created_at<? GROUP BY o.order_type,o.source');$q->execute([$rid,$utcFrom,$utcTo]);$byType=$q->fetchAll();
    $q=$db->prepare('SELECT COUNT(*) FROM orders WHERE restaurant_id=? AND status IN ("CANCELLED","REJECTED") AND created_at>=? AND created_at<?');$q->execute([$rid,$utcFrom,$utcTo]);$cancelled=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT COUNT(DISTINCT invoice_id) FROM pos_bill_edit_audit WHERE restaurant_id=? AND created_at>=? AND created_at<?');$q->execute([$rid,$utcFrom,$utcTo]);$edited=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT created_at,total FROM pos_invoices WHERE restaurant_id=? AND created_at>=? AND created_at<? ORDER BY created_at');
    $q->execute([$rid,$utcFrom,$utcTo]);$dailyBuckets=[];
    foreach($q as $row){$day=(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');$dailyBuckets[$day]??=['sale_date'=>$day,'order_count'=>0,'total_cents'=>0];$dailyBuckets[$day]['order_count']++;$dailyBuckets[$day]['total_cents']+=pos_cents($row['total']);}
    $daily=[];$monthlyBuckets=[];
    foreach($dailyBuckets as $bucket){
        $daily[]=['sale_date'=>$bucket['sale_date'],'order_count'=>$bucket['order_count'],'total'=>pos_money($bucket['total_cents'])];
        $month=substr($bucket['sale_date'],0,7);
        $monthlyBuckets[$month]??=['month'=>$month,'order_count'=>0,'total_cents'=>0];
        $monthlyBuckets[$month]['order_count']+=$bucket['order_count'];
        $monthlyBuckets[$month]['total_cents']+=$bucket['total_cents'];
    }
    $monthly=[];foreach($monthlyBuckets as $bucket)$monthly[]=['month'=>$bucket['month'],'order_count'=>$bucket['order_count'],'total'=>pos_money($bucket['total_cents'])];
    json_out(['summary'=>$summary,'payment_methods'=>$methods,'by_type'=>$byType,'cancelled_orders'=>$cancelled,'manager_edited_bills'=>$edited,'daily'=>$daily,'monthly'=>$monthly]);
}
if ($path === '/api/pos/receipt-data') {
    $id=(int)($_GET['id']??0);
    $q=$db->prepare('SELECT i.*,o.order_number,o.order_type,o.customer_name,o.created_at order_at,t.table_number,r.name restaurant_name,r.address restaurant_address,r.contact_phone,r.logo_path,p.print_logo_on_receipt,p.receipt_template,u.name cashier_name FROM pos_invoices i JOIN orders o ON o.id=i.order_id AND o.restaurant_id=i.restaurant_id JOIN restaurants r ON r.id=i.restaurant_id LEFT JOIN restaurant_pos_settings p ON p.restaurant_id=r.id LEFT JOIN restaurant_tables t ON t.id=o.table_id JOIN users u ON u.id=i.generated_by WHERE i.id=? AND i.restaurant_id=?');
    $q->execute([$id,$rid]);$record=$q->fetch();if(!$record)order_fail('Bill not found.',404);
    $q=$db->prepare('SELECT item_name,portion,quantity,unit_price,line_total,special_instruction FROM pos_invoice_items WHERE invoice_id=? ORDER BY id');$q->execute([$id]);$items=[];
    foreach($q as $line)$items[]=['name'=>$line['item_name'],'quantity'=>(float)$line['quantity'],'unitPrice'=>(float)$line['unit_price'],'amount'=>(float)$line['line_total'],'variants'=>$line['portion']?[$line['portion']]:[],'addOns'=>[],'specialInstructions'=>$line['special_instruction']??''];
    $q=$db->prepare('SELECT method,amount,amount_received,reference FROM pos_payments WHERE invoice_id=? AND restaurant_id=? ORDER BY id');$q->execute([$id,$rid]);$paymentRows=$q->fetchAll();$payments=[];
    foreach($paymentRows as $p)$payments[]=['method'=>$p['method'],'amount'=>(float)$p['amount'],'amountReceived'=>$p['amount_received']!==null?(float)$p['amount_received']:null,'reference'=>$p['reference']??''];
    $cash=array_filter($payments,static fn($p)=>$p['method']==='CASH');$method=implode(' + ',array_values(array_unique(array_column($payments,'method'))));
    $received=0.0;$change=0.0;foreach($cash as $p){$received+=(float)($p['amount_received']??$p['amount']);$change+=max(0,(float)($p['amount_received']??$p['amount'])-(float)$p['amount']);}
    $printedAt=(new DateTimeImmutable($record['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)$staff['timezone']))->format('Y-m-d H:i');
    $printLogo=(bool)($record['print_logo_on_receipt']??false) && !empty($record['logo_path']);
    $logoUrl=$printLogo?site_base().'/'.ltrim((string)$record['logo_path'],'/'):'';
        json_out(['invoiceNo'=>$record['invoice_number'],'orderNo'=>$record['order_number'],'restaurantName'=>$record['restaurant_name'],'receiptTemplate'=>(int)($record['receipt_template']??1),'printLogo'=>$printLogo,'restaurantLogoUrl'=>$logoUrl,'restaurantAddress'=>$record['restaurant_address']??'','contactPhone'=>$record['contact_phone']??'','customerName'=>$record['customer_name']??'','orderType'=>$record['order_type'],'table'=>$record['table_number']?'Table '.$record['table_number']:'','dateTime'=>$printedAt,'cashier'=>$record['cashier_name'],'items'=>$items,'subtotal'=>(float)$record['subtotal'],'serviceCharge'=>(float)$record['service_charge'],'discount'=>(float)$record['discount'],'tax'=>(float)$record['tax'],'packaging'=>(float)$record['packaging_charge'],'delivery'=>(float)$record['delivery_charge'],'total'=>(float)$record['total'],'amountPaid'=>(float)$record['paid_total'],'balanceDue'=>max(0,(float)$record['total']-(float)$record['paid_total']),'paymentStatus'=>$record['payment_status'],'paymentMethod'=>$method,'amountReceived'=>$received,'change'=>$change,'payments'=>$payments]);
}
if ($path === '/api/pos/kot-data') {
    $id=(int)($_GET['id']??0);
    $q=$db->prepare('SELECT k.*,o.order_number,o.order_type,o.special_request,t.table_number,r.name restaurant_name,u.name cashier_name FROM pos_kots k JOIN orders o ON o.id=k.order_id AND o.restaurant_id=k.restaurant_id JOIN restaurants r ON r.id=k.restaurant_id LEFT JOIN restaurant_tables t ON t.id=o.table_id JOIN users u ON u.id=k.created_by WHERE k.id=? AND k.restaurant_id=?');$q->execute([$id,$rid]);$record=$q->fetch();if(!$record)order_fail('Kitchen ticket not found.',404);
    $q=$db->prepare('SELECT item_name,portion,quantity,action,special_instruction FROM pos_kot_items WHERE kot_id=? ORDER BY id');$q->execute([$id]);$items=[];
    foreach($q as $line)$items[]=['name'=>$line['action'].' · '.$line['item_name'],'quantity'=>(float)$line['quantity'],'unitPrice'=>0,'amount'=>0,'variants'=>$line['portion']?[$line['portion']]:[],'addOns'=>[],'specialInstructions'=>$line['special_instruction']??''];
    $printedAt=(new DateTimeImmutable($record['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)$staff['timezone']))->format('Y-m-d H:i');
    json_out(['restaurant'=>$record['restaurant_name'],'kotNumber'=>$record['kot_number'],'orderNumber'=>$record['order_number'],'table'=>$record['table_number']?'Table '.$record['table_number']:'','orderType'=>$record['order_type'],'dateTime'=>$printedAt,'user'=>$record['cashier_name'],'items'=>$items]);
}
if ($path === '/api/pos/receipt' || $path === '/api/pos/kot-print') {
    $width = (string)($_GET['width'] ?? '80');
    if (!in_array($width, ['58','80'], true)) $width = '80';
    try {$printZone = new DateTimeZone((string)$staff['timezone']);} catch (Throwable $error) {$printZone = new DateTimeZone('Asia/Colombo');}
    if ($path === '/api/pos/receipt') {
        $id=(int)($_GET['id']??0);
        $q=$db->prepare('SELECT i.*,o.order_number,o.order_type,o.customer_name,o.delivery_address,o.special_request,o.created_at order_at,t.table_number,r.name restaurant_name,r.address restaurant_address,r.contact_phone,r.logo_path,p.print_logo_on_receipt,p.receipt_template,u.name cashier_name FROM pos_invoices i JOIN orders o ON o.id=i.order_id AND o.restaurant_id=i.restaurant_id JOIN restaurants r ON r.id=i.restaurant_id LEFT JOIN restaurant_pos_settings p ON p.restaurant_id=i.restaurant_id LEFT JOIN restaurant_tables t ON t.id=o.table_id JOIN users u ON u.id=i.generated_by WHERE i.id=? AND i.restaurant_id=?');
        $q->execute([$id,$rid]);$record=$q->fetch();
        if(!$record)order_fail('Bill not found.',404);
        $q=$db->prepare('SELECT item_name,portion,quantity,unit_price,discount,line_total,special_instruction FROM pos_invoice_items WHERE invoice_id=? ORDER BY id');$q->execute([$id]);$lines=$q->fetchAll();
        $q=$db->prepare('SELECT method,amount,amount_received,reference FROM pos_payments WHERE invoice_id=? AND restaurant_id=? ORDER BY id');$q->execute([$id,$rid]);$payments=$q->fetchAll();
        $title='Bill '.e($record['invoice_number']);
        $printedAt=(new DateTimeImmutable($record['created_at'],new DateTimeZone('UTC')))->setTimezone($printZone)->format('Y-m-d H:i');
        $printLogo=(bool)($record['print_logo_on_receipt']??false)&&!empty($record['logo_path']);
        $body='<header>'.($printLogo?'<img class="logo" alt="" src="'.e(site_base().'/'.$record['logo_path']).'">':'').'<h1>'.e($record['restaurant_name']).'</h1><p>'.nl2br(e($record['restaurant_address']??'')).'</p><p>'.e($record['contact_phone']??'').'</p></header><div class="rule"></div><p>Invoice: '.e($record['invoice_number']).'<br>Order: '.e($record['order_number']).'<br>'.e($record['order_type']).($record['table_number']?' · Table '.e($record['table_number']):'').'<br>Date: '.e($printedAt).'<br>By: '.e($record['cashier_name']).'</p>';
        if($record['customer_name'])$body.='<p>Customer: '.e($record['customer_name']).'</p>';
        require_once __DIR__.'/pos-receipt-template.php';
        $body.=pos_receipt_items_html($lines,(int)($record['receipt_template']??1));
        foreach(['Subtotal'=>'subtotal','Discount'=>'discount','Service charge'=>'service_charge','Tax'=>'tax','Packaging charge'=>'packaging_charge','Delivery charge'=>'delivery_charge','Grand total'=>'total','Amount paid'=>'paid_total'] as $label=>$key){if(in_array($key,['discount','service_charge','tax','packaging_charge','delivery_charge'],true) && (float)$record[$key]===0.0)continue;$body.='<p class="total"><span>'.e($label).'</span><strong>LKR '.e($record[$key]).'</strong></p>';}
        $body.='<p class="total balance"><span>Outstanding balance</span><strong>LKR '.pos_money(max(0,pos_cents($record['total'])-pos_cents($record['paid_total']))).'</strong></p><p>Payment status: '.e($record['payment_status']).'</p>';
        foreach($payments as $payment){
            if($payment['method']!=='CASH')$body.='<p class="total"><span>'.e(ucwords(strtolower(str_replace('_',' ',$payment['method'])))).'</span><strong>LKR '.e($payment['amount']).'</strong></p>';
            if($payment['reference'])$body.='<small>Reference: '.e($payment['reference']).'</small>';
            if($payment['method']==='CASH' && $payment['amount_received']!==null){$change=pos_cents($payment['amount_received'])-pos_cents($payment['amount']);$body.='<p class="total"><span>Cash received</span><strong>LKR '.e($payment['amount_received']).'</strong></p><p class="total change"><span>Change due</span><strong>LKR '.pos_money(max(0,$change)).'</strong></p>';}
        }
        $body.='<p class="thanks">Thank you for dining with us.</p>';
    } else {
        $id=(int)($_GET['id']??0);
        $q=$db->prepare('SELECT k.*,o.order_number,o.order_type,o.special_request,t.table_number,r.name restaurant_name,u.name cashier_name FROM pos_kots k JOIN orders o ON o.id=k.order_id AND o.restaurant_id=k.restaurant_id JOIN restaurants r ON r.id=k.restaurant_id LEFT JOIN restaurant_tables t ON t.id=o.table_id JOIN users u ON u.id=k.created_by WHERE k.id=? AND k.restaurant_id=?');$q->execute([$id,$rid]);$record=$q->fetch();
        if(!$record)order_fail('Kitchen ticket not found.',404);
        $q=$db->prepare('SELECT item_name,portion,quantity,action,special_instruction FROM pos_kot_items WHERE kot_id=? ORDER BY id');$q->execute([$id]);$lines=$q->fetchAll();
        $title='KOT '.e($record['kot_number']);
        $printedAt=(new DateTimeImmutable($record['created_at'],new DateTimeZone('UTC')))->setTimezone($printZone)->format('Y-m-d H:i');
        $body='<header><h1>'.e($record['restaurant_name']).'</h1><h2>KITCHEN ORDER TICKET</h2></header><p>KOT: '.e($record['kot_number']).'<br>Order: '.e($record['order_number']).'<br>'.e($record['order_type']).($record['table_number']?' · Table '.e($record['table_number']):'').'<br>Date: '.e($printedAt).'<br>By: '.e($record['cashier_name']).'</p><div class="rule"></div>';
        foreach($lines as $line)$body.='<p><strong>'.e($line['action']).' · '.(int)$line['quantity'].' × '.e($line['item_name']).($line['portion']?' ('.e($line['portion']).')':'').'</strong>'.($line['special_instruction']?'<small>'.e($line['special_instruction']).'</small>':'').'</p>';
        if($record['special_request'])$body.='<p>Order note: '.e($record['special_request']).'</p>';
    }
    header('Content-Type: text/html; charset=utf-8');
    $printCredit=$path==='/api/pos/receipt'?'<footer style="text-align:center;margin-top:16px;font-size:9px">Software Development by <a href="https://www.energeticedge.lk/" target="_blank" rel="noopener noreferrer">Energetic Edge (Pvt) Ltd</a></footer>':'';
    $contentWidth=$width-6;
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title><style>@page{size:'.$width.'mm auto;margin:3mm}*{box-sizing:border-box}body{font:12px/1.4 Arial,sans-serif;color:#111;width:'.$contentWidth.'mm;max-width:100%;margin:0 auto;padding:8px}header{text-align:center}h1{font-size:17px;margin:4px 0}h2{font-size:13px}.logo{max-width:50px;max-height:50px}.rule{border-top:1px dashed #555;margin:10px 0}.right{text-align:right}table{width:100%;border-collapse:collapse}th,td{padding:4px 1px;vertical-align:top}small{display:block}.total{display:flex;justify-content:space-between;margin:4px 0}.thanks{text-align:center;margin-top:18px}.print-controls{text-align:center;margin:15px 0}.print-controls button{padding:8px 15px}@media print{.print-controls{display:none}body{padding:0}}</style></head><body>'.((string)($_GET['embed']??'')==='1'?'':'<div class="print-controls"><button onclick="window.print()">Print</button></div>').$body.$printCredit.((string)($_GET['autoprint']??'')==='1'?'<script>window.addEventListener("load",()=>setTimeout(()=>window.print(),250));</script>':'').'</body></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') order_fail('POST required.', 405);
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) order_fail('Refresh the page to renew your security token.', 403);
try {
    if ($path === '/api/pos/tip') {
        if (!in_array($staff['role'],['RESTAURANT_ADMIN','CASHIER'],true)) order_fail('Restaurant staff access required.',403);
        $invoiceId=(int)($_POST['invoice_id']??0);
        try{$tipCents=pos_cents((string)($_POST['amount']??''));}catch(InvalidArgumentException $error){order_fail($error->getMessage());}
        if($invoiceId<1||$tipCents<0||$tipCents>999999999999)order_fail('Enter a valid tip amount.');
        $db->beginTransaction();
        $q=$db->prepare('SELECT id FROM pos_invoices WHERE id=? AND restaurant_id=? AND payment_status="PAID" FOR UPDATE');$q->execute([$invoiceId,$rid]);
        if(!$q->fetchColumn())throw new InvalidArgumentException('Tips can only be updated for a paid bill in this restaurant.');
        $q=$db->prepare('INSERT INTO pos_invoice_tips(restaurant_id,invoice_id,amount,updated_by,created_at,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE amount=VALUES(amount),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()');
        $q->execute([$rid,$invoiceId,pos_money($tipCents),$userId]);
        $db->commit();
        json_out(['ok'=>true,'invoice_id'=>$invoiceId,'tip_amount'=>pos_money($tipCents)]);
    }
    if ($path === '/api/pos/kot/reconcile') {
        $db->beginTransaction();
        $q=$db->prepare("SELECT o.id FROM orders o WHERE o.restaurant_id=? AND o.status='PREPARING' AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.quantity>0) AND NOT EXISTS (SELECT 1 FROM pos_kots k WHERE k.restaurant_id=o.restaurant_id AND k.order_id=o.id) ORDER BY o.id FOR UPDATE");
        $q->execute([$rid]);
        $orders=$q->fetchAll(PDO::FETCH_COLUMN);
        $created=0;
        foreach($orders as $orderId)if(pos_send_kot($db,(int)$orderId,$rid,$userId)!==null)$created++;
        $db->commit();
        json_out(['ok'=>true,'created'=>$created]);
    }
    if ($path === '/api/pos/customer/save') {
        $id=(int)($_POST['id']??0);
        if($id>0 && !in_array($staff['role'],['RESTAURANT_ADMIN','CASHIER'],true))throw new InvalidArgumentException('Restaurant staff access required to edit customers.');
        $name=trim((string)($_POST['name']??''));$phone=preg_replace('/\D/','',(string)($_POST['phone']??''))??'';$address=trim((string)($_POST['address']??''));
        if($name===''||mb_strlen($name)>120)throw new InvalidArgumentException('Enter a customer name (up to 120 characters).');
        if(!preg_match('/^[0-9]{10}$/D',$phone))throw new InvalidArgumentException('Enter a 10-digit phone number.');
        if(mb_strlen($address)>500)throw new InvalidArgumentException('Address is too long.');
        if($id>0){$q=$db->prepare('SELECT id FROM restaurant_customers WHERE id=? AND restaurant_id=?');$q->execute([$id,$rid]);if(!$q->fetchColumn())throw new InvalidArgumentException('Customer not found.');}
        $q=$db->prepare('SELECT id FROM restaurant_customers WHERE restaurant_id=? AND phone=?');$q->execute([$rid,$phone]);$existing=$q->fetchColumn();
        if($existing && (int)$existing!==$id)throw new InvalidArgumentException('This phone number already belongs to a customer. Select the existing customer instead.');
        if($id>0){$q=$db->prepare('UPDATE restaurant_customers SET name=?,phone=?,address=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$q->execute([$name,$phone,$address?:null,$id,$rid]);}
        else{$q=$db->prepare('INSERT INTO restaurant_customers(restaurant_id,name,phone,address,created_at,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');$q->execute([$rid,$name,$phone,$address?:null]);$id=(int)$db->lastInsertId();}
        json_out(['ok'=>true,'customer'=>['id'=>$id,'name'=>$name,'phone'=>$phone,'address'=>$address]]);
    }
    if ($path === '/api/pos/order') {
        $type = (string)($_POST['order_type'] ?? '');
        if (!in_array($type, ['DINE_IN','TAKEAWAY','DELIVERY'], true)) throw new InvalidArgumentException('Select a valid order type.');
        $name = trim((string)($_POST['customer_name'] ?? ''));
        $phone = trim((string)($_POST['customer_phone'] ?? ''));
        $address = trim((string)($_POST['delivery_address'] ?? ''));
        $deliveryNotes = trim((string)($_POST['delivery_notes'] ?? ''));
        $note = trim((string)($_POST['special_request'] ?? ''));
        if (mb_strlen($name)>120 || mb_strlen($phone)>40 || mb_strlen($address)>500 || mb_strlen($deliveryNotes)>500 || mb_strlen($note)>1000) throw new InvalidArgumentException('Customer details or notes are too long.');
        $phoneKey=$phone!==''?(preg_replace('/\D/','',$phone)??''):'';
        if($phone!=='' && !preg_match('/^[0-9]{10}$/D',$phone))throw new InvalidArgumentException('Enter a 10-digit customer phone number.');
        $packaging = $type!=='DINE_IN' ? pos_cents((string)($_POST['packaging_charge'] ?? '0')) : 0;
        $delivery = $type==='DELIVERY' ? pos_cents((string)($_POST['delivery_charge'] ?? '0')) : 0;
        $guests = $type==='DINE_IN' ? (int)($_POST['guest_count'] ?? 1) : null;
        if ($guests !== null && ($guests<1 || $guests>99)) throw new InvalidArgumentException('Guest count must be between 1 and 99.');
        $entries = json_decode((string)($_POST['items'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($entries) || !array_is_list($entries) || count($entries)<1 || count($entries)>50) throw new InvalidArgumentException('Select 1 to 50 items.');
        $key = (string)($_POST['idempotency_key'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9-]{20,100}$/D', $key)) throw new InvalidArgumentException('Invalid submission key.');
        $key = hash('sha256', 'POS|'.$rid.'|'.$userId.'|'.$key);
        $db->beginTransaction();
        $q = $db->prepare('SELECT id FROM orders WHERE restaurant_id=? AND idempotency_key=?'); $q->execute([$rid,$key]);
        if ($old = $q->fetchColumn()) { $db->commit(); json_out(['ok'=>true,'order_id'=>(int)$old,'already_created'=>true]); }
        $q = $db->prepare('SELECT id FROM subscriptions WHERE restaurant_id=? AND status IN ("ACTIVE","TRIAL") AND starts_on<=UTC_DATE() AND expires_on>=UTC_DATE() LIMIT 1 FOR UPDATE'); $q->execute([$rid]);
        if (!$q->fetchColumn()) throw new InvalidArgumentException('Restaurant subscription is not active.');
        $tableId = null;
        if ($type==='DINE_IN') {
            $tableId = (int)($_POST['table_id'] ?? 0);
            $q = $db->prepare('SELECT id FROM restaurant_tables WHERE id=? AND restaurant_id=? AND is_active=1 FOR UPDATE'); $q->execute([$tableId,$rid]);
            if (!$q->fetchColumn()) throw new InvalidArgumentException('Select an active table.');
        }
        $customerId=null;
        if($phoneKey!==''){
            $q=$db->prepare("INSERT INTO restaurant_customers(restaurant_id,name,phone,address,origin,created_at,updated_at) VALUES(?,?,?,?, 'POS',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=COALESCE(NULLIF(VALUES(name),''),name),address=COALESCE(NULLIF(VALUES(address),''),address),updated_at=UTC_TIMESTAMP()");
            $q->execute([$rid,$name?:null,$phoneKey,$address?:null]);
            $q=$db->prepare('SELECT id FROM restaurant_customers WHERE restaurant_id=? AND phone=?');$q->execute([$rid,$phoneKey]);$customerId=(int)$q->fetchColumn();
        }
        $ids = []; $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !filter_var($entry['id']??null,FILTER_VALIDATE_INT) || !filter_var($entry['qty']??null,FILTER_VALIDATE_INT) || (int)$entry['qty']>99) throw new InvalidArgumentException('Invalid item or quantity.');
            $portion = $entry['portion']??null;
            if ($portion!==null && (!is_string($portion)||mb_strlen($portion)>40)) throw new InvalidArgumentException('Invalid size option.');
            $signature=$entry['id'].':'.($portion??"\0");
            if (isset($seen[$signature])) throw new InvalidArgumentException('Duplicate item selection.');
            $seen[$signature]=true; $ids[(int)$entry['id']]=(int)$entry['id'];
            if (!is_string($entry['note']??'') || mb_strlen($entry['note']??'')>500) throw new InvalidArgumentException('Item instruction is too long.');
        }
        $q=$db->prepare('SELECT * FROM menu_items WHERE restaurant_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id FOR UPDATE');
        $q->execute([$rid,...array_values($ids)]); $menu=[]; foreach($q as $row)$menu[(int)$row['id']]=$row;
        if(count($menu)!==count($ids))throw new InvalidArgumentException('One or more items are not in this restaurant.');
        $lines=[]; $total=$packaging+$delivery;
        foreach($entries as $entry){$item=$menu[(int)$entry['id']];$portion=$entry['portion']??null;$unit=pos_item_price($item,$portion,$db,(int)$rid);$quantity=(int)$entry['qty'];$line=$unit*$quantity;$total+=$line;$lines[]=[$item,$portion,$quantity,$unit,$line,trim($entry['note']??'')];}
        $number=new_order_number($db,(int)$rid);
        $q=$db->prepare('INSERT INTO orders(restaurant_id,table_id,dining_session_id,order_number,special_request,total,status,idempotency_key,source,order_type,customer_name,customer_phone,delivery_address,delivery_notes,packaging_charge,delivery_charge,guest_count,created_by,customer_id,created_at,updated_at) VALUES(?,?,NULL,?,?,?,"PREPARING",?,?,?,?,?,?,?,?,?,?,?, ?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        $q->execute([$rid,$tableId,$number,$note,pos_money($total),$key,'POS',$type,$name?:null,$phone?:null,$address?:null,$deliveryNotes?:null,pos_money($packaging),pos_money($delivery),$guests,$userId,$customerId]);
        $orderId=(int)$db->lastInsertId();
        $q=$db->prepare('INSERT INTO order_items(order_id,menu_item_id,item_name,portion,unit_price,quantity,subtotal,special_instruction) VALUES(?,?,?,?,?,?,?,?)');
        foreach($lines as [$item,$portion,$qty,$unit,$line,$instruction])$q->execute([$orderId,$item['id'],$item['name'],$portion,pos_money($unit),$qty,pos_money($line),$instruction?:null]);
        $kotId=pos_send_kot($db,$orderId,$rid,$userId);
        $db->commit(); json_out(['ok'=>true,'order_id'=>$orderId,'order_number'=>$number,'total'=>pos_money($total),'kot_id'=>$kotId]);
    }
    if ($path === '/api/pos/order/item') {
        $orderId=(int)($_POST['order_id']??0);$action=(string)($_POST['action']??'');
        if(!in_array($action,['ADD','QUANTITY','REMOVE'],true))throw new InvalidArgumentException('Invalid order item action.');
        $db->beginTransaction();
        $q=$db->prepare('SELECT id,status,source,packaging_charge,delivery_charge FROM orders WHERE id=? AND restaurant_id=? FOR UPDATE');$q->execute([$orderId,$rid]);$order=$q->fetch();
        if(!$order)throw new InvalidArgumentException('Order not found.');
        if(in_array($order['status'],['REJECTED','CANCELLED','COMPLETED'],true))throw new InvalidArgumentException('This order is closed.');
        $q=$db->prepare('SELECT id FROM pos_invoices WHERE restaurant_id=? AND order_id=?');$q->execute([$rid,$orderId]);if($q->fetchColumn())throw new InvalidArgumentException('A final bill exists. Only authorized Manager bill edits can change it.');
        if($action==='ADD'){
            $itemId=(int)($_POST['menu_item_id']??0);$portion=$_POST['portion']??null;if($portion==='')$portion=null;
            $qty=(int)($_POST['quantity']??0);if($qty<1||$qty>99)throw new InvalidArgumentException('Quantity must be between 1 and 99.');
            $instruction=trim((string)($_POST['special_instruction']??''));if(mb_strlen($instruction)>500)throw new InvalidArgumentException('Item instruction is too long.');
            $q=$db->prepare('SELECT * FROM menu_items WHERE id=? AND restaurant_id=? FOR UPDATE');$q->execute([$itemId,$rid]);$menu=$q->fetch();if(!$menu)throw new InvalidArgumentException('Menu item not found.');
            $unit=pos_item_price($menu,$portion,$db,(int)$rid);
            $q=$db->prepare('INSERT INTO order_items(order_id,menu_item_id,item_name,portion,unit_price,quantity,subtotal,special_instruction) VALUES(?,?,?,?,?,?,?,?)');
            $q->execute([$orderId,$itemId,$menu['name'],$portion,pos_money($unit),$qty,pos_money($unit*$qty),$instruction?:null]);
        }else{
            $lineId=(int)($_POST['order_item_id']??0);$q=$db->prepare('SELECT id,unit_price FROM order_items WHERE id=? AND order_id=? FOR UPDATE');$q->execute([$lineId,$orderId]);$line=$q->fetch();if(!$line)throw new InvalidArgumentException('Order item not found.');
            $qty=$action==='REMOVE'?0:(int)($_POST['quantity']??0);if($qty<0||$qty>99)throw new InvalidArgumentException('Quantity must be between 0 and 99.');
            $q=$db->prepare('UPDATE order_items SET quantity=?,subtotal=? WHERE id=? AND order_id=?');$q->execute([$qty,pos_money(pos_cents($line['unit_price'])*$qty),$lineId,$orderId]);
        }
        $q=$db->prepare('SELECT subtotal FROM order_items WHERE order_id=? AND quantity>0');$q->execute([$orderId]);$subtotal=0;foreach($q as $line)$subtotal+=pos_cents($line['subtotal']);
        if($subtotal===0)throw new InvalidArgumentException('An order must have at least one item.');
        $total=$subtotal+pos_cents($order['packaging_charge'])+pos_cents($order['delivery_charge']);
        if($order['source']==='QR')$total+=pos_percent_charge($subtotal,(string)$staff['service_charge_percent']);
        $q=$db->prepare('UPDATE orders SET total=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$q->execute([pos_money($total),$orderId,$rid]);
        $kotId=null;
        if($action!=='QUANTITY'){$q=$db->prepare('SELECT id FROM pos_kots WHERE restaurant_id=? AND order_id=? LIMIT 1');$q->execute([$rid,$orderId]);$kotId=$q->fetchColumn()?pos_send_kot($db,$orderId,$rid,$userId):null;}
        $kotPending=pos_kot_pending($db,$orderId,(int)$rid);
        $db->commit();json_out(['ok'=>true,'total'=>pos_money($total),'kot_id'=>$kotId,'kot_pending'=>$kotPending]);
    }
    if ($path === '/api/pos/kot') {
        $orderId=(int)($_POST['order_id']??0);
        $db->beginTransaction();
        $q=$db->prepare('SELECT id,status FROM orders WHERE id=? AND restaurant_id=? FOR UPDATE');$q->execute([$orderId,$rid]);$order=$q->fetch();
        if(!$order)throw new InvalidArgumentException('Order not found.');
        if(in_array($order['status'],['REJECTED','CANCELLED','COMPLETED'],true))throw new InvalidArgumentException('Closed orders cannot be sent to kitchen.');
        $kotId=pos_send_kot($db,$orderId,$rid,$userId);
        $printKotId=$kotId;
        if (!$printKotId) {
            $q=$db->prepare('SELECT id FROM pos_kots WHERE restaurant_id=? AND order_id=? ORDER BY id DESC LIMIT 1');
            $q->execute([$rid,$orderId]);
            $printKotId=(int)$q->fetchColumn() ?: null;
        }
        $db->commit();json_out(['ok'=>true,'kot_id'=>$kotId,'print_kot_id'=>$printKotId,'message'=>$kotId?'Kitchen ticket recorded.':'No new kitchen changes.']);
    }
    if ($path === '/api/pos/status') {
        $orderId=(int)($_POST['order_id']??0);$from=(string)($_POST['expected_status']??'');$next=(string)($_POST['status']??'');
        $allowed=['PENDING'=>['ACCEPTED'],'ACCEPTED'=>['PREPARING'],'PREPARING'=>['READY'],'READY'=>['SERVED']];
        if(!in_array($next,$allowed[$from]??[],true))throw new InvalidArgumentException('Invalid order status transition.');
        $db->beginTransaction();
        $q=$db->prepare('SELECT status FROM orders WHERE id=? AND restaurant_id=? FOR UPDATE');$q->execute([$orderId,$rid]);$current=$q->fetchColumn();
        if($current===false)throw new InvalidArgumentException('Order not found.');
        if($current!==$from)throw new InvalidArgumentException('This order status has changed. Refresh and try again.');
        $q=$db->prepare('UPDATE orders SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=? AND status=?');$q->execute([$next,$orderId,$rid,$from]);
        $q=$db->prepare('INSERT INTO order_status_history(restaurant_id,order_id,user_id,previous_status,new_status,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())');$q->execute([$rid,$orderId,$userId,$from,$next]);
        $kotId=$next==='PREPARING'?pos_send_kot($db,$orderId,(int)$rid,$userId):null;
        $db->commit();json_out(['ok'=>true,'status'=>$next,'kot_id'=>$kotId]);
    }
    if ($path === '/api/pos/order/complete') {
        $orderId=(int)($_POST['order_id']??0);
        $db->beginTransaction();
        $q=$db->prepare('SELECT status,order_type,delivery_status FROM orders WHERE id=? AND restaurant_id=? FOR UPDATE');$q->execute([$orderId,$rid]);$current=$q->fetch();
        if(!$current)throw new InvalidArgumentException('Order not found.');
        $old=$current['status'];
        if(in_array($old,['REJECTED','CANCELLED'],true))throw new InvalidArgumentException('Cancelled orders cannot be completed.');
        if($current['order_type']==='DELIVERY' && $current['delivery_status']!=='DELIVERED')throw new InvalidArgumentException('Mark the delivery as delivered before completing it.');
        $q=$db->prepare('SELECT payment_status FROM pos_invoices WHERE restaurant_id=? AND order_id=? FOR UPDATE');$q->execute([$rid,$orderId]);$payment=$q->fetchColumn();
        if($payment!=='PAID')throw new InvalidArgumentException('Fully settle the bill before completing this order.');
        if($old!=='COMPLETED'){$q=$db->prepare('UPDATE orders SET status="COMPLETED",updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$q->execute([$orderId,$rid]);$q=$db->prepare('INSERT INTO order_status_history(restaurant_id,order_id,user_id,previous_status,new_status,created_at) VALUES(?,?,?,? ,"COMPLETED",UTC_TIMESTAMP())');$q->execute([$rid,$orderId,$userId,$old]);}
        $db->commit();json_out(['ok'=>true,'status'=>'COMPLETED']);
    }
    if ($path === '/api/pos/delivery/status') {
        $orderId=(int)($_POST['order_id']??0);$next=(string)($_POST['status']??'');
        $allowed=['NONE'=>'READY_FOR_DELIVERY','READY_FOR_DELIVERY'=>'OUT_FOR_DELIVERY','OUT_FOR_DELIVERY'=>'DELIVERED'];
        $db->beginTransaction();
        $q=$db->prepare('SELECT order_type,delivery_status,status FROM orders WHERE id=? AND restaurant_id=? FOR UPDATE');$q->execute([$orderId,$rid]);$order=$q->fetch();
        if(!$order||$order['order_type']!=='DELIVERY')throw new InvalidArgumentException('Delivery order not found.');
        if(in_array($order['status'],['REJECTED','CANCELLED','COMPLETED'],true))throw new InvalidArgumentException('This delivery order is closed.');
        if(($allowed[$order['delivery_status']]??null)!==$next)throw new InvalidArgumentException('Invalid delivery status transition.');
        $q=$db->prepare('SELECT id FROM pos_invoices WHERE restaurant_id=? AND order_id=?');$q->execute([$rid,$orderId]);if(!$q->fetchColumn())throw new InvalidArgumentException('Generate the bill before moving delivery status.');
        $q=$db->prepare('UPDATE orders SET delivery_status=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$q->execute([$next,$orderId,$rid]);
        $q=$db->prepare('INSERT INTO pos_delivery_status_history(restaurant_id,order_id,user_id,previous_status,new_status,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())');$q->execute([$rid,$orderId,$userId,$order['delivery_status'],$next]);
        $db->commit();json_out(['ok'=>true,'delivery_status'=>$next]);
    }
    if ($path === '/api/pos/bill') {
        $orderId=(int)($_POST['order_id']??0);
        $db->beginTransaction();
        $q=$db->prepare('SELECT * FROM orders WHERE id=? AND restaurant_id=? FOR UPDATE');$q->execute([$orderId,$rid]);$order=$q->fetch();
        if(!$order)throw new InvalidArgumentException('Order not found.');
        if(in_array($order['status'],['REJECTED','CANCELLED'],true))throw new InvalidArgumentException('Cancelled orders cannot be billed.');
        $q=$db->prepare('SELECT id,invoice_number,total,paid_total,payment_status FROM pos_invoices WHERE restaurant_id=? AND order_id=?');$q->execute([$rid,$orderId]);
        if($existing=$q->fetch()){$db->commit();json_out(['ok'=>true,'invoice'=>$existing,'already_generated'=>true]);}
        $q=$db->prepare('SELECT * FROM order_items WHERE order_id=? AND quantity>0');$q->execute([$orderId]);$items=$q->fetchAll();
        if(!$items)throw new InvalidArgumentException('Order has no items.');
        $subtotal=0;foreach($items as $item)$subtotal+=pos_cents((string)$item['subtotal']);
        $discount=pos_cents((string)($_POST['discount']??'0'));
        $service=$order['order_type']==='DINE_IN'?pos_percent_charge($subtotal,(string)$staff['service_charge_percent']):0;
        $tax=pos_cents((string)($_POST['tax']??'0'));
        if($discount>$subtotal)throw new InvalidArgumentException('Discount cannot exceed item subtotal.');
        $packaging=pos_cents((string)$order['packaging_charge']);$delivery=pos_cents((string)$order['delivery_charge']);$total=$subtotal-$discount+$service+$tax+$packaging+$delivery;
        if($total>999999999999)throw new InvalidArgumentException('Bill total exceeds the supported currency limit.');
        $invoiceNumber='INV-'.strtoupper(bin2hex(random_bytes(6)));
        $q=$db->prepare('INSERT INTO pos_invoices(restaurant_id,order_id,invoice_number,subtotal,discount,service_charge,tax,packaging_charge,delivery_charge,total,payment_status,generated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        $q->execute([$rid,$orderId,$invoiceNumber,pos_money($subtotal),pos_money($discount),pos_money($service),pos_money($tax),pos_money($packaging),pos_money($delivery),pos_money($total),$total===0?'PAID':'UNPAID',$userId]);$invoiceId=(int)$db->lastInsertId();
        $q=$db->prepare('INSERT INTO pos_invoice_items(invoice_id,order_item_id,menu_item_id,item_name,portion,quantity,unit_price,line_total,special_instruction) VALUES(?,?,?,?,?,?,?,?,?)');
        foreach($items as $item)$q->execute([$invoiceId,$item['id'],$item['menu_item_id'],$item['item_name'],$item['portion'],$item['quantity'],$item['unit_price'],$item['subtotal'],$item['special_instruction']]);
        $db->commit();json_out(['ok'=>true,'invoice'=>['id'=>$invoiceId,'invoice_number'=>$invoiceNumber,'total'=>pos_money($total),'paid_total'=>'0.00','payment_status'=>$total===0?'PAID':'UNPAID']]);
    }
    if ($path === '/api/pos/payment') {
        $invoiceId=(int)($_POST['invoice_id']??0);$method=(string)($_POST['method']??'');
        if(!in_array($method,['CASH','CARD','BANK_TRANSFER','ONLINE_PAYMENT','OTHER'],true))throw new InvalidArgumentException('Invalid payment method.');
        $retryKey=(string)($_POST['idempotency_key']??'');
        if(!preg_match('/^[a-zA-Z0-9-]{20,100}$/D',$retryKey))throw new InvalidArgumentException('Invalid payment submission key. Refresh the POS screen.');
        $retryHash=hash('sha256','PAY|'.$rid.'|'.$userId.'|'.$retryKey);
        $amount=pos_cents((string)($_POST['amount']??''));if($amount<1)throw new InvalidArgumentException('Enter a payment amount greater than zero.');
        $received=$method==='CASH'?pos_cents((string)($_POST['amount_received']??'')):null;
        if($received!==null && $received<$amount)throw new InvalidArgumentException('Insufficient cash received.');
        $reference=trim((string)($_POST['reference']??''));$notes=trim((string)($_POST['notes']??''));
        if(mb_strlen($reference)>120||mb_strlen($notes)>500)throw new InvalidArgumentException('Payment reference or notes are too long.');
        $db->beginTransaction();
        $q=$db->prepare('SELECT i.id,i.total,i.paid_total,i.payment_status,o.status order_status FROM pos_invoices i JOIN orders o ON o.id=i.order_id AND o.restaurant_id=i.restaurant_id WHERE i.id=? AND i.restaurant_id=? FOR UPDATE');$q->execute([$invoiceId,$rid]);$invoice=$q->fetch();
        if(!$invoice)throw new InvalidArgumentException('Bill not found.');
        $q=$db->prepare('SELECT invoice_id,method,amount,amount_received,reference FROM pos_payments WHERE restaurant_id=? AND idempotency_key=?');$q->execute([$rid,$retryHash]);$previous=$q->fetch();
        if($previous){
            if((int)$previous['invoice_id']!==$invoiceId||$previous['method']!==$method||pos_cents($previous['amount'])!==$amount||($previous['reference']??'')!==$reference||($previous['amount_received']===null?null:pos_cents($previous['amount_received']))!==$received)throw new InvalidArgumentException('This payment submission key was already used for different details. Reload the bill before trying again.');
            $db->commit();json_out(['ok'=>true,'already_recorded'=>true,'paid_total'=>$invoice['paid_total'],'balance'=>pos_money(pos_cents($invoice['total'])-pos_cents($invoice['paid_total'])),'change'=>pos_money(($received??$amount)-$amount),'payment_status'=>$invoice['payment_status'],'order_status'=>$invoice['order_status']]);
        }
        if($invoice['payment_status']==='PAID')throw new InvalidArgumentException('This bill is already paid and locked.');
        $total=pos_cents($invoice['total']);$paid=pos_cents($invoice['paid_total']);
        if($amount>$total-$paid)throw new InvalidArgumentException('Payment exceeds the remaining balance.');
        $q=$db->prepare('INSERT INTO pos_payments(restaurant_id,invoice_id,method,amount,amount_received,reference,notes,idempotency_key,recorded_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
        $q->execute([$rid,$invoiceId,$method,pos_money($amount),$received!==null?pos_money($received):null,$reference?:null,$notes?:null,$retryHash,$userId]);
        $newPaid=$paid+$amount;$status=$newPaid===$total?'PAID':'PARTIALLY_PAID';
        $q=$db->prepare('UPDATE pos_invoices SET paid_total=?,payment_status=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$q->execute([pos_money($newPaid),$status,$invoiceId,$rid]);
        $db->commit();json_out(['ok'=>true,'paid_total'=>pos_money($newPaid),'balance'=>pos_money($total-$newPaid),'change'=>pos_money(($received??$amount)-$amount),'payment_status'=>$status,'order_status'=>$invoice['order_status']]);
    }
    if ($path === '/api/pos/payment/batch') {
        $invoiceId=(int)($_POST['invoice_id']??0);
        $retryKey=(string)($_POST['idempotency_key']??'');
        if(!preg_match('/^[a-zA-Z0-9-]{20,100}$/D',$retryKey))throw new InvalidArgumentException('Invalid payment submission key. Refresh the POS screen.');
        $entries=json_decode((string)($_POST['payments']??''),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($entries)||!array_is_list($entries)||count($entries)<2||count($entries)>3)throw new InvalidArgumentException('Choose two or three payment methods.');
        $payments=[];$sum=0;$change=0;
        foreach($entries as $index=>$entry){
            if(!is_array($entry))throw new InvalidArgumentException('Invalid payment details.');
            $method=(string)($entry['method']??'');
            if(!in_array($method,['CASH','CARD','BANK_TRANSFER','ONLINE_PAYMENT','OTHER'],true))throw new InvalidArgumentException('Invalid payment method.');
            $amount=pos_cents((string)($entry['amount']??''));
            if($amount<1)throw new InvalidArgumentException('Each payment amount must be greater than zero.');
            $received=$method==='CASH'?pos_cents((string)($entry['amount_received']??'')):null;
            if($received!==null&&$received<$amount)throw new InvalidArgumentException('Insufficient cash received.');
            $reference=trim((string)($entry['reference']??''));
            if(mb_strlen($reference)>120)throw new InvalidArgumentException('Payment reference is too long.');
            $hash=hash('sha256','PAYBATCH|'.$rid.'|'.$userId.'|'.$retryKey.'|'.$index);
            $payments[]=[$method,$amount,$received,$reference,$hash];$sum+=$amount;$change+=($received??$amount)-$amount;
        }
        $db->beginTransaction();
        $q=$db->prepare('SELECT i.id,i.total,i.paid_total,i.payment_status,o.status order_status FROM pos_invoices i JOIN orders o ON o.id=i.order_id AND o.restaurant_id=i.restaurant_id WHERE i.id=? AND i.restaurant_id=? FOR UPDATE');$q->execute([$invoiceId,$rid]);$invoice=$q->fetch();
        if(!$invoice)throw new InvalidArgumentException('Bill not found.');
        $hashes=array_column($payments,4);
        $q=$db->prepare('SELECT invoice_id,method,amount,amount_received,reference,idempotency_key FROM pos_payments WHERE restaurant_id=? AND idempotency_key IN ('.implode(',',array_fill(0,count($hashes),'?')).')');
        $q->execute([$rid,...$hashes]);$previous=[];foreach($q as $row)$previous[$row['idempotency_key']]=$row;
        if($previous){
            if(count($previous)!==count($payments))throw new InvalidArgumentException('This split payment is incomplete. Contact the manager before retrying.');
            foreach($payments as [$method,$amount,$received,$reference,$hash]){
                $row=$previous[$hash]??null;
                if(!$row||(int)$row['invoice_id']!==$invoiceId||$row['method']!==$method||pos_cents($row['amount'])!==$amount||($row['amount_received']===null?null:pos_cents($row['amount_received']))!==$received||($row['reference']??'')!==$reference)throw new InvalidArgumentException('This payment submission key was already used for different details.');
            }
            $db->commit();json_out(['ok'=>true,'already_recorded'=>true,'paid_total'=>$invoice['paid_total'],'balance'=>pos_money(pos_cents($invoice['total'])-pos_cents($invoice['paid_total'])),'change'=>pos_money($change),'payment_status'=>$invoice['payment_status'],'order_status'=>$invoice['order_status']]);
        }
        if($invoice['payment_status']==='PAID')throw new InvalidArgumentException('This bill is already paid and locked.');
        $total=pos_cents($invoice['total']);$paid=pos_cents($invoice['paid_total']);
        if($sum>$total-$paid)throw new InvalidArgumentException('Payments exceed the remaining balance.');
        $q=$db->prepare('INSERT INTO pos_payments(restaurant_id,invoice_id,method,amount,amount_received,reference,notes,idempotency_key,recorded_by,created_at) VALUES(?,?,?,?,?, ?,NULL,?,?,UTC_TIMESTAMP())');
        foreach($payments as [$method,$amount,$received,$reference,$hash])$q->execute([$rid,$invoiceId,$method,pos_money($amount),$received!==null?pos_money($received):null,$reference?:null,$hash,$userId]);
        $newPaid=$paid+$sum;$status=$newPaid===$total?'PAID':'PARTIALLY_PAID';
        $q=$db->prepare('UPDATE pos_invoices SET paid_total=?,payment_status=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$q->execute([pos_money($newPaid),$status,$invoiceId,$rid]);
        $db->commit();json_out(['ok'=>true,'paid_total'=>pos_money($newPaid),'balance'=>pos_money($total-$newPaid),'change'=>pos_money($change),'payment_status'=>$status,'order_status'=>$invoice['order_status']]);
    }
    if ($path === '/api/pos/bill/edit') {
        if (!in_array($staff['role'],['RESTAURANT_ADMIN','CASHIER'],true) || !(int)$staff['manager_bill_edit_enabled']) order_fail('Final bill editing is not enabled for this restaurant.',403);
        $invoiceId=(int)($_POST['invoice_id']??0);$action=(string)($_POST['action']??'');
        $reason=trim((string)($_POST['reason']??''));if(mb_strlen($reason)>500)throw new InvalidArgumentException('Reason is too long.');
        if(!in_array($action,['ITEM_ADDED','ITEM_REMOVED','QUANTITY_CHANGED','PRICE_CHANGED','DISCOUNT_CHANGED','SERVICE_CHARGE_CHANGED'],true))throw new InvalidArgumentException('Invalid bill edit action.');
        $db->beginTransaction();
        $q=$db->prepare('SELECT i.*,o.source,o.order_type,o.status order_status FROM pos_invoices i JOIN orders o ON o.id=i.order_id AND o.restaurant_id=i.restaurant_id WHERE i.id=? AND i.restaurant_id=? FOR UPDATE');$q->execute([$invoiceId,$rid]);$invoice=$q->fetch();
        if(!$invoice)throw new InvalidArgumentException('Bill not found.');
        if($invoice['payment_status']==='PAID')throw new InvalidArgumentException('Paid bills are locked.');
        if(in_array($invoice['order_status'],['REJECTED','CANCELLED','COMPLETED'],true))throw new InvalidArgumentException('Closed orders cannot be edited.');
        if($action==='SERVICE_CHARGE_CHANGED'&&$invoice['order_type']!=='DINE_IN')throw new InvalidArgumentException('Service charges do not apply to pickup or delivery orders.');
        $orderId=(int)$invoice['order_id'];
        $q=$db->prepare('SELECT id FROM orders WHERE id=? AND restaurant_id=? FOR UPDATE');$q->execute([$orderId,$rid]);if(!$q->fetchColumn())throw new InvalidArgumentException('Order not found.');
        $old=null;$new=null;$menuItemId=null;$kitchenChange=false;
        if($action==='ITEM_ADDED'){
            $menuItemId=(int)($_POST['menu_item_id']??0);$portion=$_POST['portion']??null;if($portion==='')$portion=null;
            $quantity=(int)($_POST['quantity']??0);if($quantity<1||$quantity>99)throw new InvalidArgumentException('Quantity must be between 1 and 99.');
            $q=$db->prepare('SELECT * FROM menu_items WHERE id=? AND restaurant_id=? FOR UPDATE');$q->execute([$menuItemId,$rid]);$menu=$q->fetch();if(!$menu)throw new InvalidArgumentException('Menu item not found.');
            $unit=pos_item_price($menu,$portion,$db,(int)$rid);$line=pos_money($unit*$quantity);
            $q=$db->prepare('INSERT INTO order_items(order_id,menu_item_id,item_name,portion,unit_price,quantity,subtotal) VALUES(?,?,?,?,?,?,?)');$q->execute([$orderId,$menuItemId,$menu['name'],$portion,pos_money($unit),$quantity,$line]);$orderItemId=(int)$db->lastInsertId();
            $q=$db->prepare('INSERT INTO pos_invoice_items(invoice_id,order_item_id,menu_item_id,item_name,portion,quantity,unit_price,line_total) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$invoiceId,$orderItemId,$menuItemId,$menu['name'],$portion,$quantity,pos_money($unit),$line]);
            $new=['name'=>$menu['name'],'portion'=>$portion,'quantity'=>$quantity,'unit_price'=>pos_money($unit)];$kitchenChange=true;
        } elseif(in_array($action,['ITEM_REMOVED','QUANTITY_CHANGED','PRICE_CHANGED'],true)) {
            $lineId=(int)($_POST['invoice_item_id']??0);
            $q=$db->prepare('SELECT * FROM pos_invoice_items WHERE id=? AND invoice_id=? FOR UPDATE');$q->execute([$lineId,$invoiceId]);$line=$q->fetch();if(!$line)throw new InvalidArgumentException('Bill item not found.');
            $menuItemId=(int)$line['menu_item_id'];$old=['name'=>$line['item_name'],'quantity'=>(int)$line['quantity'],'unit_price'=>$line['unit_price']];
            if($action==='ITEM_REMOVED'){
                if($line['order_item_id']){$q=$db->prepare('UPDATE order_items SET quantity=0,subtotal=0 WHERE id=? AND order_id=?');$q->execute([$line['order_item_id'],$orderId]);}
                $q=$db->prepare('DELETE FROM pos_invoice_items WHERE id=? AND invoice_id=?');$q->execute([$lineId,$invoiceId]);$new=null;$kitchenChange=true;
            } else {
                $quantity=$action==='QUANTITY_CHANGED'?(int)($_POST['quantity']??0):(int)$line['quantity'];
                if($quantity<1||$quantity>99)throw new InvalidArgumentException('Quantity must be between 1 and 99.');
                $unit=$action==='PRICE_CHANGED'?pos_cents((string)($_POST['unit_price']??'')):pos_cents($line['unit_price']);
                $lineTotal=pos_money($unit*$quantity);
                $q=$db->prepare('UPDATE pos_invoice_items SET quantity=?,unit_price=?,line_total=? WHERE id=? AND invoice_id=?');$q->execute([$quantity,pos_money($unit),$lineTotal,$lineId,$invoiceId]);
                if($line['order_item_id']){$q=$db->prepare('UPDATE order_items SET quantity=?,unit_price=?,subtotal=? WHERE id=? AND order_id=?');$q->execute([$quantity,pos_money($unit),$lineTotal,$line['order_item_id'],$orderId]);}
                $new=['name'=>$line['item_name'],'quantity'=>$quantity,'unit_price'=>pos_money($unit)];$kitchenChange=$action==='QUANTITY_CHANGED';
            }
        } else {
            $field=$action==='DISCOUNT_CHANGED'?'discount':'service_charge';
            $amount=pos_money(pos_cents((string)($_POST['amount']??'')));
            $old=[$field=>$invoice[$field]];$new=[$field=>$amount];
            $q=$db->prepare('UPDATE pos_invoices SET '.$field.'=? WHERE id=? AND restaurant_id=?');$q->execute([$amount,$invoiceId,$rid]);
        }
        $q=$db->prepare('SELECT COUNT(*) FROM pos_invoice_items WHERE invoice_id=?');$q->execute([$invoiceId]);
        if((int)$q->fetchColumn()<1)throw new InvalidArgumentException('A bill must keep at least one item.');
        $automaticService=null;
        if($invoice['order_type']!=='DINE_IN'&&pos_cents((string)$invoice['service_charge'])>0){
            $newService='0.00';$q=$db->prepare('UPDATE pos_invoices SET service_charge=? WHERE id=? AND restaurant_id=?');$q->execute([$newService,$invoiceId,$rid]);
            $automaticService=['old'=>$invoice['service_charge'],'new'=>$newService];
        } elseif(in_array($action,['ITEM_ADDED','ITEM_REMOVED','QUANTITY_CHANGED','PRICE_CHANGED'],true)
            && pos_cents((string)$invoice['service_charge'])===pos_percent_charge(pos_cents((string)$invoice['subtotal']),(string)$staff['service_charge_percent'])){
            $q=$db->prepare('SELECT line_total FROM pos_invoice_items WHERE invoice_id=?');$q->execute([$invoiceId]);$newSubtotal=0;
            foreach($q as $line)$newSubtotal+=pos_cents((string)$line['line_total']);
            $newService=pos_money(pos_percent_charge($newSubtotal,(string)$staff['service_charge_percent']));
            if($newService!==$invoice['service_charge']){
                $q=$db->prepare('UPDATE pos_invoices SET service_charge=? WHERE id=? AND restaurant_id=?');$q->execute([$newService,$invoiceId,$rid]);
                $automaticService=['old'=>$invoice['service_charge'],'new'=>$newService];
            }
        }
        $totals=pos_invoice_totals($db,$invoiceId,$rid);
        $q=$db->prepare('SELECT subtotal FROM order_items WHERE order_id=? AND quantity>0');$q->execute([$orderId]);$orderCents=pos_cents((string)$invoice['packaging_charge'])+pos_cents((string)$invoice['delivery_charge']);foreach($q as $item)$orderCents+=pos_cents($item['subtotal']);
        $q=$db->prepare('UPDATE orders SET total=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND restaurant_id=?');$q->execute([pos_money($orderCents),$orderId,$rid]);
        $kotId=null;
        if($kitchenChange){$q=$db->prepare('SELECT id FROM pos_kots WHERE restaurant_id=? AND order_id=? LIMIT 1');$q->execute([$rid,$orderId]);if($q->fetchColumn())$kotId=pos_send_kot($db,$orderId,$rid,$userId);}
        $q=$db->prepare('INSERT INTO pos_bill_edit_audit(restaurant_id,order_id,invoice_id,user_id,user_name,user_role,action_type,item_id,previous_value,new_value,reason,ip_address,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
        $q->execute([$rid,$orderId,$invoiceId,$userId,$staff['name'],$staff['role'],$action,$menuItemId?:null,json_encode($old,JSON_THROW_ON_ERROR),json_encode($new,JSON_THROW_ON_ERROR),$reason?:null,substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)?:null]);
        if($automaticService)$q->execute([$rid,$orderId,$invoiceId,$userId,$staff['name'],$staff['role'],'SERVICE_CHARGE_CHANGED',null,json_encode(['service_charge'=>$automaticService['old']],JSON_THROW_ON_ERROR),json_encode(['service_charge'=>$automaticService['new']],JSON_THROW_ON_ERROR),'Automatically recalculated from the restaurant service charge percentage.',substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)?:null]);
        $db->commit();json_out(['ok'=>true,'invoice'=>$totals,'kot_id'=>$kotId]);
    }
    order_fail('Unknown POS action.',404);
} catch (JsonException|InvalidArgumentException $error) {
    if($db->inTransaction())$db->rollBack();order_fail($error->getMessage());
} catch (Throwable $error) {
    if($db->inTransaction())$db->rollBack();error_log('POS action failed: '.$error->getMessage());order_fail('Unable to complete POS action. Please retry.',500);
}
