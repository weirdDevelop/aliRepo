<?php
/**
 * 0002_kernel — platform kernel tables (2.2.0): outbox, consumed events, jobs, API idempotency.
 * Times are UTC (ADR-012). Additive only.
 */
$tables = [
    'eb_outbox' => "CREATE TABLE IF NOT EXISTS eb_outbox (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        event_id CHAR(26) NOT NULL UNIQUE,
        type VARCHAR(100) NOT NULL,
        subject VARCHAR(120) DEFAULT NULL,
        payload_json LONGTEXT NOT NULL,
        occurred_at DATETIME NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        next_attempt_at DATETIME NOT NULL,
        last_error TEXT DEFAULT NULL,
        claimed_by CHAR(16) DEFAULT NULL,
        dispatched_at DATETIME DEFAULT NULL,
        KEY idx_due (status, next_attempt_at),
        KEY idx_type (type, occurred_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'eb_outbox_consumed' => "CREATE TABLE IF NOT EXISTS eb_outbox_consumed (
        listener VARCHAR(100) NOT NULL,
        event_id CHAR(26) NOT NULL,
        consumed_at DATETIME NOT NULL,
        PRIMARY KEY (listener, event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'eb_jobs' => "CREATE TABLE IF NOT EXISTS eb_jobs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(100) NOT NULL,
        payload_json LONGTEXT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        priority TINYINT NOT NULL DEFAULT 5,
        run_at DATETIME NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        max_attempts INT NOT NULL DEFAULT 5,
        unique_key VARCHAR(190) DEFAULT NULL,
        locked_by CHAR(32) DEFAULT NULL,
        locked_until DATETIME DEFAULT NULL,
        last_error TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        finished_at DATETIME DEFAULT NULL,
        UNIQUE KEY uq_unique (unique_key),
        KEY idx_due (status, run_at, priority),
        KEY idx_lock (locked_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'eb_idempotency' => "CREATE TABLE IF NOT EXISTS eb_idempotency (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        idem_key VARCHAR(64) NOT NULL,
        actor VARCHAR(64) NOT NULL,
        method VARCHAR(8) NOT NULL,
        path VARCHAR(190) NOT NULL,
        body_sha256 CHAR(64) NOT NULL,
        status_code SMALLINT NOT NULL,
        response LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_key (actor, idem_key),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
return [
    'description' => 'Kernel: eb_outbox, eb_outbox_consumed, eb_jobs, eb_idempotency',
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
        // Only for a fresh install rollback; production rollback = leave tables (older code ignores them).
        foreach (array_reverse(array_keys($tables)) as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
    },
];
