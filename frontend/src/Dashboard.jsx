import { useEffect, useState } from 'react';
import { api } from './api.js';

const shortDate = (iso) => {
  const d = new Date(`${iso}T00:00:00`);
  return d.toLocaleDateString('en-US', { weekday: 'short' });
};

const fmtDuration = (s) => {
  if (s == null) return '—';
  if (s < 60) return `${s}s`;
  const m = Math.floor(s / 60);
  if (m < 60) return `${m}m ${s % 60}s`;
  return `${Math.floor(m / 60)}j ${m % 60}m`;
};

const TYPE_LABEL = { convert: 'Konversi MP3', transcribe: 'Transkripsi', notulensi: 'Notulensi' };

function StatCard({ label, value, sub, accent }) {
  return (
    <div className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${accent || ''}`}>{value}</p>
      {sub && <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{sub}</p>}
    </div>
  );
}

function Card({ title, children, className = '' }) {
  return (
    <div className={`rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700 ${className}`}>
      {title && <h3 className="text-sm font-semibold">{title}</h3>}
      {children}
    </div>
  );
}

function TrendChart({ data }) {
  const W = 560;
  const H = 160;
  const pad = { t: 12, r: 12, b: 24, l: 30 };
  const innerW = W - pad.l - pad.r;
  const innerH = H - pad.t - pad.b;
  const maxReq = Math.max(1, ...data.map((d) => d.requests));
  const maxTok = Math.max(1, ...data.map((d) => d.tokens));
  const n = Math.max(1, data.length - 1);
  const x = (i) => pad.l + (i / n) * innerW;
  const yReq = (v) => pad.t + innerH - (v / maxReq) * innerH;
  const yTok = (v) => pad.t + innerH - (v / maxTok) * innerH;

  const reqPts = data.map((d, i) => `${x(i)},${yReq(d.requests)}`).join(' ');
  const tokPts = data.map((d, i) => `${x(i)},${yTok(d.tokens)}`).join(' ');
  const area = `${pad.l},${pad.t + innerH} ${reqPts} ${pad.l + innerW},${pad.t + innerH}`;

  return (
    <div>
      <div className="flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400">
        <span className="inline-flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full bg-brand dark:bg-skyblue" /> Requests
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full bg-amber-400" /> Tokens
        </span>
      </div>
      <svg viewBox={`0 0 ${W} ${H}`} className="mt-2 w-full" role="img" aria-label="Tren 7 hari">
        {[0, 0.5, 1].map((f) => (
          <line
            key={f}
            x1={pad.l}
            x2={pad.l + innerW}
            y1={pad.t + innerH * f}
            y2={pad.t + innerH * f}
            className="stroke-slate-200 dark:stroke-slate-700"
            strokeWidth="1"
          />
        ))}
        <polygon points={area} className="fill-brand/10 dark:fill-skyblue/10" />
        <polyline points={reqPts} fill="none" className="stroke-brand dark:stroke-skyblue" strokeWidth="2" strokeLinejoin="round" />
        <polyline points={tokPts} fill="none" strokeDasharray="4 3" className="stroke-skyblue/70 dark:stroke-brand-400" strokeWidth="1.5" />
        {data.map((d, i) => (
          <g key={d.date}>
            <circle cx={x(i)} cy={yReq(d.requests)} r="2.5" className="fill-brand dark:fill-skyblue">
              <title>{`${d.date}: ${d.requests} req · ${d.tokens} tok`}</title>
            </circle>
            <text x={x(i)} y={H - 6} textAnchor="middle" className="fill-slate-400 text-[9px]">
              {shortDate(d.date)}
            </text>
          </g>
        ))}
      </svg>
    </div>
  );
}

function BarList({ items, color = 'bg-brand dark:bg-skyblue' }) {
  const max = Math.max(1, ...items.map((i) => i.value));
  return (
    <ul className="mt-3 space-y-3">
      {items.map((it) => (
        <li key={it.label}>
          <div className="flex items-center justify-between gap-2 text-xs">
            <span className="min-w-0 truncate">{it.label}</span>
            <span className="shrink-0 tabular-nums text-slate-500 dark:text-slate-400">{it.right}</span>
          </div>
          <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-brand-800">
            <div className={`h-full rounded-full ${color}`} style={{ width: `${Math.max(3, (it.value / max) * 100)}%` }} />
          </div>
        </li>
      ))}
    </ul>
  );
}

export default function Dashboard() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  const load = () => {
    setError(null);
    api('/api/analytics').then(setData).catch((e) => setError(e.message));
  };

  useEffect(() => {
    load();
    const t = setInterval(load, 30000);
    return () => clearInterval(t);
  }, []);

  if (error) {
    return (
      <div className="mx-auto max-w-5xl px-4 py-16 text-center">
        <p className="text-sm text-red-500">Gagal memuat analitik: {error}</p>
        <button
          type="button"
          onClick={load}
          className="mt-4 rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-slate-900"
        >
          Coba lagi
        </button>
      </div>
    );
  }

  if (!data) {
    return <div className="mx-auto max-w-5xl px-4 py-16 text-center text-sm text-slate-500">Memuat analitik...</div>;
  }

  const { stats, by_day, by_model, by_user, queue, content, hermes_status } = data;
  const maxTok = Math.max(1, ...by_day.map((d) => d.tokens));

  return (
    <div className="mx-auto max-w-5xl px-4 py-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-lg font-semibold">Dashboard</h2>
          <p className="text-xs text-slate-500 dark:text-slate-400">Ringkasan aktivitas asisten, antrean, dan konten.</p>
        </div>
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
        <StatCard label="Total percakapan" value={stats.total_requests} sub={`${stats.today_requests} hari ini`} />
        <StatCard
          label="Tingkat sukses"
          value={stats.success_rate === null ? '—' : `${Math.round(stats.success_rate * 100)}%`}
          sub={`${stats.success_requests} ok · ${stats.failed_requests} gagal`}
        />
        <StatCard label="Total token" value={stats.total_tokens.toLocaleString('en-US')} sub={`${stats.prompt_tokens.toLocaleString('en-US')} prompt`} />
        <StatCard label="Pengguna" value={stats.total_users} />
      </div>

      <div className="mt-4 grid gap-3 md:grid-cols-3">
        <Card className="md:col-span-2" title="Tren 7 hari terakhir">
          <TrendChart data={by_day} />
        </Card>

        <Card title="Token">
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
          <h4 className="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">Token per hari</h4>
          <div className="mt-2 flex h-16 items-end gap-1.5">
            {by_day.map((d) => (
              <div key={d.date} className="flex-1" title={`${d.date}: ${d.tokens.toLocaleString('en-US')} token`}>
                <div
                  className="w-full rounded-t bg-brand/60 dark:bg-skyblue/40"
                  style={{ height: `${Math.max(4, (d.tokens / maxTok) * 100)}%` }}
                />
              </div>
            ))}
          </div>
        </Card>
      </div>

      <div className="mt-4 grid gap-3 md:grid-cols-3">
        <Card title="Antrean">
          {queue ? (
            <>
              <div className="mt-3 grid grid-cols-2 gap-2 text-center">
                <div className="rounded-xl bg-slate-50 p-2 dark:bg-brand-800">
                  <p className="text-lg font-semibold">{queue.total}</p>
                  <p className="text-[10px] uppercase tracking-wide text-slate-500 dark:text-slate-400">Total tugas</p>
                </div>
                <div className="rounded-xl bg-slate-50 p-2 dark:bg-brand-800">
                  <p className="text-lg font-semibold text-emerald-600 dark:text-emerald-400">{queue.done}</p>
                  <p className="text-[10px] uppercase tracking-wide text-slate-500 dark:text-slate-400">Selesai</p>
                </div>
                <div className="rounded-xl bg-slate-50 p-2 dark:bg-brand-800">
                  <p className="text-lg font-semibold text-brand-900 dark:text-skyblue">{fmtDuration(queue.avg_duration)}</p>
                  <p className="text-[10px] uppercase tracking-wide text-slate-500 dark:text-slate-400">Rata-rata</p>
                </div>
                <div className="rounded-xl bg-slate-50 p-2 dark:bg-brand-800">
                  <p className="text-lg font-semibold text-red-600 dark:text-red-400">{queue.error}</p>
                  <p className="text-[10px] uppercase tracking-wide text-slate-500 dark:text-slate-400">Gagal</p>
                </div>
              </div>
              {queue.by_type?.length > 0 && (
                <BarList
                  items={queue.by_type.map((t) => ({
                    label: TYPE_LABEL[t.type] || t.type,
                    value: t.requests ?? 0,
                    right: `${queue.by_type.find((x) => x.type === t.type)?.count ?? ''}`,
                  }))}
                />
              )}
            </>
          ) : (
            <p className="mt-3 text-sm text-slate-500">Tidak ada data antrean.</p>
          )}
        </Card>

        <Card title="Distribusi model">
          {by_model.length === 0 ? (
            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Belum ada permintaan.</p>
          ) : (
            <BarList
              items={by_model.map((m) => ({
                label: m.model,
                value: m.requests,
                right: `${m.requests} · ${m.tokens.toLocaleString('en-US')} tok`,
              }))}
            />
          )}
        </Card>

        <Card title="Konten">
          {content ? (
            <div className="mt-3 grid grid-cols-2 gap-2 text-center">
              {[
                ['Sesi', content.sessions],
                ['Media', content.media],
                ['Tugas', content.todos],
                ['Agenda', content.events],
              ].map(([label, val]) => (
                <div key={label} className="rounded-xl bg-slate-50 p-3 dark:bg-brand-800">
                  <p className="text-xl font-semibold">{val ?? 0}</p>
                  <p className="text-[10px] uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</p>
                </div>
              ))}
            </div>
          ) : (
            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">—</p>
          )}
        </Card>
      </div>

      <div className="mt-4 grid gap-3 md:grid-cols-2">
        <Card title="Penggunaan per pengguna">
          {by_user.length === 0 ? (
            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Belum ada percakapan.</p>
          ) : (
            <div className="mt-2 overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-xs uppercase tracking-wide text-slate-400">
                  <tr>
                    <th className="py-2 text-left">Pengguna</th>
                    <th className="py-2 text-right">Req</th>
                    <th className="py-2 text-right">Token</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200 dark:divide-slate-700">
                  {by_user.map((u) => (
                    <tr key={u.id}>
                      <td className="py-2">
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
                      <td className="py-2 text-right tabular-nums">{u.requests}</td>
                      <td className="py-2 text-right tabular-nums">{u.tokens.toLocaleString('en-US')}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <Card title="Model AI">
          {by_model.length === 0 ? (
            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Belum ada permintaan.</p>
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
        </Card>
      </div>
    </div>
  );
}
