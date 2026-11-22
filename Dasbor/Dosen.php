<?php
require_once __DIR__ . "/Dosen_Auth.php";
$activeDosenPage = "rooms";
$courseOverview = [];
$overviewStatement = mysqli_prepare($conn,
    "SELECT schedules.`CourseName`, COUNT(DISTINCT attendance.`Username`) AS `TotalMahasiswa`,
            COUNT(attendance.`Id`) AS `TotalCatatan`,
            SUM(CASE WHEN attendance.`Status` = 'Hadir' THEN 1 ELSE 0 END) AS `TotalHadir`
     FROM `dosen_course_assignments` AS assignments
     INNER JOIN `class_schedules` AS schedules ON schedules.`Id` = assignments.`ScheduleId`
     LEFT JOIN `course_attendance` AS attendance ON attendance.`ScheduleId` = schedules.`Id`
     WHERE assignments.`DosenUsername` = ?
     GROUP BY schedules.`Id`, schedules.`CourseName`
     ORDER BY schedules.`CourseName`"
);
mysqli_stmt_bind_param($overviewStatement, "s", $dosenUsername);
mysqli_stmt_execute($overviewStatement);
$overviewResult = mysqli_stmt_get_result($overviewStatement);
$courseOverview = mysqli_fetch_all($overviewResult, MYSQLI_ASSOC);
mysqli_stmt_close($overviewStatement);
require __DIR__ . "/Dosen_Header.php";
?>
<section class="student-page-heading">
    <p class="home-eyebrow">PANEL DOSEN</p>
    <h1>Dashboard dosen</h1>
    <p>Kelola akses ruangan dan pantau kehadiran mahasiswa pada setiap mata kuliah.</p>
    <p><a class="home-button" href="Dosen_Kehadiran.php">Kelola kehadiran mata kuliah</a></p>
</section>

<section class="student-content-panel">
    <div class="student-panel-heading"><div><p class="home-eyebrow">MONITORING AKADEMIK</p><h2>Ringkasan mata kuliah yang diampu</h2></div><a href="Dosen_Kehadiran.php">Kelola kehadiran</a></div>
    <?php if (!$courseOverview): ?>
        <p class="admin-empty">Belum ada mata kuliah yang ditugaskan kepada akun dosen ini.</p>
    <?php else: ?>
        <div class="attendance-table-wrap"><table class="attendance-table"><thead><tr><th>Mata kuliah</th><th>Mahasiswa tercatat</th><th>Total catatan</th><th>Persentase hadir</th></tr></thead><tbody>
        <?php foreach ($courseOverview as $course): $total = (int)$course["TotalCatatan"]; $present = (int)$course["TotalHadir"]; $percent = $total ? round($present / $total * 100, 1) : 0; ?>
            <tr><td><?= dosenEscape($course["CourseName"]) ?></td><td><?= (int)$course["TotalMahasiswa"] ?></td><td><?= $total ?></td><td><strong><?= number_format($percent, 1) ?>%</strong><?php if ($total > 0 && $percent < 75): ?><div class="form-help" style="color:#b45309;font-weight:700">⚠ Di bawah 75%</div><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<section class="student-page-heading">
    <p class="home-eyebrow">PANEL DOSEN</p>
    <h1>Kontrol akses ruangan</h1>
    <p>Buka atau kunci ruangan untuk mengatur akses mahasiswa melalui sidik jari dan pengenalan wajah.</p>
</section>

<section class="student-content-panel">
    <div id="network-status-banner" class="network-status-banner" hidden></div>
    <p id="room-status" class="room-status" role="status">Memuat daftar ruangan...</p>
    <section id="room-list" class="room-grid" aria-label="Daftar ruangan"></section>
</section>

<script>
    const roomCsrfToken = <?= json_encode($roomCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const roomList = document.getElementById("room-list");
    const roomStatus = document.getElementById("room-status");
    const networkBanner = document.getElementById("network-status-banner");

    function updateNetwork() {
        if (!navigator.onLine) {
            networkBanner.textContent = "⚠️ Koneksi offline. Kontrol ruangan memerlukan koneksi internet aktif.";
            networkBanner.className = "network-status-banner offline";
            networkBanner.hidden = false;
        } else {
            networkBanner.textContent = "✅ Kembali online.";
            networkBanner.className = "network-status-banner online";
            networkBanner.hidden = false;
            setTimeout(() => { networkBanner.hidden = true; }, 3000);
        }
    }
    window.addEventListener("online", updateNetwork);
    window.addEventListener("offline", updateNetwork);
    if (!navigator.onLine) updateNetwork();

    async function loadRooms() {
        try {
            const response = await fetch("../Koneksi/Room.php", { headers: { Accept: "application/json" } });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message);
            renderRooms(result.rooms);
        } catch (error) {
            roomStatus.textContent = error.message;
        }
    }

    function renderRooms(rooms) {
        roomStatus.textContent = `${rooms.length} ruangan tersedia.`;
        roomList.replaceChildren();
        rooms.forEach((room) => {
            const locked = room.RoomStatus === "locked";
            const card = document.createElement("article");
            card.className = "room-card";
            const details = document.createElement("div");
            const label = document.createElement("p");
            label.className = "room-label";
            label.textContent = "RUANGAN";
            const title = document.createElement("h2");
            title.textContent = room.RoomName;
            const badge = document.createElement("span");
            badge.className = `room-badge ${locked ? "is-locked" : "is-open"}`;
            badge.textContent = locked ? "Terkunci" : "Terbuka";

            const radiusInfo = document.createElement("p");
            radiusInfo.style.fontSize = "12px";
            radiusInfo.style.color = "#60708f";
            radiusInfo.style.margin = "6px 0 0";
            radiusInfo.textContent = `Radius Geofencing: ${room.RadiusMeters || 100} meter`;

            details.append(label, title, badge, radiusInfo);

            const button = document.createElement("button");
            button.type = "button";
            button.className = `room-action ${locked ? "open-action" : "lock-action"}`;
            button.textContent = locked ? "Buka ruangan" : "Kunci ruangan";
            button.addEventListener("click", () => setRoomStatus(room.RoomName, locked ? "open" : "locked"));
            card.append(details, button);
            roomList.appendChild(card);
        });
    }

    async function setRoomStatus(roomName, roomStatusValue) {
        roomStatus.textContent = "Memperbarui status ruangan...";
        const form = new FormData();
        form.set("action", "set_room_status");
        form.set("room_name", roomName);
        form.set("room_status", roomStatusValue);
        form.set("csrf_token", roomCsrfToken);
        try {
            const response = await fetch("../Koneksi/Room.php", {
                method: "POST",
                body: form,
                headers: { Accept: "application/json" }
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message);
            roomStatus.textContent = result.message;
            await loadRooms();
        } catch (error) {
            roomStatus.textContent = error.message;
        }
    }

    loadRooms();
</script>
<?php require __DIR__ . "/Dosen_Footer.php"; ?>
