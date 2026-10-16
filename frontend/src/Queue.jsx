import { mediaSrc } from './api.js';

const TYPE_LABEL = {
  convert: 'Konversi MP3',
  transcribe: 'Transkripsi',
  notulensi: 'Notulensi',
  pptx: 'Presentasi',
};

export function formatEta(seconds) {
  if (seconds == null) return null;
  if (seconds < 60) return `±${Math.max(1, seconds)} detik`;
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `±${minutes} menit`;
  return `±${(minutes / 60).toFixed(1)} jam`;
}

function downloadText(name, text) {
  const slug = String(name || 'hasil').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = `${slug || 'hasil'}.txt`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

export function ProgressBar({ value }) {
  const pct = Math.max(2, Math.min(100, Number(value) || 0));
  return (
    <div className="h-2 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-brand-700">
      <div
        className="h-full rounded-full bg-brand transition-all duration-500 dark:bg-skyblue"
        style={{ width: `${pct}%` }}
      />
    </div>
  );
}

const STATUS_STYLE = {
  queued: 'bg-slate-100 text-slate-600 dark:bg-brand-800 dark:text-slate-300',
  processing: 'bg-skyblue/30 text-brand-900 dark:bg-brand-700 dark:text-skyblue',
  done: 'bg-emerald-100 text-emerald-700 dark:bg-brand-700 dark:text-emerald-300',
  error: 'bg-blush/40 text-brand-900 dark:bg-brand-700 dark:text-red-300',
};

const STATUS_LABEL = {
  queued: 'Menunggu',
  processing: 'Diproses',
  done: 'Selesai',
  error: 'Gagal',
};

function StatusBadge({ status }) {
  return (
    <span className={`shrink-0 rounded-full px-2 py-0.5 text-[0.7rem] font-medium ${STATUS_STYLE[status] || STATUS_STYLE.queued}`}>
      {STATUS_LABEL[status] || status}
    </span>
  );
}

function TaskRow({ task, onCancel }) {
  const active = task.status === 'queued' || task.status === 'processing';
  const eta = formatEta(task.eta);
  return (
    <div className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-sm font-medium">{task.title}</p>
          <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            {TYPE_LABEL[task.type] || task.type}
            {task.status === 'processing' && ` · ${task.progress}%`}
            {eta && ` · selesai ${eta}`}
            {task.status === 'queued' && task.position >= 0 && ` · antrean ke-${task.position + 1}`}
          </p>
        </div>
        <StatusBadge status={task.status} />
      </div>

      {active && (
        <div className="mt-3">
          <ProgressBar value={task.progress} />
        </div>
      )}

      {task.status === 'error' && task.error && (
        <p className="mt-2 text-xs text-red-500 dark:text-red-400">{task.error}</p>
      )}

      {task.status === 'done' && (
        <div className="mt-2 flex flex-wrap items-center gap-3">
          {task.result?.text ? (
            <button
              type="button"
              onClick={() =>
                downloadText(task.title || task.type, task.result.text)
              }
              className="rounded-lg bg-brand px-3 py-1.5 text-xs font-medium text-white transition hover:opacity-90 dark:bg-skyblue dark:text-brand-900"
            >
              Unduh hasil
            </button>
          ) : task.result?.url ? (
            <a
              href={mediaSrc(task.result.url)}
              download
              className="rounded-lg bg-brand px-3 py-1.5 text-xs font-medium text-white transition hover:opacity-90 dark:bg-skyblue dark:text-brand-900"
            >
              Unduh hasil
            </a>
          ) : null}
          {task.result?.text && (
            <span className="min-w-0 flex-1 truncate text-xs text-slate-500 dark:text-slate-400">
              {task.result.text.slice(0, 160)}
            </span>
          )}
        </div>
      )}

      {task.status === 'queued' && onCancel && (
        <button
          type="button"
          onClick={() => onCancel(task.id)}
          className="mt-2 text-xs font-medium text-blush transition hover:opacity-70"
        >
          Batalkan
        </button>
      )}
      {(task.status === 'done' || task.status === 'error') && onCancel && (
        <button
          type="button"
          onClick={() => onCancel(task.id)}
          className="mt-2 text-xs font-medium text-slate-500 transition hover:opacity-70 dark:text-slate-400"
        >
          Hapus dari riwayat
        </button>
      )}
      {task.status === 'done' && task.duration != null && (
        <p className="mt-1 text-[0.7rem] text-slate-400 dark:text-slate-500">
          Waktu: {Math.floor(task.duration / 60) > 0 ? `${Math.floor(task.duration / 60)}m ` : ''}{task.duration % 60}s
        </p>
      )}
    </div>
  );
}

export default function QueuePage({ tasks = [], onCancel }) {
  const active = tasks.filter((t) => t.status === 'queued' || t.status === 'processing');
  const finished = tasks.filter((t) => t.status === 'done' || t.status === 'error');

  const card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700';

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      <h2 className="text-lg font-semibold">Antrean</h2>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        Proses latar belakang berjalan di antrean. Anda bisa berpindah menu; progres tetap terpantau di sini.
      </p>

      {active.length > 0 && (
        <>
          <h3 className="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">Berjalan</h3>
          <div className="mt-2 space-y-3">
            {active.map((t) => (
              <TaskRow key={t.id} task={t} onCancel={onCancel} />
            ))}
          </div>
        </>
      )}

      {active.length === 0 && (
        <div className={`mt-4 ${card} text-center text-sm text-slate-500 dark:text-slate-400`}>
          Tidak ada proses berjalan.
        </div>
      )}

      {finished.length > 0 && (
        <>
          <h3 className="mt-6 text-xs font-semibold uppercase tracking-wide text-slate-400">Riwayat</h3>
          <div className="mt-2 space-y-3">
            {finished.map((t) => (
              <TaskRow key={t.id} task={t} />
            ))}
          </div>
        </>
      )}
    </div>
  );
}
