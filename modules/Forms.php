<?php
/**
 * modules/Forms.php
 * فرم/نظرسنجی چندمرحله‌ای با حافظه‌ی وضعیت گفتگو.
 *
 * منطق حالت‌دار:
 *  - کاربر کلمه‌ی کلیدی فرم را می‌فرستد → جلسه (session) شروع، سؤال ۱ پرسیده می‌شود.
 *  - هر پیام بعدی کاربر = جواب سؤال فعلی → ذخیره، سؤال بعد.
 *  - بعد از آخرین سؤال → پاسخ‌ها در eb_form_responses ذخیره، پیام پایان، جلسه completed.
 *
 * questions_json: [{q:"سؤال؟"}, ...]
 * یک کاربر در هر لحظه فقط یک جلسه‌ی active دارد.
 */

class Forms
{
    public static function all(): array
    {
        return Database::all(
            "SELECT f.*, (SELECT COUNT(*) FROM eb_form_responses WHERE form_id=f.id) AS response_count
             FROM eb_forms f ORDER BY f.created_at DESC"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one("SELECT * FROM eb_forms WHERE id = ?", [$id]);
    }

    public static function save(array $data, ?int $id = null): int
    {
        $name    = trim($data['name'] ?? '');
        $trigger = trim($data['trigger_keyword'] ?? '');
        $qs      = $data['questions_json'] ?? null;
        $finish  = (string)($data['finish_text'] ?? 'با تشکر، پاسخ شما ثبت شد.');
        $active  = !empty($data['is_active']) ? 1 : 0;
        $finishImg = $data['finish_image'] ?? null;

        if ($id) {
            Database::run(
                "UPDATE eb_forms SET name=?, trigger_keyword=?, questions_json=?, finish_text=?, finish_image=?, is_active=? WHERE id=?",
                [$name, $trigger ?: null, $qs, $finish, $finishImg, $active, $id]
            );
            return $id;
        }
        Database::run(
            "INSERT INTO eb_forms (name, trigger_keyword, questions_json, finish_text, finish_image, is_active) VALUES (?, ?, ?, ?, ?, ?)",
            [$name, $trigger ?: null, $qs, $finish, $finishImg, $active]
        );
        return Database::lastId();
    }

    public static function delete(int $id): void
    {
        Database::run("DELETE FROM eb_form_sessions WHERE form_id = ?", [$id]);
        Database::run("DELETE FROM eb_form_responses WHERE form_id = ?", [$id]);
        Database::run("DELETE FROM eb_forms WHERE id = ?", [$id]);
    }

    public static function responses(int $formId): array
    {
        return Database::all(
            "SELECT r.*, p.full_name FROM eb_form_responses r
             LEFT JOIN eb_persons p ON p.id = r.person_id
             WHERE r.form_id = ? ORDER BY r.created_at DESC LIMIT 500",
            [$formId]
        );
    }

    /** جلسه‌ی فعال کاربر (اگر باشد) */
    private static function activeSession(string $channel, string $uid): ?array
    {
        return Database::one(
            "SELECT * FROM eb_form_sessions WHERE channel=? AND channel_uid=? AND status='active' ORDER BY id DESC LIMIT 1",
            [$channel, $uid]
        );
    }

    /**
     * پردازش ورودی کاربر در چارچوب فرم.
     * خروجی true یعنی فرم این پیام را مصرف کرد (نباید به autoreply/menu برود).
     *
     * دو حالت:
     *  ۱) کاربر جلسه‌ی فعال دارد → این پیام جواب سؤال فعلی است.
     *  ۲) جلسه ندارد ولی متن با کلمه‌ی کلیدی یک فرم می‌خورد → شروع فرم.
     */
    public static function handle(string $channel, string $uid, ?string $text, ?int $personId): bool
    {
        if ($text === null || trim($text) === '') return false;
        $text = trim($text);

        $driver = ChannelManager::get($channel);
        if (!$driver) return false;

        // حالت ۱: جلسه‌ی فعال هست → جواب را بگیر
        $session = self::activeSession($channel, $uid);
        if ($session) {
            // امکان لغو
            if ($text === 'لغو' || $text === '/cancel') {
                Database::run("UPDATE eb_form_sessions SET status='cancelled' WHERE id=?", [$session['id']]);
                BotReply::send($channel, $uid, ['text'=>'فرم لغو شد.'], BotReply::SRC_FORM);
                return true;
            }
            return self::recordAnswer($driver, $channel, $uid, $session, $text, $personId);
        }

        // حالت ۲: شروع فرم با کلمه‌ی کلیدی
        $form = Database::one(
            "SELECT * FROM eb_forms WHERE is_active=1 AND trigger_keyword IS NOT NULL AND trigger_keyword = ? LIMIT 1",
            [$text]
        );
        if (!$form) return false;

        $questions = json_decode($form['questions_json'] ?? '[]', true);
        if (!is_array($questions) || !$questions) return false;

        // ساخت جلسه و پرسیدن سؤال اول
        Database::run(
            "INSERT INTO eb_form_sessions (form_id, channel, channel_uid, person_id, step, answers_json, status)
             VALUES (?, ?, ?, ?, 0, '[]', 'active')",
            [$form['id'], $channel, $uid, $personId]
        );
        // سؤال اول از مسیر BotReply تا در تاریخچه‌ی گفتگو ثبت شود (مورد ۱۰)
        BotReply::send($channel, $uid, ['text'=>$questions[0]['q'] ?? 'سؤال ۱'], BotReply::SRC_FORM);
        return true;
    }

    /** ذخیره‌ی جواب سؤال فعلی و رفتن به بعدی (یا پایان) */
    private static function recordAnswer($driver, string $channel, string $uid, array $session, string $answer, ?int $personId): bool
    {
        $form = self::find((int)$session['form_id']);
        if (!$form) {
            Database::run("UPDATE eb_form_sessions SET status='cancelled' WHERE id=?", [$session['id']]);
            return false;
        }
        $questions = json_decode($form['questions_json'] ?? '[]', true) ?: [];
        $answers = json_decode($session['answers_json'] ?? '[]', true) ?: [];
        $step = (int)$session['step'];

        // ثبت جواب سؤال فعلی
        $answers[] = ['q' => $questions[$step]['q'] ?? '', 'a' => $answer];
        $step++;

        if ($step >= count($questions)) {
            // فرم تمام شد → ذخیره‌ی پاسخ نهایی
            Database::run(
                "INSERT INTO eb_form_responses (form_id, person_id, channel, channel_uid, answers_json)
                 VALUES (?, ?, ?, ?, ?)",
                [$form['id'], $personId ?: $session['person_id'], $channel, $uid, json_encode($answers, JSON_UNESCAPED_UNICODE)]
            );
            Database::run("UPDATE eb_form_sessions SET status='completed', step=?, answers_json=? WHERE id=?",
                [$step, json_encode($answers, JSON_UNESCAPED_UNICODE), $session['id']]);

            // مورد ۵: نوتیف به پشتیبان — یک «استعلام» روی سایت تکمیل شد
            if (class_exists('Notifier')) {
                $pid = $personId ?: ($session['person_id'] ?? null);
                $who = null;
                if ($pid) {
                    try { $who = Database::one("SELECT full_name FROM eb_persons WHERE id=?", [$pid])['full_name'] ?? null; }
                    catch (Throwable $e) { /* بی‌اثر */ }
                }
                Notifier::push(
                    'form',
                    'فرم «' . $form['name'] . '» تکمیل شد' . ($who ? " — {$who}" : ''),
                    !empty($answers) ? mb_substr((string)($answers[0]['a'] ?? ''), 0, 200) : null,
                    $pid ? "index.php?page=forms&id={$form['id']}" : "index.php?page=forms"
                );
            }

            $finishMsg = ['text'=>$form['finish_text'] ?: 'با تشکر، پاسخ شما ثبت شد.'];
            if (!empty($form['finish_image']) && is_file($form['finish_image'])) {
                $finishMsg['photo'] = $form['finish_image'];
            }
            BotReply::send($channel, $uid, $finishMsg, BotReply::SRC_FORM);
            // سناریوهایی که با تکمیل این فرم شروع می‌شوند (پاسخ‌ها در payload.answers و payload.a1، a2، …)
            try {
                if (!class_exists('Flows') && is_file(__DIR__ . '/Flows.php')) require_once __DIR__ . '/Flows.php';
                if (class_exists('Flows')) {
                    $pl = ['form_id' => (int)$form['id'], 'form' => $form['name'], 'answers' => $answers];
                    foreach ($answers as $i => $an) $pl['a' . ($i + 1)] = $an['a'] ?? '';
                    Flows::onEvent('form_submit', ['person_id' => (int)($personId ?: ($session['person_id'] ?? 0)), 'channel' => $channel, 'channel_uid' => $uid, 'form_id' => (int)$form['id'], 'payload' => $pl]);
                }
            } catch (Throwable $e) {}
            return true;
        }

        // سؤال بعدی
        Database::run("UPDATE eb_form_sessions SET step=?, answers_json=? WHERE id=?",
            [$step, json_encode($answers, JSON_UNESCAPED_UNICODE), $session['id']]);
        BotReply::send($channel, $uid, ['text'=>$questions[$step]['q'] ?? ('سؤال ' . ($step+1))], BotReply::SRC_FORM);
        return true;
    }
}
