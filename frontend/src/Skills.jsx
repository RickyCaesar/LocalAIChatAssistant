import { useEffect, useState } from 'react';
import { api } from './api.js';

export default function Skills() {
  const [skills, setSkills] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

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

  const groups = skills.reduce((acc, s) => {
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
          <p className="text-xs text-slate-500 dark:text-slate-400">{skills.length} skill terdeteksi.</p>
          {Object.entries(groups).map(([category, items]) => (
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
          ))}
        </div>
      )}
    </div>
  );
}
