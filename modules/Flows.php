<?php
/**
 * modules/Flows.php
 * سناریوساز: موتور اجرای گراف گره‌ها (شبیه n8n) برای همه‌ی کانال‌ها و گفتگوی سایت.
 *
 * گراف: {nodes:[{id,type,x,y,data}], edges:[{from,port,to}]}
 * هر سناریو دقیقاً یک گره‌ی شروع (trigger) دارد. اجرای هر سناریو برای یک مخاطب یک «اجرا» (run) است
 * با متغیرها (ctx) و پشته‌ی حلقه‌ها؛ گره‌های انتظار (تأخیر، منتظر پاسخ) اجرا را متوقف می‌کنند و cron
 * یا پیام بعدی مخاطب آن را ادامه می‌دهد.
 *
 * درگاه‌های خروجی:
 *   out | true,false (شرط) | case_0..n,default (سوییچ) | item,done (حلقه) | reply,timeout (منتظر پاسخ)
 *   ok,error (درخواست HTTP و هوش مصنوعی) | a_0..n (تقسیم تصادفی)
 */
class Flows
{
    const MAX_STEPS = 300;          // سقف گام‌ها در یک اجرای پیوسته (جلوگیری از حلقه‌ی بی‌پایان)
    const MAX_LOOP = 200;           // سقف تکرار هر حلقه
    const LOG_MAX = 120;
    const SRC = 'flow';

    const TRIGGERS = ['message_in', 'contact_shared', 'webhook', 'schedule', 'manual', 'form_submit'];

    private static function boot(): void
    {
        if (!class_exists('SchemaV4')) require_once __DIR__ . '/../core/SchemaV4.php';
        if (!class_exists('Tz')) require_once __DIR__ . '/../core/Tz.php';
        SchemaV4::ensure();
    }

    /* ============================ ذخیره و فهرست ============================ */

    public static function all(): array
    {
        self::boot();
        $rows = Database::all("SELECT id, name, description, is_active, trigger_type, trigger_json, runs, errors, last_run_at, updated_at, created_at,
                                      (SELECT COUNT(*) FROM eb_flow_runs r WHERE r.flow_id = f.id AND r.status = 'waiting') waiting
                               FROM eb_flows f ORDER BY is_active DESC, updated_at DESC");
        foreach ($rows as &$r) { $r['trigger'] = json_decode((string)$r['trigger_json'], true) ?: []; unset($r['trigger_json']); $r['last_run_local'] = Tz::toLocal($r['last_run_at']); }
        unset($r);
        return $rows;
    }

    public static function get(int $id): ?array
    {
        self::boot();
        $f = Database::one("SELECT * FROM eb_flows WHERE id = ?", [$id]);
        if (!$f) return null;
        $f['graph'] = json_decode((string)$f['graph_json'], true) ?: ['nodes' => [], 'edges' => []];
        $f['trigger'] = json_decode((string)$f['trigger_json'], true) ?: [];
        unset($f['graph_json'], $f['trigger_json']);
        return $f;
    }

    /** ذخیره با اعتبارسنجی گراف */
    public static function save(array $in): array
    {
        self::boot();
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 160);
        if ($name === '') return ['ok' => false, 'error' => 'نام سناریو را بنویسید'];
        $g = is_array($in['graph'] ?? null) ? $in['graph'] : (json_decode((string)($in['graph'] ?? ''), true) ?: []);
        $check = self::validate($g);
        if (!$check['ok']) return $check;
        $g = $check['graph'];
        $trigger = $check['trigger'];
        $active = !empty($in['is_active']) ? 1 : 0;
        if ($active && !empty($check['warnings_block'])) return ['ok' => false, 'error' => $check['warnings_block']];
        $next = $trigger['type'] === 'schedule' ? self::nextScheduleRun($trigger['data'], null) : null;
        $params = [$name, mb_substr(trim((string)($in['description'] ?? '')), 0, 500) ?: null, $active, $trigger['type'],
                   json_encode($trigger['data'], JSON_UNESCAPED_UNICODE), json_encode($g, JSON_UNESCAPED_UNICODE), $next];
        $id = (int)($in['id'] ?? 0);
        if ($id) {
            Database::run("UPDATE eb_flows SET name=?, description=?, is_active=?, trigger_type=?, trigger_json=?, graph_json=?, next_run_at=? WHERE id=?", array_merge($params, [$id]));
        } else {
            Database::run("INSERT INTO eb_flows (name, description, is_active, trigger_type, trigger_json, graph_json, next_run_at, created_by) VALUES (?,?,?,?,?,?,?,?)",
                array_merge($params, [class_exists('Auth') ? Auth::id() : null]));
            $id = Database::lastId();
        }
        return ['ok' => true, 'id' => $id, 'warnings' => $check['warnings']];
    }

    public static function setActive(int $id, bool $on): array
    {
        $f = self::get($id);
        if (!$f) return ['ok' => false, 'error' => 'سناریو پیدا نشد'];
        if ($on) {
            $v = self::validate($f['graph']);
            if (!$v['ok']) return $v;
            if (!empty($v['warnings_block'])) return ['ok' => false, 'error' => $v['warnings_block']];
        }
        $next = ($on && $f['trigger_type'] === 'schedule') ? self::nextScheduleRun($f['trigger'], null) : null;
        Database::run("UPDATE eb_flows SET is_active=?, next_run_at=? WHERE id=?", [$on ? 1 : 0, $next, $id]);
        return ['ok' => true];
    }

    public static function delete(int $id): void
    {
        self::boot();
        Database::run("DELETE FROM eb_flow_runs WHERE flow_id = ?", [$id]);
        Database::run("DELETE FROM eb_flows WHERE id = ?", [$id]);
    }

    public static function duplicate(int $id): array
    {
        $f = self::get($id);
        if (!$f) return ['ok' => false, 'error' => 'سناریو پیدا نشد'];
        return self::save(['name' => $f['name'] . ' (کپی)', 'description' => $f['description'], 'graph' => $f['graph'], 'is_active' => 0]);
    }

    /** اعتبارسنجی و پاک‌سازی گراف */
    public static function validate(array $g): array
    {
        $nodes = []; $edges = []; $trigger = null; $warn = []; $block = null;
        foreach (array_slice((array)($g['nodes'] ?? []), 0, 300) as $n) {
            if (!is_array($n)) continue;
            $id = preg_match('/^[a-zA-Z0-9_-]{1,40}$/', (string)($n['id'] ?? '')) ? $n['id'] : null;
            $type = preg_match('/^[a-z_.]{2,40}$/', (string)($n['type'] ?? '')) ? $n['type'] : null;
            if (!$id || !$type || isset($nodes[$id])) continue;
            $nodes[$id] = ['id' => $id, 'type' => $type, 'x' => (int)($n['x'] ?? 0), 'y' => (int)($n['y'] ?? 0), 'data' => is_array($n['data'] ?? null) ? $n['data'] : [], 'note' => mb_substr((string)($n['note'] ?? ''), 0, 200)];
            if (strpos($type, 'trigger.') === 0) {
                if ($trigger) return ['ok' => false, 'error' => 'هر سناریو فقط یک گره‌ی شروع می‌تواند داشته باشد'];
                $tt = substr($type, 8);
                if (!in_array($tt, self::TRIGGERS, true)) return ['ok' => false, 'error' => 'نوع شروع ناشناخته است'];
                $trigger = ['type' => $tt, 'data' => $nodes[$id]['data'], 'id' => $id];
            }
        }
        if (!$trigger) return ['ok' => false, 'error' => 'سناریو گره‌ی «شروع» ندارد؛ از ستون ابزارها یکی اضافه کنید'];
        foreach ((array)($g['edges'] ?? []) as $e) {
            if (!is_array($e)) continue;
            $f = (string)($e['from'] ?? ''); $t = (string)($e['to'] ?? ''); $p = preg_match('/^[a-z0-9_]{1,20}$/', (string)($e['port'] ?? 'out')) ? (string)($e['port'] ?? 'out') : 'out';
            if (!isset($nodes[$f]) || !isset($nodes[$t]) || $f === $t) continue;
            if (strpos($nodes[$t]['type'], 'trigger.') === 0) continue;
            $edges[$f . '|' . $p . '|' . $t] = ['from' => $f, 'port' => $p, 'to' => $t];
        }
        $edges = array_values($edges);
        $hasOut = false;
        foreach ($edges as $e) if ($e['from'] === $trigger['id']) $hasOut = true;
        if (!$hasOut) $block = 'گره‌ی شروع به هیچ گره‌ای وصل نیست';
        // گره‌هایی که از شروع قابل دسترسی نیستند
        $reach = [$trigger['id'] => 1]; $queue = [$trigger['id']];
        while ($queue) { $c = array_shift($queue); foreach ($edges as $e) if ($e['from'] === $c && !isset($reach[$e['to']])) { $reach[$e['to']] = 1; $queue[] = $e['to']; } }
        $orphans = count($nodes) - count($reach);
        if ($orphans > 0) $warn[] = $orphans . ' گره به مسیر سناریو وصل نیست و اجرا نمی‌شود';
        foreach ($nodes as $n) {
            if ($n['type'] === 'act.send' && trim((string)($n['data']['text'] ?? '')) === '' && empty($n['data']['image'])) $warn[] = 'یک گره‌ی «ارسال پیام» متن ندارد';
            if ($n['type'] === 'act.http' && !preg_match('~^https?://~i', (string)($n['data']['url'] ?? ''))) $warn[] = 'آدرس یک «درخواست HTTP» معتبر نیست';
        }
        return ['ok' => true, 'graph' => ['nodes' => array_values($nodes), 'edges' => $edges], 'trigger' => $trigger, 'warnings' => array_values(array_unique($warn)), 'warnings_block' => $block];
    }

    /* ============================ رویدادها (ورودی) ============================ */

    /**
     * پیام ورودی. از AutoReply::handle صدا زده می‌شود (همه‌ی کانال‌ها و گفتگوی سایت).
     * true یعنی سناریویی پیام را «مصرف» کرد و پاسخ خودکار/هوش مصنوعی نباید جواب بدهد.
     */
    public static function onMessage(string $channel, string $channelUid, string $text): bool
    {
        try {
            self::boot();
            $ident = Database::one("SELECT id, person_id, ig_account_id FROM eb_identities WHERE channel = ? AND channel_uid = ? LIMIT 1", [$channel, $channelUid]);
            $pid = (int)($ident['person_id'] ?? 0);
            $consumed = false;

            // ۱) اجراهایی که منتظر پاسخ همین مخاطب‌اند
            if ($pid) {
                $waits = Database::all("SELECT * FROM eb_flow_runs WHERE person_id = ? AND status = 'waiting' AND wait_for = 'reply' ORDER BY id LIMIT 5", [$pid]);
                foreach ($waits as $run) {
                    $claim = Database::run("UPDATE eb_flow_runs SET status = 'running' WHERE id = ? AND status = 'waiting'", [$run['id']]);
                    if (!$claim || $claim->rowCount() !== 1) continue;
                    $ctx = json_decode((string)$run['ctx_json'], true) ?: [];
                    $ctx['text'] = $text; $ctx['channel'] = $channel; $ctx['channel_uid'] = $channelUid;
                    $node = self::nodeOf((int)$run['flow_id'], (string)$run['current_node']);
                    $port = 'reply';
                    if ($node) {
                        $valid = self::validateReply($node['data']['expect'] ?? 'any', $text);
                        if (!$valid) {
                            $tries = (int)($ctx['_tries'][$node['id']] ?? 0) + 1;
                            $ctx['_tries'][$node['id']] = $tries;
                            $max = max(1, (int)($node['data']['retries'] ?? 2));
                            if ($tries <= $max) {
                                $msg = trim((string)($node['data']['invalid_text'] ?? '')) ?: 'پاسخ معتبر نیست، لطفاً دوباره بفرستید.';
                                self::sendTo($ctx, ['text' => self::tpl($msg, $ctx)]);
                                Database::run("UPDATE eb_flow_runs SET status='waiting', ctx_json=? WHERE id=?", [json_encode($ctx, JSON_UNESCAPED_UNICODE), $run['id']]);
                                $consumed = true; continue;
                            }
                            $port = 'timeout';
                        } else {
                            $var = self::varName($node['data']['save_as'] ?? 'reply');
                            $ctx['vars'][$var] = self::normalizeReply($node['data']['expect'] ?? 'any', $text);
                            if (($node['data']['expect'] ?? '') === 'phone' && $pid && class_exists('Persons')) {
                                try { Persons::attachPhone($pid, (string)$ctx['vars'][$var]); } catch (Throwable $e) {}
                            }
                        }
                    }
                    Database::run("UPDATE eb_flow_runs SET ctx_json=?, resume_at=NULL, wait_for=NULL WHERE id=?", [json_encode($ctx, JSON_UNESCAPED_UNICODE), $run['id']]);
                    self::continueFrom((int)$run['id'], (string)$run['current_node'], $port);
                    $consumed = true;
                }
            }
            if ($consumed) return true;

            // ۲) سناریوهایی که با این پیام شروع می‌شوند
            $flows = Database::all("SELECT id, trigger_json FROM eb_flows WHERE is_active = 1 AND trigger_type = 'message_in' ORDER BY id");
            foreach ($flows as $f) {
                $t = json_decode((string)$f['trigger_json'], true) ?: [];
                if (!self::triggerMatches($t, $channel, $text, $pid, (int)($ident['ig_account_id'] ?? 0), (int)$f['id'])) continue;
                $ctx = self::baseCtx($pid, $channel, $channelUid, $text, 'message_in');
                self::start((int)$f['id'], $ctx);
                if (!array_key_exists('consume', $t) || !empty($t['consume'])) $consumed = true;
            }
            return $consumed;
        } catch (Throwable $e) {
            error_log('[flows] onMessage: ' . $e->getMessage());
            return false;
        }
    }

    /** رویدادهای دیگر: contact_shared / form_submit */
    public static function onEvent(string $type, array $info): void
    {
        try {
            self::boot();
            $flows = Database::all("SELECT id, trigger_json FROM eb_flows WHERE is_active = 1 AND trigger_type = ?", [$type]);
            foreach ($flows as $f) {
                $t = json_decode((string)$f['trigger_json'], true) ?: [];
                if (!empty($t['channels']) && !in_array($info['channel'] ?? '', (array)$t['channels'], true)) continue;
                if ($type === 'form_submit' && !empty($t['form_id']) && (int)$t['form_id'] !== (int)($info['form_id'] ?? 0)) continue;
                $ctx = self::baseCtx((int)($info['person_id'] ?? 0), (string)($info['channel'] ?? ''), (string)($info['channel_uid'] ?? ''), (string)($info['text'] ?? ''), $type);
                if (!empty($info['payload'])) $ctx['payload'] = $info['payload'];
                self::start((int)$f['id'], $ctx);
            }
        } catch (Throwable $e) { error_log('[flows] onEvent: ' . $e->getMessage()); }
    }

    /** وب‌هوک ورودی (flow-hook.php) */
    public static function onWebhook(int $flowId, string $key, array $payload): array
    {
        self::boot();
        $f = Database::one("SELECT id, is_active, trigger_type, trigger_json FROM eb_flows WHERE id = ?", [$flowId]);
        if (!$f || $f['trigger_type'] !== 'webhook') return ['ok' => false, 'error' => 'not_found', 'code' => 404];
        $t = json_decode((string)$f['trigger_json'], true) ?: [];
        if (empty($t['key']) || !hash_equals((string)$t['key'], $key)) return ['ok' => false, 'error' => 'forbidden', 'code' => 403];
        if (!(int)$f['is_active']) return ['ok' => false, 'error' => 'inactive', 'code' => 409];
        // پیدا کردن مخاطب از روی شماره یا شناسه‌ی ارسالی
        $pid = 0;
        $phone = class_exists('Persons') ? Persons::normalizePhone((string)($payload[$t['phone_field'] ?? 'phone'] ?? '')) : null;
        if ($phone) $pid = (int)(Database::one("SELECT id FROM eb_persons WHERE phone = ? LIMIT 1", [$phone])['id'] ?? 0);
        if (!$pid && !empty($payload['person_id'])) $pid = (int)$payload['person_id'];
        $ctx = self::baseCtx($pid, '', '', '', 'webhook');
        $ctx['payload'] = $payload;
        if ($phone) $ctx['vars']['phone'] = $phone;
        $runId = self::start($flowId, $ctx);
        return ['ok' => true, 'run_id' => $runId];
    }

    /** اجرای دستی از پنل برای یک یا چند مخاطب (یا بدون مخاطب) */
    public static function runManual(int $flowId, array $personIds, array $vars = []): array
    {
        self::boot();
        $f = Database::one("SELECT id FROM eb_flows WHERE id = ?", [$flowId]);
        if (!$f) return ['ok' => false, 'error' => 'سناریو پیدا نشد'];
        $ids = [];
        $personIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $personIds)))), 0, 500);
        if (!$personIds) $personIds = [0];
        foreach ($personIds as $pid) {
            $ctx = self::baseCtx($pid, '', '', '', 'manual');
            foreach ($vars as $k => $v) $ctx['vars'][self::varName((string)$k)] = is_scalar($v) ? (string)$v : $v;
            $ids[] = self::start($flowId, $ctx, true);
        }
        return ['ok' => true, 'runs' => $ids];
    }

    private static function triggerMatches(array $t, string $channel, string $text, int $pid, int $igAcc, int $flowId): bool
    {
        if (!empty($t['channels'])) {
            $chs = (array)$t['channels'];
            if (in_array('instagram', $chs, true)) $chs[] = 'instagram_apichi';
            if (!in_array($channel, $chs, true)) return false;
        }
        if (!empty($t['ig_account_id']) && (int)$t['ig_account_id'] !== $igAcc) return false;
        $mode = $t['match'] ?? 'any';
        $kws = array_values(array_filter(array_map('trim', preg_split('/[\r\n,،]+/u', (string)($t['keywords'] ?? '')))));
        $norm = function ($s) { return mb_strtolower(trim(strtr((string)$s, ['ي' => 'ی', 'ك' => 'ک', "\u{200c}" => ' ']))); };
        $tx = $norm($text);
        if ($mode !== 'any') {
            $hit = false;
            foreach ($kws as $k) {
                $k2 = $norm($k);
                if ($mode === 'exact' && $tx === $k2) $hit = true;
                elseif ($mode === 'contains' && $k2 !== '' && mb_strpos($tx, $k2) !== false) $hit = true;
                elseif ($mode === 'starts' && $k2 !== '' && mb_strpos($tx, $k2) === 0) $hit = true;
                elseif ($mode === 'regex' && @preg_match('~' . str_replace('~', '\~', $k) . '~iu', $text)) $hit = true;
                if ($hit) break;
            }
            if (!$hit) return false;
        }
        if ($pid) {
            if (!empty($t['first_only'])) {
                $n = (int)(Database::one("SELECT COUNT(*) c FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id WHERE i.person_id = ? AND m.direction = 'in'", [$pid])['c'] ?? 0);
                if ($n > 1) return false;
            }
            // یک اجرای باز از همین سناریو برای همین مخاطب کافی است
            if (Database::one("SELECT id FROM eb_flow_runs WHERE flow_id = ? AND person_id = ? AND status IN ('running','waiting') LIMIT 1", [$flowId, $pid])) return false;
            $cool = (int)($t['cooldown'] ?? 0);   // دقیقه
            if ($cool > 0 && Database::one("SELECT id FROM eb_flow_runs WHERE flow_id = ? AND person_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE) LIMIT 1", [$flowId, $pid, $cool])) return false;
            if (!empty($t['once']) && Database::one("SELECT id FROM eb_flow_runs WHERE flow_id = ? AND person_id = ? LIMIT 1", [$flowId, $pid])) return false;
        }
        if (!empty($t['hours']) && $t['hours'] !== 'any' && class_exists('Support')) {
            $on = (bool)(Support::status()['online'] ?? true);
            if ($t['hours'] === 'online' && !$on) return false;
            if ($t['hours'] === 'offline' && $on) return false;
        }
        return true;
    }

    private static function baseCtx(int $pid, string $channel, string $uid, string $text, string $trigger): array
    {
        $ctx = ['person_id' => $pid, 'channel' => $channel, 'channel_uid' => $uid, 'text' => $text, 'trigger' => $trigger, 'vars' => [], 'started' => Tz::nowLocal()];
        if ($pid && (!$channel || !$uid)) {
            // کانالی که آخرین بار مخاطب از آن پیام داده (برای پاسخ)
            $i = Database::one("SELECT i.channel, i.channel_uid FROM eb_identities i LEFT JOIN eb_messages m ON m.identity_id = i.id AND m.direction = 'in'
                                WHERE i.person_id = ? AND i.channel NOT IN ('sms','bale_safir','email') GROUP BY i.id ORDER BY MAX(m.created_at) DESC LIMIT 1", [$pid]);
            if ($i) { $ctx['channel'] = $ctx['channel'] ?: $i['channel']; $ctx['channel_uid'] = $ctx['channel_uid'] ?: $i['channel_uid']; }
        }
        return $ctx;
    }

    /* ============================ اجرا ============================ */

    public static function start(int $flowId, array $ctx, bool $force = false): int
    {
        $f = self::get($flowId);
        if (!$f || (!$force && !(int)$f['is_active'])) return 0;
        $trig = null;
        foreach ($f['graph']['nodes'] as $n) if (strpos($n['type'], 'trigger.') === 0) $trig = $n;
        if (!$trig) return 0;
        Database::run("INSERT INTO eb_flow_runs (flow_id, status, person_id, channel, trigger_type, ctx_json, stack_json, current_node, log_json) VALUES (?, 'running', ?, ?, ?, ?, '[]', ?, '[]')",
            [$flowId, $ctx['person_id'] ?: null, $ctx['channel'] ?: null, $ctx['trigger'] ?? null, json_encode($ctx, JSON_UNESCAPED_UNICODE), $trig['id']]);
        $runId = Database::lastId();
        Database::run("UPDATE eb_flows SET runs = runs + 1, last_run_at = NOW() WHERE id = ?", [$flowId]);
        self::continueFrom($runId, $trig['id'], 'out');
        return $runId;
    }

    /** ادامه‌ی اجرا از درگاه $port گره‌ی $nodeId */
    public static function continueFrom(int $runId, string $nodeId, string $port): void
    {
        $run = Database::one("SELECT * FROM eb_flow_runs WHERE id = ?", [$runId]);
        if (!$run) return;
        $flow = self::get((int)$run['flow_id']);
        if (!$flow) { self::finish($runId, 'failed', 'سناریو حذف شده است', [], []); return; }
        $nodes = []; foreach ($flow['graph']['nodes'] as $n) $nodes[$n['id']] = $n;
        $edges = $flow['graph']['edges'];
        $ctx = json_decode((string)$run['ctx_json'], true) ?: [];
        $stack = json_decode((string)$run['stack_json'], true) ?: [];
        $log = json_decode((string)$run['log_json'], true) ?: [];
        $steps = (int)$run['steps'];

        $cur = self::next($edges, $nodeId, $port);
        $guard = 0;
        while (true) {
            if ($cur === null) {
                // پایان یک شاخه: اگر داخل حلقه‌ایم، به گره‌ی حلقه برگرد
                if ($stack) { $cur = end($stack)['node']; $ctx['_loop_return'] = true; }
                else { self::finish($runId, 'done', null, $ctx, $log, $steps); return; }
            }
            if (++$guard > self::MAX_STEPS) { self::finish($runId, 'failed', 'تعداد گام‌ها از سقف ' . self::MAX_STEPS . ' گذشت (احتمالاً حلقه‌ی بی‌پایان)', $ctx, $log, $steps); return; }
            $node = $nodes[$cur] ?? null;
            if (!$node) { self::finish($runId, 'failed', 'گره‌ی ' . $cur . ' پیدا نشد', $ctx, $log, $steps); return; }
            $steps++;
            try {
                $res = self::exec($node, $ctx, $stack, (int)$run['flow_id'], $runId);
            } catch (Throwable $e) {
                $res = ['port' => 'error', 'error' => mb_substr($e->getMessage(), 0, 300)];
            }
            $log[] = ['n' => $node['id'], 't' => $node['type'], 'p' => $res['port'] ?? null, 'e' => $res['error'] ?? null, 'at' => date('H:i:s')];
            if (count($log) > self::LOG_MAX) $log = array_slice($log, -self::LOG_MAX);

            if (!empty($res['wait'])) {
                Database::run("UPDATE eb_flow_runs SET status='waiting', current_node=?, resume_at=?, wait_for=?, ctx_json=?, stack_json=?, log_json=?, steps=? WHERE id=?",
                    [$node['id'], $res['resume_at'] ?? null, $res['wait'], json_encode($ctx, JSON_UNESCAPED_UNICODE), json_encode($stack), json_encode($log, JSON_UNESCAPED_UNICODE), $steps, $runId]);
                return;
            }
            if (!empty($res['stop'])) { self::finish($runId, 'done', null, $ctx, $log, $steps); return; }
            $port = $res['port'] ?? 'out';
            if ($port === 'error' && !self::hasPort($edges, $node['id'], 'error')) {
                self::finish($runId, 'failed', ($res['error'] ?? 'خطا') . ' (گره: ' . self::label($node) . ')', $ctx, $log, $steps);
                return;
            }
            $cur = self::next($edges, $node['id'], $port);
        }
    }

    private static function finish(int $runId, string $status, ?string $error, array $ctx, array $log, int $steps = 0): void
    {
        Database::run("UPDATE eb_flow_runs SET status=?, error=?, ctx_json=?, log_json=?, steps=?, resume_at=NULL, wait_for=NULL WHERE id=?",
            [$status, $error ? mb_substr($error, 0, 480) : null, json_encode($ctx, JSON_UNESCAPED_UNICODE), json_encode($log, JSON_UNESCAPED_UNICODE), $steps, $runId]);
        if ($status === 'failed') {
            $fid = (int)(Database::one("SELECT flow_id FROM eb_flow_runs WHERE id=?", [$runId])['flow_id'] ?? 0);
            if ($fid) Database::run("UPDATE eb_flows SET errors = errors + 1 WHERE id = ?", [$fid]);
        }
    }

    private static function next(array $edges, string $from, string $port): ?string
    {
        foreach ($edges as $e) if ($e['from'] === $from && $e['port'] === $port) return $e['to'];
        return null;
    }

    private static function hasPort(array $edges, string $from, string $port): bool
    {
        foreach ($edges as $e) if ($e['from'] === $from && $e['port'] === $port) return true;
        return false;
    }

    private static function nodeOf(int $flowId, string $nodeId): ?array
    {
        $f = self::get($flowId);
        if (!$f) return null;
        foreach ($f['graph']['nodes'] as $n) if ($n['id'] === $nodeId) return $n;
        return null;
    }

    private static function label(array $n): string
    {
        return trim((string)($n['data']['title'] ?? '')) ?: $n['type'];
    }

    /* ============================ گره‌ها ============================ */

    private static function exec(array $node, array &$ctx, array &$stack, int $flowId, int $runId): array
    {
        $d = $node['data'];
        switch ($node['type']) {
            /* ---- منطق ---- */
            case 'logic.if':
                return ['port' => self::evalRules($d, $ctx) ? 'true' : 'false'];

            case 'logic.switch':
                $val = self::resolve((string)($d['var'] ?? 'text'), $ctx);
                foreach (array_values((array)($d['cases'] ?? [])) as $i => $c) {
                    if (self::compare($val, (string)($c['op'] ?? 'equals'), self::tpl((string)($c['value'] ?? ''), $ctx))) return ['port' => 'case_' . $i];
                }
                return ['port' => 'default'];

            case 'logic.loop':
                $top = $stack ? end($stack) : null;
                if ($top && $top['node'] === $node['id'] && !empty($ctx['_loop_return'])) {
                    unset($ctx['_loop_return']);
                    $idx = $top['i'] + 1;
                    array_pop($stack);
                } else {
                    unset($ctx['_loop_return']);
                    $list = self::loopList($d, $ctx);
                    $top = ['node' => $node['id'], 'list' => $list, 'i' => -1];
                    $idx = 0;
                }
                if ($idx >= count($top['list']) || $idx >= self::MAX_LOOP) {
                    unset($ctx['vars'][self::varName($d['item_var'] ?? 'item')]);
                    return ['port' => 'done'];
                }
                $top['i'] = $idx;
                $stack[] = $top;
                $ctx['vars'][self::varName($d['item_var'] ?? 'item')] = $top['list'][$idx];
                $ctx['vars']['index'] = $idx + 1;
                return ['port' => 'item'];

            case 'logic.split':
                $weights = array_map(fn($b) => max(0, (int)($b['weight'] ?? 0)), (array)($d['branches'] ?? [['weight' => 50], ['weight' => 50]]));
                $sum = array_sum($weights) ?: 1; $r = mt_rand(1, $sum); $acc = 0;
                foreach ($weights as $i => $w) { $acc += $w; if ($r <= $acc) return ['port' => 'a_' . $i]; }
                return ['port' => 'a_0'];

            case 'logic.wait':
                $amount = max(1, (int)($d['amount'] ?? 10)); $unit = in_array($d['unit'] ?? '', ['minute', 'hour', 'day'], true) ? $d['unit'] : 'minute';
                if (($d['mode'] ?? 'delay') === 'until') {
                    // تا ساعت مشخص (محلی)
                    $hm = preg_match('/^(\d{1,2}):(\d{2})$/', (string)($d['time'] ?? '09:00'), $m) ? [(int)$m[1], (int)$m[2]] : [9, 0];
                    $now = new DateTime('now', Tz::zone());
                    $target = (clone $now)->setTime($hm[0], $hm[1]);
                    if ($target <= $now) $target->modify('+1 day');
                    $local = $target->format('Y-m-d H:i:s');
                    return ['wait' => 'delay', 'resume_at' => Tz::toDb($local)];
                }
                $sec = $amount * ($unit === 'day' ? 86400 : ($unit === 'hour' ? 3600 : 60));
                $at = Database::one("SELECT DATE_ADD(NOW(), INTERVAL ? SECOND) t", [$sec])['t'];
                return ['wait' => 'delay', 'resume_at' => $at];

            case 'logic.wait_reply':
                if (empty($ctx['person_id']) || empty($ctx['channel_uid'])) return ['port' => 'timeout'];
                if (trim((string)($d['question'] ?? '')) !== '') self::sendTo($ctx, self::buildMessage(['text' => $d['question'], 'buttons' => $d['buttons'] ?? []], $ctx));
                $mins = max(1, min(43200, (int)($d['timeout'] ?? 60)));
                $at = Database::one("SELECT DATE_ADD(NOW(), INTERVAL ? MINUTE) t", [$mins])['t'];
                return ['wait' => 'reply', 'resume_at' => $at];

            case 'logic.stop':
                return ['stop' => true];

            /* ---- داده ---- */
            case 'data.set':
                foreach ((array)($d['sets'] ?? []) as $s) {
                    $name = self::varName($s['name'] ?? '');
                    if ($name === '') continue;
                    $op = $s['op'] ?? 'set';
                    $val = self::tpl((string)($s['value'] ?? ''), $ctx);
                    $cur = $ctx['vars'][$name] ?? null;
                    if ($op === 'add') $ctx['vars'][$name] = (float)$cur + (float)$val;
                    elseif ($op === 'sub') $ctx['vars'][$name] = (float)$cur - (float)$val;
                    elseif ($op === 'append') $ctx['vars'][$name] = (string)$cur . $val;
                    elseif ($op === 'extract') { $src = (string)self::resolve((string)($s['from'] ?? 'text'), $ctx); $ctx['vars'][$name] = @preg_match('~' . str_replace('~', '\~', $val) . '~u', $src, $mm) ? ($mm[1] ?? $mm[0]) : ''; }
                    elseif ($op === 'json') { $j = json_decode($val, true); $ctx['vars'][$name] = $j === null ? $val : $j; }
                    else $ctx['vars'][$name] = $val;
                }
                return ['port' => 'out'];

            /* ---- کنش‌ها ---- */
            case 'act.send':
                if (empty($ctx['channel_uid'])) return ['port' => 'error', 'error' => 'این مخاطب کانال پیام‌رسانی برای پاسخ ندارد'];
                $ok = self::sendTo($ctx, self::buildMessage($d, $ctx));
                return $ok ? ['port' => 'out'] : ['port' => 'error', 'error' => 'ارسال پیام ناموفق بود'];

            case 'act.send_phone':
                $phone = class_exists('Persons') ? Persons::normalizePhone(self::tpl((string)($d['phone'] ?? '{{person.phone}}'), $ctx)) : null;
                if (!$phone) return ['port' => 'error', 'error' => 'شماره‌ی معتبری برای ارسال نیست'];
                $r = Sender::sendByPhone([$phone], ['text' => self::tpl((string)($d['text'] ?? ''), $ctx)], in_array($d['mode'] ?? '', ['safir_then_sms', 'safir_only', 'sms_only'], true) ? $d['mode'] : 'safir_then_sms', 0);
                return (($r['safir_sent'] ?? 0) + ($r['sms_sent'] ?? 0)) ? ['port' => 'out'] : ['port' => 'error', 'error' => 'ارسال با شماره ناموفق بود'];

            case 'act.tag':
                if (empty($ctx['person_id'])) return ['port' => 'out'];
                $tag = trim(self::tpl((string)($d['tag'] ?? ''), $ctx));
                if ($tag === '') return ['port' => 'out'];
                $tid = Groups::ensureTag($tag);
                if (($d['action'] ?? 'add') === 'remove') Groups::untagPerson($tid, (int)$ctx['person_id']); else Groups::tagPersons($tid, [(int)$ctx['person_id']]);
                return ['port' => 'out'];

            case 'act.group':
                if (empty($ctx['person_id']) || empty($d['group_id'])) return ['port' => 'out'];
                if (($d['action'] ?? 'add') === 'remove') Groups::removePerson((int)$d['group_id'], (int)$ctx['person_id']); else Groups::addPersons((int)$d['group_id'], [(int)$ctx['person_id']]);
                return ['port' => 'out'];

            case 'act.assign':
                if (empty($ctx['person_id'])) return ['port' => 'out'];
                self::needDesk();
                $set = [];
                if (!empty($d['assignee_id'])) $set['assignee_id'] = (int)$d['assignee_id'];
                if (!empty($d['team_id'])) $set['team_id'] = (int)$d['team_id'];
                if (!empty($d['status'])) $set['status'] = (string)$d['status'];
                if (!empty($d['priority'])) $set['priority'] = (string)$d['priority'];
                if (!empty($d['label'])) $set['label_add'] = (int)$d['label'];
                if ($set) Desk::update([(int)$ctx['person_id']], $set);
                return ['port' => 'out'];

            case 'act.note':
                $body = self::tpl((string)($d['text'] ?? ''), $ctx);
                if ($body === '') return ['port' => 'out'];
                if (!empty($d['to_conversation']) && !empty($ctx['person_id'])) { self::needDesk(); Desk::note((int)$ctx['person_id'], $body); }
                if (!empty($d['notify']) && class_exists('Notifier')) Notifier::push('flow', mb_substr($body, 0, 120), null, !empty($ctx['person_id']) ? 'index.php#inbox.v' . (int)$ctx['person_id'] : null, !empty($ctx['person_id']) ? (int)$ctx['person_id'] : null);
                return ['port' => 'out'];

            case 'act.person':
                if (empty($ctx['person_id'])) return ['port' => 'out'];
                $name = trim(self::tpl((string)($d['name'] ?? ''), $ctx));
                $note = trim(self::tpl((string)($d['notes'] ?? ''), $ctx));
                if ($name !== '') Database::run("UPDATE eb_persons SET full_name = ? WHERE id = ?", [mb_substr($name, 0, 190), (int)$ctx['person_id']]);
                if ($note !== '') Database::run("UPDATE eb_persons SET notes = CONCAT(COALESCE(notes,''), ?) WHERE id = ?", ["\n" . $note, (int)$ctx['person_id']]);
                $ph = trim(self::tpl((string)($d['phone'] ?? ''), $ctx));
                if ($ph !== '' && class_exists('Persons') && Persons::normalizePhone($ph)) { try { Persons::attachPhone((int)$ctx['person_id'], $ph); } catch (Throwable $e) {} }
                return ['port' => 'out'];

            case 'act.ai_handoff':
                if (empty($ctx['person_id']) || !class_exists('Support')) return ['port' => 'out'];
                if (($d['action'] ?? 'on') === 'off') Support::unassignAi((int)$ctx['person_id']); else Support::assignAi((int)$ctx['person_id']);
                return ['port' => 'out'];

            case 'act.http':
                return self::http($d, $ctx);

            case 'act.ai':
                if (!class_exists('Ai') || !Ai::isReady()) return ['port' => 'error', 'error' => 'هوش مصنوعی تنظیم یا فعال نشده است'];
                $pid = (int)($ctx['person_id'] ?? 0);
                $prompt = self::tpl((string)($d['prompt'] ?? ''), $ctx);
                $msgs = [['role' => 'system', 'content' => Ai::systemWithContext($pid ?: null, $ctx['text'] ?? null) . ($prompt !== '' ? "\n\n" . $prompt : '')]];
                if ($pid && !empty($d['history'])) $msgs = array_merge($msgs, Ai::contextFor($pid));
                else $msgs[] = ['role' => 'user', 'content' => (string)($ctx['text'] ?? '') ?: $prompt];
                $r = Ai::chat($msgs);
                if (empty($r['ok'])) return ['port' => 'error', 'error' => (string)($r['error'] ?? 'پاسخ هوش مصنوعی دریافت نشد')];
                $ctx['vars'][self::varName($d['save_as'] ?? 'ai')] = (string)$r['text'];
                if (!empty($d['send']) && !empty($ctx['channel_uid'])) self::sendTo($ctx, ['text' => (string)$r['text']], BotReply::SRC_AI);
                return ['port' => 'ok'];

            case 'act.flow':
                $target = (int)($d['flow_id'] ?? 0);
                if ($target && $target !== $flowId) self::start($target, ['vars' => $ctx['vars'] ?? []] + $ctx, true);
                return ['port' => 'out'];
        }
        if (strpos($node['type'], 'trigger.') === 0) return ['port' => 'out'];
        return ['port' => 'error', 'error' => 'نوع گره ناشناخته: ' . $node['type']];
    }

    private static function needDesk(): void
    {
        if (!class_exists('Auth')) require_once __DIR__ . '/../core/Auth.php';
        if (!class_exists('Desk')) require_once __DIR__ . '/Desk.php';
    }

    private static function buildMessage(array $d, array $ctx): array
    {
        $m = ['text' => self::tpl((string)($d['text'] ?? ''), $ctx)];
        $btns = [];
        foreach ((array)($d['buttons'] ?? []) as $b) {
            $t = trim(self::tpl((string)($b['text'] ?? ''), $ctx));
            if ($t === '') continue;
            $u = trim(self::tpl((string)($b['url'] ?? ''), $ctx));
            $btns[] = $u !== '' ? ['text' => $t, 'url' => $u] : ['text' => $t, 'callback' => mb_substr($t, 0, 60)];
        }
        if ($btns && class_exists('Templates')) $m['buttons'] = Templates::normalizeButtons(array_map(fn($b) => [$b], $btns));
        if (!empty($d['image']) && preg_match('~^uploads/[A-Za-z0-9_.\-]+$~', (string)$d['image'])) {
            global $config;
            $abs = rtrim($config['paths']['uploads'] ?? (__DIR__ . '/../uploads/'), '/') . '/' . basename($d['image']);
            if (is_file($abs)) { $m['photo'] = $abs; $m['media_rel'] = $d['image']; }
        }
        if (!empty($d['request_contact'])) $m['request_contact'] = true;
        return $m;
    }

    private static function sendTo(array $ctx, array $message, string $src = self::SRC): bool
    {
        $ch = (string)($ctx['channel'] ?? ''); $uid = (string)($ctx['channel_uid'] ?? '');
        if ($ch === '' || $uid === '') return false;
        if ($ch === 'webchat' && class_exists('Webchat') && method_exists('Webchat', 'reply')) {
            try { return (bool)Webchat::reply($uid, $message, $src); } catch (Throwable $e) {}
        }
        if (!class_exists('BotReply')) return false;
        return BotReply::send($ch, $uid, $message, $src);
    }

    private static function http(array $d, array &$ctx): array
    {
        $url = self::tpl((string)($d['url'] ?? ''), $ctx, true);
        if (!preg_match('~^https?://~i', $url)) return ['port' => 'error', 'error' => 'آدرس درخواست نامعتبر است'];
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        // جلوگیری از دسترسی به شبکه‌ی داخلی سرور (SSRF)
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if ($host === 'localhost' || ($ip && filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            return ['port' => 'error', 'error' => 'درخواست به آدرس‌های داخلی شبکه مجاز نیست'];
        }
        $method = in_array(strtoupper($d['method'] ?? 'GET'), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? strtoupper($d['method']) : 'GET';
        $headers = ['Accept: application/json, */*;q=0.8', 'User-Agent: Elanban-Flows/1.0'];
        foreach ((array)($d['headers'] ?? []) as $h) {
            $k = trim((string)($h['key'] ?? '')); if ($k === '' || !preg_match('/^[A-Za-z0-9-]{1,60}$/', $k)) continue;
            $headers[] = $k . ': ' . str_replace(["\r", "\n"], '', self::tpl((string)($h['value'] ?? ''), $ctx));
        }
        $body = null;
        if ($method !== 'GET' && trim((string)($d['body'] ?? '')) !== '') {
            $body = self::tpl((string)$d['body'], $ctx, false, true);
            $headers[] = 'Content-Type: ' . (($d['body_type'] ?? 'json') === 'form' ? 'application/x-www-form-urlencoded' : 'application/json');
        }
        $build = function (array $proxy) use ($url, $method, $headers, $body) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 2 * 1024 * 1024] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []) + $proxy);
            return $ch;
        };
        [$res, $code, $err] = class_exists('NetProxy') ? NetProxy::exec('flows', $build) : (function () use ($build) { $c = $build([]); $r = curl_exec($c); $o = [$r, (int)curl_getinfo($c, CURLINFO_HTTP_CODE), curl_error($c)]; curl_close($c); return $o; })();
        $json = is_string($res) ? json_decode($res, true) : null;
        $ctx['http'] = ['status' => $code, 'body' => $json !== null ? $json : mb_substr((string)$res, 0, 4000)];
        if (!empty($d['save_as'])) $ctx['vars'][self::varName($d['save_as'])] = $ctx['http']['body'];
        if ($res === false || $code === 0) return ['port' => 'error', 'error' => 'درخواست انجام نشد: ' . $err];
        return ['port' => ($code >= 200 && $code < 400) ? 'ok' : 'error', 'error' => $code >= 400 ? 'پاسخ HTTP ' . $code : null];
    }

    /* ============================ شرط‌ها و متغیرها ============================ */

    private static function evalRules(array $d, array $ctx): bool
    {
        $rules = array_values((array)($d['rules'] ?? []));
        if (!$rules) return true;
        $any = ($d['join'] ?? 'and') === 'or';
        foreach ($rules as $r) {
            $ok = self::compare(self::resolve((string)($r['var'] ?? 'text'), $ctx), (string)($r['op'] ?? 'equals'), self::tpl((string)($r['value'] ?? ''), $ctx));
            if ($any && $ok) return true;
            if (!$any && !$ok) return false;
        }
        return !$any;
    }

    public static function compare($left, string $op, string $right): bool
    {
        $norm = fn($s) => mb_strtolower(trim(strtr(is_array($s) ? json_encode($s, JSON_UNESCAPED_UNICODE) : (string)$s, ['ي' => 'ی', 'ك' => 'ک', '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'])));
        $l = $norm($left); $r = $norm($right);
        switch ($op) {
            case 'equals': return $l === $r;
            case 'not_equals': return $l !== $r;
            case 'contains': return $r !== '' && mb_strpos($l, $r) !== false;
            case 'not_contains': return $r === '' || mb_strpos($l, $r) === false;
            case 'starts': return $r !== '' && mb_strpos($l, $r) === 0;
            case 'ends': return $r !== '' && mb_substr($l, -mb_strlen($r)) === $r;
            case 'regex': return @preg_match('~' . str_replace('~', '\~', $right) . '~iu', is_array($left) ? json_encode($left) : (string)$left) === 1;
            case 'gt': return is_numeric($l) && is_numeric($r) && (float)$l > (float)$r;
            case 'gte': return is_numeric($l) && is_numeric($r) && (float)$l >= (float)$r;
            case 'lt': return is_numeric($l) && is_numeric($r) && (float)$l < (float)$r;
            case 'lte': return is_numeric($l) && is_numeric($r) && (float)$l <= (float)$r;
            case 'empty': return $l === '' || $l === '[]' || $l === 'null';
            case 'not_empty': return !($l === '' || $l === '[]' || $l === 'null');
            case 'in_list': return in_array($l, array_map($norm, preg_split('/[\r\n,،]+/u', $right)), true);
            case 'is_true': return in_array($l, ['1', 'true', 'yes', 'بله'], true);
            case 'is_false': return in_array($l, ['', '0', 'false', 'no', 'خیر'], true);
        }
        return false;
    }

    /** مقدار یک مسیر مثل person.name یا vars.x.y یا http.body.items */
    public static function resolve(string $path, array $ctx)
    {
        $path = trim($path, " {}\t");
        if ($path === '') return '';
        $live = self::liveValue($path, $ctx);
        if ($live !== null) return $live;
        $cur = $ctx;
        foreach (explode('.', $path) as $k) {
            if (is_array($cur) && array_key_exists($k, $cur)) $cur = $cur[$k];
            elseif (is_array($cur) && ctype_digit($k) && array_key_exists((int)$k, $cur)) $cur = $cur[(int)$k];
            else return '';
        }
        return $cur;
    }

    /** مقادیر محاسبه‌ای: اطلاعات مخاطب، زمان، وضعیت تیم */
    private static function liveValue(string $path, array $ctx)
    {
        static $personCache = [];
        $pid = (int)($ctx['person_id'] ?? 0);
        if (strpos($path, 'person.') === 0) {
            if (!$pid) return '';
            if (!isset($personCache[$pid])) {
                $p = Database::one("SELECT id, full_name, phone, status, notes, created_at FROM eb_persons WHERE id = ?", [$pid]) ?: [];
                $p['first_name'] = trim(explode(' ', trim((string)($p['full_name'] ?? '')))[0] ?? '');
                $p['tags'] = array_column(Database::all("SELECT t.name FROM eb_person_tags pt JOIN eb_tags t ON t.id = pt.tag_id WHERE pt.person_id = ?", [$pid]), 'name');
                $p['groups'] = array_column(Database::all("SELECT g.name FROM eb_person_groups pg JOIN eb_groups g ON g.id = pg.group_id WHERE pg.person_id = ?", [$pid]), 'name');
                $p['messages_in'] = (int)(Database::one("SELECT COUNT(*) c FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id WHERE i.person_id = ? AND m.direction='in'", [$pid])['c'] ?? 0);
                $p['is_member'] = ($p['status'] ?? '') === 'member' ? '1' : '0';
                $personCache[$pid] = $p;
            }
            $k = substr($path, 7);
            $v = $personCache[$pid][$k] ?? '';
            return is_array($v) ? implode('، ', $v) : $v;
        }
        if (strpos($path, 'time.') === 0) {
            $now = new DateTime('now', Tz::zone());
            switch (substr($path, 5)) {
                case 'hour': return (int)$now->format('G');
                case 'minute': return (int)$now->format('i');
                case 'weekday': return [6 => 0, 0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6][(int)$now->format('w')]; // ۰=شنبه
                case 'date': return $now->format('Y-m-d');
                case 'time': return $now->format('H:i');
            }
        }
        if ($path === 'support.online') return class_exists('Support') ? ((Support::status()['online'] ?? true) ? '1' : '0') : '1';
        return null;
    }

    /** جایگذاری {{مسیر}} در متن */
    public static function tpl(string $s, array $ctx, bool $urlEncode = false, bool $jsonSafe = false): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/u', function ($m) use ($ctx, $urlEncode, $jsonSafe) {
            $v = self::resolve($m[1], $ctx);
            if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            $v = (string)$v;
            if ($urlEncode) return rawurlencode($v);
            if ($jsonSafe) return substr(json_encode($v, JSON_UNESCAPED_UNICODE), 1, -1);
            return $v;
        }, $s);
    }

    private static function varName($n): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', (string)$n);
    }

    private static function loopList(array $d, array $ctx): array
    {
        $mode = $d['mode'] ?? 'var';
        if ($mode === 'count') return range(1, max(1, min(self::MAX_LOOP, (int)($d['count'] ?? 3))));
        if ($mode === 'split') return array_values(array_filter(array_map('trim', explode((string)($d['sep'] ?? ',') ?: ',', self::tpl((string)($d['text'] ?? ''), $ctx))), fn($x) => $x !== ''));
        $v = self::resolve((string)($d['var'] ?? ''), $ctx);
        if (is_array($v)) return array_values($v);
        $j = json_decode((string)$v, true);
        return is_array($j) ? array_values($j) : ($v === '' ? [] : [$v]);
    }

    private static function validateReply(string $expect, string $text): bool
    {
        $t = trim(strtr($text, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
        switch ($expect) {
            case 'number': return is_numeric(str_replace([',', '٬'], '', $t));
            case 'phone': return class_exists('Persons') ? (bool)Persons::normalizePhone($t) : (bool)preg_match('/^(\+?98|0)?9\d{9}$/', preg_replace('/\D+/', '', $t));
            case 'email': return (bool)filter_var($t, FILTER_VALIDATE_EMAIL);
            case 'yesno': return in_array(mb_strtolower($t), ['بله', 'آره', 'خیر', 'نه', 'yes', 'no', 'y', 'n'], true);
        }
        return $t !== '';
    }

    private static function normalizeReply(string $expect, string $text)
    {
        $t = trim(strtr($text, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
        if ($expect === 'number') return (float)str_replace([',', '٬'], '', $t);
        if ($expect === 'phone' && class_exists('Persons')) return Persons::normalizePhone($t) ?: $t;
        if ($expect === 'yesno') return in_array(mb_strtolower($t), ['بله', 'آره', 'yes', 'y'], true) ? 'yes' : 'no';
        return $t;
    }

    /* ============================ cron ============================ */

    /** ادامه‌ی اجراهای منتظر و اجرای سناریوهای زمان‌بندی‌شده */
    public static function tick(): int
    {
        self::boot();
        $n = 0;
        $due = Database::all("SELECT id, current_node, wait_for FROM eb_flow_runs WHERE status = 'waiting' AND resume_at IS NOT NULL AND resume_at <= NOW() ORDER BY resume_at LIMIT 100");
        foreach ($due as $r) {
            $claim = Database::run("UPDATE eb_flow_runs SET status = 'running', resume_at = NULL, wait_for = NULL WHERE id = ? AND status = 'waiting'", [$r['id']]);
            if (!$claim || $claim->rowCount() !== 1) continue;
            self::continueFrom((int)$r['id'], (string)$r['current_node'], $r['wait_for'] === 'reply' ? 'timeout' : 'out');
            $n++;
        }
        // اجراهایی که بیش از ۱۰ دقیقه «در حال اجرا» مانده‌اند (قطع ناگهانی درخواست)
        Database::run("UPDATE eb_flow_runs SET status = 'failed', error = 'اجرا نیمه‌کاره ماند' WHERE status = 'running' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");

        $flows = Database::all("SELECT id, trigger_json FROM eb_flows WHERE is_active = 1 AND trigger_type = 'schedule' AND next_run_at IS NOT NULL AND next_run_at <= NOW()");
        foreach ($flows as $f) {
            $t = json_decode((string)$f['trigger_json'], true) ?: [];
            $next = self::nextScheduleRun($t, Database::one("SELECT NOW() n")['n']);
            $claim = Database::run("UPDATE eb_flows SET next_run_at = ? WHERE id = ? AND next_run_at <= NOW()", [$next, $f['id']]);
            if (!$claim || $claim->rowCount() !== 1) continue;
            $pids = [];
            if (($t['audience'] ?? 'none') !== 'none' && class_exists('Sender')) {
                $pids = array_slice(Sender::resolveAudience(['group_id' => !empty($t['group_id']) ? (int)$t['group_id'] : null, 'tag_id' => !empty($t['tag_id']) ? (int)$t['tag_id'] : null]), 0, max(1, min(2000, (int)($t['limit'] ?? 500))));
                foreach ($pids as $pid) { self::start((int)$f['id'], self::baseCtx((int)$pid, '', '', '', 'schedule')); $n++; }
            } else {
                self::start((int)$f['id'], self::baseCtx(0, '', '', '', 'schedule')); $n++;
            }
        }
        // پاک‌سازی تاریخچه‌ی قدیمی اجراها
        if (mt_rand(1, 60) === 1) Database::run("DELETE FROM eb_flow_runs WHERE status IN ('done','failed') AND updated_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
        return $n;
    }

    /** زمان اجرای بعدی (به وقت دیتابیس). every: minutes | hourly | daily | weekly */
    public static function nextScheduleRun(array $t, ?string $afterDb): ?string
    {
        if (!class_exists('Tz')) require_once __DIR__ . '/../core/Tz.php';
        $zone = Tz::zone();
        $nowLocal = new DateTime(Tz::toLocal($afterDb ?: Database::one("SELECT NOW() n")['n']), $zone);
        $every = $t['every'] ?? 'daily';
        if ($every === 'minutes') {
            $m = max(5, min(1440, (int)($t['minutes'] ?? 60)));
            $nx = (clone $nowLocal)->modify('+' . $m . ' minutes');
            return Tz::toDb($nx->format('Y-m-d H:i:00'));
        }
        if ($every === 'hourly') {
            $nx = (clone $nowLocal)->setTime((int)$nowLocal->format('G'), (int)($t['minute'] ?? 0));
            if ($nx <= $nowLocal) $nx->modify('+1 hour');
            return Tz::toDb($nx->format('Y-m-d H:i:00'));
        }
        [$h, $mi] = preg_match('/^(\d{1,2}):(\d{2})$/', (string)($t['time'] ?? '09:00'), $mm) ? [(int)$mm[1], (int)$mm[2]] : [9, 0];
        $days = array_map('intval', (array)($t['weekdays'] ?? [0, 1, 2, 3, 4, 5, 6]));   // ۰=شنبه
        for ($i = 0; $i <= 8; $i++) {
            $c = (clone $nowLocal)->modify('+' . $i . ' day')->setTime($h, $mi);
            if ($c <= $nowLocal) continue;
            $wd = [6 => 0, 0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6][(int)$c->format('w')];
            if ($every === 'weekly' && !in_array($wd, $days, true)) continue;
            if ($every === 'daily' && !empty($t['weekdays']) && !in_array($wd, $days, true)) continue;
            return Tz::toDb($c->format('Y-m-d H:i:00'));
        }
        return null;
    }

    /* ============================ گزارش ============================ */

    public static function runs(int $flowId, int $limit = 50): array
    {
        self::boot();
        $rows = Database::all("SELECT r.id, r.status, r.person_id, r.channel, r.trigger_type, r.current_node, r.resume_at, r.wait_for, r.steps, r.error, r.created_at, r.updated_at, p.full_name
                               FROM eb_flow_runs r LEFT JOIN eb_persons p ON p.id = r.person_id WHERE r.flow_id = ? ORDER BY r.id DESC LIMIT " . max(1, min(200, $limit)), [$flowId]);
        foreach ($rows as &$r) { $r['created_local'] = Tz::toLocal($r['created_at']); $r['resume_local'] = Tz::toLocal($r['resume_at']); }
        unset($r);
        $stats = Database::one("SELECT COUNT(*) total, SUM(status='done') done, SUM(status='failed') failed, SUM(status='waiting') waiting, SUM(status='running') running FROM eb_flow_runs WHERE flow_id = ?", [$flowId]) ?: [];
        return ['runs' => $rows, 'stats' => array_map('intval', $stats)];
    }

    public static function run(int $runId): ?array
    {
        self::boot();
        $r = Database::one("SELECT * FROM eb_flow_runs WHERE id = ?", [$runId]);
        if (!$r) return null;
        $r['ctx'] = json_decode((string)$r['ctx_json'], true) ?: [];
        $r['log'] = json_decode((string)$r['log_json'], true) ?: [];
        unset($r['ctx_json'], $r['log_json'], $r['stack_json']);
        return $r;
    }

    public static function cancelRun(int $runId): void
    {
        Database::run("UPDATE eb_flow_runs SET status = 'failed', error = 'به‌صورت دستی متوقف شد', resume_at = NULL, wait_for = NULL WHERE id = ? AND status IN ('waiting','running')", [$runId]);
    }
}
