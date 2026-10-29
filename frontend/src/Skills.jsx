import { useEffect, useState } from 'react';
import { api } from './api.js';

export default function Skills() {
  const [skills, setSkills] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [q, setQ] = useState('');

  useEffect(() => {
    (async () => {
      try {
        const data = await api('/api/skills');
        setSkills(data.skills || []);
      } catch (e) {
        setError(e.message);
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const term = q.trim().toLowerCase();
  const filtered = term
    ? skills.filter((s) => [s.name, s.category, s.description].some((v) => v?.toLowerCase().includes(term)))
    : skills;
  const groups = filtered.reduce((acc, s) => {
    const key = s.category || 'Lainnya';
    (acc[key] = acc[key] || []).push(s);
    return acc;
  }, {});

  const card = 'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700';

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      <h2 className="text-lg font-semibold">Skill Hermes</h2>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        Daftar kemampuan (skill) yang dimiliki Hermes Agent saat ini.
      </p>

      {loading ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">Memuat...</p>
      ) : error ? (
        <p className="mt-4 text-sm text-red-500">{error}</p>
      ) : skills.length === 0 ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">Belum ada skill.</p>
      ) : (
        <div className="mt-4 space-y-4">
          <div className="relative">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400">
              <circle cx="11" cy="11" r="8" />
              <path d="m21 21-4.3-4.3" />
            </svg>
            <input
              type="search"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="Cari skill..."
              className="w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-3 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
            />
          </div>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            {term ? `${filtered.length} hasil untuk "${q.trim()}".` : `${skills.length} skill terdeteksi.`}
          </p>
          {filtered.length === 0 ? (
            <p className="text-sm text-slate-500 dark:text-slate-400">Skill tidak ditemukan.</p>
          ) : (
            Object.entries(groups).map(([category, items]) => (
              <div key={category} className={card}>
                <h3 className="text-xs font-semibold uppercase tracking-wide text-brand dark:text-skyblue">{category}</h3>
                <ul className="mt-3 space-y-2">
                  {items.map((s) => (
                    <li key={`${category}-${s.name}`} className="border-b border-slate-100 pb-2 last:border-0 last:pb-0 dark:border-slate-800">
                      <p className="text-sm font-medium">{s.name}</p>
                      {s.description && (
                        <p className="text-xs text-slate-500 dark:text-slate-400">{s.description}</p>
                      )}
                    </li>
                  ))}
                </ul>
              </div>
            ))
          )}
        </div>
      )}
    </div>
  );
}
