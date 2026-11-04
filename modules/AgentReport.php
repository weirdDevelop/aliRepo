<?php
/**
 * modules/AgentReport.php
 * گزارش راندمان اپراتورها در یک بازه‌ی زمانی.
 *
 * تعریف‌ها (همان‌ها که در صفحه‌ی گزارش توضیح داده می‌شود):
 *  - پاسخ: پیامی که اپراتور از صندوق ورودی فرستاده (پیام‌های بات و پاسخ خودکار حساب نمی‌شوند).
 *  - زمان پاسخ: از اولین پیام بی‌جواب مشتری تا پاسخ اپراتور. پاسخ بات انتظار را تمام نمی‌کند،
 *    چون مشتری هنوز منتظر یک انسان است. زمان واقعی است (ساعت‌های غیرکاری هم حساب می‌شود).
 *  - اولین پاسخ: زمان پاسخ برای اولین پیام هر دور گفتگو (بعد از بسته شدن یا اولین تماس).
 *  - در مهلت (SLA): پاسخ‌هایی که زودتر از مهلت اولویت آن گفتگو رسیده‌اند.
 */
class AgentReport
{
    public static function build(string $from, string $to, ?int $onlyUser = null): array
    {
        // بازه به وقت دیتابیس؛ حداکثر ۹۳ روز
        $fromTs = strtotime($from . ' 00:00:00'); $toTs = strtotime($to . ' 23:59:59');
        if (!$fromTs || !$toTs || $toTs < $fromTs) throw new InvalidArgumentException('بازه‌ی تاریخ نامعتبر است');
        if ($toTs - $fromTs > 93 * 86400) $fromTs = $toTs - 93 * 86400 + 1;
        $f = Tz::toDb(date('Y-m-d H:i:s', $fromTs)); $t = Tz::toDb(date('Y-m-d H:i:s', $toTs));

        $names = ChatAssign::userNames();
        $users = [];
        foreach (Database::all("SELECT id, role, is_active FROM eb_users WHERE role <> 'viewer'") as $u) {
            $id = (int)$u['id'];
            if ($onlyUser && $id !== $onlyUser) continue;
            $users[$id] = ['user_id' => $id, 'name' => $names[$id] ?? ('#' . $id), 'role' => $u['role'], 'active' => (bool)$u['is_active'],
                'replies' => 0, 'chats' => 0, 'first' => 0, 'rt' => [], 'frt' => [], 'in_sla' => 0, 'sla_n' => 0,
                'closed' => 0, 'notes' => 0, 'claims' => 0, 'transfers_out' => 0, 'transfers_in' => 0, 'csat' => null, 'days' => []];
        }
        if (!Database::hasColumn('eb_messages', 'sent_by')) return ['users' => array_values($users), 'from' => $f, 'to' => $t, 'note' => 'برای این گزارش «به‌روزرسانی دیتابیس» لازم است'];

        // گفتگوهایی که در بازه پاسخ انسانی گرفته‌اند
        $pids = array_map(fn($r) => (int)$r['person_id'], Database::all(
            "SELECT DISTINCT i.person_id FROM eb_messages m INNER JOIN eb_identities i ON i.id = m.identity_id
             WHERE m.direction = 'out' AND m.sent_by IS NOT NULL AND m.created_at BETWEEN ? AND ?" . ($onlyUser ? " AND m.sent_by = " . (int)$onlyUser : ""), [$f, $t]));
        $sla = Desk::slaMinutes();
        $prio = [];
        if ($pids) foreach (array_chunk($pids, 500) as $ch) foreach (Database::all("SELECT person_id, priority FROM eb_conv_state WHERE person_id IN (" . implode(',', $ch) . ")") as $r) $prio[(int)$r['person_id']] = $r['priority'];
        $chatSet = [];
        // پیمایش پیام‌ها به ترتیب زمان برای هر گفتگو (۲ روز قبل از بازه هم برای پیدا کردن شروع انتظار)
        $pre = date('Y-m-d H:i:s', strtotime($f) - 2 * 86400);
        foreach (array_chunk($pids, 300) as $chunk) {
            $st = Database::run("SELECT i.person_id, m.direction, m.sent_by, m.created_at FROM eb_messages m INNER JOIN eb_identities i ON i.id = m.identity_id
                                 WHERE i.person_id IN (" . implode(',', $chunk) . ") AND m.created_at BETWEEN ? AND ?" . (Inbox::hasDeleted() ? " AND m.deleted_at IS NULL" : "") . "
                                 ORDER BY i.person_id, m.created_at, m.id", [$pre, $t]);
            $curP = null; $wait = null; $lastHumanOut = null;
            while ($m = $st->fetch()) {
                $p = (int)$m['person_id'];
                if ($p !== $curP) { $curP = $p; $wait = null; $lastHumanOut = null; }
                $ts = strtotime($m['created_at']);
                if ($m['direction'] === 'in') { if ($wait === null) $wait = $ts; continue; }
                if (empty($m['sent_by'])) continue;                  // پاسخ بات انتظار را تمام نمی‌کند
                $u = (int)$m['sent_by'];
                $inRange = $m['created_at'] >= $f;
                if ($inRange && isset($users[$u])) {
                    $users[$u]['replies']++;
                    $chatSet[$u][$p] = true;
                    $day = substr(Tz::toLocal($m['created_at']), 0, 10);
                    $users[$u]['days'][$day] = ($users[$u]['days'][$day] ?? 0) + 1;
                    if ($wait !== null) {
                        $sec = max(0, $ts - $wait);
                        $users[$u]['rt'][] = $sec;
                        // اولین پاسخ دور: قبلش پاسخ انسانی نبوده یا بیش از ۱۲ ساعت گذشته
                        if ($lastHumanOut === null || $wait - $lastHumanOut > 12 * 3600) { $users[$u]['frt'][] = $sec; $users[$u]['first']++; }
                        $target = ($sla[$prio[$p] ?? 'normal'] ?? 30) * 60;
                        $users[$u]['sla_n']++; if ($sec <= $target) $users[$u]['in_sla']++;
                    }
                }
                $wait = null; $lastHumanOut = $ts;
            }
        }
        foreach ($chatSet as $u => $set) if (isset($users[$u])) $users[$u]['chats'] = count($set);

        // بستن گفتگو و یادداشت‌ها
        if (Database::hasTable('eb_conv_events')) {
            foreach (Database::all("SELECT user_id, SUM(type = 'status' AND body LIKE 'closed%') closed, SUM(type = 'note') notes FROM eb_conv_events
                                    WHERE user_id IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY user_id", [$f, $t]) as $r)
                if (isset($users[(int)$r['user_id']])) { $users[(int)$r['user_id']]['closed'] = (int)$r['closed']; $users[(int)$r['user_id']]['notes'] = (int)$r['notes']; }
        }
        // تخصیص و انتقال
        if (Database::hasTable('eb_assign_log')) {
            foreach (Database::all("SELECT action, by_user_id, to_user_id, from_user_id FROM eb_assign_log WHERE created_at BETWEEN ? AND ?", [$f, $t]) as $r) {
                $by = (int)$r['by_user_id']; $to = (int)$r['to_user_id']; $fr = (int)$r['from_user_id'];
                if (in_array($r['action'], ['claim', 'takeover', 'auto'], true) && isset($users[$to])) $users[$to]['claims']++;
                if ($r['action'] === 'transfer') { if ($fr && isset($users[$fr])) $users[$fr]['transfers_out']++; if ($to && isset($users[$to])) $users[$to]['transfers_in']++; }
            }
        }
        // رضایت مشتری
        if (class_exists('Csat')) foreach (Csat::summary($f, $t) as $u => $c) if (isset($users[$u])) $users[$u]['csat'] = $c;

        $med = function (array $a) { if (!$a) return null; sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n, 2)] : (int)(($a[$n / 2 - 1] + $a[$n / 2]) / 2); };
        $avg = fn(array $a) => $a ? (int)round(array_sum($a) / count($a)) : null;
        $allRt = []; $allFrt = []; $tot = ['replies' => 0, 'chats' => 0, 'closed' => 0, 'in_sla' => 0, 'sla_n' => 0, 'csat_sum' => 0, 'csat_n' => 0];
        $out = [];
        foreach ($users as $u) {
            $allRt = array_merge($allRt, $u['rt']); $allFrt = array_merge($allFrt, $u['frt']);
            foreach (['replies', 'chats', 'closed', 'in_sla', 'sla_n'] as $k) $tot[$k] += $u[$k];
            if ($u['csat'] && $u['csat']['rated']) { $tot['csat_sum'] += $u['csat']['avg'] * $u['csat']['rated']; $tot['csat_n'] += $u['csat']['rated']; }
            $u['rt_avg'] = $avg($u['rt']); $u['rt_med'] = $med($u['rt']); $u['frt_avg'] = $avg($u['frt']); $u['frt_med'] = $med($u['frt']);
            $u['sla_pct'] = $u['sla_n'] ? (int)round($u['in_sla'] * 100 / $u['sla_n']) : null;
            unset($u['rt'], $u['frt']);
            ksort($u['days']);
            if (!$u['replies'] && !$u['closed'] && !$u['claims'] && !$u['active']) continue;
            $out[] = $u;
        }
        usort($out, fn($a, $b) => $b['replies'] <=> $a['replies']);
        return [
            'from' => Tz::toLocal($f), 'to' => Tz::toLocal($t), 'users' => $out,
            'team' => ['replies' => $tot['replies'], 'chats' => $tot['chats'], 'closed' => $tot['closed'],
                'rt_med' => $med($allRt), 'frt_med' => $med($allFrt), 'frt_avg' => $avg($allFrt),
                'sla_pct' => $tot['sla_n'] ? (int)round($tot['in_sla'] * 100 / $tot['sla_n']) : null,
                'csat' => $tot['csat_n'] ? round($tot['csat_sum'] / $tot['csat_n'], 2) : null, 'csat_n' => $tot['csat_n']],
            'sla' => $sla,
        ];
    }
}
