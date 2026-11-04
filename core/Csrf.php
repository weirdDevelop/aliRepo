<?php
/**
 * core/Csrf.php
 * محافظت CSRF برای فرم‌ها و درخواست‌های POST.
 */

class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['eb_csrf'])) {
            $_SESSION['eb_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['eb_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token()) . '">';
    }

    public static function verify(?string $token): bool
    {
        return !empty($_SESSION['eb_csrf'])
            && is_string($token)
            && hash_equals($_SESSION['eb_csrf'], $token);
    }

    /** برای درخواست‌های POST؛ در صورت نامعتبر بودن، توقف */
    public static function requireValid(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        // توکن می‌تواند در $_POST، هدر، یا بدنه‌ی JSON باشد
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if ($token === null) {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $json = json_decode($raw, true);
                if (is_array($json) && isset($json['_csrf'])) {
                    $token = $json['_csrf'];
                }
            }
        }
        if (!self::verify($token)) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'توکن امنیتی نامعتبر است']);
            exit;
        }
    }
}
