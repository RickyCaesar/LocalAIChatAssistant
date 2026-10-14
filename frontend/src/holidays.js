import Holidays from 'date-holidays';

const id = new Holidays('ID');
const cache = new Map();

// ponytail: hanya libur nasional (type 'public'); cuti bersama tidak termasuk (SKB berubah tiap tahun).
// Ganti ke sumber resmi bila cuti bersama wajib akurat.
export function holidaysForYear(year) {
  if (cache.has(year)) return cache.get(year);
  const map = {};
  for (const h of id.getHolidays(year)) {
    if (h.type !== 'public') continue;
    const key = h.date.slice(0, 10);
    if (!map[key]) map[key] = h.name;
  }
  cache.set(year, map);
  return map;
}
