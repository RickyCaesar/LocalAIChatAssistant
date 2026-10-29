import { useCallback, useEffect, useRef, useState } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { api, API_URL, clearToken, apiUpload, mediaUrl, mediaSrc, streamChat, apiQueue, apiQueueCancel, apiNotifications, apiNotificationRead, apiNotificationsReadAll, apiStt } from './api.js';
import { speak, stopSpeech, startRecording } from './speech.js';
import { confirmAction } from './feedback.js';
import { getMediaById, refreshMedia } from './mediaStore.js';
import AuthScreen from './AuthScreen.jsx';
import Dashboard from './Dashboard.jsx';
import Users from './Users.jsx';
import Gallery from './Gallery.jsx';
import Settings from './Settings.jsx';
import TodoList, { TodoIcon } from './Todo.jsx';
import CalendarPage, { CalendarIcon } from './Calendar.jsx';
import PerformanceReport, { ReportIcon } from './PerformanceReport.jsx';
import Transcribe from './Transcribe.jsx';
import Notulensi from './Notulensi.jsx';
import Convert from './Convert.jsx';
import Presentasi from './Presentasi.jsx';
import QueuePage from './Queue.jsx';
import Skills from './Skills.jsx';
import Profile from './Profile.jsx';
import { NotificationBell, ToastContainer, browserNotify, chime } from './Notifications.jsx';
// shortcut: pesan lama tanpa field at tidak menampilkan jam; tidak di-backfill.
import { fmtWita } from './time.js';

const mediaSrcFor = mediaSrc;

function CodeBlock({ language, code }) {
  const [copied, setCopied] = useState(false);

  const copy = () => {
    navigator.clipboard.writeText(code).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    });
  };

  return (
    <div className="my-3 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
      <div className="flex items-center justify-between bg-cream px-3 py-1.5 text-xs text-slate-500 dark:bg-brand-900 dark:text-slate-400">
        <span className="font-mono lowercase">{language || 'code'}</span>
        <button
          type="button"
          onClick={copy}
          className="rounded-md px-2 py-0.5 font-medium transition hover:bg-brand-100 dark:hover:bg-brand-600"
        >
          {copied ? 'Copied' : 'Copy'}
        </button>
      </div>
      <pre className="overflow-x-auto bg-cream p-3 text-sm leading-relaxed dark:bg-brand-800">
        <code className="font-mono text-slate-800 dark:text-slate-100">{code}</code>
      </pre>
    </div>
  );
}

function FileChip({ href, label }) {
  const id = href.split('/').filter(Boolean).pop();
  const meta = getMediaById(id);
  const isPdf = meta?.mime === 'application/pdf';
  return (
    <div className="my-1 max-w-full">
      <a
        href={mediaSrc(href)}
        download={label}
        className="inline-flex max-w-full items-center gap-1.5 rounded-lg bg-cream px-2.5 py-1.5 text-sm font-medium text-brand underline decoration-skyblue transition hover:bg-brand-100 dark:bg-brand-700 dark:text-skyblue dark:decoration-blush dark:hover:bg-slate-600"
      >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4 shrink-0">
          <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
          <polyline points="14 2 14 8 20 8" />
        </svg>
        <span className="truncate">{label}</span>
      </a>
      {meta?.preview && (
        <div className="mt-1.5 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
          <div className="border-b border-slate-200 bg-cream px-2.5 py-1 text-[11px] font-semibold tracking-wide text-slate-500 dark:border-slate-700 dark:bg-brand-900 dark:text-slate-400">
            PRATINJAU
          </div>
          <div className="max-h-48 overflow-y-auto whitespace-pre-wrap px-2.5 py-2 text-xs leading-relaxed text-slate-700 dark:text-slate-300">
            {meta.preview}
          </div>
        </div>
      )}
      {isPdf && (
        <iframe title={label} src={mediaSrc(href)} className="mt-1.5 h-52 w-full rounded-xl border border-slate-200 dark:border-slate-700" />
      )}
    </div>
  );
}

const markdownComponents = {
  pre: ({ children }) => <>{children}</>,
  // Tabel lebar discroll di dalam bubble sendiri, bukan melebarkan halaman.
  table: ({ children }) => (
    <div className="overflow-x-auto">
      <table>{children}</table>
    </div>
  ),
  a: ({ href, children }) => {
    if (href && href.startsWith('/api/media/')) {
      return <FileChip href={href} label={String(children)} />;
    }
    return (
      <a href={href} target="_blank" rel="noreferrer" className="text-brand underline dark:text-skyblue">
        {children}
      </a>
    );
  },
  img: ({ src, alt }) => (
    <img
      src={mediaSrcFor(src)}
      alt={alt || ''}
      className="my-3 max-h-96 rounded-xl border border-slate-200 dark:border-slate-700"
      loading="lazy"
    />
  ),
  code({ className, children }) {
    const match = /language-(\w+)/.exec(className || '');
    const text = String(children).replace(/\n$/, '');
    if (match || text.includes('\n')) {
      return <CodeBlock language={match ? match[1] : ''} code={text} />;
    }
    return (
      <code className="rounded bg-slate-200 px-1.5 py-0.5 font-mono text-[0.85em] text-blush dark:bg-brand-900 dark:text-blush">
        {children}
      </code>
    );
  },
};

function Avatar() {
  return (
    <div className="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand text-sm font-semibold text-white dark:bg-white dark:text-slate-900">
      H
    </div>
  );
}

function TypingIndicator() {
  return (
    <div className="flex items-center gap-1.5 py-1">
      {[0, 1, 2].map((i) => (
        <span
          key={i}
          className="h-2 w-2 animate-bounce rounded-full bg-slate-400 dark:bg-cream0"
          style={{ animationDelay: `${i * 0.15}s` }}
        />
      ))}
    </div>
  );
}

function formatBytes(n) {
  if (n == null) return '';
  const u = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  let v = n;
  while (v >= 1024 && i < u.length - 1) {
    v /= 1024;
    i += 1;
  }
  return `${v < 10 && i > 0 ? v.toFixed(1) : Math.round(v)} ${u[i]}`;
}

function PendingBadge({ item, onRemove }) {
  if (item.kind === 'image') {
    return (
      <div className="group relative">
        <img
          src={mediaSrcFor(item.url)}
          alt={item.filename}
          className="h-20 w-20 rounded-xl border border-slate-200 object-cover dark:border-slate-700"
        />
        <button
          type="button"
          aria-label="Remove attachment"
          onClick={() => onRemove(item.id)}
          className="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-xs font-bold text-white shadow transition hover:bg-red-600"
        >
          ×
        </button>
      </div>
    );
  }
  return (
    <div className="group relative flex max-w-56 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs dark:border-slate-700 dark:bg-brand-900">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5 shrink-0 text-brand-500">
        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
        <polyline points="14 2 14 8 20 8" />
      </svg>
      <div className="min-w-0">
        <div className="truncate font-medium text-slate-700 dark:text-slate-200">{item.filename}</div>
        <div className="text-slate-400">{formatBytes(item.size)}</div>
      </div>
      <button
        type="button"
        aria-label="Remove attachment"
        onClick={() => onRemove(item.id)}
        className="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-xs font-bold text-white shadow transition hover:bg-red-600"
      >
        ×
      </button>
    </div>
  );
}

function MessageBubble({ message, speaking, onSpeak }) {
  const isUser = message.role === 'user';

  if (isUser) {
    return (
      <div className="flex w-full justify-end gap-3">
        <div className="max-w-[80%] rounded-2xl rounded-br-md bg-brand px-4 py-2.5 text-white dark:bg-brand">
          {message.image_ids && message.image_ids.length > 0 && (
            <div className="mb-2 flex flex-wrap gap-2">
              {message.image_ids.map((id) => {
                const meta = getMediaById(id);
                if (meta && meta.kind !== 'image') {
                  return (
                    <a
                      key={id}
                      href={mediaSrc(`/api/media/${id}`)}
                      download={meta.filename}
                      className="inline-flex max-w-full items-center gap-1.5 rounded-lg bg-white/15 px-2.5 py-1.5 text-sm font-medium text-white transition hover:bg-white/25"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4 shrink-0">
                        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                        <polyline points="14 2 14 8 20 8" />
                      </svg>
                      <span className="truncate">{meta.filename}</span>
                    </a>
                  );
                }
                return (
                  <img
                    key={id}
                    src={mediaUrl(id)}
                    alt="attachment"
                    className="h-40 w-40 rounded-lg object-cover"
                  />
                );
              })}
            </div>
          )}
          {message.content && (
            <p className="whitespace-pre-wrap break-words text-[0.95rem] leading-relaxed">{message.content}</p>
          )}
          {message.at && (
            <p className="mt-1 text-right text-[0.7rem] leading-none text-white/70">{fmtWita(message.at)}</p>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="flex w-full justify-start gap-3">
      <Avatar />
      <div
        className={`max-w-[85%] rounded-2xl rounded-bl-md bg-white px-4 py-2.5 text-slate-800 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:text-slate-100 dark:ring-slate-700 ${
          message.error ? 'ring-red-300 dark:ring-red-700' : ''
        }`}
      >
        <div className="prose-chat text-[0.95rem] leading-relaxed">
          <ReactMarkdown remarkPlugins={[remarkGfm]} components={markdownComponents}>
            {message.content}
          </ReactMarkdown>
        </div>
        {!message.error && onSpeak && String(message.content).trim() && (
          <button
            type="button"
            onClick={onSpeak}
            aria-label={speaking ? 'Hentikan suara' : 'Bacakan pesan'}
            title={speaking ? 'Hentikan suara' : 'Bacakan pesan'}
            className={`mt-1.5 flex items-center gap-1.5 rounded-lg px-1.5 py-1 text-xs font-medium transition hover:bg-cream dark:hover:bg-brand-800 ${
              speaking ? 'text-brand dark:text-skyblue' : 'text-slate-400 dark:text-slate-500'
            }`}
          >
            {speaking ? <StopSoundIcon /> : <SpeakerIcon />}
            {speaking ? 'Hentikan' : 'Bacakan'}
          </button>
        )}
        {message.at && (
          <p className="mt-1 text-right text-[0.7rem] leading-none text-slate-400 dark:text-slate-500">{fmtWita(message.at)}</p>
        )}
      </div>
    </div>
  );
}

function SpeakingBars() {
  return (
    <span className="flex h-4 items-end gap-0.5" aria-hidden="true">
      {[10, 16, 7, 13].map((h, i) => (
        <span
          key={i}
          className="w-1 animate-pulse rounded-full bg-brand dark:bg-skyblue"
          style={{ height: `${h}px`, animationDelay: `${i * 120}ms` }}
        />
      ))}
    </span>
  );
}

function StreamBubble({ text }) {
  return (
    <div className="flex w-full justify-start gap-3">
      <Avatar />
      <div className="max-w-[85%] rounded-2xl rounded-bl-md bg-white px-4 py-2.5 text-slate-800 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:text-slate-100 dark:ring-slate-700">
        <p className="whitespace-pre-wrap break-words text-[0.95rem] leading-relaxed">
          {text}
          <span className="ml-1 inline-block h-4 w-0.5 animate-pulse align-middle bg-slate-400 dark:bg-cream0" />
        </p>
      </div>
    </div>
  );
}

function SunIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v2" />
      <path d="M12 20v2" />
      <path d="m4.93 4.93 1.41 1.41" />
      <path d="m17.66 17.66 1.41 1.41" />
      <path d="M2 12h2" />
      <path d="M20 12h2" />
      <path d="m6.34 17.66-1.41 1.41" />
      <path d="m19.07 4.93-1.41 1.41" />
    </svg>
  );
}

function MoonIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
      <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" />
    </svg>
  );
}

function MenuIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
      <path d="M4 6h16" />
      <path d="M4 12h16" />
      <path d="M4 18h16" />
    </svg>
  );
}

function PlusIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M12 5v14" />
      <path d="M5 12h14" />
    </svg>
  );
}

function PencilIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M12 20h9" />
      <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
    </svg>
  );
}

function TrashIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M3 6h18" />
      <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
      <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
    </svg>
  );
}

function ChatIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z" />
    </svg>
  );
}

function ChartIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M3 3v18h18" />
      <path d="M7 15v2" />
      <path d="M11 11v6" />
      <path d="M15 7v10" />
      <path d="M19 4v13" />
    </svg>
  );
}

function GearIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  );
}

function UsersIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="4" />
      <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
      <path d="M16 3.13a4 4 0 0 1 0 7.75" />
    </svg>
  );
}

// Menu profil di header: nama/email + Pengaturan akun + Keluar.
function ProfileMenu({ user, isAdmin, onProfile, onLogout }) {
  const [open, setOpen] = useState(false);
  const menuRef = useRef(null);

  // Tutup saat klik di luar. Cek contains(), bukan stopPropagation: React bisa
  // menjalankan effect ini sebelum event klik pembuka selesai naik ke document.
  useEffect(() => {
    if (!open) return;
    const close = (e) => {
      if (!menuRef.current?.contains(e.target)) setOpen(false);
    };
    document.addEventListener('click', close);
    return () => document.removeEventListener('click', close);
  }, [open]);

  const initial = (user?.name || user?.email || '?').trim().charAt(0).toUpperCase();

  return (
    <div className="relative" ref={menuRef}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-label="Menu profil"
        aria-expanded={open}
        title="Menu profil"
        className="flex h-9 w-9 items-center justify-center rounded-full bg-brand text-sm font-semibold text-white transition hover:bg-brand-600 dark:bg-skyblue dark:text-brand-900 dark:hover:bg-skyblue/85"
      >
        {initial}
      </button>

      {open && (
        <div
          className="absolute right-0 z-30 mt-2 w-60 overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700"
        >
          <div className="border-b border-slate-100 px-3 py-2 dark:border-slate-700">
            <p className="truncate text-sm font-semibold">
              {user?.name || 'Pengguna'}
              {isAdmin && (
                <span className="ml-1.5 rounded bg-skyblue/30 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-brand-700 dark:bg-brand-800/40 dark:text-skyblue">
                  admin
                </span>
              )}
            </p>
            <p className="truncate text-xs text-slate-500 dark:text-slate-400">{user?.email}</p>
          </div>
          <button
            type="button"
            onClick={() => {
              setOpen(false);
              onProfile();
            }}
            className="block w-full px-3 py-2.5 text-left text-sm transition hover:bg-cream dark:hover:bg-brand-800"
          >
            Pengaturan akun
          </button>
          <button
            type="button"
            onClick={() => {
              setOpen(false);
              onLogout();
            }}
            className="block w-full border-t border-slate-100 px-3 py-2.5 text-left text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-slate-700 dark:text-red-400 dark:hover:bg-brand-800"
          >
            Keluar
          </button>
        </div>
      )}
    </div>
  );
}

function NavButton({ active, onClick, icon, label, badge, rail }) {
  return (
    <button
      type="button"
      onClick={onClick}
      title={rail ? label : undefined}
      className={`relative flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition ${
        rail ? 'lg:justify-center lg:px-2' : ''
      } ${
        active
          ? 'bg-skyblue/30 text-brand dark:bg-brand-800 dark:text-white'
          : 'text-slate-600 hover:bg-cream dark:text-slate-300 dark:hover:bg-brand-800/60'
      }`}
    >
      {icon}
      <span className={`flex-1 whitespace-nowrap text-left ${rail ? 'lg:hidden' : ''}`}>{label}</span>
      {badge > 0 && (
        <span
          className={
            rail
              ? 'rounded-full bg-brand px-1.5 py-0.5 text-[0.65rem] font-semibold text-white lg:absolute lg:right-1 lg:top-1 lg:h-2 lg:w-2 lg:p-0 lg:text-[0px] dark:bg-skyblue'
              : 'rounded-full bg-brand px-1.5 py-0.5 text-[0.65rem] font-semibold text-white dark:bg-skyblue dark:text-brand-900'
          }
        >
          {badge}
        </span>
      )}
    </button>
  );
}

// Grup pemilik tiap halaman — dipakai untuk auto-buka grup menu yang aktif.
const PAGE_GROUP = {
  chat: 'utama',
  gallery: 'utama',
  todos: 'utama',
  calendar: 'utama',
  performance: 'utama',
  transcribe: 'alat',
  notulensi: 'alat',
  convert: 'alat',
  presentasi: 'alat',
  queue: 'alat',
  dashboard: 'admin',
  users: 'admin',
  skills: 'admin',
  settings: 'admin',
};

function NavGroup({ label, open, onToggle, rail, children }) {
  return (
    <div>
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={open}
        className={`flex w-full items-center justify-between px-3 pb-1 pt-3 ${rail ? 'lg:hidden' : ''}`}
      >
        <span className="text-[0.65rem] font-semibold uppercase tracking-wider text-slate-600 dark:text-white">{label}</span>
        <svg
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2.5"
          strokeLinecap="round"
          strokeLinejoin="round"
          className={`h-3 w-3 text-slate-400 transition-transform dark:text-slate-300 ${open ? 'rotate-90' : ''}`}
        >
          <path d="m9 18 6-6-6-6" />
        </svg>
      </button>
      {(open || rail) && children}
    </div>
  );
}

function SendIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M22 2 11 13" />
      <path d="M22 2 15 22l-4-9-9-4 20-7Z" />
    </svg>
  );
}

function ImageIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
      <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
      <circle cx="9" cy="9" r="2" />
      <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
    </svg>
  );
}

function ImageSquareIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
      <circle cx="9" cy="9" r="2" />
      <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
    </svg>
  );
}

function SpeakerIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-3.5 w-3.5">
      <path d="M11 5 6 9H2v6h4l5 4V5z" />
      <path d="M15.5 8.5a5 5 0 0 1 0 7" />
      <path d="M18.5 5.5a9 9 0 0 1 0 13" />
    </svg>
  );
}

function StopSoundIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" className="h-3.5 w-3.5">
      <rect x="6" y="6" width="12" height="12" rx="1.5" />
    </svg>
  );
}

function WaveIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" className="h-5 w-5">
      <path d="M2 12h2" />
      <path d="M6 8v8" />
      <path d="M10 5v14" />
      <path d="M14 8v8" />
      <path d="M18 10v4" />
      <path d="M22 12h0" />
    </svg>
  );
}

function MicIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
      <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
      <line x1="12" x2="12" y1="19" y2="22" />
    </svg>
  );
}

function NotesIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M4 4a2 2 0 0 1 2-2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z" />
      <path d="M14 2v5h5" />
      <line x1="8" x2="16" y1="13" y2="13" />
      <line x1="8" x2="16" y1="17" y2="17" />
    </svg>
  );
}

function ConvertIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M9 18V5l12-2v13" />
      <circle cx="6" cy="18" r="3" />
      <circle cx="18" cy="16" r="3" />
    </svg>
  );
}

function SlidesIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <rect x="2" y="4" width="20" height="12" rx="2" />
      <path d="M12 16v4" />
      <path d="M8 20h8" />
    </svg>
  );
}

function QueueIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
      <line x1="8" x2="21" y1="6" y2="6" />
      <line x1="8" x2="21" y1="12" y2="12" />
      <line x1="8" x2="21" y1="18" y2="18" />
      <circle cx="3.5" cy="6" r="1.5" />
      <circle cx="3.5" cy="12" r="1.5" />
      <circle cx="3.5" cy="18" r="1.5" />
    </svg>
  );
}

function SparklesIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
      <path d="M9.5 3 11 7l4 1.5L11 10l-1.5 4L8 10 4 8.5 8 7Z" />
      <path d="M18 13l.8 2.2L21 16l-2.2.8L18 19l-.8-2.2L15 16l2.2-.8Z" />
    </svg>
  );
}

export default function App() {
  const [auth, setAuth] = useState(() => Boolean(localStorage.getItem('hermes-token')));
  const [isAdmin, setIsAdmin] = useState(false);
  const [user, setUser] = useState(null);
  const [sessions, setSessions] = useState([]);
  const [activeId, setActiveId] = useState(null);
  const [editingId, setEditingId] = useState(null);
  const [editTitle, setEditTitle] = useState('');
  // Desktop langsung terbuka penuh; mobile mulai dengan drawer tertutup.
  const [sidebarOpen, setSidebarOpen] = useState(() => window.matchMedia('(min-width: 1024px)').matches);
  const [page, setPage] = useState('chat');
  const [queue, setQueue] = useState([]);
  const [notifs, setNotifs] = useState([]);
  const [unread, setUnread] = useState(0);
  const [toasts, setToasts] = useState([]);
  const notifLastIdRef = useRef(0);
  const notifPrimedRef = useRef(false);
  const [input, setInput] = useState('');
  const [pendingImages, setPendingImages] = useState([]);
  const [uploadError, setUploadError] = useState(null);
  const [loading, setLoading] = useState(false);
  const [streamText, setStreamText] = useState('');

  // Ikut breakpoint saat resize/rotate: desktop terbuka penuh, mobile drawer tertutup.
  // shortcut: pilihan manual expand/minimize di-reset saat melewati breakpoint lg.
  useEffect(() => {
    const mq = window.matchMedia('(min-width: 1024px)');
    const onChange = (e) => setSidebarOpen(e.matches);
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, []);

  // Grup menu bisa dibuka/tutup: default semua tertutup,
  // plus grup yang berisi menu aktif ikut terbuka saat halaman berganti.
  const [openGroups, setOpenGroups] = useState({ utama: false, alat: false, admin: false });
  const toggleGroup = (key) => setOpenGroups((g) => ({ ...g, [key]: !g[key] }));

  useEffect(() => {
    const group = PAGE_GROUP[page];
    if (group) setOpenGroups((g) => (g[group] ? g : { ...g, [group]: true }));
  }, [page]);
  const [progress, setProgress] = useState(null);
  const [elapsed, setElapsed] = useState(0);
  const [dark, setDark] = useState(() => {
    const saved = localStorage.getItem('theme');
    if (saved) return saved === 'dark';
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
  });
  const [tts, setTts] = useState({ enabled: true, voice: '', speed: 1 });
  const [speaking, setSpeaking] = useState(null); // index pesan | -1 saat auto-play
  const [voiceMode, setVoiceMode] = useState(() => localStorage.getItem('voice-mode') === '1');
  const [recording, setRecording] = useState(false);
  const [transcribing, setTranscribing] = useState(false);

  const sessionsRef = useRef(sessions);
  useEffect(() => {
    sessionsRef.current = sessions;
  }, [sessions]);
  const ttsRef = useRef(tts);
  const voiceModeRef = useRef(voiceMode);
  const recRef = useRef(null);
  const recTimerRef = useRef(null);
  useEffect(() => {
    ttsRef.current = tts;
  }, [tts]);
  useEffect(() => {
    voiceModeRef.current = voiceMode;
    localStorage.setItem('voice-mode', voiceMode ? '1' : '0');
  }, [voiceMode]);

  const active = sessions.find((s) => s.id === activeId) ?? null;
  const messages = active ? active.messages : [];

  const bottomRef = useRef(null);
  const chatMainRef = useRef(null);
  const textareaRef = useRef(null);
  const fileInputRef = useRef(null);
  const [showScrollBtn, setShowScrollBtn] = useState(false);
  const [dragOver, setDragOver] = useState(false);

  const handleChatScroll = () => {
    const el = chatMainRef.current;
    if (!el) return;
    setShowScrollBtn(el.scrollHeight - el.scrollTop - el.clientHeight > 120);
  };

  const jumpToBottom = () => {
    chatMainRef.current?.scrollTo({ top: chatMainRef.current.scrollHeight, behavior: 'smooth' });
  };

  useEffect(() => {
    if (!auth) return;
    api('/api/sessions')
      .then((data) => {
        setSessions(data.sessions);
        setActiveId((data.sessions[0] && data.sessions[0].id) || null);
        refreshMedia();
      })
      .catch(() => {
        clearToken();
        setAuth(false);
      });
  }, [auth]);

  // Global queue poll: runs while logged in regardless of the active page, so
  // progress keeps updating when the user switches tabs.
  const refreshQueue = useCallback(() => {
    apiQueue()
      .then((data) => setQueue(data.tasks || []))
      .catch(() => {});
  }, []);

  // Notifikasi memakai polling yang sama dengan antrean (bukan SSE): backend
  // dijalankan `php artisan serve` single-thread, stream panjang akan
  // memblokir seluruh API. ponytail: tab background di-throttle browser
  // (poll bisa jadi ~1 menit); upgrade ke Service Worker + Push API kalau
  // notifikasi instan saat tab tidak aktif itu penting.
  const closeToast = (key) => setToasts((prev) => prev.filter((t) => t.key !== key));

  const refreshNotifs = useCallback(() => {
    apiNotifications(notifLastIdRef.current)
      .then((data) => {
        const items = data.items || [];
        if (data.latest) notifLastIdRef.current = data.latest;
        if (items.length > 0) {
          setNotifs((prev) => [...items, ...prev].slice(0, 50));
          // Jangan munculkan toast/push untuk tumpukan lama saat pertama buka.
          if (notifPrimedRef.current) {
            items.forEach((n) => {
              const key = `${n.id}-${Date.now()}`;
              setToasts((prev) => [...prev, { ...n, key }].slice(-4));
              setTimeout(() => setToasts((prev) => prev.filter((t) => t.key !== key)), 6000);
              if (document.hidden) browserNotify(n);
            });
            chime();
          }
        }
        setUnread(data.unread || 0);
        notifPrimedRef.current = true;
      })
      .catch(() => {});
  }, []);

  const markNotificationRead = (n) => {
    if (n.is_read) return;
    setNotifs((prev) => prev.map((x) => (x.id === n.id ? { ...x, is_read: true } : x)));
    setUnread((c) => Math.max(0, c - 1));
    apiNotificationRead(n.id).catch(() => {});
  };

  const markAllNotificationsRead = () => {
    setNotifs((prev) => prev.map((x) => ({ ...x, is_read: true })));
    setUnread(0);
    apiNotificationsReadAll().catch(() => {});
  };

  const openNotification = (n) => {
    markNotificationRead(n);
    if (n.link) setPage(n.link);
  };

  useEffect(() => {
    if (!auth) {
      setQueue([]);
      setNotifs([]);
      setUnread(0);
      notifLastIdRef.current = 0;
      notifPrimedRef.current = false;
      return;
    }
    refreshQueue();
    refreshNotifs();
    const timer = setInterval(() => {
      refreshQueue();
      refreshNotifs();
    }, 3000);
    return () => clearInterval(timer);
  }, [auth, refreshQueue, refreshNotifs]);

  const activeCount = queue.filter((t) => t.status === 'queued' || t.status === 'processing').length;
  const runningTask = queue.find((t) => t.status === 'processing');

  const cancelTask = async (id) => {
    const ok = await confirmAction({
      title: 'Konfirmasi Tindakan',
      message: 'Batalkan atau hapus tugas ini dari riwayat?',
      confirmLabel: 'Ya',
    });
    if (!ok) return;
    apiQueueCancel(id).then(refreshQueue).catch(() => {});
  };

  useEffect(() => {
    const onExpired = () => setAuth(false);
    window.addEventListener('hermes-auth-expired', onExpired);
    return () => window.removeEventListener('hermes-auth-expired', onExpired);
  }, []);

  useEffect(() => {
    if (!auth) {
      setIsAdmin(false);
      setUser(null);
      return;
    }
    api('/api/me')
      .then((data) => {
        setIsAdmin(Boolean(data.user && data.user.is_admin));
        setUser(data.user || null);
      })
      .catch(() => {});
  }, [auth]);

  // Preferensi TTS + tema global. Tema ditentukan admin di halaman Settings;
  // localStorage hanya cache agar render pertama tidak berkedip.
  const loadSettings = useCallback(() => {
    api('/api/settings')
      .then((s) => {
        setTts({
          enabled: Boolean(Number(s.tts_enabled ?? 1)),
          voice: s.tts_voice || '',
          speed: Number(s.tts_speed) || 1,
        });
        const maxMb = Number(s.max_upload_mb);
        if (maxMb > 0) localStorage.setItem('max-upload-mb', String(maxMb));
        if (s.theme_palette) {
          document.documentElement.dataset.palette = s.theme_palette;
          localStorage.setItem('palette', s.theme_palette);
        }
        if (s.theme_dark) {
          document.documentElement.dataset.darkTheme = s.theme_dark;
          localStorage.setItem('dark-theme', s.theme_dark);
        }
      })
      .catch(() => {});
  }, []);

  useEffect(() => {
    if (!auth) return;
    loadSettings();
    window.addEventListener('hermes-settings-changed', loadSettings);
    return () => window.removeEventListener('hermes-settings-changed', loadSettings);
  }, [auth, loadSettings]);

  useEffect(() => {
    document.documentElement.classList.toggle('dark', dark);
    localStorage.setItem('theme', dark ? 'dark' : 'light');
  }, [dark]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages, loading, streamText]);

  useEffect(() => {
    if (page !== 'chat') return;
    const el = chatMainRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [page, activeId]);

  useEffect(() => {
    if (!loading) return;
    const timer = setInterval(() => setElapsed((s) => s + 1), 1000);
    return () => clearInterval(timer);
  }, [loading]);

  useEffect(() => {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
  }, [input]);

  const syncSession = (session) => {
    api(`/api/sessions/${session.id}`, {
      method: 'PUT',
      body: JSON.stringify({ title: session.title || 'New chat', messages: session.messages }),
      silent: true,
    }).catch(() => {});
  };

  const appendToActive = (update) => {
    const current = sessionsRef.current;
    const idx = current.findIndex((s) => s.id === activeId);
    if (idx === -1) return;
    const target = current[idx];
    const nextMessages = typeof update === 'function' ? update(target.messages) : update;
    const firstUser = nextMessages.find((m) => m.role === 'user' && !m.error);
    const title =
      target.title === 'New chat' && firstUser
        ? String(firstUser.content).replace(/\s+/g, ' ').trim().slice(0, 40)
        : target.title;
    const updated = { ...target, messages: nextMessages, title };

    const next = [...current];
    next[idx] = updated;
    setSessions(next);
    syncSession(updated);
  };

  const newSession = () => {
    setInput('');
    api('/api/sessions', { method: 'POST', silent: true })
      .then((session) => {
        setSessions((prev) => [...prev, session]);
        setActiveId(session.id);
        closeMobileSidebar();
      })
      .catch(() => {});
  };

  const closeMobileSidebar = () => {
    if (window.innerWidth < 1024) setSidebarOpen(false);
  };

  const goTo = (next) => {
    setPage(next);
    closeMobileSidebar();
  };

  const selectSession = (id) => {
    setPage('chat');
    setActiveId(id);
    setEditingId(null);
    closeMobileSidebar();
  };

  const startRename = (session) => {
    setEditingId(session.id);
    setEditTitle(session.title || '');
  };

  const cancelRename = () => {
    setEditingId(null);
    setEditTitle('');
  };

  const saveRename = async (session) => {
    const name = editTitle.trim().slice(0, 60);
    cancelRename();
    if (!name || name === session.title) return;
    try {
      const updated = await api(`/api/sessions/${session.id}`, {
        method: 'PUT',
        body: JSON.stringify({ title: name }),
        success: 'Nama percakapan diperbarui.',
      });
      setSessions((prev) => prev.map((s) => (s.id === session.id ? { ...s, title: updated.title } : s)));
    } catch {}
  };

  const removeSession = async (id) => {
    const ok = await confirmAction({
      title: 'Hapus Percakapan',
      message: 'Hapus percakapan ini? Media di Gallery tetap tersimpan.',
      confirmLabel: 'Ya, hapus',
    });
    if (!ok) return;
    api(`/api/sessions/${id}`, { method: 'DELETE', success: 'Percakapan berhasil dihapus.' }).catch(() => {});
    setSessions((prev) => prev.filter((s) => s.id !== id));
    if (activeId === id) {
      const next = sessionsRef.current.filter((s) => s.id !== id);
      setActiveId(next.length ? next[next.length - 1].id : null);
    }
  };

  const logout = async () => {
    try {
      await api('/api/logout', { method: 'POST' });
    } catch {
      /* token may already be gone; ignore */
    }
    // Matikan mic/pemutar sebelum sesi berakhir (privasi).
    voiceModeRef.current = false;
    setVoiceMode(false);
    stopVoiceRecording();
    stopSpeech();
    setSpeaking(null);
    clearToken();
    setAuth(false);
    setUser(null);
    setSessions([]);
    setActiveId(null);
  };

  // `forcedText` dipakai voice mode (hasil transkripsi) selain input teks.
  const sendMessage = async (forcedText) => {
    const text = (typeof forcedText === 'string' ? forcedText : input).trim();
    const imageIds = pendingImages.map((p) => p.id);
    if ((!text && imageIds.length === 0) || loading || !active) return;

    const history = messages
      .filter((m) => !m.error)
      .map((m) => ({
        role: m.role,
        content: m.content,
        ...(m.image_ids && m.image_ids.length ? { image_ids: m.image_ids } : {}),
      }));

    appendToActive((prev) => [...prev, { role: 'user', content: text, image_ids: imageIds, at: new Date().toISOString() }]);
    setInput('');
    setPendingImages([]);
    setUploadError(null);
    setLoading(true);
    setStreamText('');
    setProgress('waiting');
    setElapsed(0);

    try {
      const reply = await streamChat(
        { message: text, history, image_ids: imageIds, session_id: active.id },
        (chunk) => {
          setProgress('writing');
          setStreamText(chunk);
        }
      );
      setStreamText('');
      appendToActive((prev) => [...prev, { role: 'assistant', content: reply, at: new Date().toISOString() }]);
      refreshMedia();

      // Mode suara: bacakan otomatis, lalu siap merekam lagi (hands-free).
      if (voiceModeRef.current && ttsRef.current.enabled && reply.trim()) {
        setSpeaking(-1);
        const done = await speak(reply, ttsRef.current).catch((e) => {
          setUploadError(e.message);
          return false;
        });
        setSpeaking(null);
        if (done && voiceModeRef.current) setTimeout(() => startVoice(), 400);
      }
    } catch (err) {
      setStreamText('');
      appendToActive((prev) => [
        ...prev,
        {
          role: 'assistant',
          error: true,
          content: `**Connection error.** ${err.message}\n\nMake sure the backend and your local Hermes Agent are running. ${API_URL}`,
        },
      ]);
    } finally {
      setLoading(false);
      setProgress(null);
      setElapsed(0);
      textareaRef.current?.focus();
    }
  };

  const handleFiles = async (files) => {
    setUploadError(null);
    const maxMb = Number(localStorage.getItem('max-upload-mb')) || 20;
    for (const file of Array.from(files)) {
      if (file.size > maxMb * 1024 * 1024) {
        setUploadError(`File maksimal ${maxMb} MB.`);
        continue;
      }
      try {
        const item = await apiUpload(file);
        setPendingImages((prev) => [...prev, item]);
        refreshMedia();
      } catch (e) {
        setUploadError(e.message);
      }
    }
  };

  const removePendingImage = (id) => {
    setPendingImages((prev) => prev.filter((p) => p.id !== id));
  };

  const handleKeyDown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  };

  // Bacakan satu balasan. Klik lagi saat sedang berbunyi = berhenti.
  const speakAt = async (index, text) => {
    if (speaking === index) {
      stopSpeech();
      setSpeaking(null);
      return;
    }
    setSpeaking(index);
    const done = await speak(text, ttsRef.current).catch((e) => {
      setUploadError(e.message);
      return false;
    });
    setSpeaking((cur) => (cur === index ? null : cur));
    if (done && voiceModeRef.current) setTimeout(() => startVoice(), 400);
  };

  // Voice mode: rekam → transkrip (sinkron) → kirim → TTS → siap rekam lagi.
  // ponytail: tanpa VAD, rekaman berhenti otomatis setelah 20 dtk (atau klik).
  // Upgrade: deteksi keheningan via AnalyserNode supaya tak perlu batas waktu.
  const stopVoiceRecording = async () => {
    const rec = recRef.current;
    if (!rec) return;
    recRef.current = null;
    clearTimeout(recTimerRef.current);
    setRecording(false);
    const blob = await rec.stop();

    if (blob.size < 3000) {
      // Terlalu pendek / hening — coba lagi tanpa mengirim apa pun.
      if (voiceModeRef.current) setTimeout(() => startVoice(), 800);
      return;
    }

    setTranscribing(true);
    try {
      const { text } = await apiStt(blob);
      setTranscribing(false);
      if (!String(text || '').trim()) {
        if (voiceModeRef.current) setTimeout(() => startVoice(), 800);
        return;
      }
      await sendMessage(text);
    } catch (e) {
      setTranscribing(false);
      setUploadError(e.message);
    }
  };

  const startVoice = async () => {
    if (recRef.current) {
      stopVoiceRecording(); // klik saat merekam = berhenti & kirim
      return;
    }
    if (loading || speaking !== null || transcribing || !active) return;
    try {
      const rec = await startRecording();
      // Mode dibatalkan selagi izin mic diproses.
      if (!voiceModeRef.current) {
        rec.stop();
        return;
      }
      recRef.current = rec;
      setRecording(true);
      recTimerRef.current = setTimeout(() => stopVoiceRecording(), 20000);
    } catch (e) {
      setUploadError(`Mikrofon tidak bisa diakses: ${e.message}`);
      voiceModeRef.current = false;
      setVoiceMode(false);
    }
  };

  const toggleVoiceMode = () => {
    if (voiceMode) {
      voiceModeRef.current = false;
      setVoiceMode(false);
      stopVoiceRecording();
      stopSpeech();
      setSpeaking(null);
    } else {
      voiceModeRef.current = true;
      setVoiceMode(true);
    }
  };

  if (!auth) {
    return <AuthScreen onAuthenticated={() => setAuth(true)} />;
  }

  const rail = !sidebarOpen;

  return (
    <div className="flex h-dvh bg-cream text-slate-900 transition-colors dark:bg-brand-950 dark:text-slate-100">
      <div
        className={`fixed inset-0 z-30 bg-black/40 transition-opacity duration-300 lg:hidden ${
          sidebarOpen ? 'opacity-100' : 'pointer-events-none opacity-0'
        }`}
        onClick={() => setSidebarOpen(false)}
      />
      <aside
        className={`flex w-64 shrink-0 flex-col overflow-hidden border-r border-slate-200 bg-white transition-all duration-300 ease-in-out dark:border-slate-800 dark:bg-brand max-lg:fixed max-lg:inset-y-0 max-lg:left-0 max-lg:z-40 max-lg:shadow-2xl ${
          sidebarOpen ? 'max-lg:translate-x-0 lg:w-64' : 'max-lg:-translate-x-full lg:w-16'
        }`}
      >
        <div className="border-b border-slate-200 p-3 dark:border-slate-800">
          {/* rail: label grup tersembunyi, beri jarak dari menu di bawahnya */}
          <button
            type="button"
            onClick={newSession}
            disabled={page !== 'chat'}
            title={rail ? 'New chat' : undefined}
            className={`flex w-full items-center justify-center gap-2 rounded-xl bg-brand px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100 ${
              rail ? 'lg:mb-3' : ''
            }`}
          >
            <PlusIcon />
            <span className={rail ? 'lg:hidden' : ''}>New chat</span>
          </button>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto p-2 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <div className="lg:shrink-0">
          <NavGroup label="Utama" open={openGroups.utama} onToggle={() => toggleGroup('utama')} rail={rail}>
            <div className="space-y-1">
              <NavButton active={page === 'chat'} onClick={() => goTo('chat')} icon={<ChatIcon />} label="Chat" rail={rail} />
              <NavButton
                active={page === 'gallery'}
                onClick={() => goTo('gallery')}
                icon={<ImageSquareIcon />}
                label="Gallery"
                rail={rail}
              />
              <NavButton
                active={page === 'todos'}
                onClick={() => goTo('todos')}
                icon={<TodoIcon />}
                label="Todo List"
                rail={rail}
              />
              <NavButton
                active={page === 'calendar'}
                onClick={() => goTo('calendar')}
                icon={<CalendarIcon />}
                label="Kalender"
                rail={rail}
              />
              <NavButton
                active={page === 'performance'}
                onClick={() => goTo('performance')}
                icon={<ReportIcon />}
                label="Laporan Kinerja"
                rail={rail}
              />
            </div>
          </NavGroup>
          <div className={`mx-2 my-2 hidden border-t border-slate-200 dark:border-slate-800 ${rail ? 'lg:block' : ''}`} />
          <NavGroup label="Alat" open={openGroups.alat} onToggle={() => toggleGroup('alat')} rail={rail}>
            <div className="space-y-1">
              <NavButton
                active={page === 'transcribe'}
                onClick={() => goTo('transcribe')}
                icon={<MicIcon />}
                label="Transkrip"
                rail={rail}
              />
              <NavButton
                active={page === 'notulensi'}
                onClick={() => goTo('notulensi')}
                icon={<NotesIcon />}
                label="Notulensi"
                rail={rail}
              />
              <NavButton
                active={page === 'convert'}
                onClick={() => goTo('convert')}
                icon={<ConvertIcon />}
                label="ConvertToAudio"
                rail={rail}
              />
              <NavButton
                active={page === 'presentasi'}
                onClick={() => goTo('presentasi')}
                icon={<SlidesIcon />}
                label="Presentasi"
                rail={rail}
              />
            </div>
          </NavGroup>
          {isAdmin && (
            <NavGroup label="Admin" open={openGroups.admin} onToggle={() => toggleGroup('admin')} rail={rail}>
              <div className="space-y-1">
                <NavButton
                  active={page === 'dashboard'}
                  onClick={() => goTo('dashboard')}
                  icon={<ChartIcon />}
                  label="Dashboard"
                  rail={rail}
                />
                <NavButton
                  active={page === 'users'}
                  onClick={() => goTo('users')}
                  icon={<UsersIcon />}
                  label="Pengguna"
                  rail={rail}
                />
                <NavButton
                  active={page === 'skills'}
                  onClick={() => goTo('skills')}
                  icon={<SparklesIcon />}
                  label="Skill"
                  rail={rail}
                />
                <NavButton
                  active={page === 'settings'}
                  onClick={() => goTo('settings')}
                  icon={<GearIcon />}
                  label="Settings"
                  rail={rail}
                />
              </div>
            </NavGroup>
          )}
          </div>
          <div>
            <div className="mx-2 my-2 border-t border-slate-200 dark:border-slate-800" />
            {sessions.map((session) =>
              rail ? (
                <button
                  key={session.id}
                  type="button"
                  onClick={() => selectSession(session.id)}
                  onDoubleClick={() => {
                    setSidebarOpen(true);
                    startRename(session);
                  }}
                  title={`${session.title} (klik dua kali untuk ubah nama)`}
                  aria-label={`Buka ${session.title}`}
                  className={`mb-1 flex w-full items-center justify-center rounded-xl p-2 transition ${
                    session.id === activeId
                      ? 'bg-slate-200 dark:bg-brand-900'
                      : 'hover:bg-cream dark:hover:bg-brand-800/60'
                  }`}
                >
                  <span className="flex h-7 w-7 items-center justify-center rounded-full bg-skyblue/30 text-[11px] font-semibold uppercase text-brand-700 dark:bg-brand-700 dark:text-skyblue">
                    {session.title?.trim()?.[0] || '#'}
                  </span>
                </button>
              ) : (
                <div
                  key={session.id}
                  onClick={editingId === session.id ? undefined : () => selectSession(session.id)}
                  className={`group mb-1 flex items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm transition ${
                    editingId === session.id ? 'cursor-default' : 'cursor-pointer'
                  } ${
                    session.id === activeId
                      ? 'bg-slate-200 font-medium dark:bg-brand-900'
                      : 'text-slate-600 hover:bg-cream dark:text-slate-300 dark:hover:bg-brand-800/60'
                  }`}
                >
                  {editingId === session.id ? (
                    <>
                      <input
                        autoFocus
                        value={editTitle}
                        onChange={(e) => setEditTitle(e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') {
                            e.preventDefault();
                            saveRename(session);
                          }
                          if (e.key === 'Escape') {
                            e.preventDefault();
                            cancelRename();
                          }
                        }}
                        maxLength={60}
                        aria-label="Nama percakapan"
                        className="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-2 py-1 outline-none focus:border-brand dark:border-slate-600 dark:bg-brand-800 dark:focus:border-skyblue"
                      />
                      <span className="flex shrink-0 items-center gap-0.5">
                        <button
                          type="button"
                          aria-label="Simpan nama percakapan"
                          title="Simpan"
                          onClick={(e) => {
                            e.stopPropagation();
                            saveRename(session);
                          }}
                          className="rounded-md p-1 text-base leading-none text-slate-600 transition hover:opacity-70 dark:text-slate-300"
                        >
                          ✓
                        </button>
                        <button
                          type="button"
                          aria-label="Batal ubah nama"
                          title="Batal"
                          onClick={(e) => {
                            e.stopPropagation();
                            cancelRename();
                          }}
                          className="rounded-md p-1 text-base leading-none text-slate-400 transition hover:text-blush"
                        >
                          ×
                        </button>
                      </span>
                    </>
                  ) : (
                    <>
                      <span className="min-w-0 truncate">{session.title}</span>
                      <span className="flex shrink-0 items-center gap-0.5">
                        <button
                          type="button"
                          aria-label={`Ubah nama ${session.title}`}
                          title="Ubah nama"
                          onClick={(e) => {
                            e.stopPropagation();
                            startRename(session);
                          }}
                          className="shrink-0 rounded-md p-1 text-slate-400 opacity-0 transition hover:text-brand group-hover:opacity-100 dark:hover:text-skyblue"
                        >
                          <PencilIcon />
                        </button>
                        <button
                          type="button"
                          aria-label={`Delete ${session.title}`}
                          onClick={(e) => {
                            e.stopPropagation();
                            removeSession(session.id);
                          }}
                          className="shrink-0 rounded-md p-1 text-slate-400 opacity-0 transition hover:text-blush group-hover:opacity-100"
                        >
                          <TrashIcon />
                        </button>
                      </span>
                    </>
                  )}
                </div>
              )
            )}
            {sessions.length === 0 && !rail && (
              <p className="px-3 py-4 text-center text-xs text-slate-400 dark:text-slate-500">
                Belum ada percakapan.
              </p>
            )}
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-10 border-b border-slate-200 bg-white/80 pt-[env(safe-area-inset-top)] backdrop-blur dark:border-slate-800 dark:bg-brand-900/90">
          <div className="mx-auto flex max-w-3xl items-center justify-between gap-2 px-4 py-3">
            <div className="flex min-w-0 items-center gap-2.5">
              <button
                type="button"
                onClick={() => setSidebarOpen((v) => !v)}
                aria-label={rail ? 'Perlebar menu' : 'Perkecil menu'}
                title={rail ? 'Perlebar menu' : 'Perkecil menu'}
                aria-expanded={sidebarOpen}
                className="rounded-lg p-2 text-slate-600 transition hover:bg-cream dark:text-slate-300 dark:hover:bg-brand-800"
              >
                <MenuIcon />
              </button>
              <Avatar />
              <div className="min-w-0">
                <h1 className="truncate text-sm font-semibold leading-tight">
                  {page === 'chat'
                    ? active
                      ? active.title
                      : 'Hermes Assistant'
                    : page === 'dashboard'
                      ? 'Dashboard'
                      : page === 'users'
                        ? 'Kelola Pengguna'
                        : page === 'gallery'
                          ? 'Gallery'
                          : page === 'todos'
                            ? 'Todo List'
                            : page === 'calendar'
                              ? 'Kalender'
                              : page === 'performance'
                                ? 'Laporan Kinerja'
                                : page === 'transcribe'
                                  ? 'Transkrip Audio'
                                : page === 'notulensi'
                                  ? 'Notulensi'
                                  : page === 'convert'
                                    ? 'Konversi ke MP3'
                                    : page === 'presentasi'
                                      ? 'Presentasi PowerPoint'
                                      : page === 'queue'
                                        ? 'Antrean Proses'
                                        : page === 'skills'
                                      ? 'Skill Hermes'
                                      : page === 'profile'
                                        ? 'Pengaturan Akun'
                                        : 'Settings'}
                </h1>
                <p className="text-xs text-slate-500 dark:text-slate-400">Local AI chat</p>
              </div>
            </div>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={() => goTo('queue')}
                aria-label="Antrean proses"
                title={runningTask ? `Antrean proses (${runningTask.progress}%)` : 'Antrean proses'}
                className={`relative rounded-lg p-2 transition hover:bg-cream dark:hover:bg-brand-800 ${
                  page === 'queue'
                    ? 'bg-skyblue/30 text-brand dark:bg-brand-800 dark:text-white'
                    : 'text-slate-600 dark:text-slate-300'
                }`}
              >
                <QueueIcon />
                {activeCount > 0 && (
                  <span className="absolute right-0.5 top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand px-1 text-[0.6rem] font-semibold text-white dark:bg-skyblue dark:text-brand-900">
                    {activeCount > 9 ? '9+' : activeCount}
                  </span>
                )}
              </button>
              <NotificationBell
                items={notifs}
                unread={unread}
                onRead={markNotificationRead}
                onReadAll={markAllNotificationsRead}
                onOpen={openNotification}
              />
              <ProfileMenu
                user={user}
                isAdmin={isAdmin}
                onProfile={() => setPage('profile')}
                onLogout={logout}
              />
              <button
                type="button"
                onClick={() => setDark((v) => !v)}
                aria-label={dark ? 'Switch to light theme' : 'Switch to dark theme'}
                className="rounded-lg p-2 text-slate-600 transition hover:bg-cream dark:text-slate-300 dark:hover:bg-brand-800"
              >
                {dark ? <SunIcon /> : <MoonIcon />}
              </button>
            </div>
          </div>
        </header>

        {page === 'chat' ? (
          <>
            <main ref={chatMainRef} onScroll={handleChatScroll} className="flex-1 overflow-y-auto">
              <div className="mx-auto flex max-w-3xl flex-col gap-5 px-4 py-6">
                {messages.length === 0 && !loading && (
                  <div className="px-4 py-16 text-center">
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                      {active
                        ? 'Belum ada percakapan. Kirim pesan untuk mulai.'
                        : 'Mulai percakapan baru dengan tombol New chat.'}
                    </p>
                  </div>
                )}
                {messages.map((message, i) => (
                  <MessageBubble
                    key={i}
                    message={message}
                    speaking={speaking === i}
                    onSpeak={message.role === 'assistant' && !message.error ? () => speakAt(i, message.content) : null}
                  />
                ))}
                {streamText && <StreamBubble text={streamText} />}
                {loading && !streamText && (
                  <div className="flex w-full justify-start gap-3">
                    <Avatar />
                    <div className="rounded-2xl rounded-bl-md bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
                      <TypingIndicator />
                    </div>
                  </div>
                )}
                {loading && (
                  <p className="text-center text-xs text-slate-400 dark:text-slate-500">
                    {progress === 'writing'
                      ? `Hermes sedang menulis… ${elapsed}s`
                      : `Hermes sedang mengerjakan permintaan… ${elapsed}s`}
                  </p>
                )}
                <div ref={bottomRef} />
              </div>
              {showScrollBtn && (
                <button
                  type="button"
                  onClick={jumpToBottom}
                  aria-label="Scroll ke pesan terakhir"
                  title="Scroll ke pesan terakhir"
                  className="fixed right-6 bottom-28 z-20 flex h-11 w-11 items-center justify-center rounded-full bg-brand text-white shadow-lg transition hover:bg-brand-600 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                    <path d="M12 5v14" />
                    <path d="m19 12-7 7-7-7" />
                  </svg>
                </button>
              )}
            </main>

            <footer
              onDragOver={(e) => {
                e.preventDefault();
                setDragOver(true);
              }}
              onDragLeave={(e) => {
                if (e.currentTarget === e.target || !e.currentTarget.contains(e.relatedTarget)) setDragOver(false);
              }}
              onDrop={(e) => {
                e.preventDefault();
                setDragOver(false);
                handleFiles(e.dataTransfer.files);
              }}
              className={`border-t border-slate-200 bg-white/80 px-4 pt-4 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur transition dark:border-slate-800 dark:bg-brand-900/90 ${
                dragOver ? 'bg-skyblue/20 dark:bg-brand-800/80' : ''
              }`}
            >
              {dragOver && (
                <div className="mx-auto mb-2 flex max-w-3xl items-center justify-center rounded-xl border-2 border-dashed border-brand bg-cream/70 px-4 py-3 text-sm font-medium text-brand dark:border-skyblue dark:bg-brand-900">
                  Lepaskan untuk mengirim file
                </div>
              )}
              {pendingImages.length > 0 && (
                <div className="mx-auto mb-2 flex max-w-3xl flex-wrap gap-2">
                  {pendingImages.map((img) => (
                    <PendingBadge key={img.id} item={img} onRemove={removePendingImage} />
                  ))}
                </div>
              )}
              {voiceMode && (
                <div className="mx-auto mb-2 flex max-w-3xl items-center justify-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                  {speaking !== null ? (
                    <>
                      <SpeakingBars />
                      <span>AI sedang berbicara…</span>
                    </>
                  ) : recording ? (
                    <>
                      <span className="h-2.5 w-2.5 animate-pulse rounded-full bg-red-500" />
                      <span>Mendengarkan… klik mikrofon untuk berhenti & kirim</span>
                    </>
                  ) : transcribing ? (
                    <>
                      <span className="h-2.5 w-2.5 animate-pulse rounded-full bg-amber-500" />
                      <span>Mengubah suara menjadi teks…</span>
                    </>
                  ) : loading ? (
                    <span>Hermes sedang menjawab…</span>
                  ) : (
                    <span>Mode suara aktif — klik mikrofon lalu bicara</span>
                  )}
                </div>
              )}
              <div className="mx-auto flex max-w-3xl items-end gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm focus-within:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus-within:border-skyblue">
                <input
                  ref={fileInputRef}
                  type="file"
                  multiple
                  hidden
                  onChange={(e) => {
                    handleFiles(e.target.files);
                    e.target.value = '';
                  }}
                />
                <button
                  type="button"
                  onClick={() => fileInputRef.current?.click()}
                  disabled={loading}
                  aria-label="Attach file"
                  title="Attach file"
                  className="shrink-0 rounded-xl p-2.5 text-slate-500 transition hover:bg-cream disabled:opacity-40 dark:text-slate-400 dark:hover:bg-brand-600"
                >
                  <ImageIcon />
                </button>
                <textarea
                  ref={textareaRef}
                  rows={1}
                  value={input}
                  onChange={(e) => setInput(e.target.value)}
                  onKeyDown={handleKeyDown}
                  placeholder="Tulis pesan..."
                  className="max-h-40 flex-1 resize-none bg-transparent px-3 py-2 text-[0.95rem] outline-none placeholder:text-slate-400 dark:placeholder:text-slate-500"
                />
                <button
                  type="button"
                  onClick={startVoice}
                  disabled={!voiceMode || loading || transcribing || speaking !== null}
                  aria-label={recording ? 'Berhenti merekam' : 'Mulai merekam'}
                  title={recording ? 'Berhenti merekam & kirim' : 'Mulai merekam'}
                  className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl transition disabled:cursor-not-allowed disabled:opacity-40 ${
                    recording
                      ? 'animate-pulse bg-red-500 text-white hover:bg-red-600'
                      : 'text-slate-500 hover:bg-cream dark:text-slate-400 dark:hover:bg-brand-600'
                  }`}
                >
                  <MicIcon />
                </button>
                <button
                  type="button"
                  onClick={toggleVoiceMode}
                  aria-label="Mode suara"
                  title="Mode suara (rekam & dengarkan balasan)"
                  className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl transition ${
                    voiceMode
                      ? 'bg-brand text-white hover:bg-brand-600 dark:bg-skyblue dark:text-slate-900 dark:hover:bg-skyblue/85'
                      : 'text-slate-500 hover:bg-cream dark:text-slate-400 dark:hover:bg-brand-600'
                  }`}
                >
                  <WaveIcon />
                </button>
                <button
                  type="button"
                  onClick={sendMessage}
                  disabled={loading || (!input.trim() && pendingImages.length === 0) || !active}
                  aria-label="Send message"
                  className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
                >
                  <SendIcon />
                </button>
              </div>
              {uploadError && (
                <p className="mx-auto mt-2 max-w-3xl text-center text-xs text-red-500 dark:text-red-400">{uploadError}</p>
              )}
              <p className="mx-auto mt-2 max-w-3xl text-center text-xs text-slate-400 dark:text-slate-500">
                Powered by your local Hermes Agent. Check responses, it may be inaccurate.
              </p>
            </footer>
          </>
        ) : (
          <main className="flex-1 overflow-y-auto">
            {page === 'dashboard' ? (
              <Dashboard />
            ) : page === 'users' ? (
              <Users />
            ) : page === 'gallery' ? (
              <Gallery />
            ) : page === 'todos' ? (
              <TodoList />
            ) : page === 'calendar' ? (
              <CalendarPage />
            ) : page === 'performance' ? (
              <PerformanceReport />
            ) : page === 'transcribe' ? (
              <Transcribe queue={queue} refreshQueue={refreshQueue} />
            ) : page === 'notulensi' ? (
              <Notulensi queue={queue} refreshQueue={refreshQueue} />
            ) : page === 'convert' ? (
              <Convert queue={queue} refreshQueue={refreshQueue} />
            ) : page === 'presentasi' ? (
              <Presentasi />
            ) : page === 'queue' ? (
              <QueuePage tasks={queue} onCancel={cancelTask} />
            ) : page === 'skills' ? (
              <Skills />
            ) : page === 'profile' ? (
              <Profile user={user} onUserChange={setUser} />
            ) : (
              <Settings />
            )}
          </main>
        )}
      </div>

      <ToastContainer toasts={toasts} onClose={closeToast} onOpen={openNotification} />
    </div>
  );
}