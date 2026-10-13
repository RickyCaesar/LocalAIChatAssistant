import { api } from './api.js';

let byId = {};

export function getMediaById(id) {
  return byId[id];
}

export async function refreshMedia() {
  try {
    const data = await api('/api/media');
    const map = {};
    for (const m of data.media) map[m.id] = m;
    byId = map;
  } catch {
    /* keep previous cache */
  }
}