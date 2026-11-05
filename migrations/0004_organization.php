<?php
/**
 * 0004_organization — org units, effective-dated memberships, delegations, unit change history (2.4.0).
 * Additive. Nothing is imported automatically: the owner previews and applies the import
 * (Settings → واحدهای سازمانی) after reviewing the report.
 */
$tables = [
    'eb_org_units' => "CREATE TABLE IF NOT EXISTS eb_org_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pid CHAR(26) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'department',
        parent_id INT DEFAULT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'active',
        sort_order INT NOT NULL DEFAULT 0,
        source VARCHAR(20) DEFAULT NULL,
        source_id VARCHAR(64) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        archived_at DATETIME DEFAULT NULL,
        UNIQUE KEY uq_source (source, source_id),
        KEY idx_parent (parent_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'eb_org_memberships' => "CREATE TABLE IF NOT EXISTS eb_org_memberships (
        id INT AUTO_INCREMENT PRIMARY KEY,
        unit_id INT NOT NULL,
        user_id INT NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'member',
        valid_from DATE NOT NULL,
        valid_to DATE DEFAULT NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        source VARCHAR(20) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        ended_by INT DEFAULT NULL,
        ended_at DATETIME DEFAULT NULL,
        KEY idx_unit (unit_id, valid_to),
        KEY idx_user (user_id, valid_to)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'eb_org_delegations' => "CREATE TABLE IF NOT EXISTS eb_org_delegations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        unit_id INT NOT NULL,
        from_user_id INT NOT NULL,
        to_user_id INT NOT NULL,
        valid_from DATE NOT NULL,
        valid_to DATE NOT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        revoked_at DATETIME DEFAULT NULL,
        revoked_by INT DEFAULT NULL,
        KEY idx_unit (unit_id, valid_from, valid_to)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'eb_org_changes' => "CREATE TABLE IF NOT EXISTS eb_org_changes (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        unit_id INT NOT NULL,
        change_type VARCHAR(30) NOT NULL,
        old_value VARCHAR(255) DEFAULT NULL,
        new_value VARCHAR(255) DEFAULT NULL,
        by_user_id INT DEFAULT NULL,
        at DATETIME NOT NULL,
        KEY idx_unit (unit_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
return [
    'description' => 'Organization: eb_org_units, eb_org_memberships, eb_org_delegations, eb_org_changes',
    'destructive' => false,
    'up' => function (PDO $pdo) use ($tables): void {
        foreach ($tables as $sql) $pdo->exec($sql);
    },
    'verify' => function (PDO $pdo) use ($tables): array {
        $have = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff(array_keys($tables), $have));
        return $missing ? ['missing tables: ' . implode(', ', $missing)] : [];
    },
    'down' => function (PDO $pdo) use ($tables): void {
        foreach (array_reverse(array_keys($tables)) as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
    },
];
