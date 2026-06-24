# CLAUDE.md — Konteks Proyek web-cm

> File ini dibaca oleh **Pengembang** (Claude Code) di setiap sesi. Tujuannya menjaga konsistensi. Spesifikasi lengkap ada di folder `/docs`.

## 1. Apa ini
Web Sistem operasional **CV. Cimandiri** — perusahaan build-tech (berdiri 2008, Bogor) dengan 5 unit usaha: **CuFID** (furniture), **Custom Construction** (CC), **SolIT** (IT), **BIRU GIS** (survey/pemetaan), dan **CM** (induk). Sistem mengalirkan: konsultasi → desain/RAB → proyek → pembayaran/pembiayaan → lapangan → serah terima (BAST) → HR/payroll → keuangan.

Dibangun oleh satu orang (pemilik proyek) dengan bantuan Pengembang, dikendalikan dari HP. Kerjakan **bertahap & inkremental**.

## 2. Dokumen acuan (WAJIB dibaca sebelum mengerjakan modul)
- `/docs/konsep-v2.md` — konsep produk & alur bisnis lengkap
- `/docs/erd-database.md` — skema database (acuan tabel/relasi)
- `/docs/brief-teknis.md` — arsitektur, modul, acceptance criteria, milestone

## 3. Tech stack (sudah diputuskan — jangan diganti tanpa diminta)
- **Laravel 11**, PHP 8.5, **MySQL 8**
- **Filament v3** → dashboard internal (Owner, Direktur, Manager, Finance, HR, Mitra, Mandor)
- **Laravel Sanctum** → API untuk frontend konsumen & PWA Mandor (dibangun di fase lanjut)
- **spatie/laravel-permission** → roles & permissions, dilengkapi Policy kustom untuk aturan hierarki
- **Redis** (queue/cache), object storage S3-compatible (media), Midtrans/Xendit (payment) — diintegrasikan di fase terkait

## 4. Arsitektur singkat
Monolith modular Laravel. API REST ber-versi `/api/v1`. Dashboard internal via Filament. Webhook payment & notifikasi via queue.

## 5. Konvensi kode
- Ikuti **PSR-12**; format dengan Laravel Pint.
- Migration: satu tabel per file, gunakan FK constraints. Tabel & kolom `snake_case`, model `StudlyCase`.
- Validasi via Form Request; otorisasi via Policy/Gate (jangan menaruh aturan akses di controller mentah).
- **UI berbahasa Indonesia**, kode & komentar berbahasa Inggris.
- Tulis test untuk logika penting (terutama RBAC, pembayaran, payroll).

## 6. ATURAN RBAC (hard rules — tidak boleh dilanggar)
6 level: Owner(1) · Direktur(2) · Manager/Finance/HR(3) · Mitra Pembiayaan/Supplier(4) · Mandor(5) · Konsumen(6).
1. Akun **Owner** `is_protected=true` → **tidak bisa dihapus** siapa pun.
2. **Tidak ada** peran yang bisa menghapus akun dirinya sendiri.
3. Kelola akun: L1 & L2 (semua di bawah) · L3 (di bawah, dalam domainnya) · **L4 = TIDAK ADA hak kelola akun** · L5 = tidak punya akun bawahan.
4. **Manager & Mandor** hanya mengakses data sesuai `bidang` miliknya (cufid/cc/solit/birugis).
5. **Bank (Mitra Pembiayaan)**: akses **read-only**, hanya ke proyek dengan `bank_mitra_id` = miliknya. Proyek lain tertutup total.
6. Semua mutasi modul **akun & keuangan** wajib menulis `audit_logs`.

## 7. Aturan bisnis kunci (sering terlupa)
- **Karyawan/tukang = entitas data, BUKAN akun login.** Dipakai untuk absensi (oleh Mandor) & payroll (oleh HR).
- **Chat konsultasi tamu (tanpa login) TIDAK disimpan di DB** — simpan di cache/session ber-TTL, hilang saat sesi berakhir. Hanya chat akun login yang persist.
- **Pembayaran** 3 skema: termin 30/40/30, 50:50, lunas. Termin terbuka berdasarkan `due_condition`: `checkout` (langsung), `progress50` (progres ≥50%), `bast` (setelah BAST ditandatangani). **Pelunasan tidak bisa dibayar sebelum BAST signed.**
- **Payroll harian** dibayar **mingguan tiap Sabtu** = hari hadir × upah harian.
- AHSAP adalah dasar RAB; perubahan harga material harus bisa menandai RAB terdampak.

## 8. Cara kerja dengan Pengembang
- Kerjakan **satu task kecil per sesi**; buat **feature branch** dan **PR**, jangan langsung ke `main`.
- Jelaskan rencana singkat sebelum menulis kode bila task ambigu; bila ragu, **tanya** — jangan berasumsi diam-diam.
- **Jangan pernah commit secret** (API key, password). Pakai `.env`; perbarui `.env.example` saja.
- Jalankan migrasi & test sebelum menyatakan selesai. Commit message jelas (Conventional Commits, mis. `feat: ...`, `fix: ...`).
- Setelah selesai, ringkas apa yang dibuat + cara mengetesnya.
