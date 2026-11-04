<?php
/**
 * tests/integration/Sandbox.php — disposable install of the app for integration tests.
 *
 * Copies the git-tracked source (never config.php, logs, uploads) into a temp dir, writes a
 * synthetic config.php pointing at a THROWAWAY database, creates the schema exactly as
 * install.php does (Schema::ensure + SchemaV4::ensure(true) + crm/db.sql), seeds synthetic
 * users, and serves it with `php -S`. Nothing here may point at production.
 *
 * Required env: EB_TEST_DB_HOST, EB_TEST_DB_PORT, EB_TEST_DB_NAME, EB_TEST_DB_USER, EB_TEST_DB_PASS
 * The database named in EB_TEST_DB_NAME is DROPPED and recreated. Its name must start with "ebtest".
 */
final class Sandbox
{
    public string $dir;
    public string $url;
    private $server;
    private array $db;
    private string $root;

    public function __construct(string $repoRoot, int $port = 8099)
    {
        $this->root = $repoRoot;
        $this->db = [
            'host' => getenv('EB_TEST_DB_HOST') ?: '', 'port' => getenv('EB_TEST_DB_PORT') ?: '3306',
            'name' => getenv('EB_TEST_DB_NAME') ?: '', 'user' => getenv('EB_TEST_DB_USER') ?: '',
            'pass' => getenv('EB_TEST_DB_PASS') ?: '',
        ];
        if ($this->db['host'] === '' || strpos($this->db['name'], 'ebtest') !== 0) {
            throw new RuntimeException('Set EB_TEST_DB_* (database name must start with "ebtest"); integration tests skipped.');
        }
        $this->dir = sys_get_temp_dir() . '/eb-sandbox-' . getmypid();
        $this->url = "http://127.0.0.1:$port";
        $this->copySource();
        $this->writeConfig();
        $this->resetDatabase();
        $this->createSchema();
        $this->startServer($port);
    }

    public function pdo(): PDO
    {
        $d = $this->db;
        return new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    private function copySource(): void
    {
        $files = [];
        exec('git -C ' . escapeshellarg($this->root) . ' ls-files -co --exclude-standard', $files, $rc);
        if ($rc !== 0) throw new RuntimeException('git ls-files failed');
        foreach ($files as $f) {
            if ($f === 'config.php' || preg_match('#^(logs|uploads|crm/logs|crm/uploads|tests)/#', $f)) continue;
            $src = $this->root . '/' . $f; if (!is_file($src)) continue;
            $dst = $this->dir . '/' . $f;
            if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0777, true);
            copy($src, $dst);
        }
        foreach (['logs/cache', 'uploads', 'crm/logs', 'crm/uploads'] as $d) @mkdir($this->dir . '/' . $d, 0777, true);
    }

    private function writeConfig(): void
    {
        $cfg = [
            'db' => $this->db + ['charset' => 'utf8mb4'],
            'app_key' => base64_encode(random_bytes(32)),
            'jakojast_integration_token' => bin2hex(random_bytes(16)),
            'jakojast_base_url' => '', 'jakojast_outgoing_token' => '',
            'base_url' => $this->url, 'trust_proxy' => false,
            'paths' => ['uploads' => $this->dir . '/uploads/', 'logs' => $this->dir . '/logs/'],
            'debug' => false, 'analytics_license_secret' => bin2hex(random_bytes(16)),
        ];
        file_put_contents($this->dir . '/config.php', '<?php return ' . var_export($cfg, true) . ';');
    }

    private function resetDatabase(): void
    {
        $d = $this->db;
        $pdo = new PDO("mysql:host={$d['host']};port={$d['port']};charset=utf8mb4", $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("DROP DATABASE IF EXISTS `{$d['name']}`");
        $pdo->exec("CREATE DATABASE `{$d['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function createSchema(): void
    {
        $script = <<<'PHP'
<?php
chdir($argv[1]); $config = require 'config.php';
require 'core/Database.php'; require 'core/Schema.php'; require 'core/SchemaV4.php'; require 'core/Autoload.php';
Database::init($config['db']); Schema::ensure(); SchemaV4::ensure(true);
$pdo = Database::pdo();
foreach (['crm/db.sql', 'crm/db_extra.sql'] as $f) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($f));
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) { try { $pdo->exec($stmt); } catch (Throwable $e) { fwrite(STDERR, "crm sql: " . substr($e->getMessage(), 0, 120) . "\n"); } }
}
echo "schema-ok\n";
PHP;
        $tmp = $this->dir . '/__schema.php'; file_put_contents($tmp, $script);
        $out = shell_exec('php ' . escapeshellarg($tmp) . ' ' . escapeshellarg($this->dir) . ' 2>&1');
        unlink($tmp);
        if (strpos((string)$out, 'schema-ok') === false) throw new RuntimeException("schema failed:\n" . $out);
    }

    private function startServer(int $port): void
    {
        $cmd = ['php', '-S', "127.0.0.1:$port", '-t', $this->dir];
        $this->server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $this->dir . '/logs/server.log', 'a'], 2 => ['file', $this->dir . '/logs/server.log', 'a']], $pipes);
        for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) return; usleep(100000); }
        throw new RuntimeException('php -S did not start');
    }

    /** Create a panel user. $perms = permissions_json array or null. */
    public function user(string $username, string $role, ?array $perms = null): void
    {
        $this->pdo()->prepare("INSERT INTO eb_users (username, password_hash, role, permissions_json) VALUES (?, ?, ?, ?)")
            ->execute([$username, password_hash('pw-' . $username, PASSWORD_DEFAULT), $role, $perms === null ? null : json_encode($perms)]);
    }

    public function serverLog(): string { return (string)@file_get_contents($this->dir . '/logs/server.log'); }

    public function __destruct()
    {
        if ($this->server) { proc_terminate($this->server); proc_close($this->server); }
        if (is_dir($this->dir) && strpos($this->dir, '/eb-sandbox-') !== false) exec('rm -rf ' . escapeshellarg($this->dir));
    }
}

/** Minimal HTTP client with a cookie jar, logging in like a browser. */
final class Browser
{
    private string $jar;
    public ?string $csrf = null;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'ebjar'); }

    public function request(string $method, string $path, $body = null, array $headers = []): array
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['code' => $code, 'body' => (string)$out];
    }

    public function login(string $user, string $pass): bool
    {
        $r = $this->request('GET', 'index.php?page=login');
        if (!preg_match('/name="_csrf" value="([0-9a-f]+)"/', $r['body'], $m)) return false;
        $this->request('POST', 'index.php?page=login', http_build_query(['_csrf' => $m[1], 'username' => $user, 'password' => $pass]));
        $page = $this->request('GET', 'index.php')['body'];
        if (preg_match('/const CSRF = "([0-9a-f]+)"/', $page, $m) || preg_match('/"csrf":"([0-9a-f]+)"/', $page, $m)) { $this->csrf = $m[1]; return true; }
        return false;
    }

    /** Call a panel API action exactly like views/layout.php api() does. */
    public function api(string $name, array $data = []): array
    {
        $r = $this->request('POST', 'index.php?api=' . urlencode($name), json_encode($data + ['_csrf' => $this->csrf]), ['Content-Type: application/json']);
        $j = json_decode($r['body'], true);
        return ['code' => $r['code'], 'json' => is_array($j) ? $j : null, 'raw' => $r['body']];
    }
}
