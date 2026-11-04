<?php
/**
 * core/Idempotency.php — stable Idempotency-Key values for provider calls (2.1.5, risk R-04).
 *
 * YarAPI and RoboClick both require an Idempotency-Key on mutating requests and say it
 * must be derived from business data, not random — otherwise a retry sends twice.
 * Before 2.1.5 every key was random, so retried webhook events, re-queued queue rows
 * and re-claimed scheduled posts could message a customer (or publish) twice.
 *
 * Usage: code that has a business identity wraps its work in a scope:
 *     Idempotency::run('evt-' . $eventId, function () { ...provider calls... });
 * Inside the scope, key($path, $body) is deterministic: scope + call path + recipient
 * identifiers + the call's sequence number for that path/recipient. Re-running the same
 * work reproduces the same keys, so the provider can de-duplicate.
 * Message text is deliberately NOT part of the key: an AI-generated reply may differ on
 * retry and must still be recognised as the same send.
 * Outside any scope key() returns a random value — identical to pre-2.1.5 behaviour.
 *
 * Provider behaviour when a key is reused with a different body is not documented by
 * either provider; the worst case is an error instead of a duplicate message.
 */
final class Idempotency
{
    /** @var array<int, array{base:string, seq:array<string,int>}> */
    private static array $stack = [];

    /** Body fields that identify *who/what* a call targets (never content). */
    private const IDENTITY_FIELDS = ['igAccountId', 'contactId', 'commentId', 'postId', 'recipient', 'chat_id', 'to'];

    public static function begin(string $base): void
    {
        $base = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $base);
        self::$stack[] = ['base' => substr($base, 0, 40), 'seq' => []];
    }

    public static function end(): void
    {
        array_pop(self::$stack);
    }

    /** Run $fn inside a scope; the scope is always closed, even on exceptions. */
    public static function run(string $base, callable $fn)
    {
        self::begin($base);
        try { return $fn(); } finally { self::end(); }
    }

    public static function active(): bool
    {
        return self::$stack !== [];
    }

    /** Key for one provider call. ≤ 60 chars, [A-Za-z0-9_.-] only. */
    public static function key(string $path, array $body = []): string
    {
        if (!self::$stack) return 'eb-' . bin2hex(random_bytes(10));
        $i = count(self::$stack) - 1;
        $ident = [];
        foreach (self::IDENTITY_FIELDS as $f) {
            if (isset($body[$f]) && is_scalar($body[$f])) $ident[$f] = (string)$body[$f];
        }
        $slot = strtolower(trim(strtok($path, '?'), '/')) . '|' . json_encode($ident);
        $n = self::$stack[$i]['seq'][$slot] = (self::$stack[$i]['seq'][$slot] ?? 0) + 1;
        return self::$stack[$i]['base'] . '-' . substr(hash('sha256', $slot . '|' . $n), 0, 16);
    }

    /** Test helper: drop all scopes. */
    public static function reset(): void
    {
        self::$stack = [];
    }
}
