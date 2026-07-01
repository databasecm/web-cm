# Architecture Decision Records (ADR)

Catatan keputusan arsitektur & aturan bisnis yang berdampak panjang. Setiap entri
singkat: konteks, keputusan, konsekuensi. Urut terbaru di atas.

---

## ADR-0012 — Abstraksi payment gateway (interface + simulasi default)

- **Tanggal:** 2026-07-01
- **Status:** Diterima
- **Konteks:** Pembayaran butuh gateway (VA/Snap) tetapi lingkungan dev/test
  dikendalikan dari HP tanpa kredensial sandbox andal, dan menautkan SDK gateway
  nyata sekarang menambah rahasia + kerapuhan jaringan pada test. Keputusan
  produk (A3): abstraksikan dulu, gateway nyata menyusul.

- **Keputusan:** Definisikan kontrak `App\Services\Payment\PaymentGateway`:
  `createCharge(Installment): PaymentInstruction` dan
  `verifyCallback(array): PaymentSettlement` (dipakai webhook Fase 3-6).
  Implementasi default **`SimulatedGateway`** menghasilkan VA/ref **deterministik**
  dari id termin, **tanpa panggilan jaringan**. `PaymentService` meminta interface
  via DI untuk membuat charge (guard §7: hanya termin `unlocked`); binding default
  di `AppServiceProvider` → `SimulatedGateway`.

- **Idempoten:** satu termin `unlocked` → satu charge aktif. `createCharge`
  mengembalikan instruksi yang sama bila `gateway_ref` sudah terisi (tak
  membuat charge dobel). Penyelesaian bayar tetap lewat jalur tunggal
  `PaymentService::pay()` (Fase 3-4) — `SimulatedGateway::simulatePaymentReceived()`
  memanggilnya (resolusi lazy untuk menghindari siklus DI).

- **Konsekuensi & arah:** Midtrans/Xendit nyata cukup mengimplementasikan
  interface yang sama + di-`bind` ulang, **tanpa mengubah alur**. Verifikasi
  signature callback tinggal di implementasi nyata (`verifyCallback`). Tidak ada
  SDK/kredensial gateway yang dipasang sampai fase integrasi nyata.

---

## ADR-0011 — CI (GitHub Actions) sebagai gerbang independen

- **Tanggal:** 2026-06-30
- **Status:** Diterima
- **Konteks:** Pengembangan inkremental dikendalikan dari HP; tiap task ber-PR.
  Sebelum masuk domain pembayaran (Fase 3) yang sensitif, perlu **jaring
  pengaman otomatis** yang tidak bergantung pada ingatan menjalankan test/Pint
  secara lokal.

- **Keputusan:** Tambah workflow `.github/workflows/ci.yml` yang jalan di tiap
  **pull_request** dan **push ke `main`**. Langkah: setup **PHP 8.4** → `composer
  install` → `vendor/bin/pint --test` (gaya kode) → `php artisan test` (suite
  penuh). CI adalah gerbang **independen** dari reviewer manusia; keputusan
  merge tetap di tangan pemilik proyek (bukan auto-merge).

- **DB & Redis = layanan nyata (paritas produksi):** test memakai **MySQL 8**
  (bukan sqlite) demi paritas `decimal`/FK dengan produksi, dan **Redis** +
  ekstensi **phpredis** karena guest-consultation store native Redis dan test
  mem-`flushdb` tiap kasus. sqlite in-memory ditolak: menyimpang dari MySQL 8 dan
  tak bisa menopang kode Redis-native.

- **Konsekuensi:** PR merah tidak boleh di-merge. Penambahan dependensi yang
  butuh ekstensi PHP baru harus diikuti update daftar `extensions` di workflow. Caching
  `vendor` mempercepat run. Bila kelak ada migrasi MySQL-spesifik, CI sudah
  menjamin paritas sejak awal.

---

## ADR-0010 — PDF penawaran via dompdf (pure-PHP), cermin RAB beku

- **Tanggal:** 2026-06-30
- **Status:** Diterima
- **Konteks:** Penawaran (Fase 2B-9) perlu di-render ke PDF ber-kop dari RAB.
  Lingkungan target (container 8.4 & Termux 8.5, dikendalikan dari HP) **tidak
  punya Chromium/Node yang andal**, sehingga renderer berbasis headless browser
  (mis. Browsershot/Puppeteer) tidak cocok.

- **Keputusan:** Pakai **`barryvdh/laravel-dompdf`** (pure-PHP, tanpa biner
  eksternal). PDF dirender dari Blade `resources/views/pdf/rab-penawaran.blade.php`
  oleh service `RabPenawaranPdf`. Identitas perusahaan (kop) disimpan di
  `config/company.php` (override via `.env`) agar jadi satu sumber kebenaran dan
  mudah dipindah ke tabel settings nanti.

- **Sumber angka = snapshot RAB, bukan hitung ulang:** semua nilai (total
  material/upah, overhead/margin/PPN beserta `*_percent` tersnapshot, grand_total)
  dan item dibaca langsung dari kolom RAB & `rab_items` yang sudah beku
  (ADR-0007). PDF adalah **cermin** RAB — tidak pernah menghitung ulang dari AHSAP
  live.

- **Akses:** `RabPolicy::downloadPdf` = boleh `view` RAB **dan** status
  `submitted`/`approved` (draft tak ditawarkan). Manager (bidangnya) lewat aksi
  Filament "Unduh Penawaran"; konsumen lewat `GET /api/v1/rabs/{id}/pdf`
  (kanal consumer, kepemilikan via policy).

- **Konsekuensi:** dompdf punya keterbatasan CSS (tanpa flexbox/grid) → template
  memakai tabel/float sederhana. Logo opsional (dirender hanya bila file ada).
  Test menggerbangkan **isi** lewat render Blade (deterministik) + memastikan
  byte PDF benar (`%PDF`), bukan mem-parse biner PDF.

---

## ADR-0009 — Test E2E UI (Dusk) ditunda ke fase QA pra-produksi

- **Tanggal:** 2026-06-29
- **Status:** Diterima (item pra-produksi, sejajar ADR-0002)
- **Konteks:** Beberapa permukaan Filament sulit diuji penuh lewat test harness
  Livewire — khususnya **injeksi nilai `Select` di dalam `Repeater`** (nilai
  ter-strip; keterbatasan dikenal Filament/Livewire). Akibatnya, kebenaran
  bisnis (mis. RAB builder 2B-4) **digerbangkan di service-level** yang lebih
  kuat & deterministik, sementara test relation-manager hanya menutup
  visibilitas aksi & scope.

- **Keputusan:** Kebenaran logika moneter/bisnis tetap digerbangkan di
  **feature/service test** (Pest). **Cakupan E2E UI sebenarnya** (mengisi form
  repeater kompleks, alur klik penuh) dialihkan ke **Laravel Dusk** dan
  dijadwalkan sebagai **task QA pra-produksi tersendiri** — **bukan sekarang**
  (Dusk butuh browser/driver, di luar lingkup pembangunan fitur inkremental saat
  ini).

- **Konsekuensi & arah ke depan:** Sebelum go-production, kerjakan task "Smoke
  E2E (Dusk)" untuk alur kritis end-to-end (konsultasi→deal→RAB→checkout). Sampai
  itu, risiko diterima karena tiap unit logika sudah tergerbang test dan app
  belum publik. Pola "gerbang math di service, UI test untuk
  visibilitas/scope" adalah keputusan sadar, bukan kompromi.

---

## ADR-0008 — Semantik `projects.bank_mitra_id` + rekonsiliasi Fase 4

- **Tanggal:** 2026-06-29
- **Status:** Diterima (sementara untuk Fase 2B; ditinjau ulang Fase 4)
- **Konteks:** §6.5 menuntut Mitra Pembiayaan (L4) hanya melihat proyek yang
  dibiayainya (`bank_mitra_id` miliknya). `App\Models\Scopes\BankMitraScope`
  (skeleton sejak Fase 1, sudah unit-tested) membandingkan FK ini ke
  `Auth::id()`. Namun ERD §A.4 juga memuat tabel `bank_mitra` + `financings`
  (alur pembiayaan penuh) yang baru lahir di **Fase 3/4**.

- **Keputusan:** Untuk **Fase 2B**, `projects.bank_mitra_id` menunjuk ke **akun
  user Mitra (L4)** secara langsung, sehingga `BankMitraScope` bekerja apa
  adanya. Kolom bersifat **dorman** di 2B (diisi nyata saat pembiayaan dibangun).
  Penegakan §6.5 = `#[ScopedBy(BankMitraScope::class)]` pada `Project` + policy
  read-only untuk L4.

- **Konsekuensi & arah ke depan:** Saat tabel `bank_mitra` + `financings` lahir
  (**Fase 3/4**), **tinjau ulang**: apakah `projects.bank_mitra_id` di-*repoint*
  ke `bank_mitra.id`, atau dipertahankan sebagai FK ke akun user dengan
  `bank_mitra` sebagai profil tertaut `user_id` (jembatan). ADR rekonsiliasi akan
  dicatat pada fase itu; `BankMitraScope::FOREIGN_KEY` adalah satu titik ubah.

---

## ADR-0007 — RAB = snapshot beku dari AHSAP; revisi via versi baru

- **Tanggal:** 2026-06-29
- **Status:** Diterima (lapis-2 dari ADR-0004)
- **Konteks:** `rab_items.ahsap_id` menautkan item RAB ke AHSAP, tetapi penawaran
  yang sudah diterbitkan ke konsumen harus **stabil** meski `AHSAP.base_price`
  berubah kemudian (resync 2A-3).

- **Keputusan:**
  1. Saat RAB dibangun (`RabBuilder`), tiap `rab_item` dari AHSAP **menyalin**
     `description`/`unit`/`unit_price = AHSAP.base_price` **saat itu** (snapshot,
     bukan join live). `volume` diisi Manager; `subtotal = volume × unit_price`
     (BigDecimal, ADR-0005). `ahsap_id` disimpan hanya untuk jejak asal.
  2. Total RAB (`total_material`, `total_upah`, `overhead`, `margin`, `ppn`,
     `grand_total`) **dihitung & disimpan** saat build → reproducible & beku.
  3. **Resync AHSAP TIDAK mengubah RAB yang sudah ada.** RAB baru (versi baru)
     barulah memakai harga terbaru.
  4. **Imutabilitas:** RAB `approved` dibekukan; perubahan → **versi baru**
     (`version`+1, `status=draft`), bukan mutasi RAB lama.

- **Konsekuensi & arah ke depan:** Penawaran historis tak pernah berubah
  diam-diam. `contract_value` proyek (ADR finalisasi) di-set dari `grand_total`
  RAB yang disetujui → lapis-3 snapshot (master AHSAP → RAB beku → kontrak beku).

---

## ADR-0006 — Setting margin/PPN/overhead: tabel sendiri + SettingService

- **Tanggal:** 2026-06-29
- **Status:** Diterima
- **Konteks:** Margin, PPN, dan overhead RAB butuh **default global** (dikelola
  Owner/Direktur) dengan **override per-RAB**. Butuh mekanisme setting yang ringan
  (Termux) dan tanpa ketergantungan ekstra bila bisa.

- **Keputusan:**
  1. **Tabel `settings` key-value sederhana** (hand-rolled, tanpa paket) + model
     `Setting` + **`SettingService` ber-cache** yang mengekspos accessor bertipe
     (`marginPercentDefault()`, `ppnPercentDefault()`, `overheadPercentDefault()`).
  2. Dikelola **Owner/Direktur** via halaman Filament **Pengaturan**.
  3. **Override + snapshot rate ke RAB:** `rabs` menyimpan **rate beku**
     (`margin_percent`, `ppn_percent`, `overhead_percent`) **dan** nominal hasil
     (`margin`, `ppn`, `overhead`). RAB form pre-fill rate dari setting; Manager
     boleh override; rate yang dipakai di-snapshot → `grand_total` reproducible.

- **Konsekuensi & arah ke depan:** Mengubah default global **tidak** mengubah RAB
  lama (rate sudah ter-snapshot). Pola setting yang sama dapat dipakai modul lain
  (mis. upah harian payroll) di fase berikutnya.

---

## ADR-0005 — Semua kalkulasi moneter memakai BigDecimal (brick/math), bukan float

- **Tanggal:** 2026-06-27
- **Status:** Diterima
- **Konteks:** `ext-bcmath` **tidak tersedia** di runtime Termux, sehingga 2A-2
  sempat memakai `float + round` untuk `base_price`. Float berisiko galat
  pembulatan pada uang; sebelum RAB, pembayaran, payroll & pajak dibangun (banyak
  penjumlahan/perkalian rupiah berlapis), risiko ini harus dihapus.

- **Keputusan:** Mengadopsi **`brick/math` (`BigDecimal`)** sebagai **standar
  matematika-uang** untuk seluruh sistem — pure-PHP, tanpa ekstensi (lolos di
  Termux & container). Aturan:
  1. Setiap kalkulasi nilai uang (AHSAP, RAB, termin/cicilan, payroll, pajak,
     buku kas) memakai `BigDecimal`; **dilarang** memakai `float`/`round()` untuk
     uang.
  2. Sumasi/perkalian dilakukan eksak; **pembulatan hanya di hasil akhir** ke
     **2 desimal**, mode **`HALF_UP`**.
  3. Kolom DB tetap `decimal(15,2)` (atau presisi lain sesuai konteks); nilai
     disimpan sebagai string hasil `->toScale(2, HALF_UP)`.

- **Konsekuensi & arah ke depan:** `AhsapCalculator` & `AhsapComponent::lineTotal`
  direfaktor ke `BigDecimal` (nilai hasil identik dengan 2A-2, test tetap hijau).
  Modul moneter berikutnya mengikuti pola ini. Konvensi dicatat di CLAUDE.md §5.

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
