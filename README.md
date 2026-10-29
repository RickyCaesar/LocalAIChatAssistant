# Local AI Chat Assistant

Aplikasi web full-stack untuk mengobrol dengan **Hermes Agent** yang berjalan lokal. Selain chat streaming, aplikasi ini menangani berkas (unggah, pratinjau, OCR, transkripsi audio), manajemen tugas & kalender, catatan rapat (notulensi), serta otomasi dokumen (spreadsheet). Proses berat seperti transkripsi audio, konversi video→MP3, dan penyusunan notulensi AI dijalankan **asinkron di antrean (queue)** oleh container worker terpisah, dengan progres dan estimasi selesai yang bisa dipantau dari menu **Antrean**. Frontend dan backend di-containerisasi dengan Docker Compose.

> **Cabang `feature/clamav-scanner`.** Branch ini khusus untuk integrasi ClamAV dan dipisahkan dari proyek utama (`main`) sebagai referensi. Fitur pemindaian ClamAV hanya ada di branch ini; gunakan `main` bila tidak membutuhkannya.

## Fitur

- **Chat AI streaming** (SSE) dengan penyimpanan percakapan di server per akun; posisi selalu di pesan terbaru saat dibuka. Tiap pesan punya **cap waktu (WITA)** di bubble, dan **pemisah tanggal** (Hari ini/Kemarin/tanggal lengkap) muncul sebelum pesan pertama tiap hari. Sesi bisa diganti namanya langsung dari sidebar (ikon pensil) dan tetap tampil di sidebar compact.
- **Hermes Agent sebagai eksekutor**: Agent dapat menjalankan aksi dari balasannya (protokol `ACTION:`) untuk menambah/menyelesaikan todo, membuat event kalender, membuat spreadsheet (XLSX), menyusun presentasi (PPTX) bertema, serta mengirim berkas hasil olahan. Hermes selalu diberi **waktu sekarang (WITA) + tanggal hari ini & besok**, jadi permintaan seperti "jadwalkan besok jam 9" menghasilkan tanggal yang tepat.
- **Unggah & olah berkas**: gambar (OCR via Tesseract), PDF (ekstraksi teks), DOCX/XLSX/PPTX (ekstraksi teks). Berkas bisa dipratinjau langsung di web tanpa unduh. Semua unggahan dipindai **ClamAV** sebelum validasi; berkas terinfeksi ditolak dengan modal peringatan dan notifikasi.
- **Konversi ke PDF**: DOCX/PPTX/XLSX/teks/gambar → PDF memakai LibreOffice. Bisa lewat tombol **Jadikan PDF** di Gallery, atau perintah ke chat (agent menjalankan `tools/topdf.sh` lalu mengirim PDF-nya).
- **Konversi video ke MP3** (menu **ConvertToAudio**): video (MP4/MKV/MOV/…) atau berkas audio lain → MP3. Memakai PyAV (venv STT yang sudah ada), jadi tak perlu `ffmpeg` sistem. Diproses **asinkron di antrean**.
- **Gallery**: daftar media yang dikirim/dihasilkan dengan pratinjau (gambar, PDF, audio, video, teks/dokumen), tampilan **grid/compact**, **pencarian** nama berkas/percakapan/tanggal, **urutan tanggal** terbaru/terlama, filter tipe & sumber, serta detail (tanggal, ukuran, tipe, asal, percakapan, model).
- **Transkripsi audio** (Speech-to-Text) lokal memakai `faster-whisper`; unggah via tombol atau **drag & drop**. Diproses **asinkron di antrean** (container `queue-worker`).
- **Suara AI (Text-to-Speech)** lokal memakai **Piper** (CPU): tombol **Bacakan** di tiap balasan AI, plus **Mode Suara** dua arah (bicara → transkripsi → jawaban dibacakan otomatis → mikrofon siap lagi) — lihat *Mode Suara*.
- **Antrean proses**: satu daftar berisi seluruh proses latar (konversi, transkripsi, notulensi, presentasi) dengan status, **persentase progres**, **estimasi selesai**, posisi dalam antrean, dan **jam selesai (WITA)**. Tombol antrean di header (kiri lonceng notifikasi) menampilkan jumlah proses aktif dan membuka halaman Antrean; progres tetap berjalan saat berpindah menu.
- **Notifikasi**: lonceng di header dengan lencana belum dibaca, toast di kanan atas saat tugas antrean selesai/gagal, dan **notifikasi browser (OS)** opsional saat tab tidak aktif. Tersimpan per akun dan bisa ditandai dibaca.
- **Otomasi dokumen (XLSX)**: dari chat, agent dapat memicu `ACTION: DOC_BUILD <JSON>` untuk menyusun spreadsheet berstruktur (heading, kolom, baris) — misalnya tabel jadwal atau daftar kegiatan — lalu mengirimnya ke chat/Gallery.
- **Notulen rapat (DOCX) built-in**: `ACTION: NOTULEN_BUILD <JSON>` menyusun notulen dengan struktur rapat umum (kop, identitas, ringkasan, agenda, pokok pembahasan, tanya-jawab, keputusan, action items, penutup, tanda tangan) — **tanpa menulis skrip sendiri**; hasilnya masuk ke chat/Gallery.
- **Presentasi (PPTX)**: buat deck dari deskripsi atau bahan referensi. AI menyusun draft **Marp Markdown** (bisa disunting dulu), lalu dirender jadi `.pptx` **asinkron di antrean** dengan **6 tema** (Violet/Sunset/Forest/Midnight/Rose/Slate); hasilnya masuk ke Gallery.
- **Notulensi**: menyusun catatan rapat dari transkrip memakai AI, diproses **asinkron di antrean**.
- **Todo**: prioritas, catatan, tenggat + jam, durasi, tindak lanjut, bukti dari Gallery, dan indikator kinerja yang dapat dikelola per pengguna. Daftar bisa difilter per tanggal dan diurutkan berdasarkan tenggat terdekat/terjauh. Bisa ditampilkan sebagai acara Kalender; perubahan/penghapusan tersinkron dua arah.
- **Kalender**: tampilan Bulan/Minggu/Agenda, **hari libur nasional Indonesia**, acara berulang, filter per indikator, status selesai, serta edit tindak lanjut/bukti langsung dari acara tertaut. Bisa juga dikendalikan dari chat.
- **Laporan Kinerja Bulanan**: matriks kegiatan/jam/bukti per indikator, total bulanan, indikator yang dikelola per pengguna, template kegiatan bulanan, lalu **generate PDF** berisi ringkasan, matriks, rincian, catatan, tanda tangan, dan lampiran. NIP dan jabatan pengguna diambil dari profil; saat generate dapat diisi nama, jabatan, dan NIP pimpinan untuk blok **"Mengetahui,"** di samping tanda tangan Penanggung Jawab.
- **Pengaturan**: timeout, batas ukuran unggah, model & bahasa transkripsi, **suara TTS** (aktif/nonaktif, model suara, kecepatan, tombol tes), **tema tampilan** (Klasik/Senja/Mint) dan **tema gelap** (Nila/Ungu/Hijau) yang **berlaku untuk semua pengguna**. Model AI tidak diatur di sini: aplikasi selalu memakai alias gateway `hermes-agent`, jadi pemilihan model (dan mode gratisnya) sepenuhnya milik konfigurasi Hermes.
- **Daftar Skill Hermes** (khusus admin): menampilkan kemampuan yang dimiliki agent dengan pencarian nama/kategori/deskripsi.
- **Multi-akun & peran**: pengguna biasa dan admin (Dashboard analitik — termasuk **kartu kuota/kredit Hermes**, Kelola Pengguna, Skill, Settings).

## Teknologi

- **Frontend:** React (Vite), Tailwind CSS, react-markdown
- **Backend:** PHP + Laravel (HTTP Client, SQLite)
- **Antivirus:** ClamAV (`clamd`) di container terpisah; backend mengirim berkas lewat protokol `zINSTREAM`
- **Antrean:** Laravel Queue dengan driver `database`, dijalankan oleh container `queue-worker` (`php artisan queue:work`). Job `App\Jobs\RunTask` menangani convert/transcribe/notulensi; progres disimpan di tabel `tasks`.
- **STT & audio:** faster-whisper (CPU, `int8`) untuk transkripsi; PyAV untuk ekstraksi audio → MP3. Keduanya memancarkan progres ke job.
- **TTS:** Piper (CPU, `piper-tts` di venv `/opt/stt`). Model suara id_ID (~63 MB) sudah di-bake ke image di `/opt/tts/voices`; TTS untuk voice mode dipanggil **sinkron** (`POST /api/tts`), hasil WAV di-cache di `storage/app/private/tts`.
- **Spreadsheet:** penulis XLSX minimal di `app/Support/DocumentBuilder.php` (memakai ekstensi `zip`, tanpa dependensi tambahan)
- **Konversi PDF:** LibreOffice headless di dalam container backend
- **Presentasi PPTX:** `@marp-team/marp-cli` + Chromium (sudah ada di image backend) untuk merender Marp Markdown → `.pptx`
- **Hari libur Indonesia:** pustaka `date-holidays` (offline, dihitung per tahun)
- **AI Engine:** Hermes Agent lokal (gateway HTTP)
- **Deployment:** Docker & Docker Compose

## Prasyarat

1. **Docker Desktop** (atau Docker Engine + Docker Compose).
2. **Hermes Agent** berjalan di host dengan gateway HTTP aktif (default `http://127.0.0.1:8642`).

## Menjalankan

1. Salin contoh konfigurasi dan isi `APP_KEY`:

   ```bash
   cp backend/.env.example backend/.env
   touch backend/database.sqlite   # bind-mount SQLite: tanpa file, Docker membuat folder
   docker compose run --rm backend php artisan key:generate --show   # salin hasilnya ke APP_KEY
   ```

2. **Sesuaikan path**: nilai `/home/ROG/MyAssistant` di `AI_SYSTEM_PROMPT` dan `HERMES_OUTPUT_HOST` (`backend/.env`) harus menunjuk ke lokasi repo Anda. `tools/topdf.sh` mengikuti lokasi repo otomatis. `docker-compose.yml` me-mount `backend/storage/app`, `backend/.env`, dan SQLite; dari home hanya `$HOME/.hermes` (read-only).

3. Jalankan dari folder root proyek:

   ```bash
   docker compose up -d --build
   ```

   Compose juga menyalakan `clamav`. Saat pertama kali, image dan signature diunduh (butuh internet dan RAM ±2 GB); tunggu sampai `docker compose ps` menunjukkan `clamav` **healthy** sebelum menguji unggahan.

4. Buat admin pertama (tidak ada registrasi publik):

   ```bash
   docker compose exec backend php artisan app:create-admin
   ```

5. Akses aplikasi:
   - **Frontend (desktop):** http://localhost:5173 — mikrofon tetap jalan karena `localhost` termasuk secure context.
   - **Frontend (HP, satu Wi-Fi):** https://<IP-Windows>:5174 — wajib HTTPS; lihat *Akses dari HP & sertifikat*.
   - **Backend API:** http://localhost:8000

### Akses dari HP & sertifikat

Mikrofon (Mode Suara) hanya tersedia di **secure context**: `localhost` atau HTTPS. Dari HP, buka `https://<IP-Windows>:5174` (di HP Chromium, HTTP biasa membuat `navigator.mediaDevices` undefined — ini yang bikin tombol mikrofon gagal).

- **Buat sertifikat LAN sekali** dari root repo (openssl, tanpa dependensi tambahan):

  ```bash
  openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 3650 \
    -keyout certs/ca-key.pem -out certs/ca.pem -subj "/CN=MyAssistant Local CA" \
    -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign"
  openssl req -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes \
    -keyout certs/key.pem -out /tmp/leaf.csr -subj "/CN=<IP-Windows>"
  printf 'subjectAltName=IP:<IP-Windows>,IP:127.0.0.1,DNS:localhost\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n' > /tmp/leaf.ext
  openssl x509 -req -in /tmp/leaf.csr -CA certs/ca.pem -CAkey certs/ca-key.pem -CAcreateserial \
    -out certs/cert.pem -days 825 -extfile /tmp/leaf.ext
  docker compose up -d frontend
  ```

  Ulangi dengan IP baru kalau IP LAN berubah.
- **Android (Chrome):** cukup buka `https://<IP-Windows>:5174`, lalu *Advanced → Proceed* — mikrofon bisa dipakai. Bebas peringatan: pasang `certs/ca.pem` lewat *Settings → Security → Install a certificate → CA certificate*.
- **iPhone (Safari):** **wajib** pasang CA (Safari memblokir mikrofon pada sertifikat yang belum dipercaya). Sajikan **hanya** `certs/ca.pem` — jangan folder `certs`, ada kunci privat di dalamnya:

  ```bash
  mkdir -p /tmp/ca-share && cp certs/ca.pem /tmp/ca-share/myassistant-ca.crt
  python3 -m http.server 8088 --directory /tmp/ca-share
  ```

  Buka `http://<IP-Windows>:8088/myassistant-ca.crt` di Safari, *Settings → Profile Downloaded → Install*, lalu *Settings → General → About → Certificate Trust Settings* → aktifkan penuh. Hentikan server python setelah selesai.

- Frontend memakai `./certs/cert.pem` + `./certs/key.pem` bila ada; kalau tidak, self-signed bawaan (`10-pick-certs.sh`).
- Kalau di Windows Anda memakai `netsh portproxy` untuk meneruskan ke WSL, tambahkan port **5174** juga.
- Halaman HTTPS memanggil API lewat nginx (`/api` di-proxy ke backend, same-origin), jadi tak ada mixed content dan port 8000 tidak perlu dibuka ke HP.

> Kode backend/frontend **di-bake ke image**. Setiap perubahan kode perlu rebuild:
> `docker compose up -d --build backend frontend` (atau salah satu saja).
>
> `docker compose up -d` juga menyalakan service **`queue-worker`** (memakai image
> backend yang sama). Worker menunggu sampai backend lulus healthcheck, lalu
> menjalankan `php artisan queue:work --tries=3 --timeout=3600`. Karena image-nya
> sama, setelah rebuild backend jalankan `docker compose up -d backend queue-worker`
> agar worker ikut memakai kode terbaru.

## Konfigurasi

### `backend/.env` (salin dari `backend/.env.example`)

| Variabel | Default | keterangan |
|---|---|---|
| `AI_API_URL` | `http://host.docker.internal:8642/v1/chat/completions` | endpoint gateway Hermes |
| `APP_TIMEZONE` | `Asia/Makassar` | zona waktu aplikasi (WITA, UTC+8); stempel waktu & konteks tanggal Hermes mengikuti ini |
| `AI_API_KEY` | `change-me-local-dev` | kunci gateway |
| `AI_MODEL` | `hermes-agent` | alias gateway; model asli (dan-feat-fit gratis) dipilih di Hermes, bukan di aplikasi |
| `AI_TIMEOUT` | `600` | timeout request AI (detik); dapat ditimpa dari Settings dan admin bebas menentukan batasnya |
| `AI_TEMPERATURE` | `0.7` | suhu sampling |
| `AI_SYSTEM_PROMPT` | — | instruksi sistem (termasuk cara mengirim berkas via baris `MEDIA:`) |
| `HERMES_OUTPUT_HOST` | `.../storage/app/private/hermes-output` | folder keluaran berkas agent; hanya folder ini yang diimpor |
| `HERMES_AUTH_FILE` | `/home/ROG/.hermes/auth.json` | (opsional) berkas kredensial Hermes; dipakai kartu kuota/kredit di Dashboard |
| `CORS_ALLOWED_ORIGINS` | `*` | origin yang diizinkan |
| `CLAMAV_ENABLED` | `true` | pindai unggahan dengan clamd; `false` melewati pemindaian |
| `CLAMAV_HOST` | `clamav` | hostname service clamd di Compose |
| `CLAMAV_PORT` | `3310` | port clamd |
| `QUEUE_CONNECTION` | `database` | driver antrean; proses berat dijalankan oleh `queue-worker` |
| `DB_QUEUE_RETRY_AFTER` | `3660` | kapan job yang macet dianggap hilang dan boleh di-retry; dengan satu worker cukup, tapi naikkan bila menambah worker dan `ai_timeout` > 3600 |
| `TTS_ENGINE` | `piper` | mesin TTS lokal (saat ini hanya `piper`) |
| `TTS_MODEL_PATH` | `/app/storage/app/tts_models` | folder model suara tambahan (opsional; model bawaan ada di `/opt/tts/voices` dalam image) |

### Pengaturan dalam aplikasi (menu **Settings**, admin)

Tersimpan di DB (model `Setting`): `ai_timeout`, `max_upload_mb`, `stt_model`, `stt_language`, `tts_enabled`, `tts_voice`, `tts_speed`, `theme_palette`, `theme_dark`. Tema berlaku global (disimpan admin); pilihan mode terang/gelap dan sakelar **Mode Suara** tetap per browser (`localStorage`).

## Integrasi dengan Hermes Agent

Backend berjalan di dalam container yang terisolasi, jadi memakai `host.docker.internal` untuk menjangkau gateway di host (sudah diset via `extra_hosts` di `docker-compose.yml`).

```bash
# contoh menjalankan gateway di host
HERMES_HOME=/home/ROG/.hermes hermes gateway
```

Gateway yang dipakai aplikasi: `POST /v1/chat/completions` (chat, model selalu alias `hermes-agent`) dan `GET /health` (status). Container me-mount `backend/storage/app` (baca-tulis, termasuk folder output `HERMES_OUTPUT_HOST`), `backend/.env`, SQLite, dan `~/.hermes` (read-only); agent tidak lagi mendapat seluruh home directory. Sumber lokal yang ingin diolah harus diunggah lewat aplikasi atau disalin ke folder output.

### Model gratis & rate limit

Aplikasi **tidak pernah memilih model** — semua request memakai alias gateway `hermes-agent`, jadi pemilihan model (dan mode gratisnya) sepenuhnya milik konfigurasi Hermes di `~/.hermes/config.yaml`. Setup gratis yang dipakai:

```yaml
model:
  default: stepfun/step-3.7-flash:free      # SKU :free
  provider: nous
fallback_providers:                          # cadangan, dipakai otomatis saat model utama kena limit
  - provider: nous
    model: poolside/laguna-s-2.1:free
  - provider: nous
    model: upstage/solar-mini4:free
  - provider: nous
    model: ornith/ornith-1.5-122b:free
  - provider: nous
    model: reflection/beam-1.0-501b-a23b:free
auxiliary:
  free_only: true                           # fallback auxiliary ke model berbayar DITOLAK
  title_generation:
    enabled: false                          # judul sesi = turunan kalimat user, tanpa panggilan API
```

- **429 (fair-share free Nous habis)**: Hermes otomatis pindah ke model free berikutnya dan tetap menjawab. Kalau semua fallback ikut kena limit, chat gagal dengan pesan *"Kuota model gratis sedang jenuh (rate limit)…"* (`ChatController::respondBuffered`, `AIController::presentationDraft`). Tidak ada jalur ke model berbayar selama `auxiliary.free_only: true` aktif.
- `auxiliary.title_generation.enabled: false` mematikan panggilan judul AI yang sebelumnya ikut kena 429 — judul sesi tetap ada (turunan kalimat user, instan). Kalau mau judul AI lagi, arahkan ke satu SKU `:free` (mis. `poolside/laguna-s-2.1:free`).
- Cek konfigurasi aktif: `hermes config get fallback_providers`, `hermes config get auxiliary.title_generation.enabled`.
- Daftar SKU `:free` yang tersedia berubah sewaktu-waktu; cek lewat `GET /v1/models` gateway (butuh bearer token).

### Aturan yang ditempel ke pesan user

Setiap pesan user dari app diberi satu blok `[ATURAN APLIKASI MYASSISTANT — WAJIB DIIKUTI]` (`ChatController::runtimeBriefing()`): pakai tool terminal/file, simpan berkas keluaran ke `HERMES_OUTPUT_HOST`, balas dengan tepat satu baris `MEDIA: <path>` per berkas. Tanpa blok ini agent cenderung menyebut "saya tidak punya akses file" dan menyuruh user membuka folder sendiri.

### Aksi dari chat (`ACTION:`)

Selain mengirim berkas lewat baris `MEDIA:`, agent dapat memicu aksi berikut dari balasannya. Baris `ACTION:` disaring dari teks yang tampil ke user dan diganti konfirmasi:

- `ACTION: TODO_ADD <judul>`, `TODO_DONE <judul|#id>`, `TODO_DEL <judul|#id>`
- `ACTION: EVENT_ADD <YYYY-MM-DD HH:MM> | <judul>`, `EVENT_DEL <#id>`
- `ACTION: DOC_BUILD <JSON satu baris>` — menyusun XLSX lalu mengirimnya ke chat/Gallery.
- `ACTION: NOTULEN_BUILD <JSON satu baris>` — menyusun **DOCX notulen rapat** dengan struktur rapat umum lalu mengirimnya ke chat/Gallery. Tidak perlu menulis skrip sendiri lagi.
- `ACTION: GENERATE_PPTX` diikuti blok kode berpagar `marp` — merender Marp Markdown menjadi PPTX. Untuk presentasi yang disunting dulu, gunakan halaman **Presentasi**.

Waktu dan tanggal relatif (`besok`, `lusa`, `minggu depan`) dihitung dari konteks waktu sekarang yang selalu disisipkan ke model: `WAKTU SEKARANG (WITA/Asia/Makassar, UTC+8): <tanggal> <jam>, hari ini ..., besok ...`. Semua jadwal yang dibuat `EVENT_ADD` disimpan dan ditampilkan dalam **WITA**.

Skema JSON `DOC_BUILD` (dijelaskan ke model lewat `ActionRunner::toolsInstruction()`):

```json
{
  "filename": "contoh-dokumen.xlsx",
  "sheet": "Contoh",
  "title": "Contoh Tabel Kegiatan",
  "blocks": [
    {"heading": "Jadwal", "columns": ["No", "Kegiatan", "Tanggal", "Keterangan"],
     "rows": [["1", "Contoh kegiatan", "2026-05-01", "Pagi"]]},
    {"heading": "Catatan", "rows": [["Contoh catatan"]]}
  ]
}
```

Tiap blok boleh punya `heading`, `columns` (baris header tebal), dan `rows`; `columns`/`rows` juga boleh di level atas (dianggap satu blok). Berkas disimpan ke penyimpanan media dan otomatis muncul di **Gallery**.

Skema JSON `NOTULEN_BUILD` (struktur notulen rapat umum — kop, judul, identitas, ringkasan, agenda, pokok pembahasan, tanya-jawab, keputusan, action items, penutup, tanda tangan):

```json
{
  "filename": "Notulen_Rapat.docx",
  "judul": "NOTULENSI RAPAT",
  "subjudul": "Rapat Koordinasi Bulanan",
  "identitas": {"Hari/Tanggal": "Senin, 4 Januari 2027", "Tempat": "Ruang Rapat",
                "Moderator": "Moderator", "Peserta": "Peserta rapat"},
  "ringkasan": ["Rapat membahas agenda kegiatan bulanan."],
  "agenda": ["Pembukaan dan kehadiran peserta"],
  "pembahasan": [{"judul": "A. Pokok Bahasan",
                   "paragraf": ["Penjelasan singkat mengenai pokok bahasan."],
                   "poin": ["Poin pertama", "Poin kedua"]}],
  "tanya_jawab": ["Tidak ada pertanyaan dan jawaban tercatat dalam rekaman."],
  "keputusan": ["Keputusan rapat dicatat pada bagian ini."],
  "action_items": [["Penanggung Jawab", "Tindakan"], ["Peserta", "Menindaklanjuti hasil rapat."]],
  "penutup": ["Demikian notulensi ini disusun berdasarkan rekaman rapat."],
  "penanda_tangan": [["Pimpinan Kegiatan", "Pimpinan"], ["Notulen", "Notulis"]],
  "catatan": ["Sumber: rekaman rapat."]
}
```

Semua kunci opsional: yang tidak diisi dilewati, dan kop instansi dipakai otomatis kalau `kepala` tidak diberikan. Penulis DOCX (`App\Support\NotulenBuilder`) memakai `ZipArchive` seperti `DocumentBuilder` — tanpa gambar, header/footer, dan penomoran halaman (kalau perlu, ganti ke `phpoffice/phpword`).

## Notifikasi

Notifikasi dibuat otomatis saat tugas antrean selesai atau gagal (transkripsi, konversi MP3, notulensi, presentasi).

- **Lonceng** di header: riwayat + jumlah belum dibaca. Klik item untuk menandai dibaca dan pindah ke halaman terkait (Gallery/Antrean).
- **Toast** muncul di kanan atas setiap ada notifikasi baru.
- **Notifikasi browser (OS)**: buka lonceng → **Aktifkan notifikasi browser** → izinkan di dialog browser. Notifikasi OS hanya muncul saat tab sedang tidak aktif.
- Frontend mempoll `GET /api/notifications` tiap 3 detik (bersamaan dengan poll antrean), bukan SSE — lihat *Batasan Diketahui*.

## Mode Suara (TTS & STT)

Perekaman mikrofon butuh **secure context**: `localhost` atau HTTPS — dari HP buka lewat `https://<IP-Windows>:5174` (lihat *Akses dari HP & sertifikat*). Di HTTP biasa, tombol mikrofon menampilkan pesan dan tidak berfungsi.

- **Bacakan**: tiap balasan AI punya tombol **Bacakan** untuk memutar suara (Piper). Klik lagi untuk berhenti; hanya satu audio diputar pada satu waktu.
- **Mode Suara** (tombol gelombang di samping kotak chat): alur hands-free dua arah —
  1. Klik **mikrofon** lalu bicara (klik lagi untuk berhenti & kirim). Rekaman otomatis berhenti setelah **20 detik**.
  2. Audio ditranskripsi **sinkron** lewat `POST /api/stt` (faster-whisper) dan langsung dikirim sebagai pesan.
  3. Balasan AI otomatis dibacakan (`tts_enabled`), lalu mikrofon siap merekam lagi.
  Transkrip kosong/terlalu pendek tidak dikirim — perekaman dimulai ulang otomatis.
- **Model suara default**: `id_ID-news_tts-medium` (Indonesia, di-bake ke image). Menambah suara: taruh pasangan `<nama>.onnx` + `<nama>.onnx.json` (unduh dari [rhasspy/piper-voices](https://huggingface.co/rhasspy/piper-voices)) ke `backend/storage/app/tts_models/`, lalu restart backend — suara langsung muncul di Settings.
- **Latensi**: TTS praktis instan (CPU, kalimat pendek); STT memakai model yang dipilih di Settings. Untuk percakapan, pakai `small`/`base` — `large-v3` bisa > 1 menit per ucapan. Lihat *Batasan Diketahui*.

## Akun & Peran

- Tidak ada registrasi publik. Admin menambah akun lewat menu **Pengguna**; untuk instalasi baru, buat admin pertama dengan `docker compose exec backend php artisan app:create-admin`. Login tetap terbuka di `/api/login`.
- Autentikasi memakai **Bearer token** (disimpan di `localStorage` frontend); hanya URL `/api/media/` yang membawa token via query `?auth=` karena `<img>`/`<iframe>` tidak bisa mengirim header. URL eksternal tidak diberi token.
- **Admin** (kolom `is_admin`) mendapat menu tambahan: Dashboard, Pengguna, Skill, Settings.
- **Menu profil** (foto/initial di header) membuka **Pengaturan akun** (ubah nama, email, NIP, jabatan, dan password sendiri lewat `PUT /api/profile`; ganti password wajib menyertakan password saat ini) dan **Keluar**. NIP dan jabatan yang diisi dipakai pada tanda tangan Laporan Kinerja.
- **Tema tampilan** (palet terang & gelap) diatur admin di Settings dan berlaku **untuk semua pengguna** (tersimpan di tabel `settings`; `POST /api/settings` khusus admin, `GET` tetap terbuka). Toggle mode gelap/terang tetap per pengguna.

## Struktur Proyek

```
backend/            Laravel API
  .env.example                contoh konfigurasi (salin ke .env)
  app/Http/Controllers/Api/   Auth, Chat, Media, Queue, Todo, Event, PerformanceReport, User, Skill, AI, Notification
  app/Http/Middleware/ScanUploads.php  pindai semua berkas request sebelum validasi
  app/Jobs/RunTask.php        job antrean generik (convert / transcribe / notulensi / pptx)
  app/Models/Task.php         status, progress & hasil tiap tugas antrean
  app/Support/Eta.php         rumus estimasi selesai (elapsed/progress)
  app/Providers/AppServiceProvider.php  pragma SQLite (busy_timeout, journal mode)
  app/Support/                FilePreview (ekstraksi teks/OCR), ActionRunner (aksi dari chat),
                              DocumentBuilder (penulis XLSX minimal untuk DOC_BUILD),
                              NotulenBuilder (penulis DOCX notulen untuk NOTULEN_BUILD),
                              PerformanceReportBuilder (laporan kinerja bulanan PDF),
                              TodoEventSync (sinkronisasi dua arah Todo ↔ Kalender),
                              MarpGenerator (Marp Markdown → PPTX via marp-cli + Chromium),
                              NotificationService (notifikasi in-app dari task antrean),
                              ClamAVScanner (stream berkas ke clamd)
  resources/marp/notebook.css tema Marp (variabel warna di-inject dari pilihan tema)
  stt/transcribe.py           skrip faster-whisper (memancarkan progres)
  stt/toaudio.py              ekstrak audio → MP3 via PyAV (memancarkan progres)
  tests/document_builder_check.php  self-check penulis XLSX (php, tanpa framework)
  tests/notulen_builder_check.php  self-check penulis DOCX notulen (php, tanpa framework)
  tests/eta_check.php         self-check rumus ETA (php, tanpa framework)
  tests/clamav_check.php      self-check ClamAV: berkas bersih & EICAR (php, butuh service clamav)
  tests/profile_check.php     self-check endpoint profil + proteksi settings (php, butuh DB)
  tests/performance_check.php self-check sinkronisasi Todo/Kalender, template, matriks & PDF laporan (php, butuh DB)
  tests/timezone_check.php    self-check WITA: konteks Hermes, "besok", konversi ISO client (php, butuh DB)
  routes/api.php              definisi endpoint
frontend/           React + Vite + Tailwind
  src/App.jsx                 shell, navigasi sidebar, chat, polling antrean global
  src/*.jsx                   Gallery, Todo, Calendar, PerformanceReport, Transcribe, Convert, Presentasi, Notulensi, Queue, Skills, Settings, Users, Dashboard, Profile (akun), Notifications (lonceng + toast)
  src/holidays.js             helper hari libur Indonesia (date-holidays)
  src/time.js                 helper format jam & tanggal WITA (chat dan riwayat antrean)
  src/theme.css               override utilitas Tailwind agar memakai variabel tema (diimpor setelah index.css)
tools/topdf.sh      konversi dokumen → PDF via container (dipakai agent dari chat)
docker-compose.yml
```

## Endpoint API (ringkas)

Semua `/api/*` (kecuali `login`/`health`) butuh Bearer token.

- **Auth:** `POST /api/login`, `GET /api/me`, `PUT /api/profile` (nama/email/NIP/jabatan/password sendiri), `POST /api/logout`
- **Sessions:** `GET|POST /api/sessions`, `PUT|DELETE /api/sessions/{id}`
- **Chat:** `POST /api/chat` (streaming SSE)
- **Media:** `GET|POST /api/media`, `GET|DELETE /api/media/{id}` (pratinjau inline; `?download=1` untuk unduh), `POST /api/media/{id}/pdf` (konversi berkas ke PDF)
- **Antrean (asinkron):** `GET /api/queue` (daftar tugas + progress/ETA/posisi), `GET /api/queue/{id}`, `DELETE /api/queue/{id}` (batalkan yang masih menunggu), `POST /api/queue/transcribe` (unggah audio), `POST /api/queue/convert` (unggah video → MP3), `POST /api/queue/notulensi` (`{source}`)
- **Presentasi:** `POST /api/presentations` (Marp Markdown → PPTX, **asinkron**), `POST /api/presentations/draft` (AI menyusun draft Marp Markdown)
- **Notifikasi:** `GET /api/notifications` (`?after=<id>` untuk yang lebih baru; sekaligus mengembalikan `unread`), `POST /api/notifications/{id}/read`, `POST /api/notifications/read-all`
- **Suara (TTS/STT):** `GET /api/tts/voices` (daftar model suara), `POST /api/tts` (`{text, voice?, speed?}` → `audio/wav`), `POST /api/stt` (unggah audio → `{text, language}`, sinkron untuk Mode Suara)
- **Todo:** `GET|POST /api/todos`, `POST /api/todos/{id}/complete`, `PUT|DELETE /api/todos/{id}`
- **Event:** `GET|POST /api/events`, `PUT|DELETE /api/events/{id}`
- **Laporan Kinerja:** `GET /api/performance/plans`, `GET|POST /api/performance/report` (`?month=YYYY-MM`; POST juga menerima `pimpinan_nama`, `pimpinan_jabatan`, `pimpinan_nip`), `POST /api/performance/templates/{key}`
- **Settings:** `GET /api/settings` (semua user), `POST /api/settings` (**admin**)
- **Admin:** `GET /api/analytics`, `GET /api/skills`, `GET|POST /api/users`, `PUT|DELETE /api/users/{id}`

## Batasan Diketahui

- Transkripsi, konversi video→MP3, dan notulensi AI diproses **asinkron di antrean** oleh container `queue-worker`; STT tetap di CPU (mode `int8`) sehingga model besar terasa lambat, tetapi tidak lagi memblokir request lain. Satu worker = antrean berurutan, jadi tugas kedua menunggu yang pertama selesai.
- Persentase progres transkripsi/konversi berasal dari posisi segmen/frame terhadap durasi berkas; untuk notulensi AI, persentase adalah estimasi berbasis waktu (konstanta throughput di `RunTask::runNotulensiStream`, bisa dikalibrasi).
- Laporan Kinerja menghitung Todo ber-indikator dan acara Kalender ber-indikator yang belum tertaut Todo (yang tertaut dihitung sekali lewat Todo). Kegiatan tanpa indikator tidak masuk matriks. Template bulanan dibuat pada tanggal 1 pukul 09:00 dan dapat dibuat ulang bila tombolnya diklik lagi.
- OCR dan ekstraksi teks (gambar/dokumen) masih dilakukan **sinkron saat unggah**; berkas besar atau deck banyak slide bisa membuat unggahan lambat.
- Pemindaian ClamAV bersifat **fail-open**: bila `CLAMAV_ENABLED=false` atau clamd tidak bisa dihubungi, unggahan tetap diproses dan hanya dicatat di log. Ini sengaja agar aplikasi tidak mati saat signature masih dimuat; untuk keamanan ketat, ubah `ClamAVScanner` menjadi gagal-tertutup dan pantau log.
- `php artisan serve` berjalan dengan `PHP_CLI_SERVER_WORKERS=4` (fork bawaan PHP), jadi satu request berat yang belum dipindah ke antrean tidak membekukan healthcheck/API — sisanya menunggu worker kosong. Cukup untuk pemakaian lokal. Laravel hanya mengaktifkan ini dengan flag `--no-reload` (lihat `Dockerfile`); konsekuensinya, mengubah `backend/.env` butuh restart container, bukan auto-reload.
- Server dan `queue-worker` berbagi **satu file SQLite** yang di-mount sebagai file tunggal; karena itu mode jurnal dipaksa `delete` (bukan WAL) + `busy_timeout` 5s agar tidak "database is locked". Untuk konkurensi lebih berat, pindahkan DB ke direktori bersama (agar WAL bisa dipakai) atau ke Postgres.
- `DocumentBuilder` menghasilkan XLSX minimal (tabel/daftar berjudul, header tebal) tanpa rumus/grafik/pivot, dan hanya mendukung `.xlsx`.
- Pembuatan PPTX memakai **marp-cli + Chromium** (Chromium sudah ada untuk konversi). Tema presentasi adalah **6 set tetap**; menambah tema = entri di `Presentasi.jsx` + variabel CSS Marp.
- **Urutan CSS penting**: `theme.css` harus diimpor **setelah** `index.css` (setelah Tailwind) agar variabel tema menang atas utilitas Tailwind yang ber-hex statis. Dijaga oleh `main.jsx`; lihat komentar di kedua berkas.
- STT memakai `faster-whisper` (ffmpeg dibundel lewat PyAV), jadi `ffmpeg`/`whisper.cpp` tak diperlukan; base image backend adalah **Debian** (`php:8.2-cli`), bukan Alpine.
- Berkas Office (DOCX/XLSX/PPTX) tak dirender native oleh browser; pratinjau menampilkan teks hasil ekstraksi. Untuk tata letak asli, konversikan ke PDF (LibreOffice mempertahankan format).
- Konversi PDF berjalan sinkron (beberapa detik per berkas); LibreOffice memuat profil saat pertama kali dipanggil.
- Ekstrak MP3 berjalan sinkron dan menormalkan audio ke **44100 Hz / stereo** (menghindari laju sampling langka yang ditolak encoder MP3); video panjang memakai waktu lebih lama.
- Hari libur dari `date-holidays` hanya mencakup libur nasional (`public`); cuti bersama tidak termasuk.
- Acara berulang di-expand di sisi klien (maks 400 kejadian per event); edit/hapus berlaku untuk **seluruh seri**, bukan satu kejadian.
- Pencarian/filter Gallery, pencarian Skill, dan filter tanggal Todo berjalan di sisi klien karena seluruh data sudah dimuat.
- Nama, jabatan, dan NIP pimpinan pada Laporan Kinerja diisi ulang tiap generate dan tidak disimpan; NIP/jabatan pengguna sendiri tersimpan di profil.
- Token media dikirim lewat query `?auth=` (keterbatasan `<img>`/`<iframe>`), sehingga bisa muncul di log akses.
- Notifikasi diambil lewat **polling 3 detik** (bersamaan dengan poll antrean), bukan SSE/WebSocket: cukup untuk volume sekarang tanpa infrastruktur streaming. Di tab yang tidak aktif, browser men-throttle timer (bisa jadi ~1 menit), jadi notifikasi OS bisa tertunda; upgrade ke Service Worker + Push API untuk notifikasi instan.
- Notifikasi tidak dibuat untuk aksi `ACTION:` (TODO/DOC_BUILD/GENERATE_PPTX) karena semuanya dieksekusi sinkron di dalam request chat — hasilnya sudah tampil di balasan.
- **Zona waktu aplikasi = WITA** (`APP_TIMEZONE=Asia/Makassar`). Skeleton Laravel 12 mengunci `config/app.php` ke UTC, jadi nilainya diterapkan di `AppServiceProvider` (config + default PHP). Data lama digeser **+8 jam** sekali oleh migrasi `..._shift_timestamps_to_wita.php` agar momennya tidak berubah; migrasi itu sengaja tidak punya rollback (data sesudahnya sudah WITA). Tanggal dari client (`...Z`) dikonversi ke WITA di `EventController`/`TodoController` sebelum disimpan.
- Pesan chat lama (sebelum ada cap waktu) tidak menampilkan jam maupun tanggal — field `at` hanya ditulis untuk pesan baru; tidak di-backfill.
- `POST /api/stt` (Mode Suara) sengaja **sinkron** karena jeda antrean (worker poll 3 dtk) terlalu lama untuk percakapan. Berkat 4 worker di atas, request lain tetap dilayani selama transkripsi; tapi tiap STT memakai 1 worker, jadi jangan menumpuk banyak sekaligus (sisa worker bisa habis dan lock SQLite menunggu `busy_timeout`). Dengan model `small` + `int8` klip pendek ≈ 5–10 dtk (panggilan pertama memuat model); dengan `large-v3` bisa > 1 menit — set `small`/`base` di Settings untuk voice mode.
- Mode suara belum memakai VAD (deteksi keheningan): rekaman berhenti lewat klik atau batas 20 detik. Upgrade: `AnalyserNode` untuk memutus otomatis saat hening.
- Kuota free Nous (fair-share) bisa habis di tengah percakapan → Hermes otomatis fallback ke model `:free` berikutnya; kalau semuanya kena limit, chat gagal sampai quota pulih (retry_after dari provider, biasanya puluhan menit). Tidak ada jalur berbayar: `auxiliary.free_only: true` menolak fallback OpenRouter non-`:free`.
- Model AI tidak bisa diganti dari aplikasi (menu Settings) — hanya lewat konfigurasi Hermes. Aplikasi selalu mengirim alias `hermes-agent`.
- TTS memakai Piper (CPU) dengan panjang teks maks 4000 karakter per permintaan; cache WAV disimpan 24 jam di `storage/app/private/tts`.
