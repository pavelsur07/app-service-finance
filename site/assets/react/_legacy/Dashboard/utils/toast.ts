export function showToast(message: string): void {
  if (typeof window === 'undefined' || typeof document === 'undefined') {
    return;
  }

  let container = document.getElementById('dashboard-toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'dashboard-toast-container';
    container.className = 'toast-container position-fixed top-0 end-0 p-3';
    container.style.zIndex = '1080';
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = 'toast show';
  toast.setAttribute('role', 'alert');
  toast.innerHTML = `<div class="toast-body">${message}</div>`;
  container.appendChild(toast);

  window.setTimeout(() => {
    toast.remove();
  }, 2500);
}
