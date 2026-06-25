# Architecture Decision Records (ADR)

Catatan keputusan arsitektur & aturan bisnis yang berdampak panjang. Setiap entri
singkat: konteks, keputusan, konsekuensi. Urut terbaru di atas.

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
