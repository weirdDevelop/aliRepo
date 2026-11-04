<?php
/**
 * crm/api_integration/TelefonchyWebhookAuth.php — authentication of Telefonchy call webhooks (2.1.5, risk R-03).
 *
 * Telefonchy puts `webservice_token` inside every event payload (root, or `data` in the
 * documented envelope). Before 2.1.5 a mismatch was only logged and events WITHOUT a token,
 * or arriving when no token was configured, were accepted — anyone knowing the URL could
 * inject calls and leads.
 *
 * Accepted tokens: the optional dedicated webhook token (setting `telefonchy_webhook_token`)
 * and the API token (`telefonchy_token`). The dedicated setting exists because earlier live
 * tests saw real events whose token did not equal the API token; if that recurs, the owner
 * can paste the token Telefonchy actually sends without weakening the check.
 *
 * Modes (setting `telefonchy_webhook_auth`): 'enforce' (default) rejects; 'monitor' keeps the
 * pre-2.1.5 behaviour (process + log). Rejected genuine events are not lost: cron_sync.php
 * pulls the same calls from the /calls API on its next run.
 * Pure: no DB, no I/O.
 */
final class TelefonchyWebhookAuth
{
    const OK = 'ok';
    const NO_TOKEN_CONFIGURED = 'no_token_configured';
    const MISSING_TOKEN = 'missing_token';
    const MISMATCH = 'mismatch';

    /** Token carried by an event, wherever Telefonchy put it. */
    public static function receivedToken(array $payload): ?string
    {
        foreach ([$payload['webservice_token'] ?? null, $payload['data']['webservice_token'] ?? null] as $t) {
            if (is_scalar($t) && trim((string)$t) !== '') return trim((string)$t);
        }
        return null;
    }

    /** @param array<int,?string> $acceptedTokens */
    public static function check(array $payload, array $acceptedTokens): string
    {
        $accepted = array_values(array_filter(array_map(function ($t) {
            return is_string($t) ? trim($t) : '';
        }, $acceptedTokens), 'strlen'));
        if (!$accepted) return self::NO_TOKEN_CONFIGURED;
        $got = self::receivedToken($payload);
        if ($got === null) return self::MISSING_TOKEN;
        $match = false;
        foreach ($accepted as $t) $match = hash_equals($t, $got) || $match;   // no early exit: constant work
        return $match ? self::OK : self::MISMATCH;
    }

    public static function mode(?string $setting): string
    {
        return strtolower(trim((string)$setting)) === 'monitor' ? 'monitor' : 'enforce';
    }

    /** A heartbeat / empty POST that carries nothing to process. */
    public static function isEmpty(array $payload): bool
    {
        return $payload === [];
    }
}
