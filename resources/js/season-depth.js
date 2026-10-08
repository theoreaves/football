export function mountSeasonDepth() {
 const form = document.querySelector('[data-depth-chart]');
 if (!form || form.dataset.mounted) return;
 form.dataset.mounted = 'true';
 const list = form.querySelector('[data-depth-list]'), status = form.querySelector('[data-depth-status]');
 let dragging;
 const refresh = () => {
  const rows = [...list.children];
  rows.forEach((row,i) => {
   row.querySelector('[data-depth-rank]').textContent = i === 0 ? '1 · Starter' : String(i+1);
   row.querySelector('[data-depth-up]').disabled = i === 0;
   row.querySelector('[data-depth-down]').disabled = i === rows.length-1;
  });
 };
 const changed = () => { refresh(); status.textContent = 'Order changed — save to keep it.'; };
 list.querySelectorAll('[data-depth-player]').forEach(row => {
  row.querySelector('[data-depth-up]').onclick = () => { if(row.previousElementSibling) {list.insertBefore(row,row.previousElementSibling);changed();} };
  row.querySelector('[data-depth-down]').onclick = () => { if(row.nextElementSibling) {list.insertBefore(row.nextElementSibling,row);changed();} };
  const handle = row.querySelector('[data-depth-handle]');
  handle.addEventListener('pointerdown', event => {
   if(event.button !== 0) return;
   dragging = row; handle.setPointerCapture(event.pointerId); row.classList.add('depth-dragging');
  });
  handle.addEventListener('pointermove', event => {
   if(dragging !== row) return;
   const target = document.elementFromPoint(event.clientX,event.clientY)?.closest('[data-depth-player]');
   if(target && target !== row && target.parentElement === list) {
    const bounds = target.getBoundingClientRect();
    list.insertBefore(row,event.clientY < bounds.top+bounds.height/2 ? target : target.nextSibling); changed();
   }
  });
  const end = () => { if(dragging === row) {row.classList.remove('depth-dragging');dragging=null;} };
  handle.addEventListener('pointerup',end);handle.addEventListener('pointercancel',end);handle.addEventListener('lostpointercapture',end);
 });
 refresh();
}
