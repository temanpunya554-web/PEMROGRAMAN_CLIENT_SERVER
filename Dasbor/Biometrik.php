<?php
require_once __DIR__ . "/Student_Auth.php";
$activeStudentPage = "biometrics";
$faceStatement = mysqli_prepare(
    $conn,
    "SELECT `UpdatedAt`, (`FaceDescriptor` IS NOT NULL AND `FaceDescriptor` <> '') AS `HasDescriptor` FROM `mahasiswa_face_enrollments` WHERE `Username` = ? LIMIT 1"
);
mysqli_stmt_bind_param($faceStatement, "s", $studentUsername);
mysqli_stmt_execute($faceStatement);
mysqli_stmt_bind_result($faceStatement, $faceUpdatedAt, $faceHasDescriptor);
$faceEnrolled = mysqli_stmt_fetch($faceStatement);
mysqli_stmt_close($faceStatement);
require __DIR__ . "/Student_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">PENGATURAN AKUN</p>
    <h1>Pendaftaran biometrik</h1>
    <p>Kelola foto wajah dan lihat kesiapan pendaftaran sidik jari.</p>
</section>

<p class="community-privacy-note">
    Foto wajah dan ciri biometriknya digunakan sebagai acuan pencocokan saat absensi. Jika sistem meminta pendaftaran ulang, ambil foto baru dengan satu wajah, pencahayaan cukup, dan wajah menghadap kamera. Foto dapat diganti atau dihapus. Pendaftaran sidik jari menunggu perangkat sensor/SDK dari dosen.
</p>

<div class="biometric-enrollment-grid student-biometric-grid">
    <section class="student-content-panel biometric-enrollment-card">
        <h2>Pendaftaran wajah</h2>
        <p id="face-enrollment-status" role="status">
            <?= $faceEnrolled && $faceHasDescriptor
                ? "Foto dan ciri wajah terdaftar sejak " . studentEscape($faceUpdatedAt) . "."
                : ($faceEnrolled ? "Foto lama ditemukan, tetapi belum memiliki data pencocokan. Silakan ambil dan simpan ulang foto wajah." : "Belum ada foto wajah yang didaftarkan.") ?>
        </p>
        <div class="face-enrollment-preview">
            <video id="face-enrollment-video" autoplay muted playsinline hidden></video>
            <img id="face-enrollment-photo-preview" alt="Pratinjau foto pendaftaran wajah" hidden />
            <span id="face-enrollment-camera-hint">Kamera belum diaktifkan</span>
        </div>
        <p>Pastikan wajah terlihat jelas, pencahayaan cukup, dan hanya satu orang berada di dalam bingkai.</p>
        <div class="home-actions biometric-actions">
            <button type="button" class="home-button" id="start-face-enrollment">Aktifkan kamera</button>
            <button type="button" class="room-action lock-action" id="stop-face-enrollment" hidden>Matikan kamera</button>
            <button type="button" class="home-button" id="capture-face-enrollment" disabled>Ambil foto</button>
        </div>
        <label class="biometric-consent">
            <input id="face-enrollment-consent" type="checkbox" />
            <span>Saya setuju foto wajah saya disimpan untuk pendaftaran biometrik.</span>
        </label>
        <div class="home-actions biometric-actions">
            <button type="button" class="home-button" id="save-face-enrollment" disabled>Simpan pendaftaran wajah</button>
            <button type="button" class="room-action lock-action" id="delete-face-enrollment" <?= $faceEnrolled ? "" : "hidden" ?>>
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
    const faceEnrollmentCsrfToken = <?= json_encode($studentCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const enrollmentVideo = document.getElementById("face-enrollment-video");
    const enrollmentPhotoPreview = document.getElementById("face-enrollment-photo-preview");
    const enrollmentCameraHint = document.getElementById("face-enrollment-camera-hint");
    const enrollmentStatus = document.getElementById("face-enrollment-message");
    const enrollmentConsent = document.getElementById("face-enrollment-consent");
    const startEnrollmentButton = document.getElementById("start-face-enrollment");
    const stopEnrollmentButton = document.getElementById("stop-face-enrollment");
    const captureEnrollmentButton = document.getElementById("capture-face-enrollment");
    const saveEnrollmentButton = document.getElementById("save-face-enrollment");
    let enrollmentStream = null;
    let enrollmentPhotoBlob = null;
    let enrollmentPhotoUrl = null;

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
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            enrollmentStatus.textContent = "Kamera memerlukan browser yang mendukung HTTPS atau localhost.";
            return;
        }
        startEnrollmentButton.disabled = true;
        try {
            enrollmentStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "user" },
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

    captureEnrollmentButton.addEventListener("click", () => {
        if (!enrollmentStream || enrollmentVideo.videoWidth < 320 || enrollmentVideo.videoHeight < 320) {
            enrollmentStatus.textContent = "Tunggu sampai pratinjau kamera siap, lalu coba lagi.";
            return;
        }
        const canvas = document.createElement("canvas");
        canvas.width = enrollmentVideo.videoWidth;
        canvas.height = enrollmentVideo.videoHeight;
        const context = canvas.getContext("2d");
        if (!context) {
            enrollmentStatus.textContent = "Foto tidak dapat diproses oleh browser ini.";
            return;
        }
        context.drawImage(enrollmentVideo, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
            if (!blob) {
                enrollmentStatus.textContent = "Foto tidak dapat diproses. Silakan coba lagi.";
                return;
            }
            enrollmentPhotoBlob = blob;
            if (enrollmentPhotoUrl) URL.revokeObjectURL(enrollmentPhotoUrl);
            enrollmentPhotoUrl = URL.createObjectURL(blob);
            enrollmentPhotoPreview.src = enrollmentPhotoUrl;
            enrollmentPhotoPreview.hidden = false;
            enrollmentCameraHint.hidden = true;
            stopEnrollmentCamera();
            saveEnrollmentButton.disabled = !enrollmentConsent.checked;
            enrollmentStatus.textContent = "Pratinjau foto siap. Setujui penyimpanan untuk melanjutkan.";
        }, "image/jpeg", 0.88);
    });

    enrollmentConsent.addEventListener("change", () => {
        saveEnrollmentButton.disabled = !enrollmentPhotoBlob || !enrollmentConsent.checked;
    });

    saveEnrollmentButton.addEventListener("click", async () => {
        if (!enrollmentPhotoBlob || !enrollmentConsent.checked) {
            enrollmentStatus.textContent = "Ambil foto dan setujui penyimpanannya terlebih dahulu.";
            return;
        }
        saveEnrollmentButton.disabled = true;
        enrollmentStatus.textContent = "Menyimpan pendaftaran wajah...";
        try {
            enrollmentStatus.textContent = "Menganalisis wajah dan membuat ciri biometrik...";
            await loadFaceModels();
            const detections = await faceapi.detectAllFaces(
                enrollmentPhotoPreview,
                new faceapi.SsdMobilenetv1Options({ minConfidence: 0.6 })
            ).withFaceLandmarks().withFaceDescriptors();
            if (detections.length !== 1) {
                throw new Error("Pastikan tepat satu wajah terlihat jelas pada foto pendaftaran.");
            }

            const form = new FormData();
            form.set("action", "save");
            form.set("consent", "1");
            form.set("csrf_token", faceEnrollmentCsrfToken);
            form.set("face_photo", enrollmentPhotoBlob, "face-enrollment.jpg");
            form.set("face_descriptor", JSON.stringify(Array.from(detections[0].descriptor)));

            const response = await fetch("../Koneksi/Face_Enrollment.php", {
                method: "POST",
                body: form,
                headers: { Accept: "application/json" }
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message);

            document.getElementById("face-enrollment-status").textContent =
                "Foto dan ciri wajah berhasil didaftarkan untuk pencocokan saat absensi.";
            document.getElementById("delete-face-enrollment").hidden = false;
            enrollmentStatus.textContent = result.message;
            enrollmentPhotoBlob = null;
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
