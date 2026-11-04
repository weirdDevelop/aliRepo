<?php
/**
 * src/bootstrap.php — wires bounded contexts into the kernel (2.2.0).
 * Each context may provide src/<Context>/bootstrap.php that registers its event listeners
 * (Outbox::listen) and job handlers (Jobs::handle). Loaded by api.php and cron.php.
 */
foreach (glob(__DIR__ . '/*/bootstrap.php') ?: [] as $f) require_once $f;
