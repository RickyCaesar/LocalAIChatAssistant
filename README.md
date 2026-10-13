# Local AI Chat Assistant

Aplikasi web full-stack untuk mengobrol dengan **Hermes Agent** yang berjalan lokal. Selain chat streaming, aplikasi ini menangani berkas (unggah, pratinjau, OCR, transkripsi audio), manajemen tugas & kalender, serta catatan rapat (notulensi). Frontend dan backend di-containerisasi dengan Docker Compose.

## Fitur

- **Chat AI streaming** (SSE) dengan penyimpanan percakapan di server per akun.
- **Hermes Agent sebagai eksekutor**: Agent dapat menjalankan aksi dari balasannya (protokol `ACTION:`) untuk menambah/menyelesaikan todo dan event kalender, serta mengirim berkas hasil olahan.
- **Unggah & olah berkas**: gambar (OCR via Tesseract), PDF (ekstraksi teks), DOCX/XLSX/PPTX (ekstraksi teks). Berkas bisa dipratinjau langsung di web tanpa unduh.
- **Konversi ke PDF**: DOCX/PPTX/XLSX/teks/gambar → PDF memakai LibreOffice. Bisa lewat tombol **Jadikan PDF** di Gallery, atau perintah ke chat (agent menjalankan `tools/topdf.sh` lalu mengirim PDF-nya).
- **Gallery**: daftar media yang dikirim/dihasilkan, dengan pratinjau (gambar, PDF, audio, video, teks/dokumen) dan tombol unduh.
- **Transkripsi audio** (Speech-to-Text) lokal memakai `faster-whisper` di container backend.
- **Notulensi**: menyusun catatan rapat dari transkrip memakai AI.
- **Todo List** dan **Kalender** (CRUD + dikendalikan dari chat).
- **Pengaturan**: model AI, timeout, batas ukuran unggah, model & bahasa transkripsi, dan tema tampilan (Klasik/Senja/Mint).
- **Daftar Skill Hermes** (khusus admin): menampilkan kemampuan yang dimiliki agent.
- **Multi-akun & peran**: pengguna biasa dan admin (Dashboard analitik, Kelola Pengguna, Skill, Settings).

## Teknologi

- **Frontend:** React (Vite), Tailwind CSS, react-markdown
- **Backend:** PHP + Laravel (HTTP Client, SQLite)
- **STT:** faster-whisper (CPU, `int8`) di dalam container backend
- **Konversi PDF:** LibreOffice headless di dalam container backend
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

### Pengaturan dalam aplikasi (menu **Settings**, admin)

Tersimpan di DB (model `Setting`): `ai_model`, `ai_timeout`, `max_upload_mb`, `stt_model`, `stt_language`, dan pilihan tema.

## Integrasi dengan Hermes Agent

Backend berjalan di dalam container yang terisolasi, jadi memakai `host.docker.internal` untuk menjangkau gateway di host (sudah diset via `extra_hosts` di `docker-compose.yml`).

```bash
# contoh menjalankan gateway di host
HERMES_HOME=/home/ROG/.hermes hermes gateway
```

Gateway yang dipakai aplikasi: `GET /v1/models` (daftar model) dan `POST /v1/chat/completions` (chat). Folder `/home/ROG` di-mount read-only ke container agar agent bisa membaca sumber lokal dan menulis berkas hasil ke `HERMES_OUTPUT_HOST`.

## Akun & Peran

- Registrasi & login terbuka melalui `/api/register` dan `/api/login`.
- Autentikasi memakai **Bearer token** (disimpan di `localStorage` frontend); URL media membawa token via query `?auth=` karena `<img>`/`<iframe>` tidak bisa mengirim header.
- **Admin** (kolom `is_admin`) mendapat menu tambahan: Dashboard, Pengguna, Skill, Settings.

## Struktur Proyek

```
backend/            Laravel API
  .env.example                contoh konfigurasi (salin ke .env)
  app/Http/Controllers/Api/   Auth, Chat, Media, Transcribe, Todo, Event, User, Skill, AI
  app/Support/                FilePreview (ekstraksi teks/OCR), ActionRunner (aksi dari chat)
  stt/transcribe.py           skrip faster-whisper
  routes/api.php              definisi endpoint
frontend/           React + Vite + Tailwind
  src/App.jsx                 shell, navigasi sidebar, chat
  src/*.jsx                   Gallery, Todo, Calendar, Transcribe, Notulensi, Skills, Settings, Users, Dashboard
tools/topdf.sh      konversi dokumen → PDF via container (dipakai agent dari chat)
docker-compose.yml
```

## Endpoint API (ringkas)

Semua `/api/*` (kecuali `register`/`login`/`health`) butuh Bearer token.

- **Auth:** `POST /api/register`, `POST /api/login`, `GET /api/me`, `POST /api/logout`
- **Sessions:** `GET|POST /api/sessions`, `PUT|DELETE /api/sessions/{id}`
- **Chat:** `POST /api/chat` (streaming SSE), `GET /api/models`
- **Media:** `GET|POST /api/media`, `GET|DELETE /api/media/{id}` (pratinjau inline; `?download=1` untuk unduh), `POST /api/media/{id}/pdf` (konversi berkas ke PDF)
- **Transkripsi:** `POST /api/transcribe`
- **Todo:** `GET|POST /api/todos`, `PUT|DELETE /api/todos/{id}`
- **Event:** `GET|POST /api/events`, `PUT|DELETE /api/events/{id}`
- **Settings:** `GET /api/settings`, `POST /api/settings`, `POST /api/settings/model`
- **Admin:** `GET /api/analytics`, `GET /api/skills`, `GET|POST /api/users`, `PUT|DELETE /api/users/{id}`

## Batasan Diketahui

- `php artisan serve` berjalan single-thread: selama satu request panjang (mis. transkripsi) berjalan, request lain menunggu. Cukup untuk pemakaian lokal.
- OCR dan ekstraksi dokumen dilakukan sinkron saat unggah; deck besar (banyak slide) bisa lambat.
- Transkripsi berjalan di CPU (mode `int8`), sehingga model besar terasa lambat.
- Berkas Office (DOCX/XLSX/PPTX) tak dirender native oleh browser; pratinjau menampilkan teks hasil ekstraksi. Untuk tata letak asli, konversikan ke PDF (LibreOffice mempertahankan format).
- Konversi PDF berjalan sinkron (beberapa detik per berkas); LibreOffice memuat profil saat pertama kali dipanggil.
- Token media dikirim lewat query `?auth=` (keterbatasan `<img>`/`<iframe>`), sehingga bisa muncul di log akses.
