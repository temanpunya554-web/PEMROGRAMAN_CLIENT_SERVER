# Sistem Absensi Fingerprint dan Pengenalan Wajah

## Menjalankan aplikasi

1. Jalankan Apache dan MySQL pada XAMPP.
2. Jika database belum dibuat, import `Koneksi/Setup_Database.sql` melalui phpMyAdmin.
3. Jalankan `Koneksi/Migration_Profile_Roles.sql` melalui phpMyAdmin untuk membuat tabel profil terpisah dan menyalin data profil lama.
4. Jalankan `Koneksi/Migration_Admin_Dosen_Gender.sql` dan `Koneksi/Migration_Dosen_Profile_Enhancement.sql` untuk memastikan kolom jenis kelamin dan NIP pada profil Dosen tersedia.
5. Jalankan `Koneksi/Migration_Room_Geofencing.sql` untuk mengaktifkan koordinat GPS dan radius geofencing pada ruangan serta riwayat absensi.
6. Jalankan `Koneksi/Migration_Student_Authenticators.sql` untuk menyiapkan penyimpanan kredensial WebAuthn.
7. Buka `http://localhost/PEMROGRAMAN%20CLIENT%20SERVER/SIABAK.html`.

## Konfigurasi database

Atur variabel lingkungan berikut pada runtime PHP:

- `DB_HOST`
- `DB_USER`
- `DB_PASSWORD` (boleh kosong jika server database memang tidak memakai password)
- `DB_NAME`
- `DB_PORT` (opsional, default `3306`)

Nama `SIABAK_DB_HOST`, `SIABAK_DB_USERNAME`, `SIABAK_DB_PASSWORD`, `SIABAK_DB_NAME`, dan `SIABAK_DB_PORT` juga didukung sebagai alias. Pada Railway, masukkan variabel tersebut di bagian **Variables** pada layanan aplikasi dan restart/redeploy layanan. Untuk XAMPP, atur variabel lingkungan Windows sebelum menjalankan Apache, lalu restart Apache. Jangan simpan nilai rahasia di source code atau commit ke Git.

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

Pemeriksaan sidik jari menggunakan WebAuthn/passkey melalui autentikator platform perangkat, seperti sidik jari Android. Mahasiswa harus membuka **Pendaftaran biometrik** dari perangkat dan browser yang akan digunakan, lalu memilih **Daftarkan perangkat ini**. Saat absensi, server memvalidasi tanda tangan kriptografis perangkat; data sidik jari tidak dikirim atau disimpan oleh SIABAK. Browser/perangkat dapat meminta PIN atau metode kunci layar sebagai alternatif sesuai pengaturan Android.

WebAuthn hanya berjalan pada konteks aman: gunakan HTTPS untuk akses melalui jaringan atau domain, dan `localhost` untuk pengembangan pada perangkat yang sama. Akses ke alamat IP XAMPP melalui HTTP dari ponsel tidak memenuhi persyaratan keamanan browser. Server mengambil origin HTTPS dari header permintaan browser dan mencocokkannya dengan host aplikasi. Jika reverse proxy mengganti host internal atau domain berbeda, atur `SIABAK_WEBAUTHN_ORIGIN` ke origin publik yang persis (contoh `https://absensi.example.id`) dan `SIABAK_WEBAUTHN_RP_ID` ke nama domain RP (contoh `absensi.example.id`). Tidak ada mode simulasi untuk verifikasi biometrik.

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

## Endpoint penugasan dosen dan tabel permohonan izin

- `Koneksi/Save_Teacher_Course_Assignment.php` menerima POST dari admin dengan token CSRF, `dosen_username`, dan `schedule_ids[]`. Kirim `clear_assignments=1` tanpa daftar jadwal untuk menghapus seluruh jadwal dosen tersebut. Endpoint ini belum dihubungkan ke form dashboard admin; penugasan melalui UI masih dilakukan dengan mengisi `dosen_course_assignments`.
- Jalankan `Koneksi/Migration_Student_Leave_Requests.sql` setelah `Setup_Database.sql` dan `Migration_Attendance_Schedule.sql` untuk menyiapkan penyimpanan permohonan Izin/Sakit per mahasiswa, jadwal, dan tanggal. Form dan endpoint pengajuan belum tersedia.
- `Koneksi/Record_Attendance.php` adalah helper untuk jadwal aktif tertentu. Alur absensi yang berjalan tetap memakai validasi biometrik/lokasi dan pencatatan di `Koneksi/Room.php`; helper ini hanya boleh dipanggil setelah bukti tersebut tervalidasi.
- Mode absensi normal hanya menampilkan jadwal pada hari yang sesuai, mulai 15 menit sebelum kelas hingga kelas selesai. Untuk uji radius di luar waktu jadwal, `ATTENDANCE_TEST_MODE` pada `Koneksi/Room.php` dapat diubah sementara ke `true`; mode ini tetap memerlukan ruangan terbuka, GPS aktual di dalam radius, dan verifikasi biometrik.

## Catatan verifikasi biometrik pada tahap pengembangan

Alur absensi mewajibkan urutan geofencing, verifikasi WebAuthn perangkat, lalu kamera wajah. Nilai `FingerprintVerified` dan `FaceVerified` dicatat pada tabel `class_attendance`. Verifikasi wajah membandingkan descriptor akun, tetapi tantangan kedipan/gerakan kepala masih pemeriksaan keaktifan dasar di browser dan belum merupakan anti-spoof tingkat produksi.

## Pencocokan wajah saat absensi

1. Jalankan `Koneksi/Migration_Face_Descriptor.sql` pada database `SIABAK`.
2. Mahasiswa membuka menu **Pendaftaran biometrik**, mengaktifkan kamera, mengambil foto, menyetujui penyimpanan, lalu menyimpan pendaftaran. Sistem membuat face descriptor 128 dimensi dari satu wajah yang terdeteksi.
3. Saat absensi, browser memuat model face-api.js dari jsDelivr, meminta mahasiswa menggerakkan kepala sedikit ke kiri dan kanan, lalu mengirim descriptor kamera ke `Koneksi/Face_Verify.php`.
4. Server membandingkan descriptor live dengan descriptor akun yang sedang login. Hanya jika cocok, server menerbitkan bukti sesi satu kali yang diperlukan oleh `Koneksi/Room.php` untuk mencatat absensi.
5. Selama pemeriksaan, titik-titik landmark wajah ditampilkan di atas pratinjau kamera. Setelah mahasiswa menekan **Catat absensi** dan server berhasil menyimpan kehadiran, halaman menampilkan layar konfirmasi dengan tombol **Kembali ke halaman utama**.

**Catatan penting:** koneksi internet diperlukan untuk memuat pustaka dan model face-api.js. Akses kamera dan WebAuthn memerlukan HTTPS atau localhost. Ambang kecocokan awal adalah 0,52 dan perlu dikalibrasi menggunakan kamera serta kondisi pencahayaan kampus. Gerakan kepala merupakan pemeriksaan keaktifan dasar, bukan liveness/anti-spoof yang kuat; foto atau video yang diputar masih mungkin mengelabui pemeriksaan sederhana. Jangan gunakan sebagai satu-satunya faktor keamanan untuk penerapan resmi tanpa pengujian dan penguatan anti-spoof.

## Fitur layanan chat bantuan admin

1. Jalankan `Koneksi/Migration_Admin_Chat.sql` pada database untuk membuat tabel `admin_chats` (tabel juga otomatis dibuat saat pertama kali endpoint chat diakses).
2. Mahasiswa dan Dosen memiliki tombol mengambang (*floating action button*) **💬 Chat Admin** di sudut kanan bawah seluruh halaman dashboard mereka.
3. Pesan yang dikirim pengguna masuk ke antrean admin secara instan dengan indikator status operator dan riwayat chat interaktif.
4. Admin (Operator) dapat mengelola seluruh percakapan masuk di dashboard **Admin > Menu Pesan & Bantuan** (`#pesan-bantuan`), melihat daftar pengguna, membalas pesan, dan melihat jumlah notifikasi pesan yang belum terbaca secara live.

## [TAMBAHAN CLAUDE] Penugasan dosen (UI admin) dan pengajuan izin/sakit

- **Admin > Penugasan Dosen** (`Dasbor/Admin.php`, menu `#penugasan-dosen`): pilih dosen, centang jadwal yang diampu, lalu simpan. Form memakai endpoint `Koneksi/Save_Teacher_Course_Assignment.php`; menyimpan menggantikan seluruh penugasan lama dosen tersebut. Perlu `Koneksi/Migration_Teacher_Course_Assignment.sql`.
- **Mahasiswa > Izin / Sakit** (`Dasbor/Izin.php`): ajukan Izin atau Sakit untuk satu jadwal dan tanggal (hari harus sesuai jadwal, rentang 7 hari lalu s/d 30 hari ke depan). Pengajuan yang masih Menunggu bisa diubah atau dibatalkan. Perlu `Koneksi/Migration_Student_Leave_Requests.sql`. Lampiran file belum didukung.
- **Dosen > Izin / Sakit** (`Dasbor/Dosen_Izin.php`): dosen menyetujui atau menolak pengajuan untuk jadwal yang ditugaskan kepadanya. Jika disetujui, status Izin/Sakit dicatat ke `course_attendance`; status `Hadir` yang sudah ada tidak ditimpa.
