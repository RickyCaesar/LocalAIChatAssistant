import { useEffect, useMemo, useRef, useState } from 'react';
import { api, mediaSrc } from './api.js';
import { confirmAction } from './feedback.js';
import { holidaysForYear } from './holidays.js';

function MultiSelect({ options, value = [], onChange, placeholder }) {
  const [open, setOpen] = useState(false);
  const box = useRef(null);
  const selected = options.filter((o) => value.includes(o.value));
  const toggle = (v) => {
    const set = new Set(value);
    if (set.has(v)) set.delete(v); else set.add(v);
    onChange(Array.from(set));
  };

  useEffect(() => {
    if (!open) return;
    const onDown = (e) => { if (box.current && !box.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, [open]);

  return (
    <div ref={box} className="relative">
      <button type="button" onClick={() => setOpen((o) => !o)} className={`flex w-full items-center justify-between gap-2 text-left ${input}`}>
        <span className={`truncate ${selected.length ? '' : 'text-slate-400'}`}>
          {selected.length
            ? `${selected.length > 1 ? `${selected.length} dipilih · ` : ''}${selected.map((s) => s.label).join(', ')}`
            : placeholder}
        </span>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className={`h-4 w-4 shrink-0 transition ${open ? 'rotate-180' : ''}`}><path d="m6 9 6 6 6-6" /></svg>
      </button>
      {open && (
        <div className="absolute z-30 mt-1 w-full rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-brand-900">
          <div className="max-h-56 overflow-auto p-1">
            {options.length === 0 && <p className="px-2 py-2 text-xs text-slate-400">Belum ada indikator. Tambahkan di Laporan Kinerja.</p>}
            {options.map((o) => (
              <label key={o.value} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-brand-800">
                <input type="checkbox" checked={value.includes(o.value)} onChange={() => toggle(o.value)} className="h-4 w-4 shrink-0 rounded border-slate-300 text-brand focus:ring-brand dark:border-slate-600" />
                <span className="min-w-0 break-words">{o.label}</span>
              </label>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

const DAY_NAMES = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const MONTH_NAMES = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

const RECURRENCE = [
  { value: 'none', label: 'Sekali' },
  { value: 'weekly', label: 'Mingguan' },
  { value: 'monthly', label: 'Bulanan' },
  { value: 'yearly', label: 'Tahunan' },
];
const REC_LABEL = { weekly: '↻ mingguan', monthly: '↻ bulanan', yearly: '↻ tahunan' };

const dayKey = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
const addDays = (d, n) => {
  const x = new Date(d);
  x.setDate(x.getDate() + n);
  return x;
};

// ponytail: recurrence di-expand di sisi klien (cap 400 iterasi/event) & edit/hapus berlaku untuk seluruh seri.
// Ganti ke RRULE + tabel occurrence bila butuh pengecualian per-kejadian.
function expand(events, from, to) {
  const out = [];
  for (const ev of events) {
    const base = new Date(ev.start_at);
    const rec = ev.recurrence || 'none';
    if (rec === 'none') {
      if (base >= from && base <= to) out.push({ key: `${ev.id}`, event: ev, start: base });
      continue;
    }
    const cur = new Date(base);
    let guard = 0;
    while (cur <= to && guard++ < 400) {
      if (cur >= from) out.push({ key: `${ev.id}#${cur.toISOString()}`, event: ev, start: new Date(cur) });
      if (rec === 'weekly') cur.setDate(cur.getDate() + 7);
      else if (rec === 'monthly') cur.setMonth(cur.getMonth() + 1);
      else if (rec === 'yearly') cur.setFullYear(cur.getFullYear() + 1);
      else break;
    }
  }
  return out;
}

function CalendarIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <rect x="3" y="4" width="18" height="18" rx="2" />
      <path d="M16 2v4" />
      <path d="M8 2v4" />
      <path d="M3 10h18" />
    </svg>
  );
}

const fmtTime = (iso) => (iso ? new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : '');
const fmtDay = (d) => d.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

const input =
  'rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue';

const eventCls = (done) =>
  done
    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
    : 'bg-skyblue/30 text-brand-700 dark:bg-brand-800 dark:text-skyblue';

const emptyForm = (date) => ({
  title: '',
  date,
  time: '09:00',
  duration: 1,
  description: '',
  recurrence: 'none',
  syncTodo: false,
  done: false,
  performancePlanId: '',
  performancePlanIds: [],
  mediaItems: [],
  tindakLanjut: '',
});

export default function CalendarPage() {
  const [events, setEvents] = useState([]);
  const [options, setOptions] = useState({ plans: [], media: [] });
  const [planFilter, setPlanFilter] = useState('');
  const [cursor, setCursor] = useState(() => new Date());
  const [view, setView] = useState('bulan');
  const [selectedKey, setSelectedKey] = useState(() => dayKey(new Date()));
  const [form, setForm] = useState(() => emptyForm(dayKey(new Date())));
  const [editingId, setEditingId] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [viewItem, setViewItem] = useState(null);
  const [todoDetail, setTodoDetail] = useState(null);
  const [loadingView, setLoadingView] = useState(false);

  const load = () => {
    setError(null);
    api('/api/events')
      .then((data) => setEvents(data.events))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  useEffect(() => {
    let live = true;
    Promise.all([
      api('/api/performance/plans').catch(() => ({ plans: [] })),
      api('/api/media').catch(() => ({ media: [] })),
    ]).then(([plans, media]) => {
      if (live) setOptions({ plans: plans.plans || [], media: media.media || [] });
    });
    return () => { live = false; };
  }, []);

  const y = cursor.getFullYear();
  const m = cursor.getMonth();
  const today = startOfDay(new Date());
  const todayKey = dayKey(today);

  const holidayFor = (key) => holidaysForYear(Number(key.slice(0, 4)))[key] || null;

  const first = new Date(y, m, 1);
  const daysInMonth = new Date(y, m + 1, 0).getDate();
  const leading = first.getDay();
  const cells = [...Array(leading).fill(null), ...Array(daysInMonth).fill(null).map((_, i) => i + 1)];

  const weekStart = addDays(startOfDay(cursor), -cursor.getDay());
  const weekDays = [...Array(7)].map((_, i) => addDays(weekStart, i));

  const visibleFrom = view === 'minggu' ? weekStart : new Date(y, m, 1);
  const visibleTo = view === 'minggu' ? addDays(weekStart, 7) : new Date(y, m + 1, 1);

  const filteredEvents = useMemo(() => {
    if (!planFilter) return events;
    return events.filter((e) => {
      const ids = Array.isArray(e.performance_plan_ids) && e.performance_plan_ids.length
        ? e.performance_plan_ids.map(String)
        : (e.performance_plan_id ? [String(e.performance_plan_id)] : []);
      return ids.includes(planFilter);
    });
  }, [events, planFilter]);

  const byDay = useMemo(() => {
    const map = {};
    for (const occ of expand(filteredEvents, visibleFrom, visibleTo)) {
      const k = dayKey(occ.start);
      (map[k] = map[k] || []).push(occ);
    }
    for (const k of Object.keys(map)) map[k].sort((a, b) => a.start - b.start);
    return map;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filteredEvents, view, y, m, weekStart.getTime()]);

  const agenda = useMemo(
    () => expand(filteredEvents, today, addDays(today, 120)).sort((a, b) => a.start - b.start),
    [filteredEvents, today.getTime()]
  );

  const selectedEvents = useMemo(() => {
    const from = new Date(`${selectedKey}T00:00:00`);
    return expand(filteredEvents, from, addDays(from, 1)).sort((a, b) => a.start - b.start);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filteredEvents, selectedKey]);

  const resetForm = () => {
    setForm(emptyForm(selectedKey));
    setEditingId(null);
  };

  const uploadFiles = async (files) => {
    const list = Array.from(files || []);
    if (!list.length) return;
    setError(null);
    for (const file of list) {
      const fd = new FormData();
      fd.append('file', file);
      const res = await api('/api/media', { method: 'POST', body: fd, silent: true });
      setForm((f) => ({ ...f, mediaItems: [...(f.mediaItems || []), { id: res.id, filename: res.filename, mime: res.mime }] }));
    }
  };

  const submit = async (e) => {
    e.preventDefault();
    if (!form.title.trim()) return;
    const start = new Date(`${form.date}T${form.time}`);
    const end = new Date(start.getTime() + (Number(form.duration) || 1) * 3600000);
    const body = JSON.stringify({
      title: form.title,
      start_at: start.toISOString(),
      end_at: end.toISOString(),
      description: form.description || null,
      recurrence: form.recurrence,
      sync_todo: Boolean(form.syncTodo),
      performance_plan_id: form.performancePlanId || null,
      performance_plan_ids: Array.isArray(form.performancePlanIds) ? form.performancePlanIds.map(Number) : [],
      media_id: form.mediaItems?.[0]?.id || null,
      media_ids: (form.mediaItems || []).map((m) => m.id),
      tindak_lanjut: form.tindakLanjut || null,
      ...(editingId ? { done: Boolean(form.done) } : {}),
    });
    try {
      if (editingId) {
        const res = await api(`/api/events/${editingId}`, { method: 'PUT', body, success: 'Perubahan acara berhasil disimpan.' });
        setEvents((prev) => prev.map((x) => (x.id === editingId ? res.event : x)));
      } else {
        const res = await api('/api/events', { method: 'POST', body, success: 'Acara berhasil ditambahkan.' });
        setEvents((prev) => [...prev, res.event]);
      }
      resetForm();
    } catch (e2) {
      setError(e2.message);
    }
  };

  const startEdit = (ev) => {
    const d = new Date(ev.start_at);
    const end = ev.end_at ? new Date(ev.end_at) : null;
    const hours = ev.durasi_jam ?? (end && end > d ? Math.round(((end - d) / 3600000) * 100) / 100 : 1);
    const planIds = Array.isArray(ev.performance_plan_ids) && ev.performance_plan_ids.length
      ? ev.performance_plan_ids.map(String)
      : (ev.performance_plan_id ? [String(ev.performance_plan_id)] : []);
    setEditingId(ev.id);
    setViewItem(null);
    setTodoDetail(null);
    setForm({
      title: ev.title,
      date: dayKey(d),
      time: `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`,
      duration: hours,
      description: ev.description || '',
      recurrence: ev.recurrence || 'none',
      syncTodo: Boolean(ev.todo_id),
      done: Boolean(ev.done),
      performancePlanId: planIds[0] || '',
      performancePlanIds: planIds,
      mediaItems: ev.media_items?.length ? ev.media_items : (ev.media ? [ev.media] : []),
      tindakLanjut: ev.tindak_lanjut || '',
    });
  };

  const openView = async (ev) => {
    setViewItem(ev);
    setTodoDetail(null);
    if (ev.todo_id) {
      setLoadingView(true);
      try {
        const res = await api(`/api/todos/${ev.todo_id}`);
        setTodoDetail(res.todo || null);
      } catch {
        setTodoDetail(null);
      } finally {
        setLoadingView(false);
      }
    }
  };

  const remove = async (ev) => {
    const linked = ev.todo_id ? ' Todo yang tertaut juga dihapus.' : '';
    const msg = ev.recurrence && ev.recurrence !== 'none'
      ? `Hapus seluruh seri "${ev.title}"?${linked}`
      : `Hapus acara "${ev.title}"?${linked}`;
    const ok = await confirmAction({ title: 'Hapus Acara', message: msg, confirmLabel: 'Ya, hapus' });
    if (!ok) return;
    try {
      await api(`/api/events/${ev.id}`, { method: 'DELETE', success: 'Acara berhasil dihapus.' });
      setEvents((prev) => prev.filter((x) => x.id !== ev.id));
      if (editingId === ev.id) resetForm();
    } catch (e2) {
      setError(e2.message);
    }
  };

  const step = (dir) => setCursor((c) => (view === 'minggu' ? addDays(c, 7 * dir) : new Date(c.getFullYear(), c.getMonth() + dir, 1)));

  const headerLabel = view === 'minggu' ? `${weekDays[0].getDate()} ${MONTH_NAMES[weekDays[0].getMonth()]} – ${weekDays[6].getDate()} ${MONTH_NAMES[weekDays[6].getMonth()]} ${weekDays[6].getFullYear()}` : `${MONTH_NAMES[m]} ${y}`;

  const tabs = [
    { key: 'bulan', label: 'Bulan' },
    { key: 'minggu', label: 'Minggu' },
    { key: 'agenda', label: 'Agenda' },
  ];

  return (
    <div className="mx-auto max-w-5xl px-4 py-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Kalender</h2>
          <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Agenda, jadwal kinerja & hari libur Indonesia. Bisa dikelola lewat chat dengan Hermes.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <select
            value={planFilter}
            onChange={(e) => setPlanFilter(e.target.value)}
            aria-label="Filter Indikator Kinerja"
            className={`${input} !py-1.5 text-xs`}
          >
            <option value="">Semua Indikator Kinerja</option>
            {options.plans.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
          <div className="flex items-center gap-1 rounded-xl bg-cream p-1 dark:bg-brand-900">
            {tabs.map((t) => (
              <button
                key={t.key}
                type="button"
                onClick={() => setView(t.key)}
                className={`rounded-lg px-3 py-1.5 text-xs font-medium transition ${
                  view === t.key ? 'bg-white shadow-sm dark:bg-brand-600' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'
                }`}
              >
                {t.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      {error && <p className="mt-4 text-sm text-red-500">{error}</p>}

      {loading ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">Loading...</p>
      ) : (
        <>
          {view !== 'agenda' && (
            <div className="mt-4 flex items-center justify-between gap-2">
              <div className="flex items-center gap-1 rounded-xl bg-cream p-1 dark:bg-brand-900">
                <button type="button" onClick={() => step(-1)} className="rounded-lg px-3 py-1.5 text-sm font-medium transition hover:bg-white dark:hover:bg-brand-600">‹</button>
                <span className="px-2 text-sm font-semibold">{headerLabel}</span>
                <button type="button" onClick={() => step(1)} className="rounded-lg px-3 py-1.5 text-sm font-medium transition hover:bg-white dark:hover:bg-brand-600">›</button>
              </div>
              <button
                type="button"
                onClick={() => { setCursor(new Date()); setSelectedKey(todayKey); }}
                className="rounded-xl px-3 py-1.5 text-xs font-medium text-slate-500 transition hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
              >
                Hari ini
              </button>
            </div>
          )}

          {view === 'bulan' && (
            <div className="mt-3 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
              <div className="grid grid-cols-7 border-b border-slate-200 dark:border-slate-700">
                {DAY_NAMES.map((d, i) => (
                  <div key={d} className={`px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-wide ${i === 0 ? 'text-red-400' : 'text-slate-400 dark:text-slate-500'}`}>{d}</div>
                ))}
              </div>
              <div className="grid grid-cols-7">
                {cells.map((day, i) => {
                  if (day === null) return <div key={`x${i}`} className="min-h-24 border-b border-r border-slate-100 dark:border-slate-800" />;
                  const key = `${y}-${String(m + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                  const holiday = holidayFor(key);
                  const dayEvents = byDay[key] || [];
                  return (
                    <button
                      key={key}
                      type="button"
                      onClick={() => setSelectedKey(key)}
                      className={`min-h-24 border-b border-r border-slate-100 p-1.5 text-left transition hover:bg-cream dark:border-slate-800 dark:hover:bg-brand ${
                        selectedKey === key ? 'bg-skyblue/15 dark:bg-brand-900/60' : ''
                      }`}
                    >
                      <span
                        className={`inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium ${
                          key === todayKey ? 'bg-brand text-white'
                            : holiday ? 'text-red-500'
                            : key === selectedKey ? 'bg-skyblue/30 text-brand-700 dark:bg-brand-800 dark:text-skyblue'
                            : ''
                        }`}
                      >
                        {day}
                      </span>
                      {holiday && <p className="mt-0.5 truncate text-[9px] font-medium text-red-500">{holiday}</p>}
                      <div className="mt-1 space-y-1">
                        {dayEvents.slice(0, 3).map((occ) => (
                          <button
                            key={occ.key}
                            type="button"
                            onClick={(e) => { e.stopPropagation(); openView(occ.event); }}
                            className={`block w-full truncate rounded-md px-1.5 py-0.5 text-left text-[10px] font-medium ${eventCls(occ.event.done)}`}
                          >
                            {occ.event.done ? '✓ ' : ''}{fmtTime(occ.start)} {occ.event.title}
                          </button>
                        ))}
                        {dayEvents.length > 3 && <div className="px-1.5 text-[10px] text-slate-400">+{dayEvents.length - 3} lagi</div>}
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>
          )}

          {view === 'minggu' && (
            <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-7">
              {weekDays.map((d) => {
                const key = dayKey(d);
                const holiday = holidayFor(key);
                const dayEvents = byDay[key] || [];
                return (
                  <div key={key} className={`rounded-2xl bg-white p-2 shadow-sm ring-1 ring-slate-200 sm:min-h-40 dark:bg-brand-900 dark:ring-slate-700 ${key === todayKey ? 'ring-2 ring-brand dark:ring-skyblue' : ''}`}>
                    <div className={`text-xs font-semibold ${holiday || d.getDay() === 0 ? 'text-red-500' : ''}`}>
                      {DAY_NAMES[d.getDay()]} {d.getDate()}
                    </div>
                    {holiday && <p className="mt-0.5 text-[10px] font-medium text-red-500">{holiday}</p>}
                    <div className="mt-1.5 space-y-1">
                      {dayEvents.map((occ) => (
                        <button
                          key={occ.key}
                          type="button"
                          onClick={() => { openView(occ.event); setSelectedKey(key); setView('bulan'); setCursor(new Date(d.getFullYear(), d.getMonth(), 1)); }}
                          className={`block w-full truncate rounded-md px-1.5 py-1 text-left text-[10px] font-medium ${eventCls(occ.event.done)}`}
                        >
                          {occ.event.done ? '✓ ' : ''}{fmtTime(occ.start)} {occ.event.title}
                        </button>
                      ))}
                    </div>
                  </div>
                );
              })}
            </div>
          )}

          {view === 'agenda' && (
            <div className="mt-4">
              {agenda.length === 0 ? (
                <p className="rounded-2xl bg-white py-16 text-center text-sm text-slate-400 ring-1 ring-slate-200 dark:bg-brand-900 dark:text-slate-500 dark:ring-slate-700">
                  Tidak ada acara dalam 120 hari ke depan.
                </p>
              ) : (
                <ul className="space-y-2">
                  {agenda.map((occ) => {
                    const key = dayKey(occ.start);
                    const holiday = holidayFor(key);
                    return (
                      <li key={occ.key} className="flex items-center gap-3 rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
                        <div className="w-16 shrink-0 text-center">
                          <p className="text-[10px] uppercase text-slate-400">{DAY_NAMES[occ.start.getDay()]}</p>
                          <p className={`text-lg font-semibold leading-none ${holiday ? 'text-red-500' : ''}`}>{occ.start.getDate()}</p>
                          <p className="text-[10px] text-slate-400">{MONTH_NAMES[occ.start.getMonth()].slice(0, 3)}</p>
                        </div>
                        <button type="button" onClick={() => openView(occ.event)} className="min-w-0 flex-1 text-left">
                          <p className="truncate text-sm font-medium">
                            {occ.event.done ? '✓ ' : ''}{fmtTime(occ.start)} · {occ.event.title}
                            {REC_LABEL[occ.event.recurrence] ? <span className="ml-2 text-[10px] font-normal text-slate-400">{REC_LABEL[occ.event.recurrence]}</span> : null}
                          </p>
                          {holiday && <p className="text-[11px] font-medium text-red-500">{holiday}</p>}
                          <div className="mt-1 flex flex-wrap items-center gap-1.5">
                            {(occ.event.performance_plans?.length ? occ.event.performance_plans : (occ.event.performance_plan ? [occ.event.performance_plan] : [])).map((plan) => (
                              <span key={plan.id} className="rounded-full bg-skyblue/20 px-2 py-0.5 text-[10px] font-medium text-brand-700 dark:bg-brand-800 dark:text-skyblue">{plan.indikator_kinerja || plan.name}</span>
                            ))}
                            {occ.event.done && (
                              <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Selesai</span>
                            )}
                            {occ.event.media && <span className="text-[10px] text-slate-400">Bukti: {occ.event.media.filename}{occ.event.media_items?.length > 1 ? ` +${occ.event.media_items.length - 1}` : ''}</span>}
                            {occ.event.durasi_jam ? <span className="text-[10px] text-slate-400">{occ.event.durasi_jam} jam</span> : null}
                          </div>
                          {occ.event.tindak_lanjut && <p className="mt-0.5 whitespace-pre-wrap text-xs text-slate-500 dark:text-slate-400">{occ.event.tindak_lanjut}</p>}
                          {occ.event.description && <p className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">{occ.event.description}</p>}
                        </button>
              <button type="button" aria-label="Lihat detail" onClick={() => openView(occ.event)} className="shrink-0 rounded-md p-1 text-slate-400 transition hover:text-brand dark:hover:text-skyblue">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                  <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                  <circle cx="12" cy="12" r="3" />
                </svg>
              </button>
              <button type="button" aria-label="Edit acara" onClick={() => startEdit(occ.event)} className="shrink-0 rounded-md p-1 text-slate-400 transition hover:text-brand dark:hover:text-skyblue">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                  <path d="M12 20h9" />
                  <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z" />
                </svg>
              </button>
              <button type="button" aria-label="Hapus acara" onClick={() => remove(occ.event)} className="shrink-0 rounded-md p-1 text-slate-400 transition hover:text-blush">
                          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                            <path d="M3 6h18" />
                            <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                          </svg>
                        </button>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>
          )}
        </>
      )}

      {viewItem && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="w-full max-w-lg rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
            <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-700">
              <h3 className="text-sm font-semibold">Detail Kegiatan</h3>
              <button type="button" onClick={() => { setViewItem(null); setTodoDetail(null); }} className="text-xs text-slate-400 hover:text-slate-900 dark:hover:text-white">Tutup</button>
            </div>
            <div className="space-y-2 px-4 py-3 text-sm">
              <div>
                <p className="text-xs text-slate-400">Judul</p>
                <p className="font-medium">{viewItem.title}</p>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <p className="text-xs text-slate-400">Mulai</p>
                  <p>{new Date(viewItem.start_at).toLocaleString('id-ID')}</p>
                </div>
                <div>
                  <p className="text-xs text-slate-400">Durasi</p>
                  <p>{viewItem.durasi_jam ? `${viewItem.durasi_jam} jam` : '-'}</p>
                </div>
              </div>
              <div>
                <p className="text-xs text-slate-400">Indikator Kinerja</p>
                <p>{(viewItem.performance_plans?.length ? viewItem.performance_plans : (viewItem.performance_plan ? [viewItem.performance_plan] : [])).map((plan) => plan.indikator_kinerja || plan.name).join(', ') || '-'}</p>
              </div>
              <div>
                <p className="text-xs text-slate-400">Status</p>
                <p>{viewItem.done ? 'Selesai' : 'Aktif'}</p>
              </div>
              {(viewItem.description || todoDetail?.notes) && (
                <div>
                  <p className="text-xs text-slate-400">Catatan</p>
                  <p className="whitespace-pre-wrap text-slate-600 dark:text-slate-300">{viewItem.description || todoDetail?.notes}</p>
                </div>
              )}
              {(viewItem.tindak_lanjut || todoDetail?.tindak_lanjut) && (
                <div>
                  <p className="text-xs text-slate-400">Tindak Lanjut / Hasil</p>
                  <p className="whitespace-pre-wrap text-slate-600 dark:text-slate-300">{viewItem.tindak_lanjut || todoDetail?.tindak_lanjut}</p>
                </div>
              )}
              {(() => {
                const files = viewItem.media_items?.length ? viewItem.media_items : (viewItem.media ? [viewItem.media] : (todoDetail?.media ? [todoDetail.media] : []));
                return files.length > 0 ? (
                  <div>
                    <p className="text-xs text-slate-400">Bukti Fisik</p>
                    <ul className="space-y-1">
                      {files.map((m) => (
                        <li key={m.id}>
                          <a href={mediaSrc(`/api/media/${m.id}`)} target="_blank" rel="noreferrer" className="font-medium text-brand underline dark:text-skyblue">
                            {m.filename}
                          </a>
                        </li>
                      ))}
                    </ul>
                  </div>
                ) : null;
              })()}
              {loadingView && <p className="text-xs text-slate-400">Memuat detail todolist...</p>}
              {todoDetail && (
                <div>
                  <p className="text-xs text-slate-400">Terkait TodoList</p>
                  <p>#{todoDetail.id} - {todoDetail.title} {todoDetail.due_at ? `(${new Date(todoDetail.due_at).toLocaleString('id-ID')})` : ''}</p>
                </div>
              )}
            </div>
            <div className="flex items-center justify-end gap-2 border-t border-slate-200 px-4 py-2 dark:border-slate-700">
              <button type="button" onClick={() => { setViewItem(null); setTodoDetail(null); startEdit(viewItem); }} className="rounded-lg px-3 py-1.5 text-xs font-medium hover:bg-slate-50 dark:hover:bg-brand-800">Edit</button>
              <button type="button" onClick={() => { setViewItem(null); setTodoDetail(null); }} className="rounded-lg px-3 py-1.5 text-xs">Tutup</button>
            </div>
          </div>
        </div>
      )}

      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <form onSubmit={submit} className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold">{editingId ? 'Edit acara' : 'Tambah acara'}</h3>
            {editingId && (
              <button type="button" onClick={resetForm} className="text-xs font-medium text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">Batal</button>
            )}
          </div>
          <div className="mt-3 space-y-2">
            <input value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} placeholder="Judul acara..." maxLength={255} className={`w-full ${input}`} />
            <div className="flex flex-wrap gap-2">
              <input type="date" value={form.date} onChange={(e) => setForm((f) => ({ ...f, date: e.target.value }))} aria-label="Tanggal" className={`min-w-0 flex-1 ${input}`} />
              <input type="time" value={form.time} onChange={(e) => setForm((f) => ({ ...f, time: e.target.value }))} aria-label="Jam mulai" className={input} />
              <input type="number" min="0.25" max="24" step="0.25" value={form.duration} onChange={(e) => setForm((f) => ({ ...f, duration: e.target.value }))} aria-label="Durasi jam" placeholder="Jam" className={`w-24 ${input}`} />
              <select value={form.recurrence} onChange={(e) => setForm((f) => ({ ...f, recurrence: e.target.value }))} aria-label="Pengulangan" className={input}>
                {RECURRENCE.map((r) => (
                  <option key={r.value} value={r.value}>{r.label}</option>
                ))}
              </select>
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
              <MultiSelect
                options={(options.plans || []).map((p) => ({
                  value: String(p.id),
                  label: [p.indikator_kinerja || p.name, p.satuan ? `(${p.satuan})` : null].filter(Boolean).join(' '),
                }))}
                value={Array.isArray(form.performancePlanIds) ? form.performancePlanIds : (form.performancePlanId ? [String(form.performancePlanId)] : [])}
                onChange={(vals) => setForm((f) => ({ ...f, performancePlanIds: vals, performancePlanId: vals && vals.length ? vals[0] : '' }))}
                placeholder="Pilih Indikator Kinerja..."
              />
              <div
                onDragOver={(e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; }}
                onDrop={async (e) => {
                  e.preventDefault();
                  try {
                    await uploadFiles(e.dataTransfer.files);
                  } catch (err) {
                    setError(err.message);
                  }
                }}
                className="rounded-2xl border-2 border-dashed border-slate-300 px-3 py-2 text-center transition hover:border-skyblue dark:border-slate-700 dark:hover:border-skyblue"
              >
                <p className="text-[11px] text-slate-500">Seret &amp; lepas file atau pilih beberapa</p>
                <input
                  type="file"
                  multiple
                  onChange={async (e) => {
                    try {
                      await uploadFiles(e.target.files);
                    } catch (err) {
                      setError(err.message);
                    } finally {
                      e.target.value = '';
                    }
                  }}
                  className="mt-1 block w-full text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-gradient-to-r file:from-skyblue/80 file:to-cyan-400/80 file:px-2.5 file:py-1 file:text-[11px] file:font-medium file:text-white hover:file:opacity-90 dark:text-slate-400"
                />
                {(form.mediaItems || []).length > 0 && (
                  <ul className="mt-1 space-y-1 text-left">
                    {form.mediaItems.map((m) => (
                      <li key={m.id} className="flex items-center gap-2 text-[11px] text-emerald-600 dark:text-emerald-300">
                        <a href={mediaSrc(`/api/media/${m.id}`)} target="_blank" rel="noreferrer" className="truncate underline">{m.filename}</a>
                        <button
                          type="button"
                          aria-label={`Hapus lampiran ${m.filename}`}
                          onClick={() => setForm((f) => ({ ...f, mediaItems: (f.mediaItems || []).filter((x) => x.id !== m.id) }))}
                          className="shrink-0 text-slate-400 hover:text-blush"
                        >
                          ×
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </div>
            <textarea value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} placeholder="Catatan (opsional)" rows={2} className={`w-full resize-none ${input}`} />
            <input value={form.tindakLanjut} onChange={(e) => setForm((f) => ({ ...f, tindakLanjut: e.target.value }))} placeholder="Tindak lanjut / hasil (opsional)" maxLength={2000} className={`w-full ${input}`} />
            <div className="flex flex-wrap gap-3">
              <label className="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                <input type="checkbox" checked={form.syncTodo} onChange={(e) => setForm((f) => ({ ...f, syncTodo: e.target.checked }))} className="h-4 w-4 rounded border-slate-300 text-brand focus:ring-brand dark:border-slate-600" />
                Sinkronkan dengan Todo
              </label>
              {editingId && form.syncTodo && (
                <label className="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                  <input type="checkbox" checked={form.done} onChange={(e) => setForm((f) => ({ ...f, done: e.target.checked }))} className="h-4 w-4 rounded border-slate-300 text-emerald-500 focus:ring-emerald-500 dark:border-slate-600" />
                  Tandai selesai
                </label>
              )}
            </div>
            <button type="submit" disabled={!form.title.trim()} className="w-full rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100">
              {editingId ? 'Simpan perubahan' : 'Tambah acara'}
            </button>
          </div>
        </form>

        <div className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
          <div className="flex items-center justify-between gap-2">
            <h3 className="text-sm font-semibold">{fmtDay(new Date(`${selectedKey}T00:00:00`))}</h3>
            <button type="button" onClick={() => setForm((f) => ({ ...f, date: selectedKey }))} className="text-xs font-medium text-brand transition hover:text-brand-500 dark:text-skyblue">
              Pakai tanggal ini
            </button>
          </div>
          {holidayFor(selectedKey) && <p className="mt-1 text-xs font-medium text-red-500">{holidayFor(selectedKey)}</p>}
          {selectedEvents.length === 0 ? (
            <p className="mt-4 text-center text-sm text-slate-400 dark:text-slate-500">Tidak ada acara.</p>
          ) : (
            <ul className="mt-3 space-y-2">
              {selectedEvents.map((occ) => (
                <li key={occ.key} className="flex items-start justify-between gap-2 rounded-xl bg-cream px-3 py-2.5 dark:bg-brand-800">
                <button type="button" onClick={() => openView(occ.event)} className="min-w-0 flex-1 text-left">
                  <p className="truncate text-sm font-medium">
                    {occ.event.done ? '✓ ' : ''}{fmtTime(occ.start)} · {occ.event.title}
                    {REC_LABEL[occ.event.recurrence] ? <span className="ml-2 text-[10px] font-normal text-slate-400">{REC_LABEL[occ.event.recurrence]}</span> : null}
                  </p>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                      {(occ.event.performance_plans?.length ? occ.event.performance_plans : (occ.event.performance_plan ? [occ.event.performance_plan] : [])).map((plan) => (
                        <span key={plan.id} className="rounded-full bg-skyblue/20 px-2 py-0.5 text-[10px] font-medium text-brand-700 dark:bg-brand-800 dark:text-skyblue">{plan.indikator_kinerja || plan.name}</span>
                      ))}
                      {occ.event.done && (
                        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Selesai</span>
                      )}
                      {occ.event.media && <span className="text-[10px] text-slate-400">Bukti: {occ.event.media.filename}{occ.event.media_items?.length > 1 ? ` +${occ.event.media_items.length - 1}` : ''}</span>}
                      {occ.event.durasi_jam ? <span className="text-[10px] text-slate-400">{occ.event.durasi_jam} jam</span> : null}
                    </div>
                    {occ.event.tindak_lanjut && <p className="mt-0.5 whitespace-pre-wrap text-xs text-slate-500 dark:text-slate-400">{occ.event.tindak_lanjut}</p>}
                    {occ.event.description && <p className="mt-0.5 whitespace-pre-wrap text-xs text-slate-500 dark:text-slate-400">{occ.event.description}</p>}
                  </button>
                  <div className="flex shrink-0 items-center">
                    <button type="button" aria-label="Lihat detail" onClick={() => openView(occ.event)} className="rounded-md p-1 text-slate-400 transition hover:text-brand dark:hover:text-skyblue">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                        <circle cx="12" cy="12" r="3" />
                      </svg>
                    </button>
                    <button type="button" aria-label="Edit acara" onClick={() => startEdit(occ.event)} className="rounded-md p-1 text-slate-400 transition hover:text-brand dark:hover:text-skyblue">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                        <path d="M12 20h9" />
                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z" />
                      </svg>
                    </button>
                    <button type="button" aria-label="Hapus acara" onClick={() => remove(occ.event)} className="rounded-md p-1 text-slate-400 transition hover:text-blush">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                        <path d="M3 6h18" />
                        <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                      </svg>
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  );
}

export { CalendarIcon };
