<?php
/**
 * جاکجاست - کلاس لاگ‌گیری
 * Jakojast - Logger Class
 *
 * تمام خطاهای سیستم در logs/error.log ثبت می‌شوند.
 */

class Logger
{
    const LEVEL_DEBUG    = 'DEBUG';
    const LEVEL_INFO     = 'INFO';
    const LEVEL_WARNING  = 'WARNING';
    const LEVEL_ERROR    = 'ERROR';
    const LEVEL_CRITICAL = 'CRITICAL';

    /**
     * مسیر فایل لاگ
     * @return string
     */
    private static function logFile()
    {
        return LOG_PATH . '/error.log';
    }

    /**
     * نوشتن لاگ
     *
     * @param string $level
     * @param string $message
     * @param array  $context
     */
    public static function log($level, $message, array $context = [])
    {
        // اطمینان از وجود پوشه logs
        if (!is_dir(LOG_PATH)) {
            @mkdir(LOG_PATH, 0755, true);
        }

        $date = date('Y-m-d H:i:s');
        $ip = self::clientIp();
        $uri = $_SERVER['REQUEST_URI'] ?? '(cli)';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';

        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $line = sprintf(
            "[%s] [%s] [%s %s] [IP:%s] %s%s\n",
            $date,
            $level,
            $method,
            $uri,
            $ip,
            $message,
            $contextStr
        );

        // در حالت DEBUG_MODE هم در خروجی نمایش بده
        if (DEBUG_MODE && php_sapi_name() !== 'cli') {
            error_log($line);
        }

        // همیشه در فایل بنویس
        @file_put_contents(self::logFile(), $line, FILE_APPEND | LOCK_EX);
    }

    public static function debug($message, array $context = [])
    {
        if (DEBUG_MODE) {
            self::log(self::LEVEL_DEBUG, $message, $context);
        }
    }

    public static function info($message, array $context = [])
    {
        self::log(self::LEVEL_INFO, $message, $context);
    }

    public static function warning($message, array $context = [])
    {
        self::log(self::LEVEL_WARNING, $message, $context);
    }

    public static function error($message, array $context = [])
    {
        self::log(self::LEVEL_ERROR, $message, $context);
    }

    public static function critical($message, array $context = [])
    {
        self::log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * استخراج آدرس IP واقعی کلاینت (با در نظر گرفتن پروکسی)
     * @return string
     */
    private static function clientIp()
    {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CF_CONNECTING_IP',
            'REMOTE_ADDR',
        ];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /**
     * لاگ‌گیری خطاهای غیرقابل catch
     */
    public static function registerShutdownHandler()
    {
        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                self::critical('Fatal error: ' . $error['message'], [
                    'file' => $error['file'],
                    'line' => $error['line'],
                ]);
            }
        });
    }

    /**
     * لاگ‌گیری استثناهای غیرقابل catch
     */
    public static function registerExceptionHandler()
    {
        set_exception_handler(function ($exception) {
            self::critical('Uncaught exception: ' . $exception->getMessage(), [
                'class' => get_class($exception),
                'file'  => $exception->getFile(),
                'line'  => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            if (DEBUG_MODE) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'خطای داخلی سرور',
                    'error'   => $exception->getMessage(),
                    'trace'   => $exception->getTraceAsString(),
                ], JSON_UNESCAPED_UNICODE);
            } else {
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode([
                    'success' => false,
                    'message' => 'خطای داخلی سرور. لطفاً بعداً تلاش کنید.',
                ], JSON_UNESCAPED_UNICODE);
            }
        });
    }
}

// ثبت هندلرها در زمان بارگذاری کلاس
Logger::registerShutdownHandler();
Logger::registerExceptionHandler();
