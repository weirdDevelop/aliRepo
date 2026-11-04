<?php
/**
 * جاکجاست - صفحه چت داخلی
 * Jakojast - Internal Chat Page
 *
 * چت با Long Polling (سرور تا ۲۵ ثانیه منتظر پیام جدید می‌ماند)
 * کانال‌ها: عمومی، مالی، پشتیبانی، فروش، میزبانان، فنی
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

try {
    $channels = Chat::getChannelsWithCounts();
    $onlineUsers = Chat::getOnlineUsers();
    $stats = Chat::getStats();
} catch (Exception $e) {
    $channels = []; $onlineUsers = []; $stats = [];
    Logger::error('Chat page load failed: ' . $e->getMessage());
}

$currentChannel = $_GET['channel'] ?? 'عمومی';
if (!in_array($currentChannel, Chat::$channels, true)) $currentChannel = 'عمومی';
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<style>
#chat-layout {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 16px;
    height: calc(100vh - 160px);
    min-height: 500px;
}
@media (max-width: 768px) {
    #chat-layout { grid-template-columns: 1fr; }
    .chat-sidebar { display: none; }
}
.chat-sidebar {
    background: #fff;
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04);
}
.cs-header {
    padding: 14px 16px;
    font-weight: 700;
    border-bottom: 1px solid var(--border);
    background: var(--bg-secondary);
}
.channel-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}
.channel-item {
    padding: 10px 12px;
    border-radius: 8px;
    cursor: pointer;
    margin-bottom: 4px;
    transition: all 0.2s;
    font-size: 13px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.channel-item:hover { background: var(--bg-secondary); }
.channel-item.active {
    background: var(--primary);
    color: #fff;
}
.channel-item .ci-info {
    flex: 1;
    overflow: hidden;
}
.channel-item .ci-name {
    font-weight: 600;
    margin-bottom: 2px;
}
.channel-item .ci-last {
    font-size: 11px;
    opacity: 0.7;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.channel-item .ci-count {
    background: rgba(0,0,0,0.1);
    border-radius: 10px;
    padding: 2px 8px;
    font-size: 10px;
    margin-right: 8px;
}
.channel-item.active .ci-count {
    background: rgba(255,255,255,0.2);
}

.online-users {
    border-top: 1px solid var(--border);
    padding: 8px;
    max-height: 200px;
    overflow-y: auto;
}
.online-user {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    border-radius: 6px;
    font-size: 12px;
}
.online-user .ou-dot {
    width: 8px;
    height: 8px;
    background: #28a745;
    border-radius: 50%;
    flex-shrink: 0;
}

.chat-main {
    background: #fff;
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04);
}
.chat-header {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    background: var(--bg-secondary);
    display: flex;
    align-items: center;
    gap: 12px;
}
.chat-header h4 {
    font-size: 16px;
    font-weight: 700;
    color: var(--primary);
}
.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    background: #fafafa;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.chat-msg {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    max-width: 80%;
}
.chat-msg.me {
    margin-right: auto;
    flex-direction: row-reverse;
}
.chat-msg .cm-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 13px;
    flex-shrink: 0;
}
.chat-msg .cm-content {
    background: #fff;
    padding: 8px 12px;
    border-radius: 12px;
    border: 1px solid var(--border);
    max-width: 100%;
}
.chat-msg.me .cm-content {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}
.chat-msg .cm-user {
    font-size: 11px;
    color: var(--text-muted);
    margin-bottom: 2px;
}
.chat-msg.me .cm-user { color: rgba(255,255,255,0.8); }
.chat-msg .cm-text {
    font-size: 13px;
    line-height: 1.5;
    word-wrap: break-word;
    white-space: pre-wrap;
}
.chat-msg .cm-time {
    font-size: 10px;
    color: var(--text-muted);
    margin-top: 4px;
    text-align: left;
}
.chat-msg.me .cm-time { color: rgba(255,255,255,0.7); }

.chat-input-area {
    padding: 12px 16px;
    border-top: 1px solid var(--border);
    background: #fff;
    display: flex;
    gap: 8px;
    align-items: center;
}
.chat-input-area input {
    flex: 1;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 20px;
    font-family: inherit;
    font-size: 13px;
}
.chat-input-area input:focus {
    outline: none;
    border-color: var(--primary);
}
.chat-typing {
    font-size: 11px;
    color: var(--text-muted);
    padding: 4px 16px;
    height: 20px;
}
</style>

<main id="main-content">
  <div id="page-chat" class="page active">
    <!-- کارت‌های آمار -->
    <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px">
      <div class="kpi-card red">
        <div class="kpi-icon">💬</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['total'] ?? 0) ?></div>
        <div class="kpi-label">کل پیام‌ها</div>
      </div>
      <div class="kpi-card green">
        <div class="kpi-icon">📅</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['today'] ?? 0) ?></div>
        <div class="kpi-label">پیام‌های امروز</div>
      </div>
      <div class="kpi-card blue">
        <div class="kpi-icon">👥</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['active_users'] ?? 0) ?></div>
        <div class="kpi-label">کاربران فعال ۲۴ ساعت</div>
      </div>
      <div class="kpi-card purple">
        <div class="kpi-icon">📡</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits(count(Chat::$channels)) ?></div>
        <div class="kpi-label">کانال‌ها</div>
      </div>
    </div>

    <div id="chat-layout">
      <!-- سایدبار کانال‌ها -->
      <div class="chat-sidebar">
        <div class="cs-header">💬 کانال‌ها</div>
        <div class="channel-list" id="chat-channels">
          <?php foreach ($channels as $ch): ?>
            <div class="channel-item <?= $ch['name'] === $currentChannel ? 'active' : '' ?>"
                 onclick="switchChannel('<?= Helpers::e($ch['name']) ?>')">
              <div class="ci-info">
                <div class="ci-name"># <?= Helpers::e($ch['name']) ?></div>
                <?php if ($ch['last_message']): ?>
                  <div class="ci-last"><?= Helpers::e(Helpers::truncate($ch['last_message'], 25)) ?></div>
                <?php endif; ?>
              </div>
              <?php if ($ch['message_count'] > 0): ?>
                <span class="ci-count"><?= Helpers::toPersianDigits($ch['message_count']) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="online-users">
          <div class="cs-header" style="font-size:12px;padding:8px 12px;background:var(--bg-secondary)">
            👤 کاربران آنلاین
          </div>
          <div id="chat-online-users">
            <?php if (empty($onlineUsers)): ?>
              <div style="padding:8px;font-size:11px;color:var(--text-muted);text-align:center">
                کاربری آنلاین نیست
              </div>
            <?php else: ?>
              <?php foreach ($onlineUsers as $u): ?>
                <div class="online-user">
                  <span class="ou-dot"></span>
                  <span><?= Helpers::e($u['user_name']) ?></span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- بخش اصلی چت -->
      <div class="chat-main">
        <div class="chat-header">
          <h4 id="chat-channel-name"># <?= Helpers::e($currentChannel) ?></h4>
          <span style="font-size:12px;color:var(--text-muted)" id="chat-msg-count">۰ پیام</span>
        </div>
        <div class="chat-messages" id="chat-msgs">
          <div style="text-align:center;padding:40px;color:var(--text-muted)">
            <div style="font-size:32px;margin-bottom:8px">💬</div>
            <div>در حال بارگذاری پیام‌ها...</div>
          </div>
        </div>
        <div class="chat-typing" id="chat-typing"></div>
        <div class="chat-input-area">
          <input type="text" id="chat-input"
                 placeholder="پیام خود را بنویسید در #<?= Helpers::e($currentChannel) ?>..."
                 onkeypress="if(event.key==='Enter')sendChatMessage()"
                 maxlength="2000">
          <button class="btn btn-primary" onclick="sendChatMessage()" style="border-radius:20px;padding:8px 16px">➤ ارسال</button>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
// ===========================
// متغیرهای چت
// ===========================
const JJ_USER = <?= json_encode([
    'id' => $currentUser['id'] ?? 0,
    'name' => $currentUser['full_name'] ?? 'کاربر',
]) ?>;

let currentChannel = '<?= Helpers::e($currentChannel) ?>';
let lastMessageId = 0;
// chatPollActive در app.js تعریف می‌شود (تعریف دوباره‌ی let باعث از کار افتادن کل app.js می‌شد)
let chatPollController = null;

// ===========================
// دریافت اولیه پیام‌ها
// ===========================
async function loadInitialMessages() {
    try {
        const result = await fetchAPI(`chat/messages?channel=${encodeURIComponent(currentChannel)}&last_id=0`);
        if (result.success && result.data.messages) {
            displayMessages(result.data.messages, true);
            lastMessageId = result.data.last_id;
            updateMsgCount(result.data.messages.length);
            // اسکرول به پایین
            const container = document.getElementById('chat-msgs');
            container.scrollTop = container.scrollHeight;
        } else {
            console.error('Chat load failed - server response:', result);
            document.getElementById('chat-msgs').innerHTML =
                `<div style="text-align:center;padding:40px;color:var(--danger)">خطا در بارگذاری پیام‌ها: ${result.message || 'نامشخص'}</div>`;
        }
    } catch (err) {
        console.error('Chat load exception:', err);
        document.getElementById('chat-msgs').innerHTML =
            `<div style="text-align:center;padding:40px;color:var(--danger)">خطا در بارگذاری پیام‌ها (کنسول مرورگر F12 را چک کنید): ${err.message}</div>`;
    }
    // شروع Long Polling
    startLongPolling();
}

// ===========================
// Long Polling - دریافت پیام‌های جدید
// ===========================
async function startLongPolling() {
    if (chatPollActive) return;
    chatPollActive = true;

    while (chatPollActive) {
        try {
            // استفاده از fetch مستقیم (نه fetchAPI) چون fetchAPI در صورت timeout خطا می‌دهد
            const url = `${JJ.baseUrl}/api.php/chat/messages?channel=${encodeURIComponent(currentChannel)}&last_id=${lastMessageId}&poll=1`;
            const response = await fetch(url, {
                headers: {
                    'Authorization': `Bearer ${JJ.token}`,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (response.status === 401) {
                showToast('نشست شما منقضی شده است', 'warning');
                setTimeout(() => window.location.href = `${JJ.baseUrl}/index.php?page=login`, 1500);
                break;
            }

            if (!response.ok) {
                // در صورت خطا، ۳ ثانیه صبر کن و دوباره
                await new Promise(r => setTimeout(r, 3000));
                continue;
            }

            const data = await response.json();
            if (data.success && data.data.messages && data.data.messages.length > 0) {
                displayMessages(data.data.messages, false);
                lastMessageId = data.data.last_id;
                // اسکرول به پایین اگر کاربر در پایین است
                const container = document.getElementById('chat-msgs');
                const isAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
                if (isAtBottom) {
                    container.scrollTop = container.scrollHeight;
                }
            }
            // اگر timeout شد (پیام جدیدی نبود)، حلقه دوباره اجرا می‌شود
        } catch (err) {
            // در صورت خطای شبکه، ۵ ثانیه صبر کن
            console.error('Long polling error:', err);
            await new Promise(r => setTimeout(r, 5000));
        }
    }
}

function stopLongPolling() {
    chatPollActive = false;
}

// ===========================
// نمایش پیام‌ها
// ===========================
function displayMessages(messages, clearFirst = false) {
    const container = document.getElementById('chat-msgs');
    if (clearFirst) container.innerHTML = '';

    messages.forEach(msg => {
        // جلوگیری از duplicate
        if (document.querySelector(`[data-msg-id="${msg.id}"]`)) return;

        const isMe = msg.is_me == 1 || msg.user_id == JJ_USER.id;
        const avatarColor = getAvatarColor(msg.user_name || msg.user_id || 0);
        const initials = (msg.user_name || '?').slice(0, 2);

        const msgEl = document.createElement('div');
        msgEl.className = `chat-msg ${isMe ? 'me' : ''}`;
        msgEl.setAttribute('data-msg-id', msg.id);
        msgEl.innerHTML = `
            <div class="cm-avatar" style="background:${avatarColor}">${initials}</div>
            <div class="cm-content">
                ${!isMe ? `<div class="cm-user">${escapeHtml(msg.user_name)}</div>` : ''}
                <div class="cm-text">${escapeHtml(msg.text)}</div>
                <div class="cm-time">${msg.time || ''}</div>
            </div>
        `;
        container.appendChild(msgEl);
    });

    if (clearFirst) {
        if (messages.length === 0) {
            container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted)"><div style="font-size:32px;margin-bottom:8px">💬</div><div>پیامی در این کانال وجود ندارد<br>اولین پیام را شما ارسال کنید!</div></div>';
        }
    }
}

// ===========================
// ارسال پیام
// ===========================
async function sendChatMessage() {
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    if (!text) return;

    input.value = '';  // پاک کردن input بلافاصله

    try {
        const result = await fetchAPI('chat/send', {
            method: 'POST',
            body: { channel: currentChannel, text }
        });
        if (result.success) {
            // پیام نمایش داده می‌شود توسط Long Polling
            // اما برای تجربه بهتر، فوراً نمایش بده
            displayMessages([result.data], false);
            const container = document.getElementById('chat-msgs');
            container.scrollTop = container.scrollHeight;
        } else {
            showToast(result.message || 'خطا در ارسال پیام', 'error');
            input.value = text;  // بازگرداندن متن
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
        console.error('Chat send exception:', err);
        input.value = text;
    }
}

// ===========================
// تعویض کانال
// ===========================
function switchChannel(name) {
    if (name === currentChannel) return;
    stopLongPolling();
    currentChannel = name;
    document.getElementById('chat-channel-name').textContent = '# ' + name;
    document.getElementById('chat-input').placeholder = `پیام خود را بنویسید در #${name}...`;
    document.querySelectorAll('.channel-item').forEach(el => el.classList.remove('active'));
    // فعال‌سازی کانال جدید
    document.querySelectorAll('.channel-item').forEach(el => {
        if (el.textContent.includes(name)) el.classList.add('active');
    });

    lastMessageId = 0;
    document.getElementById('chat-msgs').innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted)">در حال بارگذاری...</div>';
    loadInitialMessages();
}

// ===========================
// توابع کمکی
// ===========================
function updateMsgCount(count) {
    const el = document.getElementById('chat-msg-count');
    el.textContent = `${toPersianDigits(count)} پیام`;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

// ===========================
// شروع چت
// ===========================
document.addEventListener('DOMContentLoaded', function() {
    loadInitialMessages();

    // بروزرسانی کاربران آنلاین هر ۳۰ ثانیه
    setInterval(async () => {
        try {
            const result = await fetchAPI('chat/online-users');
            if (result.success) {
                const container = document.getElementById('chat-online-users');
                if (result.data.length === 0) {
                    container.innerHTML = '<div style="padding:8px;font-size:11px;color:var(--text-muted);text-align:center">کاربری آنلاین نیست</div>';
                } else {
                    container.innerHTML = result.data.map(u => `
                        <div class="online-user">
                            <span class="ou-dot"></span>
                            <span>${escapeHtml(u.user_name)}</span>
                        </div>
                    `).join('');
                }
            }
        } catch (err) {}
    }, 30000);

    // فوکوس روی input
    document.getElementById('chat-input').focus();
});

// توقف Long Polling هنگام خروج از صفحه
window.addEventListener('beforeunload', function() {
    stopLongPolling();
});

// توقف Long Polling هنگام مخفی شدن صفحه (تب مرورگر)
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        stopLongPolling();
    } else {
        // شروع مجدد Long Polling وقتی صفحه دوباره visible شد
        if (!chatPollActive) {
            loadInitialMessages();
        }
    }
});
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
