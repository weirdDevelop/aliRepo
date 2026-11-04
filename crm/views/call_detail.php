<?php
/**
 * جاکجاست - جزئیات تماس
 * Jakojast - Call Detail Page
 *
 * نمایش اطلاعات کامل یک تماس + پخش فایل صوتی + یادداشت
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$callId = (int)($_GET['id'] ?? 0);
if (!$callId) {
    echo '<main id="main-content"><div class="card" style="padding:40px;text-align:center">
            <h2>شناسه تماس مشخص نیست</h2>
            <a href="' . BASE_URL . '/index.php?page=calls" class="btn btn-primary" style="margin-top:16px">بازگشت به لیست</a>
          </div></main>';
    require __DIR__ . '/parts/footer.php';
    exit;
}

try {
    $call = Call::find($callId);
    if (!$call) {
        echo '<main id="main-content"><div class="card" style="padding:40px;text-align:center">
                <h2>تماس یافت نشد</h2>
                <a href="' . BASE_URL . '/index.php?page=calls" class="btn btn-primary" style="margin-top:16px">بازگشت به لیست</a>
              </div></main>';
        require __DIR__ . '/parts/footer.php';
        exit;
    }
    // ⚠️ محافظت در برابر دسترسی مستقیم با حدس‌زدن id به تماس کسی که در
    // محدوده‌ی دید کاربر فعلی نیست (همان محدودیتی که در لیست اعمال می‌شود)
    if (!Auth::canViewExten($call['exten_number'] ?? null)) {
        echo '<main id="main-content"><div class="card" style="padding:40px;text-align:center">
                <h2>شما دسترسی به این تماس را ندارید</h2>
                <a href="' . BASE_URL . '/index.php?page=calls" class="btn btn-primary" style="margin-top:16px">بازگشت به لیست</a>
              </div></main>';
        require __DIR__ . '/parts/footer.php';
        exit;
    }
} catch (Exception $e) {
    Logger::error('Call detail load failed: ' . $e->getMessage());
    $call = null;
}

$typeIcon = ['inbound' => '📥', 'outbound' => '📤', 'missed' => '❌', 'internal' => '🔄'];
$typeText = ['inbound' => 'ورودی', 'outbound' => 'خروجی', 'missed' => 'بی‌پاسخ', 'internal' => 'داخلی'];
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">
  <div id="page-call_detail" class="page active">
    <div class="card">
      <!-- هدر -->
      <div class="profile-header">
        <div class="avatar" style="width:64px;height:64px;font-size:28px;background:<?= $call['status'] === 'answered' ? '#28a745' : '#dc3545' ?>">
          <?= $typeIcon[$call['type']] ?? '📞' ?>
        </div>
        <div class="ph-info">
          <h2>تماس <?= $typeText[$call['type']] ?? '' ?> - <?= Helpers::e($call['cuid']) ?></h2>
          <div style="display:flex;gap:8px;align-items:center;margin-top:4px;flex-wrap:wrap">
            <span class="badge <?= $call['status'] === 'answered' ? 'badge-success' : ($call['status'] === 'ringing' ? 'badge-warning' : 'badge-danger') ?>" id="call-status-badge">
              <?= $call['status'] === 'answered' ? 'پاسخ داده شد' : ($call['status'] === 'no_answer' ? 'بی‌پاسخ' : ($call['status'] === 'ringing' ? '⏳ در حال زنگ خوردن' : $call['status'])) ?>
            </span>
            <?php if ($call['status'] === 'ringing'): ?>
              <button class="btn btn-light btn-sm" onclick="reconcileCallStatus()" id="btn-reconcile" title="اگر این تماس مدتی است تمام شده ولی هنوز اینجا «در حال زنگ خوردن» نشان داده می‌شود (وب‌هوک hangup نرسیده)، این را بزنید">🔄 بررسی مجدد وضعیت</button>
            <?php endif; ?>
            <span class="badge badge-info"><?= Helpers::e($call['jalali_started_at'] ?? '-') ?></span>
            <?php if (!empty($call['file_record'])): ?>
              <span class="badge badge-success">🎵 فایل صوتی دارد</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="ph-actions" style="display:flex;gap:6px;flex-wrap:wrap">
          <?php if (!empty($call['lead_id'])): ?>
            <a href="<?= BASE_URL ?>/index.php?page=leads&id=<?= (int)$call['lead_id'] ?>" class="btn btn-success btn-sm">🎯 مشاهده سرنخ</a>
          <?php elseif ($call['call_source'] || $call['call_dest']): ?>
            <button class="btn btn-light btn-sm" onclick="convertToLead(<?= (int)$call['id'] ?>)">➕🎯 تبدیل به سرنخ</button>
          <?php endif; ?>
          <a href="<?= BASE_URL ?>/index.php?page=calls" class="btn btn-light btn-sm">← بازگشت</a>
        </div>
      </div>

      <!-- اطلاعات سریع -->
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-bottom:1px solid var(--border)">
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">مدت مکالمه</div>
          <div class="fw-700 text-success mt-1"><?= Helpers::e($call['talk_formatted'] ?? '-') ?></div>
        </div>
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">مدت انتظار</div>
          <div class="fw-700 text-warning mt-1"><?= Helpers::e($call['wait_formatted'] ?? '-') ?></div>
        </div>
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">اپراتور</div>
          <div class="fw-700 mt-1"><?= Helpers::e($call['staff_name'] ?: ($call['exten_number'] ?: '-')) ?></div>
        </div>
        <div style="padding:14px;text-align:center">
          <div class="text-muted fs-12">Trunk</div>
          <div class="fw-700 mt-1" dir="ltr"><?= Helpers::e($call['trunk'] ?: '-') ?></div>
        </div>
      </div>

      <div style="padding:20px">
        <!-- اطلاعات تماس -->
        <div class="grid-2" style="margin-bottom:20px">
          <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
            <div class="text-muted fs-12 mb-1">شماره مبدا</div>
            <div class="fw-700" dir="ltr" style="font-size:16px">
              <a href="tel:<?= Helpers::e($call['call_source']) ?>" style="color:var(--primary);text-decoration:none">
                📞 <?= Helpers::e($call['call_source'] ?: '-') ?>
              </a>
            </div>
          </div>
          <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
            <div class="text-muted fs-12 mb-1">شماره مقصد</div>
            <div class="fw-700" dir="ltr" style="font-size:16px">
              <a href="tel:<?= Helpers::e($call['call_dest']) ?>" style="color:var(--primary);text-decoration:none">
                📞 <?= Helpers::e($call['call_dest'] ?: '-') ?>
              </a>
            </div>
          </div>
        </div>

        <!-- مخاطب مرتبط -->
        <?php if (!empty($call['contact_name'])): ?>
        <div style="background:rgba(229,2,65,0.05);padding:16px;border-radius:8px;margin-bottom:20px;border-right:3px solid var(--primary)">
          <div class="text-muted fs-12 mb-1">👤 مخاطب مرتبط</div>
          <div style="display:flex;align-items:center;gap:12px;margin-top:4px">
            <div class="avatar" style="width:40px;height:40px;font-size:16px;background:<?= Helpers::avatarColor($call['contact_name']) ?>">
              <?= Helpers::initials($call['contact_name']) ?>
            </div>
            <div>
              <a href="<?= BASE_URL ?>/index.php?page=customer_profile&id=<?= (int)$call['contact_id'] ?>" style="color:var(--primary);font-weight:600;text-decoration:none;font-size:15px">
                <?= Helpers::e($call['contact_name']) ?>
              </a>
              <?php if (!empty($call['contact_mobile'])): ?>
                <div style="font-size:12px;color:var(--text-muted)" dir="ltr"><?= Helpers::e($call['contact_mobile']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- پخش فایل صوتی -->
        <?php if (!empty($call['file_record'])): ?>
        <div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:20px;margin-bottom:20px">
          <h4 style="margin-bottom:16px">🎵 فایل صوتی مکالمه</h4>
          <div id="recording-loading" style="display:none;text-align:center;padding:20px">
            <div style="font-size:32px;margin-bottom:8px">⏳</div>
            <div>در حال دریافت فایل صوتی از تلفنچی...</div>
          </div>
          <div id="recording-error" style="display:none;text-align:center;padding:20px;color:var(--danger)"></div>
          <div id="recording-player" style="text-align:center;display:none">
            <audio id="audio-player" controls style="width:100%;max-width:500px" dir="ltr"></audio>
            <div style="margin-top:8px;font-size:12px;color:var(--text-muted)">
              📡 فایل صوتی به‌صورت مستقیم از سرور تلفنچی دریافت و پخش می‌شود.
            </div>
          </div>
          <div style="margin-top:12px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
            <button class="btn btn-primary btn-sm" onclick="loadAndPlayRecording()">▶️ پخش</button>
            <a href="<?= BASE_URL ?>/api.php/play-recording?id=<?= (int)$callId ?>&token=<?= Helpers::e(Auth::extractToken() ?? '') ?>" class="btn btn-light btn-sm" download>⬇ دانلود</a>
            <button class="btn btn-light btn-sm" onclick="reloadRecording()" id="btn-reload-recording" title="اگر پخش/دانلود خطا می‌دهد، این را بزنید تا دوباره از تلفنچی گرفته شود">🔄 بارگذاری مجدد صدا</button>
          </div>
        </div>

        <!-- متن پیاده‌شده‌ی مکالمه (Whisper - آفلاین) -->
        <div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:20px;margin-bottom:20px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h4>📝 متن مکالمه</h4>
            <button class="btn btn-primary btn-sm" onclick="requestTranscription()" id="btn-transcribe" <?= $call['transcript_status'] === 'processing' ? 'disabled' : '' ?>>
              🎙 <?= !empty($call['transcript']) ? 'استخراج دوباره' : 'استخراج متن مکالمه' ?>
            </button>
          </div>

          <?php if ($call['transcript_status'] === 'processing'): ?>
            <div style="text-align:center;padding:20px;color:var(--text-muted)">⏳ در حال پردازش... (ممکن است تا چند دقیقه طول بکشد)</div>
          <?php elseif (!empty($call['transcript'])): ?>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px;line-height:2;white-space:pre-wrap"><?= Helpers::e($call['transcript']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:8px">
              استخراج‌شده در <?= $call['transcribed_at'] ? Jalali::fromGregorian($call['transcribed_at'], 'Y/m/d H:i') : '-' ?> — توسط Whisper (آفلاین، روی سرور شما)
            </div>
          <?php elseif ($call['transcript_status'] === 'failed'): ?>
            <div style="text-align:center;padding:16px;color:var(--danger)">❌ تبدیل صدا به متن این تماس ناموفق بود. دوباره امتحان کنید.</div>
          <?php elseif (empty($call['file_record'])): ?>
            <div style="text-align:center;padding:16px;color:var(--text-muted)">این تماس ضبط نشده، متنی برای استخراج وجود ندارد.</div>
          <?php else: ?>
            <div style="text-align:center;padding:16px;color:var(--text-muted)">هنوز متن این مکالمه استخراج نشده.</div>
          <?php endif; ?>
        </div>

        <!-- تحلیل هوشمند تماس (هوش مصنوعی تلفنچی) -->
        <div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:20px;margin-bottom:20px" id="ai-analysis-panel">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h4>🤖 تحلیل هوشمند تماس</h4>
            <button class="btn btn-primary btn-sm" id="btn-ai-analysis" onclick="requestAiAnalysis()">✨ درخواست تحلیل</button>
          </div>
          <div id="ai-analysis-body">
            <div style="text-align:center;padding:16px;color:var(--text-muted)">هنوز تحلیلی برای این تماس درخواست نشده.</div>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:8px">
            📡 توسط سرویس هوش مصنوعی تلفنچی انجام می‌شود؛ هر درخواست جدید یک واحد از سهمیه‌ی وب‌سرویس مصرف می‌کند (تحلیل‌های قبلاً کامل‌شده رایگان دوباره نمایش داده می‌شوند).
          </div>
        </div>

        <?php else: ?>
        <div style="background:var(--bg-secondary);padding:16px;border-radius:8px;margin-bottom:20px;text-align:center;color:var(--text-muted)">
          🎵 فایل صوتی برای این تماس هنوز از تلفنچی دریافت نشده.
          <div style="margin-top:10px">
            <button class="btn btn-light btn-sm" onclick="reloadRecording()" id="btn-reload-recording">🔄 بارگذاری مجدد صدا</button>
          </div>
        </div>
        <?php endif; ?>

        <!-- دسته‌بندی و لیبل عملیاتی -->
        <div style="margin-bottom:20px;display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div>
            <h4 style="margin-bottom:8px">🏷 دسته‌بندی</h4>
            <select id="detail-call-category" class="form-control" onchange="saveDetailCategory()">
              <?php foreach (Call::$callCategoryLabels as $catKey => $catLabel): ?>
                <option value="<?= Helpers::e($catKey) ?>" <?= ($call['call_category'] ?? 'unclassified') === $catKey ? 'selected' : '' ?>><?= Helpers::e($catLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <h4 style="margin-bottom:8px">🔖 لیبل پیگیری</h4>
            <select id="detail-call-label" class="form-control" onchange="saveDetailLabel()">
              <?php foreach (Call::$callLabelLabels as $lbKey => $lbLabel): ?>
                <option value="<?= Helpers::e($lbKey) ?>" <?= ($call['call_label'] ?? 'none') === $lbKey ? 'selected' : '' ?>><?= Helpers::e($lbLabel) ?></option>
              <?php endforeach; ?>
            </select>
            <div style="font-size:11px;color:var(--text-muted);margin-top:6px">استعلام قیمت، ماهانه، تایید نشده، تبدیل به فروش و ...</div>
          </div>
        </div>

        <!-- یادداشت تماس -->
        <div style="margin-bottom:20px">
          <h4 style="margin-bottom:12px">📝 یادداشت تماس</h4>
          <textarea class="form-control" id="call-notes" rows="4" placeholder="یادداشت داخلی درباره این تماس..."><?= Helpers::e($call['notes'] ?? '') ?></textarea>
          <div style="margin-top:8px;display:flex;gap:8px">
            <button class="btn btn-primary btn-sm" onclick="saveNotes()">💾 ذخیره یادداشت</button>
            <span id="notes-saved" class="text-success" style="display:none;align-self:center;font-size:12px">✓ ذخیره شد</span>
          </div>
        </div>

        <!-- اطلاعات فنی -->
        <div class="card" style="background:var(--bg-secondary)">
          <div class="card-header">
            <h4 style="font-size:14px">🔧 اطلاعات فنی</h4>
          </div>
          <div style="padding:16px;font-size:12px;line-height:1.8">
            <div><strong>cuid:</strong> <code dir="ltr"><?= Helpers::e($call['cuid']) ?></code></div>
            <div><strong>زمان شروع (میلادی):</strong> <code dir="ltr"><?= Helpers::e($call['started_at'] ?? '-') ?></code></div>
            <div><strong>زمان شروع (شمسی):</strong> <code dir="ltr"><?= Helpers::e($call['jalali_started_at'] ?? '-') ?></code></div>
            <div><strong>نوع:</strong> <?= Helpers::e($call['type']) ?></div>
            <div><strong>وضعیت:</strong> <?= Helpers::e($call['status']) ?></div>
            <div><strong>داخلی:</strong> <?= Helpers::e($call['exten_name'] ?? '') ?> (<?= Helpers::e($call['exten_number'] ?? '-') ?>)</div>
            <div><strong>Trunk:</strong> <?= Helpers::e($call['trunk'] ?? '-') ?></div>
            <div><strong>فایل صوتی:</strong> <code dir="ltr"><?= Helpers::e($call['file_record'] ?? '-') ?></code></div>
            <div><strong>تاریخ درج:</strong> <?= Helpers::e($call['imported_at'] ?? '-') ?></div>
            <?php if (!empty($call['staff_name'])): ?>
              <div><strong>پرسنل مرتبط (بر اساس داخلی):</strong> <a href="<?= BASE_URL ?>/index.php?page=staff_profile&id=<?= (int)$call['staff_id'] ?>" style="color:var(--primary)"><?= Helpers::e($call['staff_name']) ?></a> (<?= Helpers::e($call['staff_unit'] ?? '-') ?>)</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
const callId = <?= (int)$callId ?>;

async function requestTranscription() {
    const btn = document.getElementById('btn-transcribe');
    btn.disabled = true;
    btn.innerHTML = '⏳ در حال پردازش... (چند ثانیه تا چند دقیقه)';

    try {
        const result = await fetchAPI('calls/' + callId + '/transcribe', { method: 'POST' });
        if (result.success) {
            showToast('متن مکالمه استخراج شد', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(result.message || 'خطا در استخراج متن', 'error');
            btn.disabled = false;
            btn.innerHTML = '🎙 استخراج متن مکالمه';
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
        btn.disabled = false;
        btn.innerHTML = '🎙 استخراج متن مکالمه';
    }
}

// ===================== تحلیل هوشمند تماس (AI) =====================
// طبق مستندات رسمی: وضعیت را هر ۳ تا ۵ ثانیه بررسی کن تا به completed/failed برسد
const AI_ANALYSIS_POLL_MS = 4000;
const AI_ANALYSIS_STATUS_LABELS = {
    requesting: 'در حال ثبت درخواست...',
    queued: 'در صف پردازش...',
    transcribing: 'در حال تبدیل صوت به متن...',
    analyzing: 'در حال تحلیل با هوش مصنوعی...',
    reconciling: 'در حال نهایی‌سازی نتیجه...',
};
let aiAnalysisPollTimer = null;

async function requestAiAnalysis() {
    const btn = document.getElementById('btn-ai-analysis');
    btn.disabled = true;
    btn.innerHTML = '⏳ در حال ارسال درخواست...';

    try {
        const result = await fetchAPI('calls/' + callId + '/analysis/request', { method: 'POST' });
        if (!result.success) {
            showToast(result.message || 'خطا در ثبت درخواست تحلیل', 'error');
            btn.disabled = false;
            btn.innerHTML = '✨ درخواست تحلیل';
            return;
        }
        showToast(result.message, 'success');
        renderAiAnalysis(result.data);
        scheduleAiAnalysisPoll();
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
        btn.disabled = false;
        btn.innerHTML = '✨ درخواست تحلیل';
    }
}

function scheduleAiAnalysisPoll() {
    if (aiAnalysisPollTimer) clearTimeout(aiAnalysisPollTimer);
    aiAnalysisPollTimer = setTimeout(pollAiAnalysis, AI_ANALYSIS_POLL_MS);
}

async function pollAiAnalysis(silent = false) {
    try {
        const result = await fetchAPI('calls/' + callId + '/analysis', { method: 'GET' });
        if (!result.success) {
            if (!silent) showToast(result.message || 'خطا در دریافت وضعیت تحلیل', 'error');
            return;
        }
        renderAiAnalysis(result.data);

        const status = result.data && result.data.ai_analysis_status;
        if (status && !['completed', 'failed'].includes(status)) {
            scheduleAiAnalysisPoll();
        }
    } catch (err) {
        if (!silent) showToast('خطا در ارتباط با سرور', 'error');
    }
}

function renderAiAnalysis(row) {
    const body = document.getElementById('ai-analysis-body');
    const btn = document.getElementById('btn-ai-analysis');
    const status = row ? row.ai_analysis_status : null;
    const a = (row && row.ai_analysis) || {};

    if (!status) {
        body.innerHTML = '<div style="text-align:center;padding:16px;color:var(--text-muted)">هنوز تحلیلی برای این تماس درخواست نشده.</div>';
        btn.disabled = false;
        btn.innerHTML = '✨ درخواست تحلیل';
        return;
    }

    if (status === 'failed') {
        body.innerHTML = '<div style="text-align:center;padding:16px;color:var(--danger)">❌ تحلیل این تماس ناموفق بود (وارد جریان بازگشت هزینه شده). می‌توانید دوباره امتحان کنید.</div>';
        btn.disabled = false;
        btn.innerHTML = '🔁 تلاش دوباره';
        return;
    }

    if (status !== 'completed') {
        const label = AI_ANALYSIS_STATUS_LABELS[status] || status;
        const progress = row.ai_analysis_progress != null ? row.ai_analysis_progress : 0;
        body.innerHTML = `
            <div style="padding:16px 0">
              <div style="text-align:center;margin-bottom:10px;color:var(--text-muted)">⏳ ${label}</div>
              <div style="background:var(--bg-secondary);border-radius:8px;height:10px;overflow:hidden">
                <div style="background:var(--primary,#3b82f6);height:100%;width:${progress}%;transition:width .5s"></div>
              </div>
              <div style="text-align:center;font-size:11px;color:var(--text-muted);margin-top:6px">${progress}٪</div>
            </div>`;
        btn.disabled = true;
        btn.innerHTML = '⏳ در حال پردازش...';
        return;
    }

    // completed
    btn.disabled = false;
    btn.innerHTML = '✨ درخواست تحلیل';

    const sentimentBadge = a.customer_sentiment
        ? `<span class="badge badge-secondary">${escapeHtml(a.customer_sentiment)}</span>` : '';

    let keywordsHtml = '';
    if (Array.isArray(a.matched_keywords) && a.matched_keywords.length) {
        keywordsHtml = a.matched_keywords.map(k => `<span class="badge badge-secondary" style="margin:2px">${escapeHtml(k)}</span>`).join(' ');
    }

    body.innerHTML = `
        <div style="display:flex;flex-direction:column;gap:14px">
          ${a.summary ? `<div><strong style="font-size:13px">📋 خلاصه</strong><div style="margin-top:4px">${escapeHtml(a.summary)}</div></div>` : ''}
          ${a.outcome ? `<div><strong style="font-size:13px">🎯 نتیجه‌ی تماس</strong><div style="margin-top:4px">${escapeHtml(a.outcome)}</div></div>` : ''}
          ${a.customer_sentiment ? `<div><strong style="font-size:13px">😊 احساس مشتری</strong><div style="margin-top:4px">${sentimentBadge} ${a.sentiment_reason ? '<span style="color:var(--text-muted);font-size:12px">- ' + escapeHtml(a.sentiment_reason) + '</span>' : ''}</div></div>` : ''}
          ${a.coaching_tip ? `<div><strong style="font-size:13px">💡 پیشنهاد برای اپراتور</strong><div style="margin-top:4px">${escapeHtml(a.coaching_tip)}</div>${a.coaching_reason ? '<div style="color:var(--text-muted);font-size:12px;margin-top:2px">' + escapeHtml(a.coaching_reason) + '</div>' : ''}</div>` : ''}
          ${keywordsHtml ? `<div><strong style="font-size:13px">🏷 کلیدواژه‌های شناسایی‌شده</strong><div style="margin-top:6px">${keywordsHtml}</div></div>` : ''}
          ${a.report_text ? `<details><summary style="cursor:pointer;font-size:13px;font-weight:600">📄 گزارش کامل تحلیل</summary><div style="background:var(--bg-secondary);padding:12px;border-radius:8px;margin-top:8px;white-space:pre-wrap;font-size:13px">${escapeHtml(a.report_text)}</div></details>` : ''}
          ${renderAiTranscript(a)}
        </div>`;
}

function renderAiTranscript(a) {
    if (!a.transcript_by_role) return '';
    const tbr = a.transcript_by_role;
    if (tbr.mapping !== 'verified') {
        return `<div style="font-size:12px;color:var(--text-muted)">ℹ️ تفکیک متن بر اساس نقش (اپراتور/مشتری) برای این تماس قابل‌اعتماد نبود.</div>`;
    }
    return `
        <details>
          <summary style="cursor:pointer;font-size:13px;font-weight:600">🗣 متن مکالمه (تفکیک‌شده بر اساس نقش)</summary>
          <div style="background:var(--bg-secondary);padding:12px;border-radius:8px;margin-top:8px;font-size:13px;line-height:1.9">
            ${tbr.operator ? '<div style="margin-bottom:10px"><strong>👤 اپراتور:</strong><div style="white-space:pre-wrap">' + escapeHtml(tbr.operator) + '</div></div>' : ''}
            ${tbr.customer ? '<div><strong>📞 مشتری:</strong><div style="white-space:pre-wrap">' + escapeHtml(tbr.customer) + '</div></div>' : ''}
          </div>
        </details>`;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

// در بارگذاری صفحه، فقط وضعیت را یک‌بار (رایگان، بدون هزینه) چک کن -
// اگر قبلاً تحلیلی درخواست شده باشد نمایش داده می‌شود، وگرنه دکمه دست‌نخورده می‌ماند
<?php if (!empty($call['file_record'])): ?>
document.addEventListener('DOMContentLoaded', () => pollAiAnalysis(true));
<?php endif; ?>

async function reloadRecording() {
    const btn = document.getElementById('btn-reload-recording');
    btn.disabled = true;
    btn.innerHTML = '⏳ در حال بررسی از تلفنچی...';
    try {
        const result = await fetchAPI('calls/' + callId + '/reload-recording', { method: 'POST' });
        showToast(result.message || (result.success ? 'انجام شد' : 'خطا'), result.success ? 'success' : 'error');
        setTimeout(() => window.location.reload(), 1000);
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
        btn.disabled = false;
        btn.innerHTML = '🔄 بارگذاری مجدد صدا';
    }
}

async function reconcileCallStatus() {
    const btn = document.getElementById('btn-reconcile');
    btn.disabled = true;
    btn.innerHTML = '⏳ در حال بررسی...';
    try {
        const result = await fetchAPI('calls/' + callId + '/reconcile', { method: 'POST' });
        if (!result.success) {
            showToast(result.message || 'خطا در بررسی وضعیت', 'error');
            btn.disabled = false;
            btn.innerHTML = '🔄 بررسی مجدد وضعیت';
            return;
        }
        showToast(result.message, 'success');
        setTimeout(() => window.location.reload(), 900);
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
        btn.disabled = false;
        btn.innerHTML = '🔄 بررسی مجدد وضعیت';
    }
}

async function convertToLead(id) {
    const name = prompt('نام مشتری برای سرنخ جدید (خالی بگذارید تا خودکار ساخته شود):', '');
    if (name === null) return; // کاربر لغو کرد

    try {
        const result = await fetchAPI('calls/' + id + '/convert-to-lead', {
            method: 'POST',
            body: name ? { name } : {}
        });
        if (result.success) {
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 1200);
        } else {
            showToast(result.message || 'خطا در تبدیل به سرنخ', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

async function loadAndPlayRecording(autoplay = true) {
    const audio = document.getElementById('audio-player');
    const loading = document.getElementById('recording-loading');
    const error = document.getElementById('recording-error');
    const player = document.getElementById('recording-player');

    loading.style.display = 'block';
    error.style.display = 'none';
    player.style.display = 'none';

    try {
        // URL شامل token برای auth
        const url = `${JJ.baseUrl}/api.php/play-recording?id=${callId}&token=${encodeURIComponent(JJ.token)}`;
        audio.src = url;

        audio.addEventListener('loadeddata', () => {
            loading.style.display = 'none';
            player.style.display = 'block';
            // راه‌اندازی پلیر Plyr برای ظاهر بهتر (به‌جای پلیر خام مرورگر)
            if (window.Plyr && !audio.dataset.plyrInit) {
                new Plyr(audio, { controls: ['play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'speed', 'download'], download: url });
                audio.dataset.plyrInit = '1';
            }
            if (autoplay) {
                audio.play().catch(e => {
                    error.textContent = 'خطا در پخش: ' + e.message;
                    error.style.display = 'block';
                    player.style.display = 'none';
                });
            }
        }, { once: true });

        audio.addEventListener('error', () => {
            loading.style.display = 'none';
            error.textContent = 'خطا در دریافت فایل صوتی. ممکن است فایل در تلفنچی وجود نداشته باشد.';
            error.style.display = 'block';
        }, { once: true });

        // trigger load
        audio.load();

    } catch (err) {
        loading.style.display = 'none';
        error.textContent = 'خطا: ' + err.message;
        error.style.display = 'block';
    }
}

async function saveDetailCategory() {
    const category = document.getElementById('detail-call-category').value;
    try {
        const result = await fetchAPI(`calls/${callId}/category`, { method: 'PATCH', body: { category } });
        showToast(result.success ? 'دسته‌بندی ذخیره شد' : (result.message || 'خطا'), result.success ? 'success' : 'error');
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

async function saveDetailLabel() {
    const label = document.getElementById('detail-call-label').value;
    try {
        const result = await fetchAPI(`calls/${callId}/label`, { method: 'PATCH', body: { label } });
        showToast(result.success ? 'لیبل ذخیره شد' : (result.message || 'خطا'), result.success ? 'success' : 'error');
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

async function saveNotes() {
    const notes = document.getElementById('call-notes').value;
    try {
        const result = await fetchAPI(`calls/${callId}/notes`, { method: 'PUT', body: { notes } });
        if (result.success) {
            const saved = document.getElementById('notes-saved');
            saved.style.display = 'inline';
            setTimeout(() => saved.style.display = 'none', 2000);
            showToast('یادداشت ذخیره شد', 'success');
        } else {
            showToast(result.message, 'error');
        }
    } catch (err) {
        showToast('خطا در ذخیره', 'error');
    }
}

// بارگذاری خودکار فایل صوتی اگر وجود دارد (بدون autoplay - فقط آماده‌سازی)
<?php if (!empty($call['file_record'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    // ⚠️ رفع باگ: قبلاً اینجا مستقیم audio.src ست می‌شد بدون audio.load()
    // و بدون گوش‌دادن به رویداد error - یعنی اگر دریافت فایل شکست
    // می‌خورد، هیچ پیام فارسی/دوستانه‌ای نشان داده نمی‌شد و کاربر فقط
    // خطای خام مرورگر («Failed to load because no supported source was
    // found») را می‌دید. الان از همان تابع loadAndPlayRecording (با
    // autoplay=false) استفاده می‌شود تا همان مدیریت loading/error
    // یکسان اعمال شود، فقط بدون پخش خودکار.
    loadAndPlayRecording(false);
});
<?php endif; ?>
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
