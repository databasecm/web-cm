# Deliverable 1 — ERD & Struktur Database (web-cm)

Skema database untuk Web Sistem CV. Cimandiri. Dirancang relasional (MySQL/PostgreSQL), mendukung RBAC 6 level, alur pemesanan–pembayaran–pembiayaan, operasi lapangan, HR/payroll, dan keuangan.

**Konvensi**
- Setiap tabel punya `id` (PK, bigint auto-increment), `created_at`, `updated_at`.
- Soft delete (`deleted_at`) untuk entitas penting (users, projects, transactions).
- FK dinamai `<entitas>_id`.
- Enum ditulis sebagai daftar nilai; di MySQL boleh `ENUM` atau tabel referensi.

---

## A. Diagram per Domain

### A.1 Autentikasi & RBAC

```mermaid
erDiagram
    roles ||--o{ users : "memiliki"
    users ||--o{ users : "created_by"
    users ||--o{ audit_logs : "mencatat"

    roles {
        bigint id PK
        string name "owner|direktur|manager|finance|hr|mitra_pembiayaan|mitra_supplier|mandor|konsumen"
        tinyint level "1..6"
    }
    users {
        bigint id PK
        bigint role_id FK
        string name
        string email
        string password
        string bidang "cufid|cc|solit|birugis|null"
        boolean is_protected "true utk Owner"
        bigint created_by FK
    }
    audit_logs {
        bigint id PK
        bigint user_id FK
        string action
        string entity
        bigint entity_id
        json before
        json after
        string ip
    }
```

### A.2 Konsultasi & Penjualan (Sales)

```mermaid
erDiagram
    users ||--o{ consultations : "konsumen"
    users ||--o{ consultations : "manager"
    consultations ||--o{ consultation_messages : "berisi"
    users ||--o{ projects : "konsumen"
    users ||--o{ projects : "manager"
    projects ||--o{ designs : "punya"
    projects ||--|| rabs : "punya"
    rabs ||--o{ rab_items : "berisi"
    ahsap ||--o{ rab_items : "dasar"

    consultations {
        bigint id PK
        bigint konsumen_id FK "nullable (guest)"
        bigint manager_id FK
        string bidang
        boolean is_guest
        string status "open|deal|closed"
    }
    consultation_messages {
        bigint id PK
        bigint consultation_id FK
        string sender_type "konsumen|manager"
        text message
        string attachment
    }
    projects {
        bigint id PK
        bigint konsumen_id FK
        bigint manager_id FK
        string bidang
        string title
        string status "draft|design|rab|active|done|cancelled"
        decimal progress_percent
        decimal contract_value
        string payment_scheme "termin3|fifty|lunas"
        boolean is_financed
        bigint bank_mitra_id FK "nullable"
    }
    designs {
        bigint id PK
        bigint project_id FK
        int version
        string file
        string status "draft|submitted|approved"
    }
    rabs {
        bigint id PK
        bigint project_id FK
        int version
        decimal total_material
        decimal total_upah
        decimal overhead
        decimal margin
        decimal ppn
        decimal grand_total
        string status "draft|submitted|approved"
    }
    rab_items {
        bigint id PK
        bigint rab_id FK
        bigint ahsap_id FK "nullable"
        string description
        string unit
        decimal volume
        decimal unit_price
        decimal subtotal
    }
```

### A.3 Master AHSAP & Material

```mermaid
erDiagram
    ahsap ||--o{ ahsap_components : "tersusun dari"
    materials ||--o{ ahsap_components : "dipakai"
    materials ||--o{ material_price_history : "riwayat"
    suppliers ||--o{ materials : "memasok"
    users ||--o{ materials : "input (mandor/internal)"

    ahsap {
        bigint id PK
        string code
        string name
        string bidang
        string unit
        decimal base_price "hasil kalkulasi"
    }
    ahsap_components {
        bigint id PK
        bigint ahsap_id FK
        string type "material|upah|alat"
        bigint material_id FK "nullable"
        decimal coefficient
        decimal unit_price
    }
    materials {
        bigint id PK
        bigint supplier_id FK "nullable"
        bigint input_by FK "user (mandor/internal)"
        string name
        string brand
        decimal price
        string spec
        boolean is_sni
        string supplier_name
        string supplier_address
        string source "supplier|internal"
    }
    material_price_history {
        bigint id PK
        bigint material_id FK
        decimal price
        datetime recorded_at
    }
    suppliers {
        bigint id PK
        bigint user_id FK
        string company_name
    }
```

### A.4 Pembayaran, BAST & Pembiayaan

```mermaid
erDiagram
    projects ||--o{ installments : "ditagih via"
    projects ||--|| bast : "diserahkan"
    projects ||--o| financings : "dibiayai"
    bank_mitra ||--o{ financings : "memproses"
    financings ||--o{ financing_documents : "berkas"
    financings ||--o{ financing_status_logs : "riwayat status"
    installments ||--o{ transactions : "menghasilkan"

    installments {
        bigint id PK
        bigint project_id FK
        int term_no
        string label "DP|progress|pelunasan"
        decimal percentage
        decimal amount
        string due_condition "checkout|progress50|bast"
        string status "locked|unlocked|paid"
        string va_number
        datetime paid_at
    }
    bast {
        bigint id PK
        bigint project_id FK
        string file
        boolean signed_customer
        boolean signed_company
        datetime signed_at
        string status "draft|signed"
    }
    bank_mitra {
        bigint id PK
        bigint user_id FK
        string name "BSI|Muamalat|BMT|BPRS|KSPPS|..."
        string type "nasional|lokal"
    }
    financings {
        bigint id PK
        bigint project_id FK
        bigint konsumen_id FK
        bigint bank_mitra_id FK
        decimal amount
        string status "submitted|docs_required|interview|approved|rejected|disbursed"
    }
    financing_documents {
        bigint id PK
        bigint financing_id FK
        string name
        string file
        string status "pending|accepted|rejected"
    }
    financing_status_logs {
        bigint id PK
        bigint financing_id FK
        string status
        text note
        bigint created_by FK
    }
```

### A.5 Lapangan (Mandor), HR & Payroll

```mermaid
erDiagram
    employees ||--o{ attendances : "diabsen"
    projects ||--o{ attendances : "pada"
    projects ||--o{ daily_reports : "dilaporkan"
    daily_reports ||--o{ report_media : "foto/video"
    users ||--o{ daily_reports : "mandor"
    payrolls ||--o{ payslips : "berisi"
    employees ||--o{ payslips : "menerima"
    employees ||--o{ employee_status_logs : "riwayat"

    employees {
        bigint id PK
        string name
        string bidang
        string type "harian|bulanan"
        decimal daily_wage
        string position
        string status "aktif|nonaktif"
        bigint managed_by FK "mandor (user)"
    }
    attendances {
        bigint id PK
        bigint employee_id FK
        bigint project_id FK
        date date
        string status "hadir|izin|alpa"
        bigint recorded_by FK "mandor"
    }
    daily_reports {
        bigint id PK
        bigint project_id FK
        bigint mandor_id FK
        date date
        text description
        decimal progress_note
    }
    report_media {
        bigint id PK
        bigint daily_report_id FK
        string type "photo|video"
        string file
    }
    payrolls {
        bigint id PK
        date period_start
        date period_end "Sabtu utk harian"
        string type "weekly_daily|monthly"
        string status "draft|approved|paid"
    }
    payslips {
        bigint id PK
        bigint payroll_id FK
        bigint employee_id FK
        int days_present
        decimal daily_wage
        decimal gross
        decimal deductions
        decimal net
        string slip_file
    }
    employee_status_logs {
        bigint id PK
        bigint employee_id FK
        string change_type "promotion|salary"
        string old_value
        string new_value
        date effective_date
    }
```

### A.6 Keuangan & Notifikasi

```mermaid
erDiagram
    users ||--o{ transactions : "dicatat oleh (finance)"
    users ||--o{ notifications : "menerima"

    transactions {
        bigint id PK
        string type "income|expense"
        string category "pembayaran_konsumen|investor|material|operasional|gaji|lainnya"
        decimal amount
        string reference_type "installment|payroll|po|manual"
        bigint reference_id
        text description
        bigint recorded_by FK
        date date
    }
    notifications {
        bigint id PK
        bigint user_id FK
        string type
        string title
        text body
        string channel "inapp|email|wa"
        datetime read_at
    }
```

---

## B. Data Dictionary (ringkas, per domain)

**Autentikasi & RBAC**
- `roles` — 9 peran dipetakan ke 6 level (Owner=1, Direktur=2, Manager/Finance/HR=3, Mitra Pembiayaan/Supplier=4, Mandor=5, Konsumen=6).
- `users` — semua akun login. `is_protected=true` untuk Owner (tak bisa dihapus). `created_by` melacak hierarki pembuatan. `bidang` membatasi Manager/Mandor ke unit usahanya.
- `audit_logs` — jejak perubahan untuk modul akun & keuangan.

**Sales**
- `consultations` / `consultation_messages` — chat konsumen ke Manager per bidang. **Chat tamu (tanpa login) TIDAK disimpan di tabel ini** — disimpan di session/cache dan dihapus saat sesi berakhir. Hanya chat akun login yang persist.
- `projects` — proyek/produk hasil deal. Menyimpan skema bayar & status pembiayaan.
- `designs`, `rabs`, `rab_items` — desain & RAB ber-versi; item RAB bersumber dari AHSAP.

**Master**
- `ahsap` + `ahsap_components` — Analisa Harga Satuan Pekerjaan; komponen merujuk material → harga otomatis terhitung.
- `materials` + `material_price_history` — gabungan supplier + input internal/mandor; menyimpan SNI, toko, alamat, riwayat harga.
- `suppliers` — profil mitra supplier (Level 4).

**Transaksi**
- `installments` — termin pembayaran; `due_condition` mengatur kapan termin terbuka (checkout / progres 50% / BAST).
- `bast` — berita acara serah terima; status `signed` memicu termin pelunasan.
- `bank_mitra`, `financings`, `financing_documents`, `financing_status_logs` — alur pembiayaan bank, berkas, dan riwayat status.

**Lapangan & HR**
- `employees` — **entitas terkelola, bukan akun login**. Dipakai mandor untuk absensi & HR untuk penggajian.
- `attendances`, `daily_reports`, `report_media` — absensi + dokumentasi harian; media otomatis tampil di dashboard konsumen.
- `payrolls`, `payslips`, `employee_status_logs` — penggajian (harian dibayar mingguan tiap Sabtu) & slip gaji.

**Keuangan & Notifikasi**
- `transactions` — buku kas pemasukan/pengeluaran; otomatis terisi dari `installments` (income) & `payrolls`/PO material (expense).
- `notifications` — notifikasi multi-kanal.

---

## C. Catatan Implementasi RBAC

1. **Penegakan akses** di middleware/policy berdasarkan `roles.level` + `users.bidang`.
2. **Owner permanen:** blokir operasi delete bila `is_protected=true`. Tidak ada peran yang bisa menghapus dirinya sendiri (cek `auth()->id() !== target->id`).
3. **Level 4 (Mitra)** tidak memiliki kemampuan kelola akun apa pun.
4. **Bank monitoring:** scope query proyek bank = `projects WHERE bank_mitra_id = auth_user.bank_mitra_id` (read-only). Bank melihat progres, laporan harian, media, & BAST hanya untuk proyek tersebut.
5. **Mandor:** tidak punya akun bawahan; hanya CRUD `employees` (entitas) dan `attendances`/`daily_reports` pada proyek bidangnya.

---

## D. Indeks & Performa (saran)
- Index pada FK utama (`project_id`, `konsumen_id`, `bank_mitra_id`, `employee_id`).
- Index komposit `attendances(employee_id, date)` untuk rekap payroll.
- Index `installments(project_id, status)` untuk dashboard tagihan.
- Partisi/arsip `audit_logs` & `notifications` bila volume besar.
