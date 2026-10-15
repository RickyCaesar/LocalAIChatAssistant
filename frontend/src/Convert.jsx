import { useEffect, useRef, useState } from 'react';
import { apiQueueConvert, mediaSrc } from './api.js';
import { ProgressBar, formatEta } from './Queue.jsx';

const STORE_KEY = 'task-convert';

export default function Convert({ queue = [], refreshQueue }) {
  const [file, setFile] = useState(null);
  const [taskId, setTaskId] = useState(() => localStorage.getItem(STORE_KEY));
  const [error, setError] = useState(null);
  const [dragging, setDragging] = useState(false);
  const inputRef = useRef(null);

  const task = queue.find((t) => t.id === taskId) || null;
  const busy = Boolean(task && (task.status === 'queued' || task.status === 'processing'));
  const result = task?.result || null;

  useEffect(() => {
    if (task?.status === 'error') setError(task.error || 'Konversi gagal.');
  }, [task?.status, task?.id]);

  const pickFile = (f) => {
    if (!f) return;
    setFile(f);
    setError(null);
  };

  const run = async () => {
    if (!file) return;
    setError(null);
    try {
      const job = await apiQueueConvert(file);
      setTaskId(job.id);
      localStorage.setItem(STORE_KEY, job.id);
      refreshQueue?.();
    } catch (e) {
      setError(e.message);
    }
  };

  const card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700';

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      <h2 className="text-lg font-semibold">Konversi Video ke MP3</h2>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        Unggah file video (mp4, mkv, mov, webm) untuk mengekstrak audionya menjadi MP3.
      </p>

      <div
        className={`mt-4 ${card} transition ${dragging ? 'ring-2 ring-brand dark:ring-skyblue' : ''}`}
        onDragOver={(e) => {
          e.preventDefault();
          setDragging(true);
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDragging(false);
          pickFile(e.dataTransfer.files?.[0]);
        }}
      >
        <input
          ref={inputRef}
          type="file"
          accept="video/*,.mp4,.mkv,.mov,.webm,.avi,.m4v"
          className="hidden"
          onChange={(e) => pickFile(e.target.files?.[0])}
        />
        <div className="flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={() => inputRef.current?.click()}
            className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium transition hover:bg-cream dark:border-slate-700 dark:hover:bg-brand-800"
          >
            Pilih file video
          </button>
          <span className="min-w-0 flex-1 truncate text-sm text-slate-500 dark:text-slate-400">
            {file ? file.name : 'atau seret & lepas file video ke sini'}
          </span>
          <button
            type="button"
            onClick={run}
            disabled={!file || busy}
            className="rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
          >
            {busy ? 'Memproses...' : 'Konversi ke MP3'}
          </button>
        </div>
        {error && <p className="mt-3 text-sm text-red-500">{error}</p>}
      </div>

      {busy && (
        <div className={`mt-4 ${card}`}>
          <div className="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
            <span>
              {task.status === 'queued'
                ? `Menunggu antrean${task.position >= 0 ? ` (ke-${task.position + 1})` : ''}...`
                : `Mengekstrak audio · ${task.progress}%`}
            </span>
            {task.eta != null && <span>sisa {formatEta(task.eta)}</span>}
          </div>
          <div className="mt-2">
            <ProgressBar value={task.progress} />
          </div>
        </div>
      )}

      {task?.status === 'done' && result && (
        <div className={`mt-4 ${card}`}>
          <div className="flex items-center justify-between gap-3">
            <h3 className="min-w-0 truncate text-sm font-semibold">{result.filename}</h3>
            <a
              href={`${mediaSrc(result.url)}&download=1`}
              download={result.filename}
              className="shrink-0 rounded-lg bg-brand px-3 py-1.5 text-xs font-medium text-white transition hover:bg-brand-600 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
            >
              Unduh MP3
            </a>
          </div>
          <audio controls src={mediaSrc(result.url)} className="mt-3 w-full" />
        </div>
      )}
    </div>
  );
}
