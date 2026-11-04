<?php
// 2.1.5: shared phone normaliser — required here (not autoloaded) because several entry points
// (webhook/receive.php, chat.php, …) load modules with explicit require_once and no autoloader.
require_once __DIR__ . '/../core/PhoneNumber.php';
/**
 * modules/Persons.php
 * بانک مشتری + منطق هویت طلایی و تجمیع خودکار (merge).
 *
 * این قلب طراحی است: یک «شخص» می‌تواند چند «هویت» (کانال) داشته باشد،
 * و شماره موبایل کلید تجمیع است.
 */

class Persons
{
    /**
     * ثبت/به‌روزرسانی یک هویت ورودی از یک کانال.
     * اگر شماره همراه باشد، تجمیع خودکار انجام می‌شود.
     *
     * $inbound: خروجی نرمال‌شده‌ی ChannelInterface::handleInbound()
     *   ['channel'=>?, 'channel_uid'=>, 'username'=>?, 'first_name'=>?, 'phone'=>?, 'raw'=>?]
     *
     * خروجی: person_id
     */
    public static function ingestIdentity(string $channel, array $inbound): int
    {
        $uid = (string) ($inbound['channel_uid'] ?? '');
        if ($uid === '') {
            throw new InvalidArgumentException('channel_uid لازم است');
        }

        $phone = self::normalizePhone($inbound['phone'] ?? null);

        // ۱) آیا این هویت از قبل هست؟
        $identity = Database::one(
            "SELECT * FROM eb_identities WHERE channel = ? AND channel_uid = ?",
            [$channel, $uid]
        );

        if ($identity) {
            $personId = (int) $identity['person_id'];
            Database::run(
                "UPDATE eb_identities SET last_seen = NOW(), username = ?, first_name = ?, raw_json = ? WHERE id = ?",
                [
                    $inbound['username'] ?? $identity['username'],
                    $inbound['first_name'] ?? $identity['first_name'],
                    isset($inbound['raw']) ? json_encode($inbound['raw'], JSON_UNESCAPED_UNICODE) : $identity['raw_json'],
                    $identity['id'],
                ]
            );
            // اگر شخص قبلاً نامی نداشت (مثلاً چون فقط از طریق دایرکت اینستاگرام شناخته شده بود
            // که یوزرنیم نمی‌دهد) و این رویداد نامی آورد (مثلاً یک رویداد کامنت که یوزرنیم دارد)،
            // نام را همین‌جا هم پر کن — نه فقط در مسیر «هویت جدید» پایین‌تر.
            $incomingName = trim(($inbound['first_name'] ?? '') . ' ' . ($inbound['last_name'] ?? ''));
            if ($incomingName !== '') {
                Database::run(
                    "UPDATE eb_persons SET full_name = COALESCE(NULLIF(full_name,''), ?) WHERE id = ?",
                    [$incomingName, $personId]
                );
            }        } else {
            // هویت جدید است. اگر شماره داریم و شخصی با همین شماره از قبل وجود دارد،
            // هویت را مستقیم زیر همان شخص ثبت می‌کنیم (نه ساختن شخص ناشناس خالی و merge بعدی).
            $personId = null;
            if ($phone) {
                $existing = Database::one("SELECT id FROM eb_persons WHERE phone = ?", [$phone]);
                if ($existing) {
                    $personId = (int) $existing['id'];
                    // اگر این هویت نامی آورد و شخص موجود نام نداشت، نامش را پر کن
                    $incomingName = trim(($inbound['first_name'] ?? '') . ' ' . ($inbound['last_name'] ?? ''));
                    if ($incomingName !== '') {
                        Database::run(
                            "UPDATE eb_persons SET full_name = COALESCE(NULLIF(full_name,''), ?) WHERE id = ?",
                            [$incomingName, $personId]
                        );
                    }
                }
            }

            // اگر شخص موجودی پیدا نشد، یک شخص جدید می‌سازیم
            if ($personId === null) {
                $fullName = trim(($inbound['first_name'] ?? '') . ' ' . ($inbound['last_name'] ?? ''));
                // اگر شماره داریم، مستقیم member؛ وگرنه anonymous
                $status = $phone ? 'member' : 'anonymous';
                Database::run(
                    "INSERT INTO eb_persons (full_name, phone, status) VALUES (?, ?, ?)",
                    [$fullName !== '' ? $fullName : null, $phone ?: null, $status]
                );
                $personId = Database::lastId();
            }

            Database::run(
                "INSERT INTO eb_identities (person_id, channel, channel_uid, username, first_name, raw_json)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $personId, $channel, $uid,
                    $inbound['username'] ?? null,
                    $inbound['first_name'] ?? null,
                    isset($inbound['raw']) ? json_encode($inbound['raw'], JSON_UNESCAPED_UNICODE) : null,
                ]
            );

            // شماره را قبلاً مستقیم ست کردیم؛ نیازی به attachPhone نیست.
            // اینستاگرام: اگر همین یوزرنیم قبلاً به پیج دیگری پیام داده، یک پروفایل شوند
            return self::linkByUsername($personId, $channel, $inbound['username'] ?? null);
        }

        // ۲) اگر شماره داریم، تجمیع/ثبت شماره (مسیر هویت موجود)
        if ($phone) {
            $personId = self::attachPhone($personId, $phone);
        }
        // ۳) اینستاگرام: همین یوزرنیم روی پیج‌های دیگر؟ → یک پروفایل
        $personId = self::linkByUsername($personId, $channel, $inbound['username'] ?? null);

        return $personId;
    }

    /**
     * ادغام هویت‌های اینستاگرامِ یک نفر روی پیج‌های مختلف (دور ۳، مورد ۴).
     *
     * اینستاگرام به هر کاربر *برای هر پیج* یک شناسه‌ی جدا (IGSID) می‌دهد؛ پس وقتی @ali
     * به ۵ پیج ما پیام بدهد، ۵ هویت با ۵ channel_uid متفاوت داریم و تنها کلید مشترک
     * بینشان یوزرنیم است. این متد همه‌ی اشخاصی را که هویت اینستاگرامی با همین یوزرنیم
     * دارند در یک شخص جمع می‌کند (همه‌ی پیام‌ها، گروه‌ها، برچسب‌ها و سوشال‌های دیگرشان).
     *
     * نگه‌دارنده: شخصِ دارای شماره، وگرنه قدیمی‌ترین. اگر دو شخص شماره‌ی *متفاوت* داشته
     * باشند ادغام نمی‌شوند (یوزرنیم ممکن است عوض و توسط فرد دیگری برداشته شده باشد).
     * بعد از آن، چون شماره کلید طلایی است، هر شماره‌ای که در هر پیج داده شود همه‌ی
     * سوشال‌ها (تلگرام، بله، سایت، پیامک…) را هم از طریق attachPhone یکی می‌کند.
     */
    public static function linkByUsername(int $personId, string $channel, ?string $username): int
    {
        if ($channel !== 'instagram' || $personId <= 0) return $personId;
        $u = trim(ltrim(trim((string)$username), '@'));
        if ($u === '' || mb_strlen($u) < 2) return $personId;
        try {
            $rows = Database::all(
                "SELECT DISTINCT person_id FROM eb_identities WHERE channel = 'instagram' AND username = ? AND person_id <> ?",
                [$u, $personId]
            );
        } catch (Throwable $e) { return $personId; }
        if (!$rows) return $personId;
        $ids = array_unique(array_merge([$personId], array_map(fn($r) => (int)$r['person_id'], $rows)));
        return self::mergeGroup($ids, 'ig_username:' . $u);
    }

    /**
     * ادغام چند شخص در یک نفر با رعایت تعارض شماره. خروجی: شناسه‌ی نگه‌دارنده.
     */
    public static function mergeGroup(array $ids, string $reason = ''): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (count($ids) < 2) return $ids[0] ?? 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $people = Database::all("SELECT id, phone FROM eb_persons WHERE id IN ($ph) ORDER BY id ASC", $ids);
        if (!$people) return $ids[0];
        $keeper = null;
        foreach ($people as $p) if (!empty($p['phone'])) { $keeper = $p; break; }
        if (!$keeper) $keeper = $people[0];
        $kid = (int)$keeper['id'];
        foreach ($people as $p) {
            if ((int)$p['id'] === $kid) continue;
            if (!empty($p['phone']) && !empty($keeper['phone']) && $p['phone'] !== $keeper['phone']) continue; // دو نفر متفاوت
            try {
                self::merge($kid, (int)$p['id']);
                if (!empty($p['phone']) && empty($keeper['phone'])) $keeper['phone'] = $p['phone'];
                if (class_exists('ApiLog')) { try { ApiLog::record('system', 'person_merge', ['keeper' => $kid, 'loser' => (int)$p['id'], 'reason' => $reason], null, null, true, null); } catch (Throwable $e) {} }
            } catch (Throwable $e) { /* یک ادغام ناموفق نباید بقیه را متوقف کند */ }
        }
        return $kid;
    }

    /**
     * ادغام یک‌باره‌ی سوابق قبلی: همه‌ی یوزرنیم‌های اینستاگرامی که زیر بیش از یک شخص‌اند.
     * @return array{groups:int, merged:int}
     */
    public static function backfillInstagramMerge(int $max = 5000): array
    {
        $groups = 0; $merged = 0;
        $rows = Database::all(
            "SELECT username, GROUP_CONCAT(DISTINCT person_id) ids FROM eb_identities
             WHERE channel = 'instagram' AND username IS NOT NULL AND username <> ''
             GROUP BY username HAVING COUNT(DISTINCT person_id) > 1 LIMIT " . (int)$max
        );
        foreach ($rows as $r) {
            $ids = array_map('intval', explode(',', (string)$r['ids']));
            $before = count($ids);
            $kid = self::mergeGroup($ids, 'backfill:' . $r['username']);
            $left = (int)(Database::one("SELECT COUNT(DISTINCT person_id) c FROM eb_identities WHERE channel='instagram' AND username = ?", [$r['username']])['c'] ?? $before);
            if ($left < $before) { $groups++; $merged += $before - $left; }
        }
        return ['groups' => $groups, 'merged' => $merged];
    }

    /**
     * شماره را به یک شخص می‌چسباند و در صورت برخورد، merge می‌کند.
     * خروجی: person_id نهایی (که ممکن است با ورودی فرق کند اگر merge شد).
     */
    public static function attachPhone(int $personId, string $phone): int
    {
        $phone = self::normalizePhone($phone);
        if (!$phone) return $personId;

        // آیا شخص دیگری با این شماره هست؟
        $existing = Database::one("SELECT * FROM eb_persons WHERE phone = ?", [$phone]);

        if ($existing && (int) $existing['id'] !== $personId) {
            // MERGE: هویت‌های شخص فعلی را به شخص موجود منتقل کن، شخص فعلی را حذف کن
            self::merge((int) $existing['id'], $personId);
            return (int) $existing['id'];
        }

        // در غیر این صورت شماره را روی همین شخص بگذار و عضو کن
        Database::run(
            "UPDATE eb_persons SET phone = ?, status = 'member' WHERE id = ?",
            [$phone, $personId]
        );
        return $personId;
    }

    /**
     * ادغام دو شخص. هدف: «جمع‌کردن همه‌ی اطلاعات»، نه پاک‌کردن.
     * هویت‌ها/پیام‌ها/گروه‌ها/تگ‌ها از loser به keeper منتقل می‌شوند، و
     * فیلدهای پروفایل (نام، یادداشت، شماره، وضعیت) از هر دو طرف به‌گونه‌ای
     * ترکیب می‌شوند که هیچ اطلاعات غیرخالی‌ای از دست نرود.
     */
    public static function merge(int $keeperId, int $loserId): void
    {
        if ($keeperId === $loserId) return;
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $keeper = Database::one("SELECT * FROM eb_persons WHERE id = ?", [$keeperId]);
            $loser  = Database::one("SELECT * FROM eb_persons WHERE id = ?", [$loserId]);
            if (!$keeper || !$loser) { $pdo->commit(); return; }

            // --- ترکیب فیلدهای پروفایل (هیچ داده‌ی غیرخالی از دست نرود) ---
            // نام: مقدار پرتر/طولانی‌تر را نگه می‌داریم (معمولاً کامل‌تر است).
            $fullName = self::pickRicher($keeper['full_name'] ?? null, $loser['full_name'] ?? null);
            // شماره: هرکدام که موجود است (keeper اولویت دارد چون معمولاً صاحب شماره است).
            $phone = ($keeper['phone'] ?? null) ?: ($loser['phone'] ?? null);
            // یادداشت: اگر هر دو داشتند، به‌هم می‌چسبانیم تا چیزی نرود.
            $notes = self::mergeNotes($keeper['notes'] ?? null, $loser['notes'] ?? null);
            // وضعیت: اگر هرکدام member بود، نتیجه member است.
            $status = ($keeper['status'] === 'member' || $loser['status'] === 'member') ? 'member' : ($keeper['status'] ?? 'anonymous');

            // شماره UNIQUE است: اگر شماره از بازنده می‌آید، اول از روی بازنده برداشته شود
            if (empty($keeper['phone']) && !empty($loser['phone'])) {
                Database::run("UPDATE eb_persons SET phone = NULL WHERE id = ?", [$loserId]);
            }
            Database::run(
                "UPDATE eb_persons SET full_name = ?, phone = ?, notes = ?, status = ? WHERE id = ?",
                [$fullName, $phone, $notes, $status, $keeperId]
            );

            // --- انتقال هویت‌ها (با احتیاط نسبت به برخورد UNIQUE channel+uid) ---
            $loserIdentities = Database::all("SELECT * FROM eb_identities WHERE person_id = ?", [$loserId]);
            foreach ($loserIdentities as $idn) {
                $clash = Database::one(
                    "SELECT id FROM eb_identities WHERE channel = ? AND channel_uid = ? AND person_id = ?",
                    [$idn['channel'], $idn['channel_uid'], $keeperId]
                );
                if ($clash) {
                    // هویت تکراری: اطلاعاتش را در نسخه‌ی keeper پر کن (اگر خالی بود) بعد حذف کن
                    Database::run(
                        "UPDATE eb_identities SET
                           username   = COALESCE(NULLIF(username,''), ?),
                           first_name = COALESCE(NULLIF(first_name,''), ?)
                         WHERE id = ?",
                        [$idn['username'] ?? null, $idn['first_name'] ?? null, $clash['id']]
                    );
                    // پیام‌های این هویت تکراری را به هویت keeper منتقل کن (از دست نرود)
                    Database::run("UPDATE eb_messages SET identity_id = ? WHERE identity_id = ?", [$clash['id'], $idn['id']]);
                    Database::run("DELETE FROM eb_identities WHERE id = ?", [$idn['id']]);
                } else {
                    Database::run("UPDATE eb_identities SET person_id = ? WHERE id = ?", [$keeperId, $idn['id']]);
                }
            }

            // --- انتقال گروه‌ها و تگ‌ها (تکراری‌ها نادیده) ---
            Database::run("INSERT IGNORE INTO eb_person_groups (group_id, person_id) SELECT group_id, ? FROM eb_person_groups WHERE person_id = ?", [$keeperId, $loserId]);
            Database::run("INSERT IGNORE INTO eb_person_tags (tag_id, person_id) SELECT tag_id, ? FROM eb_person_tags WHERE person_id = ?", [$keeperId, $loserId]);
            Database::run("DELETE FROM eb_person_groups WHERE person_id = ?", [$loserId]);
            Database::run("DELETE FROM eb_person_tags WHERE person_id = ?", [$loserId]);

            // ارجاع‌های دیگر به شخص بازنده (فرم، لینک، کمپین، کامنت، اعلان، سابقه‌ی تخصیص) — هیچ سابقه‌ای گم نشود
            foreach ([['eb_form_responses','person_id'],['eb_link_clicks','person_id'],['eb_campaign_runs','person_id'],['eb_send_targets','person_id'],
                      ['eb_ig_comments','person_id'],['eb_notifications','person_id'],['eb_assign_log','person_id'],['eb_ig_orders','person_id']] as [$t, $c]) {
                if (Database::hasColumn($t, $c)) { try { Database::run("UPDATE `$t` SET `$c` = ? WHERE `$c` = ?", [$keeperId, $loserId]); } catch (Throwable $e) {} }
            }
            if (Database::hasColumn('eb_persons', 'assigned_user_id')) {
                Database::run("UPDATE eb_persons SET assigned_user_id = COALESCE(assigned_user_id, ?), first_responder_id = COALESCE(first_responder_id, ?) WHERE id = ?",
                    [$loser['assigned_user_id'] ?? null, $loser['first_responder_id'] ?? null, $keeperId]);
            }
            if (Database::hasTable('eb_chat_presence')) { try { Database::run("DELETE FROM eb_chat_presence WHERE person_id = ?", [$loserId]); } catch (Throwable $e) {} }
            // ۱.۰.۱۴: سوابق دستیار فروش، درخواست تماس، چت سایت و وضعیت گفتگو هم به پروفایل یکپارچه بروند
            foreach (['eb_agent_callbacks', 'eb_agent_orders', 'eb_agent_log', 'eb_agent_security', 'eb_agent_surveys', 'eb_webchat_sessions', 'eb_notice_leads', 'eb_cp_ivr_calls', 'eb_conv_events'] as $t) {
                if (Database::hasColumn($t, 'person_id')) { try { Database::run("UPDATE `$t` SET person_id = ? WHERE person_id = ?", [$keeperId, $loserId]); } catch (Throwable $e) {} }
            }
            foreach (['eb_agent_state', 'eb_conv_state'] as $t) {   // person_id کلید اصلی است: اگر keeper ردیف دارد، همان می‌ماند
                if (Database::hasColumn($t, 'person_id')) { try { Database::run("UPDATE IGNORE `$t` SET person_id = ? WHERE person_id = ?", [$keeperId, $loserId]); Database::run("DELETE FROM `$t` WHERE person_id = ?", [$loserId]); } catch (Throwable $e) {} }
            }

            // حذف شخص بازنده (پیام‌هایش چون به هویت‌ها وصل بودند، منتقل شده‌اند)
            Database::run("DELETE FROM eb_persons WHERE id = ?", [$loserId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if (class_exists('ConvIndex')) ConvIndex::touch($keeperId, $loserId);
    }

    /** از دو مقدار، پرتر/کامل‌تر را برمی‌گرداند (غیرخالی بر خالی اولویت دارد). */
    private static function pickRicher(?string $a, ?string $b): ?string
    {
        $a = $a !== null ? trim($a) : '';
        $b = $b !== null ? trim($b) : '';
        if ($a === '') return $b !== '' ? $b : null;
        if ($b === '') return $a;
        // هر دو پر: طولانی‌تر معمولاً کامل‌تر است (مثلاً «علی احمدی» در برابر «علی»)
        return mb_strlen($b) > mb_strlen($a) ? $b : $a;
    }

    /** ترکیب دو یادداشت بدون از دست رفتن هیچ‌کدام. */
    private static function mergeNotes(?string $a, ?string $b): ?string
    {
        $a = $a !== null ? trim($a) : '';
        $b = $b !== null ? trim($b) : '';
        if ($a === '') return $b !== '' ? $b : null;
        if ($b === '') return $a;
        if ($a === $b) return $a;
        return $a . "\n---\n" . $b;
    }

    /** تبدیل ارقام فارسی/عربی به انگلیسی (۰۹۱۲ / ٠٩١٢ → 0912) */
    public static function asciiDigits(string $s): string
    {
        return PhoneNumber::asciiDigits($s);
    }

    /**
     * پیدا کردن شماره موبایل ایران در هر جای یک متن آزاد.
     * مثال‌ها: «شمارم ۰۹۱۲ ۳۴۵ ۶۷۸۹ هست»، «+98-912-345-6789»، «تماس: 9123456789».
     * عددهای بلندتر (مثل شماره کارت یا کد پیگیری) عمداً نادیده گرفته می‌شوند.
     * خروجی همان قالب normalizePhone (09XXXXXXXXX) یا null.
     */
    public static function extractPhone(?string $text): ?string
    {
        if ($text === null || $text === '') return null;
        $t = self::asciiDigits($text);
        // جداکننده‌های مجاز بین گروه‌های رقم: فاصله، خط تیره، نقطه، پرانتز، فاصله‌ی مجازی
        $sep = '[\s\-\.\(\)\x{200c}\x{200f}]{0,2}';
        $re = '/(?<![0-9])(?:\+?98|0098|0)?' . $sep . '9[0-9]{2}' . $sep . '[0-9]{3}' . $sep . '[0-9]{4}(?![0-9])/u';
        if (!preg_match_all($re, $t, $m)) return null;
        foreach ($m[0] as $cand) {
            $p = self::normalizePhone($cand);
            if ($p) return $p;
        }
        return null;
    }

    /**
     * ۱.۰.۱۴: قالب استاندارد و الزامی ذخیره‌ی موبایل: 09XXXXXXXXX (۱۱ رقم). null اگر نامعتبر.
     * همه‌ی ورودی‌ها (چت سایت، کانال‌ها، فرم‌ها، اکسل، API، ثبت دستی، درخواست تماس) از همین تابع
     * می‌گذرند، پس یک مشتری با ‎+98، 0098، 98 یا 09 همیشه یک شماره و یک پروفایل دارد.
     * قالب‌های پذیرفته: 09123456789، 9123456789، 989123456789، +989123456789، 00989123456789،
     * +98 0912…، 098912…، با فاصله، خط تیره، نقطه، پرانتز و ارقام فارسی/عربی.
     */
    public static function normalizePhone($raw): ?string
    {
        // 2.1.5: single shared normaliser (core/PhoneNumber.php); identical output for every
        // input — see tests/PhoneNumberTest.php differential test against the previous code.
        return PhoneNumber::iranMobile($raw);
    }

    /** قالب بین‌المللی (98XXXXXXXXXX) فقط برای وب‌سرویس‌هایی که آن را می‌خواهند؛ هرگز برای ذخیره */
    public static function intlPhone($raw): ?string
    {
        $n = self::normalizePhone($raw);
        return $n === null ? null : '98' . substr($n, 1);
    }

    /**
     * پروفایل کامل یک شخص: اطلاعات پایه + هویت‌ها + گروه‌ها + تگ‌ها + شمارش پیام.
     */
    public static function profile(int $personId): ?array
    {
        $person = Database::one("SELECT * FROM eb_persons WHERE id = ?", [$personId]);
        if (!$person) return null;

        $person['identities'] = Database::all(
            "SELECT * FROM eb_identities WHERE person_id = ? ORDER BY last_seen DESC",
            [$personId]
        );
        // غنی‌سازی هر هویت: نام کامل، نام‌خانوادگی، بیو (از raw_json)، و آخرین پیام در آن شبکه
        foreach ($person['identities'] as &$idn) {
            $raw = json_decode($idn['raw_json'] ?? '{}', true) ?: [];
            $idn['last_name'] = $raw['last_name'] ?? ($idn['last_name'] ?? null);
            $idn['bio'] = $raw['bio'] ?? ($raw['description'] ?? null);
            $last = Database::one(
                "SELECT body, direction, created_at FROM eb_messages WHERE identity_id = ? ORDER BY created_at DESC LIMIT 1",
                [$idn['id']]
            );
            $idn['last_message'] = $last['body'] ?? null;
            $idn['last_message_at'] = $last['created_at'] ?? $idn['last_seen'] ?? null;
            $idn['last_message_dir'] = $last['direction'] ?? null;
        }
        unset($idn);
        $person['groups'] = Database::all(
            "SELECT g.id, g.name, g.color FROM eb_groups g
             INNER JOIN eb_person_groups pg ON pg.group_id = g.id
             WHERE pg.person_id = ?",
            [$personId]
        );
        $person['tags'] = Database::all(
            "SELECT t.id, t.name FROM eb_tags t
             INNER JOIN eb_person_tags pt ON pt.tag_id = t.id
             WHERE pt.person_id = ?",
            [$personId]
        );

        // شمارش پیام‌های ارسالی/دریافتی (روی همه‌ی هویت‌های این شخص)
        $stats = Database::one(
            "SELECT
               SUM(direction='out') AS sent,
               SUM(direction='in')  AS received,
               MAX(created_at)      AS last_activity
             FROM eb_messages
             WHERE identity_id IN (SELECT id FROM eb_identities WHERE person_id = ?)",
            [$personId]
        );
        $person['stats'] = [
            'sent'          => (int) ($stats['sent'] ?? 0),
            'received'      => (int) ($stats['received'] ?? 0),
            'last_activity' => $stats['last_activity'] ?? null,
        ];

        // محبوب‌ترین/فعال‌ترین کانال (بیشترین پیام)
        $topCh = Database::one(
            "SELECT channel, COUNT(*) c FROM eb_messages
             WHERE identity_id IN (SELECT id FROM eb_identities WHERE person_id = ?)
             GROUP BY channel ORDER BY c DESC LIMIT 1",
            [$personId]
        );
        $person['top_channel'] = $topCh['channel'] ?? null;

        return $person;
    }

    /**
     * کامنت‌های اینستاگرامِ این شخص زیر پست‌های همه‌ی پیج‌ها (دور ۳، مورد ۱).
     * تطبیق: person_id ثبت‌شده، یا (پیج + شناسه‌ی فرستنده) از روی هویت‌ها، یا یوزرنیم.
     * خروجی شامل لیبل/رنگ پیج، عنوان دانش پست (اگر تعریف شده) و وضعیت پاسخ خودکار است.
     */
    public static function igComments(int $personId, int $limit = 50): array
    {
        if (!Database::hasTable('eb_ig_comments')) return [];
        $hasAcc = Database::hasColumn('eb_ig_comments', 'ig_account_id') && Database::hasColumn('eb_identities', 'ig_account_id');
        $idns = Database::all("SELECT channel_uid, username" . ($hasAcc ? ", ig_account_id" : "") . " FROM eb_identities WHERE person_id = ? AND channel = 'instagram'", [$personId]);
        $or = ["c.person_id = ?"]; $params = [$personId];
        $names = [];
        foreach ($idns as $i) {
            if ($hasAcc && !empty($i['ig_account_id']) && preg_match('/^rc(\d+)_(.+)$/', (string)$i['channel_uid'], $m)) {
                $or[] = "(c.ig_account_id = ? AND c.sender_id = ?)"; $params[] = (int)$m[1]; $params[] = $m[2];
            }
            if (!empty($i['username'])) $names[strtolower($i['username'])] = true;
        }
        if ($names) { $or[] = "c.sender_username IN (" . implode(',', array_fill(0, count($names), '?')) . ")"; $params = array_merge($params, array_keys($names)); }
        $accJoin = Database::hasTable('eb_ig_accounts') && $hasAcc ? "LEFT JOIN eb_ig_accounts a ON a.id = c.ig_account_id" : "";
        $accCols = $accJoin ? ", a.label AS account_label, a.color AS account_color" : ", NULL AS account_label, NULL AS account_color";
        $knJoin = Database::hasTable('eb_ig_post_knowledge') ? "LEFT JOIN eb_ig_post_knowledge k ON k.media_id = c.media_id" : "";
        $knCol = $knJoin ? ", k.title AS post_title" : ", NULL AS post_title";
        try {
            return Database::all(
                "SELECT c.id, c.comment_id, c.media_id, c.sender_username, c.body, c.replied_at, c.replied_type, c.created_at" . ($hasAcc ? ", c.ig_account_id" : ", NULL AS ig_account_id") . " $accCols $knCol
                 FROM eb_ig_comments c $accJoin $knJoin
                 WHERE " . implode(' OR ', $or) . "
                 ORDER BY c.created_at DESC LIMIT " . (int)$limit,
                $params
            );
        } catch (Throwable $e) { return []; }
    }

    /**
     * تاریخچه‌ی پیام‌های یک شخص (همه‌ی کانال‌ها، به ترتیب زمانی).
     */
    public static function messages(int $personId, int $limit = 100): array
    {
        // جدیدترین $limit پیام (قبلاً قدیمی‌ترین‌ها برمی‌گشت و پیام‌های تازه دیده نمی‌شد)
        $del = Database::hasColumn('eb_messages', 'deleted_at') ? "AND m.deleted_at IS NULL" : "";
        $ig = Database::hasTable('eb_ig_accounts') && Database::hasColumn('eb_identities', 'ig_account_id');
        $rows = Database::all(
            "SELECT m.*, i.channel AS ch" . ($ig ? ", a.label AS account_label, a.color AS account_color" : "") . "
             FROM eb_messages m
             INNER JOIN eb_identities i ON i.id = m.identity_id
             " . ($ig ? "LEFT JOIN eb_ig_accounts a ON a.id = i.ig_account_id" : "") . "
             WHERE i.person_id = ? $del
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT " . (int)$limit,
            [$personId]
        );
        return array_reverse($rows);
    }

    /**
     * افزودن دستی مشتری توسط اپراتور.
     * بسته به کانال، شناسه‌ی درست را می‌گیرد:
     *   - بله/تلگرام/روبیکا/صفیر(ربات)  → chat_id  (در channel_uid)
     *   - پیامک/صفیر(شماره)              → شماره موبایل (هم channel_uid هم phone شخص)
     *   - ایمیل                          → آدرس ایمیل (در channel_uid)
     *
     * $channels: آرایه‌ای از ['channel'=>..., 'value'=>...] — حداقل یک هویت.
     * اگر شماره‌ای (مستقیم در فیلد phone یا از کانال پیامک/صفیر) موجود باشد،
     * منطق هویت طلایی + merge اعمال می‌شود.
     *
     * خروجی: ['person_id'=>int, 'created'=>bool, 'merged'=>bool]
     */
    public static function createManual(?string $fullName, ?string $phone, ?string $notes, array $channels): array
    {
        $fullName = $fullName !== null ? trim($fullName) : '';
        $notes    = $notes !== null ? trim($notes) : '';
        $rawPhone = $phone !== null ? trim($phone) : '';
        $phone    = self::normalizePhone($phone);
        // ۱.۰.۱۴: شماره‌ی نامعتبر یا ناقص بی‌صدا کنار گذاشته نمی‌شود؛ اپراتور خطا می‌بیند
        if ($rawPhone !== '' && $phone === null) {
            throw new InvalidArgumentException('شماره‌ی موبایل «' . $rawPhone . '» معتبر نیست؛ ۱۱ رقم با 09 (یا ‎+98) وارد کنید');
        }

        // ۱) هویت‌ها را اعتبارسنجی و نرمال کن
        $valid = [];   // [channel => uid]
        foreach ($channels as $c) {
            $ch  = trim((string)($c['channel'] ?? ''));
            $val = trim((string)($c['value'] ?? ''));
            if ($ch === '' || $val === '') continue;
            $uid = self::normalizeChannelValue($ch, $val);
            if ($uid === null) {
                throw new InvalidArgumentException("شناسه‌ی نامعتبر برای کانال «{$ch}»: {$val}");
            }
            // اگر کانالِ شماره‌ای بود و شماره‌ی صریح نداده بودیم، آن را به‌عنوان شماره‌ی طلایی هم بردار
            if (self::isPhoneChannel($ch) && !$phone) {
                $phone = $uid; // normalizeChannelValue برای کانال شماره‌ای خروجی نرمال‌شده می‌دهد
            }
            $valid[] = ['channel' => $ch, 'uid' => $uid];
        }

        if (!$valid) {
            throw new InvalidArgumentException('حداقل یک هویت (کانال + شناسه) لازم است');
        }

        // ۲) آیا هویتی از اینها از قبل وجود دارد؟ اگر بله، روی همان شخص کار کن.
        $personId = null;
        foreach ($valid as $v) {
            $existsId = Database::one(
                "SELECT person_id FROM eb_identities WHERE channel = ? AND channel_uid = ?",
                [$v['channel'], $v['uid']]
            );
            if ($existsId) { $personId = (int)$existsId['person_id']; break; }
        }

        $created = false;
        $merged  = false;

        // ۳) اگر شماره داریم و شخصی با این شماره هست، ترجیحاً روی او بنشین (هویت طلایی)
        if ($personId === null && $phone) {
            $byPhone = Database::one("SELECT id FROM eb_persons WHERE phone = ?", [$phone]);
            if ($byPhone) $personId = (int)$byPhone['id'];
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // ۴) اگر هیچ شخص موجودی پیدا نشد، شخص جدید بساز
            if ($personId === null) {
                $status = $phone ? 'member' : 'anonymous';
                Database::run(
                    "INSERT INTO eb_persons (full_name, phone, status, notes) VALUES (?, ?, ?, ?)",
                    [$fullName !== '' ? $fullName : null, $phone ?: null, $status, $notes !== '' ? $notes : null]
                );
                $personId = Database::lastId();
                $created = true;
            } else {
                // شخص موجود: فیلدهای خالی را پر کن (داده‌ی موجود را پاک نکن)
                if ($fullName !== '') {
                    Database::run(
                        "UPDATE eb_persons SET full_name = COALESCE(NULLIF(full_name,''), ?) WHERE id = ?",
                        [$fullName, $personId]
                    );
                }
                if ($notes !== '') {
                    $cur = Database::one("SELECT notes FROM eb_persons WHERE id = ?", [$personId]);
                    $merged_notes = self::mergeNotes($cur['notes'] ?? null, $notes);
                    Database::run("UPDATE eb_persons SET notes = ? WHERE id = ?", [$merged_notes, $personId]);
                }
            }

            // ۵) هویت‌ها را ثبت کن (تکراری‌ها نادیده)
            foreach ($valid as $v) {
                $dup = Database::one(
                    "SELECT id, person_id FROM eb_identities WHERE channel = ? AND channel_uid = ?",
                    [$v['channel'], $v['uid']]
                );
                if ($dup) {
                    // اگر این هویت زیر شخص دیگری بود، آن را به personId جاری منتقل کن
                    if ((int)$dup['person_id'] !== $personId) {
                        Database::run("UPDATE eb_identities SET person_id = ?, last_seen = NOW() WHERE id = ?", [$personId, $dup['id']]);
                        if (class_exists('ConvIndex')) ConvIndex::touch($personId, (int)$dup['person_id']);
                    } else {
                        Database::run("UPDATE eb_identities SET last_seen = NOW() WHERE id = ?", [$dup['id']]);
                    }
                    continue;
                }
                Database::run(
                    "INSERT INTO eb_identities (person_id, channel, channel_uid, first_name)
                     VALUES (?, ?, ?, ?)",
                    [$personId, $v['channel'], $v['uid'], $fullName !== '' ? $fullName : null]
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // ۶) شماره را با منطق هویت طلایی بچسبان (ممکن است merge را تریگر کند)
        if ($phone) {
            $before = $personId;
            $personId = self::attachPhone($personId, $phone);
            if ($personId !== $before) $merged = true;
        }

        return ['person_id' => $personId, 'created' => $created, 'merged' => $merged];
    }

    /** آیا این کانال شناسه‌اش شماره‌ی موبایل است؟ */
    private static function isPhoneChannel(string $channel): bool
    {
        return in_array($channel, ['sms', 'safir_phone', 'voice'], true);
    }

    /**
     * نرمال‌سازی/اعتبارسنجی مقدار شناسه بسته به نوع کانال.
     * خروجی: رشته‌ی نرمال‌شده، یا null اگر نامعتبر.
     */
    public static function normalizeChannelValue(string $channel, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;

        // کانال‌های شماره‌ای → شماره‌ی موبایل ایران
        if (self::isPhoneChannel($channel)) {
            return self::normalizePhone($value);
        }

        // ایمیل
        if ($channel === 'email') {
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_strtolower($value) : null;
        }

        // ربات‌ها (بله/تلگرام/روبیکا/صفیرِ ربات) → chat_id عددی
        if (in_array($channel, ['bale', 'telegram', 'roubika', 'bale_safir'], true)) {
            $d = preg_replace('/\D+/', '', $value);
            // chat_id تلگرام/بله می‌تواند منفی باشد (گروه/کانال)؛ علامت منفی را حفظ کن
            $neg = (strpos($value, '-') === 0);
            if ($d === '') return null;
            return ($neg ? '-' : '') . $d;
        }

        // پیش‌فرض: همان مقدار (بدون فاصله‌ی اضافی)
        return $value;
    }

    /**
     * ویرایش نام و یادداشت یک شخص (توسط اپراتور).
     */
    public static function update(int $personId, ?string $fullName, ?string $notes): void
    {
        Database::run(
            "UPDATE eb_persons SET full_name = ?, notes = ? WHERE id = ?",
            [$fullName, $notes, $personId]
        );
    }

    /**
     * ۱.۰.۱۴: ثبت یا ویرایش دستی شماره توسط تیم. شماره قبل از ذخیره استاندارد (09…) می‌شود؛
     * اگر مشتری دیگری همین شماره را دارد، دو پروفایل یکی می‌شوند. رشته‌ی خالی = حذف شماره.
     * @return array{ok:bool, error?:string, person_id?:int, phone?:?string, merged?:bool}
     */
    public static function setPhone(int $personId, string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            Database::run("UPDATE eb_persons SET phone = NULL WHERE id = ?", [$personId]);
            return ['ok' => true, 'person_id' => $personId, 'phone' => null, 'merged' => false];
        }
        $n = self::normalizePhone($raw);
        if ($n === null) return ['ok' => false, 'error' => 'شماره‌ی موبایل معتبر نیست؛ ۱۱ رقم با 09 (یا ‎+98) وارد کنید'];
        $cur = Database::one("SELECT phone FROM eb_persons WHERE id = ?", [$personId]);
        if (!$cur) return ['ok' => false, 'error' => 'مشتری یافت نشد'];
        if ((string)$cur['phone'] === $n) return ['ok' => true, 'person_id' => $personId, 'phone' => $n, 'merged' => false];
        $other = Database::one("SELECT id FROM eb_persons WHERE phone = ? AND id <> ?", [$n, $personId]);
        if ($other) {
            // پروفایل قدیمی‌تر نگه داشته می‌شود؛ همه‌ی پیام‌ها و سوابق به آن می‌رود
            $keeper = min($personId, (int)$other['id']); $loser = max($personId, (int)$other['id']);
            if ($keeper === $personId) Database::run("UPDATE eb_persons SET phone = NULL WHERE id = ?", [$loser]);
            self::merge($keeper, $loser);
            Database::run("UPDATE eb_persons SET phone = ?, status = 'member' WHERE id = ?", [$n, $keeper]);
            return ['ok' => true, 'person_id' => $keeper, 'phone' => $n, 'merged' => true];
        }
        Database::run("UPDATE eb_persons SET phone = ?, status = 'member' WHERE id = ?", [$n, $personId]);
        return ['ok' => true, 'person_id' => $personId, 'phone' => $n, 'merged' => false];
    }

    /**
     * به‌روزرسانی اطلاعات هویت‌های یک شخص از روی API هر پلتفرم رباتی.
     * برای هر هویت بله/تلگرام/روبیکا، getChat را صدا می‌زند و username/first_name را تازه می‌کند.
     * اگر شخص نام نداشته باشد، اولین first_name به‌دست‌آمده را به‌عنوان نامش می‌گذارد.
     *
     * خروجی: ['updated'=>int تعداد هویت تازه‌شده, 'errors'=>[...]]
     */
    public static function refreshFromNetwork(int $personId): array
    {
        $identities = Database::all(
            "SELECT id, channel, channel_uid FROM eb_identities WHERE person_id = ?",
            [$personId]
        );
        $updated = 0; $errors = []; $firstName = null;
        $botChannels = ['bale', 'telegram', 'roubika', 'bale_safir'];

        foreach ($identities as $idn) {
            if (!in_array($idn['channel'], $botChannels, true)) continue; // فقط کانال‌های رباتی getChat دارند
            $driver = ChannelManager::get($idn['channel']);
            if (!$driver || !method_exists($driver, 'getChat')) {
                $errors[] = "{$idn['channel']}: درایور در دسترس نیست";
                continue;
            }
            try {
                $info = $driver->getChat((string)$idn['channel_uid']);
            } catch (Throwable $e) {
                $errors[] = "{$idn['channel']}: " . $e->getMessage();
                continue;
            }
            if (!$info) { $errors[] = "{$idn['channel']}: پاسخی از پلتفرم نیامد"; continue; }

            Database::run(
                "UPDATE eb_identities SET username = ?, first_name = ?, last_seen = NOW() WHERE id = ?",
                [$info['username'] ?? null, $info['first_name'] ?? null, $idn['id']]
            );
            $updated++;
            if (!$firstName && !empty($info['first_name'])) {
                $firstName = trim(($info['first_name'] ?? '') . ' ' . ($info['last_name'] ?? ''));
            }
        }

        // اگر شخص نام نداشت، نام به‌دست‌آمده را بگذار
        if ($firstName) {
            Database::run(
                "UPDATE eb_persons SET full_name = COALESCE(NULLIF(full_name,''), ?) WHERE id = ?",
                [$firstName, $personId]
            );
        }
        return ['updated' => $updated, 'errors' => $errors];
    }
}
