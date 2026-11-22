<?php
require_once __DIR__ . "/Dosen_Auth.php";
$activeDosenPage = "profile";

$photoPath = (string) ($dosenProfile["PhotoPath"] ?? "");
$hasProfilePhoto = preg_match('/\AUploads\/Dosen\/[a-f0-9]{32}\.(jpg|png|webp)\z/i', $photoPath) === 1
    || preg_match('/\AUploads\/Mahasiswa\/[a-f0-9]{32}\.(jpg|png|webp)\z/i', $photoPath) === 1;

require __DIR__ . "/Dosen_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">AKUN DOSEN</p>
    <h1>Profil dan biodata pengajar</h1>
    <p>Perbarui informasi identitas, kontak, alamat, dan foto profil Anda. Username tidak dapat diubah.</p>
</section>

<section class="student-content-panel">
    <div class="student-profile-layout">
        <aside class="student-profile-photo">
            <?php if ($hasProfilePhoto && is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $photoPath))): ?>
                <img src="../<?= dosenEscape($photoPath) ?>" alt="Foto profil dosen" />
            <?php else: ?>
                <div class="student-profile-photo-placeholder" aria-label="Belum ada foto profil">
                    <?= dosenEscape(mb_substr($dosenDisplayName, 0, 1, "UTF-8")) ?>
                </div>
            <?php endif; ?>
            <p><?= dosenEscape($dosenDisplayName) ?></p>
            <span>NIP: <?= dosenEscape(!empty($dosenProfile["NIP"]) ? $dosenProfile["NIP"] : "Belum diisi") ?></span>
        </aside>

        <form id="dosen-profile-form" class="student-profile-form" enctype="multipart/form-data">
            <div class="student-profile-readonly">
                <label>Username
                    <input type="text" value="<?= dosenEscape($dosenUsername) ?>" readonly />
                </label>
                <label for="profile-nip">NIP (Nomor Induk Pegawai)
                    <input id="profile-nip" name="nip" type="text" maxlength="50" placeholder="Contoh: 19850101..."
                           value="<?= dosenEscape($dosenProfile["NIP"] ?? "") ?>" />
                </label>
            </div>

            <label for="profile-full-name">Nama lengkap dan gelar
                <input id="profile-full-name" name="full_name" type="text" maxlength="100" required
                       value="<?= dosenEscape($dosenProfile["FullName"] ?? "") ?>" />
            </label>

            <label for="profile-email">Alamat email
                <input id="profile-email" name="email" type="email" maxlength="254" required
                       value="<?= dosenEscape($dosenProfile["Email"] ?? "") ?>" />
            </label>

            <label for="profile-gender">Jenis kelamin
                <select id="profile-gender" name="gender" required>
                    <option value="">Pilih jenis kelamin</option>
                    <?php foreach (["Laki-laki", "Perempuan", "Lainnya"] as $gender): ?>
                        <option value="<?= dosenEscape($gender) ?>"
                            <?= ($dosenProfile["Gender"] ?? "") === $gender ? "selected" : "" ?>>
                            <?= dosenEscape($gender) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label for="profile-country">Negara
                <input id="profile-country" name="country" type="text" maxlength="100" required
                       value="<?= dosenEscape(!empty($dosenProfile["Country"]) ? $dosenProfile["Country"] : "Indonesia") ?>" />
            </label>

            <div class="student-profile-readonly">
                <label for="profile-province">Provinsi
                    <input id="profile-province" name="province" type="text" maxlength="100" required
                           placeholder="Contoh: Gorontalo / Jawa Barat"
                           value="<?= dosenEscape($dosenProfile["Province"] ?? "") ?>" />
                </label>
                <label for="profile-regency">Kabupaten/kota
                    <input id="profile-regency" name="regency" type="text" maxlength="100" required
                           placeholder="Contoh: Kota Gorontalo"
                           value="<?= dosenEscape($dosenProfile["Regency"] ?? "") ?>" />
                </label>
                <label for="profile-district">Kecamatan
                    <input id="profile-district" name="district" type="text" maxlength="100" required
                           placeholder="Contoh: Kota Tengah"
                           value="<?= dosenEscape($dosenProfile["District"] ?? "") ?>" />
                </label>
                <label for="profile-village">Desa/kelurahan
                    <input id="profile-village" name="village" type="text" maxlength="100" required
                           placeholder="Contoh: Dulomo"
                           value="<?= dosenEscape($dosenProfile["Village"] ?? "") ?>" />
                </label>
            </div>

            <label for="profile-photo">Foto profil baru (opsional)
                <input id="profile-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" />
                <small class="form-help">Format JPG, PNG, atau WebP, ukuran maksimal 3 MB.</small>
            </label>

            <input type="hidden" name="csrf_token" value="<?= dosenEscape($roomCsrfToken) ?>" />

            <button class="home-button" id="save-dosen-profile-btn" type="submit">
                Simpan perubahan profil
            </button>

            <p class="community-data-status" id="dosen-profile-status" role="status"></p>
        </form>
    </div>
</section>

<script>
    const dosenForm = document.getElementById("dosen-profile-form");
    const dosenStatus = document.getElementById("dosen-profile-status");
    const saveBtn = document.getElementById("save-dosen-profile-btn");

    dosenForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!dosenForm.reportValidity()) return;

        saveBtn.disabled = true;
        dosenStatus.textContent = "Menyimpan perubahan profil...";
        dosenStatus.style.color = "#182053";

        try {
            const response = await fetch("../Koneksi/Update_Dosen_Profile.php", {
                method: "POST",
                body: new FormData(dosenForm),
                headers: { Accept: "application/json" }
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message);

            dosenStatus.textContent = result.message;
            dosenStatus.style.color = "#0e6251";
            setTimeout(() => {
                window.location.reload();
            }, 800);
        } catch (error) {
            dosenStatus.textContent = error.message || "Profil tidak dapat diperbarui.";
            dosenStatus.style.color = "#a93226";
        } finally {
            saveBtn.disabled = false;
        }
    });
</script>
<?php require __DIR__ . "/Dosen_Footer.php"; ?>
