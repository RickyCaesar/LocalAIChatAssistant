import { apiTts } from './api.js';

// Pemutar TTS tunggal: memulai suara baru otomatis menghentikan yang lama.
const MAX_TTS_CHARS = 4000;
let active = null; // { audio, url, resolve }
let speechRun = 0;

function finish(ok) {
  const cur = active;
  if (!cur) return;
  active = null;
  try {
    cur.audio.pause();
  } catch {
    /* ignore */
  }
  URL.revokeObjectURL(cur.url);
  cur.resolve(ok);
}

function splitText(text) {
  const clean = String(text || '').trim();
  if (clean.length <= MAX_TTS_CHARS) return clean ? [clean] : [];

  const chunks = [];
  let rest = clean;
  while (rest.length > MAX_TTS_CHARS) {
    const part = rest.slice(0, MAX_TTS_CHARS);
    let cut = Math.max(part.lastIndexOf('\n\n'), part.lastIndexOf('. '), part.lastIndexOf(' ')) + 1;
    if (cut < MAX_TTS_CHARS / 2) cut = MAX_TTS_CHARS;
    chunks.push(rest.slice(0, cut).trim());
    rest = rest.slice(cut).trimStart();
  }
  if (rest) chunks.push(rest);

  return chunks.filter(Boolean);
}

function playBlob(blob) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(blob);
    const audio = new Audio(url);
    active = { audio, url, resolve };
    const fail = (err) => {
      if (active && active.audio === audio) finish(false);
      reject(err);
    };
    audio.onended = () => finish(true);
    audio.onerror = () => fail(new Error('Audio gagal diputar.'));
    audio.play().catch(fail);
  });
}

export function stopSpeech() {
  speechRun++;
  finish(false);
}

// Resolve `true` jika selesai diputar, `false` jika dihentikan/diganti.
export function speak(text, { voice, speed } = {}) {
  stopSpeech();
  const run = speechRun;
  const chunks = splitText(text);

  return (async () => {
    for (const chunk of chunks) {
      if (run !== speechRun) return false;
      const blob = await apiTts(chunk, voice, speed);
      if (run !== speechRun) return false;
      const done = await playBlob(blob);
      if (!done || run !== speechRun) return false;
    }
    return true;
  })();
}

// Perekam mikrofon (MediaRecorder → webm/opus, format yang dipakai STT).
// stop() mengembalikan Promise<Blob>.
export async function startRecording() {
  if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
    throw new Error('buka lewat HTTPS atau localhost — browser memblokir mikrofon di HTTP.');
  }
  const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
  const chunks = [];
  const rec = new MediaRecorder(stream);
  rec.ondataavailable = (e) => {
    if (e.data.size) chunks.push(e.data);
  };
  const done = new Promise((resolve) => {
    rec.onstop = () => {
      stream.getTracks().forEach((t) => t.stop());
      resolve(new Blob(chunks, { type: rec.mimeType || 'audio/webm' }));
    };
  });
  rec.start();

  return {
    stop: () => {
      try {
        rec.stop();
      } catch {
        /* already stopped */
      }
      return done;
    },
  };
}
