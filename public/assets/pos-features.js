document.querySelectorAll('[data-pos-feature]').forEach(form => {
  const pos = form.elements.pos_enabled;
  const edit = form.elements.manager_bill_edit_enabled;
  const status = form.querySelector('[role="status"]');
  const button = form.querySelector('button[type="submit"]');
  const sync = () => {
    edit.disabled = pos.value !== '1';
    if (edit.disabled) edit.value = '0';
  };
  pos.addEventListener('change', sync);
  sync();
  form.addEventListener('submit', async event => {
    event.preventDefault();
    button.disabled = true;
    status.textContent = 'Saving…';
    try {
      const response = await fetch(base + '/api/owner/pos-features', {method: 'POST', body: new FormData(form)});
      const result = await response.json();
      if (!response.ok || result.error) throw Error(result.error || 'Unable to save settings.');
      status.textContent = 'Features saved.';
    } catch (error) {
      status.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });
});
document.addEventListener('DOMContentLoaded', () => {
  const button = document.getElementById('owner-pos-audit-load');
  if (!button) return;
  button.addEventListener('click', async () => {
    const restaurantId = document.getElementById('owner-pos-audit-restaurant').value;
    const results = document.getElementById('owner-pos-audit-results');
    results.replaceChildren();
    if (!restaurantId) {results.textContent = 'Select a restaurant first.'; return;}
    button.disabled = true;
    results.textContent = 'Loading bill edits…';
    try {
      const response = await fetch(base + '/api/owner/pos-audit?restaurant_id=' + encodeURIComponent(restaurantId));
      const data = await response.json();
      if (!response.ok || data.error) throw Error(data.error || 'Unable to load audit history.');
      results.replaceChildren();
      if (!data.edits.length) {results.textContent = 'No manager bill edits for this restaurant.'; return;}
      const wrap = document.createElement('div'); wrap.style.overflowX = 'auto';
      const table = document.createElement('table'); table.className = 'table';
      const header = document.createElement('tr');
      for (const title of ['Date', 'Invoice / order', 'Manager', 'Action', 'Previous', 'New', 'Reason']) {const th = document.createElement('th'); th.textContent = title; header.append(th);}
      table.append(header);
      for (const edit of data.edits) {
        const row = document.createElement('tr');
        for (const value of [edit.created_at, edit.invoice_number + ' / ' + edit.order_number, edit.user_name, edit.action_type, edit.previous_value, edit.new_value, edit.reason || '—']) {
          const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
        }
        table.append(row);
      }
      wrap.append(table); results.append(wrap);
    } catch (error) {results.textContent = error.message;} finally {button.disabled = false;}
  });
});
