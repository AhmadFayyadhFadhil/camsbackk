# PRODUCT REQUIREMENT DOCUMENT (PRD)
# CLEANING & MAINTENANCE SYSTEM (CAMS)
**Dokumen Spesifikasi Kebutuhan Produk & Panduan Implementasi Teknis Tim**

* **Versi Dokumen:** 1.0.0 (Production-Ready Spec)
* **Tanggal Rilis:** November 2026
* **Target Implementasi:** PT Widatra Bhakti (Pandaan, Jawa Timur)
* **Status Sistem:** Aktif / Siap Kolaborasi Tim
* **Repositori Backend:** `https://github.com/AhmadFayyadhFadhil/camsbackk.git`
* **Repositori Frontend:** `https://github.com/AhmadFayyadhFadhil/camsfee.git`

---

## DAFTAR ISI
1. [Pendahuluan & Visi Produk](#1-pendahuluan--visi-produk)
2. [Arsitektur Sistem & Tech Stack](#2-arsitektur-sistem--tech-stack)
3. [Matriks Peran Pengguna (RBAC) & Hak Akses](#3-matriks-peran-pengguna-rbac--hak-akses)
4. [Rincian Modul & Fungsionalitas Sistem](#4-rincian-modul--fungsionalitas-sistem)
   * 4.1. Modul Autentikasi, Keamanan Sesi & Profil
   * 4.2. Modul Master Data Gedung, Ruangan, Mutasi PIC & Shift
   * 4.3. Modul Template Checklist & Item Standar Kebersihan
   * 4.4. Modul Penjadwalan & Penugasan Harian (Schedules & CS Assignments)
   * 4.5. Modul Pengerjaan Tugas CS, Validasi Geofence & Offline Queue
   * 4.6. Modul Verifikasi Laporan, On-Site Inspection & Rating SLA
   * 4.7. Modul Temuan Kerusakan Fasilitas (Findings / Maintenance Tickets)
   * 4.8. Modul Tugas Khusus, Ad-Hoc & Event Acara
   * 4.9. Modul Audit & Stock Opname Aset Ruangan (Bimonthly Audit)
   * 4.10. Modul Pemakaian Bahan Kimia & Material Pembersih
   * 4.11. Modul Notifikasi Real-Time (Server-Sent Events)
   * 4.12. Modul Dashboard Analitik & Cleanliness Grid Matrix
   * 4.13. Modul Pelaporan, Ekspor PDF/Excel & Audit Trail Log
   * 4.14. Modul Pengaturan Sistem & Identitas Perusahaan
5. [Otomasi Latar Belakang (Console Commands & Cron Scheduler)](#5-otomasi-latar-belakang-console-commands--cron-scheduler)
6. [Skema Database & Relasi Model (Data Dictionary)](#6-skema-database--relasi-model-data-dictionary)
7. [Panduan Teknis untuk Tim Kolaborasi](#7-panduan-teknis-untuk-tim-kolaborasi)
   * 7.1. Pembagian Tugas Tim (2 Kolaborator)
   * 7.2. Standar Branching & Commit Message
   * 7.3. Akun Pengujian & Kredensial Uji Coba
   * 7.4. Prosedur Uji Mutasi Data & Zero-Regression Checklist

---

## 1. PENDAHULUAN & VISI PRODUK

### 1.1 Latar Belakang
**CAMS (Cleaning and Maintenance System)** adalah sistem informasi terintegrasi berbasis web dan mobile-friendly yang dirancang untuk mengotomasi, memantau, memvalidasi, dan mengaudit seluruh kegiatan sanitasi kebersihan fasilitas serta pemeliharaan sarana/prasarana operasional industri farmasi pada lingkungan **PT Widatra Bhakti** di Pandaan.

Industri farmasi menuntut standar higienitas dan kepatuhan cGMP (*Current Good Manufacturing Practice*) yang sangat ketat. Pemantauan manual berbasis kertas (lembar checklist gantung) memiliki sejumlah celah fatal:
1. **Titip Absen & Pemalsuan Data (*Fraud*):** Staf mencentang form tanpa hadir di lokasi fisik ruangan.
2. **Ketiadaan Validasi Bukti Visual:** Tidak ada dokumentasi visual *before* dan *after* kondisi ruangan.
3. **Keterlambatan Penanganan Kerusakan:** Fasilitas yang rusak tidak segera tereskalasi ke teknisi/OB.
4. **Kendala Sinyal di Area Produksi (*Blank Spot*):** Cleanroom atau ruang bawah tanah sering tidak terjangkau jaringan internet seluler, sehingga aplikasi biasa akan gagal submit.
5. **Ketiadaan Audit Trail:** Pelacakan penanggung jawab (*PIC*) dan mutasi kepemilikan ruangan sulit diaudit saat inspeksi audit mutu regulatori.

### 1.2 Tujuan CAMS
* Menjamin **kehadiran fisik nyata** petugas di lokasi ruangan melalui kombinasi **Token QR Code Dinamis** dan **GPS Geofencing Toleransi Ketat** (rumus Haversine).
* Menyediakan kemampuan kerja **Offline-First via Offline Queue** di sisi peramban klien saat staf berada di area *blank spot* industri, dengan kompresi foto otomatis di bawah 1MB.
* Menyediakan **alur verifikasi bertingkat** oleh PIC Ruangan dan Supervisor dengan sistem penilaian mutu (SLA Star Rating 1-5).
* Menyediakan sistem **eskalasi otomatis** berbasis waktu (timeout 120 menit) untuk mencegah laporan mengendap.
* Mengintegrasikan pencatatan pemakaian bahan kimia sanitasi (*cleaning materials*) dan stock opname fisik aset (*bimonthly audit*).

---

## 2. ARSITEKTUR SISTEM & TECH STACK

```
+----------------------------------------------------------------------------------+
|                              FRONTEND ARCHITECTURE                               |
|   React 19 + Vite 6 + Tailwind/Modern CSS + Lucide Icons + Leaflet GIS Maps      |
|                                                                                  |
|   [LocalStorage/Session]        [HTML5 Canvas]              [SSE Client]         |
|   - OfflineQueueUtil.js         - imageCompressor.js        - Notification Stream|
|   - In-Flight Cache (api.js)    - Max 1600px, < 1000 KB     - Auto Tab Router    |
+----------------------------------------------------------------------------------+
                                         |
                                (REST API / SSE JSON)
                                         |
+----------------------------------------------------------------------------------+
|                              BACKEND ARCHITECTURE                                |
|   Laravel 11.x (PHP 8.2+) + Laravel Sanctum + Custom Middleware Throttling       |
|                                                                                  |
|   [Middleware Layer]             [Service Layer]           [Console Automation]  |
|   - auth:sanctum                 - TaskGeneratorService    - cams:generate-tasks |
|   - check.expiry (480m / 1440m)  - QrCodeService           - cams:check-overdue  |
|   - cs.shift (30m buffer)        - NotificationService     - cams:send-reminders |
|   - role:admin,supervisor,...    - AuditLogService         - cams:check-escalate |
|   - throttle:login / api         - GeoHelper (Haversine)   - cams:check-deadlines|
+----------------------------------------------------------------------------------+
                                         |
+----------------------------------------------------------------------------------+
|                              DATABASE ARCHITECTURE                               |
|   MySQL 8.0 / MariaDB (InnoDB, Foreign Key Cascades, Soft Deletes, UUIDs)        |
+----------------------------------------------------------------------------------+
```

### 2.1 Backend Specifications
* **Framework:** Laravel 11.x berjalan pada PHP 8.2+
* **Autentikasi:** Laravel Sanctum dengan token-based authentication. Token disimpan aman di database pada tabel `personal_access_tokens`.
* **State Management & Sesi:** Konfigurasi `SESSION_DRIVER=file` dan `CACHE_STORE=file` untuk keandalan maksimal tanpa ketergantungan ekstensi Redis eksternal.
* **Format Waktu Baku:** `Asia/Jakarta` (WIB) di seluruh manipulasi Carbon dan database.
* **Geofencing Engine:** `App\Helpers\GeoHelper::haversineDistance()` dengan radius kelengkungan bumi $R = 6.371.000\text{ m}$.

### 2.2 Frontend Specifications
* **Engine:** React 19 dengan compiler Vite 6.
* **Penyimpanan Klien:** `sessionStorage` untuk Bearer Token & User Context (mencegah persistensi tidak sengaja saat browser ditutup bersama) dan `localStorage` untuk Offline Queue data submission.
* **Image Processor:** `imageCompressor.js` berbasis HTML5 2D Canvas. Mengompres foto beresolusi tinggi dari kamera gawai menjadi JPEG berdimensi maks 1600px dengan ukuran file $< 1\text{ MB}$.
* **Pemetaan GIS:** Leaflet JS dengan tile OpenStreetMap untuk visualisasi koordinat gedung dan jejak inspeksi verifikasi (*inspection map trail*).

---

## 3. MATRIKS PERAN PENGGUNA (RBAC) & HAK AKSES

Sistem CAMS menerapkan kontrol akses berbasis peran (*Role-Based Access Control*) yang ketat melalui middleware `role:*` dan pengecekan sisi klien:

| Menu / Kapabilitas Sistem | Admin | Supervisor | PIC Area | CS (Cleaning) | OB (Maintenance) | Guest / Pelapor |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **Dashboard Operasional** | Full KPI | Full KPI | Area PIC | CS Personal | Tiket OB | Redirected |
| **Master Gedung & Ruangan** | CRUD | CRUD | Read | - | - | - |
| **Generate QR Code Ruangan** | Download/Print | Download/Print | Read | - | - | - |
| **Shift & Assignment CS** | CRUD | CRUD | Read | - | - | - |
| **Checklist Template & Items** | CRUD | CRUD | Read | - | - | - |
| **Pengerjaan Tugas CS** | - | - | - | Eksekusi (Shift Valid) | - | - |
| **Verifikasi & Approval** | Approve/Reject | Approve/Reject | Approve/Reject | - | - | - |
| **Input Temuan Kerusakan** | Yes | Yes | Yes | Yes | Yes | Yes (Publik) |
| **Update Status Tiket Kerusakan** | Yes | Yes | - | - | In Progress/Resolved | - |
| **Tugas Khusus / Ad-Hoc** | Buat & Verifikasi | Buat & Verifikasi | - | Eksekusi Tahapan | - | - |
| **Stock Opname Aset Ruangan** | Verifikasi | Verifikasi | - | Input Audit Fisik | - | - |
| **Export Laporan (PDF/Excel)** | Yes | Yes | - | - | - | - |
| **Audit Activity Log** | View All | View All | View Area | View Own | - | - |
| **Kelola Akun & Reset Sandi** | Full CRUD | Kelola Pengguna | - | - | - | - |
| **Pengaturan Logo & Branding** | Full Edit | Read Only | - | - | - | - |

---

## 4. RINCIAN MODUL & FUNGSIONALITAS SISTEM

### 4.1 Modul Autentikasi, Keamanan Sesi & Profil
1. **Endpoint Login:** `POST /api/v1/auth/login`
   * Menerima kombinasi `username` atau `email` dan `password`.
   * Dilindungi rate limiter `throttle:login` (maksimal 5 percobaan gagal per menit).
   * Menghasilkan Sanctum Bearer Token yang disisipkan ke header `Authorization: Bearer <token>`.
2. **Durasi Kedaluwarsa Token:**
   * Diatur via middleware `CheckTokenExpiry`:
     * Role **Cleaning Service (`cs`)**: Masa aktif token dibatasi **480 menit (8 jam)** untuk menyesuaikan siklus shift fisik.
     * Role lainnya (**Admin, Supervisor, PIC, OB**): Masa aktif token berlaku **1440 menit (24 jam)**.
3. **Manajemen Profil:**
   * `GET /api/v1/auth/me`: Mengambil profil terbaru, disinkronisasi setiap kali aplikasi web dibuka.
   * `POST /api/v1/auth/profile`: Update nama, email, nomor telepon, dan upload foto profil.
   * `POST /api/v1/auth/change-password`: Ganti kata sandi mandiri dengan validasi kata sandi lama.
   * `POST /api/v1/auth/reset-password/{userId}`: Akses khusus Admin untuk me-reset sandi staf bermasalah.

---

### 4.2 Modul Master Data Gedung, Ruangan, Mutasi PIC & Shift
1. **Kelola Gedung (`buildings`):**
   * Field: `nama_gedung`, `kode_gedung` (unik), `alamat`, `latitude`, `longitude`, `radius_meter` (toleransi geofence gedung, default 250 meter), `is_active`.
   * Relasi `shifts()` melalui tabel perantara `building_shifts` untuk menentukan shift apa saja yang berlaku di gedung terkait.
2. **Kelola Ruangan (`rooms`):**
   * Primary key: **UUID string** (`id`).
   * Field: `building_id`, `kode_ruangan` (unik), `nama_ruangan`, `lantai`, `latitude`, `longitude`, `radius_meter` (toleransi ruangan, default 25 meter), `pic_user_id` (foreign key ke users), `qr_code_token` (UUID unik), `is_active`.
3. **Riwayat Mutasi PIC Ruangan (`room_pic_histories`):**
   * Setiap kali `pic_user_id` pada ruangan diubah oleh Supervisor/Admin, sistem otomatis mencatat entri riwayat mencakup: `room_id`, `previous_pic_id`, `new_pic_id`, `changed_by_user_id`, dan `changed_at`.
4. **Master Shift Kerja (`shifts`):**
   * Standar Shift:
     * **S1 (Shift Pagi):** 06:00:00 - 14:00:00 WIB
     * **S2 (Shift Siang):** 14:00:00 - 22:00:00 WIB
     * **S3 (Shift Malam - Overnight):** 22:00:00 - 06:00:00 WIB (`is_overnight = true`)
     * **SN (Shift Normal Kantor):** 07:30:00 - 16:30:00 WIB
   * Logika Toleransi Buffer Shift (`ShiftValidatorHelper.php`):
     * Diberikan toleransi default **30 menit** sebelum jam mulai dan sesudah jam selesai.
     * Untuk shift lintas hari (`is_overnight`), jam pengerjaan valid jika waktu sekarang $\ge$ 21:30 **atau** waktu sekarang $\le$ 06:30 keesokan harinya.
5. **Pencetakan Massal QR Code Ruangan (`BulkQrPrint.jsx`):**
   * Supervisor/Admin dapat memilih seluruh ruangan per gedung dan mencetak lembar stiker QR Code berstandar industri lengkap dengan Nama Gedung, Kode Ruangan, Lantai, dan Token QR terenkripsi.

---

### 4.3 Modul Template Checklist & Item Standar Kebersihan
1. **Master Item Kebersihan (`checklist_items`):**
   * Daftar komponen fisik yang harus dibersihkan (contoh: Lantai & Sudut Ruangan, Kaca Jendela, Meja Kerja, Wastafel & Kran Air, Tempat Sampah, Plafon & Sarang Laba-laba).
   * Standar kebersihan eksplisit (contoh: "Kering, bebas noda kerak air, dan tidak berbau").
2. **Template Checklist (`checklist_templates` & `checklist_template_items`):**
   * Dikelompokkan berdasarkan kategori ruangan (misal: *Template Cleanroom Grade A*, *Template Perkantoran*, *Template Toilet Pabrik*).
   * Tiap item dalam template memiliki frekuensi pengerjaan (`daily`, `weekly`, `monthly`) dan urutan tampil (`sort_order`).
   * Tombol *Apply Template to Schedules* memungkinkan Supervisor menerapkan satu paket template ke seluruh ruangan dalam gedung sekaligus hanya dalam 1 klik.

---

### 4.4 Modul Penjadwalan & Penugasan Harian (Schedules & CS Assignments)
1. **Jadwal Rutin (`schedules`):**
   * Menghubungkan `room_id`, `shift_id`, frekuensi, dan target waktu pengerjaan (`target_time`).
2. **Penugasan CS (`cs_assignments`):**
   * Menugaskan staf CS (`cs_user_id`) ke jadwal tertentu (`schedule_id`) untuk rentang tanggal mulai (`start_date`) hingga selesai (`end_date`).
3. **Endpoint Optimasi Data Awal (`schedules/init-data`):**
   * Menggabungkan data gedung, ruangan, shift, template, dan pengguna aktif dalam satu request tunggal yang di-cache di memori untuk mempercepat loading form jadwal di frontend.

---

### 4.5 Modul Pengerjaan Tugas CS, Validasi Geofence & Offline Queue
Ini adalah inti operasional lapangan dari sistem CAMS:

```
[1. Staf CS Scan QR di Pintu Ruangan]
               │
               ▼
[2. Validasi Token QR & Geofence GPS (Haversine)]
   - Cek radius toleransi (Maksimal 250m dari Gedung / 25m dari Ruangan)
   - Cek kesesuaian Shift Aktif (+/- 30 menit buffer)
               │
               ├─► [Gagal Validasi] ──► Muncul Peringatan: Di Luar Jarak / Bukan Shift Anda
               ▼
[3. Form Checklist & Foto Dibuka]
   - Periksa item checklist (Kondisi: Bersih / Perlu Perhatian)
   - Unggah 1 Foto "Sebelum Pengerjaan" (Before)
   - Catat pemakaian bahan kimia/sanitizer yang digunakan
               │
               ▼
[4. Proses Pembersihan Selesai]
   - Unggah hingga 4 Foto "Sesudah Pengerjaan" (After 1, 2, 3, 4)
   - Foto otomatis dikompres oleh `imageCompressor.js` (< 1MB)
               │
               ├─► [Ada Sinyal Internet] ──► Kirim langsung ke `POST /api/v1/submissions`
               ▼
[5. Mode Blank-Spot (Tanpa Sinyal)]
   - Data otomatis disimpan ke `cams_offline_queue` (localStorage)
   - Tampil indikator antrean offline warna oranye di layar
   - Saat sinyal kembali, tombol "Sinkronkan Antrean" mengirim paket data ke server
```

1. **Aturan Validasi Foto:**
   * File foto wajib bertipe gambar (JPEG/PNG).
   * Minimal 1 foto *Before* dan minimal 1 foto *After-1*. Foto *After-2* hingga *After-4* opsional untuk sudut ruangan yang luas.
2. **Resubmission Setelah Reject:**
   * Jika laporan ditolak oleh PIC/Supervisor, status tugas kembali menjadi `REJECTED`. Staf CS dapat membuka tugas tersebut dan menekan tombol resubmit melalui `POST /api/v1/submissions/{id}/resubmit` dengan menyertakan bukti foto perbaikan baru.

---

### 4.6 Modul Verifikasi Laporan, On-Site Inspection & Rating SLA
1. **Daftar Antrean Pending (`GET /api/v1/verifications/pending`):**
   * PIC Ruangan hanya melihat laporan ruangan yang berada di bawah wewenangnya.
   * Supervisor dan Admin melihat seluruh laporan yang belum diverifikasi di seluruh pabrik.
2. **Approval di Lokasi (*On-Site Inspection*):**
   * PIC melakukan inspeksi fisik langsung.
   * Endpoint: `POST /api/v1/verifications/{submissionId}/approve`
   * Menyertakan:
     * `catatan`: Catatan evaluasi kebersihan.
     * `foto_inspeksi`: Foto bukti verifikator saat berada di ruangan (opsional/rekomendasi).
     * `sla_rating`: Penilaian bintang 1 sampai 5.
     * `sla_parameter_ratings`: Skor rinci per parameter SLA mutu kebersihan (kebersihan debu, aroma, kerapihan tata letak, kepatuhan sanitasi).
3. **Rejection Laporan:**
   * Endpoint: `POST /api/v1/verifications/{submissionId}/reject`
   * Mewajibkan pengisian `catatan_revisi` (alasan penolakan, misal: "Sudut belakang meja masih berdebu tebal").
   * Notifikasi otomatis terkirim ke CS terkait untuk pengerjaan ulang.

---

### 4.7 Modul Temuan Kerusakan Fasilitas (Findings / Maintenance Tickets)
Modul untuk melaporkan sarana prasarana pabrik yang rusak, bocor, atau membahayakan:
1. **Input Temuan Kerusakan (`POST /api/v1/findings`):**
   * Dapat diisi oleh CS, PIC, Supervisor, Admin, maupun Pelapor Umum/Tamu.
   * Field: `room_id`, `finding_category_id` (Plumbing, Elektrikal, HVAC, Sipil, Sanitasi), `deskripsi`, `prioritas` (`low`, `medium`, `high`, `critical`), `foto` (foto awal kerusakan).
2. **SLA Response Time:**
   * Prioritas `critical`: Target penanganan maksimal 4 jam.
   * Prioritas `high`: Target penanganan maksimal 24 jam.
   * Prioritas `medium`: Target penanganan 3 hari kerja.
   * Prioritas `low`: Target penanganan 7 hari kerja.
3. **Penanganan oleh Office Boy (OB) / Teknisi:**
   * Tiket ditugaskan ke OB tertentu (`assigned_to_user_id`) atau teknisi vendor luar (`assigned_to_external`).
   * OB memperbarui status ke `in_progress` saat mulai memperbaiki.
   * Setelah selesai, OB mengunggah **1 sampai 4 foto bukti hasil perbaikan** (`foto_resolved`, `foto_ob_1` s/d `foto_ob_4`) melalui endpoint `PATCH /api/v1/findings/{id}/status` dengan status `resolved`.
4. **Penyelesaian & Penutupan Tiket:**
   * Supervisor/Admin memverifikasi perbaikan fisik dan menutup tiket (`closed`).

---

### 4.8 Modul Tugas Khusus, Ad-Hoc & Event Acara
Menangani penugasan insidental di luar rutinitas harian (misal: "Persiapan Ruang Rapat Direksi", "General Cleaning Pasca Renovasi", atau "Disinfeksi Sterilisasi Tahunan").
* **Alur 3 Tahap Eksekusi CS:**
  1. **Tahap Persiapan (Setup):** CS menekan `POST /adhoc-tasks/{id}/start` lalu mengunggah foto persiapan meja/kursi/alat via `POST /adhoc-tasks/{id}/submit-setup`.
  2. **Tahap Pelaksanaan:** Tugas berstatus `IN_PROGRESS`.
  3. **Tahap Perapihan (Cleanup):** Pasca acara selesai, CS membersihkan kembali ruangan dan mengunggah foto perapihan via `POST /adhoc-tasks/{id}/submit-cleanup`.
* **Verifikasi Supervisor:** Supervisor memeriksa seluruh rangkaian foto tahapan dan menyetujui via `POST /adhoc-tasks/{id}/verify`.

---

### 4.9 Modul Audit & Stock Opname Aset Ruangan (Bimonthly Audit)
Kepatuhan farmasi menuntut pencatatan aset inventaris ruangan secara berkala setiap 2 bulan sekali (*bimonthly*).
1. **Pohon Aset Gedung & Ruangan (`GET /buildings/{id}/assets-tree`):**
   * Menampilkan struktur hierarki seluruh aset per ruangan (contoh: APAR Powder 6kg, Kursi ESD Cleanroom, Keranjang Sampah Medis, Mesin Polisher).
2. **Pelaksanaan Audit Fisik:**
   * Staf CS/Auditor memeriksa kondisi riil aset di ruangan.
   * Menginput kuantitas fisik (`jumlah_fisik`), kondisi (`kondisi`: BAIK, RUSAK_RINGAN, RUSAK_BERAT, HILANG), catatan selisih, dan mengunggah foto fisik aset.
3. **Rekonsiliasi & Verifikasi Discrepancy:**
   * Sistem otomatis menghitung selisih antara kuantitas sistem vs kuantitas fisik.
   * Supervisor memverifikasi laporan audit via `POST /room-asset-audits/{id}/verify`.

---

### 4.10 Modul Pemakaian Bahan Kimia & Material Pembersih
* Master bahan kimia pembersih (`cleaning_materials`): nama bahan, satuan (liter/botol/pcs), stok tersedia.
* Saat CS menyerahkan checklist kebersihan ruangan, CS juga mencatat pemakaian material pembersih pada tabel `submission_materials` (misal: *Floor Cleaner Karbol 100 ml*, *Glass Cleaner Spray 50 ml*).
* Memungkinkan departemen GA (*General Affairs*) memantau efisiensi pemakaian chemical pembersih per gedung.

---

### 4.11 Modul Notifikasi Real-Time (Server-Sent Events)
1. **SSE Stream Endpoint:** `GET /api/v1/notifications/stream`
   * Mengalirkan event notifikasi secara *streaming* berdaya rendah tanpa membebani server dengan polling berulang.
2. **Kategori Notifikasi:**
   * `TASK_REMINDER`: Pengingat tugas mendesak ke CS.
   * `TASK_OVERDUE`: Peringatan tugas terlambat ke Supervisor.
   * `SUBMISSION_PENDING`: Notifikasi laporan baru ke PIC Ruangan untuk diverifikasi.
   * `ESCALATION_PIC`: Notifikasi eskalasi ke Supervisor jika PIC tidak merespons dalam 120 menit.
   * `SUBMISSION_APPROVED` / `SUBMISSION_REJECTED`: Konfirmasi hasil verifikasi ke CS.
   * `FINDING_NEW` / `FINDING_OVERDUE`: Tiket temuan baru atau temuan melewati deadline SLA.
3. **Smart Notification Router (`notificationRouter.js`):**
   * Di frontend, saat pengguna mengklik item notifikasi, aplikasi langsung mengarahkan pengguna ke tab spesifik yang relevan dan membuka modal data terkait secara otomatis.

---

### 4.12 Modul Dashboard Analitik & Cleanliness Grid Matrix
Dashboard disesuaikan secara dinamis berdasarkan peran aktif pengguna:
* **Dashboard Supervisor:**
  * Metrik Kepatuhan Harian (*Daily Compliance Rate* %).
  * Donut Chart status tugas (Pending, In Progress, Submitted, Approved, Overdue).
  * Bar Chart perbandingan kepatuhan kebersihan antar gedung.
  * Widget peringatan temuan kerusakan berstatus *Critical*.
* **Dashboard PIC:**
  * Daftar ruangan yang menjadi tanggung jawabnya.
  * Kartu indikator verifikasi mendesak (*Urgent Pending Verifications*).
* **Dashboard CS:**
  * Informasi shift aktif dan rentang waktu toleransi pengerjaan.
  * Progres tugas hari ini (misal: 6 dari 8 ruangan selesai).
  * Antrean tugas offline yang belum tersinkron.
* **Dashboard OB:**
  * Jumlah tiket perbaikan aktif yang ditugaskan.
  * Countdown waktu SLA sisa untuk tiket kerusakan darurat.
* **Cleanliness Grid Matrix:**
  * Tampilan visual matriks kotak-kotak seluruh ruangan per lantai gedung.
  * Kode warna instan: Hijau (Sudah Diverifikasi Bersih), Kuning (Menunggu Verifikasi), Biru (Sedang Dikerjakan CS), Merah (Overdue / Belum Dikerjakan), Abu-abu (Tidak Ada Jadwal).

---

### 4.13 Modul Pelaporan, Ekspor PDF/Excel & Audit Trail Log
1. **Ekspor Laporan Kepatuhan (`POST /api/v1/reports/export/pdf` & `/excel`):**
   * Filter: Rentang tanggal (`start_date`, `end_date`), `building_id`, `shift_id`, `status`.
   * Format cetak rapi dengan kop resmi perusahaan, matriks persentase kepatuhan, tabel hasil pemeriksaan item, dan rekap tanda tangan verifikator.
2. **Ekspor Laporan Temuan Fasilitas (`POST /api/v1/reports/export/findings-pdf` & `/excel`):**
   * Rekap histori temuan kerusakan, kategori fasilitas, kecepatan penyelesaian teknisi, dan performa SLA.
3. **Audit Activity Log (`GET /api/v1/audit-logs`):**
   * Menggunakan `AuditLogService` untuk merekam:
     * `user_id`: Pengguna yang melakukan tindakan.
     * `action`: Jenis aksi (CREATE, UPDATE, DELETE, VERIFY, LOGIN, REJECT).
     * `model_type` & `model_id`: Entitas yang terdampak.
     * `ip_address` & `user_agent`: Alamat IP dan perangkat peramban.
     * `old_values` & `new_values`: JSON perbandingan data sebelum dan sesudah perubahan (memenuhi syarat cGMP audit trail).

---

### 4.14 Modul Pengaturan Sistem & Identitas Perusahaan
* **Identitas Perusahaan:**
  * Nama Perusahaan: `company_name` (default: "PT WIDARTA BHAKTI").
  * Deskripsi: `company_description` (default: "Cleaning Activity Monitor").
  * Logo Perusahaan: Diunggah via `POST /api/v1/settings/logo` dan dapat diakses publik tanpa login via endpoint streaming `GET /api/v1/settings/logo/image` (mencegah broken image).
  * Teks Footer: `app_footer_text` di bagian bawah sidebar navigasi.
* **Parameter Batas Toleransi Sistem (Dapat diubah dinamis oleh Admin):**
  * `escalation_pic_timeout_minutes`: Batas toleransi PIC belum verifikasi (default: **120 menit**).
  * `buffer_shift_minutes`: Toleransi jam buka/tutup shift CS (default: **30 menit**).
  * `task_reminder_before_end_minutes`: Waktu kirim pengingat sebelum deadline (default: **60 menit**).
  * `default_geofence_radius_meter`: Radius default gedung (default: **250 meter**).

---

## 5. OTOMASI LATAR BELAKANG (CONSOLE COMMANDS & CRON SCHEDULER)

Backend CAMS dilengkapi 5 artisan console commands yang harus didaftarkan pada task scheduler server (cron job):

```bash
# Tabel Penjadwalan Cron Server (Crontab Linux / Windows Task Scheduler)
* * * * * cd /path/to/cams-backend && php artisan schedule:run >> /dev/null 2>&1
```

| Command Artisan | Frekuensi Eksekusi | Deskripsi & Dampak Operasional |
| :--- | :--- | :--- |
| `php artisan cams:generate-tasks` | Setiap hari pukul 00:01 WIB | Memindai seluruh `schedules` aktif dan `cs_assignments` tanggal terkait, lalu menginstansiasi baris tugas harian di tabel `tasks` secara otomatis. |
| `php artisan cams:check-overdue` | Setiap 60 menit | Mengecek tugas yang masih berstatus `pending` atau `in_progress` tetapi waktu sekarang melewati `due_datetime`. Mengubah status menjadi `overdue` dan mengirim notifikasi ke Supervisor. |
| `php artisan cams:send-reminders` | Setiap 15 menit | Mendeteksi tugas yang deadline-nya akan berakhir dalam 60 menit ke depan, lalu mengirimkan push notifikasi peringatan ke akun CS yang bertugas. |
| `php artisan cams:check-escalations`| Setiap 30 menit | Mengecek laporan CS (`submissions`) yang telah dikirim lebih dari 120 menit namun belum diverifikasi oleh PIC Ruangan. Otomatis menaikkan notifikasi eskalasi darurat ke seluruh Supervisor. |
| `php artisan cams:check-finding-deadline`| Setiap hari pukul 07:00 WIB | Memeriksa tiket temuan kerusakan fasilitas yang melewati batas waktu `deadline_perbaikan`. Mengirimkan alert daftar tiket tertunggak ke Supervisor dan PIC. |

---

## 6. SKEMA DATABASE & RELASI MODEL (DATA DICTIONARY)

Berikut adalah daftar lengkap 30 entitas model & tabel database CAMS:

```
[users] ─────────1:N─────────► [user_roles] ◄────────N:1───────── [roles]
   │                                                                 │
   ├───────1:N (PIC)───────► [rooms] ◄──────1:N────── [buildings]     │
   │                           │                           │         │
   │                           ├────1:N──► [room_assets]   │         │
   │                           │                           │         │
   │                           └────1:N──► [schedules] ◄───┘         │
   │                                           │                     │
   ├───────1:N (CS)────────► [cs_assignments] ◄┘                     │
   │                               │                                 │
   │                               ▼                                 │
   └───────1:N─────────────► [tasks]                                 │
                               │                                     │
                               ▼                                     │
                   [checklist_submissions]                           │
                      │               │                              │
                      ▼               ▼                              │
             [checklist_results]  [submission_materials]             │
                      │                                              │
                      ▼                                              │
               [verifications] ──1:N──► [verification_sla_ratings]   │
```

### Rincian Tabel Inti:
1. **`users`:** `id`, `username`, `email`, `full_name`, `phone`, `password`, `foto_profile`, `is_active`, `timestamps`, `softDeletes`.
2. **`roles`:** `id`, `name` (`admin`, `supervisor`, `pic`, `cs`, `ob`, `guest`), `description`.
3. **`user_roles`:** `id`, `user_id`, `role_id`.
4. **`buildings`:** `id`, `nama_gedung`, `kode_gedung`, `alamat`, `latitude`, `longitude`, `radius_meter`, `is_active`, `asset_audit_cycle_months`, `last_asset_audit_at`, `next_asset_audit_at`.
5. **`shifts`:** `id`, `kode_shift`, `nama_shift`, `jam_mulai`, `jam_selesai`, `is_overnight`, `is_active`.
6. **`building_shifts`:** `id`, `building_id`, `shift_id`.
7. **`rooms`:** `id` (UUID PK), `building_id`, `kode_ruangan`, `nama_ruangan`, `lantai`, `latitude`, `longitude`, `radius_meter`, `pic_user_id`, `qr_code_token`, `is_active`, `asset_audit_cycle_months`, `last_asset_audit_at`, `next_asset_audit_at`.
8. **`room_pic_histories`:** `id`, `room_id`, `previous_pic_id`, `new_pic_id`, `changed_by_user_id`, `changed_at`.
9. **`checklist_items`:** `id`, `nama_item`, `standar_kebersihan`, `is_active`.
10. **`checklist_templates`:** `id`, `nama_template`, `deskripsi`, `is_active`.
11. **`checklist_template_items`:** `id`, `template_id`, `checklist_item_id`, `frekuensi`, `sort_order`.
12. **`schedules`:** `id`, `room_id`, `shift_id`, `template_id`, `frekuensi`, `target_time`, `is_active`.
13. **`cs_assignments`:** `id`, `cs_user_id`, `schedule_id`, `start_date`, `end_date`, `is_active`.
14. **`tasks`:** `id`, `schedule_id`, `room_id`, `shift_id`, `cs_user_id`, `task_date`, `due_datetime`, `status` (`pending`, `in_progress`, `submitted`, `verified`, `rejected`, `overdue`).
15. **`checklist_submissions`:** `id` (UUID PK), `task_id`, `cs_user_id`, `submitted_at`, `status` (`submitted`, `approved`, `rejected`), `foto_before`, `foto_after_1`, `foto_after_2`, `foto_after_3`, `foto_after_4`, `latitude`, `longitude`, `catatan_cs`.
16. **`checklist_results`:** `id`, `submission_id`, `checklist_item_id`, `kondisi` (`bersih`, `kotor`, `perlu_perhatian`), `catatan`.
17. **`submission_materials`:** `id`, `submission_id`, `cleaning_material_id`, `jumlah_terpakai`.
18. **`verifications`:** `id`, `submission_id`, `verifier_user_id`, `verified_at`, `status` (`approved`, `rejected`), `catatan`, `foto_inspeksi`, `sla_rating` (1-5).
19. **`verification_sla_ratings`:** `id`, `verification_id`, `sla_parameter_id`, `rating`.
20. **`sla_parameters`:** `id`, `nama_parameter`, `deskripsi`, `bobot`, `is_active`.
21. **`findings`:** `id`, `room_id`, `finding_category_id`, `reported_by_user_id`, `assigned_to_user_id`, `assigned_to_external`, `deskripsi`, `prioritas` (`low`, `medium`, `high`, `critical`), `status` (`open`, `in_progress`, `resolved`, `closed`), `deadline_perbaikan`, `foto`, `foto_resolved`, `foto_ob_1`, `foto_ob_2`, `foto_ob_3`, `foto_ob_4`, `catatan_perbaikan`.
22. **`finding_categories`:** `id`, `nama_kategori`, `deskripsi`.
23. **`adhoc_tasks`:** `id`, `supervisor_user_id`, `assigned_to_user_id`, `nama_tugas`, `deskripsi`, `lokasi`, `tanggal_pelaksanaan`, `target_selesai`, `status` (`pending`, `in_progress`, `setup_submitted`, `cleanup_submitted`, `completed`, `cancelled`), `foto_persiapan`, `foto_bukti`, `foto_cleanup`, `catatan_verifikasi`.
24. **`room_assets`:** `id`, `room_id`, `kode_aset`, `nama_aset`, `kategori`, `jumlah`, `kondisi`, `is_active`.
25. **`room_asset_audits`:** `id`, `room_id`, `auditor_user_id`, `verified_by_user_id`, `tanggal_audit`, `status` (`draft`, `submitted`, `verified`), `total_aset_terdata`, `total_aset_sesuai`, `total_aset_selisih`, `catatan_audit`.
26. **`room_asset_audit_items`:** `id`, `audit_id`, `room_asset_id`, `jumlah_sistem`, `jumlah_fisik`, `selisih`, `kondisi`, `foto_aset`, `keterangan`.
27. **`cleaning_materials`:** `id`, `kode_material`, `nama_material`, `satuan`, `stok`, `is_active`.
28. **`notifications`:** `id`, `user_id`, `type`, `title`, `message`, `data` (JSON), `is_read`, `created_at`.
29. **`audit_logs`:** `id`, `user_id`, `action`, `model_type`, `model_id`, `old_values` (JSON), `new_values` (JSON), `ip_address`, `user_agent`, `created_at`.
30. **`system_settings`:** `id`, `key`, `value`, `type` (`string`, `integer`, `boolean`), `deskripsi`.

---

## 7. PANDUAN TEKNIS UNTUK TIM KOLABORASI

### 7.1 Pembagian Tugas Tim (2 Kolaborator)

#### 🧑‍💻 Kolaborator 1: Backend & Database Specialist
* **Tanggung Jawab Utama:**
  * Memastikan seluruh API endpoint di `routes/api.php` berjalan stabil dan mengembalikan respons JSON standar `{ success: true, message: "...", data: ... }`.
  * Mengelola skema migrasi database di `database/migrations/`. **Dilarang keras mengubah file migrasi lama yang telah ter-migrate di database rekan setim; buat migrasi baru untuk setiap perubahan kolom.**
  * Memelihara performa query Eloquent menggunakan *eager loading* (`with([...])`) untuk mencegah masalah *N+1 query*.
  * Menguji berkala 5 artisan console commands dan scheduler cron.

#### 🎨 Kolaborator 2: Frontend & UI/UX Specialist
* **Tanggung Jawab Utama:**
  * Memastikan komponen React di `src/components/` terintegrasi mulus dengan endpoint backend via `api.js`.
  * Memelihara logika penanganan antrean lokal di `OfflineQueueUtil.js` dan kompresor kamera `imageCompressor.js`.
  * Mengelola routing tab dan hak akses tampilan berdasarkan peran pengguna di `App.jsx`.
  * Memastikan tampilan responsif di layar ponsel staf CS (orientasi portrait) dan layar desktop supervisor/admin.

---

### 7.2 Standar Branching & Commit Message
1. **Konvensi Branch Git:**
   * `main`: Branch produksi yang selalu bersih, teruji, dan siap dideploy.
   * `dev`: Branch integrasi utama seluruh fitur tim.
   * `feat/nama-fitur`: Branch pengerjaan fitur baru (contoh: `feat/sla-rating-ui`, `feat/export-pdf-enhancement`).
   * `fix/nama-bug`: Branch perbaikan bug (contoh: `fix/geofence-haversine-buffer`, `fix/qr-scanner-camera-flip`).
2. **Format Commit (Conventional Commits):**
   * `feat: tambah filter tanggal pada laporan export pdf`
   * `fix: perbaiki validasi overnight shift pada shift S3`
   * `refactor: optimasi query dashboard supervisor dengan eager loading`
   * `style: perbaiki kontras badge status pada mobile view`

---

### 7.3 Akun Pengujian & Kredensial Uji Coba
Seluruh akun hasil seeder awal memiliki password standar: **`password`** (kecuali admin utama).

| Peran | Username | Email | Kegunaan Pengujian |
| :--- | :--- | :--- | :--- |
| **System Admin** | `admin` | `admin@cams.com` | Pengaturan branding, kelola user & reset password (`AdminPass123!`) |
| **Supervisor** | `supervisor` | `supervisor@cams.com` | Approval laporan, kelola jadwal, pantau kepatuhan pabrik |
| **PIC Ruangan (GPA)** | `pic_ferry` | `ferry@cams.com` | Verifikasi on-site ruangan Gedung Produksi A |
| **PIC Ruangan (GPB)** | `pic_anto` | `anto@cams.com` | Verifikasi on-site ruangan Gedung Produksi B |
| **Staf CS 1** | `cs_budi` | `budi@cams.com` | Eksekusi checklist kebersihan Shift 1 (Pagi) |
| **Staf CS 2** | `cs_ani` | `ani@cams.com` | Eksekusi checklist kebersihan Shift 2 (Siang) |
| **Pelapor Fasilitas**| `pelapor_fasilitas`| `pelapor@cams.com` | Simulasi laporan kerusakan fasilitas umum |

---

### 7.4 Prosedur Uji Mutasi Data & Zero-Regression Checklist
Sebelum melakukan `git push` atau meminta rekan setim melakukan *pull*, pastikan telah memvalidasi checklist berikut:
* [ ] Jalankan `php artisan migrate:status` di backend untuk memastikan tidak ada migrasi yang *pending*.
* [ ] Jalankan `npm run build` di frontend untuk memverifikasi tidak ada error sintaks JSX / import modul.
* [ ] Uji skenario login dengan akun CS `cs_budi` -> Buka halaman Tugas Hari Ini -> Pastikan tidak ada pesan error shift jika jam kerja cocok.
* [ ] Uji alur pembuatan temuan kerusakan -> Periksa apakah notifikasi masuk ke kotak masuk Supervisor.
* [ ] Pastikan tidak ada credential atau file `.env` yang ter-commit ke repositori publik.

---
**Dokumen ini adalah acuan resmi arsitektur sistem CAMS.** Seluruh perubahan fitur, penambahan endpoint, dan skema database wajib didokumentasikan dan diselaraskan pada dokumen ini.
