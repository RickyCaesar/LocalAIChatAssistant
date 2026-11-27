import { useEffect, useState } from 'react';
import { resolveConfirm } from './feedback.js';

export default function FeedbackHost() {
  const [confirm, setConfirm] = useState(null);
  const [success, setSuccess] = useState(null);

  useEffect(() => {
    const onConfirm = (e) => setConfirm(e.detail);
    const onSuccess = (e) => setSuccess(e.detail);
    window.addEventListener('app-confirm', onConfirm);
    window.addEventListener('app-success', onSuccess);
    return () => {
      window.removeEventListener('app-confirm', onConfirm);
      window.removeEventListener('app-success', onSuccess);
    };
  }, []);

  useEffect(() => {
    if (!confirm && !success) return;
    const onKey = (e) => {
      if (e.key !== 'Escape') return;
      if (confirm) {
        resolveConfirm(false);
        setConfirm(null);
      } else {
        setSuccess(null);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [confirm, success]);

  if (!confirm && !success) return null;

  const closeConfirm = (ok) => {
    resolveConfirm(ok);
    setConfirm(null);
  };

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4 text-slate-900 dark:text-slate-100" role="dialog" aria-modal="true">
      {confirm ? (
        <div className="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl dark:bg-brand-900">
          <div className={`flex h-11 w-11 items-center justify-center rounded-full ${confirm.danger ? 'bg-blush/20 text-blush' : 'bg-skyblue/20 text-brand dark:text-skyblue'}`}>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
              <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
              <path d="M12 9v4" />
              <path d="M12 17h.01" />
            </svg>
          </div>
          <h3 className="mt-3 text-base font-semibold">{confirm.title}</h3>
          <p className="mt-1 whitespace-pre-wrap text-sm text-slate-500 dark:text-slate-400">{confirm.message}</p>
          <div className="mt-5 flex justify-end gap-2">
            <button type="button" onClick={() => closeConfirm(false)} className="rounded-xl px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-brand-800">
              {confirm.cancelLabel}
            </button>
            <button type="button" autoFocus onClick={() => closeConfirm(true)} className={`rounded-xl px-3 py-2 text-sm font-medium text-white ${confirm.danger ? 'bg-blush hover:opacity-90' : 'bg-brand hover:bg-brand-600 dark:bg-white dark:text-slate-900'}`}>
              {confirm.confirmLabel}
            </button>
          </div>
        </div>
      ) : (
        <div className="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl dark:bg-brand-900">
          <div className={`flex h-11 w-11 items-center justify-center rounded-full ${success.danger ? 'bg-blush/20 text-blush' : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300'}`}>
            {success.danger ? (
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
                <path d="M12 9v4" />
                <path d="M12 17h.01" />
              </svg>
            ) : (
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                <path d="m5 12 4 4L19 6" />
              </svg>
            )}
          </div>
          <h3 className="mt-3 text-base font-semibold">{success.title}</h3>
          <p className="mt-1 whitespace-pre-wrap text-sm text-slate-500 dark:text-slate-400">{success.message}</p>
          <div className="mt-5 flex justify-end">
            <button type="button" autoFocus onClick={() => setSuccess(null)} className={`rounded-xl px-4 py-2 text-sm font-medium text-white ${success.danger ? 'bg-blush hover:opacity-90' : 'bg-brand hover:bg-brand-600 dark:bg-white dark:text-slate-900'}`}>
              Tutup
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
