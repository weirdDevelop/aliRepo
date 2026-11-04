<?php
/**
 * جاکجاست - کلاس دیتابیس (PDO Singleton)
 * Jakojast - Database Class
 *
 * تمام کوئری‌ها فقط با Prepared Statements اجرا می‌شوند.
 * از PDO برای امنیت و انعطاف‌پذیری استفاده شده است.
 */

class Database
{
    /** کش «مهاجرت‌های خودکار انجام‌شده» تا ensure*()ها در هر درخواست کوئری نزنند (حذف فایل = بررسی مجدد) */
    const SCHEMA_CACHE_VERSION = 'v1';
    private static $schemaCache = null;

    private static function schemaCacheFile()
    {
        return (defined('LOG_PATH') ? LOG_PATH : __DIR__ . '/../logs') . '/.schema_cache_' . self::SCHEMA_CACHE_VERSION . '.json';
    }

    public static function schemaDone($key)
    {
        if (self::$schemaCache === null) {
            $f = self::schemaCacheFile();
            $data = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
            self::$schemaCache = is_array($data) ? $data : [];
        }
        return !empty(self::$schemaCache[$key]);
    }

    public static function markSchemaDone($key)
    {
        if (self::schemaDone($key)) return;
        self::$schemaCache[$key] = date('Y-m-d H:i:s');
        @file_put_contents(self::schemaCacheFile(), json_encode(self::$schemaCache), LOCK_EX);
    }

    /** @var PDO|null */
    private static $instance = null;

    /** @var PDO */
    private $pdo;

    /**
     * اتصال به دیتابیس با تنظیمات موجود در config.php
     */
    private function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,  // برای prepared statements واقعی
            PDO::ATTR_PERSISTENT         => false,
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            Logger::error('Database connection failed: ' . $e->getMessage());
            if (DEBUG_MODE) {
                Response::serverError('خطا در اتصال به دیتابیس: ' . $e->getMessage());
            } else {
                Response::serverError('امکان اتصال به دیتابیس وجود ندارد. لطفاً با مدیر سرور تماس بگیرید.');
            }
        }
    }

    /**
     * دریافت نمونه یکتای دیتابیس
     * @return Database
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * دریافت شیء PDO خام (برای موارد پیشرفته)
     * @return PDO
     */
    public function pdo()
    {
        return $this->pdo;
    }

    /**
     * اجرای کوئری SELECT و برگرداندن همه رکوردها
     *
     * @param string $sql
     * @param array  $params
     * @return array
     */
    public function fetchAll($sql, $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            Logger::error('SQL fetchAll failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw $e;
        }
    }

    /**
     * اجرای کوئری SELECT و برگرداندن یک رکورد
     *
     * @param string $sql
     * @param array  $params
     * @return array|null
     */
    public function fetchOne($sql, $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            Logger::error('SQL fetchOne failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw $e;
        }
    }

    /**
     * اجرای کوئری SELECT و برگرداندن یک ستون (مقدار تک)
     *
     * @param string $sql
     * @param array  $params
     * @return mixed|null
     */
    public function fetchColumn($sql, $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $val = $stmt->fetchColumn();
            return $val !== false ? $val : null;
        } catch (PDOException $e) {
            Logger::error('SQL fetchColumn failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw $e;
        }
    }

    /**
     * اجرای کوئری INSERT/UPDATE/DELETE
     *
     * @param string $sql
     * @param array  $params
     * @return int  (تعداد رکوردهای تحت تأثیر یا آخرین ID)
     */
    public function execute($sql, $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            Logger::error('SQL execute failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw $e;
        }
    }

    /**
     * درج یک رکورد و برگرداندن آخرین ID
     *
     * @param string $table
     * @param array  $data  (associative: column => value)
     * @return int|string   (آخرین ID درج شده)
     */
    public function insert($table, array $data)
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );

        $this->execute($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    /**
     * به‌روزرسانی رکوردها
     *
     * @param string $table
     * @param array  $data    (column => value)
     * @param string $where   (شرط SQL بدون کلمه WHERE)
     * @param array  $whereParams
     * @return int  (تعداد رکوردهای به‌روزرسانی شده)
     */
    public function update($table, array $data, $where, array $whereParams = [])
    {
        $setParts = [];
        $params = [];
        foreach ($data as $column => $value) {
            $setParts[] = "`{$column}` = ?";
            $params[] = $value;
        }
        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $setParts),
            $where
        );

        return $this->execute($sql, array_merge($params, $whereParams));
    }

    /**
     * حذف رکوردها
     *
     * @param string $table
     * @param string $where
     * @param array  $params
     * @return int
     */
    public function delete($table, $where, array $params = [])
    {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, $where);
        return $this->execute($sql, $params);
    }

    /**
     * شروع تراکنش
     */
    public function beginTransaction()
    {
        $this->pdo->beginTransaction();
    }

    /**
     * تأیید تراکنش
     */
    public function commit()
    {
        $this->pdo->commit();
    }

    /**
     * برگشت تراکنش
     */
    public function rollBack()
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * بررسی اتصال
     * @return bool
     */
    public function isConnected()
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Escape کردن مقدار برای استفاده در کوئری‌های غیر prepared (فقط برای موارد ضروری)
     * @param string $value
     * @return string
     */
    public function escape($value)
    {
        return $this->pdo->quote($value);
    }

    /**
     * جلوگیری از clone شدن
     */
    private function __clone() {}

    /**
     * جلوگیری از unserialize
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize a singleton.");
    }
}
