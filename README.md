# Sistem Absensi Fingerprint dan Pengenalan Wajah

## Menjalankan aplikasi

1. Jalankan Apache dan MySQL pada XAMPP.
2. Jika database belum dibuat, import `Koneksi/Setup_Database.sql` melalui phpMyAdmin. Skrip ini membuat database `siabak`.
3. Jalankan `Koneksi/Migration_Profile_Roles.sql` melalui phpMyAdmin untuk membuat tabel profil terpisah dan menyalin data profil lama.
4. Jalankan `Koneksi/Migration_Admin_Dosen_Gender.sql` dan `Koneksi/Migration_Dosen_Profile_Enhancement.sql` untuk memastikan kolom jenis kelamin dan NIP pada profil Dosen tersedia.
5. Jalankan `Koneksi/Migration_Room_Geofencing.sql` untuk mengaktifkan koordinat GPS dan radius geofencing pada ruangan serta riwayat absensi.
6. Jalankan `Koneksi/Migration_Attendance_Schedule.sql` untuk menambahkan `ScheduleId` yang diperlukan saat absensi.
7. Jalankan `Koneksi/Migration_Face_Descriptor.sql` untuk menambahkan data model pendaftaran wajah.
8. Siapkan layanan wajah lokal dengan langkah di bagian **Verifikasi wajah lokal**.
9. Buka `http://localhost/PEMROGRAMAN%20CLIENT%20SERVER/SIABAK.html`.

## Peran akun

- **Mahasiswa:** memilih ruangan, melakukan verifikasi sidik jari dan wajah, lalu mencatat kehadiran.
- **Dosen:** membuka atau mengunci ruangan yang terdaftar.
- **Admin:** memantau persentase data akun, membaca dan mengubah data Mahasiswa dan Dosen, serta melihat status ruangan dan riwayat absensi.

## Menetapkan role

```sql
UPDATE login SET Role = 'mahasiswa' WHERE Username = 'nama_mahasiswa';
UPDATE login SET Role = 'dosen' WHERE Username = 'nama_dosen';
UPDATE login SET Role = 'admin' WHERE Username = 'nama_admin';
```

Role lama `user` akan dikonversi menjadi `mahasiswa` saat database di-import ulang.

Pendaftaran publik hanya tersedia untuk **Mahasiswa**. Akun Dosen dan Admin dibuat atau ditetapkan oleh pengelola melalui database; NIK, NIM, tanggal lahir, dan foto digunakan sebagai data identitas profil Mahasiswa.

## Pemisahan profil

Data login dan peran akun disimpan di tabel `login`. Data profil disimpan terpisah berdasarkan peran:

- `mahasiswa_profiles`: identitas dan data pendaftaran Mahasiswa.
- `dosen_profiles`: nama dan email Dosen.

Jalankan `Koneksi/Migration_Profile_Roles.sql` satu kali untuk menyalin profil lama dari `account_profiles` ke tabel yang sesuai; data dan tabel lama tidak dihapus. Pendaftaran baru hanya menyimpan profil ke `mahasiswa_profiles`. Untuk menambahkan Dosen, buat akun dengan `Role = 'dosen'` di tabel `login`, lalu tambahkan profil dengan `Username` yang sama di `dosen_profiles`. Nama dan email Dosen dapat diisi saat datanya tersedia.

## Ruangan

- RK 3.16
- Lab Komputer 1
- Lab Komputer 2
- Lab Unprotek
- Lab Teknik Kendali
- RK 2.11
- Lab Kendali
- UKS Lama
- Lab Unit Produksi
- Lab Elektronika

Dosen mengubah status ruangan melalui `Dasbor/Dosen.php`. Mahasiswa hanya dapat mencatat absensi ke ruangan yang terbuka melalui `Dasbor/Beranda.php`.

## Catatan biometrik

Sidik jari masih berupa simulasi karena perangkat sensor/SDK belum tersedia. Verifikasi wajah memakai layanan Python lokal, pemrosesan gambar dan template di komputer sendiri, serta model anti-spoof probabilistik. Data wajah tidak dikirim ke layanan pihak ketiga.

## Dashboard admin

- **Persentase:** jenis kelamin (Mahasiswa, Dosen, dan gabungan), kelompok usia Mahasiswa, provinsi asal Mahasiswa, pendaftaran wajah, dan status ruangan.
- **Data akun:** tabel Mahasiswa dan Dosen dengan pencarian. Tombol **Ubah** membuka form untuk mengedit data. Username tidak dapat diubah karena menjadi kunci data absensi.
- Mahasiswa dapat diubah: nama, email, jenis kelamin, NIM, NIK, tanggal lahir, dan alamat. Dosen dapat diubah: nama, email, dan jenis kelamin. Foto dan kata sandi belum dapat diubah dari dashboard.
- Perubahan ditangani oleh `Koneksi/Admin_Users.php` (khusus admin, dengan token CSRF dan validasi email/NIK/NIM unik).

## Dashboard profil dosen

- Dosen dapat mengelola dan memperbarui data profil pribadinya secara mandiri melalui menu **Profil** (`Dasbor/Dosen_Profil.php`).
- Data yang dapat diubah: Nama lengkap dan gelar, email, jenis kelamin, serta NIP (Nomor Induk Pegawai).
- Perubahan diproses secara aman oleh `Koneksi/Update_Dosen_Profile.php` dengan token CSRF dan validasi email unik.

## Validasi lokasi (Geofencing GPS)

- Mahasiswa wajib berada di dalam radius geofencing ruangan kelas (default: 50–100 meter) sebelum dapat melakukan absensi.
- Jarak dihitung secara akurat menggunakan formula *Haversine* baik pada sisi client (real-time feedback) maupun pada sisi server (`Koneksi/Room.php`).
- Koordinat dan jarak saat absensi dicatat ke tabel `class_attendance` untuk keperluan audit kehadiran.
- Terdapat *Mode Uji Coba (Simulasi)* pada antarmuka absensi untuk memudahkan presentasi atau pengujian pada komputer desktop tanpa sensor GPS.

## Mode offline & notifikasi

- **Deteksi Koneksi:** Sistem secara otomatis mendeteksi status jaringan (online/offline) dan menampilkan banner status interaktif.
- **Cache Perangkat:** Data ruangan dan jadwal kuliah harian disimpan di `localStorage` perangkat sehingga tetap dapat diakses saat koneksi terputus di dalam ruangan kelas/lab.
- **Pengingat Notifikasi:** Mahasiswa dapat mengaktifkan notifikasi browser (*Web Notification API*) untuk pengingat jadwal kelas dan status ruangan.


## Fitur rekap dan pengelolaan kehadiran per mata kuliah

1. Impor/jalankan `Koneksi/Migration_Course_Attendance.sql` pada database `SIABAK` setelah struktur utama `Setup_Database.sql` tersedia.
2. Login sebagai mahasiswa untuk melihat rekap persentase kehadiran per mata kuliah di `Dasbor/Beranda.php`.
3. Login sebagai dosen, buka menu **Kelola Kehadiran**, pilih mata kuliah dan tanggal, lalu tetapkan status mahasiswa dan simpan.
4. Persentase dihitung dari status `Hadir` dibagi seluruh catatan pertemuan yang telah disimpan pada mata kuliah tersebut. Status `Izin`, `Sakit`, dan `Alpa` masuk sebagai tidak hadir.

Catatan: database versi awal belum menghubungkan data absensi biometrik `class_attendance` dengan ID mata kuliah. Karena itu, rekap fitur baru memakai tabel `course_attendance` yang diisi melalui menu dosen; data absensi biometrik lama tidak otomatis masuk ke persentase. Database saat ini juga belum memiliki relasi dosen-pengampu-mata-kuliah, sehingga seluruh akun dosen dapat melihat dan mengelola daftar mata kuliah yang tersedia.

## Fitur rekap dan penugasan dosen

1. Jalankan `Koneksi/Migration_Course_Attendance.sql` untuk membuat tabel rekap kehadiran per mata kuliah (jika belum dijalankan).
2. Jalankan `Koneksi/Migration_Dosen_Course_Assignments.sql` untuk membuat relasi dosen dengan jadwal/mata kuliah.
3. Admin perlu mengisi `dosen_course_assignments` sesuai dosen pengampu. Contoh:

   ```sql
   INSERT INTO dosen_course_assignments (DosenUsername, ScheduleId)
   VALUES ('username_dosen', 1);
   ```

   `ScheduleId` dapat dilihat dari tabel `class_schedules`. Satu dosen dapat memiliki beberapa jadwal/mata kuliah. Halaman dosen hanya menampilkan dan menerima perubahan untuk jadwal yang ditugaskan kepadanya.

4. Dashboard mahasiswa menampilkan persentase kehadiran tiap mata kuliah. Dashboard dosen menampilkan ringkasan persentase kelas dan persentase per mahasiswa. Sistem memberi peringatan bila persentase di bawah 75%.

## Catatan verifikasi biometrik pada tahap pengembangan

Alur absensi saat ini mewajibkan urutan geofencing, langkah sidik jari, lalu kamera wajah. Nilai `FingerprintVerified` dan `FaceVerified` dicatat pada tabel `class_attendance`. Namun, tombol sidik jari dan langkah kamera masih berupa simulasi/prototipe; kamera hanya diaktifkan dan belum melakukan pencocokan identitas wajah. Integrasi perangkat/SDK sidik jari dan mesin pengenalan wajah sungguhan masih perlu dikerjakan sebelum digunakan sebagai verifikasi produksi.

## Verifikasi wajah lokal

1. Pastikan migrasi `Koneksi/Migration_Face_Descriptor.sql` telah dijalankan pada database `siabak`.
2. Pastikan Python 3.13 tersedia di `PATH`. Pada komputer yang menjalankan XAMPP, buka PowerShell di folder proyek lalu jalankan `.\FaceService\install.ps1`. Ini membuat virtual environment Python dan memasang FastAPI, Uvicorn, serta DeepFace.
3. Buat tugas **on-demand** satu kali di Task Scheduler. Gunakan nama persis `SIABAK Face Verification On Demand`, pilih **Run only when user is logged on**, jangan tambahkan pemicu, lalu tambahkan aksi:

   - Program: `C:\xampp\htdocs\PEMROGRAMAN CLIENT SERVER\FaceService\.venv\Scripts\python.exe`
   - Arguments: `"C:\xampp\htdocs\PEMROGRAMAN CLIENT SERVER\FaceService\run_service.py"`
   - Start in: `C:\xampp\htdocs\PEMROGRAMAN CLIENT SERVER\FaceService`

   Pastikan tugas diizinkan berjalan sesuai permintaan (*on demand*).
4. Saat mahasiswa memulai pendaftaran atau verifikasi wajah, PHP memulai tugas tersebut dan menunggu layanan lokal siap. Setelah satu permintaan pendaftaran/verifikasi selesai, layanan berhenti otomatis; jika proses ditinggalkan, layanan berhenti setelah lima menit tidak aktif.
5. Layanan hanya mendengarkan di `127.0.0.1:8765`; token akses dibuat lokal dan tidak dimasukkan ke Git. Saat pertama kali dijalankan, layanan mengunduh bobot model SFace dan anti-spoof dari sumber model. Unduhan ini adalah model perangkat lunak; gambar kamera dan template biometrik tetap diproses di komputer lokal.
6. Mahasiswa membuka **Pendaftaran biometrik**, mengaktifkan kamera, mengikuti arah gerakan acak, menyetujui penyimpanan, lalu menyimpan pendaftaran. Seluruh mahasiswa perlu mendaftar ulang karena format model lama tidak kompatibel.
7. Saat absensi, browser mengirim beberapa bingkai kamera ke PHP; PHP meneruskannya ke layanan lokal untuk pemeriksaan liveness dan pencocokan. Browser tidak lagi menentukan atau mengirim template wajah.
8. Akses kamera memerlukan HTTPS atau `localhost`. Untuk memakai kamera ponsel saat XAMPP berjalan di komputer lain, situs XAMPP perlu dibuka melalui HTTPS yang dipercaya; layanan Python tetap berjalan di komputer XAMPP.

**Batasan keamanan:** deteksi anti-spoof berbasis model bersifat probabilistik, bukan bukti manusia hidup yang mutlak. Kinerja perlu diuji dengan kamera, pencahayaan, dan kondisi penggunaan sebenarnya; jangan menjadikan hasil ini satu-satunya faktor untuk keputusan berisiko tinggi. Sidik jari masih simulasi sampai perangkat dan SDK tersedia.

**Jika web dibungkus menjadi aplikasi:** paket aplikasi nantinya perlu mengatur proses layanan lokalnya sendiri (atau memakai backend tersendiri); jangan mengasumsikan layanan Python ini akan otomatis ikut berjalan pada ponsel atau komputer pengguna lain.
