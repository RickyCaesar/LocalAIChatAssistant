import { useEffect, useMemo, useState } from 'react';
import { api } from './api.js';

const DAY_NAMES = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const MONTH_NAMES = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

const dayKey = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

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

const fmt = (iso) =>
  iso
    ? new Date(iso).toLocaleString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
    : '';

const fmtTime = (iso) => (iso ? new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : '');

export default function CalendarPage() {
  const [events, setEvents] = useState([]);
  const [cursor, setCursor] = useState(() => new Date());
  const [selectedKey, setSelectedKey] = useState(() => dayKey(new Date()));
  const [form, setForm] = useState({ title: '', date: dayKey(new Date()), time: '09:00', description: '' });
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

  const byDay = useMemo(() => {
    const map = {};
    for (const ev of events) {
      const k = dayKey(new Date(ev.start_at));
      (map[k] = map[k] || []).push(ev);
    }
    for (const k of Object.keys(map)) map[k].sort((a, b) => a.start_at.localeCompare(b.start_at));
    return map;
  }, [events]);

  const y = cursor.getFullYear();
  const m = cursor.getMonth();
  const first = new Date(y, m, 1);
  const daysInMonth = new Date(y, m + 1, 0).getDate();
  const leading = first.getDay();
  const cells = [...Array(leading).fill(null), ...Array(daysInMonth).fill(null).map((_, i) => i + 1)];
  const todayKey = dayKey(new Date());

  const add = async (e) => {
    e.preventDefault();
    if (!form.title.trim()) return;
    try {
      const res = await api('/api/events', {
        method: 'POST',
        body: JSON.stringify({ title: form.title, start_at: `${form.date} ${form.time}:00`, description: form.description || null }),
      });
      setEvents((prev) => [...prev, res.event]);
      setForm((f) => ({ ...f, title: '', description: '' }));
    } catch (e2) {
      setError(e2.message);
    }
  };

  const remove = async (ev) => {
    if (!window.confirm(`Hapus acara "${ev.title}"?`)) return;
    try {
      await api(`/api/events/${ev.id}`, { method: 'DELETE' });
      setEvents((prev) => prev.filter((x) => x.id !== ev.id));
    } catch (e2) {
      setError(e2.message);
    }
  };

  const selectedEvents = byDay[selectedKey] || [];

  return (
    <div className="mx-auto max-w-5xl px-4 py-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Kalender</h2>
          <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            Agenda & jadwal. Bisa dikelola lewat chat dengan Hermes.
          </p>
        </div>
        <div className="flex items-center gap-1 rounded-xl bg-cream p-1 dark:bg-brand-900">
          <button
            type="button"
            onClick={() => setCursor(new Date(y, m - 1, 1))}
            className="rounded-lg px-3 py-1.5 text-sm font-medium transition hover:bg-white dark:hover:bg-brand-600"
          >
            ‹
          </button>
          <span className="px-2 text-sm font-semibold">
            {MONTH_NAMES[m]} {y}
          </span>
          <button
            type="button"
            onClick={() => setCursor(new Date(y, m + 1, 1))}
            className="rounded-lg px-3 py-1.5 text-sm font-medium transition hover:bg-white dark:hover:bg-brand-600"
          >
            ›
          </button>
          <button
            type="button"
            onClick={() => {
              setCursor(new Date());
              setSelectedKey(todayKey);
            }}
            className="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-500 transition hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
          >
            Hari ini
          </button>
        </div>
      </div>

      {error && <p className="mt-4 text-sm text-red-500">{error}</p>}

      {loading ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">Loading...</p>
      ) : (
        <div className="mt-4 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
          <div className="grid grid-cols-7 border-b border-slate-200 dark:border-slate-700">
            {DAY_NAMES.map((d) => (
              <div key={d} className="px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                {d}
              </div>
            ))}
          </div>
          <div className="grid grid-cols-7">
            {cells.map((day, i) => {
              if (day === null) return <div key={`x${i}`} className="min-h-24 border-b border-r border-slate-100 dark:border-slate-800" />;
              const key = `${y}-${String(m + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
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
                      key === todayKey ? 'bg-brand text-white' : key === selectedKey ? 'bg-skyblue/30 text-brand-700 dark:bg-brand-800 dark:text-skyblue' : ''
                    }`}
                  >
                    {day}
                  </span>
                  <div className="mt-1 space-y-1">
                    {dayEvents.slice(0, 3).map((ev) => (
                      <div key={ev.id} className="truncate rounded-md bg-skyblue/30 px-1.5 py-0.5 text-[10px] font-medium text-brand-700 dark:bg-brand-800 dark:text-skyblue">
                        {fmtTime(ev.start_at)} {ev.title}
                      </div>
                    ))}
                    {dayEvents.length > 3 && (
                      <div className="px-1.5 text-[10px] text-slate-400">+{dayEvents.length - 3} lagi</div>
                    )}
                  </div>
                </button>
              );
            })}
          </div>
        </div>
      )}

      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <form onSubmit={add} className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
          <h3 className="text-sm font-semibold">Tambah acara</h3>
          <div className="mt-3 space-y-2">
            <input
              value={form.title}
              onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
              placeholder="Judul acara..."
              maxLength={255}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
            />
            <div className="flex gap-2">
              <input
                type="date"
                value={form.date}
                onChange={(e) => setForm((f) => ({ ...f, date: e.target.value }))}
                className="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none dark:border-slate-700 dark:bg-brand-800"
              />
              <input
                type="time"
                value={form.time}
                onChange={(e) => setForm((f) => ({ ...f, time: e.target.value }))}
                className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none dark:border-slate-700 dark:bg-brand-800"
              />
            </div>
            <textarea
              value={form.description}
              onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
              placeholder="Catatan (opsional)"
              rows={2}
              className="w-full resize-none rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
            />
            <button
              type="submit"
              disabled={!form.title.trim()}
              className="w-full rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
            >
              Tambah acara
            </button>
          </div>
        </form>

        <div className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
          <div className="flex items-center justify-between gap-2">
            <h3 className="text-sm font-semibold">{fmt(new Date(`${selectedKey}T00:00:00`))}</h3>
            <button
              type="button"
              onClick={() => setForm((f) => ({ ...f, date: selectedKey }))}
              className="text-xs font-medium text-brand transition hover:text-brand-500 dark:text-skyblue"
            >
              Pakai tanggal ini
            </button>
          </div>
          {selectedEvents.length === 0 ? (
            <p className="mt-4 text-center text-sm text-slate-400 dark:text-slate-500">Tidak ada acara.</p>
          ) : (
            <ul className="mt-3 space-y-2">
              {selectedEvents.map((ev) => (
                <li key={ev.id} className="flex items-start justify-between gap-2 rounded-xl bg-cream px-3 py-2.5 dark:bg-brand-800">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium">
                      {fmtTime(ev.start_at)} · {ev.title}
                    </p>
                    {ev.description && <p className="mt-0.5 whitespace-pre-wrap text-xs text-slate-500 dark:text-slate-400">{ev.description}</p>}
                  </div>
                  <button
                    type="button"
                    aria-label="Hapus acara"
                    onClick={() => remove(ev)}
                    className="shrink-0 rounded-md p-1 text-slate-400 transition hover:text-blush"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                      <path d="M3 6h18" />
                      <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                      <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                    </svg>
                  </button>
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