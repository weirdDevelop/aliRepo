<?php
/**
 * جاکجاست - صفحه تیکت‌ها
 * Jakojast - Tickets Page
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$priorityFilter = $_GET['priority'] ?? '';
$typeFilter = $_GET['type'] ?? '';
// ⚠️ رفع باگ سیستمیک: قبلاً از $_GET['page'] برای شماره صفحه استفاده می‌شد
// که دقیقاً همان پارامتری است که روتر اصلی (index.php) برای تشخیص
// «کدام صفحه» (customers/leads/...) استفاده می‌کند. چون در URL هر دو
// یک کلید (page) بودند، PHP فقط آخری را نگه می‌داشت و با کلیک روی
// «صفحه ۲» کل روتر گم می‌شد. الان از $_GET['p'] استفاده می‌شود.
$page = max(1, (int)($_GET['p'] ?? 1));

try {
    $result = Ticket::all([
        'search' => $search, 'status' => $statusFilter,
        'priority' => $priorityFilter, 'type' => $typeFilter,
        'page' => $page, 'limit' => 15,
    ]);
    $tickets = $result['items'];
    $total = $result['total'];
    $totalPages = max(1, ceil($total / 15));
    $stats = Ticket::getStats();
} catch (Exception $e) {
    $tickets = []; $total = 0; $totalPages = 1; $stats = [];
}
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">
  <div id="page-tickets" class="page active">
    <!-- کارت‌های آمار -->
    <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
      <div class="kpi-card red">
        <div class="kpi-icon">🔴</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['open'] ?? 0) ?></div>
        <div class="kpi-label">تیکت باز</div>
      </div>
      <div class="kpi-card yellow">
        <div class="kpi-icon">🟡</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['in_review'] ?? 0) ?></div>
        <div class="kpi-label">در بررسی</div>
      </div>
      <div class="kpi-card blue">
        <div class="kpi-icon">🔵</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['answered'] ?? 0) ?></div>
        <div class="kpi-label">پاسخ داده شده</div>
      </div>
      <div class="kpi-card green">
        <div class="kpi-icon">🟢</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['closed'] ?? 0) ?></div>
        <div class="kpi-label">بسته شده</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h4>🎫 سیستم تیکتینگ
          <span class="badge badge-primary" style="font-size:12px"><?= Helpers::toPersianDigits($total) ?> تیکت</span>
        </h4>
        <div style="display:flex;gap:8px">
          <button class="btn btn-primary btn-sm" onclick="openTicketModal()">+ تیکت جدید</button>
          <button class="btn btn-light btn-sm" onclick="exportTickets()">⬇ گزارش</button>
        </div>
      </div>

      <div class="filter-bar">
        <div class="search-box">
          <span class="si">🔍</span>
          <input type="text" id="ticket-search" placeholder="جستجو در عنوان..." value="<?= Helpers::e($search) ?>" oninput="debounceSearch()">
        </div>
        <select id="ticket-status-filter" onchange="applyFilters()">
          <option value="">همه وضعیت‌ها</option>
          <?php foreach (Ticket::$statuses as $s): ?>
            <option value="<?= Helpers::e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= getStatusLabelFa($s) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="ticket-priority-filter" onchange="applyFilters()">
          <option value="">همه اولویت‌ها</option>
          <option value="high" <?= $priorityFilter === 'high' ? 'selected' : '' ?>>🔴 بالا</option>
          <option value="medium" <?= $priorityFilter === 'medium' ? 'selected' : '' ?>>🟡 متوسط</option>
          <option value="low" <?= $priorityFilter === 'low' ? 'selected' : '' ?>>⚪ کم</option>
        </select>
        <select id="ticket-type-filter" onchange="applyFilters()">
          <option value="">همه انواع</option>
          <?php foreach (Ticket::$types as $t): ?>
            <option value="<?= Helpers::e($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= Helpers::e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>عنوان</th><th>نوع</th><th>اولویت</th><th>وضعیت</th>
              <th>بخش</th><th>مسئول</th><th>مرتبط با</th><th>تاریخ</th><th>آخرین پاسخ</th><th>عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($tickets)): ?>
              <tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text-muted)">
                🎫 تیکتی یافت نشد
              </td></tr>
            <?php else: ?>
              <?php foreach ($tickets as $t):
                $related = $t['customer_name'] ?: $t['host_name'] ?: $t['staff_name'] ?: '-';
              ?>
                <tr>
                  <td>
                    <strong style="cursor:pointer;color:var(--primary)" onclick="openTicketDetail(<?= (int)$t['id'] ?>)">
                      <?= Helpers::e($t['title']) ?>
                    </strong>
                    <?php if ($t['message_count'] > 0): ?>
                      <span class="badge badge-secondary" style="font-size:10px"><?= Helpers::toPersianDigits($t['message_count']) ?> پیام</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge badge-info"><?= Helpers::e($t['type']) ?></span></td>
                  <td>
                    <span class="badge <?= $t['priority'] === 'high' ? 'badge-danger' : ($t['priority'] === 'medium' ? 'badge-warning' : 'badge-secondary') ?>">
                      <?= $t['priority'] === 'high' ? '🔴 بالا' : ($t['priority'] === 'medium' ? '🟡 متوسط' : '⚪ کم') ?>
                    </span>
                  </td>
                  <td>
                    <span class="badge <?= getTicketStatusBadge($t['status']) ?>">
                      <?= getStatusLabelFa($t['status']) ?>
                    </span>
                  </td>
                  <td><?= Helpers::e($t['dept'] ?: '-') ?></td>
                  <td><?= Helpers::e($t['assignee'] ?: '-') ?></td>
                  <td><?= Helpers::e($related) ?></td>
                  <td><?= Helpers::e($t['date'] ?: '-') ?></td>
                  <td><?= Helpers::e($t['last_reply'] ?: '-') ?></td>
                  <td>
                    <div style="display:flex;gap:4px">
                      <button class="btn btn-info btn-xs" title="مشاهده" onclick="openTicketDetail(<?= (int)$t['id'] ?>)">👁</button>
                      <button class="btn btn-light btn-xs" title="بستن" onclick="closeTicket(<?= (int)$t['id'] ?>)">✓</button>
                      <button class="btn btn-danger btn-xs" title="حذف" onclick="deleteTicket(<?= (int)$t['id'] ?>)">🗑</button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="pagination" style="padding:16px">
        <?php if ($page > 1): ?>
          <a href="?page=tickets&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?>&type=<?= urlencode($typeFilter) ?>&p=<?= $page - 1 ?>" class="page-btn">« قبلی</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?page=tickets&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?>&type=<?= urlencode($typeFilter) ?>&p=<?= $p ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= Helpers::toPersianDigits($p) ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?page=tickets&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?>&type=<?= urlencode($typeFilter) ?>&p=<?= $page + 1 ?>" class="page-btn">بعدی »</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- مودال تیکت جدید -->
<div class="modal-overlay" id="modal-add-ticket">
  <div class="modal-box">
    <div class="modal-header">
      <h4>🎫 تیکت جدید</h4>
      <button class="btn-close" onclick="closeModal('modal-add-ticket')">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">عنوان <span style="color:red">*</span></label>
        <input type="text" class="form-control" id="ticket-title" placeholder="موضوع تیکت">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">نوع</label>
          <select class="form-control" id="ticket-type">
            <?php foreach (Ticket::$types as $t): ?>
              <option value="<?= Helpers::e($t) ?>"><?= Helpers::e($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">اولویت</label>
          <select class="form-control" id="ticket-priority">
            <option value="high">🔴 بالا</option>
            <option value="medium" selected>🟡 متوسط</option>
            <option value="low">⚪ کم</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">بخش</label>
          <select class="form-control" id="ticket-dept">
            <?php foreach (Ticket::$depts as $d): ?>
              <option value="<?= Helpers::e($d) ?>"><?= Helpers::e($d) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">مسئول</label>
          <select class="form-control" id="ticket-assignee">
            <option value="">انتخاب مسئول...</option>
            <?php
            $staffList = Database::getInstance()->fetchAll('SELECT name FROM staff ORDER BY name');
            foreach ($staffList as $s):
            ?>
              <option value="<?= Helpers::e($s['name']) ?>"><?= Helpers::e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">متن تیکت <span style="color:red">*</span></label>
        <textarea class="form-control" id="ticket-text" rows="4" placeholder="شرح مشکل یا درخواست..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-add-ticket')">انصراف</button>
      <button class="btn btn-primary" onclick="submitTicket()">✓ ثبت تیکت</button>
    </div>
  </div>
</div>

<!-- مودال جزئیات تیکت -->
<div class="modal-overlay" id="modal-ticket-detail">
  <div class="modal-box modal-xl">
    <div class="modal-header">
      <div>
        <h4 id="ticket-detail-title">-</h4>
        <div style="display:flex;gap:8px;margin-top:4px;flex-wrap:wrap" id="ticket-detail-badges"></div>
      </div>
      <button class="btn-close" onclick="closeModal('modal-ticket-detail')">×</button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 280px;min-height:400px">
      <div style="padding:16px;border-left:1px solid var(--border);display:flex;flex-direction:column">
        <div id="ticket-messages" class="chat-messages" style="flex:1;background:#fafafa;border-radius:8px;padding:12px;max-height:300px;overflow-y:auto;margin-bottom:12px;gap:10px;display:flex;flex-direction:column"></div>
        <div style="display:flex;gap:8px">
          <textarea class="form-control" id="ticket-reply-input" rows="2" placeholder="پاسخ جدید..." style="resize:none"></textarea>
          <button class="btn btn-primary" onclick="sendTicketReply()" style="white-space:nowrap">ارسال پاسخ</button>
        </div>
      </div>
      <div style="padding:16px;background:var(--bg-secondary)">
        <div class="fw-700 mb-3">جزئیات تیکت</div>
        <div style="font-size:13px" id="ticket-detail-info"></div>
        <div class="form-group" style="margin-top:16px">
          <label class="form-label">تغییر وضعیت</label>
          <select class="form-control" id="ticket-status-select" onchange="updateTicketStatus()">
            <?php foreach (Ticket::$statuses as $s): ?>
              <option value="<?= Helpers::e($s) ?>"><?= getStatusLabelFa($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">انتقال به</label>
          <select class="form-control" id="ticket-assignee-select" onchange="updateTicketAssignee()">
            <option value="">انتخاب مسئول...</option>
            <?php foreach ($staffList as $s): ?>
              <option value="<?= Helpers::e($s['name']) ?>"><?= Helpers::e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
function getTicketStatusBadge($status) {
    $map = ['open' => 'badge-danger', 'in_review' => 'badge-warning', 'answered' => 'badge-info', 'closed' => 'badge-secondary'];
    return $map[$status] ?? 'badge-secondary';
}
function getStatusLabelFa($status) {
    $map = ['active' => 'فعال', 'inactive' => 'غیرفعال', 'blocked' => 'مسدود',
            'pending' => 'در انتظار', 'approved' => 'تأیید شده', 'rejected' => 'رد شده',
            'open' => 'باز', 'in_review' => 'در بررسی', 'answered' => 'پاسخ داده شده', 'closed' => 'بسته شده',
            'new' => 'جدید', 'in_progress' => 'در حال انجام', 'review' => 'در انتظار بررسی',
            'done' => 'انجام شده', 'cancelled' => 'لغو شده', 'paid' => 'پرداخت شده'];
    return $map[$status] ?? $status;
}
?>

<script>
let searchTimer = null;
let currentTicketId = null;

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 500);
}
function applyFilters() {
    const params = new URLSearchParams({page: 'tickets'});
    const s = document.getElementById('ticket-search').value.trim();
    const st = document.getElementById('ticket-status-filter').value;
    const pr = document.getElementById('ticket-priority-filter').value;
    const tp = document.getElementById('ticket-type-filter').value;
    if (s) params.set('search', s);
    if (st) params.set('status', st);
    if (pr) params.set('priority', pr);
    if (tp) params.set('type', tp);
    window.location.href = '<?= BASE_URL ?>/index.php?' + params.toString();
}
function openTicketModal() {
    ['ticket-title','ticket-text'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('ticket-type').value = 'customer';
    document.getElementById('ticket-priority').value = 'medium';
    document.getElementById('ticket-dept').value = 'فروش';
    document.getElementById('ticket-assignee').value = '';
    openModal('modal-add-ticket');
}
async function submitTicket() {
    const data = {
        title: document.getElementById('ticket-title').value.trim(),
        type: document.getElementById('ticket-type').value,
        priority: document.getElementById('ticket-priority').value,
        dept: document.getElementById('ticket-dept').value,
        assignee: document.getElementById('ticket-assignee').value,
        message: document.getElementById('ticket-text').value.trim(),
    };
    if (!data.title || !data.message) return showToast('عنوان و متن الزامی است', 'warning');
    try {
        const result = await fetchAPI('tickets', { method: 'POST', body: data });
        if (result.success) {
            showToast('تیکت ایجاد شد', 'success');
            closeModal('modal-add-ticket');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}
async function openTicketDetail(id) {
    currentTicketId = id;
    try {
        const result = await fetchAPI('tickets/' + id);
        if (!result.success) return showToast(result.message, 'error');
        const t = result.data;
        document.getElementById('ticket-detail-title').textContent = t.title;
        document.getElementById('ticket-detail-badges').innerHTML = `
            <span class="badge badge-secondary">کد: TKT${String(t.id).padStart(3, '0')}</span>
            <span class="badge ${getTicketStatusBadge(t.status)}">${getStatusLabelFa(t.status)}</span>
            <span class="badge ${t.priority === 'high' ? 'badge-danger' : 'badge-warning'}">${t.priority === 'high' ? 'بالا' : t.priority === 'medium' ? 'متوسط' : 'کم'}</span>
            <span class="badge badge-info">${t.type}</span>
        `;
        document.getElementById('ticket-detail-info').innerHTML = `
            <div class="mb-2"><span class="text-muted">بخش:</span> ${t.dept || '-'}</div>
            <div class="mb-2"><span class="text-muted">مسئول:</span> ${t.assignee || '-'}</div>
            <div class="mb-2"><span class="text-muted">تاریخ:</span> ${t.date || '-'}</div>
            <div class="mb-2"><span class="text-muted">آخرین پاسخ:</span> ${t.last_reply || '-'}</div>
            ${t.customer_name ? `<div class="mb-2"><span class="text-muted">مشتری:</span> ${t.customer_name}</div>` : ''}
            ${t.host_name ? `<div class="mb-2"><span class="text-muted">میزبان:</span> ${t.host_name}</div>` : ''}
        `;
        document.getElementById('ticket-status-select').value = t.status;
        document.getElementById('ticket-assignee-select').value = t.assignee || '';

        // نمایش پیام‌ها
        const msgsContainer = document.getElementById('ticket-messages');
        const messages = t.messages_array || [];
        if (messages.length === 0) {
            msgsContainer.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-muted)">پیامی وجود ندارد</div>';
        } else {
            msgsContainer.innerHTML = messages.map(m => `
                <div style="background:${m.isSupport ? 'var(--primary)' : '#fff'};color:${m.isSupport ? '#fff' : 'var(--text)'};padding:10px 14px;border-radius:12px;max-width:80%;${m.isSupport ? 'margin-right:auto;' : ''}border:1px solid var(--border)">
                    <div style="font-size:11px;opacity:0.8;margin-bottom:4px">${m.isSupport ? '🎓 ' : '👤 '}${m.from} - ${m.date}</div>
                    <div>${escapeHtml(m.text)}</div>
                </div>
            `).join('');
            msgsContainer.scrollTop = msgsContainer.scrollHeight;
        }

        openModal('modal-ticket-detail');
    } catch (err) { showToast('خطا', 'error'); }
}
async function sendTicketReply() {
    if (!currentTicketId) return;
    const text = document.getElementById('ticket-reply-input').value.trim();
    if (!text) return showToast('متن پاسخ را وارد کنید', 'warning');
    try {
        const result = await fetchAPI(`tickets/${currentTicketId}/reply`, { method: 'POST', body: { text } });
        if (result.success) {
            showToast('پاسخ ثبت شد', 'success');
            document.getElementById('ticket-reply-input').value = '';
            openTicketDetail(currentTicketId);  // refresh
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}
async function updateTicketStatus() {
    if (!currentTicketId) return;
    const status = document.getElementById('ticket-status-select').value;
    try {
        await fetchAPI(`tickets/${currentTicketId}/status`, { method: 'PATCH', body: { status } });
        showToast('وضعیت تغییر کرد', 'success');
    } catch (err) { showToast('خطا', 'error'); }
}
async function updateTicketAssignee() {
    if (!currentTicketId) return;
    const assignee = document.getElementById('ticket-assignee-select').value;
    try {
        await fetchAPI(`tickets/${currentTicketId}/assign`, { method: 'PATCH', body: { assignee } });
        showToast('تیکت منتقل شد', 'success');
    } catch (err) { showToast('خطا', 'error'); }
}
async function closeTicket(id) {
    if (!confirm('بستن تیکت؟')) return;
    try {
        await fetchAPI(`tickets/${id}/status`, { method: 'PATCH', body: { status: 'closed' } });
        showToast('تیکت بسته شد', 'success');
        setTimeout(() => window.location.reload(), 800);
    } catch (err) { showToast('خطا', 'error'); }
}
async function deleteTicket(id) {
    if (!confirmDelete('حذف تیکت؟')) return;
    try {
        await fetchAPI('tickets/' + id, { method: 'DELETE' });
        showToast('تیکت حذف شد', 'success');
        setTimeout(() => window.location.reload(), 800);
    } catch (err) { showToast('خطا', 'error'); }
}
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
async function exportTickets() {
    try {
        const result = await fetchAPI('tickets?limit=1000');
        if (!result.success) return;
        const rows = result.data;
        const csv = ['ردیف,عنوان,نوع,اولویت,وضعیت,بخش,مسئول,تاریخ,آخرین پاسخ'];
        rows.forEach((t, i) => {
            csv.push([i+1, '"'+t.title+'"', t.type, t.priority, t.status, t.dept||'',
                '"'+(t.assignee||'')+'"', t.date||'', t.last_reply||''].join(','));
        });
        const blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        downloadBlob(blob, `tickets-${Date.now()}.csv`);
        showToast('فایل دانلود شد', 'success');
    } catch (err) { showToast('خطا', 'error'); }
}
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
