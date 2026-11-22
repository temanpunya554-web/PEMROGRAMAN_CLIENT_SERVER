<?php

const FACE_SERVICE_MODEL_VERSION = "deepface-sface-antispoof-v1";
const FACE_SERVICE_TASK_NAME = "SIABAK Face Verification On Demand";

final class FaceServiceException extends RuntimeException
{
    private $statusCode;

    public function __construct($statusCode, $message)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }
}

function faceCreateLivenessChallenge($sessionKey)
{
    $challenge = random_int(0, 1) === 0 ? "left-right" : "right-left";
    $nonce = bin2hex(random_bytes(32));
    $_SESSION[$sessionKey] = [
        "challenge" => $challenge,
        "nonce" => $nonce,
        "expires" => time() + 600,
    ];

    return ["challenge" => $challenge, "nonce" => $nonce];
}

function faceConsumeLivenessChallenge($sessionKey, $submittedChallenge, $submittedNonce)
{
    $savedChallenge = $_SESSION[$sessionKey] ?? null;
    unset($_SESSION[$sessionKey]);

    if (
        !is_array($savedChallenge) ||
        !isset($savedChallenge["challenge"], $savedChallenge["nonce"], $savedChallenge["expires"]) ||
        !is_string($savedChallenge["challenge"]) ||
        !is_string($savedChallenge["nonce"]) ||
        $savedChallenge["expires"] < time() ||
        !is_string($submittedChallenge) ||
        !is_string($submittedNonce) ||
        !hash_equals($savedChallenge["challenge"], $submittedChallenge) ||
        !hash_equals($savedChallenge["nonce"], $submittedNonce)
    ) {
        throw new FaceServiceException(400, "Tantangan kamera sudah kedaluwarsa. Muat ulang halaman dan coba lagi.");
    }

    return $savedChallenge["challenge"];
}

function faceServiceRequest($endpoint, array $payload)
{
    set_time_limit(300);

    if (!function_exists("curl_init")) {
        throw new FaceServiceException(503, "Ekstensi cURL PHP belum aktif.");
    }

    $tokenPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . "FaceService" . DIRECTORY_SEPARATOR . ".service-token";
    if (!is_file($tokenPath) || !is_readable($tokenPath)) {
        throw new FaceServiceException(503, "Layanan verifikasi wajah belum dijalankan.");
    }
    $token = file_get_contents($tokenPath);
    if (!is_string($token) || trim($token) === "") {
        error_log("Face service token file could not be read.");
        throw new FaceServiceException(503, "Token layanan verifikasi wajah tidak dapat dibaca.");
    }

    try {
        $requestBody = json_encode($payload, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new FaceServiceException(400, "Data kamera tidak dapat disiapkan.");
    }

    $handle = curl_init("http://127.0.0.1:8765" . $endpoint);
    if ($handle === false) {
        throw new FaceServiceException(503, "Koneksi ke layanan verifikasi wajah tidak dapat disiapkan.");
    }
    $optionsSet = curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . trim($token),
            "Content-Type: application/json",
            "Accept: application/json",
        ],
        CURLOPT_PROXY => "",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 300,
    ]);
    if (!$optionsSet) {
        $curlError = curl_error($handle);
        curl_close($handle);
        error_log("Face service request could not be configured: " . $curlError);
        throw new FaceServiceException(503, "Koneksi ke layanan verifikasi wajah tidak dapat disiapkan.");
    }

    $responseBody = curl_exec($handle);
    $curlError = curl_error($handle);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if (!is_string($responseBody)) {
        error_log("Face service request failed: " . $curlError);
        throw new FaceServiceException(503, "Layanan verifikasi wajah tidak merespons. Pastikan FaceService berjalan.");
    }

    try {
        $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        error_log("Face service returned invalid JSON with status " . $statusCode);
        throw new FaceServiceException(503, "Layanan verifikasi wajah memberikan respons yang tidak valid.");
    }

    if (!is_array($response)) {
        throw new FaceServiceException(503, "Layanan verifikasi wajah memberikan respons yang tidak valid.");
    }
    if ($statusCode < 200 || $statusCode >= 300) {
        $message = isset($response["detail"]) && is_string($response["detail"])
            ? $response["detail"]
            : "Pemeriksaan wajah tidak berhasil.";
        if ($statusCode >= 500 && $statusCode !== 503) {
            $message = "Layanan verifikasi wajah mengalami gangguan internal. Silakan coba lagi.";
        }
        throw new FaceServiceException($statusCode, $message);
    }

    return $response;
}

function faceServiceHealthIsReady()
{
    if (!function_exists("curl_init")) {
        throw new FaceServiceException(503, "Ekstensi cURL PHP belum aktif.");
    }

    $handle = curl_init("http://127.0.0.1:8765/health");
    if ($handle === false) {
        throw new FaceServiceException(503, "Koneksi ke layanan verifikasi wajah tidak dapat disiapkan.");
    }
    $optionsSet = curl_setopt_array($handle, [
        CURLOPT_HTTPGET => true,
        CURLOPT_PROXY => "",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => 2,
    ]);
    if (!$optionsSet) {
        $curlError = curl_error($handle);
        curl_close($handle);
        error_log("Face service health request could not be configured: " . $curlError);
        throw new FaceServiceException(503, "Koneksi ke layanan verifikasi wajah tidak dapat disiapkan.");
    }

    $responseBody = curl_exec($handle);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (!is_string($responseBody) || $statusCode !== 200) {
        return false;
    }

    $response = json_decode($responseBody, true);
    return is_array($response)
        && ($response["success"] ?? false) === true
        && ($response["model"] ?? "") === FACE_SERVICE_MODEL_VERSION;
}

function faceEnsureServiceRunning()
{
    set_time_limit(180);
    if (faceServiceHealthIsReady()) {
        return;
    }

    if (!function_exists("proc_open")) {
        throw new FaceServiceException(503, "PHP tidak dapat menjalankan tugas layanan wajah.");
    }

    $windowsRoot = getenv("SystemRoot");
    if (!is_string($windowsRoot) || $windowsRoot === "") {
        $windowsRoot = "C:\\Windows";
    }
    $taskScheduler = $windowsRoot . DIRECTORY_SEPARATOR . "System32" . DIRECTORY_SEPARATOR . "schtasks.exe";
    if (!is_file($taskScheduler)) {
        throw new FaceServiceException(503, "Penjadwal tugas Windows tidak ditemukan.");
    }

    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];
    $process = proc_open(
        [$taskScheduler, "/Run", "/TN", FACE_SERVICE_TASK_NAME],
        $descriptors,
        $pipes,
        null,
        null,
        ["bypass_shell" => true]
    );
    if (!is_resource($process)) {
        throw new FaceServiceException(503, "Tugas layanan wajah tidak dapat dijalankan.");
    }

    fclose($pipes[0]);
    $taskOutput = stream_get_contents($pipes[1]);
    $taskError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $taskExitCode = proc_close($process);
    if ($taskExitCode !== 0) {
        error_log("Face service task could not be started: " . trim($taskOutput . " " . $taskError));
        throw new FaceServiceException(
            503,
            "Tugas otomatis belum disiapkan. Buat tugas SIABAK Face Verification On Demand di Task Scheduler sesuai README."
        );
    }

    for ($attempt = 0; $attempt < 150; $attempt++) {
        usleep(500000);
        if (faceServiceHealthIsReady()) {
            return;
        }
    }

    throw new FaceServiceException(
        503,
        "Layanan wajah tidak siap setelah 75 detik. Periksa error FaceService atau jalankan ulang tugas layanan."
    );
}

function faceDecodeFrameGroups()
{
    $firstFrames = json_decode($_POST["first_frames"] ?? "", true);
    $secondFrames = json_decode($_POST["second_frames"] ?? "", true);

    foreach ([$firstFrames, $secondFrames] as $frames) {
        if (!is_array($frames) || count($frames) !== 3) {
            throw new FaceServiceException(400, "Ambil enam bingkai kamera dengan mengikuti tantangan gerak.");
        }
        foreach ($frames as $frame) {
            if (!is_string($frame) || $frame === "" || strlen($frame) > 700000) {
                throw new FaceServiceException(400, "Data bingkai kamera tidak valid.");
            }
        }
    }

    return [$firstFrames, $secondFrames];
}

function faceDecodeJpegFrame($encoded)
{
    $imageBytes = base64_decode($encoded, true);
    if (!is_string($imageBytes) || strlen($imageBytes) < 1 || strlen($imageBytes) > 512 * 1024) {
        throw new FaceServiceException(400, "Foto acuan kamera tidak valid.");
    }

    $imageInfo = getimagesizefromstring($imageBytes);
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($imageBytes);
    if (
        $imageInfo === false ||
        $mimeType !== "image/jpeg" ||
        $imageInfo[0] < 320 ||
        $imageInfo[1] < 320 ||
        $imageInfo[0] > 1920 ||
        $imageInfo[1] > 1920
    ) {
        throw new FaceServiceException(400, "Foto acuan harus berupa JPEG berukuran 320 sampai 1920 piksel.");
    }

    return $imageBytes;
}
