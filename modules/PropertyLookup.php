<?php
/**
 * modules/PropertyLookup.php
 * لوکاپ خودکار اقامتگاه — قسمتی از سناریوی فلوچارت میهمان/میزبان که قبلاً به‌عنوان
 * «فعلاً وصل به پشتیبان» جا گذاشته بودیم. حالا وصل شد:
 *   کاربر روی دکمه‌ای مثل «قیمت» می‌زند → گره‌ی مقصد (awaits_property_field='price')
 *   فرستاده می‌شود و یک حالت «منتظر اسم اقامتگاه» برای همان کاربر ثبت می‌شود → کاربر اسم
 *   اقامتگاه را می‌فرستد → همین‌جا جواب (فیلد موردنظر) از eb_properties داده می‌شود.
 *
 * کاملاً مستقل از کانال است (همان الگوی IgPhoneGate/IgCommentAutomation) — چون گره‌های
 * بات‌ساز خودشان چندکاناله‌اند، این هم هست؛ برای همین کانالی مثل تلگرام/بله هم اگر همین
 * سناریو را با همان awaits_property_field بسازند، کار می‌کند، نه فقط چت سایت.
 */

class PropertyLookup
{
    const FIELD_LABELS = [
        'price'            => 'قیمت',
        'checkin_time'     => 'ساعت ورود',
        'checkout_time'    => 'ساعت خروج',
        'min_advance_days' => 'حداقل روزهای لازم برای رزرو از قبل',
    ];

    // ==================== CRUD اقامتگاه‌ها ====================

    public static function all(bool $onlyActive = false): array
    {
        $sql = "SELECT * FROM eb_properties";
        if ($onlyActive) $sql .= " WHERE is_active = 1";
        $sql .= " ORDER BY name ASC";
        return Database::all($sql);
    }

    public static function save(array $p): int
    {
        $id = (int)($p['id'] ?? 0);
        $vals = [
            trim((string)($p['name'] ?? '')),
            trim((string)($p['price'] ?? '')) ?: null,
            trim((string)($p['checkin_time'] ?? '')) ?: null,
            trim((string)($p['checkout_time'] ?? '')) ?: null,
            trim((string)($p['min_advance_days'] ?? '')) ?: null,
            trim((string)($p['notes'] ?? '')) ?: null,
            !empty($p['is_active']) ? 1 : 0,
        ];
        if ($id > 0) {
            Database::run(
                "UPDATE eb_properties SET name=?, price=?, checkin_time=?, checkout_time=?, min_advance_days=?, notes=?, is_active=? WHERE id=?",
                array_merge($vals, [$id])
            );
            return $id;
        }
        Database::run(
            "INSERT INTO eb_properties (name, price, checkin_time, checkout_time, min_advance_days, notes, is_active) VALUES (?,?,?,?,?,?,?)",
            $vals
        );
        return (int)Database::lastId();
    }

    public static function delete(int $id): void
    {
        Database::run("DELETE FROM eb_properties WHERE id = ?", [$id]);
    }

    // ==================== حالت «منتظر اسم اقامتگاه» ====================

    public static function setPending(string $channel, string $channelUid, string $field): void
    {
        if (!array_key_exists($field, self::FIELD_LABELS)) return;
        Database::run(
            "INSERT INTO eb_property_lookup_pending (channel, channel_uid, field) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE field = VALUES(field), created_at = NOW()",
            [$channel, $channelUid, $field]
        );
    }

    private static function getPending(string $channel, string $channelUid): ?array
    {
        return Database::one(
            "SELECT * FROM eb_property_lookup_pending WHERE channel = ? AND channel_uid = ?",
            [$channel, $channelUid]
        );
    }

    private static function clearPending(string $channel, string $channelUid): void
    {
        Database::run("DELETE FROM eb_property_lookup_pending WHERE channel = ? AND channel_uid = ?", [$channel, $channelUid]);
    }

    /**
     * برای هر پیام dm/webchat، قبل از فرم/منو/AI صدا زده می‌شود.
     * @return bool آیا این پیام به‌عنوان «اسم اقامتگاه» مصرف شد؟
     */
    public static function handleIncoming(string $channel, string $channelUid, string $text): bool
    {
        $pending = self::getPending($channel, $channelUid);
        if (!$pending) return false;

        $driver = class_exists('ChannelManager') ? ChannelManager::get($channel) : null;
        if (!$driver) { self::clearPending($channel, $channelUid); return false; }

        $name = trim($text);
        $field = $pending['field'];
        $fieldLabel = self::FIELD_LABELS[$field] ?? $field;

        $matches = Database::all(
            "SELECT * FROM eb_properties WHERE is_active = 1 AND name LIKE ? LIMIT 6",
            ['%' . $name . '%']
        );

        if (count($matches) === 1) {
            $value = trim((string)($matches[0][$field] ?? ''));
            $reply = $value !== ''
                ? "«{$matches[0]['name']}» — {$fieldLabel}: {$value}"
                : "برای «{$matches[0]['name']}» هنوز {$fieldLabel} ثبت نشده — یکی از همکاران بهتون می‌گه.";
            self::clearPending($channel, $channelUid);
        } elseif (count($matches) > 1) {
            $names = implode('، ', array_column($matches, 'name'));
            $reply = "چند تا اقامتگاه با این اسم پیدا کردم: {$names}\nلطفاً دقیق‌تر اسم رو بفرست.";
            // pending را عمداً پاک نمی‌کنیم — کاربر دوباره تلاش می‌کند
        } else {
            $reply = "اقامتگاهی با این اسم پیدا نکردم 🙁 لطفاً اسم دقیق‌ترش رو بفرست، یا بنویس «پشتیبان» تا وصلتون کنم.";
            // pending می‌ماند تا یک بار دیگر امتحان کند
        }

        BotReply::send($channel, $channelUid, ['text' => $reply], BotReply::SRC_BOTMENU);
        return true;
    }
}
