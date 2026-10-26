import { useEffect, useState } from 'react';
import { api, mediaSrc } from './api.js';

const input =
  'rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue';

const currentMonth = () => {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Makassar',
    year: 'numeric',
    month: '2-digit',
  }).formatToParts(new Date());
  const get = (type) => parts.find((p) => p.type === type)?.value;
  return `${get('year')}-${get('month')}`;
};

const fmtNum = (n) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(Number(n) || 0);

export function ReportIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" />
      <path d="M14 2v6h6" />
      <path d="M8 13h8" />
      <path d="M8 17h5" />
    </svg>
  );
}

export default function PerformanceReport() {
  const [month, setMonth] = useState(currentMonth);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState(false);
  const [template, setTemplate] = useState(null);
  const [download, setDownload] = useState(null);
  const [notice, setNotice] = useState(null);
  const [error, setError] = useState(null);
  const [newPlan, setNewPlan] = useState('');
  const [addingPlan, setAddingPlan] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [editingName, setEditingName] = useState('');
  const [savingPlan, setSavingPlan] = useState(false);

  const load = () => {
    setError(null);
    setLoading(true);
    api(`/api/performance/report?month=${month}`)
      .then(setData)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };

  useEffect(load, [month]);

  const generate = async () => {
    setGenerating(true);
    setError(null);
    setDownload(null);
    try {
      const res = await api('/api/performance/report', { method: 'POST', body: JSON.stringify({ month }) });
      setDownload(res.media);
      setNotice('Laporan DOCX siap diunduh.');
    } catch (e) {
      setError(e.message);
    } finally {
      setGenerating(false);
    }
  };

  const addPlan = async (e) => {
    e.preventDefault();
    if (!newPlan.trim()) return;
    setAddingPlan(true);
    setError(null);
    try {
      await api('/api/performance/plans', { method: 'POST', body: JSON.stringify({ name: newPlan.trim() }) });
      setNewPlan('');
      load();
    } catch (err) {
      setError(err.message);
    } finally {
      setAddingPlan(false);
    }
  };

  const startEditPlan = (plan) => {
    setEditingId(plan.id);
    setEditingName(plan.name);
    setError(null);
  };

  const cancelEditPlan = () => {
    setEditingId(null);
    setEditingName('');
  };

  const saveEditPlan = async () => {
    if (!editingId || !editingName.trim()) return;
    setSavingPlan(true);
    setError(null);
    try {
      await api(`/api/performance/plans/${editingId}`, { method: 'PUT', body: JSON.stringify({ name: editingName.trim() }) });
      setEditingId(null);
      setEditingName('');
      load();
    } catch (err) {
      setError(err.message);
    } finally {
      setSavingPlan(false);
    }
  };

  const useTemplate = async (key) => {
    setTemplate(key);
    setError(null);
    setDownload(null);
    try {
      const res = await api(`/api/performance/templates/${key}`, { method: 'POST', body: JSON.stringify({ month }) });
      setNotice(`Template dibuat: ${res.todo.title}. Edit tanggal, jam, atau bukti di Todo/Kalender.`);
      load();
    } catch (e) {
      setError(e.message);
    } finally {
      setTemplate(null);
    }
  };

  const total = data?.totals || { activities: 0, hours: 0, evidence: 0, done: 0 };

  return (
    <div className="mx-auto max-w-5xl px-4 py-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Laporan Kinerja</h2>
          <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            Matriks bulanan e-SAKIP dari Todo dan Kalender.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <label className="text-xs font-medium text-slate-500 dark:text-slate-400" htmlFor="report-month">Bulan</label>
          <input
            id="report-month"
            type="month"
            value={month}
            onChange={(e) => { setMonth(e.target.value); setDownload(null); setNotice(null); }}
            className={input}
          />
          <button
            type="button"
            onClick={generate}
            disabled={generating || loading}
            className="rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
          >
            {generating ? 'Menyusun...' : 'Generate DOCX'}
          </button>
        </div>
      </div>

      <form onSubmit={addPlan} className="mt-3 flex flex-wrap items-center gap-2">
        <input
          value={newPlan}
          onChange={(e) => setNewPlan(e.target.value)}
          placeholder="Nama indikator baru..."
          className={input}
          maxLength={100}
        />
        <button
          type="submit"
          disabled={addingPlan || !newPlan.trim()}
          className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium transition hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-brand-800"
        >
          {addingPlan ? 'Menambah...' : '+ Tambah indikator'}
        </button>
      </form>

      {error && <p className="mt-4 text-sm text-red-500">{error}</p>}
      {notice && (
        <div className="mt-4 rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
          {notice}
          {download && (
            <a href={mediaSrc(download.url)} className="ml-2 font-semibold underline" download={download.filename}>
              {download.filename}
            </a>
          )}
        </div>
      )}

      {loading ? (
        <p className="mt-6 text-sm text-slate-500 dark:text-slate-400">Memuat matriks...</p>
      ) : (
        <>
          <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
            {[
              ['Kegiatan', total.activities],
              ['Total jam', fmtNum(total.hours)],
              ['Bukti fisik', total.evidence],
              ['Selesai', total.done],
            ].map(([label, value]) => (
              <div key={label} className="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
                <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</p>
                <p className="mt-1 text-xl font-semibold">{value}</p>
              </div>
            ))}
          </div>

          <div className="mt-4 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-700">
              <h3 className="text-sm font-semibold">Matriks {data?.month_label}</h3>
              <span className="text-xs text-slate-400">Hanya kegiatan ber-indikator dan bertanggal bulan ini.</span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[560px] text-left text-sm">
                <thead className="bg-cream text-[11px] uppercase tracking-wide text-slate-500 dark:bg-brand-800 dark:text-slate-400">
                  <tr>
                    <th className="px-4 py-2 font-semibold">Indikator</th>
                    <th className="px-3 py-2 text-right font-semibold">Kegiatan</th>
                    <th className="px-3 py-2 text-right font-semibold">Jam</th>
                    <th className="px-3 py-2 text-right font-semibold">Bukti</th>
                    <th className="px-4 py-2 text-right font-semibold">Selesai</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {(data?.plans || []).map((plan) => (
                    <tr key={plan.id}>
                      <td className="px-4 py-2.5 font-medium">
                        {editingId === plan.id ? (
                          <div className="flex items-center gap-2">
                            <input
                              value={editingName}
                              onChange={(e) => setEditingName(e.target.value)}
                              onKeyDown={(e) => {
                                if (e.key === 'Enter') saveEditPlan();
                                if (e.key === 'Escape') cancelEditPlan();
                              }}
                              className={`${input} py-1`}
                              maxLength={100}
                            />
                            <button type="button" disabled={savingPlan} onClick={saveEditPlan} className="text-xs font-medium text-emerald-600 disabled:opacity-40">Simpan</button>
                            <button type="button" disabled={savingPlan} onClick={cancelEditPlan} className="text-xs text-slate-400">Batal</button>
                          </div>
                        ) : (
                          <button type="button" onClick={() => startEditPlan(plan)} className="text-left hover:underline">{plan.name}</button>
                        )}
                      </td>
                      <td className="px-3 py-2.5 text-right tabular-nums">{plan.activities}</td>
                      <td className="px-3 py-2.5 text-right tabular-nums">{fmtNum(plan.hours)}</td>
                      <td className="px-3 py-2.5 text-right tabular-nums">{plan.evidence}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums">{plan.done}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot className="bg-cream/70 font-semibold dark:bg-brand-800/60">
                  <tr>
                    <td className="px-4 py-2.5">Total</td>
                    <td className="px-3 py-2.5 text-right tabular-nums">{total.activities}</td>
                    <td className="px-3 py-2.5 text-right tabular-nums">{fmtNum(total.hours)}</td>
                    <td className="px-3 py-2.5 text-right tabular-nums">{total.evidence}</td>
                    <td className="px-4 py-2.5 text-right tabular-nums">{total.done}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>

          <div className="mt-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
            <h3 className="text-sm font-semibold">Bukti per indikator</h3>
            <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {(data?.plans || []).map((plan) => (
                <div key={plan.id} className="rounded-xl bg-cream px-3 py-2 dark:bg-brand-800">
                  <p className="text-xs font-semibold">{plan.name}</p>
                  {plan.evidence_files?.length ? (
                    <ul className="mt-1 max-h-24 space-y-0.5 overflow-auto text-[11px] text-slate-500 dark:text-slate-400">
                      {plan.evidence_files.map((file) => <li key={file} className="truncate" title={file}>{file}</li>)}
                    </ul>
                  ) : (
                    <p className="mt-1 text-[11px] text-slate-400">Belum ada bukti.</p>
                  )}
                </div>
              ))}
            </div>
          </div>

          <div className="mt-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
            <h3 className="text-sm font-semibold">Template bulanan</h3>
            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
              Dibuat sebagai Todo + acara Kalender pada tanggal 1 bulan terpilih.
            </p>
            <div className="mt-3 flex flex-wrap gap-2">
              {(data?.templates || []).map((t) => (
                <button
                  key={t.key}
                  type="button"
                  onClick={() => useTemplate(t.key)}
                  disabled={template !== null}
                  className="rounded-xl border border-slate-200 px-3 py-2 text-left text-xs font-medium transition hover:border-slate-400 disabled:opacity-40 dark:border-slate-700 dark:hover:border-slate-500"
                >
                  {template === t.key ? 'Membuat...' : t.title}
                  <span className="mt-0.5 block text-[10px] font-normal text-slate-400">{fmtNum(t.duration_jam)} jam</span>
                </button>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  );
}
