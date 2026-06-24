# Deliverable 3 — Brief Teknis untuk Developer (web-cm)

Dokumen acuan pembangunan Web Sistem CV. Cimandiri. Dibaca bersama: Konsep v2, ERD (Deliverable 1), dan Wireframe (Deliverable 2).

---

## 1. Tujuan & Lingkup

Membangun dua aplikasi terhubung:
- **Web Profil** (`cvcimandiri.co.id`) — situs publik & marketing untuk 5 unit usaha; pintu konsultasi.
- **Web Sistem** (`/sistem`) — aplikasi operasional berbasis RBAC 6 level: konsultasi → desain/RAB → proyek → pembayaran/pembiayaan → lapangan → serah terima → HR/payroll → keuangan.

**Di luar lingkup tahap awal:** akuntansi penuh ber-PSAK, integrasi pajak resmi (e-Faktur). Disediakan hook agar bisa menyusul.

---

## 2. Tech Stack

| Komponen | Pilihan | Alasan |
|----------|---------|--------|
| Backend | **Laravel 11** (PHP 8.3) | RBAC, queue, scheduler, ekosistem matang |
| Auth | Laravel Sanctum + 2FA (TOTP) | API token + keamanan level tinggi |
| Admin/Dashboard | **Filament v3** atau React + Inertia | cepat untuk back-office; React bila butuh UX kustom |
| Frontend Profil | Next.js / Astro | SEO & performa |
| Mobile Mandor | **PWA** (Workbox, IndexedDB) | offline-first di lapangan |
| Database | **MySQL 8** / PostgreSQL 15 | relasional, transaksi |
| Queue/Cache | Redis | notifikasi, reminder, antrean upload |
| Storage | S3-compatible (object storage) | foto/video lapangan, berkas pembiayaan |
| Payment | **Midtrans / Xendit** | Virtual Account per termin |
| Notifikasi | WhatsApp Business API + email (SMTP) | multi-kanal |
| E-signature | TTE (mis. Privy/peruri) atau tanda tangan kanvas + hash | BAST digital |

> Membangun di stack ini sekaligus menjadi **showcase kapabilitas SolIT**.

---

## 3. Arsitektur

```
[ Web Profil (Next.js) ] --form konsultasi--> [ API Laravel ]
                                                   │
[ PWA Mandor ] --sync offline--> [ API Laravel ] --┤
                                                   ├── MySQL
[ Dashboard (Filament/React) ] <--> [ API Laravel ]├── Redis (queue/cache)
                                                   ├── S3 (media/berkas)
[ Payment Gateway ] <--webhook--> [ API Laravel ] ─┘
[ WhatsApp/Email ] <--queue-- 
```

- **Monolith modular** (Laravel) dengan domain modules: Auth, Sales, Project, Master(AHSAP/Material), Payment, Financing, Field, HR, Finance, Notification.
- API REST ber-versi (`/api/v1`). Webhook payment & WA diproses via queue.

---

## 4. RBAC — Aturan Wajib

Implementasi via Policy + Middleware. Acuan level di ERD.

1. Owner (`is_protected=true`) **tidak bisa dihapus** oleh siapa pun.
2. Tidak ada peran yang bisa menghapus akun dirinya sendiri.
3. Hak kelola akun: L1 & L2 (semua di bawah), L3 (di bawah, dalam domainnya), **L4 = tidak ada**, L5 (tidak ada akun bawahan — hanya entitas karyawan).
4. Scoping bidang: Manager & Mandor hanya akses data `bidang` miliknya.
5. **Bank (L4 pembiayaan):** akses **read-only** ke proyek dengan `bank_mitra_id = user.bank_mitra_id` (progres, laporan harian, media, BAST). Semua proyek lain tertutup.
6. Setiap aksi pada modul **akun & keuangan** menulis `audit_logs`.

**Acceptance:** uji otomatis untuk tiap matriks akses (peran × aksi × scope) wajib lulus.

---

## 5. Modul & Kriteria Penerimaan

### 5.1 Konsultasi
- Konsumen login → chat tersimpan; **tamu → chat session-only, dihapus saat sesi berakhir** (simpan di cache/Redis dengan TTL, bukan DB).
- Routing ke Manager sesuai `bidang`.
- *Acceptance:* tutup tab sebagai tamu → riwayat hilang; sebagai login → riwayat tetap.

### 5.2 Sales (Desain & RAB)
- Manager buat akun konsumen offline, desain & RAB ber-versi.
- **Builder RAB dari AHSAP:** pilih item → hitung subtotal + overhead + margin + PPN → grand total → generate PDF penawaran.
- Konsumen menyetujui → status `approved` → Manager buat `project`.
- *Acceptance:* perubahan harga material memunculkan flag pada RAB terkait.

### 5.3 Pembayaran & BAST
- 3 skema: `termin3` (30/40/30), `fifty` (50/50), `lunas`.
- `installments.due_condition`: `checkout` → langsung unlock; `progress50` → unlock saat `projects.progress_percent >= 50`; `bast` → unlock saat BAST `signed`.
- Integrasi VA payment gateway + verifikasi webhook → buat `transactions(income)`.
- BAST digital + e-signature → set `signed` → unlock pelunasan.
- *Acceptance:* pelunasan tak bisa dibayar sebelum BAST signed.

### 5.4 Pembiayaan
- Konsumen pilih `bank_mitra` → upload berkas → status flow: `submitted → docs_required → interview → approved/rejected → disbursed`.
- Setiap perubahan menulis `financing_status_logs` + notifikasi ke konsumen.
- Bank: verifikasi/approve/reject, kirim reminder cicilan, monitor proyek (read-only).
- *Acceptance:* konsumen menerima notifikasi tiap status berubah; bank hanya melihat proyek yang didanainya.

### 5.5 Lapangan (Mandor, PWA)
- Absensi `employees` per proyek/hari; laporan harian + media.
- **Offline-first:** input & media diantre di IndexedDB, sync saat online (idempotent, dedup by client UUID).
- Media & laporan otomatis muncul di timeline konsumen.
- *Acceptance:* input offline lalu online → tersinkron tanpa duplikasi.

### 5.6 HR & Payroll
- CRUD karyawan (entitas), status jabatan/gaji (log), rekap absensi.
- **Payroll mingguan harian:** periode tutup Jumat, cair **Sabtu**; gaji = hari hadir × upah harian; generate slip; kirim daftar bayar ke Finance.
- *Acceptance:* slip & total sesuai rekap absensi mandor.

### 5.7 Finance
- Buku kas income/expense berkategori; otomatis dari termin (income) & payroll/PO material (expense); verifikasi transfer manual.
- Laporan: arus kas, laba-rugi per proyek.

### 5.8 Master Data
- **AHSAP** + komponen (material/upah/alat) → harga otomatis.
- **Database Material** gabungan supplier + input mandor (field: tanggal otomatis, nama, merk, harga, spec, SNI, toko, alamat) + riwayat harga.
- Akses: semua internal **kecuali** Mitra & Konsumen.

---

## 6. API (gambaran endpoint utama)

```
POST  /api/v1/auth/login            GET  /api/v1/me
GET   /api/v1/consultations         POST /api/v1/consultations/{id}/messages
GET   /api/v1/projects              POST /api/v1/projects
POST  /api/v1/projects/{id}/rab     POST /api/v1/rabs/{id}/approve
POST  /api/v1/projects/{id}/checkout
GET   /api/v1/installments?project= POST /api/v1/payments/webhook (gateway)
POST  /api/v1/financings            POST /api/v1/financings/{id}/status
POST  /api/v1/attendances/sync      POST /api/v1/daily-reports (multipart)
GET   /api/v1/bank/projects         (scoped, read-only)
POST  /api/v1/payrolls/generate     POST /api/v1/transactions
GET   /api/v1/ahsap | /materials    POST /api/v1/materials
```

Konvensi: paginasi, filter `?bidang=`, response standar `{data, meta}`, error standar `{message, errors}`.

---

## 7. Integrasi
- **Payment gateway:** VA per termin; webhook → update `installments.status=paid` + `transactions`.
- **WhatsApp Business API:** kanal konsultasi & notifikasi (opt-in).
- **E-signature:** BAST; simpan hash + audit.
- **Object storage:** media lapangan & berkas pembiayaan (akses bertanda tangan/URL sementara).

## 8. Notifikasi (event → penerima)
- Konsultasi dibalas → konsumen · RAB siap → konsumen · Termin jatuh tempo → konsumen · Laporan harian baru → konsumen (& bank pemberi dana) · Status pembiayaan berubah → konsumen · Daftar payroll siap → Finance · Cicilan jatuh tempo → konsumen (oleh bank).

---

## 9. Non-Fungsional
- **Keamanan:** 2FA L1–L3; enkripsi berkas sensitif (KTP, slip gaji, dok pembiayaan); audit log; rate limit; validasi & otorisasi tiap endpoint; backup harian + retensi.
- **Performa:** index sesuai ERD §D; cache dashboard agregat; kompresi media.
- **Offline (PWA):** antrean tahan kehilangan koneksi; sync idempotent.
- **Privasi:** chat tamu tidak persist; URL media bertanda tangan & kedaluwarsa.
- **Audit:** semua mutasi akun & keuangan tercatat.
- **Aksesibilitas & responsif:** mobile-first untuk mandor & konsumen.

---

## 10. Lingkungan & Rilis
- Env: `local` → `staging` → `production`. CI/CD (GitHub Actions) + migrasi otomatis.
- Seeder: roles, akun Owner awal, contoh AHSAP & material, bank mitra (BSI, Muamalat, BMT, BPRS, KSPPS).
- Monitoring: log terpusat + error tracking + uptime.

---

## 11. Milestone (selaras Roadmap v2)

| Fase | Output | Estimasi |
|------|--------|----------|
| 1 | Auth + RBAC 6 level + Web Profil + Konsultasi | 3–4 mgg |
| 2 | Sales: desain, RAB dari AHSAP, buat proyek, checkout | 3–4 mgg |
| 3 | Pembayaran 3 skema + gateway + BAST digital | 2–3 mgg |
| 4 | Pembiayaan + portal bank + monitoring read-only | 2–3 mgg |
| 5 | PWA Mandor: absensi, laporan harian, sync offline | 3 mgg |
| 6 | HR + payroll mingguan + Finance + Master Material | 3–4 mgg |
| 7 | Notifikasi, dashboard KPI, garansi, optimasi & UAT | 2–3 mgg |

*Estimasi indikatif untuk tim 2–3 developer; perlu dikalibrasi tim.*

---

## 12. Asumsi & Pertanyaan Terbuka (perlu konfirmasi)
1. Margin & PPN RAB — apakah persentase tetap atau per proyek?
2. Pengingat cicilan oleh bank — kanal (WA/email/SMS) & frekuensi?
3. Apakah investor butuh akun tersendiri (read-only laporan), atau cukup dicatat sebagai sumber pemasukan di Finance?
4. E-signature BAST — pakai penyedia resmi (TTE) atau tanda tangan kanvas + bukti foto?
5. Apakah konsumen boleh punya beberapa proyek lintas bidang sekaligus? (skema data sudah mendukung)
6. Aturan upah harian: ada lembur/potongan, atau murni hari hadir × upah?

---

## 13. Definisi Selesai (Definition of Done)
Setiap modul: lulus uji RBAC, validasi input, unit/feature test inti, dokumentasi API, dan demo alur end-to-end pada staging sebelum UAT.
