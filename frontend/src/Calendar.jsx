import { useEffect, useMemo, useState } from 'react';
import { api } from './api.js';
import { holidaysForYear } from './holidays.js';

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

export default function CalendarPage() {
  const [events, setEvents] = useState([]);
  const [cursor, setCursor] = useState(() => new Date());
  const [view, setView] = useState('bulan');
  const [selectedKey, setSelectedKey] = useState(() => dayKey(new Date()));
  const [form, setForm] = useState({ title: '', date: dayKey(new Date()), time: '09:00', description: '', recurrence: 'none' });
  const [editingId, setEditingId] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = () => {
    setError(null);
    api('/api/events')
      .then((data) => setEvents(data.events))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

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

  const byDay = useMemo(() => {
    const map = {};
    for (const occ of expand(events, visibleFrom, visibleTo)) {
      const k = dayKey(occ.start);
      (map[k] = map[k] || []).push(occ);
    }
    for (const k of Object.keys(map)) map[k].sort((a, b) => a.start - b.start);
    return map;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [events, view, y, m, weekStart.getTime()]);

  const agenda = useMemo(() => expand(events, today, addDays(today, 120)).sort((a, b) => a.start - b.start), [events, today.getTime()]);

  const selectedEvents = useMemo(() => {
    const from = new Date(`${selectedKey}T00:00:00`);
    return expand(events, from, addDays(from, 1)).sort((a, b) => a.start - b.start);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [events, selectedKey]);

  const resetForm = () => {
    setForm({ title: '', date: selectedKey, time: '09:00', description: '', recurrence: 'none' });
    setEditingId(null);
  };

  const submit = async (e) => {
    e.preventDefault();
    if (!form.title.trim()) return;
    const body = JSON.stringify({
      title: form.title,
      start_at: new Date(`${form.date}T${form.time}`).toISOString(),
      description: form.description || null,
      recurrence: form.recurrence,
    });
    try {
      if (editingId) {
        const res = await api(`/api/events/${editingId}`, { method: 'PUT', body });
        setEvents((prev) => prev.map((x) => (x.id === editingId ? res.event : x)));
      } else {
        const res = await api('/api/events', { method: 'POST', body });
        setEvents((prev) => [...prev, res.event]);
      }
      resetForm();
    } catch (e2) {
      setError(e2.message);
    }
  };

  const startEdit = (ev) => {
    const d = new Date(ev.start_at);
    setEditingId(ev.id);
    setForm({
      title: ev.title,
      date: dayKey(d),
      time: `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`,
      description: ev.description || '',
      recurrence: ev.recurrence || 'none',
    });
  };

  const remove = async (ev) => {
    const msg = ev.recurrence && ev.recurrence !== 'none' ? `Hapus seluruh seri "${ev.title}"?` : `Hapus acara "${ev.title}"?`;
    if (!window.confirm(msg)) return;
    try {
      await api(`/api/events/${ev.id}`, { method: 'DELETE' });
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
          <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Agenda, jadwal & hari libur Indonesia. Bisa dikelola lewat chat dengan Hermes.</p>
        </div>
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
                          <div key={occ.key} className="truncate rounded-md bg-skyblue/30 px-1.5 py-0.5 text-[10px] font-medium text-brand-700 dark:bg-brand-800 dark:text-skyblue">
                            {fmtTime(occ.start)} {occ.event.title}
                          </div>
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
                  <div key={key} className={`min-h-40 rounded-2xl bg-white p-2 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700 ${key === todayKey ? 'ring-2 ring-brand dark:ring-skyblue' : ''}`}>
                    <div className={`text-xs font-semibold ${holiday || d.getDay() === 0 ? 'text-red-500' : ''}`}>
                      {DAY_NAMES[d.getDay()]} {d.getDate()}
                    </div>
                    {holiday && <p className="mt-0.5 text-[10px] font-medium text-red-500">{holiday}</p>}
                    <div className="mt-1.5 space-y-1">
                      {dayEvents.map((occ) => (
                        <button
                          key={occ.key}
                          type="button"
                          onClick={() => { setSelectedKey(key); setView('bulan'); setCursor(new Date(d.getFullYear(), d.getMonth(), 1)); }}
                          className="block w-full truncate rounded-md bg-skyblue/30 px-1.5 py-1 text-left text-[10px] font-medium text-brand-700 dark:bg-brand-800 dark:text-skyblue"
                        >
                          {fmtTime(occ.start)} {occ.event.title}
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
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium">
                            {fmtTime(occ.start)} · {occ.event.title}
                            {REC_LABEL[occ.event.recurrence] ? <span className="ml-2 text-[10px] font-normal text-slate-400">{REC_LABEL[occ.event.recurrence]}</span> : null}
                          </p>
                          {holiday && <p className="text-[11px] font-medium text-red-500">{holiday}</p>}
                          {occ.event.description && <p className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">{occ.event.description}</p>}
                        </div>
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
              <input type="date" value={form.date} onChange={(e) => setForm((f) => ({ ...f, date: e.target.value }))} className={`min-w-0 flex-1 ${input}`} />
              <input type="time" value={form.time} onChange={(e) => setForm((f) => ({ ...f, time: e.target.value }))} className={input} />
              <select value={form.recurrence} onChange={(e) => setForm((f) => ({ ...f, recurrence: e.target.value }))} className={input}>
                {RECURRENCE.map((r) => (
                  <option key={r.value} value={r.value}>{r.label}</option>
                ))}
              </select>
            </div>
            <textarea value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} placeholder="Catatan (opsional)" rows={2} className={`w-full resize-none ${input}`} />
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
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium">
                      {fmtTime(occ.start)} · {occ.event.title}
                      {REC_LABEL[occ.event.recurrence] ? <span className="ml-2 text-[10px] font-normal text-slate-400">{REC_LABEL[occ.event.recurrence]}</span> : null}
                    </p>
                    {occ.event.description && <p className="mt-0.5 whitespace-pre-wrap text-xs text-slate-500 dark:text-slate-400">{occ.event.description}</p>}
                  </div>
                  <div className="flex shrink-0 items-center">
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
