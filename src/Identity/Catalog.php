<?php
namespace Eb\Identity;

/**
 * Permission catalogue (2.3.0). Labels mirror the existing operator editor (assets/p-settings.js PERMS).
 * admin_only keys can never be placed in a role (they stay reserved to administrators, as before 2.3.0).
 * Later contexts add their keys here (e.g. Work in 2.5.0: work.task.*).
 */
final class Catalog
{
    /** @return array<int, array{key:string,label:string,group:string,admin_only:bool}> */
    public static function all(): array
    {
        $groups = [
            'گفتگو' => ['dashboard' => 'داشبورد', 'inbox' => 'صندوق ورودی', 'instagram' => 'اینستاگرام', 'webchat' => 'گفتگوی سایت', 'support' => 'پشتیبانی تیمی'],
            'ارسال' => ['send' => 'ارسال پیام', 'safir' => 'ارسال با شماره', 'campaigns' => 'کمپین', 'scheduled' => 'زمان‌بندی', 'queue' => 'صف ارسال', 'sendlog' => 'تاریخچه‌ی ارسال'],
            'مشتریان' => ['persons' => 'بانک مشتری', 'groups' => 'گروه و برچسب'],
            'اتوماسیون' => ['botbuilder' => 'بات‌ساز و سناریوها', 'autoreply' => 'پاسخ خودکار', 'forms' => 'فرم و نظرسنجی', 'callbacks' => 'درخواست تماس‌های دستیار فروش', 'ai' => 'هوش مصنوعی'],
            'محتوا و تحلیل' => ['templates' => 'الگوها', 'links' => 'لینک رهگیری', 'notices' => 'اعلان سایت', 'stats' => 'آمار', 'analytics' => 'آنالیتیکس', 'marketing' => 'مارکتینگ', 'apilog' => 'لاگ سیستم'],
            'سیستم' => ['settings' => 'تنظیمات', 'channels' => 'کانال‌ها', 'botsettings' => 'تنظیمات ربات'],
            'CRM' => ['crm_calls' => 'تماس‌ها و پیگیری شماره', 'crm_leads' => 'سرنخ‌ها و استعلام‌ها', 'crm_customers' => 'مشتریان CRM', 'crm_hosts' => 'میزبانان',
                      'crm_host_leads' => 'جذب میزبان', 'crm_tickets' => 'تیکت‌ها', 'crm_tasks' => 'تسک‌ها و پروژه‌ها', 'crm_chat' => 'چت داخلی',
                      'crm_staff' => 'پرسنل', 'crm_leaves' => 'مرخصی‌ها', 'crm_payroll' => 'حقوق و دستمزد', 'crm_reports' => 'گزارش‌ها', 'crm_settings' => 'تنظیمات CRM'],
        ];
        $out = [];
        foreach ($groups as $g => $pages) {
            foreach ($pages as $page => $label) {
                $out[] = ['key' => \Permissions::pageKey($page), 'label' => $label, 'group' => $g,
                          'admin_only' => in_array($page, \Permissions::ADMIN_ONLY, true)];
            }
        }
        $out[] = ['key' => '*.page.view', 'label' => 'همه‌ی بخش‌های غیرمدیریتی', 'group' => 'گروهی', 'admin_only' => false];
        $out[] = ['key' => 'customer.person.delete', 'label' => 'حذف و مدیریت مخاطبان', 'group' => 'مشتریان', 'admin_only' => false];
        $out[] = ['key' => 'identity.role.manage', 'label' => 'مدیریت نقش‌های دسترسی', 'group' => 'سیستم', 'admin_only' => true];
        return $out;
    }

    /** @return array<string, array> key => entry */
    public static function byKey(): array
    {
        $m = [];
        foreach (self::all() as $e) $m[$e['key']] = $e;
        return $m;
    }

    /** Keys that may be placed in roles. */
    public static function grantable(string $key): bool
    {
        $e = self::byKey()[$key] ?? null;
        return $e !== null && !$e['admin_only'];
    }
}
