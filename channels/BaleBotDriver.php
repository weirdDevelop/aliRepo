<?php
/**
 * channels/BaleBotDriver.php
 * کانال ربات بله — فقط API_BASE را تعیین می‌کند.
 * (روبیکا و تلگرام هم به همین سادگی از BaseBotDriver ساخته می‌شوند.)
 */

class BaleBotDriver extends BaseBotDriver
{
    protected string $apiBase = 'https://tapi.bale.ai';

    public function name(): string
    {
        return 'bale';
    }

    public function capabilities(): array
    {
        return ['text', 'photo', 'buttons', 'inbound', 'request_contact'];
    }
}
