# Checklist Go-Live — Swap Mode Dev → Produksi

> Kumpulan **utang pra-produksi** yang tersebar di ADR (`docs/decisions.md`),
> dijadikan satu daftar operasional. Kerjakan **sebelum** aplikasi menerima uang
> atau data nyata. Untuk langkah pasang server, lihat [deploy.md](./deploy.md).
>
> Pola umum sistem ini **A3**: fitur diabstraksikan dulu dengan implementasi
> "simulasi/lokal", lalu ditukar ke yang nyata **via config saja, tanpa mengubah
> alur/pemicu**. Checklist ini adalah daftar swap tersebut.

Legenda: **[WAJIB]** sebelum go-live · **[OPSIONAL]** sesuai kebutuhan ·
**[perlu verifikasi]** butuh dicek ke kondisi nyata.

---

## 1. Media → object storage S3-compatible (ADR-0015) **[WAJIB]**

Di dev/test media disimpan di disk **lokal privat** (`MEDIA_DISK=media`). Saat
go-live, tukar ke S3-compatible — **hanya konfigurasi**, tanpa perubahan kode.

- [ ] Set di `.env`:
  ```dotenv
  MEDIA_DISK=s3
  AWS_ACCESS_KEY_ID=<key>
  AWS_SECRET_ACCESS_KEY=<secret>
  AWS_DEFAULT_REGION=<region>
  AWS_BUCKET=<bucket-privat>
  AWS_ENDPOINT=<https://endpoint-s3-compatible>   # mis. MinIO/Spaces; kosong bila AWS asli
  AWS_USE_PATH_STYLE_ENDPOINT=true                # umumnya true utk S3-compatible non-AWS [perlu verifikasi]
  # AWS_URL=<opsional, base URL publik — TIDAK dipakai untuk media privat>
  ```
- [ ] **Bucket harus privat** (bukan public-read). Media hanya boleh diakses
      lewat route bertanda-tangan `media.show`.
- [ ] **Verifikasi dua lapis tetap jalan di S3:** signed/temporary URL
      (kesegaran) **dan** policy-on-serve (otorisasi). Uji:
  - konsumen pemilik dapat membuka dokumennya (200);
  - pihak tak berhak dengan URL valid tetap **403**;
  - URL kedaluwarsa (TTL `MEDIA_URL_TTL`, default 300 dtk) **403**;
  - URL tanpa tanda tangan **403**.
- [ ] Ukuran batas sesuai lapangan: `MEDIA_MAX_IMAGE_KB`, `MEDIA_MAX_DOCUMENT_KB`,
      `MEDIA_MAX_VIDEO_KB` (naikkan video bila perlu). Selaraskan
      `client_max_body_size` (Nginx) & `upload_max_filesize`/`post_max_size`.
- [ ] Migrasi berkas lama **[perlu verifikasi]** — bila sudah ada data media di
      disk lokal, pindahkan ke bucket sebelum menukar `MEDIA_DISK`.

---

## 2. Payment gateway nyata (ADR-0012 / ADR-0013) **[WAJIB sebelum terima uang]**

**SUDAH DIBANGUN (2026-08).** Gateway **Midtrans nyata** (Core API VA), webhook
hardening, dan audit log semuanya sudah ada dan diuji. Yang tersisa untuk go-live
hanyalah **mengisi kredensial + menukar mode via config** (pola A3) — **tanpa
perubahan kode**.

Sudah dibangun & digerbangkan test:

- [x] **Implementasi gateway nyata** `App\Services\Payment\MidtransGateway`
      (`createCharge()` + `verifyCallback()`) dari kontrak `PaymentGateway`.
      Binding dipilih **via config** `config/payment.php` (`gateway` = select
      `simulated`|`midtrans`), di-*resolve* di `AppServiceProvider`. Default masih
      `simulated` (bebas kredensial).
- [x] **Verifikasi signature callback NYATA** (SHA512 `hash_equals`, kredensial
      dari config/env — **tanpa kunci hard-coded**), plus re-konfirmasi Status API
      opsional (`MIDTRANS_VERIFY_STATUS`).
- [x] **Throttle + allowlist IP** pada webhook publik — middleware
      `AllowlistPaymentWebhookIp` + limiter `throttle:payment-webhook`.
- [x] **`payment_webhook_logs`** (ADR-0013 #3) — tabel audit tiap callback
      (payload ter-redaksi via `PaymentWebhookLogger`, hasil verifikasi, keputusan
      settle/no-op).
- [x] `PaymentGatewayMidtransDodTest` menggerbangkan klausa integrasi.

Aksi saat deploy (isi di `.env`):

- [ ] **Aktifkan gateway nyata:** `PAYMENT_GATEWAY=midtrans`.
- [ ] **Kredensial Midtrans produksi:** `MIDTRANS_SERVER_KEY=<...>`,
      `MIDTRANS_CLIENT_KEY=<...>`, `MIDTRANS_IS_PRODUCTION=true`. (Opsional:
      `MIDTRANS_BANK` VA, `MIDTRANS_ORDER_PREFIX`, `MIDTRANS_VERIFY_STATUS`.)
- [ ] **Allowlist IP webhook Midtrans:** `PAYMENT_WEBHOOK_IPS=<ip1,ip2,...>`
      (kosong = allowlist non-aktif; **isi di produksi**). Sesuaikan
      `PAYMENT_WEBHOOK_MAX_ATTEMPTS` / `PAYMENT_WEBHOOK_DECAY_SECONDS` bila perlu.
- [ ] **Queue worker hidup** (lihat deploy.md §2.5) — callback terverifikasi
      diselesaikan lewat job `ProcessPaymentCallback` di queue.
- [ ] Idempotensi/anti-replay berbasis **state termin** (Fase 3-6) sudah ada dan
      **tak perlu diubah** — pelunasan tetap tak bisa dibayar sebelum BAST signed
      (§7).

---

## 3. Notifikasi multi-channel (Fase 7) **[OPSIONAL]**

In-app (database channel) **sudah jalan tanpa konfigurasi tambahan**. Arsitektur
sudah siap menambah email/WhatsApp **tanpa menyentuh pemicu di service** (pola
A3) — cukup ubah config + kredensial.

- [ ] Tambah channel di `config/notifications.php` (via env
      `NOTIFICATION_CHANNELS`, mis. `database,mail`). `via()` di
      `BaseNotification` membaca daftar ini.
- [ ] **Email:** isi kredensial SMTP di `.env` (`MAIL_MAILER`, `MAIL_HOST`,
      `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`).
      **[perlu verifikasi]** — perlu menambah method `toMail()` pada notifikasi
      saat channel `mail` diaktifkan.
- [ ] **WhatsApp:** channel + kredensial provider **[perlu verifikasi]** (belum
      ada provider yang dipilih; butuh implementasi channel kustom).
- [ ] **Pagar privasi tetap berlaku:** body notifikasi **tak pernah** memuat
      nominal uang / isi dokumen sensitif — ini penting justru saat body keluar
      lewat pihak ketiga (email/WA). Detail selalu di balik `action_url`
      ber-policy.

---

## 4. Keamanan produksi & sesi **[WAJIB]**

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`.
- [ ] **HTTPS** aktif (sertifikat valid). Cookie/session mengikuti domain HTTPS.
- [ ] **Sanctum** — set `SANCTUM_STATEFUL_DOMAINS` ke domain frontend/PWA yang
      sah (default mengikuti `APP_URL`). Token API (konsumen & Mandor PWA) tidak
      butuh domain stateful, tetapi SPA berbasis cookie butuh.
- [ ] **CORS** — sesuaikan origin yang diizinkan untuk frontend konsumen/PWA
      Mandor **[perlu verifikasi]** (`config/cors.php` bila dipublikasikan;
      default Laravel mengizinkan `api/*`).
- [ ] **2FA (Fortify)** — wajib untuk level 1–3 (Owner/Direktur/Manager/Finance/
      HR) sesuai kebijakan; pastikan rahasia 2FA aman (terenkripsi via `APP_KEY`
      — lihat catatan rotasi `APP_KEY` di deploy.md §4).
- [ ] `SESSION_ENCRYPT` **[perlu verifikasi]** kebijakan; session di Redis.
- [ ] Panel internal Filament ada di `/sistem` — hanya level 1–5 boleh akses
      (Konsumen L6 ditolak, pakai app konsumen terpisah). Pastikan tak ada rute
      admin yang bocor tanpa auth.
- [ ] Backup DB + storage privat terjadwal (lihat deploy.md §4).

---

## 5. Hal yang ditunda (daftar terbuka dari ADR)

Bukan penghalang go-live fungsional, tetapi tercatat agar tidak terlupa. Kerjakan
sebagai task tersendiri.

| Item | Sumber | Catatan |
|---|---|---|
| ~~**Upgrade Laravel 12**~~ ✅ | ADR-0002 | **Selesai (2026-08).** Framework di `^12` (Laravel 12.65), suite hijau, Filament v3 & Fortify kompat. ADR-0002 → status *dieksekusi*. |
| ~~**Smoke E2E (Dusk)**~~ ✅ | ADR-0009 | **Selesai (2026-08).** Dusk terpasang non-blocking: D-1 (smoke panel) & D-2 (2FA TOTP nyata) hijau; D-3 (RAB builder Select-dalam-Repeater) *known-flaky* → skipped, kebenaran tetap digerbangkan service-level 2B-4. Lihat `D-3-Penutupan.md`. |
| ~~**`payment_webhook_logs`**~~ ✅ | ADR-0013 #3 | **Selesai (2026-08).** Tabel + `PaymentWebhookLogger` terpasang (lihat §2). |
| **Payroll bulanan** | ditunda dari Fase 6-1 | Saat ini hanya payroll **harian mingguan** (Sabtu = hari hadir × upah harian). Skema bulanan menyusul bila dibutuhkan. |
| **Tabel `bank_mitra` (multi-PIC)** | ADR-0014 | `bank_mitra_id` kini menunjuk **akun user L4** (satu akun = satu mitra). Bila satu bank butuh banyak PIC/akun, kenalkan tabel profil `bank_mitra` + migrasi FK di **satu titik** (`BankMitraScope::FOREIGN_KEY`). Tak ada migrasi data sekarang. |

---

## 6. Urutan kerja disarankan (ringkas)

1. Pasang server + deploy dasar ([deploy.md](./deploy.md)) → app jalan internal
   (in-app notif, media lokal, gateway simulasi).
2. Keamanan produksi (§4): HTTPS, `APP_DEBUG=false`, Sanctum/CORS, 2FA.
3. Media → S3 (§1) + verifikasi signed/policy.
4. Gateway pembayaran nyata (§2) — **sebelum** menerima uang: signature +
   throttle/allowlist + queue worker.
5. (Opsional) Notifikasi email/WA (§3).
6. Jadwalkan task ditunda (§5) sesuai prioritas.
