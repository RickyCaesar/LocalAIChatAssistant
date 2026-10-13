import { useEffect, useState } from 'react';
import { api } from './api.js';

const fmtDate = (iso) =>
  iso ? new Date(iso).toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short' }) : '';

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

export default function TodoList() {
  const [todos, setTodos] = useState([]);
  const [title, setTitle] = useState('');
  const [dueAt, setDueAt] = useState('');
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

  const add = async (e) => {
    e.preventDefault();
    const t = title.trim();
    if (!t) return;
    try {
      const res = await api('/api/todos', {
        method: 'POST',
        body: JSON.stringify({ title: t, due_at: dueAt || null }),
      });
      setTodos((prev) => [...prev, res.todo]);
      setTitle('');
      setDueAt('');
    } catch (e2) {
      setError(e2.message);
    }
  };

  const toggle = async (todo) => {
    try {
      const res = await api(`/api/todos/${todo.id}`, {
        method: 'PUT',
        body: JSON.stringify({ done: !todo.done }),
      });
      setTodos((prev) => prev.map((t) => (t.id === todo.id ? res.todo : t)));
    } catch (e) {
      setError(e.message);
    }
  };

  const remove = async (todo) => {
    if (!window.confirm('Hapus todo ini?')) return;
    try {
      await api(`/api/todos/${todo.id}`, { method: 'DELETE' });
      setTodos((prev) => prev.filter((t) => t.id !== todo.id));
    } catch (e) {
      setError(e.message);
    }
  };

  const open = todos.filter((t) => !t.done).length;
  const overdue = todos.filter((t) => !t.done && t.due_at && new Date(t.due_at) < new Date());

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      <h2 className="text-lg font-semibold">Todo List</h2>
      <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Tugasmu. Bisa dikelola lewat chat dengan Hermes.</p>

      {error && <p className="mt-4 text-sm text-red-500">{error}</p>}

      <form onSubmit={add} className="mt-4 flex flex-wrap items-center gap-2">
        <input
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          placeholder="Tambah tugas..."
          maxLength={255}
          className="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
        />
        <input
          type="date"
          value={dueAt}
          onChange={(e) => setDueAt(e.target.value)}
          className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none dark:border-slate-700 dark:bg-brand-900"
        />
        <button
          type="submit"
          disabled={!title.trim()}
          className="rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
        >
          Tambah
        </button>
      </form>

      <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
        {open} terbuka dari {todos.length} total
        {overdue.length > 0 ? ` · ${overdue.length} terlambat` : ''}
      </p>

      {loading ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">Loading...</p>
      ) : todos.length === 0 ? (
        <p className="mt-16 text-center text-sm text-slate-400 dark:text-slate-500">
          Belum ada tugas. Tambahkan di atas, atau minta Hermes mencatatnya lewat chat.
        </p>
      ) : (
        <ul className="mt-4 space-y-2">
          {todos.map((todo) => (
            <li
              key={todo.id}
              className={`flex items-center gap-3 rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700 ${
                todo.done ? 'opacity-60' : ''
              }`}
            >
              <button
                type="button"
                aria-label={todo.done ? 'Tandai belum selesai' : 'Tandai selesai'}
                onClick={() => toggle(todo)}
                className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-md border transition ${
                  todo.done
                    ? 'border-emerald-500 bg-emerald-500 text-white'
                    : 'border-slate-300 text-transparent hover:border-emerald-500 dark:border-slate-600'
                }`}
              >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" className="h-3 w-3">
                  <polyline points="20 6 9 17 4 12" />
                </svg>
              </button>
              <span className={`min-w-0 flex-1 truncate text-sm ${todo.done ? 'line-through' : ''}`}>{todo.title}</span>
              {todo.due_at && (
                <span className={`shrink-0 text-xs ${todo.done || new Date(todo.due_at) >= new Date() ? 'text-slate-400 dark:text-slate-500' : 'font-medium text-red-500'}`}>
                  {fmtDate(todo.due_at)}
                </span>
              )}
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
          ))}
        </ul>
      )}
    </div>
  );
}