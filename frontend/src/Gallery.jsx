import { useEffect, useState } from 'react';
import { api, mediaSrc } from './api.js';

const fmtDate = (iso) =>
  iso
    ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
    : '—';

const fmtSize = (bytes) => {
  if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MB`;
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${bytes} B`;
};

const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();

const relDate = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  const days = Math.round((startOfDay(new Date()) - startOfDay(d)) / 86400000);
  const time = d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
  if (days === 0) return `Hari ini, ${time}`;
  if (days === 1) return `Kemarin, ${time}`;
  if (days > 1 && days < 7) return `${d.toLocaleDateString('id-ID', { weekday: 'long' })}, ${time}`;
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
};

const isImage = (m) => m.mime && m.mime.startsWith('image/');
const isPdf = (m) => m.mime === 'application/pdf';
const isAudio = (m) => m.mime && m.mime.startsWith('audio/');
const isVideo = (m) => m.mime && m.mime.startsWith('video/');

const downloadSrc = (url) => `${mediaSrc(url)}&download=1`;

const extOf = (m) => {
  const parts = (m.filename || '').split('.');
  if (parts.length > 1 && parts.at(-1)) return parts.pop().toUpperCase();
  const sub = (m.mime || '').split('/');
  return sub.length > 1 && sub[1] ? sub[1].toUpperCase() : 'FILE';
};

const extClass = (ext) => {
  if (ext === 'PDF') return 'bg-blush text-brand-900';
  if (ext === 'PPT' || ext === 'PPTX') return 'bg-brand text-white';
  if (ext === 'XLS' || ext === 'XLSX' || ext === 'CSV') return 'bg-brand-600 text-white';
  if (ext === 'DOC' || ext === 'DOCX' || ext === 'TXT' || ext === 'RTF') return 'bg-skyblue text-brand-900';
  return 'bg-slate-200 text-slate-700 dark:bg-brand-800 dark:text-cream';
};

const categorize = (m) => {
  if (isImage(m)) return 'image';
  if (isPdf(m)) return 'pdf';
  if (isAudio(m)) return 'audio';
  if (isVideo(m)) return 'video';
  return 'doc';
};

function FileIcon({ className = 'h-8 w-8' }) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className}>
      <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
      <polyline points="14 2 14 8 20 8" />
    </svg>
  );
}

export default function Gallery() {
  const [items, setItems] = useState([]);
  const [filter, setFilter] = useState('all');
  const [type, setType] = useState('all');
  const [q, setQ] = useState('');
  const [view, setView] = useState(() => localStorage.getItem('gallery-view') || 'grid');
  const [selected, setSelected] = useState(null);
  const [converting, setConverting] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const setViewMode = (v) => {
    setView(v);
    localStorage.setItem('gallery-view', v);
  };

  const load = () => {
    setError(null);
    setLoading(true);
    api('/api/media')
      .then((data) => setItems(data.media))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  const remove = async (id) => {
    if (!window.confirm('Hapus media ini?')) return;
    try {
      await api(`/api/media/${id}`, { method: 'DELETE' });
      setItems((prev) => prev.filter((m) => m.id !== id));
      setSelected((cur) => (cur && cur.id === id ? null : cur));
    } catch (e) {
      setError(e.message);
    }
  };

  const toPdf = async () => {
    if (!selected) return;
    setConverting(true);
    setError(null);
    try {
      const made = await api(`/api/media/${selected.id}/pdf`, { method: 'POST' });
      setItems((prev) => [made, ...prev]);
      setSelected(made);
    } catch (e) {
      setError(e.message);
    } finally {
      setConverting(false);
    }
  };

  const tabs = [
    { key: 'all', label: 'Semua' },
    { key: 'user', label: 'Dikirim' },
    { key: 'assistant', label: 'Diolah AI' },
  ];

  const typeOptions = [
    { key: 'all', label: 'Semua tipe' },
    { key: 'image', label: 'Gambar' },
    { key: 'pdf', label: 'PDF' },
    { key: 'doc', label: 'Dokumen' },
    { key: 'audio', label: 'Audio' },
    { key: 'video', label: 'Video' },
  ];

  const query = q.trim().toLowerCase();
  const visible = items.filter((m) => {
    if (filter !== 'all' && m.role !== filter) return false;
    if (type !== 'all' && categorize(m) !== type) return false;
    if (query && !`${m.filename || ''} ${m.session_title || ''}`.toLowerCase().includes(query)) return false;
    return true;
  });

  return (
    <div className="mx-auto max-w-5xl px-4 py-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Gallery</h2>
          <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            Media yang dikirim dan dihasilkan dalam percakapan.
          </p>
        </div>
        <div className="flex gap-1 rounded-xl bg-cream p-1 dark:bg-brand-900">
          {tabs.map((t) => (
            <button
              key={t.key}
              type="button"
              onClick={() => setFilter(t.key)}
              className={`rounded-lg px-3 py-1.5 text-xs font-medium transition ${
                filter === t.key
                  ? 'bg-white text-slate-900 shadow-sm dark:bg-brand-800 dark:text-white'
                  : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'
              }`}
            >
              {t.label}
            </button>
          ))}
        </div>
      </div>

      {error && <p className="mt-4 text-sm text-red-500">{error}</p>}

      <div className="mt-4 flex flex-wrap items-center gap-2">
        <div className="relative min-w-[12rem] flex-1">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400">
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.3-4.3" />
          </svg>
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Cari nama file atau percakapan..."
            className="w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-3 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
          />
        </div>
        <select
          value={type}
          onChange={(e) => setType(e.target.value)}
          className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none dark:border-slate-700 dark:bg-brand-800"
        >
          {typeOptions.map((t) => (
            <option key={t.key} value={t.key}>{t.label}</option>
          ))}
        </select>
        <div className="flex gap-1 rounded-xl bg-cream p-1 dark:bg-brand-900">
          <button
            type="button"
            aria-label="Tampilan grid"
            onClick={() => setViewMode('grid')}
            className={`rounded-lg p-1.5 transition ${
              view === 'grid' ? 'bg-white text-slate-900 shadow-sm dark:bg-brand-800 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'
            }`}
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
              <rect x="3" y="3" width="7" height="7" rx="1" />
              <rect x="14" y="3" width="7" height="7" rx="1" />
              <rect x="3" y="14" width="7" height="7" rx="1" />
              <rect x="14" y="14" width="7" height="7" rx="1" />
            </svg>
          </button>
          <button
            type="button"
            aria-label="Tampilan compact"
            onClick={() => setViewMode('compact')}
            className={`rounded-lg p-1.5 transition ${
              view === 'compact' ? 'bg-white text-slate-900 shadow-sm dark:bg-brand-800 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'
            }`}
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
              <path d="M8 6h13" />
              <path d="M8 12h13" />
              <path d="M8 18h13" />
              <path d="M3 6h.01" />
              <path d="M3 12h.01" />
              <path d="M3 18h.01" />
            </svg>
          </button>
        </div>
      </div>

      {loading ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">Loading media...</p>
      ) : visible.length === 0 ? (
        <p className="mt-16 text-center text-sm text-slate-400 dark:text-slate-500">
          {items.length === 0 ? 'Belum ada media. Kirim gambar di tab Chat untuk mulai.' : 'Tidak ada media yang cocok dengan pencarian/filter.'}
        </p>
      ) : view === 'grid' ? (
        <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {visible.map((m) => (
            <button
              key={m.id}
              type="button"
              onClick={() => setSelected(m)}
              className="group overflow-hidden rounded-2xl bg-white text-left shadow-sm ring-1 ring-slate-200 transition hover:shadow-md dark:bg-brand-900 dark:ring-slate-700"
            >
              <div className="relative">
                {isImage(m) ? (
                  <img
                    src={mediaSrc(m.url)}
                    alt={m.filename}
                    loading="lazy"
                    className="aspect-square w-full object-cover"
                  />
                ) : (
                  <div className="flex aspect-square w-full flex-col items-center justify-center gap-2 bg-cream text-slate-400 dark:bg-brand-800">
                    <span className={`rounded-lg px-2.5 py-1 text-[10px] font-bold uppercase tracking-widest ${extClass(extOf(m))}`}>
                      {extOf(m)}
                    </span>
                    <span className="max-w-[80%] truncate px-2 text-[11px] text-slate-500 dark:text-slate-300">
                      {m.filename}
                    </span>
                  </div>
                )}
                <span
                  className={`absolute left-2 top-2 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${
                    m.role === 'assistant'
                      ? 'bg-emerald-500/90 text-white'
                      : 'bg-brand/80 text-white'
                  }`}
                >
                  {m.role === 'assistant' ? 'AI' : 'Kirim'}
                </span>
              </div>
              <div className="p-2.5">
                <p className="truncate text-xs font-medium" title={m.filename}>{m.filename}</p>
                <div className="mt-1 flex items-center gap-1.5 text-[10px] text-slate-400 dark:text-slate-500">
                  <span className={`rounded px-1 py-0.5 font-bold uppercase tracking-wide ${extClass(extOf(m))}`}>{extOf(m)}</span>
                  <span>{fmtSize(m.size)}</span>
                </div>
                <p className="mt-1 truncate text-[11px] text-slate-400 dark:text-slate-500" title={m.session_title || ''}>
                  {relDate(m.created_at)}
                  {m.session_title ? ` · ${m.session_title}` : ''}
                </p>
              </div>
            </button>
          ))}
        </div>
      ) : (
        <div className="mt-4 divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:divide-slate-800 dark:bg-brand-900 dark:ring-slate-700">
          {visible.map((m) => (
            <button
              key={m.id}
              type="button"
              onClick={() => setSelected(m)}
              className="flex w-full items-center gap-3 px-3 py-2 text-left transition hover:bg-cream dark:hover:bg-brand-800"
            >
              <div className="relative flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-cream dark:bg-brand-800">
                {isImage(m) ? (
                  <img src={mediaSrc(m.url)} alt={m.filename} loading="lazy" className="h-full w-full object-cover" />
                ) : (
                  <span className={`rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ${extClass(extOf(m))}`}>{extOf(m)}</span>
                )}
              </div>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium" title={m.filename}>{m.filename}</p>
                <p className="truncate text-[11px] text-slate-400 dark:text-slate-500">
                  {fmtSize(m.size)} · {relDate(m.created_at)}
                  {m.session_title ? ` · ${m.session_title}` : ''}
                </p>
              </div>
              <span className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${
                m.role === 'assistant' ? 'bg-emerald-500/90 text-white' : 'bg-brand/80 text-white'
              }`}>
                {m.role === 'assistant' ? 'AI' : 'Kirim'}
              </span>
            </button>
          ))}
        </div>
      )}

      {selected && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
          onClick={() => setSelected(null)}
        >
          <div
            className="max-w-2xl w-full overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-brand-900"
            onClick={(e) => e.stopPropagation()}
          >
            {isImage(selected) ? (
              <img src={mediaSrc(selected.url)} alt={selected.filename} className="max-h-[60vh] w-full object-contain" />
            ) : isPdf(selected) ? (
              <iframe
                title={selected.filename}
                src={mediaSrc(selected.url)}
                className="h-[60vh] w-full border-0 bg-white"
              />
            ) : isAudio(selected) ? (
              <div className="flex flex-col items-center gap-3 bg-cream p-8 dark:bg-brand-800">
                <FileIcon className="h-12 w-12 text-slate-400" />
                <audio src={mediaSrc(selected.url)} controls className="w-full" />
              </div>
            ) : isVideo(selected) ? (
              <video src={mediaSrc(selected.url)} controls className="max-h-[60vh] w-full bg-black" />
            ) : selected.preview ? (
              <div className="max-h-[60vh] overflow-auto bg-cream p-4 dark:bg-brand-800">
                <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                  Pratinjau isi
                </p>
                <pre className="whitespace-pre-wrap break-words font-sans text-sm text-slate-700 dark:text-slate-200">
                  {selected.preview}
                </pre>
              </div>
            ) : (
              <div className="flex max-h-[60vh] flex-col items-center justify-center gap-3 bg-cream p-8 text-slate-500 dark:bg-brand-800 dark:text-slate-300">
                <span className={`rounded-lg px-3 py-1 text-xs font-bold uppercase tracking-widest ${extClass(extOf(selected))}`}>
                  {extOf(selected)}
                </span>
                <FileIcon className="h-14 w-14" />
                <p className="truncate text-sm">{selected.filename}</p>
                <p className="text-xs">Pratinjau tidak tersedia</p>
              </div>
            )}
            <div className="flex flex-wrap items-end justify-between gap-3 border-t border-slate-200 p-4 dark:border-slate-700">
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold" title={selected.filename}>{selected.filename}</p>
                <dl className="mt-1.5 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-3">
                  <div>
                    <dt className="text-slate-400 dark:text-slate-500">Tanggal</dt>
                    <dd className="text-slate-700 dark:text-slate-200">{fmtDate(selected.created_at)}</dd>
                  </div>
                  <div>
                    <dt className="text-slate-400 dark:text-slate-500">Ukuran</dt>
                    <dd className="text-slate-700 dark:text-slate-200">{fmtSize(selected.size)}</dd>
                  </div>
                  <div>
                    <dt className="text-slate-400 dark:text-slate-500">Tipe</dt>
                    <dd className="text-slate-700 dark:text-slate-200">{selected.mime || extOf(selected)}</dd>
                  </div>
                  <div>
                    <dt className="text-slate-400 dark:text-slate-500">Asal</dt>
                    <dd className="text-slate-700 dark:text-slate-200">{selected.role === 'assistant' ? 'Diolah AI' : 'Dikirim'}</dd>
                  </div>
                  <div className="min-w-0">
                    <dt className="text-slate-400 dark:text-slate-500">Percakapan</dt>
                    <dd className="truncate text-slate-700 dark:text-slate-200">{selected.session_title || 'Tanpa percakapan'}</dd>
                  </div>
                  {selected.model && (
                    <div className="min-w-0">
                      <dt className="text-slate-400 dark:text-slate-500">Model</dt>
                      <dd className="truncate text-slate-700 dark:text-slate-200">{selected.model}</dd>
                    </div>
                  )}
                </dl>
              </div>
              <div className="flex shrink-0 gap-2">
                {selected.mime !== 'application/pdf' && (
                  <button
                    type="button"
                    onClick={toPdf}
                    disabled={converting}
                    className="rounded-xl bg-skyblue px-3 py-2 text-sm font-medium text-brand-900 transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
                  >
                    {converting ? 'Mengonversi...' : 'Jadikan PDF'}
                  </button>
                )}
                <a
                  href={downloadSrc(selected.url)}
                  download={selected.filename}
                  className="rounded-xl bg-brand px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-600 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
                >
                  Unduh
                </a>
                <button
                  type="button"
                  onClick={() => remove(selected.id)}
                  className="rounded-xl bg-red-50 px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-100 dark:bg-red-900/40 dark:text-red-400 dark:hover:bg-red-900/60"
                >
                  Hapus
                </button>
                <button
                  type="button"
                  onClick={() => setSelected(null)}
                  className="rounded-xl bg-cream px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-brand-100 dark:bg-brand-700 dark:text-slate-300 dark:hover:bg-slate-600"
                >
                  Tutup
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}