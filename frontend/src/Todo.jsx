import { useEffect, useState } from 'react';
import { api, mediaSrc } from './api.js';

const PRIORITIES = [
  { value: 'tinggi', label: 'Tinggi' },
  { value: 'sedang', label: 'Sedang' },
  { value: 'rendah', label: 'Rendah' },
];

const PRIORITY_CLS = {
  tinggi: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300',
  sedang: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
  rendah: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
};

const toInput = (iso) => {
  if (!iso) return '';
  const d = new Date(iso);
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
};

const fmtDue = (iso) =>
  iso
    ? new Date(iso).toLocaleString('id-ID', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
    : '';

const input =
  'rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue';

export function TodoIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <rect x="3" y="5" width="6" height="6" rx="1" />
      <path d="m3 17 2 2 4-4" />
      <path d="M13 6h8" />
      <path d="M13 12h8" />
      <path d="M13 18h8" />
    </svg>
  );
}

const blank = {
  title: '',
  dueAt: '',
  priority: 'sedang',
  notes: '',
  durasiJam: '',
  performancePlanId: '',
  mediaId: '',
  tindakLanjut: '',
  syncCalendar: false,
};

const payload = (form, extra = {}) => ({
  title: form.title.trim(),
  due_at: form.dueAt ? new Date(form.dueAt).toISOString() : null,
  priority: form.priority,
  notes: form.notes.trim() || null,
  durasi_jam: form.durasiJam === '' ? null : Number(form.durasiJam),
  performance_plan_id: form.performancePlanId || null,
  media_id: form.mediaId || null,
  tindak_lanjut: form.tindakLanjut.trim() || null,
  sync_calendar: Boolean(form.syncCalendar),
  ...extra,
});

export default function TodoList() {
  const [todos, setTodos] = useState([]);
  const [form, setForm] = useState(blank);
  const [filter, setFilter] = useState('semua');
  const [editing, setEditing] = useState(null);
  const [options, setOptions] = useState({ plans: [], media: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = () => {
    setError(null);
    api('/api/todos')
      .then((data) => setTodos(data.todos))
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

  const add = async (e) => {
    e.preventDefault();
    if (!form.title.trim()) return;
    try {
      const res = await api('/api/todos', { method: 'POST', body: JSON.stringify(payload(form)) });
      setTodos((prev) => [...prev, res.todo]);
      setForm(blank);
    } catch (e2) {
      setError(e2.message);
    }
  };

  const toggle = async (todo) => {
    try {
      const res = todo.done
        ? await api(`/api/todos/${todo.id}`, { method: 'PUT', body: JSON.stringify({ done: false }) })
        : await api(`/api/todos/${todo.id}/complete`, { method: 'POST', body: JSON.stringify({}) });
      setTodos((prev) => prev.map((t) => (t.id === todo.id ? res.todo : t)));
    } catch (e) {
      setError(e.message);
    }
  };

  const openEdit = (todo) => {
    setEditing({
      id: todo.id,
      title: todo.title,
      dueAt: toInput(todo.due_at),
      priority: todo.priority || 'sedang',
      notes: todo.notes || '',
      durasiJam: todo.durasi_jam ?? '',
      performancePlanId: todo.performance_plan_id ?? '',
      mediaId: todo.media_id ?? '',
      tindakLanjut: todo.tindak_lanjut || '',
      syncCalendar: Boolean(todo.event_id),
    });
  };

  const saveEdit = async (e) => {
    e.preventDefault();
    if (!editing.title.trim()) return;
    try {
      const res = await api(`/api/todos/${editing.id}`, { method: 'PUT', body: JSON.stringify(payload(editing)) });
      setTodos((prev) => prev.map((x) => (x.id === editing.id ? res.todo : x)));
      setEditing(null);
    } catch (e2) {
      setError(e2.message);
    }
  };

  const remove = async (todo) => {
    if (!window.confirm('Hapus todo ini? Acara kalender yang tertaut juga dihapus.')) return;
    try {
      await api(`/api/todos/${todo.id}`, { method: 'DELETE' });
      setTodos((prev) => prev.filter((t) => t.id !== todo.id));
    } catch (e) {
      setError(e.message);
    }
  };

  const open = todos.filter((t) => !t.done).length;
  const overdue = todos.filter((t) => !t.done && t.due_at && new Date(t.due_at) < new Date());

  const shown = todos.filter((t) => (filter === 'aktif' ? !t.done : filter === 'selesai' ? t.done : true));

  const tabs = [
    { key: 'semua', label: `Semua (${todos.length})` },
    { key: 'aktif', label: `Aktif (${open})` },
    { key: 'selesai', label: `Selesai (${todos.length - open})` },
  ];

  const metaFields = (value, set) => (
    <>
      <div className="grid gap-2 sm:grid-cols-2">
        <select
          aria-label="Indikator e-SAKIP"
          value={value.performancePlanId}
          onChange={(e) => set((f) => ({ ...f, performancePlanId: e.target.value }))}
          className={input}
        >
          <option value="">Indikator e-SAKIP...</option>
          {options.plans.map((p) => (
            <option key={p.id} value={p.id}>{p.name}</option>
          ))}
        </select>
        <input
          type="number"
          min="0.25"
          max="24"
          step="0.25"
          value={value.durasiJam}
          onChange={(e) => set((f) => ({ ...f, durasiJam: e.target.value }))}
          placeholder="Durasi (jam)"
          aria-label="Durasi jam"
          className={input}
        />
        <div className="flex flex-wrap items-center gap-2 sm:col-span-2">
          <input
            type="file"
            onChange={async (e) => {
              const file = e.target.files?.[0];
              if (!file) return;
              setError?.(null);
              const fd = new FormData();
              fd.append('image', file);
              try {
                const res = await api('/api/media', { method: 'POST', body: fd });
                set((f) => ({ ...f, mediaId: res.id }));
              } catch (err) {
                setError?.(err.message);
              } finally {
                e.target.value = '';
              }
            }}
            className="block w-full text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-slate-700 hover:file:bg-slate-200 dark:text-slate-400 dark:file:bg-brand-800 dark:file:text-slate-200"
          />
          {value.mediaId && <span className="text-[11px] text-emerald-600 dark:text-emerald-300">File terlampir</span>}
        </div>
      </div>
      <input
        value={value.tindakLanjut}
        onChange={(e) => set((f) => ({ ...f, tindakLanjut: e.target.value }))}
        placeholder="Tindak lanjut / hasil (opsional)"
        maxLength={2000}
        className={`w-full ${input}`}
      />
      <label className={`flex items-center gap-2 text-xs ${!value.dueAt ? 'text-slate-400 dark:text-slate-500' : 'text-slate-600 dark:text-slate-300'}`}>
        <input
          type="checkbox"
          checked={value.syncCalendar}
          disabled={!value.dueAt}
          onChange={(e) => set((f) => ({ ...f, syncCalendar: e.target.checked }))}
          className="h-4 w-4 rounded border-slate-300 text-brand focus:ring-brand disabled:opacity-40 dark:border-slate-600"
        />
        Tampilkan di Kalender
      </label>
    </>
  );

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      <h2 className="text-lg font-semibold">Todo List</h2>
      <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Tugas dan kegiatan e-SAKIP. Bisa dikelola lewat chat dengan Hermes.</p>

      {error && <p className="mt-4 text-sm text-red-500">{error}</p>}

      <form onSubmit={add} className="mt-4 space-y-2">
        <div className="flex flex-wrap items-center gap-2">
          <input
            value={form.title}
            onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
            placeholder="Tambah tugas..."
            maxLength={255}
            className={`min-w-0 flex-1 basis-full sm:basis-auto ${input}`}
          />
          <input
            type="datetime-local"
            value={form.dueAt}
            onChange={(e) => setForm((f) => ({ ...f, dueAt: e.target.value, syncCalendar: e.target.value ? f.syncCalendar : false }))}
            aria-label="Jatuh tempo"
            className={input}
          />
          <select value={form.priority} onChange={(e) => setForm((f) => ({ ...f, priority: e.target.value }))} aria-label="Prioritas" className={input}>
            {PRIORITIES.map((p) => (
              <option key={p.value} value={p.value}>{p.label}</option>
            ))}
          </select>
          <button
            type="submit"
            disabled={!form.title.trim()}
            className="rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
          >
            Tambah
          </button>
        </div>
        <input
          value={form.notes}
          onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))}
          placeholder="Catatan (opsional)"
          maxLength={2000}
          className={`w-full ${input}`}
        />
        {metaFields(form, setForm)}
      </form>

      <div className="mt-4 flex items-center gap-1 rounded-xl bg-cream p-1 dark:bg-brand-900">
        {tabs.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setFilter(t.key)}
            className={`rounded-lg px-3 py-1.5 text-xs font-medium transition ${
              filter === t.key ? 'bg-white shadow-sm dark:bg-brand-600' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'
            }`}
          >
            {t.label}
          </button>
        ))}
        {overdue.length > 0 && <span className="ml-auto pr-2 text-xs font-medium text-red-500">{overdue.length} terlambat</span>}
      </div>

      {loading ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">Loading...</p>
      ) : shown.length === 0 ? (
        <p className="mt-16 text-center text-sm text-slate-400 dark:text-slate-500">
          {filter === 'semua' ? 'Belum ada tugas. Tambahkan di atas, atau minta Hermes mencatatnya lewat chat.' : 'Tidak ada tugas di filter ini.'}
        </p>
      ) : (
        <ul className="mt-4 space-y-2">
          {shown.map((todo) =>
            editing && editing.id === todo.id ? (
              <li key={todo.id} className="rounded-2xl bg-white p-3 shadow-sm ring-2 ring-brand dark:bg-brand-900 dark:ring-skyblue">
                <form onSubmit={saveEdit} className="space-y-2">
                  <input value={editing.title} onChange={(e) => setEditing((s) => ({ ...s, title: e.target.value }))} maxLength={255} className={`w-full ${input}`} autoFocus />
                  <div className="flex flex-wrap gap-2">
                    <input
                      type="datetime-local"
                      value={editing.dueAt}
                      onChange={(e) => setEditing((s) => ({ ...s, dueAt: e.target.value, syncCalendar: e.target.value ? s.syncCalendar : false }))}
                      aria-label="Jatuh tempo"
                      className={input}
                    />
                    <select value={editing.priority} onChange={(e) => setEditing((s) => ({ ...s, priority: e.target.value }))} aria-label="Prioritas" className={input}>
                      {PRIORITIES.map((p) => (
                        <option key={p.value} value={p.value}>{p.label}</option>
                      ))}
                    </select>
                  </div>
                  <input value={editing.notes} onChange={(e) => setEditing((s) => ({ ...s, notes: e.target.value }))} placeholder="Catatan (opsional)" maxLength={2000} className={`w-full ${input}`} />
                  {metaFields(editing, setEditing)}
                  <div className="flex gap-2">
                    <button type="submit" disabled={!editing.title.trim()} className="rounded-lg bg-brand px-3 py-1.5 text-xs font-medium text-white disabled:opacity-40 dark:bg-white dark:text-slate-900">Simpan</button>
                    <button type="button" onClick={() => setEditing(null)} className="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">Batal</button>
                  </div>
                </form>
              </li>
            ) : (
              <li
                key={todo.id}
                className={`flex items-start gap-3 rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700 ${
                  todo.done ? 'opacity-60' : ''
                }`}
              >
                <button
                  type="button"
                  aria-label={todo.done ? 'Tandai belum selesai' : 'Tandai selesai'}
                  onClick={() => toggle(todo)}
                  className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md border transition ${
                    todo.done ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 text-transparent hover:border-emerald-500 dark:border-slate-600'
                  }`}
                >
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" className="h-3 w-3">
                    <polyline points="20 6 9 17 4 12" />
                  </svg>
                </button>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <span className={`min-w-0 flex-1 truncate text-sm ${todo.done ? 'line-through' : ''}`}>{todo.title}</span>
                    <span className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ${PRIORITY_CLS[todo.priority] || PRIORITY_CLS.sedang}`}>
                      {todo.priority || 'sedang'}
                    </span>
                  </div>
                  {todo.notes && <p className="mt-0.5 whitespace-pre-wrap text-xs text-slate-500 dark:text-slate-400">{todo.notes}</p>}
                  <div className="mt-1 flex flex-wrap items-center gap-1.5">
                    {todo.performance_plan && (
                      <span className="rounded-full bg-skyblue/20 px-2 py-0.5 text-[10px] font-medium text-brand-700 dark:bg-brand-800 dark:text-skyblue">
                        {todo.performance_plan.name}
                      </span>
                    )}
                    {todo.event_id && (
                      <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                        Kalender
                      </span>
                    )}
                    {todo.durasi_jam !== null && todo.durasi_jam !== undefined && (
                      <span className="text-[10px] text-slate-400">{todo.durasi_jam} jam</span>
                    )}
                    {todo.media && (
                      <a
                        href={mediaSrc(`/api/media/${todo.media.id}`)}
                        target="_blank"
                        rel="noreferrer"
                        className="max-w-[12rem] truncate text-[10px] font-medium text-brand underline dark:text-skyblue"
                      >
                        {todo.media.filename}
                      </a>
                    )}
                  </div>
                  {todo.tindak_lanjut && <p className="mt-0.5 whitespace-pre-wrap text-xs text-slate-500 dark:text-slate-400">Hasil: {todo.tindak_lanjut}</p>}
                  {todo.due_at && (
                    <p className={`mt-0.5 text-xs ${todo.done || new Date(todo.due_at) >= new Date() ? 'text-slate-400 dark:text-slate-500' : 'font-medium text-red-500'}`}>
                      {fmtDue(todo.due_at)}
                    </p>
                  )}
                </div>
                <button
                  type="button"
                  aria-label="Edit todo"
                  onClick={() => openEdit(todo)}
                  className="shrink-0 rounded-md p-1 text-slate-400 transition hover:text-brand dark:hover:text-skyblue"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                    <path d="M12 20h9" />
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z" />
                  </svg>
                </button>
                <button
                  type="button"
                  aria-label="Hapus todo"
                  onClick={() => remove(todo)}
                  className="shrink-0 rounded-md p-1 text-slate-400 transition hover:text-blush"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                    <path d="M3 6h18" />
                    <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                  </svg>
                </button>
              </li>
            )
          )}
        </ul>
      )}
    </div>
  );
}
