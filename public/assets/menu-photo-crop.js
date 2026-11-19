// Crops a selected food photo locally; no image leaves the browser until Save.
window.cropFoodPhoto = file => new Promise((resolve, reject) => {
  const sourceUrl = URL.createObjectURL(file);
  const image = new Image();
  image.onerror = () => {URL.revokeObjectURL(sourceUrl); reject(Error('This image could not be opened. Choose a JPEG, PNG or WebP photo.'));};
  image.onload = () => {
    const dialog = document.createElement('dialog'); dialog.className = 'admin-photo-crop-dialog';
    dialog.innerHTML = '<div class="admin-photo-crop-head"><div><small>FOOD PHOTO</small><h2>Crop photo</h2><p>Drag the photo to position it inside the square.</p></div><button type="button" data-crop-close aria-label="Cancel photo crop">×</button></div><canvas width="320" height="320" aria-label="Square crop preview"></canvas><label class="admin-photo-crop-zoom">Zoom <input type="range" min="1" max="3" step="0.01" value="1"></label><p>Output: 400 × 400 px · use the original photo if no crop is needed.</p><div class="admin-photo-crop-actions"><button type="button" data-crop-original>Use original</button><button type="button" data-crop-apply>Crop & use</button></div>';
    document.body.append(dialog);
    const canvas = dialog.querySelector('canvas'), context = canvas.getContext('2d');
    const zoomInput = dialog.querySelector('input[type="range"]');
    const size = 320, baseScale = Math.max(size / image.naturalWidth, size / image.naturalHeight);
    let zoom = 1, drawnWidth = image.naturalWidth * baseScale, drawnHeight = image.naturalHeight * baseScale;
    let left = (size - drawnWidth) / 2, top = (size - drawnHeight) / 2, pointer = null;
    const clamp = () => {left = Math.min(0, Math.max(size - drawnWidth, left)); top = Math.min(0, Math.max(size - drawnHeight, top));};
    const draw = () => {context.clearRect(0, 0, size, size);context.drawImage(image, left, top, drawnWidth, drawnHeight);};
    zoomInput.addEventListener('input', () => {
      const previous = zoom; zoom = Number(zoomInput.value);
      left = size / 2 + (left - size / 2) * zoom / previous;
      top = size / 2 + (top - size / 2) * zoom / previous;
      drawnWidth = image.naturalWidth * baseScale * zoom;
      drawnHeight = image.naturalHeight * baseScale * zoom;
      clamp(); draw();
    });
    canvas.addEventListener('pointerdown', event => {pointer = {x:event.clientX,y:event.clientY};canvas.setPointerCapture(event.pointerId);});
    canvas.addEventListener('pointermove', event => {
      if (!pointer) return;
      const factor = size / canvas.getBoundingClientRect().width;
      left += (event.clientX - pointer.x) * factor; top += (event.clientY - pointer.y) * factor;
      pointer = {x:event.clientX,y:event.clientY}; clamp(); draw();
    });
    canvas.addEventListener('pointerup', () => {pointer = null;});
    canvas.addEventListener('pointercancel', () => {pointer = null;});
    let settled = false;
    const finish = result => {if (settled) return; settled = true; dialog.close(); dialog.remove(); URL.revokeObjectURL(sourceUrl); resolve(result);};
    dialog.addEventListener('cancel', event => {event.preventDefault(); finish({action:'cancel'});});
    dialog.querySelector('[data-crop-close]').onclick = () => finish({action:'cancel'});
    dialog.querySelector('[data-crop-original]').onclick = () => finish({action:'original'});
    dialog.querySelector('[data-crop-apply]').onclick = () => {
      const output = document.createElement('canvas'); output.width = 400; output.height = 400;
      const outputContext = output.getContext('2d');
      const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
      if (type === 'image/jpeg') {outputContext.fillStyle = '#fff'; outputContext.fillRect(0,0,400,400);}
      outputContext.drawImage(image,left * 1.25,top * 1.25,drawnWidth * 1.25,drawnHeight * 1.25);
      output.toBlob(blob => {
        if (!blob) {if (!settled) {settled = true;dialog.close();dialog.remove();URL.revokeObjectURL(sourceUrl);reject(Error('Photo crop failed. Please choose the image again.'));}return;}
        const extension = type === 'image/png' ? 'png' : 'jpg';
        const name = (file.name.replace(/\.[^.]+$/, '') || 'food-photo') + '-cropped.' + extension;
        finish({action:'crop', file:new File([blob],name,{type})});
      },type,type === 'image/jpeg' ? .9 : undefined);
    };
    draw(); dialog.showModal();
  };
  image.src = sourceUrl;
});
