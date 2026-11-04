<?php
/**
 * modules/AgentLifecycle.php — پیگیری فروش و نظرسنجی بعد از رزرو (با cron)
 * ==========================================================================
 * ۱) پیگیری بعد از پیشنهاد: اگر دستیار گزینه پیشنهاد داده و مشتری چند ساعت جواب نداده و سفارشی هم
 *    ثبت نشده، یک پیام پیگیری کوتاه (نوشته‌ی دستیار یا متن ثابت) می‌رود. در اینستاگرام فقط داخل
 *    پنجره‌ی ۲۴ ساعته‌ی متا.
 * ۲) یادآوری پرداخت: سفارش «منتظر پرداخت» بعد از N دقیقه (اول وضعیت تازه از وب‌سرویس گرفته می‌شود).
 * ۳) پیام روز ورود: صبح روز ورود (ساعت ۱۴، کارت ملی، پشتیبانی).
 * ۴) نظرسنجی بعد از اقامت: روز خروج؛ پاسخ ۱ تا ۵ در همان گفتگو ثبت می‌شود، امتیاز پایین به تیم
 *    اعلان می‌دهد و گفتگو را به همکار می‌سپارد؛ بعد یک توضیح اختیاری گرفته می‌شود.
 * اگر پنجره‌ی ۲۴ ساعته‌ی اینستاگرام بسته باشد و مشتری شماره داشته باشد، با پیامک می‌رود.
 */
class AgentLifecycle
{
    const DEFAULTS = [
        'fu_enabled' => '1', 'fu_hours' => '3', 'fu_max' => '1', 'fu_mode' => 'ai',
        'fu_text' => 'سلام {name} 🌿 گزینه‌هایی که فرستادم رو دیدید؟ اگر سؤالی دارید یا گزینه‌ی دیگه‌ای می‌خواید بفرمایید؛ برای آخر هفته واحدها زود پر می‌شن.',
        'pay_enabled' => '1', 'pay_minutes' => '10',
        'pay_text' => "یادآوری: رزرو «{title}» هنوز پرداخت نشده. مهلت پرداخت محدود است و بعد از آن واحد آزاد می‌شود.\nلینک پرداخت: {checkout_url}",
        'arr_enabled' => '1', 'arr_hour' => '10',
        'arr_text' => 'سلام {name} 🌿 امروز روز ورود شما به «{title}» است. تحویل از ساعت ۱۴ و تخلیه ساعت ۱۲ روز آخر است؛ لطفاً کارت ملی همراهتان باشد. اگر هنگام ورود مشکلی بود، همین‌جا پیام بدهید.',
        'sv_enabled' => '1', 'sv_hour' => '13', 'sv_low' => '2',
        'sv_text' => 'سلام {name} 🌿 امیدواریم اقامت خوبی در «{title}» داشته باشید. از ۱ تا ۵ به اقامتتان چه امتیازی می‌دهید؟ (فقط عدد را بفرستید)',
        'sv_thanks' => 'ممنون از امتیازتان 🙏 اگر نکته‌ای درباره‌ی نظافت، تطابق با عکس‌ها یا میزبان دارید، بنویسید تا ثبت شود.',
        'sv_thanks_low' => 'خیلی متأسفیم که اقامت مطابق انتظارتان نبود 🙏 همکارانم همین امروز پیگیری می‌کنند؛ لطفاً بنویسید چه مشکلی پیش آمد.',
        'sv_comment_thanks' => 'نظرتان ثبت شد، ممنون که وقت گذاشتید 🌿',
        // بعد از رد شدن استعلام
        'rej_instant' => '1', 'rej_enabled' => '1', 'rej_hours' => '2',
        'rej_text' => 'سلام {name} 🌿 متأسفانه «{title}» برای تاریخ شما پر بود. چند گزینه‌ی مشابه برای همان تاریخ پیدا کنم؟ فقط بگید بودجه یا منطقه‌تون تغییری داره یا نه.',
        // گزارش روزانه به مدیران در بله
        'rep_enabled' => '0', 'rep_hour' => '22', 'rep_chats' => '', 'rep_last' => '',
    ];

    public static function opt(string $k): string
    {
        $v = SalesAgent::get($k);
        return $v !== '' ? $v : (self::DEFAULTS[$k] ?? '');
    }

    /** از cron.php */
    public static function tick(): array
    {
        if (!class_exists('SalesAgent') || SalesAgent::get('enabled') !== '1') return ['skip' => 'off'];
        ApiConnectors::ensure();
        $res = ['watch' => 0, 'followups' => 0, 'rejected' => 0, 'pay' => 0, 'arrival' => 0, 'survey' => 0, 'report' => 0];
        foreach (['watch' => 'watchOrders', 'followups' => 'leadFollowups', 'rejected' => 'rejFollowups', 'pay' => 'payReminders', 'arrival' => 'arrivals', 'survey' => 'surveys', 'report' => 'dailyReport'] as $k => $fn) {
            try { $res[$k] = self::$fn(); } catch (Throwable $e) { $res[$k] = 'error: ' . $e->getMessage(); error_log('[agent lifecycle ' . $k . '] ' . $e->getMessage()); }
        }
        return $res;
    }

    /* ---------------- ۰) پیگیری خودکار وضعیت استعلام‌ها (۱.۰.۱۱) ----------------
     * اگر وب‌هوک وب‌سرویس تنظیم نشده یا نرسید، هر چند دقیقه وضعیت استعلام‌های باز و رزروهای منتظر پرداخت
     * استعلام می‌شود و تغییر (تأیید، رد، لغو، پرداخت) مثل وب‌هوک در همان گفتگو به مشتری اعلام می‌شود.
     */
    const WATCH = ['enquiry_pending' => 4, 'pending' => 4, 'awaiting_host' => 4, 'awaiting_payment' => 10, 'pending_payment' => 10];
    const FINAL = ['paid', 'confirmed', 'completed', 'rejected', 'declined', 'cancelled', 'canceled', 'expired', 'refunded', 'failed', 'checked_out'];

    private static function watchOrders(): int
    {
        if (!Database::hasColumn('eb_agent_orders', 'polled_at')) return 0;
        // ۱.۰.۱۶: همه‌ی وضعیت‌های باز (نه فقط چند نام شناخته‌شده) — هر نامی که وب‌سرویس برای «منتظر میزبان» بدهد
        $fin = "'" . implode("','", self::FINAL) . "'";
        $rows = Database::all("SELECT * FROM eb_agent_orders WHERE (status IS NULL OR status NOT IN ($fin)) AND person_id IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
                                AND (polled_at IS NULL OR polled_at < DATE_SUB(NOW(), INTERVAL 4 MINUTE)) ORDER BY polled_at IS NOT NULL, polled_at LIMIT 10");
        $n = 0;
        foreach ($rows as $o) {
            $gap = self::WATCH[$o['status']] ?? 5;
            if ($o['polled_at'] && strtotime($o['polled_at']) > time() - $gap * 60) continue;
            Database::run("UPDATE eb_agent_orders SET polled_at = NOW() WHERE id = ?", [$o['id']]);
            $c = ApiConnectors::get((int)$o['connector_id']);
            if (!$c) continue;
            foreach ($c['tools'] as $t) {
                if (empty($t['track']) || !empty($t['confirm']) || $t['method'] !== 'GET' || !preg_match('/\{(\w+)\}/', $t['path'], $m)) continue;
                $r = ApiConnectors::call($c, $t, [$m[1] => $o['ref']], []);
                if (!$r['ok'] || !is_array($r['data'])) break;
                $new = (string)(ApiConnectors::find($r['data'], (string)(((array)$c['webhook'])['status_key'] ?? 'status')) ?? '');
                if ($new === '' || $new === (string)$o['status']) break;
                $event = self::statusEvent($new, (bool)ApiConnectors::find($r['data'], 'is_paid'));
                $payload = (array)$r['data'] + ['event' => $event];
                if (empty(ApiConnectors::find($payload, 'external_chat_id')) && !empty($o['identity_id'])) $payload['external_chat_id'] = 'eb-' . (int)$o['identity_id'];
                SalesAgent::onWebhook($c, $payload);
                ApiConnectors::log((int)$o['person_id'], (int)$c['id'], 'lifecycle', 'watch', ['ref' => $o['ref']], true, null, 'وضعیت ' . $o['ref'] . ': ' . $o['status'] . ' ← ' . $new, null, null);
                $n++;
                break;
            }
        }
        return $n;
    }

    private static function statusEvent(string $status, bool $paid): string
    {
        $s = strtolower($status);
        if ($paid || in_array($s, ['paid', 'confirmed', 'completed'], true)) return 'booking.paid';
        if (in_array($s, ['awaiting_payment', 'pending_payment', 'accepted', 'approved', 'host_approved', 'host_accepted', 'enquiry_accepted', 'ready_to_pay', 'unpaid'], true)) return 'booking.enquiry_accepted';
        if (in_array($s, ['rejected', 'declined', 'cancelled', 'canceled', 'expired', 'host_rejected', 'enquiry_rejected'], true)) return 'booking.enquiry_rejected';
        return 'booking.' . $status;
    }

    /* ---------------- ۱) پیگیری بعد از پیشنهاد ---------------- */
    private static function leadFollowups(): int
    {
        if (self::opt('fu_enabled') !== '1' || !SalesAgent::enabled()) return 0;
        $h = max(0.25, (float)self::opt('fu_hours'));
        $max = max(1, (int)self::opt('fu_max'));
        $rows = Database::all("SELECT s.person_id, s.last_offer_at, s.followups FROM eb_agent_state s
            JOIN eb_persons p ON p.id = s.person_id
            WHERE s.mode = 'ai' AND s.person_id > 0 AND s.last_offer_at IS NOT NULL AND s.followups < ?
              AND s.last_offer_at < DATE_SUB(NOW(), INTERVAL ? MINUTE) AND s.last_offer_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
              AND (s.last_followup_at IS NULL OR s.last_followup_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
              AND p.assigned_user_id IS NULL
              AND NOT EXISTS (SELECT 1 FROM eb_agent_orders o WHERE o.person_id = s.person_id AND o.created_at >= s.last_offer_at)
            ORDER BY s.last_offer_at LIMIT 15", [$max, (int)round($h * 60), (int)round($h * 60)]);
        $n = 0;
        foreach ($rows as $r) {
            $pid = (int)$r['person_id'];
            $last = self::lastMessages($pid);
            // مشتری بعد از پیشنهاد دیگر چیزی نگفته و آخرین پیام از ماست
            if (!$last || $last['dir'] !== 'out' || ($last['in_at'] && strtotime($last['in_at']) > strtotime($r['last_offer_at']))) {
                Database::run("UPDATE eb_agent_state SET followups = followups + 1, last_followup_at = NOW() WHERE person_id = ?", [$pid]); // دیگر بررسی نشود
                continue;
            }
            if (!self::windowOpen($last) || !SalesAgent::channelOn($last['ch'])) continue;
            $text = '';
            if (self::opt('fu_mode') === 'ai') {
                $msgs = SalesAgent::history($pid);
                $hrs = max(1, (int)round((time() - strtotime($r['last_offer_at'])) / 3600));
                $msgs[] = ['role' => 'system', 'content' => "[پیگیری خودکار] مشتری حدود {$hrs} ساعت پیش گزینه‌ها را گرفته و جوابی نداده. یک پیام پیگیری خیلی کوتاه، گرم و غیرتکراری بنویس: بپرس گزینه‌ها را دیده، اگر سؤال یا گزینه‌ی دیگری می‌خواهد بگوید، و اگر تاریخ نزدیک یا آخر هفته است فوریت واقعی را یادآوری کن. ابزار صدا نزن."];
                $r2 = SalesAgent::run($msgs, ['person_id' => $pid, 'identity_id' => $last['iid'], 'channel' => $last['ch'], 'marker' => 0, 'no_tools' => true]);
                if ($r2['ok']) $text = $r2['text'];
            }
            if ($text === '') $text = self::fill(self::opt('fu_text'), $pid, []);
            if (self::deliver($pid, $text, $last)) $n++;
            Database::run("UPDATE eb_agent_state SET followups = followups + 1, last_followup_at = NOW() WHERE person_id = ?", [$pid]);
            ApiConnectors::log($pid, null, 'lifecycle', 'followup', null, true, null, 'پیگیری بعد از پیشنهاد', $text, null);
        }
        return $n;
    }

    /* ---------------- ۲) یادآوری پرداخت ---------------- */
    private static function payReminders(): int
    {
        if (self::opt('pay_enabled') !== '1') return 0;
        $min = max(3, (int)self::opt('pay_minutes'));
        $rows = Database::all("SELECT * FROM eb_agent_orders WHERE status IN ('awaiting_payment','pending_payment') AND reminded_at IS NULL AND person_id IS NOT NULL
                                AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE) AND updated_at > DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 15", [$min]);
        $n = 0;
        foreach ($rows as $o) {
            Database::run("UPDATE eb_agent_orders SET reminded_at = NOW() WHERE id = ?", [$o['id']]);
            $o = self::refresh($o);
            if (!in_array($o['status'], ['awaiting_payment', 'pending_payment'], true) || empty($o['checkout_url'])) continue;
            if (self::deliver((int)$o['person_id'], self::fill(self::opt('pay_text'), (int)$o['person_id'], $o), self::lastMessages((int)$o['person_id']))) $n++;
            ApiConnectors::log((int)$o['person_id'], (int)$o['connector_id'], 'lifecycle', 'pay_reminder', null, true, null, 'یادآوری پرداخت ' . $o['ref'], null, null);
        }
        return $n;
    }

    /* ---------------- ۳) پیام روز ورود ---------------- */
    private static function arrivals(): int
    {
        if (self::opt('arr_enabled') !== '1') return 0;
        [$today] = SalesAgent::todayJalali();
        if ((int)(new DateTime('now', new DateTimeZone('Asia/Tehran')))->format('G') < (int)self::opt('arr_hour')) return 0;
        $rows = Database::all("SELECT * FROM eb_agent_orders WHERE status IN ('paid','confirmed','completed') AND arrival_at IS NULL AND person_id IS NOT NULL AND check_in = ? LIMIT 20", [$today]);
        $n = 0;
        foreach ($rows as $o) {
            Database::run("UPDATE eb_agent_orders SET arrival_at = NOW() WHERE id = ?", [$o['id']]);
            if (self::deliver((int)$o['person_id'], self::fill(self::opt('arr_text'), (int)$o['person_id'], $o), self::lastMessages((int)$o['person_id']))) $n++;
            ApiConnectors::log((int)$o['person_id'], (int)$o['connector_id'], 'lifecycle', 'arrival', null, true, null, 'پیام روز ورود ' . $o['ref'], null, null);
        }
        return $n;
    }

    /* ---------------- ۴) نظرسنجی بعد از اقامت ---------------- */
    private static function surveys(): int
    {
        if (self::opt('sv_enabled') !== '1') return 0;
        [$today] = SalesAgent::todayJalali();
        $hour = (int)(new DateTime('now', new DateTimeZone('Asia/Tehran')))->format('G');
        $d3 = new DateTime('-4 days', new DateTimeZone('Asia/Tehran'));
        [$y, $m, $d] = SalesAgent::g2j((int)$d3->format('Y'), (int)$d3->format('n'), (int)$d3->format('j'));
        $from = sprintf('%04d-%02d-%02d', $y, $m, $d);
        $rows = Database::all("SELECT * FROM eb_agent_orders WHERE status IN ('paid','confirmed','completed') AND survey_at IS NULL AND person_id IS NOT NULL
                                AND check_out IS NOT NULL AND check_out >= ? AND (check_out < ? OR (check_out = ? AND ? >= ?)) LIMIT 20",
                                [$from, $today, $today, $hour, (int)self::opt('sv_hour')]);
        $n = 0;
        foreach ($rows as $o) {
            $pid = (int)$o['person_id'];
            Database::run("UPDATE eb_agent_orders SET survey_at = NOW() WHERE id = ?", [$o['id']]);
            if (self::deliver($pid, self::fill(self::opt('sv_text'), $pid, $o), self::lastMessages($pid))) {
                Database::run("INSERT INTO eb_agent_state (person_id, mode, survey_order, survey_wait, survey_wait_at) VALUES (?, 'ai', ?, 'rating', NOW())
                               ON DUPLICATE KEY UPDATE survey_order = VALUES(survey_order), survey_wait = 'rating', survey_wait_at = NOW()", [$pid, $o['id']]);
                $n++;
            }
            ApiConnectors::log($pid, (int)$o['connector_id'], 'lifecycle', 'survey', null, true, null, 'نظرسنجی ' . $o['ref'], null, null);
        }
        return $n;
    }

    /**
     * پاسخ مشتری به نظرسنجی (از SalesAgent::handle، پیش از هوش مصنوعی).
     * @return bool پیام مصرف شد؟
     */
    public static function capture(int $pid, int $iid, string $channel, string $text): bool
    {
        $st = Database::one("SELECT survey_order, survey_wait, survey_wait_at FROM eb_agent_state WHERE person_id = ?", [$pid]);
        if (!$st || empty($st['survey_wait'])) return false;
        $clear = fn() => Database::run("UPDATE eb_agent_state SET survey_wait = NULL WHERE person_id = ?", [$pid]);
        $age = time() - strtotime((string)$st['survey_wait_at']);
        $oid = (int)$st['survey_order'];
        if ($st['survey_wait'] === 'rating') {
            if ($age > 4 * 86400) { $clear(); return false; }
            $t = strtr(trim($text), ['۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5']);
            $words = ['خیلی خوب' => 5, 'خیلی بد' => 1, 'عالی' => 5, 'افتضاح' => 1, 'متوسط' => 3, 'معمولی' => 3, 'خوب' => 4, 'بد' => 2];   // بلندترها اول
            $rating = preg_match('/^\D{0,12}([1-5])\D{0,25}$/u', $t, $m) ? (int)$m[1] : null;
            if ($rating === null && mb_strlen($t) <= 14) foreach ($words as $w => $v) if (mb_strpos($t, $w) !== false) { $rating = $v; break; }
            if ($rating === null) { $clear(); return false; }   // جواب دیگری داد؛ دستیار ادامه می‌دهد
            Database::run("INSERT INTO eb_agent_surveys (order_id, person_id, rating) VALUES (?,?,?)", [$oid ?: null, $pid, $rating]);
            if ($oid) Database::run("UPDATE eb_agent_orders SET rating = ? WHERE id = ?", [$rating, $oid]);
            Database::run("UPDATE eb_agent_state SET survey_wait = 'comment', survey_wait_at = NOW() WHERE person_id = ?", [$pid]);
            $low = $rating <= (int)self::opt('sv_low');
            SalesAgent::sendText($pid, $iid, $channel, self::opt($low ? 'sv_thanks_low' : 'sv_thanks'));
            if ($low) {
                SalesAgent::handoff($pid, 'امتیاز پایین نظرسنجی: ' . $rating . ' از ۵', false);
                if (class_exists('Notifier')) Notifier::push('ai_survey', '⭐ امتیاز پایین مهمان: ' . $rating . ' از ۵', 'نیاز به پیگیری فوری', 'index.php#inbox.v' . $pid, $pid);
            }
            return true;
        }
        if ($st['survey_wait'] === 'comment') {
            $clear();
            if ($age > 6 * 3600 || mb_strlen(trim($text)) < 3) return false;
            $sv = Database::one("SELECT id FROM eb_agent_surveys WHERE person_id = ? ORDER BY id DESC LIMIT 1", [$pid]);
            if ($sv) Database::run("UPDATE eb_agent_surveys SET comment = ? WHERE id = ?", [mb_substr(trim($text), 0, 2000), $sv['id']]);
            // اگر امتیاز پایین بود گفتگو با همکار است؛ وگرنه تشکر کوتاه
            if (!SalesAgent::humanOwns($pid)) SalesAgent::sendText($pid, $iid, $channel, self::opt('sv_comment_thanks'));
            return true;
        }
        return false;
    }

    /* ---------------- ۵) بعد از رد شدن استعلام ---------------- */
    private static function rejFollowups(): int
    {
        if (self::opt('rej_enabled') !== '1' || !SalesAgent::enabled()) return 0;
        $min = (int)round(max(0.25, (float)self::opt('rej_hours')) * 60);
        $rows = Database::all("SELECT * FROM eb_agent_orders WHERE status = 'rejected' AND rej_fu_at IS NULL AND person_id IS NOT NULL AND rejected_at IS NOT NULL
                                AND rejected_at < DATE_SUB(NOW(), INTERVAL ? MINUTE) AND rejected_at > DATE_SUB(NOW(), INTERVAL 2 DAY) LIMIT 15", [$min]);
        $n = 0;
        foreach ($rows as $o) {
            $pid = (int)$o['person_id'];
            Database::run("UPDATE eb_agent_orders SET rej_fu_at = NOW() WHERE id = ?", [$o['id']]);
            if (SalesAgent::humanOwns($pid)) continue;
            $last = self::lastMessages($pid);
            if (!$last || strtotime((string)$last['in_at']) > strtotime((string)$o['rejected_at'])) continue;
            if (!SalesAgent::channelOn($last['ch'])) continue;            // مشتری جواب داده
            if (Database::one("SELECT 1 FROM eb_agent_orders WHERE person_id = ? AND created_at > ? AND id <> ?", [$pid, $o['rejected_at'], $o['id']])) continue;  // سفارش تازه گرفته
            if (!self::windowOpen($last)) { self::deliver($pid, self::fill(self::opt('rej_text'), $pid, $o), $last); continue; }   // پیامک (اگر ممکن)
            $text = '';
            $msgs = SalesAgent::history($pid);
            if ($msgs) {
                $msgs[] = ['role' => 'system', 'content' => '[پیگیری بعد از رد استعلام] درخواست رزرو «' . ($o['title'] ?: 'اقامتگاه') . '»'
                    . ($o['check_in'] ? ' برای ' . $o['check_in'] . ($o['check_out'] ? ' تا ' . $o['check_out'] : '') : '') . ($o['guests'] ? ' برای ' . $o['guests'] . ' نفر' : '')
                    . ' از طرف میزبان رد شد و مشتری از آن موقع جوابی نداده. با جستجوی تازه برای همان تاریخ و تعداد نفرات، ۲ تا ۳ گزینه‌ی مشابه (همان منطقه یا نزدیک، قیمت نزدیک) پیدا کن و در یک پیام کوتاه و گرم پیشنهاد بده. اگر گزینه‌ای نبود، بپرس تاریخ یا منطقه انعطاف دارد.'];
                $r = SalesAgent::run($msgs, ['person_id' => $pid, 'identity_id' => $last['iid'], 'channel' => $last['ch'], 'marker' => 0]);
                if ($r['ok']) $text = $r['text'];
            }
            if ($text === '') $text = self::fill(self::opt('rej_text'), $pid, $o);
            if (SalesAgent::sendText($pid, $last['iid'], $last['ch'], $text)) $n++;
            ApiConnectors::log($pid, (int)$o['connector_id'], 'lifecycle', 'rej_followup', null, true, null, 'پیگیری بعد از رد استعلام ' . $o['ref'], $text, null);
        }
        return $n;
    }

    /* ---------------- ۶) گزارش روزانه به مدیران (بله) ---------------- */
    public static function reportChats(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)SalesAgent::get('rep_chats')))));
    }

    public static function isReportChat(string $channel, string $uid): bool
    {
        return $channel === 'bale' && in_array($uid, self::reportChats(), true);
    }

    private static function dailyReport(): int
    {
        if (self::opt('rep_enabled') !== '1' || !self::reportChats()) return 0;
        $now = new DateTime('now', new DateTimeZone('Asia/Tehran'));
        if ((int)$now->format('G') < (int)self::opt('rep_hour')) return 0;
        [$today] = SalesAgent::todayJalali();
        if (SalesAgent::get('rep_last') === $today) return 0;
        Database::run("INSERT INTO eb_settings (k, v) VALUES ('agent.rep_last', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$today]);
        return self::sendReport(self::reportChats());
    }

    /** ارسال گزارش امروز به چت‌های بله. @return int تعداد ارسال موفق */
    public static function sendReport(array $chats): int
    {
        $d = ChannelManager::get('bale');
        if (!$d) return 0;
        $text = self::buildReport();
        $n = 0;
        foreach ($chats as $c) {
            foreach (SalesAgent::split($text, 3800) as $part) { $r = $d->send(['channel_uid' => $c], ['text' => $part]); if (empty($r['ok'])) { error_log('[agent report] ' . ($r['error'] ?? '')); continue 2; } }
            $n++;
        }
        ApiConnectors::log(null, null, 'lifecycle', 'report', ['chats' => $chats], $n > 0, null, 'گزارش روزانه به ' . $n . ' مدیر', $text, null);
        return $n;
    }

    /** متن گزارش امروز (از نیمه‌شب به وقت تهران تا الان) */
    public static function buildReport(): string
    {
        ApiConnectors::ensure();
        $tz = new DateTimeZone('Asia/Tehran');
        $mid = (new DateTime('today', $tz))->format('Y-m-d H:i:s');
        $from = class_exists('Tz') ? (Tz::toDb($mid) ?? $mid) : $mid;
        [$jd, $wd] = SalesAgent::todayJalali();
        $fa = fn($v) => strtr((string)$v, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
        $num = fn($v) => $fa(number_format((float)$v));
        $one = fn(string $sql, array $p = []) => (int)(Database::one($sql, $p)['c'] ?? 0);
        $tool = fn(string $like) => $one("SELECT COUNT(DISTINCT person_id) c FROM eb_agent_log WHERE kind = 'tool' AND ok = 1 AND person_id > 0 AND created_at >= ? AND ($like)", [$from]);
        $hasSrc = Database::hasColumn('eb_messages', 'source');
        $chats = $hasSrc ? $one("SELECT COUNT(DISTINCT i.person_id) c FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id WHERE m.source = 'ai' AND m.direction = 'out' AND m.created_at >= ?", [$from]) : 0;
        $msgs = $hasSrc ? $one("SELECT COUNT(*) c FROM eb_messages WHERE source = 'ai' AND direction = 'out' AND created_at >= ?", [$from]) : 0;
        $offers = $tool("tool LIKE '%search' OR tool LIKE '%send_links'");
        $quotes = $tool("tool LIKE '%quote'");
        $orders = Database::all("SELECT title, ref, status, amount FROM eb_agent_orders WHERE created_at >= ? ORDER BY id DESC", [$from]);
        $paidRows = Database::all("SELECT amount FROM eb_agent_orders WHERE status IN ('paid','confirmed','completed') AND updated_at >= ?", [$from]);
        $paidSum = array_sum(array_map(fn($r) => (int)$r['amount'], $paidRows));
        $awaiting = $one("SELECT COUNT(*) c FROM eb_agent_orders WHERE status IN ('awaiting_payment','pending_payment') AND updated_at >= ?", [$from]);
        $rejected = $one("SELECT COUNT(*) c FROM eb_agent_orders WHERE status = 'rejected' AND updated_at >= ?", [$from]);
        $sv = Database::one("SELECT COUNT(*) n, AVG(rating) a FROM eb_agent_surveys WHERE rating IS NOT NULL AND created_at >= ?", [$from]) ?: [];
        $low = Database::all("SELECT s.rating, s.comment, o.title, COALESCE(NULLIF(p.full_name,''), CONCAT('مشتری ', p.id)) person FROM eb_agent_surveys s
                              LEFT JOIN eb_agent_orders o ON o.id = s.order_id LEFT JOIN eb_persons p ON p.id = s.person_id
                              WHERE s.rating IS NOT NULL AND s.rating <= ? AND s.created_at >= ? ORDER BY s.id", [(int)self::opt('sv_low'), $from]);
        $sec = $one("SELECT COUNT(*) c FROM eb_agent_security WHERE kind <> 'sim' AND created_at >= ?", [$from]);
        $paused = $one("SELECT COUNT(*) c FROM eb_agent_security WHERE action = 'paused' AND created_at >= ?", [$from]);
        $waiting = $one("SELECT COUNT(*) c FROM eb_agent_state s JOIN eb_persons p ON p.id = s.person_id WHERE s.mode = 'human' AND p.assigned_user_id IS NULL");
        $fails = Notifier::hasTable() ? $one("SELECT COUNT(*) c FROM eb_notifications WHERE type = 'ai_fail' AND created_at >= ?", [$from]) : 0;
        $st = ['awaiting_payment' => 'منتظر پرداخت', 'paid' => 'پرداخت‌شده', 'rejected' => 'رد شده', 'enquiry_pending' => 'منتظر میزبان', 'pending' => 'در انتظار'];

        $L = ["📊 گزارش دستیار فروش — {$wd} " . $fa($jd), ''];
        $L[] = "💬 گفتگو با دستیار: " . $fa($chats) . " مشتری · " . $fa($msgs) . " پیام";
        $L[] = "🔎 پیشنهاد اقامتگاه: " . $fa($offers) . " · 💵 استعلام قیمت: " . $fa($quotes);
        $L[] = "🧾 سفارش ثبت‌شده: " . $fa(count($orders)) . " · ✅ پرداخت‌شده: " . $fa(count($paidRows)) . ($paidSum ? ' (' . $num($paidSum) . ' تومان)' : '');
        if ($awaiting || $rejected) $L[] = "⏳ منتظر پرداخت: " . $fa($awaiting) . " · ❌ استعلام رد شده: " . $fa($rejected);
        if ($orders) {
            $L[] = ''; $L[] = 'سفارش‌های امروز:';
            foreach (array_slice($orders, 0, 8) as $o) $L[] = '• ' . ($o['title'] ?: 'کد ' . $o['ref']) . ($o['amount'] ? ' — ' . $num($o['amount']) . ' تومان' : '') . ' — ' . ($st[$o['status']] ?? ($o['status'] ?: '—'));
            if (count($orders) > 8) $L[] = '… و ' . $fa(count($orders) - 8) . ' سفارش دیگر';
        }
        $L[] = '';
        $L[] = "⭐ نظرسنجی امروز: " . ((int)($sv['n'] ?? 0) ? $fa((int)$sv['n']) . ' نظر، میانگین ' . $fa(round((float)$sv['a'], 1)) . ' از ۵' : 'نظری ثبت نشد');
        if ($low) {
            $L[] = '⚠️ امتیازهای پایین:';
            foreach ($low as $r) $L[] = '• ' . $r['person'] . ' — ' . $fa($r['rating']) . ' از ۵' . ($r['title'] ? ' — ' . $r['title'] : '') . ($r['comment'] ? ' — «' . mb_substr($r['comment'], 0, 120) . '»' : '');
        }
        $L[] = "🛡 هشدار امنیتی: " . $fa($sec) . ($paused ? ' (دستیار برای ' . $fa($paused) . ' مشتری متوقف شد)' : '');
        $L[] = "🙋 منتظر همکار: " . $fa($waiting) . " گفتگو" . ($fails ? " · ❗ پاسخ ناموفق دستیار: " . $fa($fails) : '');
        $cbT = $one("SELECT COUNT(*) c FROM eb_agent_callbacks WHERE created_at >= ?", [$from]);
        $cbO = $one("SELECT COUNT(*) c FROM eb_agent_callbacks WHERE status = 'open'");
        if ($cbT || $cbO) $L[] = "📞 درخواست تماس امروز: " . $fa($cbT) . " · بی‌پاسخ: " . $fa($cbO);
        // ۱.۰.۱۳: تقاضای امروز برای بازاریابی
        try {
            $dm = AgentDemand::summary(1);
            if ($dm['total']) {
                $top = fn(string $k, int $n = 3) => implode('، ', array_map(fn($x) => $x['label'] . ' ' . $fa($x['n']), array_slice($dm['by'][$k] ?? [], 0, $n)));
                $L[] = '';
                $L[] = '🧭 تقاضای امروز (' . $fa($dm['total']) . ' درخواست):';
                if ($t = $top('area')) $L[] = '• مقصد: ' . $t;
                if ($t = $top('class')) $L[] = '• رده‌ی قیمت: ' . $t;
                if ($t = $top('amenity')) $L[] = '• امکانات: ' . $t;
                if ($t = $top('group')) $L[] = '• نوع مسافر: ' . $t;
                if ($dm['kpi']['unmet']) $L[] = '• بی‌نتیجه (جا نداشتیم): ' . $fa($dm['kpi']['unmet']);
            }
        } catch (Throwable $e) {}
        return implode("\n", $L);
    }

    /* ---------------- کمکی‌ها ---------------- */

    /** آخرین پیام‌های گفتگو + هویت پاسخ */
    private static function lastMessages(int $pid): ?array
    {
        $out = Database::one("SELECT m.direction, m.created_at FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id WHERE i.person_id = ? ORDER BY m.id DESC LIMIT 1", [$pid]);
        $in = Database::one("SELECT m.created_at, i.id iid, i.channel FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id WHERE i.person_id = ? AND m.direction = 'in' ORDER BY m.id DESC LIMIT 1", [$pid]);
        if (!$out || !$in) return null;
        return ['dir' => $out['direction'], 'in_at' => $in['created_at'], 'iid' => (int)$in['iid'], 'ch' => (string)$in['channel']];
    }

    /** پنجره‌ی ۲۴ ساعته‌ی اینستاگرام (کمی کمتر برای اطمینان) */
    private static function windowOpen(array $last): bool
    {
        if (strpos($last['ch'], 'instagram') !== 0) return true;
        return strtotime($last['in_at']) > time() - 23 * 3600 - 30 * 60;
    }

    /** ارسال در همان گفتگو؛ اگر پنجره‌ی اینستاگرام بسته بود، با پیامک (اگر شماره و پنل پیامک هست) */
    private static function deliver(int $pid, string $text, ?array $last): bool
    {
        if (trim($text) === '') return false;
        if ($last && self::windowOpen($last)) return SalesAgent::sendText($pid, $last['iid'], $last['ch'], $text);
        $p = Database::one("SELECT phone FROM eb_persons WHERE id = ?", [$pid]);
        if (!empty($p['phone']) && ChannelManager::get('sms')) {
            $r = Sender::sendToPerson($pid, ['text' => $text], ['sms'], null, ['source' => 'ai']);
            if (empty($r['ok'])) {
                // هویت پیامکی نداشت: مستقیم
                $d = ChannelManager::get('sms');
                $x = $d ? $d->send(['phone' => $p['phone'], 'channel_uid' => $p['phone']], ['text' => $text]) : ['ok' => false];
                return !empty($x['ok']);
            }
            return true;
        }
        ApiConnectors::log($pid, null, 'lifecycle', 'skip', null, false, null, 'پنجره‌ی ۲۴ ساعته‌ی اینستاگرام بسته است و شماره/پیامک نداریم', $text, null);
        return false;
    }

    private static function fill(string $tpl, int $pid, array $o): string
    {
        $p = Database::one("SELECT full_name FROM eb_persons WHERE id = ?", [$pid]);
        $name = trim((string)($p['full_name'] ?? ''));
        $t = strtr($tpl, ['{name}' => $name, '{title}' => (string)($o['title'] ?? ''), '{ref}' => (string)($o['ref'] ?? ''), '{checkout_url}' => (string)($o['checkout_url'] ?? ''),
            '{check_in}' => (string)($o['check_in'] ?? ''), '{check_out}' => (string)($o['check_out'] ?? ''), '{nl}' => "\n"]);
        return trim(preg_replace('/\s+([،,!؟?])/u', '$1', str_replace('سلام  ', 'سلام ', $t)));
    }

    /** وضعیت تازه‌ی سفارش از وب‌سرویس (ابزار پیگیری با track) */
    private static function refresh(array $o): array
    {
        $c = ApiConnectors::get((int)$o['connector_id']);
        if (!$c) return $o;
        foreach ($c['tools'] as $t) {
            if (empty($t['track']) || !empty($t['confirm']) || $t['method'] !== 'GET' || !preg_match('/\{(\w+)\}/', $t['path'], $m)) continue;
            $r = ApiConnectors::call($c, $t, [$m[1] => $o['ref']], []);
            if ($r['ok']) {
                $u = ApiConnectors::trackOrder($c, $r['data'], (int)$o['person_id'], null);
                if ($u) return $u;
            }
            break;
        }
        return $o;
    }
}
