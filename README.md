# Sistem Absensi Fingerprint dan Pengenalan Wajah

## Menjalankan aplikasi

1. Jalankan Apache dan MySQL pada XAMPP.
2. Jika database belum dibuat, import `Koneksi/Setup_Database.sql` melalui phpMyAdmin.
3. Jalankan `Koneksi/Migration_Profile_Roles.sql` melalui phpMyAdmin untuk membuat tabel profil terpisah dan menyalin data profil lama.
4. Jalankan `Koneksi/Migration_Admin_Dosen_Gender.sql` dan `Koneksi/Migration_Dosen_Profile_Enhancement.sql` untuk memastikan kolom jenis kelamin dan NIP pada profil Dosen tersedia.
5. Jalankan `Koneksi/Migration_Room_Geofencing.sql` untuk mengaktifkan koordinat GPS dan radius geofencing pada ruangan serta riwayat absensi.
6. Buka `http://localhost/PEMROGRAMAN%20CLIENT%20SERVER/SIABAK.html`.

## Peran akun

- **Mahasiswa:** memilih ruangan, melakukan verifikasi sidik jari dan wajah, lalu mencatat kehadiran.
- **Dosen:** membuka atau mengunci lima ruangan.
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

Dosen mengubah status ruangan melalui `Dasbor/Dosen.php`. Mahasiswa hanya dapat mencatat absensi ke ruangan yang terbuka melalui `Dasbor/Beranda.php`.

## Catatan biometrik

Versi ini menggunakan simulasi sidik jari dan kamera perangkat untuk pengenalan wajah sebagai prototipe presentasi. Verifikasi identitas produksi memerlukan sensor fingerprint, SDK wajah/liveness, dan penyimpanan template biometrik yang aman.

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

## Pencocokan wajah saat absensi

1. Jalankan `Koneksi/Migration_Face_Descriptor.sql` pada database `SIABAK`.
2. Mahasiswa membuka menu **Pendaftaran biometrik**, mengaktifkan kamera, mengambil foto, menyetujui penyimpanan, lalu menyimpan pendaftaran. Sistem membuat face descriptor 128 dimensi dari satu wajah yang terdeteksi.
3. Saat absensi, browser memuat model face-api.js dari jsDelivr, meminta mahasiswa menggerakkan kepala sedikit ke kiri dan kanan, lalu mengirim descriptor kamera ke `Koneksi/Face_Verify.php`.
4. Server membandingkan descriptor live dengan descriptor akun yang sedang login. Hanya jika cocok, server menerbitkan bukti sesi satu kali yang diperlukan oleh `Koneksi/Room.php` untuk mencatat absensi.

**Catatan penting:** koneksi internet diperlukan untuk memuat pustaka dan model face-api.js. Akses kamera memerlukan HTTPS atau localhost. Ambang kecocokan awal adalah 0,52 dan perlu dikalibrasi menggunakan kamera serta kondisi pencahayaan kampus. Gerakan kepala merupakan pemeriksaan keaktifan dasar, bukan liveness/anti-spoof yang kuat; foto atau video yang diputar masih mungkin mengelabui pemeriksaan sederhana. Jangan gunakan sebagai satu-satunya faktor keamanan untuk penerapan resmi tanpa pengujian dan penguatan anti-spoof. Sidik jari tetap simulasi sampai perangkat dan SDK sensor tersedia.
