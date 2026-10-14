import { useEffect, useRef, useState } from 'react';
import { apiTranscribe, apiTranscribeStatus } from './api.js';

export default function Transcribe() {
  const [file, setFile] = useState(null);
  const [status, setStatus] = useState('idle'); // idle | pending | processing | done | error
  const [text, setText] = useState('');
  const [meta, setMeta] = useState(null);
  const [error, setError] = useState(null);
  const [copied, setCopied] = useState('');
  const [dragging, setDragging] = useState(false);
  const inputRef = useRef(null);
  const pollRef = useRef(null);

  const busy = status === 'pending' || status === 'processing';

  useEffect(() => () => clearInterval(pollRef.current), []);

  const pickFile = (f) => {
    if (!f) return;
    setFile(f);
    setError(null);
  };

  const run = async () => {
    if (!file) return;
    clearInterval(pollRef.current);
    setStatus('pending');
    setError(null);
    setText('');
    setMeta(null);
    try {
      const job = await apiTranscribe(file);
      setStatus(job.status || 'pending');
      pollRef.current = setInterval(async () => {
        try {
          const s = await apiTranscribeStatus(job.id);
          if (s.status === 'done') {
            clearInterval(pollRef.current);
            setStatus('done');
            setText(s.text || '');
            setMeta(s);
            try {
              if (s.text) localStorage.setItem('last-transcript', s.text);
            } catch {
              /* ignore */
            }
            if (!s.text) setError('Tidak ada teks yang terdeteksi dari audio ini.');
          } else if (s.status === 'error') {
            clearInterval(pollRef.current);
            setStatus('error');
            setError(s.error || 'Transkripsi gagal.');
          } else {
            setStatus(s.status);
          }
        } catch (e) {
          clearInterval(pollRef.current);
          setStatus('error');
          setError(e.message);
        }
      }, 3000);
    } catch (e) {
      setStatus('error');
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
        Unggah file audio (mp3, m4a, wav, ogg) untuk diubah menjadi teks.
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
        {busy && (
          <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
            Sedang ditranskripsi di server
            {status === 'pending' ? ' (menunggu antrean)' : ''}. Audio panjang bisa butuh beberapa menit.
          </p>
        )}
        {error && <p className="mt-3 text-sm text-red-500">{error}</p>}
      </div>

      {text && (
        <div className={`mt-4 ${card}`}>
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold">Hasil transkrip</h3>
            <div className="flex items-center gap-2 text-xs">
              {meta?.language && <span className="text-slate-400">[{meta.language}]</span>}
              <button type="button" onClick={() => copy(text, 'text')} className="rounded-lg px-2 py-1 font-medium text-brand transition hover:bg-cream dark:text-skyblue dark:hover:bg-brand-800">
                {copied === 'text' ? 'Tersalin' : 'Salin'}
              </button>
              <button type="button" onClick={download} className="rounded-lg px-2 py-1 font-medium text-brand transition hover:bg-cream dark:text-skyblue dark:hover:bg-brand-800">
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
