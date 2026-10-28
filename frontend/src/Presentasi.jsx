import { useEffect, useRef, useState } from 'react';
import { api, mediaSrc, apiUpload, apiGeneratePptx, apiDraftPresentation, apiQueueTask } from './api.js';
import { confirmAction, showSuccess } from './feedback.js';
import { ProgressBar } from './Queue.jsx';

const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
const MAX_MATERIAL_CHARS = 20000;

// Palet tema. Dikirim sebagai hex ke backend lalu di-inject ke CSS Marp.
const THEMES = [
  { id: 'violet', name: 'Violet Modern', accent: '#4f46e5', accent2: '#06b6d4', bg: '#ffffff', ink: '#0f172a' },
  { id: 'sunset', name: 'Sunset Hangat', accent: '#ea580c', accent2: '#e11d48', bg: '#fffbf7', ink: '#3b1f12' },
  { id: 'forest', name: 'Hutan Segar', accent: '#059669', accent2: '#65a30d', bg: '#f7fdf9', ink: '#10331f' },
  { id: 'midnight', name: 'Midnight Gelap', accent: '#818cf8', accent2: '#22d3ee', bg: '#0f172a', ink: '#e2e8f0' },
  { id: 'rose', name: 'Rose Mewah', accent: '#e11d48', accent2: '#a855f7', bg: '#fff7fa', ink: '#3f0d20' },
  { id: 'slate', name: 'Slate Profesional', accent: '#0ea5e9', accent2: '#475569', bg: '#f8fafc', ink: '#0f172a' },
];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const TEMPLATE = `---
marp: true
theme: notebook
paginate: true
---

<!-- _class: title -->

# Judul Presentasi

Subjudul atau nama pembuat

---

## Slide Kedua

- Poin pertama
- Poin kedua
- Poin ketiga

---

## Slide Ketiga

| Kolom A | Kolom B |
| --- | --- |
| isi 1 | isi 2 |
`;

const fmtSize = (bytes) => {
  if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MB`;
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${bytes} B`;
};

const fmtDate = (iso) =>
  iso ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '—';

const downloadSrc = (url) => `${mediaSrc(url)}&download=1`;

const slideCount = (md) => {
  const body = md.replace(/^---[\s\S]*?---\s*/, '');
  const slides = body.split(/\n---+\s*\n|\n---+\s*$/).filter((s) => s.trim() !== '');
  return Math.max(1, slides.length);
};

export default function Presentasi() {
  const [markdown, setMarkdown] = useState(TEMPLATE);
  const [filename, setFilename] = useState('');
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState(0);
  const [themeId, setThemeId] = useState('violet');
  const [error, setError] = useState(null);
  const [result, setResult] = useState(null);
  const [items, setItems] = useState([]);
  const [prompt, setPrompt] = useState('');
  const [drafting, setDrafting] = useState(false);
  const [materials, setMaterials] = useState([]);
  const [uploading, setUploading] = useState(false);
  const [dragOver, setDragOver] = useState(false);
  const fileInput = useRef(null);

  const load = () => {
    api('/api/media')
      .then((data) => setItems((data.media || []).filter((m) => m.mime === PPTX_MIME)))
      .catch(() => {});
  };

  useEffect(load, []);

  const materialText = materials
    .filter((m) => (m.preview || '').trim())
    .map((m) => `### ${m.filename}\n${m.preview}`)
    .join('\n\n')
    .slice(0, MAX_MATERIAL_CHARS);

  const handleFiles = async (files) => {
    const list = Array.from(files || []);
    if (!list.length) return;
    setUploading(true);
    setError(null);
    try {
      for (const file of list) {
        const media = await apiUpload(file);
        setMaterials((prev) => [...prev, media]);
      }
      showSuccess(list.length > 1 ? `${list.length} materi berhasil diunggah.` : 'Materi berhasil diunggah.');
    } catch (e) {
      setError(e.message);
    } finally {
      setUploading(false);
      if (fileInput.current) fileInput.current.value = '';
    }
  };

  const removeMaterial = async (id) => {
    const ok = await confirmAction({ title: 'Hapus Materi', message: 'Hapus materi yang diunggah ini?', confirmLabel: 'Ya, hapus' });
    if (!ok) return;
    setMaterials((prev) => prev.filter((m) => m.id !== id));
    try {
      await api(`/api/media/${id}`, { method: 'DELETE', success: 'Materi berhasil dihapus.' });
    } catch {
      /* sudah lepas dari daftar; abaikan */
    }
  };

  const draft = async () => {
    if (!prompt.trim()) return;
    setDrafting(true);
    setError(null);
    try {
      const data = await apiDraftPresentation(prompt, materialText);
      setMarkdown(data.markdown);
      if (!filename.trim()) {
        const guess = prompt.trim().split(/\s+/).slice(0, 4).join(' ');
        setFilename(guess);
      }
    } catch (e) {
      setError(e.message);
    } finally {
      setDrafting(false);
    }
  };

  const activeTheme = THEMES.find((t) => t.id === themeId) || THEMES[0];

  const build = async () => {
    if (!markdown.trim()) return;
    setBusy(true);
    setProgress(0);
    setError(null);
    setResult(null);
    try {
      const task = await apiGeneratePptx(markdown, filename, activeTheme);
      setProgress(task.progress || 0);
      for (;;) {
        await sleep(2000);
        const t = await apiQueueTask(task.id);
        setProgress(t.progress || 0);
        if (t.status === 'done') {
          const r = t.result || {};
          if (r.url) setResult({ id: r.media_id, url: r.url, filename: r.filename, size: r.size });
          load();
          break;
        }
        if (t.status === 'error') {
          throw new Error(t.error || 'Gagal membuat presentasi.');
        }
      }
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
      setProgress(0);
    }
  };

  const remove = async (id) => {
    const ok = await confirmAction({ title: 'Hapus Presentasi', message: 'Hapus presentasi ini?', confirmLabel: 'Ya, hapus' });
    if (!ok) return;
    try {
      await api(`/api/media/${id}`, { method: 'DELETE', success: 'Presentasi berhasil dihapus.' });
      setItems((prev) => prev.filter((m) => m.id !== id));
      setResult((cur) => (cur && cur.id === id ? null : cur));
    } catch (e) {
      setError(e.message);
    }
  };

  const card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700';

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      <h2 className="text-lg font-semibold">Presentasi</h2>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        Unggah materi (PDF/peraturan, dsb.), minta Hermes menyusun draf, sunting, lalu buat PowerPoint (.pptx) via Marp.
      </p>

      <div className={`mt-4 ${card}`}>
        <label className="text-xs font-medium text-slate-500 dark:text-slate-400">Materi referensi (opsional)</label>
        <div
          role="button"
          tabIndex={0}
          onClick={() => fileInput.current?.click()}
          onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && fileInput.current?.click()}
          onDragOver={(e) => {
            e.preventDefault();
            setDragOver(true);
          }}
          onDragLeave={() => setDragOver(false)}
          onDrop={(e) => {
            e.preventDefault();
            setDragOver(false);
            handleFiles(e.dataTransfer.files);
          }}
          className={`mt-1 flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-6 text-center transition ${
            dragOver
              ? 'border-brand bg-brand-50 dark:border-skyblue dark:bg-brand-800'
              : 'border-slate-300 hover:border-slate-400 dark:border-slate-600 dark:hover:border-slate-500'
          }`}
        >
          <span className="text-sm text-slate-600 dark:text-slate-300">
            {uploading ? 'Mengunggah…' : 'Tarik & lepas berkas di sini, atau klik untuk memilih'}
          </span>
          <span className="mt-1 text-xs text-slate-400 dark:text-slate-500">
            PDF, DOCX, PPTX, XLSX, TXT, MD, gambar — teksnya diekstrak sebagai bahan.
          </span>
          <input
            ref={fileInput}
            type="file"
            multiple
            className="hidden"
            onClick={(e) => e.stopPropagation()}
            onChange={(e) => handleFiles(e.target.files)}
          />
        </div>
        {materials.length > 0 && (
          <ul className="mt-3 space-y-1">
            {materials.map((m) => (
              <li
                key={m.id}
                className="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-1.5 text-xs dark:bg-brand-800"
              >
                <span className="truncate">{m.filename}</span>
                <span className="ml-auto shrink-0 text-slate-400 dark:text-slate-500">
                  {m.preview && m.preview.trim()
                    ? `${m.preview.length.toLocaleString('id-ID')} karakter`
                    : 'teks tidak terbaca'}
                </span>
                <button
                  type="button"
                  onClick={() => removeMaterial(m.id)}
                  className="shrink-0 text-red-500 hover:underline"
                >
                  Hapus
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      <div className={`mt-4 ${card}`}>
        <label className="text-xs font-medium text-slate-500 dark:text-slate-400" htmlFor="pptx-prompt">
          Buat dengan AI (Hermes)
        </label>
        <textarea
          id="pptx-prompt"
          value={prompt}
          onChange={(e) => setPrompt(e.target.value)}
          rows={3}
          placeholder="Contoh: presentasi 5 slide tentang rencana anggaran sekolah 2026 untuk rapat komite..."
          className="mt-1 w-full resize-y rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm leading-relaxed outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
        />
        <button
          type="button"
          onClick={draft}
          disabled={!prompt.trim() || drafting}
          className="mt-3 rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
        >
          {drafting ? 'Hermes menyusun draf...' : 'Susun draf dengan AI'}
        </button>
        <p className="mt-1.5 text-xs text-slate-400 dark:text-slate-500">
          Hasilnya masuk ke editor di bawah — silakan sunting dulu sebelum dibuat.
        </p>
      </div>

      <div className={`mt-4 ${card}`}>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <label className="text-xs font-medium text-slate-500 dark:text-slate-400" htmlFor="pptx-name">
            Nama berkas
          </label>
          <span className="text-xs text-slate-400 dark:text-slate-500">{slideCount(markdown)} slide</span>
        </div>
        <input
          id="pptx-name"
          value={filename}
          onChange={(e) => setFilename(e.target.value)}
          placeholder="presentasi-saya (tanpa .pptx)"
          className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
        />

        <div className="mt-3">
          <label className="text-xs font-medium text-slate-500 dark:text-slate-400">Tema desain</label>
          <div className="mt-1 flex flex-wrap gap-2">
            {THEMES.map((t) => (
              <button
                key={t.id}
                type="button"
                onClick={() => setThemeId(t.id)}
                title={t.name}
                className={`flex items-center gap-2 rounded-xl border px-2.5 py-1.5 text-xs transition ${
                  themeId === t.id
                    ? 'border-brand ring-2 ring-brand/30 dark:border-skyblue'
                    : 'border-slate-200 hover:border-slate-400 dark:border-slate-700'
                }`}
              >
                <span
                  className="h-4 w-4 shrink-0 rounded-full"
                  style={{ background: `linear-gradient(135deg, ${t.accent}, ${t.accent2})` }}
                />
                <span className="text-slate-600 dark:text-slate-300">{t.name}</span>
              </button>
            ))}
          </div>
        </div>

        <textarea
          value={markdown}
          onChange={(e) => setMarkdown(e.target.value)}
          rows={16}
          spellCheck={false}
          placeholder="Tempel atau tulis Markdown Marp di sini..."
          className="mt-3 w-full resize-y rounded-xl border border-slate-200 bg-white px-3 py-2 font-mono text-xs leading-relaxed outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
        />

        <div className="mt-3 flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={build}
            disabled={!markdown.trim() || busy}
            className="rounded-xl bg-blush px-4 py-2 text-sm font-medium text-brand-900 transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
          >
            {busy ? 'Membuat PPTX...' : 'Buat PowerPoint'}
          </button>
          <button
            type="button"
            onClick={() => setMarkdown(TEMPLATE)}
            className="text-xs font-medium text-brand transition hover:opacity-70 dark:text-skyblue"
          >
            Muat contoh
          </button>
        </div>
        {busy && (
          <div className="mt-3">
            <ProgressBar value={progress} />
            <p className="mt-1 text-xs text-slate-400 dark:text-slate-500">
              Diproses di server — boleh ditinggal, pantau juga di menu Antrean. {progress}%
            </p>
          </div>
        )}
        {error && <p className="mt-3 text-sm text-red-500">{error}</p>}
      </div>

      {result && (
        <div className={`mt-4 ${card}`}>
          <h3 className="text-sm font-semibold">Presentasi siap</h3>
          <p className="mt-1 truncate text-sm text-slate-600 dark:text-slate-300">{result.filename}</p>
          <p className="mt-0.5 text-xs text-slate-400 dark:text-slate-500">{fmtSize(result.size)}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            <a
              href={downloadSrc(result.url)}
              download={result.filename}
              className="rounded-xl bg-brand px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-600 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
            >
              Unduh .pptx
            </a>
            <button
              type="button"
              onClick={() => remove(result.id)}
              className="rounded-xl bg-red-50 px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-100 dark:bg-red-900/40 dark:text-red-400 dark:hover:bg-red-900/60"
            >
              Hapus
            </button>
          </div>
        </div>
      )}

      <div className="mt-6">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-400">Presentasi tersimpan</h3>
        {items.length === 0 ? (
          <p className="mt-2 text-sm text-slate-400 dark:text-slate-500">Belum ada presentasi.</p>
        ) : (
          <ul className="mt-2 divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:divide-slate-800 dark:bg-brand-900 dark:ring-slate-700">
            {items.map((m) => (
              <li key={m.id} className="flex items-center gap-3 px-3 py-2">
                <span className="shrink-0 rounded-lg bg-brand px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-white">
                  PPTX
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium" title={m.filename}>{m.filename}</p>
                  <p className="text-[11px] text-slate-400 dark:text-slate-500">
                    {fmtSize(m.size)} · {fmtDate(m.created_at)}
                  </p>
                </div>
                <a
                  href={downloadSrc(m.url)}
                  download={m.filename}
                  className="shrink-0 text-xs font-medium text-brand transition hover:opacity-70 dark:text-skyblue"
                >
                  Unduh
                </a>
                <button
                  type="button"
                  onClick={() => remove(m.id)}
                  className="shrink-0 text-xs font-medium text-slate-400 transition hover:text-blush"
                >
                  Hapus
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
