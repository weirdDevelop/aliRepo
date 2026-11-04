<?php
/**
 * modules/Stats.php
 * آمار دقیق برای مارکتینگ.
 */

class Stats
{
    public static function overview(): array
    {
        $persons   = (int)(Database::one("SELECT COUNT(*) c FROM eb_persons")['c'] ?? 0);
        $members   = (int)(Database::one("SELECT COUNT(*) c FROM eb_persons WHERE status='member'")['c'] ?? 0);
        $sent      = (int)(Database::one("SELECT COUNT(*) c FROM eb_messages WHERE direction='out' AND status='sent'")['c'] ?? 0);
        $failed    = (int)(Database::one("SELECT COUNT(*) c FROM eb_messages WHERE direction='out' AND status='failed'")['c'] ?? 0);
        $received  = (int)(Database::one("SELECT COUNT(*) c FROM eb_messages WHERE direction='in'")['c'] ?? 0);

        // تفکیک هویت بر اساس کانال
        $byChannel = Database::all(
            "SELECT channel, COUNT(*) c FROM eb_identities GROUP BY channel ORDER BY c DESC"
        );

        // پیام در ۷ روز اخیر (برای نمودار)
        $daily = Database::all(
            "SELECT DATE(created_at) d, 
                    SUM(direction='out') sent, SUM(direction='in') received
             FROM eb_messages
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at) ORDER BY d"
        );

        // نرخ پاسخ‌دهی: چند درصد از اشخاص حداقل یک پیام دریافتی فرستاده‌اند
        $responders = (int)(Database::one(
            "SELECT COUNT(DISTINCT i.person_id) c FROM eb_messages m
             INNER JOIN eb_identities i ON i.id=m.identity_id
             WHERE m.direction='in'"
        )['c'] ?? 0);
        $responseRate = $persons > 0 ? round($responders / $persons * 100) : 0;

        // رشد اعضا در ۳۰ روز اخیر
        $newMembers = (int)(Database::one(
            "SELECT COUNT(*) c FROM eb_persons WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )['c'] ?? 0);

        return [
            'persons'       => $persons,
            'members'       => $members,
            'anonymous'     => $persons - $members,
            'sent'          => $sent,
            'failed'        => $failed,
            'received'      => $received,
            'delivery_rate' => ($sent + $failed) > 0 ? round($sent / ($sent + $failed) * 100) : 0,
            'response_rate' => $responseRate,
            'new_30d'       => $newMembers,
            'by_channel'    => $byChannel,
            'daily'         => $daily,
        ];
    }
}
