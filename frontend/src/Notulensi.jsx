import { useEffect, useState } from 'react';
import { streamChat } from './api.js';

const PROMPT =
  'Kamu adalah notulis rapat profesional. Dari transkrip/catatan berikut, buat notulensi rapi dalam bahasa Indonesia dengan bagian: Ringkasan, Poin Penting, Keputusan, dan Action Items. Jangan mengarang di luar isi.\n\nISI:\n';

export default function Notulensi() {
  const [source, setSource] = useState('');
  const [minutes, setMinutes] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    // Prefill from the last transcription so Transkrip → Notulensi flows
    // without shared state. ponytail: localStorage ~5MB cap; a very long
    // transcript may not persist — paste manually if it exceeds that.
    try {
      const saved = localStorage.getItem('last-transcript');
      if (saved) setSource((s) => s || saved);
    } catch {
      /* ignore */
    }
  }, []);

  const run = async () => {
    if (!source.trim()) return;
    setBusy(true);
    setError(null);
    setMinutes('');
    try {
      const out = await streamChat({ message: PROMPT + source }, () => {});
      setMinutes(out || '');
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  };

  const copy = () => {
    if (!minutes) return;
    navigator.clipboard.writeText(minutes).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    });
  };

  const card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700';

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      <h2 className="text-lg font-semibold">Notulensi</h2>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        Tempel transkrip atau catatan, lalu susun notulensi rapi dengan AI.
      </p>

      <div className={`mt-4 ${card}`}>
        <textarea
          value={source}
          onChange={(e) => setSource(e.target.value)}
          rows={10}
          placeholder="Tempel transkrip / catatan rapat di sini..."
          className="w-full resize-y rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm leading-relaxed outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
        />
        <button
          type="button"
          onClick={run}
          disabled={!source.trim() || busy}
          className="mt-3 rounded-xl bg-blush px-4 py-2 text-sm font-medium text-brand-900 transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
        >
          {busy ? 'Menyusun notulensi...' : 'Buat notulensi (AI)'}
        </button>
        {error && <p className="mt-3 text-sm text-red-500">{error}</p>}
      </div>

      {minutes && (
        <div className={`mt-4 ${card}`}>
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold">Hasil notulensi</h3>
            <button
              type="button"
              onClick={copy}
              className="text-xs font-medium text-brand transition hover:opacity-70 dark:text-skyblue"
            >
              {copied ? 'Tersalin' : 'Salin'}
            </button>
          </div>
          <textarea
            value={minutes}
            onChange={(e) => setMinutes(e.target.value)}
            rows={14}
            className="mt-3 w-full resize-y rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm leading-relaxed outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
          />
        </div>
      )}
    </div>
  );
}
