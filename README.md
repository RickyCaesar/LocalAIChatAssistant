# Local AI Chat Assistant

Aplikasi web full-stack untuk mengobrol dengan **Hermes Agent** yang berjalan lokal. Selain chat streaming, aplikasi ini menangani berkas (unggah, pratinjau, OCR, transkripsi audio), manajemen tugas & kalender, catatan rapat (notulensi), serta otomasi dokumen (spreadsheet). Proses berat seperti transkripsi audio, konversi video→MP3, dan penyusunan notulensi AI dijalankan **asinkron di antrean (queue)** oleh container worker terpisah, dengan progres dan estimasi selesai yang bisa dipantau dari menu **Antrean**. Frontend dan backend di-containerisasi dengan Docker Compose.

## Fitur

- **Chat AI streaming** (SSE) dengan penyimpanan percakapan di server per akun; posisi selalu di pesan terbaru saat dibuka.
- **Hermes Agent sebagai eksekutor**: Agent dapat menjalankan aksi dari balasannya (protokol `ACTION:`) untuk menambah/menyelesaikan todo, membuat event kalender, membuat spreadsheet (XLSX), serta mengirim berkas hasil olahan.
- **Unggah & olah berkas**: gambar (OCR via Tesseract), PDF (ekstraksi teks), DOCX/XLSX/PPTX (ekstraksi teks). Berkas bisa dipratinjau langsung di web tanpa unduh.
- **Konversi ke PDF**: DOCX/PPTX/XLSX/teks/gambar → PDF memakai LibreOffice. Bisa lewat tombol **Jadikan PDF** di Gallery, atau perintah ke chat (agent menjalankan `tools/topdf.sh` lalu mengirim PDF-nya).
- **Konversi video ke MP3** (menu **ConvertToAudio**): video (MP4/MKV/MOV/…) atau berkas audio lain → MP3. Memakai PyAV (venv STT yang sudah ada), jadi tak perlu `ffmpeg` sistem. Diproses **asinkron di antrean**.
- **Gallery**: daftar media yang dikirim/dihasilkan dengan pratinjau (gambar, PDF, audio, video, teks/dokumen), tampilan **grid/compact**, **pencarian**, filter tipe & sumber, serta detail (tanggal, ukuran, tipe, asal, percakapan, model).
- **Transkripsi audio** (Speech-to-Text) lokal memakai `faster-whisper`; unggah via tombol atau **drag & drop**. Diproses **asinkron di antrean** (container `queue-worker`).
- **Antrean proses (menu Antrean)**: satu daftar berisi seluruh proses latar (konversi, transkripsi, notulensi) dengan status, **persentase progres**, **estimasi selesai**, dan posisi dalam antrean. Indikator ringkas tampil di header dari menu mana pun, dan progres tetap berjalan saat berpindah menu.
- **Otomasi dokumen (XLSX)**: dari chat, agent dapat memicu `ACTION: DOC_BUILD <JSON>` untuk menyusun spreadsheet berstruktur (heading, kolom, baris) — mis. tabel penugasan reviewer atau daftar tata tertib acara — lalu mengirimnya ke chat/Gallery.
- **Notulensi**: menyusun catatan rapat dari transkrip memakai AI, diproses **asinkron di antrean**.
- **Todo List**: prioritas (tinggi/sedang/rendah), catatan, edit inline, due date + jam, filter Semua/Aktif/Selesai.
- **Kalender**: tampilan Bulan/Minggu/Agenda, **hari libur nasional Indonesia**, acara berulang (mingguan/bulanan/tahunan), edit/hapus. Bisa juga dikendalikan dari chat.
- **Pengaturan**: model AI, timeout, batas ukuran unggah, model & bahasa transkripsi, dan tema tampilan (Klasik/Senja/Mint).
- **Daftar Skill Hermes** (khusus admin): menampilkan kemampuan yang dimiliki agent.
- **Multi-akun & peran**: pengguna biasa dan admin (Dashboard analitik, Kelola Pengguna, Skill, Settings).

## Teknologi

- **Frontend:** React (Vite), Tailwind CSS, react-markdown
- **Backend:** PHP + Laravel (HTTP Client, SQLite)
- **Antrean:** Laravel Queue dengan driver `database`, dijalankan oleh container `queue-worker` (`php artisan queue:work`). Job `App\Jobs\RunTask` menangani convert/transcribe/notulensi; progres disimpan di tabel `tasks`.
- **STT & audio:** faster-whisper (CPU, `int8`) untuk transkripsi; PyAV untuk ekstraksi audio → MP3. Keduanya memancarkan progres ke job.
- **Spreadsheet:** penulis XLSX minimal di `app/Support/DocumentBuilder.php` (memakai ekstensi `zip`, tanpa dependensi tambahan)
- **Konversi PDF:** LibreOffice headless di dalam container backend
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
   docker compose run --rm backend php artisan key:generate --show   # salin hasilnya ke APP_KEY
   ```

2. **Sesuaikan path**: nilai `/home/ROG/MyAssistant` di `backend/.env` dan `tools/topdf.sh` harus menunjuk ke lokasi repo Anda. `docker-compose.yml` me-mount `$HOME`, jadi letakkan repo di dalam home directory.

3. Jalankan dari folder root proyek:

   ```bash
   docker compose up -d --build
   ```

4. Akses aplikasi:
   - **Frontend:** http://localhost:5173
   - **Backend API:** http://localhost:8000

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
| `AI_API_KEY` | `change-me-local-dev` | kunci gateway |
| `AI_MODEL` | `hermes-agent` | nama model |
| `AI_TIMEOUT` | `600` | timeout request AI (detik), dapat ditimpa dari Settings |
| `AI_TEMPERATURE` | `0.7` | suhu sampling |
| `AI_SYSTEM_PROMPT` | — | instruksi sistem (termasuk cara mengirim berkas via baris `MEDIA:`) |
| `HERMES_OUTPUT_HOST` | `.../storage/app/private` | folder keluaran berkas agent |
| `CORS_ALLOWED_ORIGINS` | `*` | origin yang diizinkan |
| `QUEUE_CONNECTION` | `database` | driver antrean; proses berat dijalankan oleh `queue-worker` |
| `DB_QUEUE_RETRY_AFTER` | `3660` | kapan job boleh di-retry; **harus > `--timeout` worker** (3600) |

### Pengaturan dalam aplikasi (menu **Settings**, admin)

Tersimpan di DB (model `Setting`): `ai_model`, `ai_timeout`, `max_upload_mb`, `stt_model`, `stt_language`, dan pilihan tema.

## Integrasi dengan Hermes Agent

Backend berjalan di dalam container yang terisolasi, jadi memakai `host.docker.internal` untuk menjangkau gateway di host (sudah diset via `extra_hosts` di `docker-compose.yml`).

```bash
# contoh menjalankan gateway di host
HERMES_HOME=/home/ROG/.hermes hermes gateway
```

Gateway yang dipakai aplikasi: `GET /v1/models` (daftar model) dan `POST /v1/chat/completions` (chat). Folder `/home/ROG` di-mount read-only ke container agar agent bisa membaca sumber lokal dan menulis berkas hasil ke `HERMES_OUTPUT_HOST`.

### Aksi dari chat (`ACTION:`)

Selain mengirim berkas lewat baris `MEDIA:`, agent dapat memicu aksi berikut dari balasannya. Baris `ACTION:` disaring dari teks yang tampil ke user dan diganti konfirmasi:

- `ACTION: TODO_ADD <judul>`, `TODO_DONE <judul|#id>`, `TODO_DEL <judul|#id>`
- `ACTION: EVENT_ADD <YYYY-MM-DD HH:MM> | <judul>`, `EVENT_DEL <#id>`
- `ACTION: DOC_BUILD <JSON satu baris>` — menyusun XLSX lalu mengirimnya ke chat/Gallery.

Skema JSON `DOC_BUILD` (dijelaskan ke model lewat `ActionRunner::toolsInstruction()`):

```json
{
  "filename": "penugasan-reviewer.xlsx",
  "sheet": "TKA",
  "title": "Penugasan Reviewer Tes Kemampuan Akademik",
  "blocks": [
    {"heading": "Jadwal", "columns": ["No", "Reviewer", "Tanggal", "Sesi"],
     "rows": [["1", "Budi", "2026-05-01", "Pagi"]]},
    {"heading": "Tata Tertib Acara", "rows": [["Datang 15 menit lebih awal"]]}
  ]
}
```

Tiap blok boleh punya `heading`, `columns` (baris header tebal), dan `rows`; `columns`/`rows` juga boleh di level atas (dianggap satu blok). Berkas disimpan ke penyimpanan media dan otomatis muncul di **Gallery**.

## Akun & Peran

- Registrasi & login terbuka melalui `/api/register` dan `/api/login`.
- Autentikasi memakai **Bearer token** (disimpan di `localStorage` frontend); URL media membawa token via query `?auth=` karena `<img>`/`<iframe>` tidak bisa mengirim header.
- **Admin** (kolom `is_admin`) mendapat menu tambahan: Dashboard, Pengguna, Skill, Settings.

## Struktur Proyek

```
backend/            Laravel API
  .env.example                contoh konfigurasi (salin ke .env)
  app/Http/Controllers/Api/   Auth, Chat, Media, Queue, Todo, Event, User, Skill, AI
  app/Jobs/RunTask.php        job antrean generik (convert / transcribe / notulensi)
  app/Models/Task.php         status, progress & hasil tiap tugas antrean
  app/Support/Eta.php         rumus estimasi selesai (elapsed/progress)
  app/Providers/AppServiceProvider.php  pragma SQLite (busy_timeout, journal mode)
  app/Support/                FilePreview (ekstraksi teks/OCR), ActionRunner (aksi dari chat),
                              DocumentBuilder (penulis XLSX minimal untuk DOC_BUILD)
  stt/transcribe.py           skrip faster-whisper (memancarkan progres)
  stt/toaudio.py              ekstrak audio → MP3 via PyAV (memancarkan progres)
  tests/document_builder_check.php  self-check penulis XLSX (php, tanpa framework)
  tests/eta_check.php         self-check rumus ETA (php, tanpa framework)
  routes/api.php              definisi endpoint
frontend/           React + Vite + Tailwind
  src/App.jsx                 shell, navigasi sidebar, chat, polling antrean global
  src/*.jsx                   Gallery, Todo, Calendar, Transcribe, Convert, Notulensi, Queue, Skills, Settings, Users, Dashboard
  src/holidays.js             helper hari libur Indonesia (date-holidays)
tools/topdf.sh      konversi dokumen → PDF via container (dipakai agent dari chat)
docker-compose.yml
```

## Endpoint API (ringkas)

Semua `/api/*` (kecuali `register`/`login`/`health`) butuh Bearer token.

- **Auth:** `POST /api/register`, `POST /api/login`, `GET /api/me`, `POST /api/logout`
- **Sessions:** `GET|POST /api/sessions`, `PUT|DELETE /api/sessions/{id}`
- **Chat:** `POST /api/chat` (streaming SSE), `GET /api/models`
- **Media:** `GET|POST /api/media`, `GET|DELETE /api/media/{id}` (pratinjau inline; `?download=1` untuk unduh), `POST /api/media/{id}/pdf` (konversi berkas ke PDF)
- **Antrean (asinkron):** `GET /api/queue` (daftar tugas + progress/ETA/posisi), `GET /api/queue/{id}`, `DELETE /api/queue/{id}` (batalkan yang masih menunggu), `POST /api/queue/transcribe` (unggah audio), `POST /api/queue/convert` (unggah video → MP3), `POST /api/queue/notulensi` (`{source}`)
- **Todo:** `GET|POST /api/todos`, `PUT|DELETE /api/todos/{id}`
- **Event:** `GET|POST /api/events`, `PUT|DELETE /api/events/{id}`
- **Settings:** `GET /api/settings`, `POST /api/settings`, `POST /api/settings/model`
- **Admin:** `GET /api/analytics`, `GET /api/skills`, `GET|POST /api/users`, `PUT|DELETE /api/users/{id}`

## Batasan Diketahui

- Transkripsi, konversi video→MP3, dan notulensi AI diproses **asinkron di antrean** oleh container `queue-worker`; STT tetap di CPU (mode `int8`) sehingga model besar terasa lambat, tetapi tidak lagi memblokir request lain. Satu worker = antrean berurutan, jadi tugas kedua menunggu yang pertama selesai.
- Persentase progres transkripsi/konversi berasal dari posisi segmen/frame terhadap durasi berkas; untuk notulensi AI, persentase adalah estimasi berbasis waktu (konstanta throughput di `RunTask::runNotulensiStream`, bisa dikalibrasi).
- OCR dan ekstraksi teks (gambar/dokumen) masih dilakukan **sinkron saat unggah**; berkas besar atau deck banyak slide bisa membuat unggahan lambat.
- `php artisan serve` berjalan single-thread, jadi request berat yang belum dipindah ke antrean bisa saling menunggu. Cukup untuk pemakaian lokal.
- Server dan `queue-worker` berbagi **satu file SQLite** yang di-mount sebagai file tunggal; karena itu mode jurnal dipaksa `delete` (bukan WAL) + `busy_timeout` 5s agar tidak "database is locked". Untuk konkurensi lebih berat, pindahkan DB ke direktori bersama (agar WAL bisa dipakai) atau ke Postgres.
- `DocumentBuilder` menghasilkan XLSX minimal (tabel/daftar berjudul, header tebal) tanpa rumus/grafik/pivot, dan hanya mendukung `.xlsx`.
- STT memakai `faster-whisper` (ffmpeg dibundel lewat PyAV), jadi `ffmpeg`/`whisper.cpp` tak diperlukan; base image backend adalah **Debian** (`php:8.2-cli`), bukan Alpine.
- Berkas Office (DOCX/XLSX/PPTX) tak dirender native oleh browser; pratinjau menampilkan teks hasil ekstraksi. Untuk tata letak asli, konversikan ke PDF (LibreOffice mempertahankan format).
- Konversi PDF berjalan sinkron (beberapa detik per berkas); LibreOffice memuat profil saat pertama kali dipanggil.
- Ekstrak MP3 berjalan sinkron dan menormalkan audio ke **44100 Hz / stereo** (menghindari laju sampling langka yang ditolak encoder MP3); video panjang memakai waktu lebih lama.
- Hari libur dari `date-holidays` hanya mencakup libur nasional (`public`); cuti bersama tidak termasuk.
- Acara berulang di-expand di sisi klien (maks 400 kejadian per event); edit/hapus berlaku untuk **seluruh seri**, bukan satu kejadian.
- Pencarian & filter Gallery berjalan di sisi klien (memuat seluruh daftar media).
- Token media dikirim lewat query `?auth=` (keterbatasan `<img>`/`<iframe>`), sehingga bisa muncul di log akses.
