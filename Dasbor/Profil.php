<?php
require_once __DIR__ . "/Student_Auth.php";
$activeStudentPage = "profile";
$photoPath = (string) ($studentProfile["PhotoPath"] ?? "");
$hasProfilePhoto = preg_match('/\AUploads\/Mahasiswa\/[a-f0-9]{32}\.(jpg|png|webp)\z/i', $photoPath) === 1;
require __DIR__ . "/Student_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">AKUN MAHASISWA</p>
    <h1>Profil dan biodata</h1>
    <p>Perbarui informasi kontak dan foto profil. NIM dan NIK tidak dapat diubah dari halaman ini.</p>
</section>

<section class="student-content-panel">
    <div class="student-profile-layout">
        <aside class="student-profile-photo">
            <?php if ($hasProfilePhoto): ?>
                <img src="../<?= studentEscape($photoPath) ?>" alt="Foto profil mahasiswa" />
            <?php else: ?>
                <div class="student-profile-photo-placeholder" aria-label="Belum ada foto profil">
                    <?= studentEscape(mb_substr($studentDisplayName, 0, 1, "UTF-8")) ?>
                </div>
            <?php endif; ?>
            <p><?= studentEscape($studentDisplayName) ?></p>
            <span>NIM <?= studentEscape($studentProfile["NIM"] ?? $studentUsername) ?></span>
        </aside>

        <form id="student-profile-form" class="student-profile-form" enctype="multipart/form-data">
            <div class="student-profile-readonly">
                <label>NIM
                    <input type="text" value="<?= studentEscape($studentProfile["NIM"] ?? $studentUsername) ?>" readonly />
                </label>
                <label>NIK
                    <input type="text" value="<?= studentEscape($studentProfile["NIK"] ?? "") ?>" readonly />
                </label>
            </div>
            <label for="profile-full-name">Nama lengkap
                <input id="profile-full-name" name="full_name" type="text" maxlength="100" required
                       value="<?= studentEscape($studentProfile["FullName"] ?? "") ?>" />
            </label>
            <label for="profile-email">Email
                <input id="profile-email" name="email" type="email" maxlength="254" required
                       value="<?= studentEscape($studentProfile["Email"] ?? "") ?>" />
            </label>
            <label for="profile-gender">Jenis kelamin
                <select id="profile-gender" name="gender" required>
                    <option value="">Pilih jenis kelamin</option>
                    <?php foreach (["Laki-laki", "Perempuan", "Lainnya"] as $gender): ?>
                        <option value="<?= studentEscape($gender) ?>"
                            <?= ($studentProfile["Gender"] ?? "") === $gender ? "selected" : "" ?>>
                            <?= studentEscape($gender) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="profile-country">Negara
                <input id="profile-country" name="country" type="text" maxlength="100" required
                       value="<?= studentEscape($studentProfile["Country"] ?? "") ?>" />
            </label>
            <div class="student-profile-readonly">
                <label for="profile-province">Provinsi
                    <input id="profile-province" name="province" type="text" maxlength="100" required
                           value="<?= studentEscape($studentProfile["Province"] ?? "") ?>" />
                </label>
                <label for="profile-regency">Kabupaten/kota
                    <input id="profile-regency" name="regency" type="text" maxlength="100" required
                           value="<?= studentEscape($studentProfile["Regency"] ?? "") ?>" />
                </label>
                <label for="profile-district">Kecamatan
                    <input id="profile-district" name="district" type="text" maxlength="100" required
                           value="<?= studentEscape($studentProfile["District"] ?? "") ?>" />
                </label>
                <label for="profile-village">Desa/kelurahan
                    <input id="profile-village" name="village" type="text" maxlength="100" required
                           value="<?= studentEscape($studentProfile["Village"] ?? "") ?>" />
                </label>
            </div>
            <label for="profile-photo">Foto profil baru (opsional)
                <input id="profile-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" />
                <small class="form-help">JPG, PNG, atau WebP, maksimal 3 MB.</small>
            </label>
            <input type="hidden" name="csrf_token"
                   value="<?= studentEscape($studentCsrfToken) ?>" />
            <button class="home-button" id="save-profile-button" type="submit">Simpan perubahan</button>
            <p class="community-data-status" id="profile-status" role="status"></p>
        </form>
    </div>
</section>

<script>
    const profileForm = document.getElementById("student-profile-form");
    const profileStatus = document.getElementById("profile-status");
    const saveProfileButton = document.getElementById("save-profile-button");

    profileForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!profileForm.reportValidity()) return;
        saveProfileButton.disabled = true;
        profileStatus.textContent = "Menyimpan perubahan...";
        try {
            const response = await fetch("../Koneksi/Update_Student_Profile.php", {
                method: "POST",
                body: new FormData(profileForm),
                headers: { Accept: "application/json" }
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message);
            profileStatus.textContent = result.message;
            window.location.reload();
        } catch (error) {
            profileStatus.textContent = error.message || "Profil tidak dapat diperbarui.";
        } finally {
            saveProfileButton.disabled = false;
        }
    });
</script>
<?php require __DIR__ . "/Student_Footer.php"; ?>
