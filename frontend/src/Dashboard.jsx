import { useEffect, useState } from 'react';
import { api } from './api.js';

const shortDate = (iso) => {
  const d = new Date(`${iso}T00:00:00`);
  return d.toLocaleDateString('en-US', { weekday: 'short' });
};

function StatCard({ label, value, sub }) {
  return (
    <div className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</p>
      <p className="mt-1 text-2xl font-semibold">{value}</p>
      {sub && <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{sub}</p>}
    </div>
  );
}

export default function Dashboard() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  const load = () => {
    setError(null);
    setData(null);
    api('/api/analytics')
      .then(setData)
      .catch((e) => setError(e.message));
  };

  useEffect(load, []);

  if (error) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-16 text-center">
        <p className="text-sm text-red-500">{error}</p>
        <button
          type="button"
          onClick={load}
          className="mt-4 rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-slate-900"
        >
          Retry
        </button>
      </div>
    );
  }

  if (!data) {
    return <div className="mx-auto max-w-3xl px-4 py-16 text-center text-sm text-slate-500">Loading analytics...</div>;
  }

  const { stats, by_day, by_model, by_user, hermes_status } = data;
  const maxDay = Math.max(1, ...by_day.map((d) => d.requests));

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">Dashboard</h2>
        <div className="flex items-center gap-3">
          <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${
              hermes_status === 'online'
                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
                : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400'
            }`}
          >
            <span className={`h-1.5 w-1.5 rounded-full ${hermes_status === 'online' ? 'bg-emerald-500' : 'bg-red-500'}`} />
            Hermes {hermes_status}
          </span>
          <button
            type="button"
            onClick={load}
            className="rounded-xl bg-brand px-3 py-1.5 text-sm font-medium text-white transition hover:bg-brand-600 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
          >
            Refresh
          </button>
        </div>
      </div>

      <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        <StatCard label="Total requests" value={stats.total_requests} />
        <StatCard label="Today" value={stats.today_requests} />
        <StatCard
          label="Success rate"
          value={stats.success_rate === null ? '—' : `${Math.round(stats.success_rate * 100)}%`}
          sub={`${stats.success_requests} ok · ${stats.failed_requests} error`}
        />
        <StatCard label="Total tokens" value={stats.total_tokens.toLocaleString('en-US')} />
      </div>

      <div className="mt-4 grid gap-3 md:grid-cols-2">
        <div className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
          <h3 className="text-sm font-semibold">Requests · last 7 days</h3>
          <div className="mt-4 flex h-32 items-end gap-2">
            {by_day.map((d) => (
              <div key={d.date} className="flex flex-1 flex-col items-center gap-1" title={`${d.date}: ${d.requests}`}>
                <span className="text-[10px] text-slate-500 dark:text-slate-400">{d.requests}</span>
                <div
                  className="w-full rounded-t-md bg-brand/70 dark:bg-skyblue/40"
                  style={{ height: `${Math.max(4, (d.requests / maxDay) * 100)}%` }}
                />
                <span className="text-[10px] text-slate-500 dark:text-slate-400">{shortDate(d.date)}</span>
              </div>
            ))}
          </div>
        </div>

        <div className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
          <h3 className="text-sm font-semibold">Tokens</h3>
          <dl className="mt-3 space-y-2 text-sm">
            <div className="flex justify-between">
              <dt className="text-slate-500 dark:text-slate-400">Prompt</dt>
              <dd className="font-medium">{stats.prompt_tokens.toLocaleString('en-US')}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-slate-500 dark:text-slate-400">Completion</dt>
              <dd className="font-medium">{stats.completion_tokens.toLocaleString('en-US')}</dd>
            </div>
            <div className="flex justify-between border-t border-slate-200 pt-2 dark:border-slate-700">
              <dt className="font-medium">Total</dt>
              <dd className="font-semibold">{stats.total_tokens.toLocaleString('en-US')}</dd>
            </div>
          </dl>
        </div>
      </div>

      <div className="mt-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
        <h3 className="text-sm font-semibold">Usage by user</h3>
        {!by_user || by_user.length === 0 ? (
          <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">No users yet.</p>
        ) : (
          <div className="mt-3 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                <tr>
                  <th className="py-2 pr-3 font-medium">User</th>
                  <th className="py-2 pr-3 text-right font-medium">Requests</th>
                  <th className="py-2 text-right font-medium">Tokens</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200 dark:divide-slate-700">
                {by_user.map((u) => (
                  <tr key={u.id}>
                    <td className="py-2 pr-3">
                      <div className="flex items-center gap-1.5">
                        <span className="truncate">{u.name || u.email}</span>
                        {u.is_admin && (
                          <span className="rounded bg-skyblue/30 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-brand-700 dark:bg-brand-800/40 dark:text-skyblue">
                            admin
                          </span>
                        )}
                      </div>
                      <div className="truncate text-xs text-slate-400">{u.email}</div>
                    </td>
                    <td className="py-2 pr-3 text-right tabular-nums">{u.requests}</td>
                    <td className="py-2 text-right tabular-nums">{u.tokens.toLocaleString('en-US')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="mt-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
        <h3 className="text-sm font-semibold">Usage by model</h3>
        {by_model.length === 0 ? (
          <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">No requests yet.</p>
        ) : (
          <ul className="mt-3 divide-y divide-slate-200 text-sm dark:divide-slate-700">
            {by_model.map((m) => (
              <li key={m.model} className="flex items-center justify-between gap-3 py-2">
                <span className="min-w-0 truncate font-mono text-xs">{m.model}</span>
                <span className="shrink-0 text-slate-500 dark:text-slate-400">
                  {m.requests} req · {m.tokens.toLocaleString('en-US')} tok
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}