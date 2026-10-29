import { showAlert, showSuccess } from './feedback.js';

// Di HTTPS (HP lewat nginx) API dipanggil same-origin — nginx mem-proxy /api
// ke backend. Di HTTP (localhost) langsung ke port 8000.
export const API_URL =
  import.meta.env.VITE_API_URL ||
  (window.location.protocol === 'https:'
    ? ''
    : `${window.location.protocol}//${window.location.hostname}:8000`);

export function getToken() {
  return localStorage.getItem('hermes-token');
}

export function setToken(token) {
  localStorage.setItem('hermes-token', token);
}

export function clearToken() {
  localStorage.removeItem('hermes-token');
}

// <img> cannot send the Authorization header, so media URLs carry the token
// as a ?auth= query param (accepted by EnsureBearerToken on GET only).
export const mediaUrl = (id) => `${API_URL}/api/media/${id}?auth=${encodeURIComponent(getToken() || '')}`;

export const mediaSrc = (path) => {
  if (/^(?:https?:|data:|blob:)/i.test(path) || !path.startsWith('/api/media/')) return path;
  const url = `${API_URL}${path}`;
  const sep = url.includes('?') ? '&' : '?';
  return `${url}${sep}auth=${encodeURIComponent(getToken() || '')}`;
};

export async function api(path, options = {}) {
  const headers = { Accept: 'application/json', ...(options.headers || {}) };
  if (!(options.body instanceof FormData) && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;

  const res = await fetch(`${API_URL}${path}`, { ...options, headers });

  const contentType = res.headers.get('content-type') || '';
  const data = contentType.includes('application/json') ? await res.json().catch(() => ({})) : null;

  if (res.status === 401 && token) {
    clearToken();
    window.dispatchEvent(new CustomEvent('hermes-auth-expired'));
  }

  if (!res.ok) {
    const msg =
      (data && (data.detail || data.error || data.message)) || `Request failed (${res.status}).`;
    throw new Error(msg);
  }

  const method = (options.method || 'GET').toUpperCase();
  const quiet = options.silent || path.startsWith('/api/login') || path.startsWith('/api/logout') || path.startsWith('/api/chat');
  if (!quiet && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
    showSuccess(
      options.success ||
        (method === 'DELETE' ? 'Data berhasil dihapus.' : method === 'PUT' ? 'Perubahan berhasil disimpan.' : 'Data berhasil diproses.')
    );
  }

  return data;
}

function alertVirus(data) {
  if (data?.code !== 'virus_detected') return;
  showAlert(data.error || 'Berkas ditolak oleh pemindai antivirus.', 'File virus terdeteksi');
}

export async function apiUpload(imageFile) {
  const fd = new FormData();
  fd.append('image', imageFile);
  const res = await fetch(`${API_URL}/api/media`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${getToken()}`, Accept: 'application/json' },
    body: fd,
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && getToken()) {
    clearToken();
    window.dispatchEvent(new CustomEvent('hermes-auth-expired'));
  }
  if (!res.ok) {
    alertVirus(data);
    throw new Error(data.detail || data.error || data.message || `Upload failed (${res.status}).`);
  }
  return data;
}

// Multipart POST for queue endpoints (transcribe/convert). Returns the task.
async function apiUploadTo(path, fd) {
  const res = await fetch(`${API_URL}${path}`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${getToken()}`, Accept: 'application/json' },
    body: fd,
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && getToken()) {
    clearToken();
    window.dispatchEvent(new CustomEvent('hermes-auth-expired'));
  }
  if (!res.ok) {
    alertVirus(data);
    throw new Error(data.detail || data.error || data.message || `Request gagal (${res.status}).`);
  }
  return data;
}

// Queue a background transcription. Returns a task { id, status, ... }.
export function apiQueueTranscribe(audioFile) {
  const fd = new FormData();
  fd.append('audio', audioFile);
  return apiUploadTo('/api/queue/transcribe', fd);
}

// Queue a video -> MP3 conversion. Returns a task.
export function apiQueueConvert(videoFile) {
  const fd = new FormData();
  fd.append('video', videoFile);
  return apiUploadTo('/api/queue/convert', fd);
}

// Queue the AI notulensi build from a transcript.
export function apiQueueNotulensi(source) {
  return api('/api/queue/notulensi', { method: 'POST', body: JSON.stringify({ source }), success: 'Notulensi berhasil masuk antrean.' });
}

// Queue a PowerPoint build from Marp markdown. Returns a task { id, status, ... }.
export function apiGeneratePptx(markdown, filename, theme) {
  return api('/api/presentations', {
    method: 'POST',
    body: JSON.stringify({ markdown, filename, theme }),
    success: 'Presentasi berhasil masuk antrean.',
  });
}

// Ask Hermes to draft Marp markdown. `material` is optional extracted text
// (from an uploaded PDF/docx/etc.) to ground the deck. Returns { markdown }.
export function apiDraftPresentation(prompt, material) {
  return api('/api/presentations/draft', {
    method: 'POST',
    body: JSON.stringify({ prompt, material: material || undefined }),
    success: 'Draf presentasi berhasil dibuat.',
  });
}

// The whole queue for the current user (active first is done client-side).
export function apiQueue() {
  return api('/api/queue');
}

// Notifications: `after` = only items newer than that id (polling for
// toasts/push); without it, the latest 30 (bell dropdown). Returns
// { items, unread, latest }.
export function apiNotifications(after = 0) {
  return api(`/api/notifications${after ? `?after=${after}` : ''}`);
}

export function apiNotificationRead(id) {
  return api(`/api/notifications/${id}/read`, { method: 'POST', silent: true });
}

export function apiNotificationsReadAll() {
  return api('/api/notifications/read-all', { method: 'POST', silent: true });
}

export function apiQueueCancel(id) {
  return api(`/api/queue/${id}`, { method: 'DELETE', success: 'Tugas berhasil dibatalkan atau dihapus.' });
}

// Single task by id (for polling a build).
export function apiQueueTask(id) {
  return api(`/api/queue/${id}`);
}

// ---- Text-to-speech (Piper) & voice input ----

export function apiTtsVoices() {
  return api('/api/tts/voices');
}

// Returns an audio Blob (audio/wav) — bypasses api()'s JSON handling.
export async function apiTts(text, voice, speed) {
  const res = await fetch(`${API_URL}/api/tts`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${getToken()}` },
    body: JSON.stringify({ text, voice, speed }),
  });
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new Error(data.error || `TTS gagal (${res.status}).`);
  }
  return res.blob();
}

// Synchronous transcription (voice mode) — the queue is too slow for
// back-and-forth conversation. Returns { text, language }.
export function apiStt(blob) {
  const fd = new FormData();
  fd.append('audio', blob, 'voice.webm');
  return apiUploadTo('/api/stt', fd);
}

// Stream the /api/chat response (SSE). Resolves with the final reply text.
// onDelta(text) fires with the accumulated text as token chunks arrive.
export function streamChat(payload, onDelta) {
  return new Promise((resolve, reject) => {
    const token = getToken();
    fetch(`${API_URL}/api/chat`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'text/event-stream, application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify({ ...payload, stream: true }),
    }).then(async (res) => {
      const ctype = res.headers.get('content-type') || '';
      if (!res.ok) {
        if (res.status === 401 && token) {
          clearToken();
          window.dispatchEvent(new CustomEvent('hermes-auth-expired'));
        }
        const data = await res.json().catch(() => ({}));
        throw new Error((data && (data.detail || data.error)) || `Request failed (${res.status}).`);
      }

      // Non-stream fallback (gateway/browser without streaming support).
      if (!ctype.includes('text/event-stream') || !res.body) {
        const data = await res.json().catch(() => ({}));
        const reply = (data && data.reply) || '';
        onDelta(reply);
        resolve(reply);
        return;
      }

      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      let acc = '';
      let final = null;

      const done = () => {
        if (final !== null) return resolve(final);
        if (acc) return resolve(acc);
        return reject(new Error('Tidak ada respons dari Hermes, coba lagi.'));
      };

      try {
        for (;;) {
          const { done: isDone, value } = await reader.read();
          if (isDone) break;
          buffer += decoder.decode(value, { stream: true });
          let sep = buffer.indexOf('\n\n');
          while (sep !== -1) {
            const raw = buffer.slice(0, sep);
            buffer = buffer.slice(sep + 2);
            const dataLine = raw.split('\n').find((l) => l.startsWith('data:'));
            if (dataLine) {
              const payloadText = dataLine.slice(5).trim();
              if (payloadText === '[DONE]') continue;
              try {
                const obj = JSON.parse(payloadText);
                if (obj.type === 'final') {
                  final = obj.text;
                } else {
                  const delta = obj.choices?.[0]?.delta?.content;
                  if (typeof delta === 'string' && delta) {
                    acc += delta;
                    onDelta(acc);
                  }
                }
              } catch {
                /* skip malformed event */
              }
            }
            sep = buffer.indexOf('\n\n');
          }
        }
        done();
      } catch (e) {
        reject(e);
      }
    }).catch(reject);
  });
}