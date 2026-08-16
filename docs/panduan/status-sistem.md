# Status Sistem web-cm

> Potret satu-halaman status pembangunan **web-cm** (CV. Cimandiri). Diperbarui
> pada penutupan jalur go-live (2026-08). Untuk langkah operasional lihat
> [checklist-go-live.md](./checklist-go-live.md) & [deploy.md](./deploy.md);
> untuk keputusan arsitektur lihat [`../decisions.md`](../decisions.md).

## Ringkasan

**Pembangunan fitur inti + jalur go-live: SELESAI.** Yang tersisa untuk produksi
**bukan** pekerjaan kode — hanya **isi kredensial + tukar driver via `.env`**
(pola A3), sesuai checklist go-live.

- **Tes:** `php artisan test` → **635 lolos**. Gerbang wajib CI = **Pint + Pest**
  (`.github/workflows/ci.yml`).
- **E2E (Dusk):** workflow **terpisah & non-blocking** (`dusk.yml`) — **3 lolos +
  1 skipped** (D-3 known-flaky; jangan dipromosikan jadi required).

## Modul & fase (selesai)

| Area | Status | Catatan |
|---|---|---|
| **Fase 1** — Fondasi, RBAC 6-level, audit log | ✅ | Policy hierarki + `is_protected` Owner. |
| **Fase 2** — Konsultasi → deal → proyek; AHSAP → RAB (2B) | ✅ | RAB builder dari AHSAP; snapshot harga (ADR-0007). |
| **Fase 3** — Pembayaran/termin + webhook | ✅ | Skema 30/40/30 · 50:50 · lunas; pelunasan terkunci sampai BAST signed. |
| **Fase 4** — Pembiayaan (Mitra/Bank, read-only) | ✅ | Scope `bank_mitra_id` (ADR-0008/0014). |
| **Fase 5** — Lapangan: absensi, laporan harian, media | ✅ | Media privat dua-lapis (signed URL + policy). |
| **Fase 6** — HR & payroll harian mingguan | ✅ | Payroll bulanan ditunda (checklist §5). |
| **Fase 7** — Notifikasi in-app (database channel) | ✅ | Email/WA siap ditambah via config (pola A3). |
| **Media** — object storage abstraksi | ✅ | Lokal privat di dev; swap ke S3 saat go-live (checklist §1). |

## Jalur go-live (selesai)

| Item | ADR | Status |
|---|---|---|
| **Upgrade Laravel 12** (PHP 8.4) | ADR-0002 | ✅ dieksekusi — framework `^12`, suite hijau. |
| **Payment gateway nyata (Midtrans)** | ADR-0012 | ✅ dieksekusi — `MidtransGateway` (Core API VA); pilih via `PAYMENT_GATEWAY`. |
| **Webhook hardening + audit** | ADR-0013 | ✅ dieksekusi — signature SHA512, allowlist IP + throttle, `payment_webhook_logs`. |
| **Dusk E2E** | ADR-0009 | ✅ sebagian — D-1/D-2 hijau; D-3 known-flaky/skipped (service-level 2B-4 tetap menggerbang). |

## Yang tersisa untuk PRODUKSI (bukan kode)

Semua ini **hanya konfigurasi/kredensial** saat deploy — rinci di
[checklist-go-live.md](./checklist-go-live.md):

1. **Media → S3** — `MEDIA_DISK=s3` + kredensial S3 (bucket **privat**). *(§1, wajib)*
2. **Gateway aktif** — `PAYMENT_GATEWAY=midtrans` + `MIDTRANS_SERVER_KEY`/
   `MIDTRANS_CLIENT_KEY`/`MIDTRANS_IS_PRODUCTION=true` + `PAYMENT_WEBHOOK_IPS`. *(§2, wajib sebelum terima uang)*
3. **Keamanan produksi** — `APP_ENV=production`, `APP_DEBUG=false`, HTTPS,
   Sanctum/CORS, 2FA L1–3, backup terjadwal, queue worker hidup. *(§4, wajib)*
4. **Notifikasi email/WA** — isi kredensial + `NOTIFICATION_CHANNELS`. *(§3, opsional)*

## Ditunda (tercatat, bukan penghalang)

Payroll bulanan · tabel `bank_mitra` multi-PIC (ADR-0014) · pengaktifan kembali
D-3 bila otomasi Choices.js membaik. Lihat checklist §5 & `D-3-Penutupan.md`.
