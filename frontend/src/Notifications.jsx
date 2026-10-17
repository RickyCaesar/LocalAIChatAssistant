import { useState } from 'react';

// Browser push (OS) hanya untuk item BARU — dipanggil App.jsx saat polling
// menemukan notifikasi yang belum pernah ditampilkan.
export function browserNotify(n) {
  try {
    if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return;
    const os = new Notification(n.title, { body: n.message, tag: `notif-${n.id}` });
    os.onclick = () => window.focus();
  } catch {
    /* sebagian browser butuh service worker; abaikan */
  }
}

// Chime singkat via WebAudio — tanpa berkas audio. ponytail: satu nada tetap,
// ganti ke berkas/sintesis kalau ingin suara berbeda per tipe.
export function chime() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.value = 880;
    gain.gain.value = 0.06;
    osc.connect(gain).connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.18);
    setTimeout(() => ctx.close().catch(() => {}), 500);
  } catch {
    /* audio butuh gesture pertama; abaikan */
  }
}

const TYPE_DOT = {
  success: 'bg-emerald-500',
  error: 'bg-blush',
  info: 'bg-skyblue',
};

function timeAgo(iso) {
  const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return 'baru saja';
  if (s < 3600) return `${Math.floor(s / 60)} mnt lalu`;
  if (s < 86400) return `${Math.floor(s / 3600)} jam lalu`;
  return `${Math.floor(s / 86400)} hr lalu`;
}

function BellIcon() {
  return (
    <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
      <path d="M13.7 21a2 2 0 0 1-3.4 0" />
    </svg>
  );
}

function PermissionRow() {
  const supported = typeof Notification !== 'undefined';
  const [perm, setPerm] = useState(supported ? Notification.permission : 'unsupported');

  if (!supported || perm === 'granted') return null;

  if (perm === 'denied') {
    return (
      <p className="border-b border-slate-100 px-3 py-2 text-[0.7rem] text-slate-400 dark:border-slate-700 dark:text-slate-500">
        Notifikasi browser diblokir di pengaturan situs.
      </p>
    );
  }

  return (
    <button
      type="button"
      onClick={() => Notification.requestPermission().then(setPerm).catch(() => {})}
      className="w-full border-b border-slate-100 px-3 py-2 text-left text-xs font-medium text-brand transition hover:bg-cream dark:border-slate-700 dark:text-skyblue dark:hover:bg-brand-800"
    >
      Aktifkan notifikasi browser
    </button>
  );
}

export function NotificationBell({ items, unread, onRead, onReadAll, onOpen }) {
  const [open, setOpen] = useState(false);

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-label="Notifikasi"
        title="Notifikasi"
        className="relative rounded-lg p-2 text-slate-600 transition hover:bg-cream dark:text-slate-300 dark:hover:bg-brand-800"
      >
        <BellIcon />
        {unread > 0 && (
          <span className="absolute right-0.5 top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-blush px-1 text-[0.6rem] font-semibold text-white">
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-20" onClick={() => setOpen(false)} />
          <div className="absolute right-0 z-30 mt-2 w-80 overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
            <div className="flex items-center justify-between border-b border-slate-100 px-3 py-2 dark:border-slate-700">
              <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">Notifikasi</span>
              {unread > 0 && (
                <button
                  type="button"
                  onClick={onReadAll}
                  className="text-[0.7rem] font-medium text-brand transition hover:opacity-70 dark:text-skyblue"
                >
                  Tandai semua dibaca
                </button>
              )}
            </div>

            <PermissionRow />

            <div className="max-h-80 overflow-y-auto">
              {items.length === 0 && (
                <p className="px-3 py-6 text-center text-xs text-slate-400 dark:text-slate-500">
                  Belum ada notifikasi.
                </p>
              )}
              {items.map((n) => (
                <button
                  key={n.id}
                  type="button"
                  onClick={() => {
                    onRead(n);
                    onOpen(n);
                    setOpen(false);
                  }}
                  className={`flex w-full items-start gap-2 border-b border-slate-50 px-3 py-2.5 text-left transition last:border-0 hover:bg-cream dark:border-slate-800 dark:hover:bg-brand-800 ${
                    n.is_read ? 'opacity-60' : ''
                  }`}
                >
                  <span className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${TYPE_DOT[n.type] || TYPE_DOT.info}`} />
                  <span className="min-w-0 flex-1">
                    <span className={`block truncate text-xs ${n.is_read ? '' : 'font-semibold'}`}>{n.title}</span>
                    <span className="mt-0.5 block truncate text-[0.7rem] text-slate-500 dark:text-slate-400">{n.message}</span>
                    <span className="mt-0.5 block text-[0.65rem] text-slate-400 dark:text-slate-500">
                      {timeAgo(n.created_at)}
                    </span>
                  </span>
                </button>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  );
}

export function ToastContainer({ toasts, onClose, onOpen }) {
  if (toasts.length === 0) return null;

  return (
    <div className="pointer-events-none fixed right-4 top-4 z-50 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2">
      {toasts.map((t) => (
        <div
          key={t.key}
          onClick={() => {
            onOpen(t);
            onClose(t.key);
          }}
          className="pointer-events-auto flex cursor-pointer items-start gap-2 rounded-xl bg-white/95 p-3 shadow-lg ring-1 ring-slate-200 backdrop-blur transition hover:bg-white dark:bg-brand-800/95 dark:ring-slate-700 dark:hover:bg-brand-800"
        >
          <span className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${TYPE_DOT[t.type] || TYPE_DOT.info}`} />
          <span className="min-w-0 flex-1">
            <span className="block truncate text-sm font-medium">{t.title}</span>
            <span className="mt-0.5 block truncate text-xs text-slate-500 dark:text-slate-400">{t.message}</span>
          </span>
          <button
            type="button"
            aria-label="Tutup"
            onClick={(e) => {
              e.stopPropagation();
              onClose(t.key);
            }}
            className="shrink-0 rounded-md px-1 text-slate-400 transition hover:text-slate-600 dark:hover:text-slate-200"
          >
            ×
          </button>
        </div>
      ))}
    </div>
  );
}
