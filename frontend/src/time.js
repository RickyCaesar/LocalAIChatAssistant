// Jam WITA (Asia/Makassar), dipakai di chat & riwayat antrean.
export const fmtWita = (iso) =>
  iso
    ? new Date(iso).toLocaleTimeString('id-ID', {
        timeZone: 'Asia/Makassar',
        hour: '2-digit',
        minute: '2-digit',
      })
    : '';
