# Konsep Detail Website CV. Cimandiri (web-cm) — v2

**Domain:** cvcimandiri.co.id (Web Profil) · cvcimandiri.co.id/sistem (Web Sistem)
**Tagline:** Integrated Build-Tech Service — *Mitra Profesional untuk Proyek Terintegrasi*

> **Apa yang baru di v2:** v1 fokus pada web profil & gambaran umum sistem. v2 melengkapi **seluruh kebutuhan operasional**: hierarki 6 level akun (RBAC), alur konsultasi–pemesanan–checkout–pembayaran–pembiayaan, serta modul Manager, Mandor, HR, Finance, Mitra, AHSAP, dan Database Material. Bagian bertanda **💡 Saran** adalah usulan tambahan dari saya untuk menyempurnakan sistem.

---

## 1. Gambaran Sistem

Dua aplikasi yang saling terhubung:

```
WEB PROFIL  (cvcimandiri.co.id)      → publik, marketing, kepercayaan, pintu konsultasi
WEB SISTEM  (cvcimandiri.co.id/sistem) → operasional & transaksi, berbasis login & hak akses
```

Web Sistem adalah pusat operasional: dari satu konsultasi konsumen, mengalir ke desain → RAB → proyek → pembayaran/pembiayaan → eksekusi lapangan → serah terima → keuangan & penggajian. Semua aktor (internal, mitra, konsumen) bekerja di sistem yang sama dengan hak akses berbeda.

---

## 2. Hierarki 6 Level Akun (RBAC)

| Lvl | Peran | Cakupan Akses | Kelola Akun |
|----|-------|---------------|-------------|
| 1 | **Owner / Super-Admin** | Penuh ke semua modul | Kelola semua akun. **Permanen, tidak bisa dihapus** |
| 2 | **Direktur** | Penuh ke semua modul | Buat/edit/hapus akun di bawahnya. **Tidak bisa hapus diri sendiri** |
| 3 | **Manager / Finance / HR** | Sesuai bidang masing-masing | Buat/edit/hapus akun di bawahnya. Tidak bisa hapus diri sendiri |
| 4 | **Mitra** (pembiayaan, supplier) | Sesuai bidang (modul mitra saja) | **Tidak bisa kelola akun apa pun.** Tidak bisa hapus diri sendiri |
| 5 | **Mandor / Pengawas** | Bidang masing-masing (CuFID, CC, SolIT, BIRU GIS) | **Tidak ada akun di bawahnya.** Mengelola data **karyawan/tukang** sebagai entitas (bukan akun login) untuk absensi. Tidak bisa hapus diri sendiri |
| 6 | **Konsumen** | Lihat/monitoring proyek & terima laporan | Tidak bisa hapus diri sendiri |

**Catatan Level 3:** Manager, Finance, dan HR sederajat tapi beda domain — Manager (proyek/desain/RAB), Finance (keuangan), HR (kepegawaian). Masing-masing hanya melihat modul domainnya.

**Catatan Level 5 (dikonfirmasi):** Mandor **tidak memiliki akun di bawahnya**. Seluruh **tukang/karyawan adalah entitas terkelola** (data kepegawaian, bukan akun login) yang dipakai mandor untuk absensi dan HR untuk penggajian. Dengan begitu mandor tidak pernah menyentuh akun konsumen maupun akun login lain.

> **💡 Saran keamanan:** Aktifkan **2FA wajib** untuk Level 1–3, **audit log** (siapa mengubah apa & kapan) di semua modul keuangan/akun, dan **enkripsi berkas sensitif** (KTP, slip gaji, dokumen pembiayaan).

---

## 3. Alur Konsultasi Konsumen

```
Konsumen kunjungi cvcimandiri.co.id
        │
        ▼
   Klik "Chat / Konsultasi"
        │
   ┌────┴─────────────┐
   ▼                  ▼
DAFTAR + LOGIN     LEWATI (tanpa login)
   │                  │
Chat tersimpan     Chat hanya selama sesi —
permanen di akun   terhapus saat halaman ditutup
   │                  │
   └────────┬─────────┘
            ▼
   Manager bidang terkait merespons
   (CuFID / CC / SolIT / BIRU GIS)
```

- **Login:** seluruh riwayat konsultasi tersimpan di akun konsumen.
- **Tanpa login:** chat bersifat sementara (session-only) dan hilang setelah halaman ditutup.
- Konsultasi dirutekan otomatis ke **Manager sesuai bidang** yang dipilih konsumen.

> **💡 Saran:** Saat konsultasi tanpa login berakhir "deal", tawarkan tombol cepat "Simpan percakapan ini → buat akun" agar riwayat tidak hilang. Selain itu, integrasikan **WhatsApp Business API** sebagai kanal alternatif yang tetap masuk ke inbox Manager di sistem.

---

## 4. Alur Pemesanan (End-to-End)

```
Konsultasi ──► DEAL
   │
   ├─ Konsumen sudah punya akun ──────────────┐
   │                                          ▼
   └─ Konsumen tanpa akun ──► Manager buat ──► Manager susun
                              akun konsumen     DESAIN + RAB/PENAWARAN
                                                     │
                          (konsumen pantau via akun) │
                                                     ▼
                                          Konsumen SETUJUI desain & RAB
                                                     │
                                                     ▼
                          Manager buat PROYEK/PRODUK baru
                          (harga = RAB, metode bayar disepakati)
                                                     │
                                                     ▼
                          Produk/Proyek muncul di halaman konsumen
                                                     │
                                                     ▼
                                   Konsumen CHECKOUT + pilih metode bayar
                                          │
                              ┌───────────┴───────────┐
                              ▼                       ▼
                     TANPA PEMBIAYAAN          DENGAN PEMBIAYAAN
                     (3 skema termin)          (via bank mitra)
```

---

## 5. Skema Pembayaran

### a. Tanpa Pembiayaan

| Skema | Termin |
|-------|--------|
| **Termin 3x** | DP 30% setelah checkout · 40% saat progres 50% · 30% pelunasan setelah **BAST** |
| **50 : 50** | DP 50% setelah checkout · 50% pelunasan setelah **BAST** |
| **Lunas** | 100% dibayar di awal setelah checkout |

- Termin 40% otomatis ter-*unlock* untuk ditagih ketika progres pekerjaan mencapai **50%** (diisi Mandor/Manager).
- Pelunasan ter-*unlock* ketika **Berita Acara Serah Terima (BAST)** diterbitkan.

### b. Dengan Pembiayaan (Bank Mitra)

```
Pilih bank mitra ──► Upload berkas persyaratan ──► Menunggu persetujuan
                                                          │
       Konsumen memantau & merespons setiap update status:
       • bank minta berkas tambahan
       • bank menjadwalkan wawancara
       • disetujui / ditolak
```

**Target mitra pembiayaan:** Nasional — **BSI, Bank Muamalat**. Lokal/daerah — **BMT, BPRS, KSPPS**.

> **💡 Saran:** Untuk termin tanpa pembiayaan, integrasikan **payment gateway** (Midtrans/Xendit/Doku) dengan **Virtual Account per termin** + opsi konfirmasi transfer manual (diverifikasi Finance). Terbitkan **BAST digital** lengkap tanda tangan elektronik & foto bukti — inilah pemicu resmi tagihan pelunasan, sehingga rantai progres → BAST → pelunasan terotomasi.

---

## 6. Modul Sistem per Peran

### Manager (Level 3)
- Kelola semua proyek di bidangnya.
- Merespons konsultasi & menanggapi keluhan konsumen terkait proyek.
- Menyusun **AHSAP** (Analisa Harga Satuan Pekerjaan) sebagai dasar RAB.
- Membuat **desain, RAB, dan rencana kerja**.
- Membuat akun untuk konsumen offline; membuat proyek/produk setelah RAB disetujui.

> **💡 Saran:** Buat generator **RAB otomatis dari AHSAP** — Manager pilih item pekerjaan dari master AHSAP, sistem hitung total + overhead + margin + PPN, lalu hasilkan **PDF penawaran** ber-kop Cimandiri. Tambahkan **versioning** desain/RAB agar revisi terlacak.

### Mandor / Pengawas (Level 5)
- **Absensi karyawan** harian.
- **Dokumentasi progres** proyek setiap hari (foto/video).
- Menulis **laporan harian**.
- Foto/video & laporan harian **otomatis terhubung ke halaman konsumen**.
- Menginput progres % (memicu termin pembayaran).

> **💡 Saran:** Sediakan **aplikasi mobile/PWA khusus mandor** yang bisa bekerja **offline** (sinyal lokasi proyek sering lemah) — foto mengantre dan ter-upload otomatis saat ada koneksi. Setiap upload tampil sebagai *timeline harian* di akun konsumen.

### HR (Level 3)
- Tambah/edit/hapus karyawan, mandor, dll.
- Update status: kenaikan jabatan, kenaikan gaji, dll.
- Rekap **semua absensi** karyawan.
- **Slip gaji** semua karyawan.
- Penggajian: karyawan harian (diabsen mandor) dibayar **mingguan setiap Sabtu**.

> **💡 Saran:** Otomatiskan **payroll** — sistem hitung gaji mingguan dari rekap absensi mandor (hari hadir × upah harian), generate slip gaji, dan kirim daftar bayar ke Finance setiap Jumat agar siap cair Sabtu.

### Finance (Level 3)
- Catat semua transaksi:
  - **Pemasukan:** pembayaran konsumen, investor, dll.
  - **Pengeluaran:** pembelian material, operasional, gaji, dll.

> **💡 Saran:** Bangun sebagai **buku kas berbasis kategori** dengan saldo berjalan, **laporan laba-rugi per proyek** (pendapatan vs biaya material+upah), dan dashboard arus kas. Tagihan termin & cicilan otomatis mengalir ke pemasukan; payroll & PO material otomatis ke pengeluaran.

### Mitra Pembiayaan (Level 4)
- Mengelola, memverifikasi, menyetujui/menolak pengajuan pembiayaan konsumen.
- Mengirim **pengingat tagihan/cicilan** kepada konsumen.
- **Memantau progres perkembangan proyek yang dibiayainya** — bank dapat melihat progres %, laporan harian, foto/video lapangan, dan status BAST khusus untuk proyek-proyek yang ia danai (read-only, terbatas pada proyek terkait).

> **💡 Saran:** Akses pemantauan bank dibatasi **hanya pada proyek yang dibiayainya** (data proyek lain tetap tertutup). Tampilkan dalam **dashboard mitra** berisi: daftar proyek yang didanai, progres terkini, dan jadwal/penyerapan dana per termin — sehingga bank punya transparansi penuh atas penggunaan pembiayaannya.

### Mitra Supplier (Level 4)
- Membuat **daftar harga material** lengkap spesifikasi (buat/edit/update/hapus).

### Konsumen (Level 6)
- Memantau seluruh progres proyek miliknya.
- Menerima laporan harian (foto/video) otomatis dari mandor.
- Checkout, memilih & memantau pembayaran/pembiayaan.

> **💡 Saran — Timeline Proyek Terpadu:** Satu feed di akun konsumen yang menggabungkan **progres %**, **laporan harian mandor**, **status termin/cicilan**, **status pembiayaan**, dan **BAST** — agar konsumen melihat semuanya di satu tempat. Tambahkan **rating & ulasan** setelah BAST yang (atas izin) tampil di web profil sebagai testimoni.

---

## 7. Modul Bersama (akses semua akun internal, KECUALI Mitra & Konsumen)

### Master Data AHSAP
Database harga satuan pekerjaan per bidang — dasar pembuatan RAB. Bisa diperbarui mengikuti harga material terkini.

### Database Material
Menggabungkan daftar material dari **mitra supplier** + **input internal**. Mandor dapat menginput pembelian material mendadak di lokasi.

Contoh field input:
| Field | Contoh |
|-------|--------|
| Tanggal | otomatis saat input |
| Nama Material | Semen |
| Merk | Tiga Roda |
| Harga | 70.000 |
| Spesifikasi | — |
| SNI | Ya (Ya/Tidak) |
| Toko/Supplier | TB. Baru Jadi |
| Alamat | Jl. Raya Nasional No. 71 |

> **💡 Saran:** Tautkan Database Material ↔ AHSAP ↔ RAB. Saat harga material berubah, sistem bisa menandai AHSAP/RAB yang terdampak. Tambahkan **riwayat harga** per material agar perubahan harga lapangan terlacak untuk evaluasi.

---

## 8. Saran Penyempurnaan Tambahan (Ringkasan)

1. **Notifikasi multi-kanal** (in-app + email + WhatsApp) untuk setiap perubahan status: konsultasi dibalas, RAB siap, termin jatuh tempo, laporan harian baru, status pembiayaan.
2. **Dashboard per peran** dengan ringkasan & KPI (proyek aktif, tagihan tertunda, absensi hari ini, arus kas).
3. **After-sales / garansi** — pencatatan masa garansi & kanal komplain pasca-serah-terima.
4. **Manajemen dokumen terpusat** per proyek (kontrak, RAB, BAST, berkas pembiayaan).
5. **Reminder otomatis** (cron) untuk termin jatuh tempo & cicilan.
6. **Multi-proyek & multi-bidang** dalam satu akun konsumen (mis. konsumen pesan konstruksi + furniture sekaligus).
7. **Laporan ringkas untuk investor** (akses terbatas) sebagai bukti kredibilitas & transparansi.
8. **Kebijakan retensi data**: chat tanpa login disimpan di session storage agar benar-benar terhapus saat tutup halaman (sesuai permintaan).

---

## 9. Rekomendasi Teknologi (diperbarui)

| Lapisan | Rekomendasi |
|---------|-------------|
| Web Profil | Next.js / WordPress (tema custom) |
| Web Sistem | **Laravel** (backend kuat untuk RBAC, transaksi, payroll) + dashboard React/Filament |
| Mobile Mandor | **PWA** (offline-first) |
| Database | MySQL / PostgreSQL |
| Pembayaran | Midtrans / Xendit (VA per termin) |
| Notifikasi | WhatsApp Business API + email (SMTP) |
| Penyimpanan berkas | Object storage (foto/video lapangan) |
| Hosting | VPS/Cloud (showcase kapabilitas SolIT) |

---

## 10. Entitas Data Utama (gambaran)

`User` (6 level) · `Konsumen` · `Karyawan` · `Konsultasi/Chat` · `Proyek` · `Desain` · `RAB` · `AHSAP` · `Material` · `Pembayaran/Termin` · `Pembiayaan` · `BankMitra` · `Supplier` · `Absensi` · `LaporanHarian` · `Payroll/SlipGaji` · `Transaksi (kas)` · `BAST` · `Notifikasi` · `AuditLog`

---

## 11. Roadmap v2

**Fase 1 — Fondasi:** Web profil + autentikasi & RBAC 6 level + konsultasi (login/no-login).
**Fase 2 — Sales:** Alur deal → desain → RAB (dari AHSAP) → buat proyek → checkout.
**Fase 3 — Pembayaran:** 3 skema termin + payment gateway + BAST digital.
**Fase 4 — Pembiayaan:** Alur bank mitra + portal mitra pembiayaan + reminder cicilan.
**Fase 5 — Lapangan:** Modul Mandor (PWA), absensi, laporan harian → timeline konsumen.
**Fase 6 — Back-office:** HR + payroll mingguan + Finance (buku kas) + Database Material + Supplier.
**Fase 7 — Penyempurnaan:** Notifikasi, dashboard KPI, garansi, laporan investor, optimasi.

---

## 12. Cakupan vs Dokumen Operasional

✅ 6 level akun & hak akses · ✅ Konsultasi login/no-login · ✅ Akun konsumen offline oleh manager · ✅ Alur pemesanan lengkap · ✅ Checkout + 3 skema pembayaran + BAST · ✅ Alur pembiayaan bank · ✅ **Bank memantau progres proyek yang dibiayainya** · ✅ Karyawan/tukang sebagai entitas terkelola (bukan akun) · ✅ Modul Mandor (absensi/laporan harian/auto-link konsumen) · ✅ Mitra pembiayaan & supplier · ✅ HR (gaji mingguan Sabtu, slip gaji, absensi) · ✅ Finance (buku kas) · ✅ Manager (AHSAP, RAB, rencana kerja) · ✅ Master AHSAP · ✅ Database Material + input mandor · ✅ Target bank mitra (BSI, Muamalat, BMT, BPRS, KSPPS)

**Status: konsep v2 menutup 100% kebutuhan di dokumen operasional, ditambah saran penyempurnaan.**
