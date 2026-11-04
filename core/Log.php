<?php
/**
 * core/Log.php — structured JSON-line logging with request ids and redaction (2.2.0).
 * File: logs/app-YYYY-MM-DD.log (logs/ is web-denied). Never logs secrets, message bodies or full phones.
 */
final class Log
{
    private static ?string $rid = null;
    /** keys whose values are always replaced */
    private const SECRET_KEYS = '/(pass(word)?|secret|token|api[_-]?key|authorization|cookie|signature|app_key|otp|code_verifier)/i';
    /** keys holding message content */
    private const BODY_KEYS = '/^(body|text|message|content|caption|raw|payload)$/i';

    /** Request id: accepted from a well-formed incoming X-Request-Id, else generated. */
    public static function requestId(): string
    {
        if (self::$rid !== null) return self::$rid;
        $in = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
        return self::$rid = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $in) ? $in : Ulid::generate();
    }

    public static function setRequestId(?string $id): void { self::$rid = $id; }

    public static function info(string $event, array $ctx = []): void { self::write('info', $event, $ctx); }
    public static function warn(string $event, array $ctx = []): void { self::write('warn', $event, $ctx); }
    public static function error(string $event, array $ctx = []): void { self::write('error', $event, $ctx); }

    /** Exposed for tests. */
    public static function redact($v, string $key = '')
    {
        if ($key !== '' && preg_match(self::SECRET_KEYS, $key)) return '[redacted]';
        if ($key !== '' && preg_match(self::BODY_KEYS, $key) && (is_string($v) || is_array($v))) return '[body ' . (is_string($v) ? mb_strlen($v) . ' chars' : 'omitted') . ']';
        if (is_array($v)) { $o = []; foreach ($v as $k => $x) $o[$k] = self::redact($x, (string)$k); return $o; }
        if (is_string($v)) {
            // phone numbers: keep the last 4 digits only
            $v = preg_replace_callback('/(?<!\d)(\+?98|0098|0)?9\d{9}(?!\d)/', fn($m) => '…' . substr($m[0], -4), $v);
            $v = preg_replace('/\b(ak|rk|wh)_(live_)?[A-Za-z0-9]{6,}\b/', '$1_[redacted]', $v);
            return mb_substr($v, 0, 2000);
        }
        return $v;
    }

    private static function write(string $level, string $event, array $ctx): void
    {
        $line = json_encode([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => $level, 'event' => $event, 'request_id' => self::requestId(),
            'user_id' => class_exists('Auth', false) && session_status() !== PHP_SESSION_NONE ? (Auth::id() ?? null) : null,
            'ctx' => self::redact($ctx),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $dir = dirname(__DIR__) . '/logs';
        if (!@file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX)) error_log('[elanban] ' . $line);
    }
}
