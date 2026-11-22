<?php
require_once __DIR__ . "/Student_Auth.php";
require_once __DIR__ . "/../Koneksi/Face_Service_Client.php";
$activeStudentPage = "biometrics";
$faceStatement = mysqli_prepare(
    $conn,
    "SELECT `UpdatedAt`, (`FaceDescriptor` IS NOT NULL AND `FaceDescriptor` <> '') AS `HasDescriptor`, COALESCE(`ModelVersion`, '') AS `ModelVersion` FROM `mahasiswa_face_enrollments` WHERE `Username` = ? LIMIT 1"
);
mysqli_stmt_bind_param($faceStatement, "s", $studentUsername);
mysqli_stmt_execute($faceStatement);
mysqli_stmt_bind_result($faceStatement, $faceUpdatedAt, $faceHasDescriptor, $faceModelVersion);
$faceRecordFound = mysqli_stmt_fetch($faceStatement);
mysqli_stmt_close($faceStatement);
$faceModelCurrent = $faceRecordFound && $faceHasDescriptor && $faceModelVersion === FACE_SERVICE_MODEL_VERSION;
require __DIR__ . "/Student_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">PENGATURAN AKUN</p>
    <h1>Pendaftaran biometrik</h1>
    <p>Daftarkan wajah melalui kamera langsung dengan pemeriksaan anti-spoof di komputer lokal.</p>
</section>

<p class="community-privacy-note">
    Foto wajah dan ciri biometriknya digunakan sebagai acuan pencocokan saat absensi. Pemrosesan dilakukan di komputer kampus/lokal; bingkai kamera tidak dikirim ke layanan internet. Pemeriksaan liveness membantu menolak foto atau tampilan ulang, tetapi tidak dapat menjamin bahwa semua jenis spoofing terdeteksi. Foto dapat diganti atau dihapus.
</p>

<div class="biometric-enrollment-grid student-biometric-grid">
    <section class="student-content-panel biometric-enrollment-card">
        <h2>Pendaftaran wajah</h2>
        <p id="face-enrollment-status" role="status">
            <?= $faceModelCurrent
                ? "Pendaftaran wajah terbaru tersimpan sejak " . studentEscape($faceUpdatedAt) . "."
                : ($faceRecordFound ? "Pendaftaran wajah belum memakai model terbaru. Daftarkan ulang wajah melalui pemeriksaan kamera baru." : "Belum ada foto wajah yang didaftarkan.") ?>
        </p>
        <div class="face-enrollment-preview">
            <video id="face-enrollment-video" autoplay muted playsinline hidden></video>
            <img id="face-enrollment-photo-preview" alt="Pratinjau foto pendaftaran wajah" hidden />
            <span id="face-enrollment-camera-hint">Kamera belum diaktifkan</span>
        </div>
        <p id="face-enrollment-challenge" role="status">Pastikan hanya satu wajah terlihat. Ikuti arah gerakan yang ditampilkan saat pemeriksaan.</p>
        <div class="home-actions biometric-actions">
            <button type="button" class="home-button" id="start-face-enrollment">Aktifkan kamera</button>
            <button type="button" class="room-action lock-action" id="stop-face-enrollment" hidden>Matikan kamera</button>
            <button type="button" class="home-button" id="capture-face-enrollment" disabled>Mulai pemeriksaan langsung</button>
        </div>
        <label class="biometric-consent">
            <input id="face-enrollment-consent" type="checkbox" />
            <span>Saya setuju foto acuan wajah disimpan untuk pendaftaran biometrik.</span>
        </label>
        <div class="home-actions biometric-actions">
            <button type="button" class="home-button" id="save-face-enrollment" disabled>Simpan pendaftaran wajah</button>
            <button type="button" class="room-action lock-action" id="delete-face-enrollment" <?= $faceRecordFound ? "" : "hidden" ?>>
                Hapus foto wajah
            </button>
        </div>
        <p class="community-data-status" id="face-enrollment-message" role="status"></p>
    </section>

    <section class="student-content-panel biometric-enrollment-card">
        <h2>Pendaftaran sidik jari</h2>
        <p class="student-enrollment-status">Status: menunggu perangkat sensor.</p>
        <p>Pendaftaran sidik jari asli belum bisa dilakukan dari halaman web ini. Setelah tipe sensor/SDK ditentukan bersama dosen, bagian ini dapat dihubungkan ke perangkat pendaftaran.</p>
        <p class="community-privacy-note">Belum ada data sidik jari yang direkam atau disimpan.</p>
    </section>
</div>

<script>
    const faceEnrollmentCsrfToken = <?= json_encode($studentCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let faceEnrollmentChallenge = null;
    let faceEnrollmentNonce = null;
    const enrollmentVideo = document.getElementById("face-enrollment-video");
    const enrollmentPhotoPreview = document.getElementById("face-enrollment-photo-preview");
    const enrollmentCameraHint = document.getElementById("face-enrollment-camera-hint");
    const enrollmentStatus = document.getElementById("face-enrollment-message");
    const enrollmentChallengeHint = document.getElementById("face-enrollment-challenge");
    const enrollmentConsent = document.getElementById("face-enrollment-consent");
    const startEnrollmentButton = document.getElementById("start-face-enrollment");
    const stopEnrollmentButton = document.getElementById("stop-face-enrollment");
    const captureEnrollmentButton = document.getElementById("capture-face-enrollment");
    const saveEnrollmentButton = document.getElementById("save-face-enrollment");
    let enrollmentStream = null;
    let enrollmentPhotoBlob = null;
    let enrollmentPhotoUrl = null;
    let enrollmentFrames = null;

    async function requestLivenessChallenge(purpose) {
        const form = new FormData();
        form.set("csrf_token", faceEnrollmentCsrfToken);
        form.set("purpose", purpose);
        const response = await fetch("../Koneksi/Face_Challenge.php", {
            method: "POST",
            body: form,
            headers: { Accept: "application/json" }
        });
        const responseText = await response.text();
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (error) {
            throw new Error("Server tidak mengembalikan respons valid. Layanan FaceService mungkin masih dimuat.");
        }
        if (!response.ok || !result.success) throw new Error(result.message || "Tantangan kamera gagal disiapkan.");
        return result;
    }

    function delay(milliseconds) {
        return new Promise((resolve) => setTimeout(resolve, milliseconds));
    }

    async function captureEnrollmentFrame() {
        if (!enrollmentStream || enrollmentVideo.videoWidth < 320 || enrollmentVideo.videoHeight < 320) {
            throw new Error("Tunggu sampai pratinjau kamera siap, lalu coba lagi.");
        }
        const scale = Math.min(1, 640 / enrollmentVideo.videoWidth);
        const canvas = document.createElement("canvas");
        canvas.width = Math.round(enrollmentVideo.videoWidth * scale);
        canvas.height = Math.round(enrollmentVideo.videoHeight * scale);
        const context = canvas.getContext("2d");
        if (!context) throw new Error("Bingkai kamera tidak dapat diproses oleh browser ini.");
        context.drawImage(enrollmentVideo, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.75));
        if (!blob) throw new Error("Bingkai kamera tidak dapat diproses. Silakan coba lagi.");
        const dataUrl = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(new Error("Bingkai kamera tidak dapat dibaca."));
            reader.readAsDataURL(blob);
        });
        return { blob, base64: String(dataUrl).split(",")[1] };
    }

    function stopEnrollmentCamera() {
        if (enrollmentStream) {
            enrollmentStream.getTracks().forEach((track) => track.stop());
            enrollmentStream = null;
        }
        enrollmentVideo.srcObject = null;
        enrollmentVideo.hidden = true;
        startEnrollmentButton.disabled = false;
        stopEnrollmentButton.hidden = true;
        captureEnrollmentButton.disabled = true;
        if (!enrollmentPhotoBlob) {
            enrollmentCameraHint.textContent = "Kamera dimatikan";
            enrollmentCameraHint.hidden = false;
        }
    }

    startEnrollmentButton.addEventListener("click", async () => {
        enrollmentStatus.textContent = "";
        enrollmentFrames = null;
        enrollmentPhotoBlob = null;
        if (enrollmentPhotoUrl) {
            URL.revokeObjectURL(enrollmentPhotoUrl);
            enrollmentPhotoUrl = null;
        }
        enrollmentPhotoPreview.removeAttribute("src");
        enrollmentPhotoPreview.hidden = true;
        saveEnrollmentButton.disabled = true;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            enrollmentStatus.textContent = "Kamera memerlukan browser yang mendukung HTTPS atau localhost.";
            return;
        }
        startEnrollmentButton.disabled = true;
        try {
            enrollmentStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "user", width: { ideal: 640 }, height: { ideal: 480 } },
                audio: false
            });
            enrollmentVideo.srcObject = enrollmentStream;
            enrollmentVideo.hidden = false;
            enrollmentPhotoPreview.hidden = true;
            enrollmentCameraHint.hidden = true;
            stopEnrollmentButton.hidden = false;
            captureEnrollmentButton.disabled = false;
        } catch (error) {
            startEnrollmentButton.disabled = false;
            enrollmentStatus.textContent = "Kamera tidak dapat diaktifkan. Periksa izin kamera browser.";
        }
    });

    stopEnrollmentButton.addEventListener("click", stopEnrollmentCamera);

    captureEnrollmentButton.addEventListener("click", async () => {
        captureEnrollmentButton.disabled = true;
        enrollmentStatus.textContent = "Menyiapkan layanan kamera lokal...";
        try {
            const challengeResult = await requestLivenessChallenge("enrollment");
            faceEnrollmentChallenge = challengeResult.challenge;
            faceEnrollmentNonce = challengeResult.nonce;
            const movementText = faceEnrollmentChallenge === "left-right" ? "ke kiri, lalu ke kanan" : "ke kanan, lalu ke kiri";
            const firstFrames = [];
            for (let index = 0; index < 3; index += 1) {
                firstFrames.push(await captureEnrollmentFrame());
                await delay(350);
            }
            enrollmentChallengeHint.textContent = `Sekarang gerakkan kepala perlahan ${movementText}.`;
            enrollmentStatus.textContent = "Gerakkan kepala mengikuti arah yang diminta.";
            await delay(1800);
            const secondFrames = [];
            for (let index = 0; index < 3; index += 1) {
                secondFrames.push(await captureEnrollmentFrame());
                await delay(350);
            }

            enrollmentFrames = {
                first: firstFrames.map((frame) => frame.base64),
                second: secondFrames.map((frame) => frame.base64)
            };
            enrollmentPhotoBlob = firstFrames[1].blob;
            if (enrollmentPhotoUrl) URL.revokeObjectURL(enrollmentPhotoUrl);
            enrollmentPhotoUrl = URL.createObjectURL(enrollmentPhotoBlob);
            enrollmentPhotoPreview.src = enrollmentPhotoUrl;
            enrollmentPhotoPreview.hidden = false;
            enrollmentCameraHint.hidden = true;
            stopEnrollmentCamera();
            saveEnrollmentButton.disabled = !enrollmentConsent.checked;
            enrollmentStatus.textContent = "Bingkai kamera siap. Setujui penyimpanan untuk menjalankan pemeriksaan wajah lokal.";
        } catch (error) {
            enrollmentStatus.textContent = error.message || "Bingkai kamera tidak dapat diambil.";
        } finally {
            captureEnrollmentButton.disabled = !enrollmentStream;
        }
    });

    enrollmentConsent.addEventListener("change", () => {
        saveEnrollmentButton.disabled = !enrollmentPhotoBlob || !enrollmentConsent.checked;
    });

    saveEnrollmentButton.addEventListener("click", async () => {
        if (!enrollmentPhotoBlob || !enrollmentFrames || !faceEnrollmentChallenge || !faceEnrollmentNonce || !enrollmentConsent.checked) {
            enrollmentStatus.textContent = "Selesaikan pemeriksaan kamera dan setujui penyimpanannya terlebih dahulu.";
            return;
        }
        saveEnrollmentButton.disabled = true;
        enrollmentStatus.textContent = "Menyimpan pendaftaran wajah...";
        try {
            enrollmentStatus.textContent = "Menganalisis bingkai di layanan verifikasi lokal...";
            const form = new FormData();
            form.set("action", "save");
            form.set("consent", "1");
            form.set("csrf_token", faceEnrollmentCsrfToken);
            form.set("challenge", faceEnrollmentChallenge);
            form.set("challenge_nonce", faceEnrollmentNonce);
            form.set("first_frames", JSON.stringify(enrollmentFrames.first));
            form.set("second_frames", JSON.stringify(enrollmentFrames.second));

            const response = await fetch("../Koneksi/Face_Enrollment.php", {
                method: "POST",
                body: form,
                headers: { Accept: "application/json" }
            });
            const responseText = await response.text();
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (error) {
                throw new Error("Server wajah mengembalikan respons tidak valid. Periksa layanan FaceService, lalu coba lagi.");
            }
            if (!response.ok || !result.success) throw new Error(result.message || "Pendaftaran wajah gagal.");

            document.getElementById("face-enrollment-status").textContent =
                "Pendaftaran wajah berhasil disimpan setelah pemeriksaan kamera.";
            document.getElementById("delete-face-enrollment").hidden = false;
            enrollmentStatus.textContent = result.message;
            enrollmentPhotoBlob = null;
            enrollmentFrames = null;
        } catch (error) {
            enrollmentStatus.textContent = error.message || "Wajah tidak dapat dianalisis atau disimpan.";
            saveEnrollmentButton.disabled = false;
        }
    });

    document.getElementById("delete-face-enrollment").addEventListener("click", async () => {
        if (!window.confirm("Hapus foto pendaftaran wajah dari akun ini?")) return;
        const form = new FormData();
        form.set("action", "delete");
        form.set("csrf_token", faceEnrollmentCsrfToken);
        try {
            const response = await fetch("../Koneksi/Face_Enrollment.php", {
                method: "POST",
                body: form,
                headers: { Accept: "application/json" }
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message);
            document.getElementById("face-enrollment-status").textContent = "Belum ada foto wajah yang didaftarkan.";
            document.getElementById("delete-face-enrollment").hidden = true;
            enrollmentStatus.textContent = result.message;
            enrollmentPhotoPreview.removeAttribute("src");
            enrollmentPhotoPreview.hidden = true;
            enrollmentFrames = null;
            enrollmentPhotoBlob = null;
            if (enrollmentPhotoUrl) {
                URL.revokeObjectURL(enrollmentPhotoUrl);
                enrollmentPhotoUrl = null;
            }
        } catch (error) {
            enrollmentStatus.textContent = error.message || "Foto wajah tidak dapat dihapus.";
        }
    });

    window.addEventListener("beforeunload", () => {
        stopEnrollmentCamera();
        if (enrollmentPhotoUrl) URL.revokeObjectURL(enrollmentPhotoUrl);
    });
</script>
<?php require __DIR__ . "/Student_Footer.php"; ?>
