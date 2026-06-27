# Architecture Decision Records (ADR)

Catatan keputusan arsitektur & aturan bisnis yang berdampak panjang. Setiap entri
singkat: konteks, keputusan, konsekuensi. Urut terbaru di atas.

---

## ADR-0004 — AHSAP = master hidup, RAB = penawaran beku (dua lapis snapshot)

- **Tanggal:** 2026-06-27
- **Status:** Diterima (kontrak desain untuk Fase 2A/2B)
- **Konteks:** AHSAP (Analisa Harga Satuan Pekerjaan) menjadi dasar RAB. Harga
  material berubah dari waktu ke waktu (Material DB = sumber kebenaran), sedangkan
  RAB yang sudah diterbitkan ke konsumen adalah penawaran harga yang harus
  **stabil**. CLAUDE.md §7: "perubahan harga material harus bisa menandai RAB
  terdampak" — menandai, bukan menulis ulang diam-diam.

- **Keputusan — dua lapis snapshot:**
  1. **Komponen AHSAP menyimpan `unit_price` sebagai snapshot** dari
     `Material.price` saat komponen ditambah/di-sync (bukan join live).
     `base_price = Σ(coefficient × unit_price)` dihitung dari snapshot → AHSAP
     self-contained & stabil.
  2. **Perubahan harga material TIDAK mengubah AHSAP diam-diam.** Ia hanya
     menandai AHSAP terdampak `needs_review` + mencatat `material_price_history`.
     Sinkronisasi adalah **aksi Manager eksplisit** ("Sinkronkan & Hitung Ulang")
     yang menarik harga terkini ke komponen, menghitung ulang `base_price`, dan
     membersihkan flag.
  3. **RAB men-snapshot `base_price` AHSAP saat RAB dibuat** (ke `rab_items`).
     Karena itu resync AHSAP **tidak pernah** mengubah RAB yang sudah ada — AHSAP
     = master hidup; RAB = penawaran beku.

- **Konsekuensi & arah ke depan:** Tidak ada perubahan harga yang merembet
  diam-diam ke dokumen historis. Drift harga selalu eksplisit & terlacak
  (history + flag + resync). Bila kelak butuh imutabilitas penuh pada AHSAP itu
  sendiri, dapat ditambah **versioning AHSAP**, tetapi snapshot-saat-RAB sudah
  cukup untuk melindungi RAB. Penandaan AHSAP saat harga berubah dibuat
  **idempoten** (satu perubahan harga = satu penandaan) meski jalur service &
  observer berjalan bersamaan.

---

## ADR-0003 — Konsultasi tamu ephemeral & jembatan deal→akun (Fase 1B)

- **Tanggal:** 2026-06-25
- **Status:** Diterima
- **Konteks:** Fase 1B membangun konsultasi login & tanpa-login. Runtime kita
  Termux single-instance, Redis tersedia, **belum** pakai websockets. Konsep §3
  & §10 menuntut chat tamu **benar-benar ephemeral** (tidak pernah ke DB, hilang
  saat sesi berakhir), sedangkan konsep §4 menuntut alur "deal tamu → Manager buat
  akun konsumen". ADR-0001 melarang pelebaran hierarki kelola-akun Manager.

- **Keputusan:**
  1. **Chat tamu hidup hanya di Redis ber-TTL**, tidak pernah ditulis ke
     `consultations`/`consultation_messages`. Token sesi opaque (UUID) dipegang
     klien di `sessionStorage`; TTL Redis sliding sebagai backstop. Tutup halaman
     → token hilang → key kedaluwarsa sendiri. Tanpa Eloquent, tanpa DB write.
  2. **Routing tamu di level bidang**, bukan ke Manager tertentu. Index aktif
     `guest:active:{bidang}` (sorted set). **`manager_id` TIDAK di-pin saat sesi
     dibuat** — diisi hanya saat Manager pertama merespons (**claim**). Ini
     menghindari sesi yatim yang tertambat ke Manager yang sedang offline.
  3. **Tanpa websockets → polling sederhana** dua arah (Manager `poll()` di
     Filament; tamu AJAX ber-interval). "Online" = heuristik `last_seen`. Balasan
     setelah tamu pergi ditulis ke Redis tapi tak terlihat & ikut kedaluwarsa —
     diterima (ephemeral, tanpa jaminan kirim/read-receipt).
  4. **Transport tamu = stateless API `/api/v1` + token opaque + throttle**,
     bukan web-route ber-session.
  5. **Jembatan deal→akun = aksi sempit terikat-konteks** (mis.
     `createCustomerForDeal`), **bukan** pelebaran `UserPolicy::canManage`. Aksi
     ini membuat akun Konsumen (L6, tanpa bidang), mempersist `consultations` +
     menyalin transkrip Redis **sekali atas izin**, menulis `audit_logs` (§6.6),
     lalu meng-expire key Redis.
  6. **Hak lanjutan Manager atas akun yang ia buat = NOL.** `canManageAccounts()`
     tetap seragam `false`; **tidak** ada hak scoped-by-relationship. Reset
     password = self-service konsumen; intervensi admin via L1/L2/L3-in-domain.
  7. **Widget chat publik (B6) DITUNDA** ke fase consumer-web. B3 (API tamu
     stateless) & B4 (sisi Manager) tetap dibangun & diuji penuh via feature test
     tanpa UI publik.

- **Konsekuensi & arah ke depan:** Permukaan otorisasi tetap sempit & auditable.
  Tidak ada persistensi data tamu sampai titik deal eksplisit. Sesi tamu bersifat
  best-effort tanpa jaminan pengiriman — dapat ditingkatkan di fase consumer-web
  (mis. websockets/Reverb) bila diperlukan, tanpa mengubah keputusan ephemeral.

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
