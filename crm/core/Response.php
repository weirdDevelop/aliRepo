<?php
/**
 * جاکجاست - کلاس پاسخ‌دهی استاندارد
 * Jakojast - Response Class
 *
 * تمام APIها از این کلاس برای خروجی JSON استفاده می‌کنند.
 * فرمت استاندارد: { success, data, meta, message }
 */

class Response
{
    /**
     * پاسخ موفق
     *
     * @param mixed  $data
     * @param string $message
     * @param array  $meta
     * @param int    $httpCode
     */
    public static function success($data = null, $message = 'عملیات با موفقیت انجام شد', $meta = [], $httpCode = 200)
    {
        self::json([
            'success' => true,
            'data'    => $data,
            'meta'    => $meta,
            'message' => $message,
        ], $httpCode);
    }

    /**
     * پاسخ موفق با لیست صفحه‌بندی شده
     *
     * @param array $items
     * @param int   $total
     * @param int   $page
     * @param int   $limit
     * @param string $message
     */
    public static function paginated(array $items, $total, $page, $limit, $message = 'لیست دریافت شد')
    {
        self::json([
            'success' => true,
            'data'    => $items,
            'meta'    => [
                'total'      => (int)$total,
                'page'       => (int)$page,
                'limit'      => (int)$limit,
                'totalPages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
            ],
            'message' => $message,
        ], 200);
    }

    /**
     * پاسخ خطای ۴۰۰ (Bad Request)
     *
     * @param string $message
     * @param array  $errors
     */
    public static function badRequest($message = 'درخواست نامعتبر است', $errors = [])
    {
        self::json([
            'success' => false,
            'data'    => null,
            'meta'    => ['errors' => $errors],
            'message' => $message,
        ], 400);
    }

    /**
     * پاسخ خطای ۴۰۱ (Unauthorized)
     *
     * @param string $message
     */
    public static function unauthorized($message = 'احراز هویت لازم است')
    {
        self::json([
            'success' => false,
            'data'    => null,
            'meta'    => [],
            'message' => $message,
        ], 401);
    }

    /**
     * پاسخ خطای ۴۰۳ (Forbidden)
     *
     * @param string $message
     */
    public static function forbidden($message = 'دسترسی غیرمجاز')
    {
        self::json([
            'success' => false,
            'data'    => null,
            'meta'    => [],
            'message' => $message,
        ], 403);
    }

    /**
     * پاسخ خطای ۴۰۴ (Not Found)
     *
     * @param string $message
     */
    public static function notFound($message = 'موردی یافت نشد')
    {
        self::json([
            'success' => false,
            'data'    => null,
            'meta'    => [],
            'message' => $message,
        ], 404);
    }

    /**
     * پاسخ خطای ۴۲۲ (Validation Error)
     *
     * @param string $message
     * @param array  $errors
     */
    public static function validationError($message = 'خطا در اعتبارسنجی داده‌ها', $errors = [])
    {
        self::json([
            'success' => false,
            'data'    => null,
            'meta'    => ['errors' => $errors],
            'message' => $message,
        ], 422);
    }

    /**
     * پاسخ خطای ۵۰۰ (Server Error)
     *
     * @param string $message
     */
    public static function serverError($message = 'خطای داخلی سرور')
    {
        self::json([
            'success' => false,
            'data'    => null,
            'meta'    => [],
            'message' => $message,
        ], 500);
    }

    /**
     * پاسخ عمومی JSON
     *
     * @param array $payload
     * @param int   $httpCode
     */
    public static function json(array $payload, $httpCode = 200)
    {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * پاسخ CSV برای خروجی اکسل
     *
     * @param string $filename
     * @param array  $headers
     * @param array  $rows
     */
    public static function csv($filename, array $headers, array $rows)
    {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            // BOM برای پشتیبانی از UTF-8 در Excel
            echo "\xEF\xBB\xBF";
        }
        $out = fopen('php://output', 'w');
        // هدر
        fputcsv($out, $headers);
        // ردیف‌ها
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    /**
     * پاسخ Plain Text
     *
     * @param string $text
     * @param int    $httpCode
     */
    public static function text($text, $httpCode = 200)
    {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $text;
        exit;
    }

    /**
     * پاسخ خالی (مثلاً برای webhook)
     *
     * @param int $httpCode
     */
    public static function empty($httpCode = 200)
    {
        if (!headers_sent()) {
            http_response_code($httpCode);
        }
        exit;
    }
}
