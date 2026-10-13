import { useEffect, useRef, useState } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { api, API_URL, clearToken, apiUpload, mediaUrl, mediaSrc, streamChat } from './api.js';
import { getMediaById, refreshMedia } from './mediaStore.js';
import AuthScreen from './AuthScreen.jsx';
import Dashboard from './Dashboard.jsx';
import Users from './Users.jsx';
import Gallery from './Gallery.jsx';
import Settings from './Settings.jsx';
import TodoList, { TodoIcon } from './Todo.jsx';
import CalendarPage, { CalendarIcon } from './Calendar.jsx';
import Transcribe from './Transcribe.jsx';
import Notulensi from './Notulensi.jsx';
import Skills from './Skills.jsx';

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

function MessageBubble({ message }) {
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
      </div>
    </div>
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

function LogoutIcon() {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
      <path d="m16 17 5-5-5-5" />
      <path d="M21 12H9" />
    </svg>
  );
}

function NavButton({ active, onClick, icon, label }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition ${
        active
          ? 'bg-skyblue/30 text-brand dark:bg-brand-800 dark:text-white'
          : 'text-slate-600 hover:bg-cream dark:text-slate-300 dark:hover:bg-brand-800/60'
      }`}
    >
      {icon}
      {label}
    </button>
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
  const [sessions, setSessions] = useState([]);
  const [activeId, setActiveId] = useState(null);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [page, setPage] = useState('chat');
  const [input, setInput] = useState('');
  const [pendingImages, setPendingImages] = useState([]);
  const [uploadError, setUploadError] = useState(null);
  const [loading, setLoading] = useState(false);
  const [streamText, setStreamText] = useState('');
  const [progress, setProgress] = useState(null);
  const [elapsed, setElapsed] = useState(0);
  const [dark, setDark] = useState(() => {
    const saved = localStorage.getItem('theme');
    if (saved) return saved === 'dark';
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
  });

  const sessionsRef = useRef(sessions);
  useEffect(() => {
    sessionsRef.current = sessions;
  }, [sessions]);

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

  useEffect(() => {
    const onExpired = () => setAuth(false);
    window.addEventListener('hermes-auth-expired', onExpired);
    return () => window.removeEventListener('hermes-auth-expired', onExpired);
  }, []);

  useEffect(() => {
    if (!auth) {
      setIsAdmin(false);
      return;
    }
    api('/api/me')
      .then((data) => setIsAdmin(Boolean(data.user && data.user.is_admin)))
      .catch(() => {});
  }, [auth]);

  useEffect(() => {
    document.documentElement.classList.toggle('dark', dark);
    localStorage.setItem('theme', dark ? 'dark' : 'light');
  }, [dark]);

  useEffect(() => {
    document.documentElement.dataset.palette = localStorage.getItem('palette') || 'klasik';
  }, []);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages, loading, streamText]);

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
    api('/api/sessions', { method: 'POST' })
      .then((session) => {
        setSessions((prev) => [...prev, session]);
        setActiveId(session.id);
      })
      .catch(() => {});
  };

  const selectSession = (id) => {
    setActiveId(id);
    setSidebarOpen(false);
  };

  const removeSession = (id) => {
    api(`/api/sessions/${id}`, { method: 'DELETE' }).catch(() => {});
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
    clearToken();
    setAuth(false);
    setSessions([]);
    setActiveId(null);
  };

  const sendMessage = async () => {
    const text = input.trim();
    const imageIds = pendingImages.map((p) => p.id);
    if ((!text && imageIds.length === 0) || loading || !active) return;

    const history = messages
      .filter((m) => !m.error)
      .map((m) => ({
        role: m.role,
        content: m.content,
        ...(m.image_ids && m.image_ids.length ? { image_ids: m.image_ids } : {}),
      }));

    appendToActive((prev) => [...prev, { role: 'user', content: text, image_ids: imageIds }]);
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
      appendToActive((prev) => [...prev, { role: 'assistant', content: reply }]);
      refreshMedia();
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

  if (!auth) {
    return <AuthScreen onAuthenticated={() => setAuth(true)} />;
  }

  return (
    <div className="flex h-screen bg-cream text-slate-900 transition-colors dark:bg-brand-950 dark:text-slate-100">
      {sidebarOpen && (
        <div
          className="fixed inset-0 z-30 bg-black/40 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}
      <aside
        className={`w-64 shrink-0 flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-brand ${
          sidebarOpen ? 'flex' : 'hidden'
        } max-lg:fixed max-lg:inset-y-0 max-lg:left-0 max-lg:z-40 max-lg:shadow-2xl`}
      >
        <div className="border-b border-slate-200 p-3 dark:border-slate-800">
          <button
            type="button"
            onClick={newSession}
            disabled={page !== 'chat'}
            className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
          >
            <PlusIcon />
            New chat
          </button>
          <div className="mt-2 space-y-1">
            <NavButton active={page === 'chat'} onClick={() => setPage('chat')} icon={<ChatIcon />} label="Chat" />
            <NavButton
              active={page === 'gallery'}
              onClick={() => setPage('gallery')}
              icon={<ImageSquareIcon />}
              label="Gallery"
            />
            <NavButton
              active={page === 'todos'}
              onClick={() => setPage('todos')}
              icon={<TodoIcon />}
              label="Todo List"
            />
            <NavButton
              active={page === 'calendar'}
              onClick={() => setPage('calendar')}
              icon={<CalendarIcon />}
              label="Kalender"
            />
            <NavButton
              active={page === 'transcribe'}
              onClick={() => setPage('transcribe')}
              icon={<MicIcon />}
              label="Transkrip"
            />
            <NavButton
              active={page === 'notulensi'}
              onClick={() => setPage('notulensi')}
              icon={<NotesIcon />}
              label="Notulensi"
            />
          </div>
        </div>
        <div className="flex-1 overflow-y-auto p-2">
          {page === 'chat' && (
            <>
            {sessions.map((session) => (
              <div
                key={session.id}
                onClick={() => selectSession(session.id)}
                className={`group mb-1 flex cursor-pointer items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm transition ${
                  session.id === activeId
                    ? 'bg-slate-200 font-medium dark:bg-brand-900'
                    : 'text-slate-600 hover:bg-cream dark:text-slate-300 dark:hover:bg-brand-800/60'
                }`}
              >
                <span className="min-w-0 truncate">{session.title}</span>
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
              </div>
            ))}
            {sessions.length === 0 && (
              <p className="px-3 py-4 text-center text-xs text-slate-400 dark:text-slate-500">
                Belum ada percakapan.
              </p>
            )}
            </>
          )}
        </div>
        <div className="border-t border-slate-200 p-3 dark:border-slate-800">
          <div className="space-y-1">
            {isAdmin && (
              <NavButton
                active={page === 'dashboard'}
                onClick={() => setPage('dashboard')}
                icon={<ChartIcon />}
                label="Dashboard"
              />
            )}
            {isAdmin && (
              <NavButton
                active={page === 'users'}
                onClick={() => setPage('users')}
                icon={<UsersIcon />}
                label="Pengguna"
              />
            )}
            {isAdmin && (
              <NavButton
                active={page === 'skills'}
                onClick={() => setPage('skills')}
                icon={<SparklesIcon />}
                label="Skill"
              />
            )}
            {isAdmin && (
              <NavButton
                active={page === 'settings'}
                onClick={() => setPage('settings')}
                icon={<GearIcon />}
                label="Settings"
              />
            )}
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-10 border-b border-slate-200 bg-white/80 backdrop-blur dark:border-slate-800 dark:bg-brand-900/90">
          <div className="mx-auto flex max-w-3xl items-center justify-between gap-2 px-4 py-3">
            <div className="flex min-w-0 items-center gap-2.5">
              <button
                type="button"
                onClick={() => setSidebarOpen((v) => !v)}
                aria-label="Toggle sessions"
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
                              : page === 'transcribe'
                                ? 'Transkrip Audio'
                                : page === 'notulensi'
                                  ? 'Notulensi'
                                  : page === 'skills'
                                    ? 'Skill Hermes'
                                    : 'Settings'}
                </h1>
                <p className="text-xs text-slate-500 dark:text-slate-400">Local AI chat</p>
              </div>
            </div>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={logout}
                aria-label="Log out"
                title="Log out"
                className="rounded-lg p-2 text-slate-600 transition hover:bg-cream dark:text-slate-300 dark:hover:bg-brand-800"
              >
                <LogoutIcon />
              </button>
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
                  <MessageBubble key={i} message={message} />
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
              className={`border-t border-slate-200 bg-white/80 px-4 pb-3 pt-4 backdrop-blur transition dark:border-slate-800 dark:bg-brand-900/90 ${
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
                  placeholder="Send a message to Hermes..."
                  className="max-h-40 flex-1 resize-none bg-transparent px-3 py-2 text-[0.95rem] outline-none placeholder:text-slate-400 dark:placeholder:text-slate-500"
                />
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
            ) : page === 'transcribe' ? (
              <Transcribe />
            ) : page === 'notulensi' ? (
              <Notulensi />
            ) : page === 'skills' ? (
              <Skills />
            ) : (
              <Settings />
            )}
          </main>
        )}
      </div>
    </div>
  );
}