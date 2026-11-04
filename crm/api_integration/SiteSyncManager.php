<?php
/**
 * همگام‌سازی داده از دیتابیس سایت جاکجاست به CRM
 *
 * تنظیمات (جدول settings):
 *   site_db_host, site_db_port, site_db_name, site_db_user, site_db_pass
 *   site_table_customers, site_table_hosts, site_table_properties,
 *   site_table_inquiries, site_table_sales
 *
 * نام ستون‌های رایج به‌صورت خودکار حدس زده می‌شوند؛ در صورت ساختار متفاوت
 * می‌توانید با تنظیمات پیشرفته (JSON) نگاشت را override کنید.
 */

class SiteSyncManager
{
    /** @var PDO|null */
    private $pdo = null;
    private $lastError = null;

    public function isConfigured()
    {
        return Report::getSetting('site_db_name') && Report::getSetting('site_db_user');
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function connect()
    {
        if ($this->pdo) return $this->pdo;
        if (!$this->isConfigured()) {
            $this->lastError = 'اتصال سایت پیکربندی نشده است';
            return null;
        }
        try {
            $host = Report::getSetting('site_db_host', 'localhost');
            $port = Report::getSetting('site_db_port', '3306');
            $name = Report::getSetting('site_db_name');
            $user = Report::getSetting('site_db_user');
            $pass = Report::getSetting('site_db_pass', '');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return $this->pdo;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            Logger::error('SiteSync connect failed: ' . $e->getMessage());
            return null;
        }
    }

    public function testConnection()
    {
        $pdo = $this->connect();
        if (!$pdo) {
            return ['ok' => false, 'message' => $this->lastError ?: 'اتصال ناموفق'];
        }
        try {
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            return [
                'ok' => true,
                'message' => 'اتصال موفق — ' . count($tables) . ' جدول یافت شد',
                'tables' => $tables,
            ];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * همگام‌سازی کامل (یا بخشی)
     * @param array $modules customers|hosts|properties|inquiries|sales
     */
    public function sync(array $modules = ['customers', 'hosts', 'properties', 'inquiries', 'sales'])
    {
        $pdo = $this->connect();
        if (!$pdo) {
            return ['success' => false, 'message' => $this->lastError ?: 'اتصال سایت برقرار نیست', 'stats' => []];
        }

        $stats = [];
        foreach ($modules as $mod) {
            try {
                if ($mod === 'customers') $stats['customers'] = $this->syncCustomers($pdo);
                elseif ($mod === 'hosts') $stats['hosts'] = $this->syncHosts($pdo);
                elseif ($mod === 'properties') $stats['properties'] = $this->syncProperties($pdo);
                elseif ($mod === 'inquiries') $stats['inquiries'] = $this->syncInquiries($pdo);
                elseif ($mod === 'sales') $stats['sales'] = $this->syncSales($pdo);
            } catch (Exception $e) {
                $stats[$mod] = ['error' => $e->getMessage(), 'created' => 0, 'updated' => 0];
                Logger::error("SiteSync {$mod}: " . $e->getMessage());
            }
        }
        Report::setSetting('site_last_sync_at', date('Y-m-d H:i:s'));
        return ['success' => true, 'message' => 'همگام‌سازی سایت انجام شد', 'stats' => $stats];
    }

    private function tableName($key, $default)
    {
        return Report::getSetting($key, $default);
    }

    private function pick($row, array $candidates, $default = null)
    {
        foreach ($candidates as $c) {
            if (array_key_exists($c, $row) && $row[$c] !== null && $row[$c] !== '') {
                return $row[$c];
            }
        }
        return $default;
    }

    private function fetchRows(PDO $pdo, $table, $limit = 5000)
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        if ($table === '') throw new Exception('نام جدول خالی است');
        $stmt = $pdo->query("SELECT * FROM `{$table}` ORDER BY 1 DESC LIMIT " . (int)$limit);
        return $stmt->fetchAll();
    }

    private function syncCustomers(PDO $pdo)
    {
        Customer::ensureYasColumns();
        $table = $this->tableName('site_table_customers', 'users');
        $rows = $this->fetchRows($pdo, $table);
        $created = 0; $updated = 0; $skipped = 0;
        $db = Database::getInstance();

        foreach ($rows as $row) {
            $mobileRaw = $this->pick($row, ['mobile', 'cellphone', 'mobile_number', 'phone', 'tel']);
            if (!$mobileRaw) { $skipped++; continue; }
            // اول موبایل سخت‌گیرانه؛ اگر ثابت بود با sanitizePhone نگه دار
            $mobile = Helpers::sanitizeMobile($mobileRaw);
            if ($mobile === false) {
                $mobile = Helpers::sanitizePhone($mobileRaw);
            }
            if ($mobile === false) { $skipped++; continue; }

            $name = $this->pick($row, ['name', 'full_name', 'fullname', 'display_name', 'first_name'], 'مهمان سایت');
            if ($this->pick($row, ['last_name', 'family'])) {
                $name = trim($name . ' ' . $this->pick($row, ['last_name', 'family']));
            }
            $email = $this->pick($row, ['email', 'mail']);
            $city = $this->pick($row, ['city', 'town']);
            $siteId = (string)$this->pick($row, ['id', 'user_id', 'uid'], '');

            $existing = $db->fetchOne('SELECT id FROM customers WHERE mobile = ? OR site_user_id = ? LIMIT 1', [$mobile, $siteId ?: '__none__']);
            $payload = [
                'name'   => $name,
                'mobile' => $mobile,
                'email'  => $email,
                'city'   => $city,
                'status' => 'active',
                'site_user_id' => $siteId ?: null,
            ];
            // فیلدهای یاس در صورت وجود در سایت
            if (($v = $this->pick($row, ['favorite_day', 'fav_day', 'popular_day'])) !== null) $payload['favorite_day'] = $v;
            if (($v = $this->pick($row, ['loyalty', 'loyalty_percent', 'loyalty_score'])) !== null) $payload['loyalty_percent'] = (float)$v;
            if (($v = $this->pick($row, ['avg_purchase', 'average_purchase', 'avg_amount'])) !== null) $payload['avg_purchase'] = (int)$v;
            if (($v = $this->pick($row, ['reservations', 'orders_count', 'purchase_count'])) !== null) $payload['reservations'] = (int)$v;
            if (($v = $this->pick($row, ['total_spend', 'total_paid', 'spent'])) !== null) $payload['total_spend'] = (int)$v;
            if (($v = $this->pick($row, ['last_purchase', 'last_order_date'])) !== null) $payload['last_purchase'] = $v;

            if ($existing) {
                unset($payload['mobile']); // موبایل کلید یکتاست
                Customer::update((int)$existing['id'], $payload);
                $updated++;
            } else {
                if (empty($payload['join_date'])) $payload['join_date'] = Jalali::today();
                Customer::create($payload);
                $created++;
            }
        }
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'scanned' => count($rows)];
    }

    private function syncHosts(PDO $pdo)
    {
        Host::migrate();
        $table = $this->tableName('site_table_hosts', 'hosts');
        $rows = $this->fetchRows($pdo, $table);
        $created = 0; $updated = 0; $skipped = 0;
        $db = Database::getInstance();

        foreach ($rows as $row) {
            $mobileRaw = $this->pick($row, ['mobile', 'cellphone', 'phone', 'tel']);
            if (!$mobileRaw) { $skipped++; continue; }
            $mobile = Helpers::sanitizeMobile($mobileRaw);
            if ($mobile === false) {
                $mobile = Helpers::sanitizePhone($mobileRaw);
            }
            if ($mobile === false) { $skipped++; continue; }

            $name = $this->pick($row, ['name', 'full_name', 'fullname', 'host_name'], 'میزبان سایت');
            $siteId = (string)$this->pick($row, ['id', 'host_id'], '');
            $existing = $db->fetchOne(
                'SELECT id FROM hosts WHERE mobile = ? OR (site_host_id IS NOT NULL AND site_host_id = ?) LIMIT 1',
                [$mobile, $siteId ?: '__none__']
            );
            $payload = [
                'name'   => $name,
                'mobile' => $mobile,
                'email'  => $this->pick($row, ['email']),
                'city'   => $this->pick($row, ['city']),
                'sheba'  => $this->pick($row, ['sheba', 'iban', 'shaba']),
                'national_code' => $this->pick($row, ['national_code', 'national_id', 'code_melli']),
                'status' => 'active',
                'site_host_id' => $siteId ?: null,
                'properties' => (int)$this->pick($row, ['properties', 'properties_count', 'listing_count'], 0),
            ];

            if ($existing) {
                unset($payload['mobile']);
                Host::update((int)$existing['id'], $payload);
                $updated++;
            } else {
                if (empty($payload['join_date'])) $payload['join_date'] = Jalali::today();
                Host::create($payload);
                $created++;
            }
        }
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'scanned' => count($rows)];
    }

    private function syncProperties(PDO $pdo)
    {
        HostProperty::migrate();
        $table = $this->tableName('site_table_properties', 'properties');
        $rows = $this->fetchRows($pdo, $table);
        $created = 0; $updated = 0; $skipped = 0;
        $db = Database::getInstance();

        foreach ($rows as $row) {
            $sitePropId = (string)$this->pick($row, ['id', 'property_id', 'listing_id'], '');
            if ($sitePropId === '') { $skipped++; continue; }

            $hostSiteId = (string)$this->pick($row, ['host_id', 'owner_id', 'user_id'], '');
            $hostMobile = $this->pick($row, ['host_mobile', 'owner_mobile']);
            $host = null;
            if ($hostSiteId !== '') {
                $host = $db->fetchOne('SELECT id FROM hosts WHERE site_host_id = ? LIMIT 1', [$hostSiteId]);
            }
            if (!$host && $hostMobile) {
                $m = Helpers::sanitizeMobile($hostMobile);
                if ($m) $host = $db->fetchOne('SELECT id FROM hosts WHERE mobile = ? LIMIT 1', [$m]);
            }
            if (!$host) { $skipped++; continue; }

            $title = $this->pick($row, ['title', 'name', 'property_title'], 'اقامتگاه');
            $existing = $db->fetchOne('SELECT id FROM host_properties WHERE site_property_id = ?', [$sitePropId]);
            $payload = [
                'title' => $title,
                'city' => $this->pick($row, ['city']),
                'address' => $this->pick($row, ['address', 'location']),
                'property_type' => $this->pick($row, ['type', 'property_type', 'category']),
                'capacity' => (int)$this->pick($row, ['capacity', 'guests', 'max_guests'], 0) ?: null,
                'base_price' => (int)$this->pick($row, ['price', 'base_price', 'nightly_price'], 0),
                'status' => 'active',
                'cover_image' => $this->pick($row, ['cover', 'cover_image', 'image', 'thumbnail']),
            ];
            if ($existing) {
                HostProperty::update((int)$existing['id'], $payload);
                $updated++;
            } else {
                HostProperty::create(array_merge($payload, [
                    'host_id' => (int)$host['id'],
                    'site_property_id' => $sitePropId,
                ]));
                $created++;
            }
        }
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'scanned' => count($rows)];
    }

    private function syncInquiries(PDO $pdo)
    {
        SiteInquiry::migrate();
        $table = $this->tableName('site_table_inquiries', 'inquiries');
        $rows = $this->fetchRows($pdo, $table);
        $created = 0; $updated = 0;
        foreach ($rows as $row) {
            $siteId = (string)$this->pick($row, ['id', 'inquiry_id'], '');
            $before = $siteId ? Database::getInstance()->fetchOne('SELECT id FROM site_inquiries WHERE site_id = ?', [$siteId]) : null;
            SiteInquiry::upsertFromSite([
                'site_id' => $siteId ?: null,
                'name' => $this->pick($row, ['name', 'full_name', 'customer_name']),
                'mobile' => $this->pick($row, ['mobile', 'phone', 'tel']),
                'email' => $this->pick($row, ['email']),
                'city' => $this->pick($row, ['city']),
                'message' => $this->pick($row, ['message', 'text', 'body', 'description']),
                'property_title' => $this->pick($row, ['property_title', 'listing_title', 'title']),
                'source' => $this->pick($row, ['source'], 'سایت'),
                'assignee' => $this->pick($row, ['assignee', 'supporter', 'agent', 'support_name']),
                'inquired_at' => $this->pick($row, ['created_at', 'inquiry_date', 'date']),
                'raw_payload' => json_encode($row, JSON_UNESCAPED_UNICODE),
            ]);
            if ($before) $updated++; else $created++;
        }
        return ['created' => $created, 'updated' => $updated, 'scanned' => count($rows)];
    }

    private function syncSales(PDO $pdo)
    {
        SiteSale::migrate();
        $table = $this->tableName('site_table_sales', 'orders');
        $rows = $this->fetchRows($pdo, $table);
        $created = 0; $updated = 0;
        foreach ($rows as $row) {
            $siteId = (string)$this->pick($row, ['id', 'order_id', 'reservation_id'], '');
            $before = $siteId ? Database::getInstance()->fetchOne('SELECT id FROM site_sales WHERE site_id = ?', [$siteId]) : null;
            SiteSale::upsertFromSite([
                'site_id' => $siteId ?: null,
                'order_code' => $this->pick($row, ['code', 'order_code', 'reservation_code', 'tracking_code']),
                'customer_name' => $this->pick($row, ['customer_name', 'guest_name', 'name']),
                'customer_mobile' => $this->pick($row, ['customer_mobile', 'guest_mobile', 'mobile', 'phone']),
                'host_name' => $this->pick($row, ['host_name', 'owner_name']),
                'property_title' => $this->pick($row, ['property_title', 'listing_title', 'title']),
                'city' => $this->pick($row, ['city']),
                'check_in' => $this->pick($row, ['check_in', 'checkin', 'from_date']),
                'check_out' => $this->pick($row, ['check_out', 'checkout', 'to_date']),
                'nights' => (int)$this->pick($row, ['nights', 'night_count'], 0) ?: null,
                'amount' => (int)$this->pick($row, ['amount', 'total', 'price', 'payable'], 0),
                'commission' => (int)$this->pick($row, ['commission', 'fee'], 0),
                'status' => $this->mapSaleStatus($this->pick($row, ['status'], 'confirmed')),
                'sold_at' => $this->pick($row, ['sold_at', 'created_at', 'paid_at', 'confirmed_at']),
                'raw_payload' => json_encode($row, JSON_UNESCAPED_UNICODE),
            ]);
            if ($before) $updated++; else $created++;
        }
        return ['created' => $created, 'updated' => $updated, 'scanned' => count($rows)];
    }

    private function mapSaleStatus($status)
    {
        $s = strtolower((string)$status);
        $map = [
            'pending' => 'pending', 'wait' => 'pending',
            'confirmed' => 'confirmed', 'paid' => 'confirmed', 'success' => 'confirmed',
            'cancelled' => 'cancelled', 'canceled' => 'cancelled',
            'completed' => 'completed', 'done' => 'completed',
            'refunded' => 'refunded',
        ];
        return $map[$s] ?? (in_array($s, SiteSale::$statuses, true) ? $s : 'confirmed');
    }
}
