<?php
// Chat_Widget.php - Floating chat widget untuk Mahasiswa dan Dosen menghubungi Admin
if (!isset($_SESSION["username"])) {
    return;
}
?>
<!-- Tombol Floating Chat Admin -->
<button type="button" id="chat-fab-btn" class="chat-fab-btn" aria-label="Buka Chat Admin">
    <svg class="chat-fab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
    </svg>
    <span class="chat-fab-text">Chat Admin</span>
    <span id="chat-fab-badge" class="chat-fab-badge" hidden>0</span>
</button>

<!-- Jendela Obrolan Floating -->
<aside id="chat-window" class="chat-window" aria-label="Jendela Chat Admin" hidden>
    <header class="chat-header">
        <div class="chat-header-info">
            <div class="chat-admin-avatar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
            </div>
            <div>
                <h3>Pusat Bantuan Admin</h3>
                <span class="chat-status-indicator">
                    <span class="chat-status-dot"></span> Operator Siaga
                </span>
            </div>
        </div>
        <button type="button" id="chat-close-btn" class="chat-close-btn" aria-label="Tutup Chat">&times;</button>
    </header>

    <div id="chat-messages" class="chat-messages-container">
        <div class="chat-intro-card">
            <p><strong>Selamat datang di Layanan Bantuan SIABAK.</strong></p>
            <p>Punya kendala dengan akun, verifikasi absensi, jadwal kuliah, atau ruangan? Kirimkan pesan Anda di bawah, admin kami akan segera merespons.</p>
        </div>
        <div id="chat-stream" class="chat-stream"></div>
    </div>

    <form id="chat-form" class="chat-input-form">
        <input type="text" id="chat-input-field" class="chat-input-field" placeholder="Ketik pesan untuk admin..." maxlength="1000" autocomplete="off" required />
        <button type="submit" id="chat-send-btn" class="chat-send-btn" aria-label="Kirim Pesan">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                <line x1="22" y1="2" x2="11" y2="13"></line>
                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
            </svg>
        </button>
    </form>
</aside>

<script>
(function() {
    const fabBtn = document.getElementById("chat-fab-btn");
    const chatWindow = document.getElementById("chat-window");
    const closeBtn = document.getElementById("chat-close-btn");
    const badge = document.getElementById("chat-fab-badge");
    const stream = document.getElementById("chat-stream");
    const messagesContainer = document.getElementById("chat-messages");
    const chatForm = document.getElementById("chat-form");
    const inputField = document.getElementById("chat-input-field");
    const sendBtn = document.getElementById("chat-send-btn");

    let isChatOpen = false;
    let pollInterval = null;
    let lastMessageCount = 0;

    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
    }

    function formatTime(dateStr) {
        try {
            const date = new Date(dateStr);
            return date.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
        } catch (e) {
            return "";
        }
    }

    async function checkUnreadCount() {
        if (isChatOpen) return;
        try {
            const res = await fetch("../Koneksi/Admin_Chat.php?action=get_unread_count", { headers: { Accept: "application/json" } });
            const data = await res.json();
            if (data.success && data.unread_count > 0) {
                badge.textContent = data.unread_count > 9 ? "9+" : data.unread_count;
                badge.hidden = false;
            } else {
                badge.hidden = true;
            }
        } catch (e) {}
    }

    async function loadMessages(scrollToBottom = false) {
        try {
            const res = await fetch("../Koneksi/Admin_Chat.php?action=get_messages", { headers: { Accept: "application/json" } });
            const data = await res.json();
            if (!data.success) return;

            const messages = data.messages || [];
            if (messages.length !== lastMessageCount) {
                renderMessages(messages);
                lastMessageCount = messages.length;
                if (scrollToBottom || messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight < 120) {
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                }
            }
            badge.hidden = true;
        } catch (e) {}
    }

    function renderMessages(messages) {
        stream.innerHTML = "";
        messages.forEach((msg) => {
            const isUser = msg.SenderRole !== "admin";
            const bubbleWrap = document.createElement("div");
            bubbleWrap.className = isUser ? "chat-row chat-row-user" : "chat-row chat-row-admin";

            const bubble = document.createElement("div");
            bubble.className = isUser ? "chat-bubble chat-bubble-user" : "chat-bubble chat-bubble-admin";
            bubble.innerHTML = `
                <div class="chat-bubble-text">${escapeHtml(msg.Message)}</div>
                <span class="chat-bubble-time">${formatTime(msg.CreatedAt)}</span>
            `;

            bubbleWrap.appendChild(bubble);
            stream.appendChild(bubbleWrap);
        });
    }

    function toggleChat(open) {
        isChatOpen = open;
        chatWindow.hidden = !open;
        if (open) {
            fabBtn.classList.add("chat-fab-active");
            badge.hidden = true;
            loadMessages(true);
            inputField.focus();
            if (!pollInterval) {
                pollInterval = setInterval(loadMessages, 3500);
            }
        } else {
            fabBtn.classList.remove("chat-fab-active");
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }
    }

    fabBtn.addEventListener("click", () => toggleChat(!isChatOpen));
    closeBtn.addEventListener("click", () => toggleChat(false));

    chatForm.addEventListener("submit", async (e) => {
        e.preventDefault();
        const text = inputField.value.trim();
        if (!text) return;

        sendBtn.disabled = true;
        const formData = new FormData();
        formData.set("action", "send_message");
        formData.set("message", text);

        try {
            const res = await fetch("../Koneksi/Admin_Chat.php", {
                method: "POST",
                body: formData,
                headers: { Accept: "application/json" }
            });
            const data = await res.json();
            if (res.ok && data.success) {
                inputField.value = "";
                await loadMessages(true);
            } else {
                alert(data.message || "Gagal mengirim pesan.");
            }
        } catch (err) {
            alert("Gagal terhubung ke server chat.");
        } finally {
            sendBtn.disabled = false;
            inputField.focus();
        }
    });

    // Pengecekan awal pesan belum terbaca & interval background
    checkUnreadCount();
    setInterval(checkUnreadCount, 10000);
})();
</script>
