(() => {
  const form = document.querySelector('#qr-logo-form');
  if (!form) return;
  const preview = document.querySelector('#qr-logo-preview');
  const viewer = document.createElement('dialog');
  viewer.className = 'qr-image-viewer';
  viewer.setAttribute('aria-label', 'Enlarged image preview');
  const close = document.createElement('button');
  close.type = 'button';
  close.textContent = '×';
  close.setAttribute('aria-label', 'Close image preview');
  const enlarged = document.createElement('img');
  viewer.append(close, enlarged);
  document.body.append(viewer);
  close.addEventListener('click', () => viewer.close());
  viewer.addEventListener('click', event => { if (event.target === viewer) viewer.close(); });
  document.querySelectorAll('.qr-card-previews img').forEach(image => {
    image.tabIndex = 0;
    image.setAttribute('role', 'button');
    image.setAttribute('aria-label', 'Enlarge ' + image.alt);
    image.title = 'Click to enlarge';
    const open = () => {
      if (image.hidden || !image.getAttribute('src')) return;
      enlarged.src = image.currentSrc || image.src;
      enlarged.alt = image.alt;
      viewer.showModal();
    };
    image.addEventListener('click', open);
    image.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
    });
  });
  const status = form.querySelector('[role="status"]');
  const button = form.querySelector('button[type="submit"],button:not([type])');
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!form.elements.logo.files.length) {
      await window.showAdminPopup('Choose a logo image first.', 'error');
      return;
    }
    button.disabled = true;
    status.textContent = 'Uploading logo…';
    const done = window.beginImageUpload('Uploading QR card logo…');
    try {
      const response = await fetch(base + '/api/admin/qr-logo', {method: 'POST', body: new FormData(form)});
      const result = await response.json();
      if (!response.ok || result.error) throw Error(result.error || 'Unable to save QR card logo.');
      done();
      preview.src = base + '/' + String(result.path).replace(/^\/+/, '') + '?v=' + Date.now();
      preview.hidden = false;
      form.reset();
      status.textContent = '';
      await window.showAdminPopup('Your logo will appear at the top-left of newly generated QR card PDFs.');
    } catch (error) {
      done();
      status.textContent = '';
      await window.showAdminPopup(error.message, 'error');
    } finally {
      done();
      button.disabled = false;
    }
  });
})();
