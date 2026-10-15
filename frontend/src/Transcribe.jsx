import { useEffect, useRef, useState } from 'react';
import { apiQueueTranscribe } from './api.js';
import { ProgressBar, formatEta } from './Queue.jsx';

const STORE_KEY = 'task-transcribe';

export default function Transcribe({ queue = [], refreshQueue }) {
  const [file, setFile] = useState(null);
  const [taskId, setTaskId] = useState(() => localStorage.getItem(STORE_KEY));
  const [text, setText] = useState('');
  const [error, setError] = useState(null);
  const [copied, setCopied] = useState('');
  const [dragging, setDragging] = useState(false);
  const inputRef = useRef(null);

  const task = queue.find((t) => t.id === taskId) || null;
  const busy = Boolean(task && (task.status === 'queued' || task.status === 'processing'));
  const result = task?.result || null;

  useEffect(() => {
    if (task?.status === 'done' && result) {
      setText((prev) => prev || result.text || '');
      try {
        if (result.text) localStorage.setItem('last-transcript', result.text);
      } catch {
        /* ignore */
      }
    } else if (task?.status === 'error') {
      setError(task.error || 'Transkripsi gagal.');
    }
  }, [task?.status, task?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const pickFile = (f) => {
    if (!f) return;
    setFile(f);
    setError(null);
  };

  const run = async () => {
    if (!file) return;
    setError(null);
    setText('');
    try {
      const job = await apiQueueTranscribe(file);
      setTaskId(job.id);
      localStorage.setItem(STORE_KEY, job.id);
      refreshQueue?.();
    } catch (e) {
      setError(e.message);
    }
  };

  const copy = (value, tag) => {
    if (!value) return;
    navigator.clipboard.writeText(value).then(() => {
      setCopied(tag);
      setTimeout(() => setCopied(''), 1500);
    });
  };

  const download = () => {
    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = (file?.name?.replace(/\.[^.]+$/, '') || 'transkrip') + '.txt';
    a.click();
    URL.revokeObjectURL(url);
  };

  const card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700';

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      <h2 className="text-lg font-semibold">Transkrip Audio</h2>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        Unggah file audio (mp3, m4a, wav, ogg) untuk diubah menjadi teks. Proses berjalan di antrean.
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
          accept="audio/*,.mp3,.m4a,.wav,.ogg,.aac,.flac"
          className="hidden"
          onChange={(e) => pickFile(e.target.files?.[0])}
        />
        <div className="flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={() => inputRef.current?.click()}
            className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium transition hover:bg-cream dark:border-slate-700 dark:hover:bg-brand-800"
          >
            Pilih file audio
          </button>
          <span className="min-w-0 flex-1 truncate text-sm text-slate-500 dark:text-slate-400">
            {file ? file.name : 'atau seret & lepas file audio ke sini'}
          </span>
          <button
            type="button"
            onClick={run}
            disabled={!file || busy}
            className="rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
          >
            {busy ? 'Memproses...' : 'Transkrip'}
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
                : `Transkripsi berjalan · ${task.progress}%`}
            </span>
            {task.eta != null && <span>sisa {formatEta(task.eta)}</span>}
          </div>
          <div className="mt-2">
            <ProgressBar value={task.progress} />
          </div>
        </div>
      )}

      {(text || result) && (
        <div className={`mt-4 ${card}`}>
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold">Hasil transkripsi</h3>
            <div className="flex items-center gap-3">
              {result?.language && <span className="text-xs text-slate-400">{result.language}</span>}
              <button
                type="button"
                onClick={() => copy(text, 'all')}
                className="text-xs font-medium text-brand transition hover:opacity-70 dark:text-skyblue"
              >
                {copied ? 'Tersalin' : 'Salin'}
              </button>
              <button
                type="button"
                onClick={download}
                className="text-xs font-medium text-brand transition hover:opacity-70 dark:text-skyblue"
              >
                Unduh .txt
              </button>
            </div>
          </div>
          <textarea
            value={text}
            onChange={(e) => setText(e.target.value)}
            rows={12}
            className="mt-3 w-full resize-y rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm leading-relaxed outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
          />
        </div>
      )}
    </div>
  );
}
