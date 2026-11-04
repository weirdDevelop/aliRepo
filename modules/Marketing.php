<?php
/**
 * modules/Marketing.php
 * ماژول مارکتینگ — لایه‌ی اول: اهداف، نقش‌ها (چارت سازمانی)، اعضای تیم.
 * هر متد با گارد مهاجرت محافظت می‌شود تا قبل از «به‌روزرسانی دیتابیس» سفیدنشدن صفحه را تضمین کند.
 */
class Marketing
{
    private static $tableCache = [];

    /** آیا جدول وجود دارد؟ (کش‌شده در همان درخواست) — گارد مهاجرت. */
    private static function hasTable(string $t): bool
    {
        if (array_key_exists($t, self::$tableCache)) return self::$tableCache[$t];
        try {
            // نکته: «SHOW TABLES LIKE ?» با placeholder در MySQL کار نمی‌کند؛
            // به‌جای آن از information_schema با پارامتر امن استفاده می‌کنیم.
            $row = Database::one(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
                [$t]
            );
            return self::$tableCache[$t] = (bool)$row;
        } catch (Throwable $e) {
            return self::$tableCache[$t] = false;
        }
    }

    /** آیا ماژول آماده است؟ (هر سه جدول ساخته شده‌اند) */
    public static function ready(): bool
    {
        return self::hasTable('eb_mkt_goals')
            && self::hasTable('eb_mkt_roles')
            && self::hasTable('eb_mkt_members')
            && self::hasTable('eb_mkt_content');
    }

    // ---------------- اهداف ----------------
    public static function goals(?string $period = null): array
    {
        if (!self::hasTable('eb_mkt_goals')) return [];
        if ($period) {
            return Database::all(
                "SELECT g.*, m.full_name AS owner_name
                 FROM eb_mkt_goals g LEFT JOIN eb_mkt_members m ON m.id=g.owner_id
                 WHERE g.period=? ORDER BY g.due_date IS NULL, g.due_date, g.id DESC",
                [$period]
            );
        }
        return Database::all(
            "SELECT g.*, m.full_name AS owner_name
             FROM eb_mkt_goals g LEFT JOIN eb_mkt_members m ON m.id=g.owner_id
             ORDER BY FIELD(g.period,'month','week','day','campaign'), g.id DESC"
        );
    }

    public static function saveGoal(array $d): int
    {
        $period = in_array(($d['period'] ?? 'month'), ['month','week','day','campaign'], true) ? $d['period'] : 'month';
        $title  = trim($d['title'] ?? '');
        if ($title === '') throw new RuntimeException('عنوان هدف لازم است');
        $params = [
            $period, $title,
            trim($d['metric'] ?? '') ?: null,
            (float)($d['target_value'] ?? 0),
            (float)($d['current_value'] ?? 0),
            !empty($d['owner_id']) ? (int)$d['owner_id'] : null,
            !empty($d['due_date']) ? $d['due_date'] : null,
        ];
        if (!empty($d['id'])) {
            Database::run(
                "UPDATE eb_mkt_goals SET period=?, title=?, metric=?, target_value=?, current_value=?, owner_id=?, due_date=? WHERE id=?",
                array_merge($params, [(int)$d['id']])
            );
            return (int)$d['id'];
        }
        Database::run(
            "INSERT INTO eb_mkt_goals (period,title,metric,target_value,current_value,owner_id,due_date) VALUES (?,?,?,?,?,?,?)",
            $params
        );
        return (int)Database::pdo()->lastInsertId();
    }

    public static function deleteGoal(int $id): void
    {
        if (!self::hasTable('eb_mkt_goals')) return;
        Database::run("DELETE FROM eb_mkt_goals WHERE id=?", [$id]);
    }

    // ---------------- نقش‌ها (چارت سازمانی) ----------------
    public static function roles(): array
    {
        if (!self::hasTable('eb_mkt_roles')) return [];
        $roles = Database::all("SELECT * FROM eb_mkt_roles ORDER BY sort_order, id");
        $members = self::hasTable('eb_mkt_members')
            ? Database::all("SELECT id, role_id, full_name, email, phone FROM eb_mkt_members ORDER BY id")
            : [];
        $byRole = [];
        foreach ($members as $m) { $byRole[(int)$m['role_id']][] = $m; }
        foreach ($roles as &$r) { $r['members'] = $byRole[(int)$r['id']] ?? []; }
        return $roles;
    }

    public static function saveRole(array $d): int
    {
        $title = trim($d['title'] ?? '');
        if ($title === '') throw new RuntimeException('عنوان نقش لازم است');
        $level = in_array(($d['level'] ?? 'specialist'), ['strategic','specialist','executive'], true) ? $d['level'] : 'specialist';
        $parent = !empty($d['parent_id']) ? (int)$d['parent_id'] : null;
        $duties = trim($d['duties'] ?? '') ?: null;
        if (!empty($d['id'])) {
            // جلوگیری از حلقه: نقش نمی‌تواند والد خودش شود
            if ($parent === (int)$d['id']) $parent = null;
            Database::run("UPDATE eb_mkt_roles SET parent_id=?, title=?, level=?, duties=? WHERE id=?",
                [$parent, $title, $level, $duties, (int)$d['id']]);
            return (int)$d['id'];
        }
        Database::run("INSERT INTO eb_mkt_roles (parent_id,title,level,duties) VALUES (?,?,?,?)",
            [$parent, $title, $level, $duties]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function deleteRole(int $id): void
    {
        if (!self::hasTable('eb_mkt_roles')) return;
        // فرزندان را به والدِ نقشِ حذف‌شده وصل کن (درخت نشکند)
        $node = Database::one("SELECT parent_id FROM eb_mkt_roles WHERE id=?", [$id]);
        $newParent = $node ? $node['parent_id'] : null;
        Database::run("UPDATE eb_mkt_roles SET parent_id=? WHERE parent_id=?", [$newParent, $id]);
        if (self::hasTable('eb_mkt_members')) {
            Database::run("UPDATE eb_mkt_members SET role_id=NULL WHERE role_id=?", [$id]);
        }
        Database::run("DELETE FROM eb_mkt_roles WHERE id=?", [$id]);
    }

    // ---------------- اعضای تیم ----------------
    public static function members(): array
    {
        if (!self::hasTable('eb_mkt_members')) return [];
        $linkSel = self::hasMemberLink() ? ", " . Auth::nameSql('u') . " AS link_username" : "";
        $linkJoin = self::hasMemberLink() ? "LEFT JOIN eb_users u ON u.id=m.user_id" : "";
        return Database::all(
            "SELECT m.*, r.title AS role_title{$linkSel} FROM eb_mkt_members m
             LEFT JOIN eb_mkt_roles r ON r.id=m.role_id {$linkJoin} ORDER BY m.id DESC"
        );
    }

    /** کاربران اعلان‌بان که هنوز به هیچ عضوی لینک نشده‌اند (برای انتخاب). */
    public static function linkableUsers(): array
    {
        if (!self::hasTable('eb_users')) return [];
        $linked = [];
        if (self::hasMemberLink()) {
            foreach (Database::all("SELECT user_id FROM eb_mkt_members WHERE user_id IS NOT NULL") as $r) {
                $linked[(int)$r['user_id']] = true;
            }
        }
        $users = Database::all("SELECT id, " . Auth::nameSql('') . " AS username, username AS login, role FROM eb_users WHERE is_active=1 AND role <> 'viewer' ORDER BY username");
        return array_values(array_filter($users, fn($u) => !isset($linked[(int)$u['id']])));
    }

    public static function saveMember(array $d): int
    {
        $name = trim($d['full_name'] ?? '');
        if ($name === '') throw new RuntimeException('نام عضو لازم است');
        $params = [
            $name,
            trim($d['email'] ?? '') ?: null,
            trim($d['phone'] ?? '') ?: null,
            !empty($d['role_id']) ? (int)$d['role_id'] : null,
        ];
        if (!empty($d['id'])) {
            Database::run("UPDATE eb_mkt_members SET full_name=?, email=?, phone=?, role_id=? WHERE id=?",
                array_merge($params, [(int)$d['id']]));
            $mid = (int)$d['id'];
        } else {
            Database::run("INSERT INTO eb_mkt_members (full_name,email,phone,role_id) VALUES (?,?,?,?)", $params);
            $mid = (int)Database::pdo()->lastInsertId();
        }
        self::saveMemberLink($mid, $d);
        return $mid;
    }

    /** ذخیره‌ی پیوند پرسنل به کاربر اعلان‌بان + دپارتمان (در صورت وجود ستون‌ها). */
    private static function saveMemberLink(int $mid, array $d): void
    {
        if (!$mid || !self::hasMemberLink()) return;
        if (!array_key_exists('user_id', $d) && !array_key_exists('department', $d)) return;
        $uid = !empty($d['user_id']) ? (int)$d['user_id'] : null;
        $dep = in_array(($d['department'] ?? 'marketing'), ['marketing','sales','content','design','other'], true)
             ? $d['department'] : 'marketing';
        // جلوگیری از لینک یک کاربر به دو عضو
        if ($uid) {
            Database::run("UPDATE eb_mkt_members SET user_id=NULL WHERE user_id=? AND id<>?", [$uid, $mid]);
        }
        Database::run("UPDATE eb_mkt_members SET user_id=?, department=? WHERE id=?", [$uid, $dep, $mid]);
    }

    /** عضو مارکتینگِ متناظر با یک کاربر اعلان‌بان (برای «تسک‌های من»). */
    public static function memberByUser(int $userId): ?array
    {
        if (!$userId || !self::hasMemberLink()) return null;
        $row = Database::one("SELECT * FROM eb_mkt_members WHERE user_id=? LIMIT 1", [$userId]);
        return $row ?: null;
    }

    public static function deleteMember(int $id): void
    {
        if (!self::hasTable('eb_mkt_members')) return;
        Database::run("DELETE FROM eb_mkt_members WHERE id=?", [$id]);
    }

    // ---------------- محتوا / وظایف (تقویم + کانبان) ----------------
    const STATUSES = ['idea','producing','review','ready','published'];

    public static function contentReady(): bool
    {
        return self::hasTable('eb_mkt_content');
    }

    /** آیا ستون‌های پیوند پرسنل (user_id/department) موجودند؟ */
    private static $hasMemberLink = null;
    private static function hasMemberLink(): bool
    {
        if (self::$hasMemberLink !== null) return self::$hasMemberLink;
        if (!self::hasTable('eb_mkt_members')) return self::$hasMemberLink = false;
        try {
            $row = Database::one(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='eb_mkt_members'
                       AND COLUMN_NAME='user_id' LIMIT 1"
            );
            return self::$hasMemberLink = (bool)$row;
        } catch (Throwable $e) {
            return self::$hasMemberLink = false;
        }
    }

    /** آیا ستون‌های مالی (budget/spent) موجودند؟ */
    private static $hasMoney = null;
    private static function hasMoney(): bool
    {
        if (self::$hasMoney !== null) return self::$hasMoney;
        if (!self::hasTable('eb_mkt_content')) return self::$hasMoney = false;
        try {
            $row = Database::one(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='eb_mkt_content'
                       AND COLUMN_NAME='budget' LIMIT 1"
            );
            return self::$hasMoney = (bool)$row;
        } catch (Throwable $e) {
            return self::$hasMoney = false;
        }
    }

    /** آیا ستون fields_json (مهاجرت‌شده) موجود است؟ — گارد مهاجرت. */
    private static $hasFieldsJson = null;
    private static function hasFieldsJson(): bool
    {
        if (self::$hasFieldsJson !== null) return self::$hasFieldsJson;
        if (!self::hasTable('eb_mkt_content')) return self::$hasFieldsJson = false;
        try {
            $row = Database::one(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_mkt_content'
                       AND COLUMN_NAME = 'fields_json' LIMIT 1"
            );
            return self::$hasFieldsJson = (bool)$row;
        } catch (Throwable $e) {
            return self::$hasFieldsJson = false;
        }
    }

    // ---------------- پیج‌ها / اکانت‌های شرکت (برای تفکیک تقویم محتوا) ----------------
    private static $pagesReady = null;

    /** جدول پیج‌ها و ستون page_id محتوا — خودکار ساخته می‌شوند (نیازی به «به‌روزرسانی دیتابیس» نیست) */
    public static function ensurePages(): bool
    {
        if (self::$pagesReady !== null) return self::$pagesReady;
        try {
            Database::run("CREATE TABLE IF NOT EXISTS eb_mkt_pages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                platform VARCHAR(40) DEFAULT NULL,
                handle VARCHAR(120) DEFAULT NULL,
                color VARCHAR(7) NOT NULL DEFAULT '#6366f1',
                ig_account_id INT DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ig (ig_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            if (self::hasTable('eb_mkt_content') && !Database::all("SHOW COLUMNS FROM eb_mkt_content LIKE 'page_id'")) {
                Database::run("ALTER TABLE eb_mkt_content ADD COLUMN page_id INT DEFAULT NULL");
                try { Database::run("ALTER TABLE eb_mkt_content ADD INDEX idx_page (page_id)"); } catch (Throwable $e) {}
            }
            return self::$pagesReady = true;
        } catch (Throwable $e) {
            error_log('[Marketing::ensurePages] ' . $e->getMessage());
            return self::$pagesReady = false;
        }
    }

    /** پیج‌های اینستاگرام متصل (یار API / سرویس‌دهنده) خودکار به‌عنوان پیج تقویم اضافه می‌شوند */
    private static function syncPagesFromInstagram(): void
    {
        if (!self::hasTable('eb_ig_accounts')) return;
        try {
            foreach (Database::all("SELECT id, label, username, color FROM eb_ig_accounts WHERE is_active = 1") as $a) {
                if (Database::one("SELECT id FROM eb_mkt_pages WHERE ig_account_id = ?", [(int)$a['id']])) continue;
                $name = trim((string)$a['label']) !== '' ? $a['label'] : ($a['username'] ? '@' . ltrim($a['username'], '@') : 'پیج #' . $a['id']);
                Database::run("INSERT INTO eb_mkt_pages (name, platform, handle, color, ig_account_id) VALUES (?, 'instagram', ?, ?, ?)",
                    [$name, $a['username'] ?: null, preg_match('/^#[0-9a-f]{6}$/i', (string)$a['color']) ? $a['color'] : '#6366f1', (int)$a['id']]);
            }
        } catch (Throwable $e) {}
    }

    public static function pages(bool $withInactive = false): array
    {
        if (!self::ensurePages()) return [];
        self::syncPagesFromInstagram();
        $rows = Database::all("SELECT p.*, (SELECT COUNT(*) FROM eb_mkt_content c WHERE c.page_id = p.id) AS content_count
                               FROM eb_mkt_pages p" . ($withInactive ? '' : ' WHERE p.is_active = 1') . " ORDER BY p.sort_order, p.id");
        return $rows;
    }

    public static function savePage(array $d): int
    {
        if (!self::ensurePages()) throw new RuntimeException('جدول پیج‌ها ساخته نشد');
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') throw new RuntimeException('نام پیج لازم است');
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string)($d['color'] ?? '')) ? $d['color'] : '#6366f1';
        $platform = trim((string)($d['platform'] ?? '')) ?: null;
        $handle = trim((string)($d['handle'] ?? '')) ?: null;
        $active = isset($d['is_active']) ? (!empty($d['is_active']) ? 1 : 0) : 1;
        if (!empty($d['id'])) {
            Database::run("UPDATE eb_mkt_pages SET name=?, platform=?, handle=?, color=?, is_active=?, sort_order=? WHERE id=?",
                [$name, $platform, $handle, $color, $active, (int)($d['sort_order'] ?? 0), (int)$d['id']]);
            return (int)$d['id'];
        }
        $dup = Database::one("SELECT id FROM eb_mkt_pages WHERE name = ?", [$name]);
        if ($dup) return (int)$dup['id'];
        Database::run("INSERT INTO eb_mkt_pages (name, platform, handle, color, is_active, sort_order) VALUES (?,?,?,?,?,?)",
            [$name, $platform, $handle, $color, $active, (int)($d['sort_order'] ?? 0)]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** حذف پیج: محتواهایش «بدون پیج» می‌شوند (حذف نمی‌شوند). پیج اینستاگرامی فقط غیرفعال می‌شود تا دوباره ساخته نشود. */
    public static function deletePage(int $id): void
    {
        if (!self::ensurePages()) return;
        Database::run("UPDATE eb_mkt_content SET page_id = NULL WHERE page_id = ?", [$id]);
        $p = Database::one("SELECT ig_account_id FROM eb_mkt_pages WHERE id = ?", [$id]);
        if ($p && $p['ig_account_id']) Database::run("UPDATE eb_mkt_pages SET is_active = 0 WHERE id = ?", [$id]);
        else Database::run("DELETE FROM eb_mkt_pages WHERE id = ?", [$id]);
    }

    /** لیست محتوا؛ با فیلتر اختیاری بازه‌ی تاریخ (برای تقویم) یا همه (برای کانبان). */
    public static function content(?string $from = null, ?string $to = null, ?int $pageId = null): array
    {
        if (!self::hasTable('eb_mkt_content')) return [];
        $pg = self::ensurePages();
        $hasSteps = self::hasTable('eb_mkt_content_steps');
        $progSel = $hasSteps
            ? ", (SELECT COUNT(*) FROM eb_mkt_content_steps s WHERE s.content_id=c.id) AS steps_total,
                 (SELECT COUNT(*) FROM eb_mkt_content_steps s WHERE s.content_id=c.id AND s.done_at IS NOT NULL) AS steps_done"
            : ", 0 AS steps_total, 0 AS steps_done";
        $pageSel = $pg ? ", pg.name AS page_name, pg.color AS page_color, pg.platform AS page_platform" : "";
        $pageJoin = $pg ? " LEFT JOIN eb_mkt_pages pg ON pg.id=c.page_id" : "";
        $sql = "SELECT c.*, m.full_name AS assignee_name{$progSel}{$pageSel} FROM eb_mkt_content c
                LEFT JOIN eb_mkt_members m ON m.id=c.assignee_id{$pageJoin}";
        $params = []; $where = [];
        if ($from && $to) { $where[] = "c.plan_date BETWEEN ? AND ?"; $params[] = $from; $params[] = $to; }
        if ($pg && $pageId !== null) {
            if ($pageId === 0) $where[] = "c.page_id IS NULL";
            else { $where[] = "c.page_id = ?"; $params[] = $pageId; }
        }
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY c.plan_date IS NULL, c.plan_date, c.plan_time, c.sort_order, c.id";
        return Database::all($sql, $params);
    }

    public static function saveContent(array $d): int
    {
        $title = trim($d['title'] ?? '');
        if ($title === '') throw new RuntimeException('عنوان محتوا لازم است');
        $status = in_array(($d['status'] ?? 'idea'), self::STATUSES, true) ? ($d['status'] ?? 'idea') : 'idea';
        // فیلدهای اختصاصی کانال (JSON) — فقط اگر ستونش موجود باشد ذخیره می‌شود
        $fieldsJson = null;
        if (isset($d['fields']) && is_array($d['fields']) && $d['fields']) {
            $clean = [];
            foreach ($d['fields'] as $k => $v) {
                $k = preg_replace('/[^a-z0-9_]/i', '', (string)$k);
                if ($k === '') continue;
                if (is_array($v)) {
                    $v = array_values(array_filter(array_map(fn($x) => trim((string)$x), $v), fn($x) => $x !== ''));
                } else {
                    $v = trim((string)$v);
                }
                if ($v !== '' && $v !== []) $clean[$k] = $v;
            }
            if ($clean) $fieldsJson = json_encode($clean, JSON_UNESCAPED_UNICODE);
        }
        $params = [
            $title,
            trim($d['channel'] ?? '') ?: null,
            trim($d['content_type'] ?? '') ?: null,
            $status,
            !empty($d['assignee_id']) ? (int)$d['assignee_id'] : null,
            !empty($d['plan_date']) ? $d['plan_date'] : null,
            (preg_match('/^\d{2}:\d{2}$/', $d['plan_time'] ?? '') ? $d['plan_time'] : null),
            trim($d['scenario'] ?? '') ?: null,
            ($status === 'published' ? date('Y-m-d H:i:s') : null),
        ];
        $withFields = self::hasFieldsJson();
        if (!empty($d['id'])) {
            if ($status === 'published') {
                $prev = Database::one("SELECT published_at FROM eb_mkt_content WHERE id=?", [(int)$d['id']]);
                if ($prev && $prev['published_at']) $params[8] = $prev['published_at'];
            }
            if ($withFields) {
                Database::run(
                    "UPDATE eb_mkt_content SET title=?, channel=?, content_type=?, status=?, assignee_id=?, plan_date=?, plan_time=?, scenario=?, published_at=?, fields_json=? WHERE id=?",
                    array_merge($params, [$fieldsJson, (int)$d['id']])
                );
            } else {
                Database::run(
                    "UPDATE eb_mkt_content SET title=?, channel=?, content_type=?, status=?, assignee_id=?, plan_date=?, plan_time=?, scenario=?, published_at=? WHERE id=?",
                    array_merge($params, [(int)$d['id']])
                );
            }
            self::saveMoney((int)$d['id'], $d);
            self::savePageRef((int)$d['id'], $d);
            return (int)$d['id'];
        }
        if ($withFields) {
            Database::run(
                "INSERT INTO eb_mkt_content (title,channel,content_type,status,assignee_id,plan_date,plan_time,scenario,published_at,fields_json) VALUES (?,?,?,?,?,?,?,?,?,?)",
                array_merge($params, [$fieldsJson])
            );
        } else {
            Database::run(
                "INSERT INTO eb_mkt_content (title,channel,content_type,status,assignee_id,plan_date,plan_time,scenario,published_at) VALUES (?,?,?,?,?,?,?,?,?)",
                $params
            );
        }
        $newId = (int)Database::pdo()->lastInsertId();
        self::saveMoney($newId, $d);
        self::savePageRef($newId, $d);
        // تزریق رودمپ پیش‌فرض بر اساس نوع محتوا (فقط برای محتوای تازه)
        self::seedSteps($newId, trim($d['content_type'] ?? ''));
        return $newId;
    }

    /** پیج محتوا: page_id موجود، یا page_name برای ساخت پیج تازه از همان فرم */
    private static function savePageRef(int $id, array $d): void
    {
        if (!$id || !self::ensurePages()) return;
        if (!array_key_exists('page_id', $d) && empty($d['page_name'])) return;
        $pid = !empty($d['page_id']) ? (int)$d['page_id'] : null;
        if (!$pid && trim((string)($d['page_name'] ?? '')) !== '') {
            $pid = self::savePage(['name' => $d['page_name'], 'platform' => $d['channel'] ?? null]);
        }
        Database::run("UPDATE eb_mkt_content SET page_id = ? WHERE id = ?", [$pid, $id]);
    }

    /** ذخیره‌ی بودجه/خرج‌کرد محتوا (در صورت وجود ستون‌ها) — جدا برای سادگی کوئری اصلی. */
    private static function saveMoney(int $id, array $d): void
    {
        if (!$id || !self::hasMoney()) return;
        if (!array_key_exists('budget', $d) && !array_key_exists('spent', $d)) return;
        $budget = isset($d['budget']) ? (float)preg_replace('/[^0-9.]/', '', (string)$d['budget']) : 0;
        $spent  = isset($d['spent'])  ? (float)preg_replace('/[^0-9.]/', '', (string)$d['spent'])  : 0;
        Database::run("UPDATE eb_mkt_content SET budget=?, spent=? WHERE id=?", [$budget, $spent, $id]);
    }

    /** تغییر وضعیت سریع (جابه‌جایی کارت کانبان). */
    public static function moveContent(int $id, string $status): void
    {
        if (!self::hasTable('eb_mkt_content')) return;
        if (!in_array($status, self::STATUSES, true)) return;
        $pub = ($status === 'published') ? date('Y-m-d H:i:s') : null;
        if ($status === 'published') {
            $prev = Database::one("SELECT published_at FROM eb_mkt_content WHERE id=?", [$id]);
            if ($prev && $prev['published_at']) $pub = $prev['published_at'];
        }
        Database::run("UPDATE eb_mkt_content SET status=?, published_at=? WHERE id=?", [$status, $pub, $id]);
    }

    public static function deleteContent(int $id): void
    {
        if (!self::hasTable('eb_mkt_content')) return;
        Database::run("DELETE FROM eb_mkt_content WHERE id=?", [$id]);
    }

    // ---------------- بودجه و مالی ----------------
    public static function budgetsReady(): bool
    {
        return self::hasTable('eb_mkt_budgets');
    }

    /** فهرست بودجه‌های یک دوره (period مثل 1405-04) همراه با خرج‌کرد واقعی محاسبه‌شده. */
    public static function budgets(?string $period = null): array
    {
        if (!self::hasTable('eb_mkt_budgets')) return [];
        if ($period) {
            return Database::all("SELECT * FROM eb_mkt_budgets WHERE period=? ORDER BY channel", [$period]);
        }
        return Database::all("SELECT * FROM eb_mkt_budgets ORDER BY period DESC, channel");
    }

    public static function saveBudget(array $d): int
    {
        if (!self::hasTable('eb_mkt_budgets')) throw new RuntimeException('ابتدا دیتابیس را به‌روزرسانی کنید');
        $period = trim($d['period'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) throw new RuntimeException('دوره نامعتبر است (مثل 1405-04)');
        $channel = trim($d['channel'] ?? '') ?: null;
        $amount = (float)preg_replace('/[^0-9.]/', '', (string)($d['amount'] ?? 0));
        $note = trim($d['note'] ?? '') ?: null;
        if (!empty($d['id'])) {
            Database::run("UPDATE eb_mkt_budgets SET period=?, channel=?, amount=?, note=? WHERE id=?",
                [$period, $channel, $amount, $note, (int)$d['id']]);
            return (int)$d['id'];
        }
        // upsert بر اساس (period, channel)
        $exist = Database::one("SELECT id FROM eb_mkt_budgets WHERE period=? AND (channel<=>?)", [$period, $channel]);
        if ($exist) {
            Database::run("UPDATE eb_mkt_budgets SET amount=?, note=? WHERE id=?", [$amount, $note, (int)$exist['id']]);
            return (int)$exist['id'];
        }
        Database::run("INSERT INTO eb_mkt_budgets (period,channel,amount,note) VALUES (?,?,?,?)",
            [$period, $channel, $amount, $note]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function deleteBudget(int $id): void
    {
        if (!self::hasTable('eb_mkt_budgets')) return;
        Database::run("DELETE FROM eb_mkt_budgets WHERE id=?", [$id]);
    }

    /**
     * گزارش مالی یک دوره:
     * - خرج‌کرد واقعی هر کانال (از eb_mkt_content بر اساس ماه میلادیِ plan_date)
     * - بودجه‌ی تعیین‌شده هر کانال
     * - مقایسه و درصد مصرف
     * gregFrom/gregTo: بازه‌ی میلادیِ معادل دوره‌ی شمسی (از سمت JS می‌آید).
     */
    public static function financeReport(string $period, ?string $gregFrom = null, ?string $gregTo = null): array
    {
        $out = ['period'=>$period, 'rows'=>[], 'total_budget'=>0, 'total_spent'=>0, 'total_planned'=>0];
        if (!self::hasTable('eb_mkt_content')) return $out;

        $spentByCh = [];
        if (self::hasMoney() && $gregFrom && $gregTo) {
            $rows = Database::all(
                "SELECT COALESCE(channel,'—') channel,
                        SUM(spent) spent, SUM(budget) planned, COUNT(*) cnt
                 FROM eb_mkt_content
                 WHERE plan_date BETWEEN ? AND ?
                 GROUP BY channel",
                [$gregFrom, $gregTo]
            );
            foreach ($rows as $r) $spentByCh[$r['channel']] = $r;
        }

        $budByCh = [];
        if (self::hasTable('eb_mkt_budgets')) {
            foreach (Database::all("SELECT channel, amount FROM eb_mkt_budgets WHERE period=?", [$period]) as $b) {
                $budByCh[$b['channel'] ?? '—'] = (float)$b['amount'];
            }
        }

        $channels = array_unique(array_merge(array_keys($spentByCh), array_keys($budByCh)));
        foreach ($channels as $ch) {
            $budget = $budByCh[$ch] ?? 0;
            $spent = isset($spentByCh[$ch]) ? (float)$spentByCh[$ch]['spent'] : 0;
            $planned = isset($spentByCh[$ch]) ? (float)$spentByCh[$ch]['planned'] : 0;
            $cnt = isset($spentByCh[$ch]) ? (int)$spentByCh[$ch]['cnt'] : 0;
            $out['rows'][] = [
                'channel' => $ch,
                'budget' => $budget,
                'spent' => $spent,
                'planned' => $planned,
                'count' => $cnt,
                'pct' => $budget > 0 ? round($spent / $budget * 100) : null,
                'over' => $budget > 0 && $spent > $budget,
            ];
            $out['total_budget'] += $budget;
            $out['total_spent'] += $spent;
            $out['total_planned'] += $planned;
        }
        usort($out['rows'], fn($a,$b) => $b['spent'] <=> $a['spent']);
        return $out;
    }


    // ---------------- عوامل (crew) و رودمپ مراحل ----------------
    public static function crewStepsReady(): bool
    {
        return self::hasTable('eb_mkt_content_crew') && self::hasTable('eb_mkt_content_steps');
    }

    /** قالب پیش‌فرض رودمپ بر اساس نوع محتوا. خروجی: آرایه‌ای از نام مراحل. */
    public static function roadmapTemplate(string $type): array
    {
        $T = [
            'ریلز'       => ['ایده', 'سناریو', 'فیلم‌برداری', 'تدوین', 'کاور و کپشن', 'بازبینی', 'انتشار'],
            'تریال'      => ['ایده', 'سناریو', 'فیلم‌برداری', 'تدوین', 'انتشار تست', 'بررسی نتیجه'],
            'ریلز کولَب' => ['ایده', 'هماهنگی با همکار', 'سناریو', 'فیلم‌برداری', 'تدوین', 'بازبینی', 'انتشار'],
            'استوری'     => ['ایده', 'طراحی/تولید', 'بازبینی', 'انتشار'],
            'اسلایدی'    => ['ایده', 'متن اسلایدها', 'طراحی', 'کپشن', 'بازبینی', 'انتشار'],
            'کاروسل'     => ['ایده', 'متن', 'طراحی', 'کپشن', 'بازبینی', 'انتشار'],
            'پست'        => ['ایده', 'تولید', 'کپشن', 'بازبینی', 'انتشار'],
            'لایو'       => ['ایده', 'هماهنگی مهمان', 'تیزر', 'برگزاری', 'بازنشر'],
            'تردز'       => ['ایده', 'نگارش', 'بازبینی', 'انتشار'],
            'گایدلاین'   => ['ایده', 'گردآوری موارد', 'طراحی کاور', 'انتشار'],
            'مقاله'      => ['ایده و کلمه کلیدی', 'تحقیق', 'نگارش', 'بهینه‌سازی سئو', 'تصویرسازی', 'بازبینی', 'انتشار'],
            'لندینگ'     => ['ایده', 'کپی‌رایتینگ', 'طراحی', 'پیاده‌سازی', 'تست A/B', 'انتشار'],
            'تسک سئو'    => ['تحقیق کلمه', 'تحلیل رقبا', 'اقدام', 'بررسی نتیجه'],
            'ویدیو'      => ['ایده', 'سناریو', 'فیلم‌برداری', 'تدوین', 'تامبنیل', 'بازبینی', 'انتشار'],
            'شورت'       => ['ایده', 'فیلم‌برداری', 'تدوین', 'انتشار'],
            'تبلیغ'      => ['برنامه‌ریزی', 'تولید خلاقانه', 'راه‌اندازی کمپین', 'پایش', 'گزارش'],
            'تبلیغ اینفلوئنسر' => ['انتخاب اینفلوئنسر', 'هماهنگی و بریف', 'تولید', 'انتشار', 'بررسی نتیجه'],
            'آگهی'       => ['تهیه محتوا', 'انتشار آگهی', 'پایش'],
            'بیلبورد'    => ['طراحی', 'تأیید', 'چاپ', 'نصب', 'پایش'],
            'رپورتاژ'    => ['انتخاب رسانه', 'نگارش', 'انتشار', 'بررسی نتیجه'],
            'کمپین'      => ['تهیه متن', 'سگمنت‌بندی', 'ارسال', 'بررسی نتیجه'],
            'خبرنامه'    => ['ایده', 'نگارش', 'طراحی', 'ارسال', 'بررسی نرخ باز شدن'],
            'نوتیفیکیشن' => ['نگارش', 'سگمنت', 'ارسال', 'بررسی نتیجه'],
        ];
        return $T[$type] ?? ['ایده', 'تولید', 'بازبینی', 'انتشار'];
    }

    /** تزریق مراحل پیش‌فرض برای یک محتوا (اگر مرحله‌ای ندارد). */
    public static function seedSteps(int $contentId, string $type): void
    {
        if (!self::hasTable('eb_mkt_content_steps') || !$contentId) return;
        $exist = Database::one("SELECT COUNT(*) c FROM eb_mkt_content_steps WHERE content_id=?", [$contentId]);
        if ($exist && (int)$exist['c'] > 0) return;
        $i = 0;
        foreach (self::roadmapTemplate($type) as $name) {
            Database::run(
                "INSERT INTO eb_mkt_content_steps (content_id, step_name, sort_order) VALUES (?,?,?)",
                [$contentId, $name, $i++]
            );
        }
    }

    /** جزئیات کامل یک محتوا: خودش + عوامل + مراحل. */
    public static function contentDetail(int $id): array
    {
        $out = ['content' => null, 'crew' => [], 'steps' => [], 'progress' => 0];
        if (!self::hasTable('eb_mkt_content') || !$id) return $out;
        $sel = "c.*";
        $c = Database::one("SELECT {$sel}, m.full_name AS assignee_name FROM eb_mkt_content c
                            LEFT JOIN eb_mkt_members m ON m.id=c.assignee_id WHERE c.id=?", [$id]);
        if (!$c) return $out;
        $out['content'] = $c;
        if (self::hasTable('eb_mkt_content_crew')) {
            $out['crew'] = Database::all(
                "SELECT cr.*, m.full_name FROM eb_mkt_content_crew cr
                 LEFT JOIN eb_mkt_members m ON m.id=cr.member_id
                 WHERE cr.content_id=? ORDER BY cr.id", [$id]
            );
        }
        if (self::hasTable('eb_mkt_content_steps')) {
            $out['steps'] = Database::all(
                "SELECT s.*, m.full_name AS assignee_name FROM eb_mkt_content_steps s
                 LEFT JOIN eb_mkt_members m ON m.id=s.assignee_id
                 WHERE s.content_id=? ORDER BY s.sort_order, s.id", [$id]
            );
            $total = count($out['steps']);
            $done = 0;
            foreach ($out['steps'] as $s) if (!empty($s['done_at'])) $done++;
            $out['progress'] = $total > 0 ? (int)round($done / $total * 100) : 0;
        }
        return $out;
    }

    // -------- عوامل (crew) --------
    public static function addCrew(int $contentId, ?int $memberId, string $prodRole): int
    {
        if (!self::hasTable('eb_mkt_content_crew')) throw new RuntimeException('ابتدا دیتابیس را به‌روزرسانی کنید');
        $prodRole = trim($prodRole);
        if ($contentId <= 0 || $prodRole === '') throw new RuntimeException('نقش تولید لازم است');
        Database::run("INSERT INTO eb_mkt_content_crew (content_id, member_id, prod_role) VALUES (?,?,?)",
            [$contentId, $memberId ?: null, $prodRole]);
        return (int)Database::pdo()->lastInsertId();
    }
    public static function deleteCrew(int $id): void
    {
        if (!self::hasTable('eb_mkt_content_crew')) return;
        Database::run("DELETE FROM eb_mkt_content_crew WHERE id=?", [$id]);
    }

    // -------- مراحل رودمپ --------
    public static function saveStep(array $d): int
    {
        if (!self::hasTable('eb_mkt_content_steps')) throw new RuntimeException('ابتدا دیتابیس را به‌روزرسانی کنید');
        $cid = (int)($d['content_id'] ?? 0);
        $name = trim($d['step_name'] ?? '');
        if ($cid <= 0 || $name === '') throw new RuntimeException('نام مرحله لازم است');
        $assignee = !empty($d['assignee_id']) ? (int)$d['assignee_id'] : null;
        $due = !empty($d['due_date']) ? $d['due_date'] : null;
        if (!empty($d['id'])) {
            Database::run("UPDATE eb_mkt_content_steps SET step_name=?, assignee_id=?, due_date=? WHERE id=?",
                [$name, $assignee, $due, (int)$d['id']]);
            return (int)$d['id'];
        }
        $mx = Database::one("SELECT COALESCE(MAX(sort_order),-1)+1 n FROM eb_mkt_content_steps WHERE content_id=?", [$cid]);
        Database::run("INSERT INTO eb_mkt_content_steps (content_id, step_name, assignee_id, due_date, sort_order) VALUES (?,?,?,?,?)",
            [$cid, $name, $assignee, $due, (int)($mx['n'] ?? 0)]);
        return (int)Database::pdo()->lastInsertId();
    }
    public static function toggleStep(int $id, bool $done): void
    {
        if (!self::hasTable('eb_mkt_content_steps')) return;
        Database::run("UPDATE eb_mkt_content_steps SET done_at=? WHERE id=?",
            [$done ? date('Y-m-d H:i:s') : null, $id]);
    }
    public static function deleteStep(int $id): void
    {
        if (!self::hasTable('eb_mkt_content_steps')) return;
        Database::run("DELETE FROM eb_mkt_content_steps WHERE id=?", [$id]);
    }


    // ---------------- تسک‌های من ----------------
    /**
     * تسک‌های یک عضو: مراحلِ assigned + محتواهایی که خودش مسئول اصلی است.
     * خروجی شامل دسته‌بندی سررسید (امروز/این هفته/این ماه/معوق/بدون تاریخ) سمت کلاینت انجام می‌شود.
     * $gToday: تاریخ امروز میلادی (از کلاینت) برای محاسبه‌ی معوق.
     */
    public static function myTasks(int $memberId, ?string $gToday = null): array
    {
        $out = ['steps' => [], 'content' => [], 'stats' => ['open'=>0,'done'=>0,'overdue'=>0]];
        if (!$memberId || !self::hasTable('eb_mkt_content')) return $out;
        $today = $gToday ?: date('Y-m-d');

        if (self::hasTable('eb_mkt_content_steps')) {
            $out['steps'] = Database::all(
                "SELECT s.id, s.step_name, s.due_date, s.done_at, s.content_id,
                        c.title AS content_title, c.channel, c.content_type
                 FROM eb_mkt_content_steps s
                 JOIN eb_mkt_content c ON c.id=s.content_id
                 WHERE s.assignee_id=?
                 ORDER BY s.done_at IS NOT NULL, s.due_date IS NULL, s.due_date",
                [$memberId]
            );
        }
        // محتوایی که مسئول اصلی‌اش این عضو است (assignee_id)
        $out['content'] = Database::all(
            "SELECT c.id, c.title, c.channel, c.content_type, c.status, c.plan_date
             FROM eb_mkt_content c
             WHERE c.assignee_id=?
             ORDER BY c.status='published', c.plan_date IS NULL, c.plan_date",
            [$memberId]
        );

        foreach ($out['steps'] as $s) {
            if (!empty($s['done_at'])) { $out['stats']['done']++; }
            else {
                $out['stats']['open']++;
                if (!empty($s['due_date']) && $s['due_date'] < $today) $out['stats']['overdue']++;
            }
        }
        return $out;
    }

    // ---------------- پل ارسال (محتوا → اعلان‌بان) ----------------
    /** کانال‌های محتوا که با ارسال اعلان‌بان هم‌خوان‌اند. */
    private static function sendableChannels(): array
    {
        return ['telegram','bale','roubika','sms','email'];
    }

    /**
     * متن قابل‌ارسال یک محتوا را برای پل به صفحه‌ی ارسال استخراج می‌کند.
     * از fields_json (کپشن/متن/CTA/لینک) و scenario می‌سازد.
     */
    public static function sendablePayload(int $id): array
    {
        $out = ['ok'=>false, 'sendable'=>false];
        if (!self::hasTable('eb_mkt_content') || !$id) return $out;
        $c = Database::one("SELECT * FROM eb_mkt_content WHERE id=?", [$id]);
        if (!$c) return $out;
        $fields = [];
        if (!empty($c['fields_json'])) {
            $tmp = json_decode($c['fields_json'], true);
            if (is_array($tmp)) $fields = $tmp;
        }
        // متن: اولویت با کپشن/متن، بعد سناریوی فیلد، بعد یادداشت کلی
        $textParts = [];
        foreach (['text','caption','body','message','ad_text'] as $k) {
            if (!empty($fields[$k])) { $textParts[] = $fields[$k]; break; }
        }
        if (!$textParts && !empty($c['scenario'])) {
            // حذف نشانه‌ی seed از انتهای متن
            $textParts[] = trim(preg_replace('/\s*\[seed:[^\]]+\]\s*$/u', '', $c['scenario']));
        }
        $cta = $fields['cta'] ?? '';
        $link = $fields['link'] ?? ($fields['short_link'] ?? ($fields['campaign_link'] ?? ''));
        $text = trim(implode("\n\n", array_filter($textParts)));
        if ($cta) $text .= ($text ? "\n\n" : '') . $cta;

        // دکمه‌ی اینلاین در صورت وجود لینک (برای بله/تلگرام/روبیکا)
        $buttons = '';
        if ($link) {
            $buttons = json_encode([[['text' => ($cta ?: 'مشاهده'), 'url' => $link]]], JSON_UNESCAPED_UNICODE);
        }

        $channel = $c['channel'] ?? '';
        $out['ok'] = true;
        $out['sendable'] = in_array($channel, self::sendableChannels(), true);
        $out['channel'] = $channel;
        $out['title'] = $c['title'];
        $out['status'] = $c['status'];
        $out['text'] = $text;
        $out['buttons'] = $buttons;
        $out['priority'] = self::channelToPriority($channel);
        return $out;
    }

    /** نگاشت کانال محتوا به رشته‌ی priority صفحه‌ی ارسال. */
    private static function channelToPriority(string $channel): string
    {
        switch ($channel) {
            case 'telegram': return 'telegram';
            case 'bale':     return 'bale';
            case 'roubika':  return 'roubika';
            case 'sms':      return 'sms';
            case 'email':    return 'email';
            default:         return 'bale,telegram,roubika';
        }
    }

    // ---------------- داده‌ی نمونه ----------------
    /** آیا داده‌ی نمونه (seed) قبلاً وارد شده؟ */
    public static function hasSeed(): bool
    {
        if (!self::hasTable('eb_mkt_content')) return false;
        $row = Database::one("SELECT COUNT(*) c FROM eb_mkt_content WHERE scenario LIKE '%[seed:jakojast]%'");
        return $row && (int)$row['c'] > 0;
    }

    /**
     * گزارش‌های مارکتینگ — همه از داده‌ی واقعی eb_mkt_content محاسبه می‌شوند.
     * شامل: روند انتشار، حجم هر کانال، وضعیت محتوا، هیت‌مپ روز×ساعت، بار کاری تیم، پیشرفت اهداف.
     */
    public static function reports(): array
    {
        $out = [
            'total' => 0, 'by_channel' => [], 'by_status' => [], 'by_type' => [],
            'trend' => [], 'heatmap' => [], 'team' => [], 'goals' => [],
            'best_slot' => null, 'insights' => [],
        ];
        if (!self::hasTable('eb_mkt_content')) return $out;

        $out['total'] = (int)(Database::one("SELECT COUNT(*) c FROM eb_mkt_content")['c'] ?? 0);

        $out['by_channel'] = Database::all(
            "SELECT COALESCE(channel,'—') channel, COUNT(*) c FROM eb_mkt_content GROUP BY channel ORDER BY c DESC"
        );
        $out['by_status'] = Database::all(
            "SELECT status, COUNT(*) c FROM eb_mkt_content GROUP BY status"
        );
        $out['by_type'] = Database::all(
            "SELECT COALESCE(content_type,'—') content_type, COUNT(*) c FROM eb_mkt_content GROUP BY content_type ORDER BY c DESC"
        );
        // روند انتشار: تعداد محتوا در هر روزِ planهای موجود
        $out['trend'] = Database::all(
            "SELECT plan_date d, COUNT(*) c FROM eb_mkt_content WHERE plan_date IS NOT NULL GROUP BY plan_date ORDER BY plan_date"
        );
        // هیت‌مپ: روز هفته (1=یکشنبه..7=شنبه در MySQL DAYOFWEEK) × ساعت
        $out['heatmap'] = Database::all(
            "SELECT DAYOFWEEK(plan_date) dow,
                    CASE WHEN plan_time IS NULL OR plan_time='' THEN -1 ELSE CAST(SUBSTRING(plan_time,1,2) AS UNSIGNED) END hr,
                    COUNT(*) c
             FROM eb_mkt_content WHERE plan_date IS NOT NULL GROUP BY dow, hr"
        );
        // بار کاری تیم
        if (self::hasTable('eb_mkt_members')) {
            $out['team'] = Database::all(
                "SELECT m.id, m.full_name,
                        SUM(CASE WHEN c.status='published' THEN 1 ELSE 0 END) done,
                        SUM(CASE WHEN c.status<>'published' THEN 1 ELSE 0 END) open_cnt,
                        COUNT(c.id) total
                 FROM eb_mkt_members m
                 LEFT JOIN eb_mkt_content c ON c.assignee_id=m.id
                 GROUP BY m.id, m.full_name ORDER BY total DESC"
            );
        }
        // پیشرفت اهداف
        if (self::hasTable('eb_mkt_goals')) {
            $out['goals'] = Database::all(
                "SELECT period, title, target_value, current_value FROM eb_mkt_goals
                 ORDER BY FIELD(period,'month','week','day','campaign'), id DESC"
            );
        }
        // بهترین اسلات (بیشترین تعداد در یک روز×ساعت معتبر)
        $best = null;
        foreach ($out['heatmap'] as $h) {
            if ((int)$h['hr'] < 0) continue;
            if ($best === null || (int)$h['c'] > (int)$best['c']) $best = $h;
        }
        $out['best_slot'] = $best;

        return $out;
    }

    /**
     * تزریق داده‌ی واقعی تقویم محتوا از فایل seed_marketing.sql.
     * ایمن برای اجرای چندباره (اول seedهای قبلی پاک می‌شوند).
     * خروجی: تعداد ردیف‌های واردشده.
     */
    public static function seedSampleData(): int
    {
        if (!self::hasTable('eb_mkt_content')) {
            throw new RuntimeException('ابتدا دیتابیس را به‌روزرسانی کنید');
        }
        $file = dirname(__DIR__) . '/seed_marketing.sql';
        if (!is_file($file)) {
            throw new RuntimeException('فایل داده‌ی نمونه یافت نشد');
        }
        $sql = file_get_contents($file);
        $pdo = Database::pdo();
        // اجرای دستورات؛ DELETE سپس INSERT چندردیفی
        // به‌صورت چند statement جدا (با ; در انتهای هر کدام)
        // چون مقادیر متنی فارسی شامل ; نیستند (escape شده‌اند) جداسازی ساده امن است.
        $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
        $inserted = 0;
        foreach ($statements as $stmt) {
            if ($stmt === '' || strpos($stmt, '--') === 0) continue;
            // حذف خطوط کامنت ابتدای statement
            $clean = preg_replace('/^(\s*--.*\n)+/m', '', $stmt);
            if (trim($clean) === '') continue;
            $affected = $pdo->exec($clean);
            if (stripos($clean, 'INSERT') === 0 && $affected !== false) {
                $inserted += $affected;
            }
        }
        return $inserted;
    }
}
