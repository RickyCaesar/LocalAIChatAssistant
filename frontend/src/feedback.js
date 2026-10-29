let pendingConfirm = null;

export function confirmAction({ title = 'Konfirmasi', message = '', confirmLabel = 'Ya', cancelLabel = 'Batal', danger = true } = {}) {
  return new Promise((resolve) => {
    pendingConfirm?.(false);
    pendingConfirm = resolve;
    window.dispatchEvent(new CustomEvent('app-confirm', { detail: { title, message, confirmLabel, cancelLabel, danger } }));
  });
}

export function resolveConfirm(ok) {
  const done = pendingConfirm;
  pendingConfirm = null;
  done?.(ok);
}

export function showSuccess(message = 'Proses berhasil.', title = 'Berhasil') {
  window.dispatchEvent(new CustomEvent('app-success', { detail: { title, message } }));
}

export function showAlert(message = 'Terjadi masalah.', title = 'Peringatan') {
  window.dispatchEvent(new CustomEvent('app-success', { detail: { title, message, danger: true } }));
}
