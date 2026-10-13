import { useEffect, useState } from 'react';
import { api } from './api.js';

const emptyForm = { name: '', email: '', password: '', is_admin: false };

function Badge({ admin }) {
  return admin ? (
    <span className="rounded bg-skyblue/30 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-brand-700 dark:bg-brand-800/40 dark:text-skyblue">
      admin
    </span>
  ) : (
    <span className="rounded bg-cream px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-500 dark:bg-brand-700 dark:text-slate-400">
      user
    </span>
  );
}

export default function Users() {
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    setError(null);
    try {
      const data = await api('/api/users');
      setUsers(data.users);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const createUser = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      await api('/api/users', { method: 'POST', body: JSON.stringify(form) });
      setForm(emptyForm);
      setNotice('Pengguna dibuat.');
      await load();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  const toggleAdmin = async (u) => {
    setError(null);
    setNotice(null);
    try {
      await api(`/api/users/${u.id}`, { method: 'PUT', body: JSON.stringify({ is_admin: !u.is_admin }) });
      await load();
    } catch (err) {
      setError(err.message);
    }
  };

  const resetPassword = async (u) => {
    const password = window.prompt(`Password baru untuk ${u.email} (min. 6 karakter):`);
    if (!password) return;
    setError(null);
    setNotice(null);
    try {
      await api(`/api/users/${u.id}`, { method: 'PUT', body: JSON.stringify({ password }) });
      setNotice(`Password ${u.email} diperbarui.`);
    } catch (err) {
      setError(err.message);
    }
  };

  const removeUser = async (u) => {
    if (!window.confirm(`Hapus pengguna ${u.email}? Tindakan ini permanen.`)) return;
    setError(null);
    setNotice(null);
    try {
      await api(`/api/users/${u.id}`, { method: 'DELETE' });
      setNotice(`Pengguna ${u.email} dihapus.`);
      await load();
    } catch (err) {
      setError(err.message);
    }
  };

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">Kelola Pengguna</h2>
        <span className="text-xs text-slate-500 dark:text-slate-400">{users.length} akun</span>
      </div>

      {error && <p className="mt-3 text-sm text-red-500">{error}</p>}
      {notice && <p className="mt-3 text-sm text-emerald-600 dark:text-emerald-400">{notice}</p>}

      <form
        onSubmit={createUser}
        className="mt-4 grid gap-2 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-2 dark:bg-brand-900 dark:ring-slate-700"
      >
        <h3 className="text-sm font-semibold sm:col-span-2">Tambah pengguna</h3>
        <input
          type="text"
          value={form.name}
          onChange={(e) => setForm({ ...form, name: e.target.value })}
          placeholder="Nama"
          required
          className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
        />
        <input
          type="email"
          value={form.email}
          onChange={(e) => setForm({ ...form, email: e.target.value })}
          placeholder="Email"
          required
          className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
        />
        <input
          type="password"
          value={form.password}
          onChange={(e) => setForm({ ...form, password: e.target.value })}
          placeholder="Password (min. 6)"
          required
          minLength={6}
          className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
        />
        <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
          <input
            type="checkbox"
            checked={form.is_admin}
            onChange={(e) => setForm({ ...form, is_admin: e.target.checked })}
            className="h-4 w-4 rounded border-slate-300"
          />
          Jadikan admin
        </label>
        <button
          type="submit"
          disabled={busy}
          className="rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-40 sm:col-span-2 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
        >
          {busy ? 'Menyimpan...' : 'Tambah pengguna'}
        </button>
      </form>

      <div className="mt-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
        <h3 className="text-sm font-semibold">Daftar pengguna</h3>
        {loading ? (
          <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Memuat...</p>
        ) : (
          <div className="mt-3 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                <tr>
                  <th className="py-2 pr-3 font-medium">Pengguna</th>
                  <th className="py-2 pr-3 text-right font-medium">Req</th>
                  <th className="py-2 pr-3 text-right font-medium">Token</th>
                  <th className="py-2 text-right font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200 dark:divide-slate-700">
                {users.map((u) => (
                  <tr key={u.id}>
                    <td className="py-2 pr-3">
                      <div className="flex items-center gap-1.5">
                        <span className="truncate font-medium">{u.name || u.email}</span>
                        <Badge admin={u.is_admin} />
                      </div>
                      <div className="truncate text-xs text-slate-400">{u.email}</div>
                    </td>
                    <td className="py-2 pr-3 text-right tabular-nums">{u.requests}</td>
                    <td className="py-2 pr-3 text-right tabular-nums">{u.tokens.toLocaleString('en-US')}</td>
                    <td className="py-2">
                      <div className="flex justify-end gap-2 whitespace-nowrap text-xs">
                        <button
                          type="button"
                          onClick={() => toggleAdmin(u)}
                          className="rounded-lg px-2 py-1 font-medium text-brand hover:bg-skyblue/15 dark:text-skyblue dark:hover:bg-brand-600"
                        >
                          {u.is_admin ? 'Turunkan' : 'Jadikan admin'}
                        </button>
                        <button
                          type="button"
                          onClick={() => resetPassword(u)}
                          className="rounded-lg px-2 py-1 font-medium text-slate-600 hover:bg-cream dark:text-slate-300 dark:hover:bg-brand-600"
                        >
                          Reset password
                        </button>
                        <button
                          type="button"
                          onClick={() => removeUser(u)}
                          className="rounded-lg px-2 py-1 font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-brand-600"
                        >
                          Hapus
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}