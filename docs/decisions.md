# Architecture Decision Records (ADR)

Catatan keputusan arsitektur & aturan bisnis yang berdampak panjang. Setiap entri
singkat: konteks, keputusan, konsekuensi. Urut terbaru di atas.

---

## ADR-0002 — Tetap di Laravel 11.x; upgrade ke 12.x ditunda (pre-produksi)

- **Tanggal:** 2026-06-25
- **Status:** Diterima
- **Konteks:** `composer audit` melaporkan 3 advisory pada `laravel/framework`
  (Temporary Signed URL Path Confusion; CRLF injection in default email rule —
  dua advisory). Versi perbaikan **hanya ada di Laravel 12** (`12.60.0` dan
  `12.61.1`+); **seluruh branch 11.x — termasuk 11.54.0 (terbaru) — tetap
  terdampak**. Tidak ada rilis 11.x yang memuat fix, sehingga "update dalam 11.x"
  tidak dapat menutup ketiganya.

- **Keputusan:** Untuk sekarang **tetap di Laravel 11.x**. Upgrade ke Laravel 12
  adalah **major bump** (perlu verifikasi kompatibilitas Filament v3 & Fortify),
  jadi **tidak** dilakukan di tengah Fase 1. Advisory dicatat di sini dan upgrade
  dijadwalkan sebagai **task maintenance tersendiri**.

- **Konsekuensi & arah ke depan:** Sebelum aplikasi **go-production**, kerjakan
  task khusus "Upgrade Laravel 12": naikkan constraint composer, jalankan uji
  kompatibilitas penuh (Filament/Fortify/Pest), pastikan suite hijau, dalam PR
  terpisah — menutup ketiga advisory. Sampai saat itu, risiko diterima karena app
  belum terekspos publik. Constraint PHP sudah `^8.4` (lihat PR #14), kompatibel
  dengan Laravel 12 (butuh PHP ≥ 8.2) sehingga tidak menghalangi upgrade.

---

## ADR-0001 — Manager tidak mengelola akun tanpa bidang (Konsumen/Mitra/Supplier)

- **Tanggal:** 2026-06-25
- **Status:** Diterima
- **Konteks:** RBAC §6.4 membatasi Manager (L3) ke `bidang` miliknya. Akun
  Konsumen (L6), Mitra Pembiayaan & Supplier (L4) tidak memiliki `bidang`
  (berada di luar unit usaha mana pun). Akibatnya, gerbang hierarki
  (`UserPolicy::canManage`) menolak Manager mengelola akun-akun tanpa bidang,
  meski levelnya di bawah Manager.

- **Keputusan:** Perilaku ini **disengaja, bukan bug**. Hierarki umum **tidak**
  dilonggarkan untuk memberi Manager akses ke akun tanpa bidang. Spesifikasi
  RBAC didokumentasikan eksplisit di `tests/Feature/Rbac/RbacSpecificationTest.php`
  (sel "Manager → Konsumen/Supplier (no bidang) — blocked").

- **Konsekuensi & arah ke depan:** Kebutuhan Sales "Manager membuat akun Konsumen
  saat deal" akan ditangani di **Fase 1B/2** sebagai **aksi spesifik yang terikat
  konteks deal** (mis. kebijakan/aksi `createCustomerForDeal`), **bukan** dengan
  memperluas `UserPolicy::canManage` atau mengubah aturan bidang umum. Ini menjaga
  permukaan otorisasi tetap sempit dan auditable.
