<?php
header("Content-Type: application/json; charset=utf-8");
session_start();

class WebAuthnInvalidResponse extends RuntimeException
{
}

function webauthnRespond($statusCode, $success, $message, $extra = [])
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function webauthnBase64UrlEncode($data)
{
    return rtrim(strtr(base64_encode($data), "+/", "-_"), "=");
}

function webauthnBase64UrlDecode($value)
{
    if (!is_string($value) || $value === "" || !preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
        throw new WebAuthnInvalidResponse("Data autentikator tidak valid.");
    }
    $decoded = base64_decode(strtr($value, "-_", "+/") . str_repeat("=", (4 - strlen($value) % 4) % 4), true);
    if ($decoded === false) {
        throw new WebAuthnInvalidResponse("Data autentikator tidak valid.");
    }
    return $decoded;
}

function webauthnExpectedOrigin()
{
    $configuredOrigin = getenv("SIABAK_WEBAUTHN_ORIGIN");
    if (is_string($configuredOrigin) && $configuredOrigin !== "") {
        $origin = $configuredOrigin;
    } else {
        $host = $_SERVER["HTTP_HOST"] ?? "";
        if (!is_string($host) || $host === "" || preg_match('/[\r\n\/\\\\]/', $host)) {
            throw new RuntimeException("Asal WebAuthn belum dikonfigurasi.");
        }
        $hostParts = parse_url("http://" . $host);
        if (!is_array($hostParts) || !isset($hostParts["host"]) || isset($hostParts["user"]) || isset($hostParts["pass"])) {
            throw new RuntimeException("Host aplikasi tidak valid untuk WebAuthn.");
        }
        $requestOrigin = $_SERVER["HTTP_ORIGIN"] ?? "";
        if (is_string($requestOrigin) && $requestOrigin !== "") {
            $requestOriginParts = parse_url($requestOrigin);
            if (
                !is_array($requestOriginParts) ||
                !isset($requestOriginParts["host"]) ||
                strtolower($requestOriginParts["host"]) !== strtolower($hostParts["host"] ?? "")
            ) {
                throw new RuntimeException("Origin permintaan tidak cocok dengan host aplikasi.");
            }
            $origin = $requestOrigin;
        } else {
            $scheme = (!empty($_SERVER["HTTPS"]) && strtolower((string) $_SERVER["HTTPS"]) !== "off") ? "https" : "http";
            $origin = $scheme . "://" . $host;
        }
    }

    $parts = parse_url($origin);
    if (
        !is_array($parts) ||
        !isset($parts["scheme"], $parts["host"]) ||
        !in_array(strtolower($parts["scheme"]), ["https", "http"], true) ||
        isset($parts["user"]) ||
        isset($parts["pass"]) ||
        isset($parts["query"]) ||
        isset($parts["fragment"]) ||
        (isset($parts["path"]) && $parts["path"] !== "" && $parts["path"] !== "/")
    ) {
        throw new RuntimeException("Asal WebAuthn tidak valid.");
    }

    $originHost = strtolower($parts["host"]);
    $rpId = getenv("SIABAK_WEBAUTHN_RP_ID");
    if (!is_string($rpId) || $rpId === "") {
        $rpId = $originHost;
    }
    $rpId = strtolower($rpId);
    if (
        !preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\z/', $rpId) ||
        !preg_match('/\A[a-z0-9.-]+\z/', $originHost) ||
        ($originHost !== $rpId && !str_ends_with($originHost, "." . $rpId))
    ) {
        throw new RuntimeException("RP ID WebAuthn tidak cocok dengan domain aplikasi.");
    }

    $port = isset($parts["port"]) ? ":" . $parts["port"] : "";
    return [strtolower($parts["scheme"]) . "://" . $originHost . $port, $rpId];
}

function webauthnCborLength($additional, $data, &$offset)
{
    if ($additional < 24) {
        return $additional;
    }
    $sizes = [24 => 1, 25 => 2, 26 => 4];
    if (!isset($sizes[$additional])) {
        throw new WebAuthnInvalidResponse("Data CBOR autentikator tidak didukung.");
    }
    $size = $sizes[$additional];
    if ($offset + $size > strlen($data)) {
        throw new WebAuthnInvalidResponse("Data CBOR autentikator terpotong.");
    }
    $bytes = substr($data, $offset, $size);
    $offset += $size;
    if ($size === 1) {
        return ord($bytes);
    }
    if ($size === 2) {
        return unpack("n", $bytes)[1];
    }
    return unpack("N", $bytes)[1];
}

function webauthnCborDecode($data, &$offset, $depth = 0)
{
    if ($depth > 16 || $offset >= strlen($data)) {
        throw new WebAuthnInvalidResponse("Data CBOR autentikator tidak valid.");
    }
    $initial = ord($data[$offset++]);
    $major = $initial >> 5;
    $additional = $initial & 31;

    if ($major === 7) {
        if ($additional === 20) return false;
        if ($additional === 21) return true;
        if ($additional === 22) return null;
        throw new WebAuthnInvalidResponse("Data CBOR autentikator tidak didukung.");
    }
    $length = webauthnCborLength($additional, $data, $offset);
    if ($major === 0) return $length;
    if ($major === 1) return -1 - $length;
    if ($major === 2 || $major === 3) {
        if ($length > strlen($data) - $offset) {
            throw new WebAuthnInvalidResponse("Data CBOR autentikator terpotong.");
        }
        $value = substr($data, $offset, $length);
        $offset += $length;
        return $value;
    }
    if ($major === 4) {
        if ($length > 128) throw new WebAuthnInvalidResponse("Data CBOR autentikator terlalu besar.");
        $values = [];
        for ($index = 0; $index < $length; $index++) {
            $values[] = webauthnCborDecode($data, $offset, $depth + 1);
        }
        return $values;
    }
    if ($major === 5) {
        if ($length > 128) throw new WebAuthnInvalidResponse("Data CBOR autentikator terlalu besar.");
        $values = [];
        for ($index = 0; $index < $length; $index++) {
            $key = webauthnCborDecode($data, $offset, $depth + 1);
            $value = webauthnCborDecode($data, $offset, $depth + 1);
            if (!is_int($key) && !is_string($key)) {
                throw new WebAuthnInvalidResponse("Kunci CBOR autentikator tidak valid.");
            }
            $values[$key] = $value;
        }
        return $values;
    }
    throw new WebAuthnInvalidResponse("Data CBOR autentikator tidak didukung.");
}

function webauthnReadClientData($encoded, $expectedType, $challenge, $expectedOrigin)
{
    $raw = webauthnBase64UrlDecode($encoded);
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data["type"] ?? null) !== $expectedType) {
        throw new WebAuthnInvalidResponse("Jenis respons autentikator tidak sesuai.");
    }
    if (($data["challenge"] ?? null) !== $challenge) {
        throw new WebAuthnInvalidResponse("Challenge browser berbeda dari challenge sesi server. Muat ulang halaman lalu coba kembali.");
    }
    if (($data["origin"] ?? null) !== $expectedOrigin) {
        throw new WebAuthnInvalidResponse("Origin browser tidak cocok dengan konfigurasi server. Periksa HTTPS, proxy, atau SIABAK_WEBAUTHN_ORIGIN.");
    }
    if (($data["crossOrigin"] ?? false) !== false) {
        throw new WebAuthnInvalidResponse("Pendaftaran biometrik tidak diizinkan dari iframe lintas origin.");
    }
    return $raw;
}

function webauthnValidateAuthenticatorData($data, $rpId, $requireAttestedCredential)
{
    if (strlen($data) < 37 || !hash_equals(hash("sha256", $rpId, true), substr($data, 0, 32))) {
        throw new WebAuthnInvalidResponse("Data autentikator tidak cocok dengan aplikasi ini.");
    }
    $flags = ord($data[32]);
    if (($flags & 0x05) !== 0x05) {
        throw new WebAuthnInvalidResponse("Verifikasi perangkat atau kehadiran pengguna tidak berhasil.");
    }
    $signCount = unpack("N", substr($data, 33, 4))[1];
    if ($requireAttestedCredential && ($flags & 0x40) === 0) {
        throw new WebAuthnInvalidResponse("Kredensial perangkat tidak valid.");
    }
    return [$flags, $signCount];
}

function webauthnCosePublicKeyPem($cose)
{
    if (
        !is_array($cose) ||
        ($cose[1] ?? null) !== 2 ||
        ($cose[3] ?? null) !== -7 ||
        ($cose[-1] ?? null) !== 1 ||
        !isset($cose[-2], $cose[-3]) ||
        !is_string($cose[-2]) ||
        !is_string($cose[-3]) ||
        strlen($cose[-2]) !== 32 ||
        strlen($cose[-3]) !== 32
    ) {
        throw new WebAuthnInvalidResponse("Kunci autentikator harus menggunakan ECDSA P-256.");
    }

    $derLength = function ($length) {
        if ($length < 128) return chr($length);
        $bytes = "";
        while ($length > 0) {
            $bytes = chr($length & 255) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    };
    $der = function ($tag, $value) use ($derLength) {
        return chr($tag) . $derLength(strlen($value)) . $value;
    };
    $algorithm = $der(0x30, hex2bin("06072A8648CE3D0201") . hex2bin("06082A8648CE3D030107"));
    $publicPoint = "\x04" . $cose[-2] . $cose[-3];
    $subjectPublicKeyInfo = $der(0x30, $algorithm . $der(0x03, "\x00" . $publicPoint));
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
        . "-----END PUBLIC KEY-----";
}

function webauthnReadCredential($encodedJson)
{
    if (!is_string($encodedJson) || strlen($encodedJson) > 16000) {
        throw new WebAuthnInvalidResponse("Respons autentikator tidak valid.");
    }
    $credential = json_decode($encodedJson, true);
    if (
        !is_array($credential) ||
        ($credential["type"] ?? null) !== "public-key" ||
        !isset($credential["id"], $credential["rawId"], $credential["response"]) ||
        !is_string($credential["id"]) ||
        !is_array($credential["response"])
    ) {
        throw new WebAuthnInvalidResponse("Respons autentikator tidak lengkap.");
    }
    $rawId = webauthnBase64UrlDecode($credential["rawId"]);
    if (strlen($rawId) < 1 || strlen($rawId) > 1024 || !hash_equals($credential["id"], webauthnBase64UrlEncode($rawId))) {
        throw new WebAuthnInvalidResponse("ID kredensial autentikator tidak valid.");
    }
    return [$credential, webauthnBase64UrlEncode($rawId)];
}

function webauthnCreateOptions($username, $rpId, $credentials)
{
    return [
        "challenge" => webauthnBase64UrlEncode(random_bytes(32)),
        "rp" => ["name" => "SIABAK", "id" => $rpId],
        "user" => [
            "id" => webauthnBase64UrlEncode(hash("sha256", "SIABAK WebAuthn:" . $username, true)),
            "name" => $username,
            "displayName" => $username,
        ],
        "pubKeyCredParams" => [["type" => "public-key", "alg" => -7]],
        "authenticatorSelection" => [
            "authenticatorAttachment" => "platform",
            "residentKey" => "discouraged",
            "userVerification" => "required",
        ],
        "timeout" => 60000,
        "attestation" => "none",
        "excludeCredentials" => array_map(function ($id) {
            return ["type" => "public-key", "id" => $id];
        }, $credentials),
    ];
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_SESSION["username"]) || !is_string($_SESSION["username"])) {
    webauthnRespond(401, false, "Silakan login sebagai mahasiswa terlebih dahulu.");
}

$csrf = $_POST["csrf_token"] ?? "";
if (!is_string($csrf) || !isset($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $csrf)) {
    webauthnRespond(400, false, "Token keamanan tidak valid. Muat ulang halaman.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    require_once __DIR__ . "/Koneksi.php";
    mysqli_set_charset($conn, "utf8mb4");
    $username = $_SESSION["username"];
    $roleStatement = mysqli_prepare($conn, "SELECT `Role` FROM `login` WHERE `Username` = ? LIMIT 1");
    mysqli_stmt_bind_param($roleStatement, "s", $username);
    mysqli_stmt_execute($roleStatement);
    mysqli_stmt_bind_result($roleStatement, $role);
    $accountFound = mysqli_stmt_fetch($roleStatement);
    mysqli_stmt_close($roleStatement);
    if (!$accountFound || $role !== "mahasiswa") {
        webauthnRespond(403, false, "Autentikasi sidik jari hanya tersedia untuk akun mahasiswa.");
    }

    [$expectedOrigin, $rpId] = webauthnExpectedOrigin();
    $action = $_POST["action"] ?? "";

    if ($action === "register_options") {
        $countStatement = mysqli_prepare($conn, "SELECT COUNT(*) FROM `mahasiswa_authenticators` WHERE `Username` = ?");
        mysqli_stmt_bind_param($countStatement, "s", $username);
        mysqli_stmt_execute($countStatement);
        mysqli_stmt_bind_result($countStatement, $credentialCount);
        mysqli_stmt_fetch($countStatement);
        mysqli_stmt_close($countStatement);
        if ($credentialCount >= 5) {
            webauthnRespond(409, false, "Maksimal lima perangkat autentikator dapat didaftarkan.");
        }

        $lookup = mysqli_prepare($conn, "SELECT `CredentialId` FROM `mahasiswa_authenticators` WHERE `Username` = ?");
        mysqli_stmt_bind_param($lookup, "s", $username);
        mysqli_stmt_execute($lookup);
        $existingCredentials = mysqli_stmt_get_result($lookup);
        $credentialIds = [];
        while ($row = mysqli_fetch_assoc($existingCredentials)) {
            $credentialIds[] = $row["CredentialId"];
        }
        mysqli_stmt_close($lookup);

        $options = webauthnCreateOptions($username, $rpId, $credentialIds);
        $_SESSION["webauthn_registration_challenge"] = [
            "challenge" => $options["challenge"],
            "expires" => time() + 300,
            "origin" => $expectedOrigin,
            "rp_id" => $rpId,
        ];
        webauthnRespond(200, true, "Siapkan verifikasi biometrik perangkat.", ["publicKey" => $options]);
    }

    if ($action === "register_verify") {
        $pending = $_SESSION["webauthn_registration_challenge"] ?? null;
        unset($_SESSION["webauthn_registration_challenge"]);
        if (!is_array($pending) || ($pending["expires"] ?? 0) < time()) {
            webauthnRespond(400, false, "Pendaftaran kedaluwarsa. Silakan mulai kembali.");
        }
        [$credential, $credentialId] = webauthnReadCredential($_POST["credential"] ?? "");
        $response = $credential["response"];
        webauthnReadClientData($response["clientDataJSON"] ?? "", "webauthn.create", $pending["challenge"], $pending["origin"]);
        $attestationObject = webauthnBase64UrlDecode($response["attestationObject"] ?? "");
        $offset = 0;
        $attestation = webauthnCborDecode($attestationObject, $offset);
        if (
            $offset !== strlen($attestationObject) ||
            !is_array($attestation) ||
            ($attestation["fmt"] ?? null) !== "none" ||
            ($attestation["attStmt"] ?? null) !== [] ||
            !isset($attestation["authData"]) ||
            !is_string($attestation["authData"])
        ) {
            webauthnRespond(400, false, "Format pendaftaran perangkat tidak didukung.");
        }
        [$flags, $signCount] = webauthnValidateAuthenticatorData($attestation["authData"], $pending["rp_id"], true);
        if (($flags & 0x80) !== 0 || strlen($attestation["authData"]) < 55) {
            webauthnRespond(400, false, "Data pendaftaran perangkat tidak valid.");
        }
        $authenticatorData = $attestation["authData"];
        $credentialLength = unpack("n", substr($authenticatorData, 53, 2))[1];
        $credentialOffset = 55;
        if ($credentialLength < 1 || $credentialOffset + $credentialLength >= strlen($authenticatorData)) {
            webauthnRespond(400, false, "ID kredensial perangkat tidak valid.");
        }
        $authenticatorCredentialId = substr($authenticatorData, $credentialOffset, $credentialLength);
        if (!hash_equals($credentialId, webauthnBase64UrlEncode($authenticatorCredentialId))) {
            webauthnRespond(400, false, "ID perangkat tidak cocok dengan respons pendaftaran.");
        }
        $coseOffset = $credentialOffset + $credentialLength;
        $coseStart = $coseOffset;
        $cose = webauthnCborDecode($authenticatorData, $coseOffset);
        if ($coseOffset !== strlen($authenticatorData)) {
            webauthnRespond(400, false, "Ekstensi autentikator tidak didukung.");
        }
        webauthnCosePublicKeyPem($cose);
        $publicKeyCose = webauthnBase64UrlEncode(substr($authenticatorData, $coseStart, $coseOffset - $coseStart));

        $save = mysqli_prepare(
            $conn,
            "INSERT INTO `mahasiswa_authenticators` (`Username`, `CredentialId`, `PublicKeyCose`, `SignCount`) VALUES (?, ?, ?, ?)"
        );
        $signCountValue = (string) $signCount;
        mysqli_stmt_bind_param($save, "ssss", $username, $credentialId, $publicKeyCose, $signCountValue);
        mysqli_stmt_execute($save);
        mysqli_stmt_close($save);
        webauthnRespond(200, true, "Perangkat berhasil didaftarkan. Saat absensi, perangkat akan meminta verifikasi biometrik.");
    }

    if ($action === "authentication_options") {
        $roomName = trim((string) ($_POST["room_name"] ?? ""));
        $scheduleId = filter_var($_POST["schedule_id"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
        if ($roomName === "" || $scheduleId === false) {
            webauthnRespond(400, false, "Pilih ruangan dan jadwal sebelum memverifikasi perangkat.");
        }
        $lookup = mysqli_prepare($conn, "SELECT `CredentialId` FROM `mahasiswa_authenticators` WHERE `Username` = ?");
        mysqli_stmt_bind_param($lookup, "s", $username);
        mysqli_stmt_execute($lookup);
        $result = mysqli_stmt_get_result($lookup);
        $credentials = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $credentials[] = $row["CredentialId"];
        }
        mysqli_stmt_close($lookup);
        if (!$credentials) {
            webauthnRespond(409, false, "Belum ada perangkat biometrik terdaftar. Daftarkan sidik jari/perangkat dari menu Biometrik terlebih dahulu.");
        }

        $challenge = webauthnBase64UrlEncode(random_bytes(32));
        $_SESSION["webauthn_authentication_challenge"] = [
            "challenge" => $challenge,
            "expires" => time() + 120,
            "origin" => $expectedOrigin,
            "rp_id" => $rpId,
            "room_name" => $roomName,
            "schedule_id" => (int) $scheduleId,
        ];
        $allowCredentials = array_map(function ($id) {
            return ["type" => "public-key", "id" => $id];
        }, $credentials);
        webauthnRespond(200, true, "Konfirmasi biometrik pada perangkat Anda.", [
            "publicKey" => [
                "challenge" => $challenge,
                "rpId" => $rpId,
                "allowCredentials" => $allowCredentials,
                "userVerification" => "required",
                "timeout" => 60000,
            ],
        ]);
    }

    if ($action === "authentication_verify") {
        $pending = $_SESSION["webauthn_authentication_challenge"] ?? null;
        unset($_SESSION["webauthn_authentication_challenge"]);
        if (!is_array($pending) || ($pending["expires"] ?? 0) < time()) {
            webauthnRespond(400, false, "Verifikasi perangkat kedaluwarsa. Silakan coba kembali.");
        }
        [$credential, $credentialId] = webauthnReadCredential($_POST["credential"] ?? "");
        $response = $credential["response"];
        $clientData = webauthnReadClientData($response["clientDataJSON"] ?? "", "webauthn.get", $pending["challenge"], $pending["origin"]);

        $lookup = mysqli_prepare(
            $conn,
            "SELECT `PublicKeyCose`, `SignCount` FROM `mahasiswa_authenticators` WHERE `Username` = ? AND `CredentialId` = ? LIMIT 1"
        );
        mysqli_stmt_bind_param($lookup, "ss", $username, $credentialId);
        mysqli_stmt_execute($lookup);
        mysqli_stmt_bind_result($lookup, $publicKeyCose, $storedSignCount);
        $credentialFound = mysqli_stmt_fetch($lookup);
        mysqli_stmt_close($lookup);
        if (!$credentialFound) {
            webauthnRespond(403, false, "Perangkat ini tidak terdaftar pada akun Anda.");
        }

        $authenticatorData = webauthnBase64UrlDecode($response["authenticatorData"] ?? "");
        [$flags, $signCount] = webauthnValidateAuthenticatorData($authenticatorData, $pending["rp_id"], false);
        if (($flags & 0x40) !== 0) {
            webauthnRespond(400, false, "Data autentikator tidak valid.");
        }
        if ($storedSignCount > 0 && $signCount > 0 && $signCount <= $storedSignCount) {
            webauthnRespond(403, false, "Penghitung keamanan perangkat tidak valid. Daftarkan ulang perangkat.");
        }

        $coseJson = webauthnBase64UrlDecode($publicKeyCose);
        $coseOffset = 0;
        $cose = webauthnCborDecode($coseJson, $coseOffset);
        if ($coseOffset !== strlen($coseJson)) {
            webauthnRespond(500, false, "Kunci autentikator tersimpan tidak valid.");
        }
        $publicKey = openssl_pkey_get_public(webauthnCosePublicKeyPem($cose));
        if ($publicKey === false) {
            webauthnRespond(500, false, "Kunci autentikator tidak dapat dibaca oleh server.");
        }
        $signature = webauthnBase64UrlDecode($response["signature"] ?? "");
        $signedData = $authenticatorData . hash("sha256", $clientData, true);
        $signatureValid = openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($publicKey);
        if ($signatureValid !== 1) {
            webauthnRespond(403, false, "Tanda tangan perangkat tidak valid.");
        }

        $userHandle = $response["userHandle"] ?? null;
        $expectedUserHandle = hash("sha256", "SIABAK WebAuthn:" . $username, true);
        if ($userHandle !== null && $userHandle !== "" && !hash_equals($expectedUserHandle, webauthnBase64UrlDecode($userHandle))) {
            webauthnRespond(403, false, "Akun autentikator tidak cocok.");
        }

        $updateCount = mysqli_prepare(
            $conn,
            "UPDATE `mahasiswa_authenticators` SET `SignCount` = GREATEST(`SignCount`, ?) WHERE `Username` = ? AND `CredentialId` = ?"
        );
        $signCountValue = (string) $signCount;
        mysqli_stmt_bind_param($updateCount, "sss", $signCountValue, $username, $credentialId);
        mysqli_stmt_execute($updateCount);
        mysqli_stmt_close($updateCount);

        $proof = bin2hex(random_bytes(32));
        $_SESSION["fingerprint_attendance_proof"] = [
            "proof" => $proof,
            "expires" => time() + 120,
            "room_name" => $pending["room_name"],
            "schedule_id" => $pending["schedule_id"],
        ];
        webauthnRespond(200, true, "Verifikasi biometrik perangkat berhasil.", ["proof" => $proof]);
    }

    if ($action === "delete_credentials") {
        $delete = mysqli_prepare($conn, "DELETE FROM `mahasiswa_authenticators` WHERE `Username` = ?");
        mysqli_stmt_bind_param($delete, "s", $username);
        mysqli_stmt_execute($delete);
        $deleted = mysqli_stmt_affected_rows($delete);
        mysqli_stmt_close($delete);
        unset($_SESSION["fingerprint_attendance_proof"]);
        webauthnRespond(200, true, $deleted > 0 ? "Perangkat biometrik berhasil dihapus." : "Tidak ada perangkat biometrik yang terdaftar.");
    }

    webauthnRespond(400, false, "Aksi autentikator tidak dikenali.");
} catch (WebAuthnInvalidResponse $error) {
    webauthnRespond(400, false, $error->getMessage());
} catch (Throwable $error) {
    error_log("WebAuthn error: " . $error->getMessage());
    webauthnRespond(500, false, "Verifikasi perangkat gagal diproses. Pastikan migrasi autentikator sudah dijalankan dan konfigurasi HTTPS benar.");
}
