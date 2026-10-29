// Jam/tanggal WITA (Asia/Makassar), dipakai di chat & riwayat antrean.
export const fmtWita = (iso) =>
  iso
    ? new Date(iso).toLocaleTimeString('id-ID', {
        timeZone: 'Asia/Makassar',
        hour: '2-digit',
        minute: '2-digit',
      })
    : '';

export const fmtWitaDate = (iso) => {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  const day = date.toLocaleDateString('id-ID', { timeZone: 'Asia/Makassar' });
  const today = new Date().toLocaleDateString('id-ID', { timeZone: 'Asia/Makassar' });
  const yesterday = new Date(Date.now() - 86400000).toLocaleDateString('id-ID', { timeZone: 'Asia/Makassar' });
  if (day === today) return 'Hari ini';
  if (day === yesterday) return 'Kemarin';
  return date.toLocaleDateString('id-ID', { timeZone: 'Asia/Makassar', day: 'numeric', month: 'long', year: 'numeric' });
};
