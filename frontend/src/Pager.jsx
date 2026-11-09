const cls =
  'rounded-lg bg-cream px-3 py-1.5 font-medium text-slate-600 transition hover:bg-brand-100 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-brand-800 dark:text-slate-300 dark:hover:bg-slate-600';

export default function Pager({ page, pages, onChange }) {
  if (pages <= 1) return null;
  return (
    <div className="mt-4 flex items-center justify-center gap-3 text-xs">
      <button type="button" className={cls} disabled={page <= 1} onClick={() => onChange(page - 1)}>
        Sebelumnya
      </button>
      <span className="text-slate-500 dark:text-slate-400">
        Halaman {page} dari {pages}
      </span>
      <button type="button" className={cls} disabled={page >= pages} onClick={() => onChange(page + 1)}>
        Berikutnya
      </button>
    </div>
  );
}
