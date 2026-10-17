import { apiTts } from './api.js';

// Pemutar TTS tunggal: memulai suara baru otomatis menghentikan yang lama.
let active = null; // { audio, url, resolve }

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

export function stopSpeech() {
  finish(false);
}

// Resolve `true` jika selesai diputar, `false` jika dihentikan/diganti.
export function speak(text, { voice, speed } = {}) {
  stopSpeech();
  return apiTts(text, voice, speed).then(
    (blob) =>
      new Promise((resolve, reject) => {
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
      })
  );
}

// Perekam mikrofon (MediaRecorder → webm/opus, format yang dipakai STT).
// stop() mengembalikan Promise<Blob>.
export async function startRecording() {
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
