<?php
/**
 * 0001_baseline — adopts the existing schema as the starting point of versioned migrations.
 * Runs the same idempotent code the installer and "به‌روزرسانی دیتابیس" run (Schema::ensure +
 * SchemaV4::ensure(true)), then verifies that every table those files declare exists.
 * Safe on a fresh install and on a long-running production database.
 */
return [
    'description' => 'Baseline: existing Elanban core schema (core/Schema.php + core/SchemaV4.php)',
    'destructive' => false,
    'up' => function (PDO $pdo): void {
        require_once __DIR__ . '/../core/Schema.php';
        require_once __DIR__ . '/../core/SchemaV4.php';
        Schema::ensure();
        SchemaV4::ensure(true);
        Database::forgetSchemaCache();
    },
    'verify' => function (PDO $pdo): array {
        $expected = [];
        foreach (['Schema.php', 'SchemaV4.php'] as $f) {
            preg_match_all('/CREATE TABLE IF NOT EXISTS `?(eb_[a-z0-9_]+)`?/', file_get_contents(__DIR__ . '/../core/' . $f), $m);
            $expected = array_merge($expected, $m[1]);
        }
        $have = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff(array_unique($expected), $have));
        return $missing ? ['missing tables: ' . implode(', ', $missing)] : [];
    },
    'down' => null,
];
