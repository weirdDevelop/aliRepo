<?php
/**
 * جاکجاست - صفحه تسک‌ها و پروژه‌ها
 * Jakojast - Tasks Page
 *
 * ۳ نمای کانبان، گانت و پروژه‌ها
 * کانبان با HTML5 Drag & Drop + AJAX update
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$view = $_GET['view'] ?? 'kanban';
$assigneeFilter = $_GET['assignee'] ?? '';

try {
    // دریافت همه تسک‌ها برای کانبان
    $taskParams = ['limit' => 500];
    if ($assigneeFilter) $taskParams['assignee'] = $assigneeFilter;
    $allTasks = Task::all($taskParams);
    $tasks = $allTasks['items'];

    // گروه‌بندی بر اساس status برای کانبان
    $kanban = [];
    foreach (Task::$statuses as $s) $kanban[$s] = [];
    foreach ($tasks as $t) {
        if (isset($kanban[$t['status']])) $kanban[$t['status']][] = $t;
    }

    $projects = Project::all(['limit' => 50])['items'];
    $assignees = Task::getAssignees();
    $stats = Task::getStats();
} catch (Exception $e) {
    $kanban = []; $tasks = []; $projects = []; $assignees = []; $stats = [];
    Logger::error('Tasks page load failed: ' . $e->getMessage());
}
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<style>
/* استایل‌های کانبان */
.kanban-board {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    padding: 8px;
    overflow-x: auto;
}
@media (max-width: 992px) {
    .kanban-board { grid-template-columns: repeat(2, 1fr); }
}
.kanban-column {
    background: var(--bg-secondary);
    border-radius: 12px;
    padding: 12px;
    min-height: 400px;
    display: flex;
    flex-direction: column;
}
.kanban-column-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--border);
    font-weight: 700;
    font-size: 14px;
}
.kanban-column-count {
    background: var(--primary);
    color: #fff;
    border-radius: 12px;
    padding: 2px 8px;
    font-size: 11px;
    min-width: 24px;
    text-align: center;
}
.kanban-column.new .kanban-column-count { background: #17a2b8; }
.kanban-column.in_progress .kanban-column-count { background: #007bff; }
.kanban-column.review .kanban-column-count { background: #ffc107; color: #333; }
.kanban-column.done .kanban-column-count { background: #28a745; }
.kanban-column.cancelled .kanban-column-count { background: #6c757d; }

.kanban-cards {
    flex: 1;
    overflow-y: auto;
    min-height: 100px;
}
.kanban-card {
    background: #fff;
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 8px;
    cursor: grab;
    border: 1px solid var(--border);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: all 0.2s;
    border-right: 3px solid var(--primary);
}
.kanban-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transform: translateY(-1px);
}
.kanban-card.dragging {
    opacity: 0.5;
    cursor: grabbing;
}
.kanban-card.priority-high { border-right-color: #dc3545; }
.kanban-card.priority-medium { border-right-color: #ffc107; }
.kanban-card.priority-low { border-right-color: #28a745; }

.kanban-card .kc-title {
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 6px;
    color: var(--text);
}
.kanban-card .kc-meta {
    font-size: 11px;
    color: var(--text-muted);
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 6px;
}
.kanban-card .kc-progress {
    height: 4px;
    background: var(--bg-tertiary);
    border-radius: 2px;
    overflow: hidden;
    margin-top: 6px;
}
.kanban-card .kc-progress-bar {
    height: 100%;
    background: var(--primary);
    transition: width 0.3s;
}

.kanban-column.drop-target {
    background: rgba(229, 2, 65, 0.05);
    outline: 2px dashed var(--primary);
    outline-offset: -4px;
}

/* گانت */
.gantt-chart {
    overflow-x: auto;
    padding: 8px;
}
.gantt-row {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 12px;
    margin-bottom: 4px;
    align-items: center;
    padding: 6px 0;
    border-bottom: 1px solid var(--border);
}
.gantt-row-header {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 2px solid var(--border);
    font-weight: 700;
    font-size: 12px;
    color: var(--text-muted);
}
.gantt-task-title {
    font-size: 12px;
    font-weight: 600;
    padding: 0 8px;
}
.gantt-bar-container {
    position: relative;
    height: 24px;
    background: var(--bg-secondary);
    border-radius: 4px;
}
.gantt-bar {
    position: absolute;
    height: 100%;
    background: linear-gradient(to right, var(--primary), var(--primary-light));
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    padding: 0 8px;
    overflow: hidden;
    white-space: nowrap;
}
.gantt-bar.done { background: linear-gradient(to right, #28a745, #20c997); }
.gantt-bar.in_progress { background: linear-gradient(to right, #007bff, #17a2b8); }
.gantt-bar.review { background: linear-gradient(to right, #ffc107, #fd7e14); color: #333; }
.gantt-bar.cancelled { background: #6c757d; }
</style>

<main id="main-content">
  <div id="page-tasks" class="page active">

    <!-- کارت‌های آمار -->
    <div class="kpi-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:20px">
      <div class="kpi-card blue">
        <div class="kpi-icon">🆕</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['new'] ?? 0) ?></div>
        <div class="kpi-label">جدید</div>
      </div>
      <div class="kpi-card yellow">
        <div class="kpi-icon">🔄</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['in_progress'] ?? 0) ?></div>
        <div class="kpi-label">در حال انجام</div>
      </div>
      <div class="kpi-card orange">
        <div class="kpi-icon">👀</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['review'] ?? 0) ?></div>
        <div class="kpi-label">در بررسی</div>
      </div>
      <div class="kpi-card green">
        <div class="kpi-icon">✅</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['done'] ?? 0) ?></div>
        <div class="kpi-label">انجام شده</div>
      </div>
      <div class="kpi-card purple">
        <div class="kpi-icon">📈</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['completion_rate'] ?? 0) ?>٪</div>
        <div class="kpi-label">نرخ تکمیل</div>
      </div>
    </div>

    <!-- نوار ابزار -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if (in_array($currentUser['role'] ?? '', ['admin', 'manager'], true)): ?>
          <button class="btn btn-primary" onclick="openTaskModal()">+ تسک جدید</button>
          <button class="btn btn-light" onclick="openProjectModal()">📁 پروژه جدید</button>
        <?php endif; ?>
        <select id="task-assignee-filter" onchange="applyAssigneeFilter()" class="form-control" style="width:auto">
          <option value="">همه مسئولین</option>
          <?php foreach ($assignees as $a): ?>
            <option value="<?= Helpers::e($a) ?>" <?= $assigneeFilter === $a ? 'selected' : '' ?>><?= Helpers::e($a) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:4px">
        <button class="btn btn-light btn-sm <?= $view === 'kanban' ? 'btn-primary' : '' ?>" onclick="switchView('kanban')" id="view-kanban">🗂 کانبان</button>
        <button class="btn btn-light btn-sm <?= $view === 'gantt' ? 'btn-primary' : '' ?>" onclick="switchView('gantt')" id="view-gantt">📊 گانت</button>
        <button class="btn btn-light btn-sm <?= $view === 'projects' ? 'btn-primary' : '' ?>" onclick="switchView('projects')" id="view-projects">📁 پروژه‌ها</button>
      </div>
    </div>

    <!-- نمای کانبان -->
    <div id="task-view-kanban" style="<?= $view !== 'kanban' ? 'display:none' : '' ?>">
      <div class="kanban-board" id="kanban-board">
        <?php foreach (Task::$statuses as $status):
            $statusClass = $status;
            $cards = $kanban[$status] ?? [];
        ?>
        <div class="kanban-column <?= $statusClass ?>" data-status="<?= $status ?>"
             ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event)">
          <div class="kanban-column-header">
            <span><?= Task::$statusLabels[$status] ?></span>
            <span class="kanban-column-count"><?= count($cards) ?></span>
          </div>
          <div class="kanban-cards">
            <?php if (empty($cards)): ?>
              <div style="text-align:center;padding:20px;color:var(--text-muted);font-size:12px">خالی</div>
            <?php else: ?>
              <?php foreach ($cards as $task): ?>
                <div class="kanban-card priority-<?= $task['priority'] ?>"
                     draggable="true"
                     data-task-id="<?= (int)$task['id'] ?>"
                     ondragstart="handleDragStart(event)"
                     ondragend="handleDragEnd(event)"
                     onclick="openTaskDetail(<?= (int)$task['id'] ?>)">
                  <div class="kc-title"><?= Helpers::e($task['title']) ?></div>
                  <div class="kc-meta">
                    <?php if ($task['project']): ?>
                      <span>📁 <?= Helpers::e($task['project']) ?></span>
                    <?php endif; ?>
                    <?php if ($task['assignee']): ?>
                      <span>👤 <?= Helpers::e($task['assignee']) ?></span>
                    <?php endif; ?>
                    <?php if ($task['end_date']): ?>
                      <span>📅 <?= Helpers::e($task['end_date']) ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($task['subtasks_array']) && $task['subtask_progress'] > 0): ?>
                    <div class="kc-progress">
                      <div class="kc-progress-bar" style="width:<?= $task['subtask_progress'] ?>%"></div>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- نمای گانت -->
    <div id="task-view-gantt" style="<?= $view !== 'gantt' ? 'display:none' : '' ?>">
      <div class="card">
        <div class="card-header"><h4>📊 گانت‌چارت پروژه‌ها</h4></div>
        <div class="gantt-chart">
          <div class="gantt-row-header">
            <div>عنوان تسک</div>
            <div>زمان‌بندی</div>
          </div>
          <?php
          // یافتن min و max تاریخ برای محاسبه عرض نوار
          $allDates = [];
          foreach ($tasks as $t) {
              if ($t['start_date']) $allDates[] = Jalali::toGregorianString($t['start_date']);
              if ($t['end_date']) $allDates[] = Jalali::toGregorianString($t['end_date']);
          }
          $minDate = !empty($allDates) ? min($allDates) : date('Y-m-d');
          $maxDate = !empty($allDates) ? max($allDates) : date('Y-m-d', strtotime('+30 days'));
          $totalDays = max(1, (strtotime($maxDate) - strtotime($minDate)) / 86400);

          foreach ($tasks as $t):
            if (!$t['start_date'] || !$t['end_date']) continue;
            $startG = Jalali::toGregorianString($t['start_date']);
            $endG = Jalali::toGregorianString($t['end_date']);
            if (!$startG || !$endG) continue;

            $startOffset = (strtotime($startG) - strtotime($minDate)) / 86400;
            $duration = max(1, (strtotime($endG) - strtotime($startG)) / 86400);
            $leftPct = max(0, ($startOffset / $totalDays) * 100);
            $widthPct = min(100 - $leftPct, ($duration / $totalDays) * 100);
          ?>
            <div class="gantt-row">
              <div class="gantt-task-title">
                <?= Helpers::e($t['title']) ?>
                <div style="font-size:10px;color:var(--text-muted)"><?= Helpers::e($t['assignee'] ?: '-') ?></div>
              </div>
              <div class="gantt-bar-container">
                <div class="gantt-bar <?= $t['status'] ?>"
                     style="left:<?= $leftPct ?>%;width:<?= $widthPct ?>%"
                     title="<?= Helpers::e($t['start_date']) ?> تا <?= Helpers::e($t['end_date']) ?>">
                  <?= Helpers::e($t['start_date']) ?> → <?= Helpers::e($t['end_date']) ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- نمای پروژه‌ها -->
    <div id="task-view-projects" style="<?= $view !== 'projects' ? 'display:none' : '' ?>">
      <div class="grid-2">
        <div id="projects-list">
          <?php if (empty($projects)): ?>
            <div class="card" style="padding:30px;text-align:center;color:var(--text-muted)">
              📁 پروژه‌ای ثبت نشده
            </div>
          <?php else: ?>
            <?php foreach ($projects as $p): ?>
              <div class="card" style="margin-bottom:12px">
                <div class="card-header">
                  <h4 style="font-size:14px">📁 <?= Helpers::e($p['name']) ?></h4>
                  <span class="badge badge-primary"><?= Helpers::toPersianDigits($p['progress']) ?>٪</span>
                </div>
                <div style="padding:12px">
                  <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px"><?= Helpers::e($p['description'] ?: '-') ?></div>
                  <div style="height:8px;background:var(--bg-secondary);border-radius:4px;margin-bottom:8px">
                    <div style="width:<?= (int)$p['progress'] ?>%;height:100%;background:var(--primary);border-radius:4px"></div>
                  </div>
                  <div style="font-size:11px;color:var(--text-muted)">
                    📅 <?= Helpers::e($p['deadline'] ?: '-') ?> | ✅ <?= Helpers::toPersianDigits($p['done_count']) ?>/<?= Helpers::toPersianDigits($p['task_count']) ?> تسک
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="card">
          <div class="card-header"><h4>📈 خلاصه</h4></div>
          <div style="padding:16px">
            <div class="stat-box mb-3" style="margin-bottom:12px">
              <div class="sb-value text-primary"><?= Helpers::toPersianDigits(count($projects)) ?></div>
              <div class="sb-label">پروژه</div>
            </div>
            <div class="stat-box mb-3" style="margin-bottom:12px">
              <div class="sb-value text-info"><?= Helpers::toPersianDigits($stats['total'] ?? 0) ?></div>
              <div class="sb-label">تسک کل</div>
            </div>
            <div class="stat-box">
              <div class="sb-value text-success"><?= Helpers::toPersianDigits($stats['completion_rate'] ?? 0) ?>٪</div>
              <div class="sb-label">نرخ تکمیل</div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</main>

<!-- مودال تسک -->
<div class="modal-overlay" id="modal-add-task">
  <div class="modal-box modal-lg">
    <div class="modal-header">
      <h4 id="task-modal-title">✅ تسک جدید</h4>
      <button class="btn-close" onclick="closeModal('modal-add-task')">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="task-id">
      <div class="form-group">
        <label class="form-label">عنوان تسک <span style="color:red">*</span></label>
        <input type="text" class="form-control" id="task-title" placeholder="عنوان...">
      </div>
      <div class="form-group">
        <label class="form-label">توضیحات</label>
        <textarea class="form-control" id="task-desc" rows="2" placeholder="توضیحات..."></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">اولویت</label>
          <select class="form-control" id="task-priority">
            <option value="high">🔴 بالا</option>
            <option value="medium" selected>🟡 متوسط</option>
            <option value="low">⚪ کم</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">وضعیت</label>
          <select class="form-control" id="task-status">
            <?php foreach (Task::$statuses as $s): ?>
              <option value="<?= Helpers::e($s) ?>"><?= Task::$statusLabels[$s] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">پروژه</label>
          <select class="form-control" id="task-project-id">
            <option value="">بدون پروژه</option>
            <?php foreach ($projects as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= Helpers::e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">مسئول</label>
          <select class="form-control" id="task-assignee">
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
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">تاریخ شروع (شمسی)</label>
          <input type="text" class="form-control" id="task-start" placeholder="1403/02/01" value="<?= Jalali::today() ?>">
        </div>
        <div class="form-group">
          <label class="form-label">ددلاین (شمسی)</label>
          <input type="text" class="form-control" id="task-end" placeholder="1403/03/01">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-add-task')">انصراف</button>
      <button class="btn btn-primary" onclick="saveTask()">✓ ثبت تسک</button>
    </div>
  </div>
</div>

<!-- مودال جزئیات تسک -->
<div class="modal-overlay" id="modal-task-detail">
  <div class="modal-box modal-lg">
    <div class="modal-header">
      <h4 id="task-detail-title">-</h4>
      <button class="btn-close" onclick="closeModal('modal-task-detail')">×</button>
    </div>
    <div class="modal-body" id="task-detail-body"></div>
  </div>
</div>

<!-- مودال پروژه -->
<div class="modal-overlay" id="modal-project">
  <div class="modal-box">
    <div class="modal-header">
      <h4>📁 پروژه جدید</h4>
      <button class="btn-close" onclick="closeModal('modal-project')">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">نام پروژه <span style="color:red">*</span></label>
        <input type="text" class="form-control" id="project-name" placeholder="نام پروژه">
      </div>
      <div class="form-group">
        <label class="form-label">توضیحات</label>
        <textarea class="form-control" id="project-desc" rows="2" placeholder="هدف پروژه..."></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">پیشرفت (٪)</label>
          <input type="number" class="form-control" id="project-progress" value="0" min="0" max="100">
        </div>
        <div class="form-group">
          <label class="form-label">ددلاین</label>
          <input type="text" class="form-control" id="project-deadline" placeholder="1403/06/31">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">اعضای تیم (با کاما جدا کنید)</label>
        <input type="text" class="form-control" id="project-members" placeholder="امیر نوری، پریناز صالح">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-project')">انصراف</button>
      <button class="btn btn-primary" onclick="saveProject()">✓ ایجاد پروژه</button>
    </div>
  </div>
</div>

<script>
// ===========================
// توابع کانبان + Drag & Drop
// ===========================
let draggedTaskId = null;

function handleDragStart(e) {
    draggedTaskId = e.target.dataset.taskId;
    e.target.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
}

function handleDragEnd(e) {
    e.target.classList.remove('dragging');
    document.querySelectorAll('.kanban-column').forEach(c => c.classList.remove('drop-target'));
}

function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    e.currentTarget.classList.add('drop-target');
}

function handleDragLeave(e) {
    if (e.target === e.currentTarget) {
        e.currentTarget.classList.remove('drop-target');
    }
}

async function handleDrop(e) {
    e.preventDefault();
    e.currentTarget.classList.remove('drop-target');
    const newStatus = e.currentTarget.dataset.status;
    if (!draggedTaskId || !newStatus) return;

    // به‌روزرسانی از طریق API
    try {
        const result = await fetchAPI(`tasks/${draggedTaskId}/status`, {
            method: 'PATCH',
            body: { status: newStatus }
        });
        if (result.success) {
            showToast('وضعیت تسک تغییر کرد', 'success');
            setTimeout(() => window.location.reload(), 600);
        } else {
            showToast(result.message, 'error');
        }
    } catch (err) {
        showToast('خطا در به‌روزرسانی', 'error');
    }
    draggedTaskId = null;
}

// ===========================
// ناوبری بین نماها
// ===========================
function switchView(view) {
    const params = new URLSearchParams(window.location.search);
    params.set('page', 'tasks');
    params.set('view', view);
    window.location.href = '<?= BASE_URL ?>/index.php?' + params.toString();
}

function applyAssigneeFilter() {
    const params = new URLSearchParams({page: 'tasks'});
    const v = document.getElementById('task-assignee-filter').value;
    if (v) params.set('assignee', v);
    params.set('view', '<?= $view ?>');
    window.location.href = '<?= BASE_URL ?>/index.php?' + params.toString();
}

// ===========================
// مودال تسک
// ===========================
function openTaskModal() {
    document.getElementById('task-modal-title').textContent = '✅ تسک جدید';
    document.getElementById('task-id').value = '';
    ['task-title','task-desc','task-end'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('task-priority').value = 'medium';
    document.getElementById('task-status').value = 'new';
    document.getElementById('task-project-id').value = '';
    document.getElementById('task-assignee').value = '';
    document.getElementById('task-start').value = '<?= Jalali::today() ?>';
    openModal('modal-add-task');
}

async function saveTask() {
    const id = document.getElementById('task-id').value;
    const data = {
        title: document.getElementById('task-title').value.trim(),
        description: document.getElementById('task-desc').value.trim(),
        priority: document.getElementById('task-priority').value,
        status: document.getElementById('task-status').value,
        project_id: parseInt(document.getElementById('task-project-id').value) || null,
        assignee: document.getElementById('task-assignee').value,
        start_date: document.getElementById('task-start').value.trim(),
        end_date: document.getElementById('task-end').value.trim(),
    };
    if (!data.title) return showToast('عنوان الزامی است', 'warning');
    try {
        const result = id
            ? await fetchAPI('tasks/' + id, { method: 'PUT', body: data })
            : await fetchAPI('tasks', { method: 'POST', body: data });
        if (result.success) {
            showToast(id ? 'تسک به‌روزرسانی شد' : 'تسک ایجاد شد', 'success');
            closeModal('modal-add-task');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}

async function openTaskDetail(id) {
    try {
        const result = await fetchAPI('tasks/' + id);
        if (!result.success) return showToast(result.message, 'error');
        const t = result.data;
        document.getElementById('task-detail-title').textContent = t.title;

        let subtasksHtml = '';
        if (t.subtasks_array && t.subtasks_array.length > 0) {
            subtasksHtml = '<div class="fw-700 mb-2">زیرتسک‌ها</div><div class="subtask-list mb-4">';
            t.subtasks_array.forEach((st, i) => {
                subtasksHtml += `
                    <div class="subtask-item" style="display:flex;align-items:center;gap:8px;padding:6px;border-bottom:1px solid var(--border)">
                        <input type="checkbox" ${st.done ? 'checked' : ''} onchange="toggleSubtask(${t.id}, ${i}, this.checked)">
                        <span style="${st.done ? 'text-decoration:line-through;color:var(--text-muted)' : ''}">${escapeHtml(st.text)}</span>
                    </div>
                `;
            });
            subtasksHtml += '</div>';
        }

        let commentsHtml = '';
        if (t.comments_array && t.comments_array.length > 0) {
            commentsHtml = '<div class="fw-700 mb-2">کامنت‌ها</div>';
            t.comments_array.forEach(c => {
                commentsHtml += `
                    <div style="background:var(--bg-secondary);padding:8px 12px;border-radius:8px;margin-bottom:6px">
                        <div style="font-size:11px;color:var(--text-muted)">${c.user} - ${c.date}</div>
                        <div>${escapeHtml(c.text)}</div>
                    </div>
                `;
            });
        }

        document.getElementById('task-detail-body').innerHTML = `
            <div style="display:grid;grid-template-columns:1fr 200px;gap:16px">
                <div>
                    <p style="color:var(--text-muted);font-size:13.5px;margin-bottom:16px">${escapeHtml(t.description || '')}</p>
                    ${subtasksHtml}
                    ${commentsHtml}
                    <div style="display:flex;gap:8px;margin-top:10px">
                        <input type="text" class="form-control" id="new-comment-input" placeholder="کامنت جدید...">
                        <button class="btn btn-primary btn-sm" onclick="addComment(${t.id})">ارسال</button>
                    </div>
                </div>
                <div>
                    <div class="mb-3">
                        <div class="text-muted fs-12 mb-1">اولویت</div>
                        <span class="badge ${t.priority === 'high' ? 'badge-danger' : 'badge-warning'}">${t.priority === 'high' ? 'بالا' : t.priority === 'medium' ? 'متوسط' : 'کم'}</span>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted fs-12 mb-1">وضعیت</div>
                        <span class="badge ${t.status === 'done' ? 'badge-success' : 'badge-info'}">${Task.statusLabels[t.status]}</span>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted fs-12 mb-1">پروژه</div>
                        <div class="fw-600">${t.project_name || t.project || '-'}</div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted fs-12 mb-1">مسئول</div>
                        <div class="fw-600">${t.assignee || '-'}</div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted fs-12 mb-1">تاریخ</div>
                        <div class="fw-600">${t.start_date || '-'} تا ${t.end_date || '-'}</div>
                    </div>
                    <button class="btn btn-danger w-100" onclick="if(confirm('حذف تسک؟')){deleteTask(${t.id})}">🗑 حذف تسک</button>
                </div>
            </div>
        `;
        openModal('modal-task-detail');
    } catch (err) { showToast('خطا', 'error'); }
}

async function toggleSubtask(taskId, index, checked) {
    try {
        await fetchAPI(`tasks/${taskId}/subtasks/${index}`, {
            method: 'PATCH',
            body: { done: checked }
        });
        showToast('زیرتسک به‌روزرسانی شد', 'success');
    } catch (err) { showToast('خطا', 'error'); }
}

async function addComment(taskId) {
    const input = document.getElementById('new-comment-input');
    const text = input.value.trim();
    if (!text) return;
    try {
        const result = await fetchAPI(`tasks/${taskId}/comments`, { method: 'POST', body: { text } });
        if (result.success) {
            showToast('کامنت ثبت شد', 'success');
            openTaskDetail(taskId);  // refresh
        }
    } catch (err) { showToast('خطا', 'error'); }
}

async function deleteTask(id) {
    try {
        await fetchAPI('tasks/' + id, { method: 'DELETE' });
        showToast('تسک حذف شد', 'success');
        closeModal('modal-task-detail');
        setTimeout(() => window.location.reload(), 800);
    } catch (err) { showToast('خطا', 'error'); }
}

// ===========================
// مودال پروژه
// ===========================
function openProjectModal() {
    ['project-name','project-desc','project-deadline','project-members'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('project-progress').value = '0';
    openModal('modal-project');
}

async function saveProject() {
    const data = {
        name: document.getElementById('project-name').value.trim(),
        description: document.getElementById('project-desc').value.trim(),
        progress: parseInt(document.getElementById('project-progress').value) || 0,
        deadline: document.getElementById('project-deadline').value.trim(),
        members: document.getElementById('project-members').value.split(',').map(s => s.trim()).filter(s => s),
    };
    if (!data.name) return showToast('نام پروژه الزامی است', 'warning');
    try {
        const result = await fetchAPI('projects', { method: 'POST', body: data });
        if (result.success) {
            showToast('پروژه ایجاد شد', 'success');
            closeModal('modal-project');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Task status labels برای JS
const Task = {
    statusLabels: {
        new: '🆕 جدید',
        in_progress: '🔄 در حال انجام',
        review: '👀 در انتظار بررسی',
        done: '✅ انجام شده',
        cancelled: '❌ لغو شده'
    }
};
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
