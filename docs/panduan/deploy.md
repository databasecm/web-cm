# Panduan Deploy — web-cm (CV. Cimandiri)

> Dokumen operasional untuk memasang aplikasi ke server produksi. Ditulis dari
> kode & konfigurasi **nyata** dalam repo (composer.json, `config/*`,
> `.env.example`, `.github/workflows/ci.yml`, ADR di `docs/decisions.md`).
> Hal yang belum pasti ditandai **[perlu verifikasi]**.
>
> **Sebelum menerima uang / data nyata**, kerjakan dulu
> [checklist-go-live.md](./checklist-go-live.md) — kumpulan utang pra-produksi
> (gateway, media S3, keamanan) yang **wajib** ditutup.

Stack: **Laravel 11**, PHP **8.4+**, **MySQL 8**, **Redis**, Filament v3
(dashboard `/sistem`), Sanctum (API `/api/v1`). Uang memakai `brick/math`
(BigDecimal) — presisi eksak, bukan float.

---

## 1. Prasyarat server

### 1.1 PHP
- **PHP `^8.4`** (mencakup 8.4 & 8.5). CI menguji pada `8.4`.
- **Ekstensi wajib** (sumber: `.github/workflows/ci.yml` + pemakaian di kode):
  - `mbstring`, `dom`, `curl`, `pdo_mysql`, `redis` (phpredis), `gd`, `bcmath`,
    `intl` — daftar yang di-*setup* CI dan menjadi acuan.
  - `openssl` — enkripsi/hashing framework (standar, biasanya sudah ada).
  - `fileinfo` — deteksi MIME **berbasis isi** file di `MediaService`
    (validasi tipe upload server-side, ADR-0015). Wajib ada.
  - `bcmath` — dipakai `brick/math` untuk kalkulasi uang eksak (ADR-0005).
    (Alternatif `gmp` juga didukung brick/math, tetapi CI memakai `bcmath`.)
  - Ekstensi umum Laravel lain yang biasanya sudah tersedia: `ctype`, `json`,
    `tokenizer`, `xml`, `pcre`.
- **PHP-FPM** untuk dijalankan di belakang Nginx/Apache.

Verifikasi cepat di server:

```bash
php -v                       # harus 8.4+
php -m | grep -Ei 'mbstring|curl|pdo_mysql|redis|gd|bcmath|intl|fileinfo|openssl'
```

### 1.2 Layanan
- **MySQL 8** (CI: `mysql:8.0`). Buat database + user khusus aplikasi.
- **Redis** (CI: `redis:7`) — dipakai untuk **queue**, **cache**, dan
  **session** (lihat `.env.example`: `QUEUE_CONNECTION=redis`,
  `CACHE_STORE=redis`, `SESSION_DRIVER=redis`). Juga menyimpan chat konsultasi
  tamu ber-TTL (tak persist di DB).
- **Composer 2** (untuk `composer install`).
- **Node.js + npm** — ada aset frontend Vite (`package.json`: Vite 6, Tailwind
  3). Diperlukan saat build aset (`npm run build`). **[perlu verifikasi]** versi
  Node yang dipakai tim (Vite 6 umumnya butuh Node ≥ 18).

### 1.3 Web server + PHP-FPM (contoh)

Aplikasi disajikan dari `public/` (front controller `public/index.php`).

**Nginx (contoh minimal):**

```nginx
server {
    listen 443 ssl http2;
    server_name app.contoh.id;

    root /var/www/web-cm/public;
    index index.php;

    # HTTPS wajib di produksi (lihat checklist go-live).
    ssl_certificate     /etc/letsencrypt/live/app.contoh.id/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.contoh.id/privkey.pem;

    client_max_body_size 60M;   # cukup untuk video lapangan (MEDIA_MAX_VIDEO_KB=51200 ≈ 50 MB)

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

> `client_max_body_size` harus ≥ batas upload terbesar. Batas media ada di
> `config/media.php` (image 5 MB, dokumen 10 MB, video 50 MB — semua
> override-able via env). Selaraskan juga `upload_max_filesize` &
> `post_max_size` di `php.ini`.

**Apache:** aktifkan `mod_rewrite`, arahkan DocumentRoot ke `public/`; Laravel
sudah menyertakan `public/.htaccess`.

---

## 2. Langkah deploy

### 2.1 Ambil kode & dependency

```bash
git clone <repo-url> /var/www/web-cm
cd /var/www/web-cm

# Dependency PHP untuk PRODUKSI (tanpa dev, autoloader dioptimasi).
composer install --no-dev --optimize-autoloader

# Aset frontend (Vite). Filament v3 mem-publish asetnya via post-autoload-dump
# (filament:upgrade); aset aplikasi (app.css/js) dibangun oleh Vite:
npm ci
npm run build
```

### 2.2 Konfigurasi environment

```bash
cp .env.example .env
php artisan key:generate           # mengisi APP_KEY (lihat catatan rotasi di §4)
```

Edit `.env` — minimal yang harus diisi/diubah untuk produksi:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.contoh.id
APP_TIMEZONE=Asia/Jakarta          # sudah default di .env.example

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=web_cm
DB_USERNAME=web_cm
DB_PASSWORD=<rahasia>

# Redis (queue/cache/session)
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=<rahasia-bila-ada>
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

# Akun Owner (diseed oleh OwnerSeeder — WAJIB diisi sebelum seeding; jangan commit)
OWNER_NAME="Nama Owner"
OWNER_EMAIL=owner@contoh.id
OWNER_PASSWORD=<password-kuat>
```

> **Jangan pernah commit rahasia.** `.env` tidak masuk git; perbarui hanya
> `.env.example` bila menambah variabel baru.
>
> Variabel go-live lain (S3 media, gateway pembayaran, mail/WA, Sanctum/CORS)
> ada di [checklist-go-live.md](./checklist-go-live.md).

### 2.3 Migrasi & seed

```bash
php artisan migrate --force        # --force wajib di production (non-interaktif)

# Seed peran + akun Owner (butuh OWNER_EMAIL & OWNER_PASSWORD terisi).
php artisan db:seed --force        # [perlu verifikasi] seeder yang dijalankan di prod
```

> `OwnerSeeder` **gagal** bila `OWNER_EMAIL`/`OWNER_PASSWORD` kosong (proteksi
> disengaja). Akun Owner `is_protected` — tak bisa dihapus siapa pun (RBAC §6.1).
> **[perlu verifikasi]** daftar seeder yang layak jalan di produksi (peran +
> Owner + setting default; hindari seeder data contoh).

### 2.4 Cache konfigurasi & storage link

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link           # symlink storage/app/public → public/storage
```

> Setelah **setiap** perubahan `.env` di produksi, jalankan ulang
> `php artisan config:cache` (nilai env yang sudah di-cache tidak ikut berubah
> otomatis). Untuk membersihkan: `php artisan optimize:clear`.

### 2.5 Queue worker — WAJIB jalan

Notifikasi in-app (Fase 7, semua `ShouldQueue`) **dan** pemrosesan callback
pembayaran (`App\Jobs\ProcessPaymentCallback`, di-*dispatch* dari
`POST /api/v1/payments/webhook`) berjalan **asinkron lewat queue Redis**. **Bila
worker tidak berjalan, notifikasi tak terkirim dan pembayaran webhook tak
diproses.**

Gunakan **Supervisor** agar worker selalu hidup & auto-restart:

```ini
; /etc/supervisor/conf.d/web-cm-worker.conf
[program:web-cm-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/web-cm/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600            ; beri waktu job selesai saat restart (graceful)
user=www-data
numprocs=1                   ; naikkan bila beban notifikasi/webhook tinggi
redirect_stderr=true
stdout_logfile=/var/www/web-cm/storage/logs/worker.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start web-cm-worker:*
```

> **Setelah setiap deploy** yang mengubah kode, restart worker agar memuat kode
> baru: `php artisan queue:restart` (worker akan berhenti dengan rapi lalu
> di-spawn ulang oleh Supervisor).

### 2.6 Scheduler (bila diperlukan)

Saat ini **belum ada task terjadwal** yang didaftarkan (`routes/console.php`
hanya berisi contoh `inspire`). Payroll mingguan (Sabtu) saat ini adalah **aksi
manual HR** (generate → bayar), bukan cron. **[perlu verifikasi]** — bila nanti
ada task terjadwal (mis. otomasi payroll, pembersihan), pasang satu cron:

```cron
* * * * * cd /var/www/web-cm && php artisan schedule:run >> /dev/null 2>&1
```

---

## 3. Alur rilis ulang (deploy berikutnya)

```bash
cd /var/www/web-cm
php artisan down                    # mode maintenance (opsional)
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart           # muat kode baru di worker
php artisan up
```

---

## 4. Backup & keamanan

- **Backup database** terjadwal (mis. `mysqldump` harian + retensi). Ini sumber
  kebenaran seluruh operasional (proyek, pembayaran, payroll, audit_logs).
- **Storage privat = dokumen sensitif.** Media disimpan di disk **privat**
  (`config/filesystems.php` → disk `media`, root `storage/app/media`), **di luar
  web root** — dokumen pembiayaan (KTP/slip gaji), desain, BAST, foto lapangan
  **tidak boleh** dapat diakses langsung via URL publik. Akses hanya lewat route
  bertanda-tangan yang **tetap mengecek policy** saat serve (ADR-0015). Ikutkan
  `storage/app/media` (atau bucket S3) dalam strategi backup.
- **Rotasi `APP_KEY` — hati-hati, jangan sembarangan.** `APP_KEY` menjadi dasar:
  - **tanda tangan URL media** (signed/temporary URL, ADR-0015) — rotasi
    meng-*invalidate* semua URL media yang sedang beredar;
  - **enkripsi cookie/session** — rotasi memaksa logout semua sesi;
  - **kolom terenkripsi** — rahasia 2FA Fortify (`two_factor_secret`,
    `two_factor_recovery_codes`) dienkripsi dengan `APP_KEY`; **rotasi tanpa
    migrasi ulang akan merusak 2FA akun yang sudah aktif**. Rotasi hanya dengan
    prosedur re-enkripsi yang direncanakan, bukan mendadak.
- **`APP_DEBUG=false`** di produksi (jangan bocorkan stack trace).
- **HTTPS** wajib; lihat item keamanan lengkap di
  [checklist-go-live.md](./checklist-go-live.md).
- **Semua mutasi akun & keuangan tertulis di `audit_logs`** (RBAC §6.6); pointer
  berkas sensitif ter-redaksi di jejak audit. Jaga tabel ini (jangan dipangkas
  tanpa arsip).

---

## 5. Rujukan

- Konteks proyek & aturan: `CLAUDE.md`, `docs/konsep-v2.md`,
  `docs/brief-teknis.md`, `docs/erd-database.md`.
- Keputusan arsitektur (sumber utang pra-produksi): `docs/decisions.md` (ADR).
- **Checklist go-live (wajib sebelum produksi):**
  [checklist-go-live.md](./checklist-go-live.md).
