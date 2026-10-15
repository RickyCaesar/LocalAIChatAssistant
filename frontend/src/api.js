export const API_URL =
  import.meta.env.VITE_API_URL ||
  `${window.location.protocol}//${window.location.hostname}:8000`;

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
  const url = path.startsWith('http') ? path : `${API_URL}${path}`;
  const sep = url.includes('?') ? '&' : '?';
  return `${url}${sep}auth=${encodeURIComponent(getToken() || '')}`;
};

export async function api(path, options = {}) {
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json', ...(options.headers || {}) };
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

  return data;
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
  return api('/api/queue/notulensi', { method: 'POST', body: JSON.stringify({ source }) });
}

// The whole queue for the current user (active first is done client-side).
export function apiQueue() {
  return api('/api/queue');
}

export function apiQueueCancel(id) {
  return api(`/api/queue/${id}`, { method: 'DELETE' });
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