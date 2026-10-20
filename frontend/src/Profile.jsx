import { useEffect, useState } from 'react';
import { api } from './api.js';

const input =
  'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue';
const card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700';

export default function Profile({ user, onUserChange }) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [profileMsg, setProfileMsg] = useState(null);
  const [profileError, setProfileError] = useState(null);
  const [savingProfile, setSavingProfile] = useState(false);

  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [pwMsg, setPwMsg] = useState(null);
  const [pwError, setPwError] = useState(null);
  const [savingPw, setSavingPw] = useState(false);

  useEffect(() => {
    if (!user) return;
    setName(user.name || '');
    setEmail(user.email || '');
  }, [user]);

  const saveProfile = async (e) => {
    e.preventDefault();
    setSavingProfile(true);
    setProfileMsg(null);
    setProfileError(null);
    try {
      const data = await api('/api/profile', {
        method: 'PUT',
        body: JSON.stringify({ name, email }),
      });
      onUserChange(data.user);
      setProfileMsg('Profil tersimpan.');
    } catch (err) {
      setProfileError(err.message);
    } finally {
      setSavingProfile(false);
    }
  };

  const savePassword = async (e) => {
    e.preventDefault();
    setSavingPw(true);
    setPwMsg(null);
    setPwError(null);
    try {
      await api('/api/profile', {
        method: 'PUT',
        body: JSON.stringify({ current_password: current, new_password: next }),
      });
      setCurrent('');
      setNext('');
      setPwMsg('Password diperbarui.');
    } catch (err) {
      setPwError(err.message);
    } finally {
      setSavingPw(false);
    }
  };

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      <h2 className="text-lg font-semibold">Pengaturan Akun</h2>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        Ubah nama, email, dan password akunmu sendiri.
      </p>

      <form onSubmit={saveProfile} className={`mt-4 ${card}`}>
        <h3 className="text-sm font-semibold">Profil</h3>
        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          <div>
            <label htmlFor="profile-name" className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">
              Nama
            </label>
            <input
              id="profile-name"
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
              className={input}
            />
          </div>
          <div>
            <label htmlFor="profile-email" className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">
              Email
            </label>
            <input
              id="profile-email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              className={input}
            />
          </div>
        </div>
        <button
          type="submit"
          disabled={savingProfile}
          className="mt-3 rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
        >
          {savingProfile ? 'Menyimpan...' : 'Simpan profil'}
        </button>
        {profileMsg && <p className="mt-3 text-sm text-emerald-600 dark:text-emerald-400">{profileMsg}</p>}
        {profileError && <p className="mt-3 text-sm text-red-500">{profileError}</p>}
      </form>

      <form onSubmit={savePassword} className={`mt-4 ${card}`}>
        <h3 className="text-sm font-semibold">Ubah password</h3>
        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          <div>
            <label htmlFor="profile-current" className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">
              Password saat ini
            </label>
            <input
              id="profile-current"
              type="password"
              value={current}
              onChange={(e) => setCurrent(e.target.value)}
              required
              className={input}
            />
          </div>
          <div>
            <label htmlFor="profile-next" className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">
              Password baru (min. 6)
            </label>
            <input
              id="profile-next"
              type="password"
              value={next}
              onChange={(e) => setNext(e.target.value)}
              required
              minLength={6}
              className={input}
            />
          </div>
        </div>
        <button
          type="submit"
          disabled={savingPw}
          className="mt-3 rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
        >
          {savingPw ? 'Menyimpan...' : 'Ubah password'}
        </button>
        {pwMsg && <p className="mt-3 text-sm text-emerald-600 dark:text-emerald-400">{pwMsg}</p>}
        {pwError && <p className="mt-3 text-sm text-red-500">{pwError}</p>}
      </form>
    </div>
  );
}
