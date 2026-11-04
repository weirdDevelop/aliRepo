<?php
/**
 * modules/Campaigns.php
 * کمپین چندمرحله‌ای با پیگیری خودکار.
 *
 * مفهوم:
 *  - هر کمپین چند «مرحله» (step) دارد، با ترتیب و فاصله‌ی زمانی.
 *  - مرحله‌ی ۱ به کل مخاطب هدف می‌رود.
 *  - مرحله‌های بعد بعد از delay_hours، و اگر only_nonresponders=1،
 *    فقط به کسانی که به مرحله‌ی قبل «جواب نداده‌اند» می‌رود.
 *  - cron مراحل سررسیده را اجرا می‌کند.
 *
 * «جواب دادن» = وجود حداقل یک پیام ورودی (direction='in') از آن شخص
 * بعد از زمان ارسال مرحله‌ی قبل.
 */

class Campaigns
{
    public static function all(): array
    {
        return Database::all(
            "SELECT c.*,
               (SELECT COUNT(*) FROM eb_campaign_steps WHERE campaign_id=c.id) AS step_count
             FROM eb_campaigns c ORDER BY c.created_at DESC LIMIT 100"
        );
    }

    public static function find(int $id): ?array
    {
        $c = Database::one("SELECT * FROM eb_campaigns WHERE id = ?", [$id]);
        if (!$c) return null;
        $c['steps'] = Database::all(
            "SELECT * FROM eb_campaign_steps WHERE campaign_id = ? ORDER BY step_order ASC",
            [$id]
        );
        return $c;
    }

    public static function create(string $name, array $filter, array $channels, array $steps): int
    {
        Database::run(
            "INSERT INTO eb_campaigns (name, channels, filter_json, status) VALUES (?, ?, ?, 'draft')",
            [$name, implode(',', $channels), json_encode($filter, JSON_UNESCAPED_UNICODE)]
        );
        $cid = Database::lastId();
        $order = 1;
        foreach ($steps as $s) {
            Database::run(
                "INSERT INTO eb_campaign_steps (campaign_id, step_order, template_id, text, buttons_json, delay_hours, only_nonresponders)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $cid, $order,
                    !empty($s['template_id']) ? (int)$s['template_id'] : null,
                    $s['text'] ?? null,
                    !empty($s['buttons_json']) ? $s['buttons_json'] : null,
                    (int)($s['delay_hours'] ?? 72),
                    !empty($s['only_nonresponders']) ? 1 : 0,
                ]
            );
            $order++;
        }
        return $cid;
    }

    public static function delete(int $id): void
    {
        Database::run("DELETE FROM eb_campaign_steps WHERE campaign_id = ?", [$id]);
        Database::run("DELETE FROM eb_campaign_runs WHERE campaign_id = ?", [$id]);
        Database::run("DELETE FROM eb_campaigns WHERE id = ?", [$id]);
    }

    private static function stepMessage(array $step): array
    {
        if (!empty($step['template_id'])) {
            $tpl = Templates::find((int)$step['template_id']);
            if ($tpl) return Templates::toMessage($tpl);
        }
        $msg = ['text' => $step['text'] ?? ''];
        if (!empty($step['buttons_json'])) {
            $dec = json_decode($step['buttons_json'], true);
            if (is_array($dec)) $msg['buttons'] = Templates::normalizeButtons($dec);
        }
        return $msg;
    }

    public static function start(int $id): array
    {
        $c = self::find($id);
        if (!$c) return ['ok' => false, 'error' => 'کمپین یافت نشد'];
        if (empty($c['steps'])) return ['ok' => false, 'error' => 'کمپین هیچ مرحله‌ای ندارد'];

        Database::run("UPDATE eb_campaigns SET status='sending' WHERE id=?", [$id]);
        $res = self::runStep($c, $c['steps'][0]);

        $hasMore = count($c['steps']) > 1;
        Database::run("UPDATE eb_campaigns SET status=? WHERE id=?", [$hasMore ? 'sending' : 'sent', $id]);

        return ['ok' => true] + $res;
    }

    private static function runStep(array $campaign, array $step): array
    {
        $filter = json_decode($campaign['filter_json'] ?? '{}', true) ?: [];
        $channels = array_filter(explode(',', $campaign['channels'] ?? '')) ?: ['bale','telegram','roubika'];

        $persons = Sender::resolveAudience($filter);

        if (!empty($step['only_nonresponders']) && (int)$step['step_order'] > 1) {
            $persons = self::filterNonResponders((int)$campaign['id'], (int)$step['step_order'], $persons);
        }

        if (!$persons) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0];
        }

        $message = self::stepMessage($step);
        $sendId = SendLog::start('campaign', $campaign['name'] . ' - مرحله ' . $step['step_order'], $message['text'] ?? null, $channels);
        $result = Sender::sendBulk($persons, $message, $channels, 50, null, $sendId);
        SendLog::finish($sendId, $result['total'], $result['sent'], $result['failed'], ['by_channel' => $result['by_channel'] ?? []]);

        foreach ($persons as $pid) {
            Database::run(
                "INSERT INTO eb_campaign_runs (campaign_id, step_id, person_id, status) VALUES (?, ?, ?, 'sent')",
                [$campaign['id'], $step['id'], (int)$pid]
            );
        }

        return $result;
    }

    private static function filterNonResponders(int $campaignId, int $stepOrder, array $persons): array
    {
        $prevStep = Database::one(
            "SELECT * FROM eb_campaign_steps WHERE campaign_id=? AND step_order=?",
            [$campaignId, $stepOrder - 1]
        );
        if (!$prevStep) return $persons;

        $out = [];
        foreach ($persons as $pid) {
            $pid = (int)$pid;
            $run = Database::one(
                "SELECT run_at FROM eb_campaign_runs WHERE campaign_id=? AND step_id=? AND person_id=? ORDER BY run_at DESC LIMIT 1",
                [$campaignId, $prevStep['id'], $pid]
            );
            if (!$run) continue;

            $responded = Database::one(
                "SELECT 1 FROM eb_messages m
                 INNER JOIN eb_identities i ON i.id=m.identity_id
                 WHERE i.person_id=? AND m.direction='in' AND m.created_at >= ? LIMIT 1",
                [$pid, $run['run_at']]
            );
            if (!$responded) {
                $out[] = $pid;
            } else {
                Database::run(
                    "UPDATE eb_campaign_runs SET responded=1 WHERE campaign_id=? AND step_id=? AND person_id=?",
                    [$campaignId, $prevStep['id'], $pid]
                );
            }
        }
        return $out;
    }

    public static function processDue(): int
    {
        $count = 0;
        $active = Database::all("SELECT * FROM eb_campaigns WHERE status='sending'");
        foreach ($active as $c) {
            $campaign = self::find((int)$c['id']);
            if (!$campaign || empty($campaign['steps'])) {
                Database::run("UPDATE eb_campaigns SET status='sent' WHERE id=?", [$c['id']]);
                continue;
            }
            $steps = $campaign['steps'];

            $doneOrders = [];
            foreach ($steps as $st) {
                $has = Database::one("SELECT 1 FROM eb_campaign_runs WHERE step_id=? LIMIT 1", [$st['id']]);
                if ($has) $doneOrders[(int)$st['step_order']] = true;
            }

            $next = null;
            foreach ($steps as $st) {
                if (empty($doneOrders[(int)$st['step_order']])) { $next = $st; break; }
            }
            if (!$next) {
                Database::run("UPDATE eb_campaigns SET status='sent' WHERE id=?", [$c['id']]);
                continue;
            }

            $prevOrder = (int)$next['step_order'] - 1;
            if ($prevOrder >= 1) {
                $prevStep = null;
                foreach ($steps as $st) { if ((int)$st['step_order'] === $prevOrder) { $prevStep = $st; break; } }
                $lastRun = $prevStep ? Database::one("SELECT MAX(run_at) m FROM eb_campaign_runs WHERE step_id=?", [$prevStep['id']]) : null;
                if ($lastRun && $lastRun['m']) {
                    $due = strtotime($lastRun['m']) + ((int)$next['delay_hours'] * 3600);
                    if (time() < $due) continue;
                }
            }

            self::runStep($campaign, $next);
            $count++;

            if ((int)$next['step_order'] >= count($steps)) {
                Database::run("UPDATE eb_campaigns SET status='sent' WHERE id=?", [$c['id']]);
            }
        }
        return $count;
    }

    public static function stats(int $id): array
    {
        $steps = Database::all("SELECT * FROM eb_campaign_steps WHERE campaign_id=? ORDER BY step_order", [$id]);
        $out = [];
        foreach ($steps as $st) {
            $sent = (int)(Database::one("SELECT COUNT(*) c FROM eb_campaign_runs WHERE step_id=?", [$st['id']])['c'] ?? 0);
            $resp = (int)(Database::one("SELECT COUNT(*) c FROM eb_campaign_runs WHERE step_id=? AND responded=1", [$st['id']])['c'] ?? 0);
            $out[] = ['order' => $st['step_order'], 'sent' => $sent, 'responded' => $resp];
        }
        return $out;
    }
}
