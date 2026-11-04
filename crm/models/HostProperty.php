<?php
/**
 * اقامتگاه‌های متعلق به یک میزبان (CRUD + عکس)
 */

class HostProperty
{
    private static $migrated = false;

    private static $fillable = [
        'host_id', 'title', 'city', 'address', 'property_type', 'capacity',
        'base_price', 'status', 'site_property_id', 'cover_image', 'notes',
    ];

    public static $statuses = ['active', 'inactive', 'draft'];
    public static $types = ['آپارتمان', 'سوئیت', 'ویلا', 'کلبه', 'هتل‌آپارتمان', 'بوم‌گردی', 'سایر'];

    public static function migrate()
    {
        if (self::$migrated) return ['added' => [], 'error' => null];
        $added = [];
        try {
            $db = Database::getInstance();
            $exists = $db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'host_properties'"
            );
            if (!(int)$exists) {
                $db->execute("CREATE TABLE IF NOT EXISTS `host_properties` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `host_id` INT UNSIGNED NOT NULL,
                    `title` VARCHAR(200) NOT NULL,
                    `city` VARCHAR(80) DEFAULT NULL,
                    `address` VARCHAR(255) DEFAULT NULL,
                    `property_type` VARCHAR(50) DEFAULT NULL,
                    `capacity` INT UNSIGNED DEFAULT NULL,
                    `base_price` BIGINT NOT NULL DEFAULT 0,
                    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                    `site_property_id` VARCHAR(64) DEFAULT NULL,
                    `cover_image` VARCHAR(255) DEFAULT NULL,
                    `notes` TEXT DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_host` (`host_id`),
                    KEY `idx_site_property` (`site_property_id`),
                    KEY `idx_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $added[] = 'host_properties';
            }

            $imgExists = $db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'host_property_images'"
            );
            if (!(int)$imgExists) {
                $db->execute("CREATE TABLE IF NOT EXISTS `host_property_images` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `property_id` INT UNSIGNED NOT NULL,
                    `file_path` VARCHAR(255) NOT NULL,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_property` (`property_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $added[] = 'host_property_images';
            }
        } catch (Exception $e) {
            Logger::error('HostProperty::migrate failed: ' . $e->getMessage());
            self::$migrated = true;
            return ['added' => $added, 'error' => $e->getMessage()];
        }
        self::$migrated = true;
        return ['added' => $added, 'error' => null];
    }

    public static function forHost($hostId)
    {
        self::migrate();
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM host_properties WHERE host_id = ? ORDER BY id DESC',
            [(int)$hostId]
        );
        foreach ($rows as &$r) {
            $r['images'] = self::getImages((int)$r['id']);
        }
        return $rows;
    }

    public static function find($id)
    {
        self::migrate();
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM host_properties WHERE id = ? LIMIT 1',
            [(int)$id]
        );
        if (!$row) return null;
        $row['images'] = self::getImages((int)$id);
        return $row;
    }

    public static function getImages($propertyId)
    {
        self::migrate();
        return Database::getInstance()->fetchAll(
            'SELECT * FROM host_property_images WHERE property_id = ? ORDER BY sort_order ASC, id ASC',
            [(int)$propertyId]
        );
    }

    public static function create(array $data)
    {
        self::migrate();
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }
        if (empty($filtered['host_id']) || empty($filtered['title'])) {
            Response::validationError('میزبان و عنوان اقامتگاه الزامی است');
        }
        if (empty($filtered['status'])) $filtered['status'] = 'active';
        $id = Database::getInstance()->insert('host_properties', $filtered);
        self::refreshHostCount((int)$filtered['host_id']);
        return $id;
    }

    public static function update($id, array $data)
    {
        self::migrate();
        $existing = self::find($id);
        if (!$existing) Response::notFound('اقامتگاه یافت نشد');
        $filtered = [];
        foreach (self::$fillable as $f) {
            if ($f === 'host_id') continue;
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }
        if (empty($filtered)) return 0;
        $n = Database::getInstance()->update('host_properties', $filtered, 'id = ?', [(int)$id]);
        self::refreshHostCount((int)$existing['host_id']);
        return $n;
    }

    public static function delete($id)
    {
        self::migrate();
        $existing = self::find($id);
        if (!$existing) Response::notFound('اقامتگاه یافت نشد');
        $db = Database::getInstance();
        foreach ($existing['images'] as $img) {
            self::deleteImageFile($img['file_path'] ?? '');
        }
        $db->execute('DELETE FROM host_property_images WHERE property_id = ?', [(int)$id]);
        if (!empty($existing['cover_image'])) {
            self::deleteImageFile($existing['cover_image']);
        }
        $db->execute('DELETE FROM host_properties WHERE id = ?', [(int)$id]);
        self::refreshHostCount((int)$existing['host_id']);
        return true;
    }

    public static function addImage($propertyId, $relativePath)
    {
        self::migrate();
        $prop = self::find($propertyId);
        if (!$prop) Response::notFound('اقامتگاه یافت نشد');
        $id = Database::getInstance()->insert('host_property_images', [
            'property_id' => (int)$propertyId,
            'file_path'   => $relativePath,
            'sort_order'  => count($prop['images']),
        ]);
        if (empty($prop['cover_image'])) {
            Database::getInstance()->update('host_properties', ['cover_image' => $relativePath], 'id = ?', [(int)$propertyId]);
        }
        return $id;
    }

    public static function deleteImage($imageId)
    {
        self::migrate();
        $db = Database::getInstance();
        $img = $db->fetchOne('SELECT * FROM host_property_images WHERE id = ?', [(int)$imageId]);
        if (!$img) Response::notFound('تصویر یافت نشد');
        self::deleteImageFile($img['file_path'] ?? '');
        $db->execute('DELETE FROM host_property_images WHERE id = ?', [(int)$imageId]);
        $prop = self::find((int)$img['property_id']);
        if ($prop && ($prop['cover_image'] ?? '') === ($img['file_path'] ?? '')) {
            $next = $prop['images'][0]['file_path'] ?? null;
            $db->update('host_properties', ['cover_image' => $next], 'id = ?', [(int)$img['property_id']]);
        }
        return true;
    }

    private static function deleteImageFile($relativePath)
    {
        if (!$relativePath) return;
        $full = ROOT_PATH . '/' . ltrim(str_replace(['..', '\\'], ['', '/'], $relativePath), '/');
        if (is_file($full) && strpos(realpath($full), realpath(ROOT_PATH . '/uploads')) === 0) {
            @unlink($full);
        }
    }

    public static function refreshHostCount($hostId)
    {
        self::migrate();
        $cnt = (int)Database::getInstance()->fetchColumn(
            'SELECT COUNT(*) FROM host_properties WHERE host_id = ? AND status != ?',
            [(int)$hostId, 'draft']
        );
        Database::getInstance()->update('hosts', ['properties' => $cnt], 'id = ?', [(int)$hostId]);
    }

    public static function upsertFromSite($hostId, $sitePropertyId, array $data)
    {
        self::migrate();
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            'SELECT id FROM host_properties WHERE site_property_id = ? LIMIT 1',
            [(string)$sitePropertyId]
        );
        $payload = array_merge($data, [
            'host_id' => (int)$hostId,
            'site_property_id' => (string)$sitePropertyId,
        ]);
        if ($existing) {
            self::update((int)$existing['id'], $payload);
            return (int)$existing['id'];
        }
        return self::create($payload);
    }
}
