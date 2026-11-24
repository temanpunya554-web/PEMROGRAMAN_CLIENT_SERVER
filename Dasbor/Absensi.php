<?php
require_once __DIR__ . "/Student_Auth.php";
$activeStudentPage = "attendance";
require __DIR__ . "/Student_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">ABSENSI MAHASISWA</p>
    <h1>Verifikasi masuk ruangan</h1>
    <p>Pilih ruangan dan jadwal mata kuliah, lalu ikuti verifikasi lokasi (geofencing) dan biometrik untuk mencatat kehadiran.</p>
</section>

<section class="student-content-panel">
    <div id="network-status-banner" class="network-status-banner" hidden></div>
    <p id="attendance-test-mode-notice" class="form-help" role="status" hidden></p>
    <p id="verification-status" class="community-data-status" role="status">Memuat status ruangan...</p>

    <div class="verification-layout">
        <div class="verification-controls">
            <label for="room-select">Pilih ruangan</label>
            <select id="room-select">
                <option value="">Memuat ruangan...</option>
            </select>

            <label for="schedule-select">Jadwal mata kuliah</label>
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
            </div>

            <!-- TAHAP 2: FINGERPRINT -->
            <button type="button" id="fingerprint-button" class="room-action lock-action" disabled>
                2. Verifikasi sidik jari (simulasi)
            </button>

            <!-- TAHAP 3: KAMERA WAJAH -->
            <button type="button" id="face-button" class="room-action open-action" disabled>
                3. Verifikasi manusia &amp; cocokkan wajah
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
        <strong>Ketentuan Absensi:</strong> Mahasiswa harus berada di dalam radius geofencing ruangan (50–100 meter). Pencocokan wajah membandingkan kamera dengan ciri wajah akun yang terdaftar dan meminta tantangan acak berupa kedipan atau gerakan kepala. Pemeriksaan keaktifan ini berjalan di browser dan belum merupakan layanan anti-spoof tingkat produksi. Sidik jari masih simulasi karena perangkat belum tersedia.
    </p>
</section>

<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/dist/face-api.js"></script>
<script>
    const FACE_MODEL_URL = "https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/model";
    let faceModelsReady = false;
    async function loadFaceModels() {
        if (faceModelsReady) return;
        if (!window.faceapi) throw new Error("Pustaka pengenalan wajah gagal dimuat. Periksa koneksi internet.");
        await Promise.all([faceapi.nets.ssdMobilenetv1.loadFromUri(FACE_MODEL_URL), faceapi.nets.faceLandmark68Net.loadFromUri(FACE_MODEL_URL), faceapi.nets.faceRecognitionNet.loadFromUri(FACE_MODEL_URL)]);
        faceModelsReady = true;
    }
    const roomCsrfToken = <?= json_encode($studentCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const roomSelect = document.getElementById("room-select");
    const scheduleSelect = document.getElementById("schedule-select");
    const scheduleHint = document.getElementById("schedule-hint");
    const attendanceTestModeNotice = document.getElementById("attendance-test-mode-notice");
    const verificationStatus = document.getElementById("verification-status");
    const gpsButton = document.getElementById("gps-button");
    const gpsInfoBox = document.getElementById("gps-info-box");
    const gpsBadge = document.getElementById("gps-badge");
    const gpsDetails = document.getElementById("gps-details");
    const fingerprintButton = document.getElementById("fingerprint-button");
    const faceButton = document.getElementById("face-button");
    const enterRoomButton = document.getElementById("enter-room-button");
    const faceVideo = document.getElementById("face-video");
    const cameraStatus = document.getElementById("camera-status");
    const networkBanner = document.getElementById("network-status-banner");

    let roomsData = [];
    let activeSchedules = [];
    let scheduleLoadError = "";
    let openMinutesBefore = 15;
    let attendanceTestMode = false;
    let currentRoom = null;
    let gpsVerified = false;
    let userCoords = { latitude: null, longitude: null };
    let fingerprintVerified = false;
    let faceVerified = false;
    let cameraStream = null;
    let faceProof = null;

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

    function calculateEyeAspectRatio(eye) {
        const distance = (first, second) => Math.hypot(first.x - second.x, first.y - second.y);
        const horizontal = distance(eye[0], eye[3]);
        if (horizontal === 0) return 0;
        return (distance(eye[1], eye[5]) + distance(eye[2], eye[4])) / (2 * horizontal);
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
            scheduleLoadError = "";
            openMinutesBefore = result.open_minutes_before || openMinutesBefore;
            attendanceTestMode = result.test_mode === true;
            attendanceTestModeNotice.hidden = !attendanceTestMode;
            attendanceTestModeNotice.textContent = attendanceTestMode
                ? "MODE UJI COBA AKTIF: absensi dapat dilakukan kapan saja untuk jadwal terdaftar. Ruangan harus tetap terbuka, dan GPS, radius, serta verifikasi biometrik tetap diperiksa."
                : "";
        } catch (error) {
            activeSchedules = [];
            scheduleLoadError = error.message || "Periksa koneksi dan sesi login.";
            attendanceTestMode = false;
            attendanceTestModeNotice.hidden = true;
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

        if (scheduleLoadError) {
            scheduleSelect.add(new Option("Jadwal gagal dimuat", ""));
            scheduleSelect.disabled = true;
            scheduleHint.textContent = `Jadwal mata kuliah tidak dapat dimuat: ${scheduleLoadError}`;
            return;
        }

        const matches = activeSchedules.filter(item => String(item.RoomName).trim().toLowerCase() === roomName.trim().toLowerCase());
        if (matches.length === 0) {
            scheduleSelect.add(new Option(attendanceTestMode ? "Tidak ada jadwal terdaftar" : "Tidak ada kelas berlangsung", ""));
            scheduleSelect.disabled = true;
            scheduleHint.textContent = attendanceTestMode
                ? `Belum ada jadwal mata kuliah yang terdaftar di ${roomName}.`
                : `Tidak ada mata kuliah di ${roomName} saat ini. Absensi dibuka ${openMinutesBefore} menit sebelum kelas dimulai sampai kelas selesai.`;
        } else {
            if (matches.length > 1 || attendanceTestMode) scheduleSelect.add(new Option("Pilih mata kuliah", ""));
            matches.forEach(item => {
                const day = attendanceTestMode ? `${item.DayName} · ` : "";
                scheduleSelect.add(new Option(`${day}${item.CourseName} (${formatClock(item.StartTime)}-${formatClock(item.EndTime)})`, item.Id));
            });
            scheduleSelect.disabled = !attendanceTestMode && matches.length === 1;
            if (matches.some(item => String(item.Id) === previous)) {
                scheduleSelect.value = previous;
            } else if (matches.length === 1 && !attendanceTestMode) {
                scheduleSelect.value = String(matches[0].Id);
            }
            scheduleHint.textContent = attendanceTestMode
                ? "Pilih jadwal terdaftar untuk uji radius lokasi; hari dan jam jadwal tidak membatasi absensi dalam mode uji coba."
                : matches.length === 1
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

        const hasRoomCoordinates = currentRoom.Latitude !== undefined && currentRoom.Latitude !== null &&
            String(currentRoom.Latitude).trim() !== "" &&
            currentRoom.Longitude !== undefined && currentRoom.Longitude !== null &&
            String(currentRoom.Longitude).trim() !== "";
        const targetLat = Number(currentRoom.Latitude);
        const targetLon = Number(currentRoom.Longitude);
        const maxRadius = currentRoom.RadiusMeters ? parseInt(currentRoom.RadiusMeters, 10) : 100;

        if (!hasRoomCoordinates || !Number.isFinite(targetLat) || !Number.isFinite(targetLon) ||
            targetLat < -90 || targetLat > 90 || targetLon < -180 || targetLon > 180) {
            gpsVerified = false;
            gpsBadge.textContent = "Lokasi ruangan belum diatur ❌";
            gpsBadge.className = "gps-badge gps-badge-out-range";
            gpsDetails.textContent = "Koordinat ruangan belum dikonfigurasi. Minta dosen atau admin mengatur geofence ruangan.";
            fingerprintButton.disabled = true;
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
                gpsDetails.innerHTML = `Tidak dapat membaca GPS (${err.message}). Pastikan izin lokasi browser aktif.`;
                fingerprintButton.disabled = true;
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
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

    // --- TAHAP 3: TANTANGAN KEAKTIFAN + PENCOCOKAN WAJAH ---
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
            await loadFaceModels();
            if (!cameraStream) {
                cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" }, audio: false });
                faceVideo.srcObject = cameraStream;
                await new Promise(resolve => faceVideo.onloadedmetadata = resolve);
                await faceVideo.play();
            }
            const challenge = Math.random() < 0.5 ? "blink" : "turn";
            cameraStatus.textContent = "Pemeriksaan manusia aktif";
            verificationStatus.textContent = challenge === "blink"
                ? "Tantangan keaktifan: kedipkan mata sekali, lalu buka kembali saat melihat kamera."
                : "Tantangan keaktifan: gerakkan kepala perlahan ke kiri lalu ke kanan.";
            const offsets = [];
            const openEyeRatios = [];
            let openEyeBaseline = null;
            let eyesClosed = false;
            let livenessPassed = false;
            let finalDetection = null;
            const challengeDeadline = Date.now() + 12000;
            while (Date.now() < challengeDeadline && !livenessPassed) {
                await new Promise(resolve => setTimeout(resolve, 180));
                const detection = await faceapi.detectSingleFace(faceVideo, new faceapi.SsdMobilenetv1Options({ minConfidence: 0.6 })).withFaceLandmarks().withFaceDescriptor();
                if (!detection) throw new Error("Wajah tidak terdeteksi. Pastikan wajah terlihat jelas dan pencahayaan cukup.");
                const box = detection.detection.box;
                const nose = detection.landmarks.getNose();
                const leftEye = detection.landmarks.getLeftEye();
                const rightEye = detection.landmarks.getRightEye();
                const noseX = nose.reduce((sum, point) => sum + point.x, 0) / nose.length;
                const eyesX = (leftEye.reduce((sum, point) => sum + point.x, 0) / leftEye.length + rightEye.reduce((sum, point) => sum + point.x, 0) / rightEye.length) / 2;
                offsets.push((noseX - eyesX) / Math.max(box.width, 1));
                finalDetection = detection;

                if (challenge === "turn") {
                    livenessPassed = Math.max(...offsets) - Math.min(...offsets) >= 0.035;
                } else {
                    const eyeRatio = (calculateEyeAspectRatio(leftEye) + calculateEyeAspectRatio(rightEye)) / 2;
                    if (openEyeBaseline === null) {
                        if (eyeRatio > 0.12) openEyeRatios.push(eyeRatio);
                        if (openEyeRatios.length === 4) {
                            openEyeBaseline = openEyeRatios.reduce((sum, value) => sum + value, 0) / openEyeRatios.length;
                        }
                    } else if (eyeRatio < openEyeBaseline * 0.68) {
                        eyesClosed = true;
                    } else if (eyesClosed && eyeRatio > openEyeBaseline * 0.82) {
                        livenessPassed = true;
                    }
                }
            }
            if (!livenessPassed) {
                throw new Error(challenge === "blink"
                    ? "Kedipan belum terdeteksi. Coba lagi dengan pencahayaan yang cukup dan buka mata setelah berkedip."
                    : "Gerakan kepala belum terdeteksi. Coba lagi dengan menoleh perlahan ke kiri lalu ke kanan.");
            }
            const form = new FormData();
            form.set("csrf_token", roomCsrfToken);
            form.set("descriptor", JSON.stringify(Array.from(finalDetection.descriptor)));
            const verifyResponse = await fetch("../Koneksi/Face_Verify.php", { method: "POST", body: form, headers: { Accept: "application/json" } });
            const verifyResult = await verifyResponse.json();
            if (!verifyResponse.ok || !verifyResult.success) throw new Error(verifyResult.message || "Wajah tidak cocok.");
            faceProof = verifyResult.proof;
            faceVerified = true;
            enterRoomButton.disabled = false;
            cameraStatus.textContent = "Wajah cocok dengan akun terdaftar ✓";
            verificationStatus.textContent = verifyResult.message + " Tantangan keaktifan berhasil.";
        } catch (error) {
            faceVerified = false;
            faceProof = null;
            cameraStatus.textContent = "Verifikasi wajah belum berhasil";
            verificationStatus.textContent = error.message || "Verifikasi wajah gagal. Silakan coba lagi.";
        } finally {
            faceButton.disabled = false;
        }
    });

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
