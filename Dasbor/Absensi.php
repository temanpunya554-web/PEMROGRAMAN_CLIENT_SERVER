<?php
require_once __DIR__ . "/Student_Auth.php";
require_once __DIR__ . "/../Koneksi/Face_Service_Client.php";
$activeStudentPage = "attendance";
require __DIR__ . "/Student_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">ABSENSI MAHASISWA</p>
    <h1>Verifikasi masuk ruangan</h1>
    <p>Pilih ruangan, pastikan mata kuliah yang sedang berlangsung sudah benar, lalu ikuti verifikasi lokasi (geofencing) dan biometrik untuk mencatat kehadiran.</p>
</section>

<section class="student-content-panel">
    <div id="network-status-banner" class="network-status-banner" hidden></div>
    <p id="verification-status" class="community-data-status" role="status">Memuat status ruangan...</p>

    <div class="verification-layout">
        <div class="verification-controls">
            <label for="room-select">Pilih ruangan</label>
            <select id="room-select">
                <option value="">Memuat ruangan...</option>
            </select>

            <label for="schedule-select">Mata kuliah yang sedang berlangsung</label>
            <select id="schedule-select" disabled>
                <option value="">Pilih ruangan terlebih dahulu</option>
            </select>
            <p id="schedule-hint" class="form-help" role="status"></p>

            <!-- TAHAP 1: GEOFENCING / GPS -->
            <button type="button" id="gps-button" class="room-action lock-action" disabled>
                1. Periksa lokasi GPS (Radius 50–100m)
            </button>

            <div id="gps-info-box" class="gps-info-box" hidden>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <strong>Status Lokasi Geofence:</strong>
                    <span id="gps-badge" class="gps-badge gps-badge-pending">Belum diperiksa</span>
                </div>
                <div id="gps-details">Menunggu penentuan titik koordinat...</div>
                <label class="gps-simulation-toggle" title="Gunakan mode uji coba jika menguji di PC/laptop tanpa sensor GPS">
                    <input type="checkbox" id="gps-simulate-checkbox" />
                    <span>Mode Uji Coba: Simulasikan berada di dalam ruangan kelas</span>
                </label>
            </div>

            <!-- TAHAP 2: FINGERPRINT -->
            <button type="button" id="fingerprint-button" class="room-action lock-action" disabled>
                2. Verifikasi sidik jari (simulasi)
            </button>

            <!-- TAHAP 3: KAMERA WAJAH -->
            <button type="button" id="face-button" class="room-action open-action" disabled>
                3. Cocokkan wajah terdaftar
            </button>

            <!-- TAHAP 4: CATAT ABSENSI -->
            <button type="button" id="enter-room-button" class="home-button" disabled>
                Catat absensi
            </button>
        </div>

        <div class="face-preview">
            <video id="face-video" autoplay muted playsinline></video>
            <span id="camera-status">Kamera belum aktif</span>
        </div>
    </div>

    <p class="community-privacy-note">
        <strong>Ketentuan Absensi:</strong> Mahasiswa harus berada di dalam radius geofencing ruangan (50–100 meter). Kamera mengirim bingkai ke layanan wajah lokal di komputer XAMPP untuk pemeriksaan anti-spoof dan pencocokan dengan akun terdaftar. Pemeriksaan liveness tetap probabilistik dan tidak menjamin penolakan semua bentuk spoofing. Sidik jari masih simulasi karena perangkat belum tersedia.
    </p>
</section>

<script>
    const roomCsrfToken = <?= json_encode($studentCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const roomSelect = document.getElementById("room-select");
    const scheduleSelect = document.getElementById("schedule-select");
    const scheduleHint = document.getElementById("schedule-hint");
    const verificationStatus = document.getElementById("verification-status");
    const gpsButton = document.getElementById("gps-button");
    const gpsInfoBox = document.getElementById("gps-info-box");
    const gpsBadge = document.getElementById("gps-badge");
    const gpsDetails = document.getElementById("gps-details");
    const gpsSimulateCheckbox = document.getElementById("gps-simulate-checkbox");
    const fingerprintButton = document.getElementById("fingerprint-button");
    const faceButton = document.getElementById("face-button");
    const enterRoomButton = document.getElementById("enter-room-button");
    const faceVideo = document.getElementById("face-video");
    const cameraStatus = document.getElementById("camera-status");
    const networkBanner = document.getElementById("network-status-banner");

    let roomsData = [];
    let activeSchedules = [];
    let openMinutesBefore = 15;
    let currentRoom = null;
    let gpsVerified = false;
    let userCoords = { latitude: null, longitude: null };
    let fingerprintVerified = false;
    let faceVerified = false;
    let cameraStream = null;
    let faceProof = null;

    function delay(milliseconds) {
        return new Promise((resolve) => setTimeout(resolve, milliseconds));
    }

    async function requestLivenessChallenge() {
        const form = new FormData();
        form.set("csrf_token", roomCsrfToken);
        form.set("purpose", "attendance");
        const response = await fetch("../Koneksi/Face_Challenge.php", {
            method: "POST",
            body: form,
            headers: { Accept: "application/json" }
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || "Tantangan kamera gagal disiapkan.");
        return result;
    }

    async function captureFaceFrame() {
        if (faceVideo.videoWidth < 320 || faceVideo.videoHeight < 320) {
            throw new Error("Tunggu sampai pratinjau kamera siap, lalu coba lagi.");
        }
        const scale = Math.min(1, 640 / faceVideo.videoWidth);
        const canvas = document.createElement("canvas");
        canvas.width = Math.round(faceVideo.videoWidth * scale);
        canvas.height = Math.round(faceVideo.videoHeight * scale);
        const context = canvas.getContext("2d");
        if (!context) throw new Error("Bingkai kamera tidak dapat diproses oleh browser ini.");
        context.drawImage(faceVideo, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.75));
        if (!blob) throw new Error("Bingkai kamera tidak dapat diproses. Silakan coba lagi.");
        const dataUrl = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(new Error("Bingkai kamera tidak dapat dibaca."));
            reader.readAsDataURL(blob);
        });
        return String(dataUrl).split(",")[1];
    }

    // --- FITUR UX: DETEKSI KONEKSI ONLINE/OFFLINE ---
    function updateNetworkStatus() {
        if (!navigator.onLine) {
            networkBanner.textContent = "⚠️ Koneksi offline. Menampilkan data tersimpan dari cache perangkat.";
            networkBanner.className = "network-status-banner offline";
            networkBanner.hidden = false;
        } else {
            networkBanner.textContent = "✅ Koneksi internet kembali online.";
            networkBanner.className = "network-status-banner online";
            networkBanner.hidden = false;
            setTimeout(() => { networkBanner.hidden = true; }, 3500);
        }
    }
    window.addEventListener("online", updateNetworkStatus);
    window.addEventListener("offline", updateNetworkStatus);
    if (!navigator.onLine) updateNetworkStatus();

    // --- FORMULA HAVERSINE (Hitung Jarak GPS di Client) ---
    function calculateHaversineDistance(lat1, lon1, lat2, lon2) {
        const R = 6371000; // Radius bumi dalam meter
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return Math.round(R * c);
    }

    function stopCamera() {
        if (cameraStream) {
            cameraStream.getTracks().forEach((track) => track.stop());
            cameraStream = null;
        }
        faceVideo.srcObject = null;
        cameraStatus.textContent = "Kamera belum aktif";
    }

    function resetVerification(message) {
        gpsVerified = false;
        fingerprintVerified = false;
        faceVerified = false;
        faceProof = null;
        userCoords = { latitude: null, longitude: null };

        gpsButton.disabled = !(roomSelect.value && scheduleSelect.value);
        gpsInfoBox.hidden = true;
        gpsBadge.textContent = "Belum diperiksa";
        gpsBadge.className = "gps-badge gps-badge-pending";

        fingerprintButton.disabled = true;
        faceButton.disabled = true;
        enterRoomButton.disabled = true;
        stopCamera();
        verificationStatus.textContent = message;
    }

    async function loadRoomsForStudent() {
        try {
            const response = await fetch("../Koneksi/Room.php", { headers: { Accept: "application/json" } });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message);
            roomsData = result.rooms || [];

            // Simpan cache lokal untuk mode offline
            try { localStorage.setItem("siabak_rooms_cache", JSON.stringify(roomsData)); } catch (e) {}

            populateRoomsSelect(roomsData);
            verificationStatus.textContent = "Pilih ruangan, lalu periksa lokasi GPS untuk memulai absensi.";
        } catch (error) {
            // Coba ambil dari cache lokal jika offline
            const cached = localStorage.getItem("siabak_rooms_cache");
            if (cached) {
                try {
                    roomsData = JSON.parse(cached);
                    populateRoomsSelect(roomsData);
                    verificationStatus.textContent = "Memuat daftar ruangan dari penyimpanan cache lokal (Offline).";
                    return;
                } catch (e) {}
            }
            verificationStatus.textContent = error.message || "Daftar ruangan tidak dapat dimuat.";
        }
    }

    function formatClock(timeValue) {
        return timeValue ? String(timeValue).slice(0, 5) : "--:--";
    }

    async function loadActiveSchedules() {
        try {
            const response = await fetch("../Koneksi/Room.php?view=schedules", { headers: { Accept: "application/json" }, cache: "no-store" });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message);
            activeSchedules = result.schedules || [];
            openMinutesBefore = result.open_minutes_before || openMinutesBefore;
        } catch (error) {
            activeSchedules = [];
        }
        // Jangan mengubah pilihan saat mahasiswa sudah di tengah verifikasi.
        if (!gpsVerified) renderScheduleOptions();
    }

    function renderScheduleOptions() {
        const previous = scheduleSelect.value;
        const roomName = roomSelect.value;
        scheduleSelect.replaceChildren();
        scheduleHint.textContent = "";

        if (!roomName) {
            scheduleSelect.add(new Option("Pilih ruangan terlebih dahulu", ""));
            scheduleSelect.disabled = true;
            return;
        }

        const matches = activeSchedules.filter(item => String(item.RoomName).trim().toLowerCase() === roomName.trim().toLowerCase());
        if (matches.length === 0) {
            scheduleSelect.add(new Option("Tidak ada kelas berlangsung", ""));
            scheduleSelect.disabled = true;
            scheduleHint.textContent = `Tidak ada mata kuliah di ${roomName} saat ini. Absensi dibuka ${openMinutesBefore} menit sebelum kelas dimulai sampai kelas selesai.`;
        } else {
            if (matches.length > 1) scheduleSelect.add(new Option("Pilih mata kuliah", ""));
            matches.forEach(item => {
                scheduleSelect.add(new Option(`${item.CourseName} (${formatClock(item.StartTime)}-${formatClock(item.EndTime)})`, item.Id));
            });
            scheduleSelect.disabled = matches.length === 1;
            if (matches.some(item => String(item.Id) === previous)) {
                scheduleSelect.value = previous;
            } else if (matches.length === 1) {
                scheduleSelect.value = String(matches[0].Id);
            }
            scheduleHint.textContent = matches.length === 1
                ? "Mata kuliah dipilih otomatis sesuai jadwal ruangan ini."
                : "Ada lebih dari satu kelas di ruangan ini. Pilih mata kuliah Anda.";
        }
        gpsButton.disabled = !(roomSelect.value && scheduleSelect.value) || gpsVerified;
    }

    function populateRoomsSelect(rooms) {
        roomSelect.replaceChildren(new Option("Pilih ruangan", ""));
        rooms.forEach((room) => {
            const locked = room.RoomStatus === "locked";
            roomSelect.add(new Option(`${room.RoomName}${locked ? " - TERKUNCI" : " - TERBUKA"}`, room.RoomName));
        });
    }

    roomSelect.addEventListener("change", () => {
        const selectedName = roomSelect.value;
        currentRoom = roomsData.find(r => r.RoomName === selectedName) || null;
        resetVerification(selectedName
            ? "Pilih mata kuliah, lalu lakukan pemeriksaan lokasi GPS."
            : "Pilih ruangan terlebih dahulu.");
        renderScheduleOptions();
        if (selectedName && scheduleSelect.value) {
            verificationStatus.textContent = "Tahap 1: Lakukan pemeriksaan lokasi GPS untuk memastikan Anda berada di ruangan kelas.";
        } else if (selectedName) {
            verificationStatus.textContent = scheduleHint.textContent || "Pilih mata kuliah terlebih dahulu.";
        }
    });

    scheduleSelect.addEventListener("change", () => {
        resetVerification(scheduleSelect.value
            ? "Tahap 1: Lakukan pemeriksaan lokasi GPS untuk memastikan Anda berada di ruangan kelas."
            : "Pilih mata kuliah terlebih dahulu.");
    });

    // --- TAHAP 1: VALIDASI GEOFENCING / GPS ---
    gpsButton.addEventListener("click", () => {
        if (!currentRoom) {
            verificationStatus.textContent = "Pilih ruangan terlebih dahulu.";
            return;
        }

        gpsInfoBox.hidden = false;
        gpsBadge.textContent = "Mendeteksi...";
        gpsBadge.className = "gps-badge gps-badge-pending";
        gpsDetails.textContent = "Meminta koordinat GPS perangkat...";

        const targetLat = currentRoom.Latitude !== undefined && currentRoom.Latitude !== null ? parseFloat(currentRoom.Latitude) : -6.2000000;
        const targetLon = currentRoom.Longitude !== undefined && currentRoom.Longitude !== null ? parseFloat(currentRoom.Longitude) : 106.8166667;
        const maxRadius = currentRoom.RadiusMeters ? parseInt(currentRoom.RadiusMeters, 10) : 100;

        // Opsi simulasi / testing
        if (gpsSimulateCheckbox.checked) {
            userCoords.latitude = targetLat;
            userCoords.longitude = targetLon;
            gpsVerified = true;
            gpsBadge.textContent = "Di dalam radius (Simulasi) ✅";
            gpsBadge.className = "gps-badge gps-badge-in-range";
            gpsDetails.innerHTML = `Mode Uji Coba: Koordinat disesuaikan (${targetLat.toFixed(6)}, ${targetLon.toFixed(6)}). Jarak terukur: <strong>0 meter</strong> (Maks: ${maxRadius}m).`;
            fingerprintButton.disabled = false;
            verificationStatus.textContent = "Lokasi terverifikasi. Lanjutkan ke Tahap 2: Verifikasi Sidik Jari.";
            return;
        }

        if (!navigator.geolocation) {
            gpsBadge.textContent = "GPS Tidak Didukung ❌";
            gpsBadge.className = "gps-badge gps-badge-out-range";
            gpsDetails.textContent = "Perangkat browser Anda tidak mendukung Geolocation API.";
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) => {
                userCoords.latitude = position.coords.latitude;
                userCoords.longitude = position.coords.longitude;
                const distance = calculateHaversineDistance(userCoords.latitude, userCoords.longitude, targetLat, targetLon);

                if (distance <= maxRadius) {
                    gpsVerified = true;
                    gpsBadge.textContent = `Di dalam radius (${distance}m) ✅`;
                    gpsBadge.className = "gps-badge gps-badge-in-range";
                    gpsDetails.innerHTML = `Koordinat: ${userCoords.latitude.toFixed(6)}, ${userCoords.longitude.toFixed(6)}<br />Jarak ke ruangan: <strong>${distance} meter</strong> (Batas maksimal: ${maxRadius}m).`;
                    fingerprintButton.disabled = false;
                    verificationStatus.textContent = "Lokasi terverifikasi di area kampus. Lanjutkan ke Tahap 2: Verifikasi Sidik Jari.";
                } else {
                    gpsVerified = false;
                    gpsBadge.textContent = `Di luar radius (${distance}m) ❌`;
                    gpsBadge.className = "gps-badge gps-badge-out-range";
                    gpsDetails.innerHTML = `Koordinat Anda: ${userCoords.latitude.toFixed(6)}, ${userCoords.longitude.toFixed(6)}<br />Jarak Anda: <strong>${distance} meter</strong> (Melebihi batas maksimal ${maxRadius}m). Silakan berada di sekitar ruangan kelas.`;
                    fingerprintButton.disabled = true;
                    verificationStatus.textContent = "Akses absensi ditolak karena posisi Anda berada di luar radius ruangan.";
                }
            },
            (err) => {
                gpsVerified = false;
                gpsBadge.textContent = "Izin Ditolak / Gagal ❌";
                gpsBadge.className = "gps-badge gps-badge-out-range";
                gpsDetails.innerHTML = `Tidak dapat membaca GPS (${err.message}). Pastikan izin lokasi aktif atau centang Mode Uji Coba di bawah.`;
                fingerprintButton.disabled = true;
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    });

    gpsSimulateCheckbox.addEventListener("change", () => {
        if (!gpsButton.disabled) {
            gpsButton.click();
        }
    });

    // --- TAHAP 2: SIDIK JARI ---
    fingerprintButton.addEventListener("click", () => {
        if (!gpsVerified) {
            verificationStatus.textContent = "Verifikasi lokasi GPS Anda terlebih dahulu.";
            return;
        }
        fingerprintVerified = true;
        faceButton.disabled = false;
        verificationStatus.textContent = "Tahap sidik jari simulasi selesai. Lanjutkan ke Tahap 3: Aktifkan kamera.";
    });

    // --- TAHAP 3: PENCOCOKAN WAJAH DAN PEMERIKSAAN KEAKTIFAN ---
    faceButton.addEventListener("click", async () => {
        if (!fingerprintVerified) {
            verificationStatus.textContent = "Selesaikan verifikasi sidik jari terlebih dahulu.";
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            verificationStatus.textContent = "Kamera memerlukan browser yang mendukung HTTPS atau localhost.";
            return;
        }
        faceButton.disabled = true;
        faceVerified = false;
        faceProof = null;
        try {
            cameraStatus.textContent = "Menyiapkan layanan verifikasi lokal...";
            verificationStatus.textContent = "Menyalakan layanan lokal untuk pemeriksaan wajah...";
            const challengeResult = await requestLivenessChallenge();
            if (!cameraStream) {
                cameraStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: "user", width: { ideal: 640 }, height: { ideal: 480 } },
                    audio: false
                });
                faceVideo.srcObject = cameraStream;
                await new Promise((resolve, reject) => {
                    faceVideo.onloadedmetadata = resolve;
                    faceVideo.onerror = () => reject(new Error("Pratinjau kamera tidak dapat dimulai."));
                });
                await faceVideo.play();
            }
            cameraStatus.textContent = "Kamera aktif. Ikuti arah gerakan yang diminta.";
            const movementText = challengeResult.challenge === "left-right" ? "ke kiri, lalu ke kanan" : "ke kanan, lalu ke kiri";
            verificationStatus.textContent = "Lihat kamera dan gerakkan kepala perlahan " + movementText + ".";

            const firstFrames = [];
            for (let index = 0; index < 3; index += 1) {
                firstFrames.push(await captureFaceFrame());
                await delay(350);
            }
            await delay(1800);
            const secondFrames = [];
            for (let index = 0; index < 3; index += 1) {
                secondFrames.push(await captureFaceFrame());
                await delay(350);
            }
            stopCamera();

            const form = new FormData();
            form.set("csrf_token", roomCsrfToken);
            form.set("challenge", challengeResult.challenge);
            form.set("challenge_nonce", challengeResult.nonce);
            form.set("first_frames", JSON.stringify(firstFrames));
            form.set("second_frames", JSON.stringify(secondFrames));
            const verifyResponse = await fetch("../Koneksi/Face_Verify.php", { method: "POST", body: form, headers: { Accept: "application/json" } });
            const verifyResult = await verifyResponse.json();
            if (!verifyResponse.ok || !verifyResult.success) throw new Error(verifyResult.message || "Wajah tidak cocok.");
            faceProof = verifyResult.proof;
            faceVerified = true;
            enterRoomButton.disabled = false;
            cameraStatus.textContent = "Wajah cocok dengan akun terdaftar ✓";
            verificationStatus.textContent = verifyResult.message;
        } catch (error) {
            stopCamera();
            faceVerified = false;
            faceProof = null;
            cameraStatus.textContent = "Verifikasi wajah belum berhasil";
            verificationStatus.textContent = error.message || "Verifikasi wajah gagal. Silakan coba lagi.";
        } finally {
            faceButton.disabled = false;
        }
    });

    window.addEventListener("beforeunload", stopCamera);

    // --- TAHAP 4: CATAT ABSENSI ---
    enterRoomButton.addEventListener("click", async () => {
        if (!roomSelect.value || !scheduleSelect.value || !gpsVerified || !fingerprintVerified || !faceVerified) {
            verificationStatus.textContent = "Lengkapi seluruh tahapan verifikasi terlebih dahulu.";
            return;
        }
        enterRoomButton.disabled = true;
        verificationStatus.textContent = "Mengirim dan mencatat data absensi...";

        const form = new FormData();
        form.set("action", "verify_access");
        form.set("room_name", roomSelect.value);
        form.set("schedule_id", scheduleSelect.value);
        form.set("fingerprint_verified", "1");
        form.set("face_verified", "1");
        form.set("face_proof", faceProof || "");
        form.set("user_latitude", userCoords.latitude !== null ? userCoords.latitude : "");
        form.set("user_longitude", userCoords.longitude !== null ? userCoords.longitude : "");
        form.set("csrf_token", roomCsrfToken);

        try {
            const response = await fetch("../Koneksi/Room.php", {
                method: "POST",
                body: form,
                headers: { Accept: "application/json" }
            });
            const result = await response.json();
            verificationStatus.textContent = result.message;

            if (response.ok && result.success) {
                // Simpan cache riwayat lokal
                try {
                    const localHistory = JSON.parse(localStorage.getItem("siabak_history_cache") || "[]");
                    localHistory.unshift({
                        roomName: roomSelect.value,
                        courseName: scheduleSelect.options[scheduleSelect.selectedIndex]?.text || "",
                        time: new Date().toLocaleString("id-ID"),
                        distance: result.distance_meters || "0"
                    });
                    localStorage.setItem("siabak_history_cache", JSON.stringify(localHistory.slice(0, 20)));
                } catch (e) {}

                await loadRoomsForStudent();
                resetVerification("Absensi berhasil dicatat! Kehadiran dan koordinat Anda tersimpan di sistem.");
                await loadActiveSchedules();
            }
        } catch (error) {
            verificationStatus.textContent = "Absensi tidak dapat diproses karena gangguan server atau jaringan.";
        } finally {
            enterRoomButton.disabled = !(roomSelect.value && scheduleSelect.value && gpsVerified && fingerprintVerified && faceVerified);
        }
    });

    window.addEventListener("beforeunload", stopCamera);
    loadRoomsForStudent().then(loadActiveSchedules);
    setInterval(loadActiveSchedules, 60000);
</script>
<?php require __DIR__ . "/Student_Footer.php"; ?>
