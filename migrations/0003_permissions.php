<?php
/**
 * 0003_permissions — permission roles and grants (2.3.0, ADR-006). Additive.
 * No roles or grants are created: existing access keeps coming from the legacy fields
 * (eb_users.role, permissions_json, org-chart roles), projected by core/Permissions.php.
 */
$tables = [
    'eb_perm_roles' => "CREATE TABLE IF NOT EXISTS eb_perm_roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pid CHAR(26) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(500) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'eb_perm_role_keys' => "CREATE TABLE IF NOT EXISTS eb_perm_role_keys (
        role_id INT NOT NULL,
        perm_key VARCHAR(120) NOT NULL,
        PRIMARY KEY (role_id, perm_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'eb_perm_grants' => "CREATE TABLE IF NOT EXISTS eb_perm_grants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        role_id INT NOT NULL,
        scope_type VARCHAR(20) NOT NULL DEFAULT 'tenant',
        scope_id INT NOT NULL DEFAULT 0,
        granted_by INT DEFAULT NULL,
        granted_at DATETIME NOT NULL,
        expires_at DATETIME DEFAULT NULL,
        UNIQUE KEY uq_grant (user_id, role_id, scope_type, scope_id),
        KEY idx_role (role_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
return [
    'description' => 'Permissions: eb_perm_roles, eb_perm_role_keys, eb_perm_grants',
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
