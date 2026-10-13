import { useState } from 'react';
import { api, setToken } from './api.js';

export default function AuthScreen({ onAuthenticated }) {
  const [mode, setMode] = useState('login');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const path = mode === 'login' ? '/api/login' : '/api/register';
      const payload = mode === 'login' ? { email, password } : { name, email, password };
      const data = await api(path, { method: 'POST', body: JSON.stringify(payload) });
      setToken(data.token);
      onAuthenticated();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-cream px-4 text-slate-900 dark:bg-brand-950 dark:text-slate-100">
      <div className="w-full max-w-sm">
        <div className="mb-6 text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-brand text-lg font-bold text-white dark:bg-white dark:text-slate-900">
            H
          </div>
          <h1 className="mt-3 text-xl font-semibold">Hermes Assistant</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{mode === 'login' ? 'Masuk untuk melanjutkan' : 'Buat akun baru'}</p>
        </div>

        <form
          onSubmit={submit}
          className="space-y-3 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-brand dark:ring-slate-700"
        >
          {mode === 'register' && (
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Nama"
              required
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
            />
          )}
          <input
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="Email"
            required
            autoComplete="email"
            className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
          />
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="Password (min. 6 karakter)"
            required
            minLength={6}
            autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
            className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
          />

          {error && <p className="text-sm text-red-500">{error}</p>}

          <button
            type="submit"
            disabled={busy}
            className="w-full rounded-xl bg-brand px-3 py-2.5 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
          >
            {busy ? 'Memproses...' : mode === 'login' ? 'Masuk' : 'Daftar'}
          </button>
        </form>

        <p className="mt-4 text-center text-sm text-slate-500 dark:text-slate-400">
          {mode === 'login' ? 'Belum punya akun?' : 'Sudah punya akun?'}{' '}
          <button
            type="button"
            onClick={() => {
              setMode(mode === 'login' ? 'register' : 'login');
              setError(null);
            }}
            className="font-medium text-slate-900 underline dark:text-white"
          >
            {mode === 'login' ? 'Daftar di sini' : 'Masuk di sini'}
          </button>
        </p>
      </div>
    </div>
  );
}