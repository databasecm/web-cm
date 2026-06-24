# web-cm — Web Sistem CV. Cimandiri

Aplikasi operasional internal CV. Cimandiri (Integrated Build-Tech Service).
Monolith modular **Laravel 11** dengan dashboard internal **Filament** dan API
ber-versi `/api/v1`. Lihat `CLAUDE.md` dan folder `docs/` untuk konsep produk,
ERD, dan brief teknis.

## Tech Stack

- **Laravel 11** (PHP 8.3+)
- **MySQL 8** (database utama)
- **Redis** (cache, queue, session)
- **Filament v3** (dashboard internal, panel di `/sistem`) — _ditambahkan Task 2_
- **spatie/laravel-permission** + Policy kustom (RBAC) — _ditambahkan Task 3+_
- **Pest** (testing) · **Laravel Pint** (code style, PSR-12)

## Setup Lokal

```bash
# 1. Install dependency
composer install

# 2. Siapkan environment
cp .env.example .env
php artisan key:generate

# 3. Sesuaikan kredensial DB (MySQL) & Redis di .env, lalu migrasi
php artisan migrate
```

> Catatan: `.env.example` default mengarah ke MySQL + Redis. Untuk pengembangan
> cepat tanpa MySQL, set `DB_CONNECTION=sqlite` dan `touch database/database.sqlite`.

## Menjalankan

```bash
php artisan serve        # http://localhost:8000
```

## Testing & Code Style

```bash
./vendor/bin/pest        # jalankan test suite (Pest)
composer test            # alias bawaan Laravel

./vendor/bin/pint        # format kode (PSR-12 / preset Laravel)
./vendor/bin/pint --test # cek tanpa mengubah file
```

## Roadmap (ringkas)

Pengembangan bertahap. **Fase 1 (berjalan):** Fondasi — scaffold, Auth + RBAC 6
level, audit log, 2FA (L1–L3). Detail fase ada di `docs/brief-teknis.md` §11.
