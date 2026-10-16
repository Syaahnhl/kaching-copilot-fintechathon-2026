<div id="kaching-chatbot-container" class="kaching-chatbot-container">
    <!-- Chat Window -->
    <div id="kaching-chatbot-window" class="kaching-chatbot-window kaching-chatbot-hidden">
        <div class="kaching-chatbot-header">
            <div class="kaching-chatbot-header-title">
                <div class="kaching-chatbot-avatar">
                    {{-- Ikon Robot AI (Material Symbols) --}}
                    <svg fill="currentColor" viewBox="0 0 24 24" width="22" height="22">
                        <path d="M13 2.05V4h2a2 2 0 1 1 0 4h-1v1h2a7 7 0 0 1 7 7v2h-2v-2a5 5 0 0 0-5-5H8a5 5 0 0 0-5 5v2H1v-2a7 7 0 0 1 7-7h2V8H9a2 2 0 1 1 0-4h2V2.05A1 1 0 0 1 12 1a1 1 0 0 1 1 1.05zM8 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm8 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-4 4a1 1 0 0 0-1 1v2a1 1 0 0 0 2 0v-2a1 1 0 0 0-1-1z" />
                    </svg>
                </div>
                <!-- Flex container for Text & Online Mark -->
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span>Kaching AI Assistant</span>
                    <div class="kaching-online-indicator"></div>
                </div>
            </div>
            <div class="kaching-chatbot-header-actions">
                <button id="kaching-chatbot-reset" class="kaching-chatbot-reset" title="Reset Percakapan">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                </button>
                <button id="kaching-chatbot-close" class="kaching-chatbot-close">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>

        <div id="kaching-chatbot-messages" class="kaching-chatbot-messages">
            <div class="kaching-chat-bubble bot-bubble">
                Halo! 👋 Saya AI Assistant dari Kaching POS. Ada yang ingin Anda tanyakan seputar fitur, layanan, atau harga?
            </div>

            <!-- Suggestion Chips (in-chat, below greeting) -->
            <div id="kaching-quick-replies" class="kaching-chips-in-chat">
                <button type="button" class="kaching-qr-chip">💰 Cek Harga</button>
                <button type="button" class="kaching-qr-chip">🚀 Coba Demo Web Order</button>
                <button type="button" class="kaching-qr-chip">✨ Fitur Unggulan</button>
            </div>

        </div>

        <div class="kaching-chatbot-input-area">
            <form id="kaching-chatbot-form">
                <input type="text" id="kaching-chatbot-input" placeholder="Tulis pesan Anda..." autocomplete="off" required>
                <button type="button" id="kaching-chatbot-mic" title="Voice Input">
                    <svg fill="currentColor" viewBox="0 0 24 24" width="20" height="20">
                        <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"></path>
                        <path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"></path>
                    </svg>
                </button>
                <button type="submit" id="kaching-chatbot-send">
                    <svg fill="currentColor" viewBox="0 0 24 24" width="18" height="18" style="transform: translateX(1px);">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path>
                    </svg>
                </button>
            </form>
        </div>
    </div>

    <!-- Floating Toggle Button -->
    <button id="kaching-chatbot-toggle" class="kaching-chatbot-toggle">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="28" height="28">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
        </svg>
    </button>
</div>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');

    .kaching-chatbot-container {
        position: fixed;
        bottom: 16px;
        /* Direndahkan agar tidak terlalu melayang */
        right: 24px;
        /* Dipindah ke pojok kanan */
        z-index: 999999;
        font-family: 'Inter', sans-serif;
    }

    .kaching-chatbot-toggle {
        width: 65px;
        height: 65px;
        border-radius: 50%;
        background: linear-gradient(135deg, #1d4ed8 0%, #1e3a8a 100%);
        color: white;
        border: none;
        cursor: pointer;
        box-shadow: 0 10px 20px rgba(29, 78, 216, 0.35);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        position: absolute;
        bottom: 0;
        right: 0;
        /* Diubah jadi right: 0 agar rata kanan dengan container */
        animation: pulseFloat 2.5s infinite alternate;
    }

    .kaching-chatbot-toggle:hover {
        transform: scale(1.1) translateY(-5px);
        box-shadow: 0 15px 25px rgba(29, 78, 216, 0.5);
    }

    @keyframes pulseFloat {
        0% {
            transform: translateY(0px);
            box-shadow: 0 10px 20px rgba(29, 78, 216, 0.3);
        }

        100% {
            transform: translateY(-8px);
            box-shadow: 0 15px 30px rgba(29, 78, 216, 0.5);
        }
    }

    .kaching-chatbot-window {
        width: 380px;
        height: 550px;
        max-width: calc(100vw - 48px);
        /* Responsif: Lebar tak akan melebihi layar */
        max-height: calc(100vh - 120px);
        /* Responsif: Tinggi aman dari header/bawah layar */
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-radius: 20px;
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12), 0 5px 15px rgba(0, 0, 0, 0.05);
        border: none;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        position: absolute;
        bottom: 0px;
        /* SEKARANG PAS DI BAWAH */
        right: 0;
        transform-origin: bottom right;
        /* Pusat mengembang dari pojok kanan bawah */
        transition: opacity 0.3s ease, transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.15);
        /* Efek membal (spring) yang pas, tidak berlebihan */
        opacity: 1;
        transform: scale(1) translateY(0);
        visibility: visible;
    }

    .kaching-chatbot-hidden {
        opacity: 0;
        transform: scale(0.3) translateY(60px) translateX(40px);
        /* Menyusut mengecil masuk ke pojok kanan bawah */
        visibility: hidden;
        pointer-events: none;
    }

    .kaching-toggle-hidden {
        transform: scale(0.5) rotate(180deg) !important;
        /* Putaran lebih dramatis tapi smooth */
        opacity: 0 !important;
        visibility: hidden;
        pointer-events: none;
        transition: transform 0.4s ease-in, opacity 0.3s ease-out !important;
    }

    .kaching-chatbot-header {
        background: linear-gradient(135deg, #1d4ed8 0%, #1e3a8a 100%);
        padding: 16px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: white;
    }

    .kaching-chatbot-header-actions {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .kaching-chatbot-reset {
        background: rgba(255, 255, 255, 0.12);
        border: none;
        color: white;
        cursor: pointer;
        opacity: 0.75;
        transition: opacity 0.2s, transform 0.3s, background 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 50%;
    }

    .kaching-chatbot-reset:hover {
        opacity: 1;
        background: rgba(255, 255, 255, 0.22);
        transform: rotate(180deg);
    }

    .kaching-chatbot-header-title {
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 600;
        font-size: 16px;
        letter-spacing: 0.2px;
    }

    .kaching-chatbot-avatar {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .kaching-chatbot-close {
        background: none;
        border: none;
        color: white;
        cursor: pointer;
        opacity: 0.7;
        transition: opacity 0.2s, transform 0.2s;
        display: flex;
    }

    .kaching-chatbot-close:hover {
        opacity: 1;
        transform: rotate(90deg);
    }

    .kaching-chatbot-messages {
        flex: 1;
        padding: 20px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 12px;
        background: #f8fafc;
        scroll-behavior: smooth;
    }

    .kaching-chatbot-messages::-webkit-scrollbar {
        width: 6px;
    }

    .kaching-chatbot-messages::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }

    .kaching-chat-bubble {
        max-width: 85%;
        padding: 12px 16px;
        border-radius: 16px;
        font-size: 14.5px;
        line-height: 1.5;
        animation: fadeInBubble 0.3s ease-out forwards;
        word-wrap: break-word;
    }

    @keyframes fadeInBubble {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .bot-bubble {
        background: white;
        color: #334155;
        border-bottom-left-radius: 4px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.03);
        align-self: flex-start;
        border: 1px solid #f1f5f9;
    }

    .user-bubble {
        background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
        color: white;
        border-bottom-right-radius: 4px;
        box-shadow: 0 4px 10px rgba(29, 78, 216, 0.2);
        align-self: flex-end;
    }

    .kaching-chatbot-input-area {
        padding: 15px 20px;
        background: white;
        border-top: 1px solid #f1f5f9;
    }

    #kaching-chatbot-form {
        display: flex;
        gap: 10px;
        position: relative;
    }

    #kaching-chatbot-form input {
        flex: 1;
        padding: 14px 16px;
        padding-right: 80px;
        border-radius: 20px;
        border: 1px solid #e2e8f0;
        outline: none;
        font-size: 14px;
        transition: all 0.3s;
        background: #f8fafc;
        color: #334155;
    }

    #kaching-chatbot-form input:focus {
        border-color: #1d4ed8;
        background: white;
        box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.12);
    }

    #kaching-chatbot-mic {
        position: absolute;
        right: 48px;
        top: 50%;
        transform: translateY(-50%);
        background: transparent;
        color: #94a3b8;
        border: none;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: color 0.2s, transform 0.2s;
        border-radius: 50%;
    }

    #kaching-chatbot-mic:hover {
        color: #1d4ed8;
        background: #f1f5f9;
    }

    #kaching-chatbot-mic.listening {
        color: #ef4444;
        animation: micPulse 1.5s infinite;
    }

    @keyframes micPulse {
        0% {
            transform: translateY(-50%) scale(1);
        }

        50% {
            transform: translateY(-50%) scale(1.15);
        }

        100% {
            transform: translateY(-50%) scale(1);
        }
    }

    #kaching-chatbot-send {
        position: absolute;
        right: 6px;
        top: 50%;
        transform: translateY(-50%);
        background: linear-gradient(135deg, #1d4ed8 0%, #1e3a8a 100%);
        color: white;
        border: none;
        border-radius: 50%;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
    }

    #kaching-chatbot-send:hover {
        transform: translateY(-50%) scale(1.1);
        box-shadow: 0 4px 8px rgba(29, 78, 216, 0.4);
    }

    .kaching-chatbot-footer {
        text-align: center;
        font-size: 11px;
        color: #94a3b8;
        margin-top: 10px;
        letter-spacing: 0.5px;
        font-weight: 500;
    }

    .typing-indicator {
        display: flex;
        gap: 5px;
        padding: 14px 18px;
        background: white;
        border-radius: 16px;
        border-bottom-left-radius: 4px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.03);
        width: fit-content;
        align-self: flex-start;
        border: 1px solid #f1f5f9;
    }

    .typing-dot {
        width: 6px;
        height: 6px;
        background: #94a3b8;
        border-radius: 50%;
        animation: typing 1.4s infinite ease-in-out both;
    }

    .typing-dot:nth-child(1) {
        animation-delay: -0.32s;
    }

    .typing-dot:nth-child(2) {
        animation-delay: -0.16s;
    }

    @keyframes typing {

        0%,
        80%,
        100% {
            transform: scale(0);
            opacity: 0.5;
        }

        40% {
            transform: scale(1);
            opacity: 1;
        }
    }

    .kaching-quick-replies-scrollable {
        display: flex;
        overflow-x: auto;
        gap: 8px;
        padding-bottom: 10px;
        margin-bottom: 5px;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .kaching-quick-replies-scrollable::-webkit-scrollbar {
        display: none;
    }

    /* Chips yang hidup di dalam aliran chat (di bawah greeting) */
    .kaching-chips-in-chat {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 8px;
        align-self: flex-start;
        max-width: 95%;
        transition: opacity 0.3s ease;
    }

    .kaching-qr-chip {
        padding: 6px 12px;
        background: white;
        border: 1.5px solid #1d4ed8;
        color: #1d4ed8;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
    }

    .kaching-qr-chip:hover {
        background: #1d4ed8;
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(29, 78, 216, 0.2);
    }

    .kaching-qr-chip.cs-chip {
        border-color: #ec4899;
        color: #ec4899;
    }

    .kaching-qr-chip.cs-chip:hover {
        background: #ec4899;
        color: white;
        box-shadow: 0 4px 8px rgba(236, 72, 153, 0.2);
    }

    .bot-bubble a {
        color: #1d4ed8;
        text-decoration: underline;
        font-weight: 600;
    }

    /* Online Indicator pulse */
    .kaching-online-indicator {
        width: 8px;
        height: 8px;
        background-color: #4ade80;
        border-radius: 50%;
        position: relative;
        margin-left: 2px;
    }

    .kaching-online-indicator::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background-color: #4ade80;
        animation: kachingPulseIndicator 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }

    @keyframes kachingPulseIndicator {
        0% {
            transform: scale(1);
            opacity: 0.8;
        }

        70%,
        100% {
            transform: scale(2.5);
            opacity: 0;
        }
    }

    /* Penyesuaian responsif untuk HP & Tablet */
    @media (max-width: 768px) {
        .kaching-chatbot-container {
            bottom: 16px;
            right: 16px;
            /* Margin pinggir diperkecil di HP agar lebih lega */
        }

        .kaching-chatbot-window {
            width: calc(100vw - 32px);
            /* Dinamis mengisi layar HP dikurangi margin */
            height: calc(100vh - 100px);
            /* Dinamis menghindari URL bar browser */
            max-height: 600px;
            bottom: 0;
            /* Tetap rata bawah sejajar dengan area tombol */
            right: 0;
            border-radius: 16px;
            /* Ujung kotak sedikit lebih melengkung alami di HP */
        }

        /* Tombol toggle agak dikecilkan sedikit di HP agar tidak menutupi konten */
        .kaching-chatbot-toggle {
            width: 55px;
            height: 55px;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const toggleBtn = document.getElementById('kaching-chatbot-toggle');
        const chatWindow = document.getElementById('kaching-chatbot-window');
        const closeBtn = document.getElementById('kaching-chatbot-close');
        const chatForm = document.getElementById('kaching-chatbot-form');
        const chatInput = document.getElementById('kaching-chatbot-input');
        const messagesContainer = document.getElementById('kaching-chatbot-messages');

        let isProcessing = false;
        let widgetChatHistory = [];

        // Toggle Window Visibility
        const toggleWindow = () => {
            const isHidden = chatWindow.classList.contains('kaching-chatbot-hidden');

            if (isHidden) {
                // Open: Hide btn, show window
                toggleBtn.classList.add('kaching-toggle-hidden');
                chatWindow.classList.remove('kaching-chatbot-hidden');
                setTimeout(() => chatInput.focus(), 400);
            } else {
                // Close: Show btn, hide window
                chatWindow.classList.add('kaching-chatbot-hidden');
                toggleBtn.classList.remove('kaching-toggle-hidden');
            }
        };

        toggleBtn.addEventListener('click', toggleWindow);
        closeBtn.addEventListener('click', toggleWindow);

        // ─── SCROLL HELPER (definisikan lebih awal agar bisa dipakai resetBtn & bindChips) ───
        const scrollToBottom = () => {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        };

        // ─── RESET / CLEAR CHAT (Fitur Ekspo) ────────────────────────────────
        const resetBtn = document.getElementById('kaching-chatbot-reset');

        const GREETING_HTML = `
            <div class="kaching-chat-bubble bot-bubble">
                Halo! 👋 Saya AI Assistant dari Kaching POS. Ada yang ingin Anda tanyakan seputar fitur, layanan, atau harga?
            </div>
            <div id="kaching-quick-replies" class="kaching-chips-in-chat">
                <button type="button" class="kaching-qr-chip">💰 Cek Harga</button>
                <button type="button" class="kaching-qr-chip">🚀 Coba Demo Web Order</button>
                <button type="button" class="kaching-qr-chip">✨ Fitur Unggulan</button>
            </div>
        `;

        const bindChips = () => {
            const qrContainer = document.getElementById('kaching-quick-replies');
            if (qrContainer) {
                qrContainer.addEventListener('click', (e) => {
                    if (e.target.classList.contains('kaching-qr-chip')) {
                        const text = e.target.innerText.trim();
                        chatInput.value = text;
                        chatForm.dispatchEvent(new Event('submit'));
                        qrContainer.style.opacity = '0';
                        setTimeout(() => qrContainer.remove(), 300);
                    }
                });
            }
        };

        resetBtn.addEventListener('click', () => {
            messagesContainer.innerHTML = GREETING_HTML;
            chatInput.value = '';
            isProcessing = false;
            widgetChatHistory = [];
            bindChips();
            scrollToBottom();
        });

        // Bind chips yang sudah ada di HTML awal
        bindChips();

        const addMessage = (text, isUser = false) => {
            // 1. SAFETY CHECK: Pastikan respons dari server benar-benar ada dan berupa teks
            if (!isUser && (!text || typeof text !== 'string')) {
                console.error("AI Response Error: Invalid text format", text);
                text = @js(__('copilot.system.clarification_ai_unavailable')) || "Layanan asisten AI sedang tidak dapat dihubungi saat ini. Silakan coba beberapa saat lagi.";
            }

            const bubble = document.createElement('div');
            bubble.classList.add('kaching-chat-bubble');
            bubble.classList.add(isUser ? 'user-bubble' : 'bot-bubble');

            if (isUser) {
                bubble.innerText = text;
            } else {
                let finalMessage = text;
                // 2. Intercept & Handle CTA Tags (Minimalist Inline Links)
                const linkStyle = "color: #2563eb; text-decoration: underline; font-weight: 600;";

                // Gunakan regex untuk membersihkan titik (.) atau spasi nyasar sebelum tag
                if (finalMessage.includes('[CTA_HARGA]')) {
                    finalMessage = finalMessage.replace(/(\s*\.?\s*)?\[CTA_HARGA\]/, ` <a href="/harga" style="${linkStyle}">halaman ini</a>.`);
                } else if (finalMessage.includes('[CTA_FNB]')) {
                    finalMessage = finalMessage.replace(/(\s*\.?\s*)?\[CTA_FNB\]/, ` <a href="/fnb" style="${linkStyle}">halaman ini</a>.`);
                } else if (finalMessage.includes('[CTA_FITUR]')) {
                    finalMessage = finalMessage.replace(/(\s*\.?\s*)?\[CTA_FITUR\]/, ` <a href="/fitur" style="${linkStyle}">halaman ini</a>.`);
                }

                // 3. legacy tag removal (fallback)
                finalMessage = finalMessage.replace(/\[TRIGGER_UPGRADE_[^\]]+\]/g, '');

                // 4. Hapus tag [ROUTE:...] khusus Android agar tidak tampil di Web
                let cleanText = finalMessage.replace(/\[ROUTE:[^\]]+\]/g, '').trim();

                // 5. Simple Markdown Link Renderer [text](url) -> <a href="url">text</a>
                const htmlContent = cleanText.replace(/\[([^\]]+)\]\(([^\)]+)\)/g, '<a href="$2" target="_blank">$1</a>');
                bubble.innerHTML = htmlContent.replace(/\n/g, '<br>');
            }

            messagesContainer.appendChild(bubble);
            // Auto-scroll setelah pesan baru ditambahkan
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        };

        // Quick Replies awal sudah di-handle oleh bindChips() di atas
        // (kode lama dihapus untuk menghindari duplikasi listener)

        const addTypingIndicator = () => {
            const indicator = document.createElement('div');
            indicator.id = 'kaching-typing';
            indicator.classList.add('typing-indicator');
            indicator.innerHTML = '<div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div>';
            messagesContainer.appendChild(indicator);
            // Auto-scroll: instant assignment lebih andal di semua browser
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        };

        const removeTypingIndicator = () => {
            const indicator = document.getElementById('kaching-typing');
            if (indicator) {
                indicator.remove();
            }
        };

        chatForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (isProcessing) return;

            const message = chatInput.value.trim();
            if (!message) return;

            chatInput.value = '';
            addMessage(message, true);
            addTypingIndicator();

            isProcessing = true;

            try {
                const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
                const csrfToken = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';

                const response = await fetch('/chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        message: message,
                        history: widgetChatHistory.slice(-8)
                    })
                });

                removeTypingIndicator();

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    let errMsg = errorData.error || errorData.message;
                    if (!errMsg) {
                        if (response.status === 419) {
                            errMsg = 'Sesi halaman telah kedaluwarsa. Silakan muat ulang halaman.';
                        } else if (response.status === 429) {
                            errMsg = 'Terlalu banyak permintaan dalam waktu singkat. Mohon tunggu beberapa detik sebelum mencoba lagi.';
                        } else {
                            errMsg = 'Maaf, terjadi kesalahan saat menghubungi server.';
                        }
                    } else if (response.status === 429 && errMsg === 'Too Many Attempts.') {
                        errMsg = 'Terlalu banyak permintaan dalam waktu singkat. Mohon tunggu beberapa detik sebelum mencoba lagi.';
                    }
                    addMessage(errMsg);
                    isProcessing = false;
                    return;
                }

                const data = await response.json();
                const replyText = data.reply || (data.data && data.data.reply) || data.message;
                if (replyText && typeof replyText === 'string') {
                    addMessage(replyText);
                    widgetChatHistory.push({ role: 'user', content: message });
                    widgetChatHistory.push({ role: 'assistant', content: replyText });
                    if (widgetChatHistory.length > 8) {
                        widgetChatHistory = widgetChatHistory.slice(-8);
                    }
                } else {
                    addMessage(@js(__('copilot.system.clarification_default')) || 'Ada hal lain yang dapat saya bantu terkait Kaching?');
                }

            } catch (error) {
                removeTypingIndicator();
                addMessage('Error: Koneksi terputus. Silakan periksa koneksi internet Anda.');
                console.error('Chatbot Error:', error);
            } finally {
                isProcessing = false;
            }
        });
        // Web Speech API Integration
        const micBtn = document.getElementById('kaching-chatbot-mic');

        if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            const recognition = new SpeechRecognition();

            recognition.continuous = false;
            recognition.interimResults = false;
            recognition.lang = 'id-ID';

            recognition.onstart = function() {
                micBtn.classList.add('listening');
                chatInput.placeholder = 'Mendengarkan...';
            };

            recognition.onresult = function(event) {
                const transcript = event.results[0][0].transcript;
                chatInput.value = transcript;
            };

            recognition.onerror = function(event) {
                console.error('Speech recognition error detected: ' + event.error);
                micBtn.classList.remove('listening');
                chatInput.placeholder = 'Tulis pesan Anda...';
            };

            recognition.onend = function() {
                micBtn.classList.remove('listening');
                chatInput.placeholder = 'Tulis pesan Anda...';
            };

            micBtn.addEventListener('click', () => {
                if (micBtn.classList.contains('listening')) {
                    recognition.stop();
                } else {
                    recognition.start();
                }
            });
        } else {
            micBtn.style.display = 'none';
        }
    });
</script>