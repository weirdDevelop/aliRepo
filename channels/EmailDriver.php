<?php
/**
 * channels/EmailDriver.php
 * کانال ایمیل از طریق SMTP (بدون کتابخانه، با سوکت خام).
 *
 * تنظیمات از eb_settings:
 *   channel.email.host, .port, .user, .pass, .from, .secure (ssl|tls|'')
 *
 * recipient: ['email' => 'a@b.com'] یا ['channel_uid'=>'a@b.com']
 * message:   ['text'=>..., 'subject'=>?]
 *
 * نکته: SMTP با TLS واقعی نیاز به اکستنشن openssl دارد. تست زنده روی سرور لازم است.
 */

class EmailDriver implements ChannelInterface
{
    private $host, $port, $user, $pass, $from, $secure;

    public function __construct(array $cfg)
    {
        $this->host   = $cfg['host'] ?? '';
        $this->port   = (int)($cfg['port'] ?? 587);
        $this->user   = $cfg['user'] ?? '';
        $this->pass   = $cfg['pass'] ?? '';
        $this->from   = $cfg['from'] ?? $this->user;
        $this->secure = $cfg['secure'] ?? 'tls'; // tls | ssl | ''
    }

    public function name(): string { return 'email'; }

    public function capabilities(): array { return ['text', 'html', 'bulk_email']; }

    public function send(array $recipient, array $message): array
    {
        $to = $recipient['email'] ?? $recipient['channel_uid'] ?? $recipient['phone'] ?? null;
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'ایمیل نامعتبر'];
        }
        $subject = $message['subject'] ?? 'پیام';
        $body    = $message['text'] ?? '';
        return $this->smtpSend($to, $subject, $body);
    }

    public function handleInbound(array $payload): ?array { return null; } // ایمیل ورودی فعلاً نداریم

    public function setWebhook(string $url): array { return ['ok' => false, 'error' => 'ایمیل webhook ندارد']; }

    /** ارسال واقعی از طریق SMTP */
    private function smtpSend(string $to, string $subject, string $body): array
    {
        if ($this->host === '') return ['ok' => false, 'error' => 'SMTP تنظیم نشده'];

        $host = $this->host;
        if ($this->secure === 'ssl') $host = 'ssl://' . $host;

        $errno = 0; $errstr = '';
        $fp = @fsockopen($host, $this->port, $errno, $errstr, 15);
        if (!$fp) return ['ok' => false, 'error' => "اتصال SMTP ناموفق: $errstr"];

        $read = function() use ($fp) {
            $data = '';
            while ($str = fgets($fp, 515)) {
                $data .= $str;
                if (isset($str[3]) && $str[3] === ' ') break;
            }
            return $data;
        };
        $cmd = function($c) use ($fp, $read) {
            fwrite($fp, $c . "\r\n");
            return $read();
        };

        try {
            $read(); // banner
            $cmd("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

            // STARTTLS
            if ($this->secure === 'tls') {
                $cmd("STARTTLS");
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($fp);
                    return ['ok' => false, 'error' => 'STARTTLS ناموفق'];
                }
                $cmd("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            }

            // احراز هویت AUTH LOGIN
            if ($this->user !== '') {
                $cmd("AUTH LOGIN");
                $r = $cmd(base64_encode($this->user));
                $r = $cmd(base64_encode($this->pass));
                if (strpos($r, '235') !== 0) {
                    fclose($fp);
                    return ['ok' => false, 'error' => 'احراز هویت SMTP ناموفق'];
                }
            }

            $cmd("MAIL FROM:<{$this->from}>");
            $cmd("RCPT TO:<{$to}>");
            $cmd("DATA");

            $headers  = "From: {$this->from}\r\n";
            $headers .= "To: {$to}\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n";
            $data = $headers . "\r\n" . chunk_split(base64_encode($body));
            $r = $cmd($data . "\r\n.");
            $cmd("QUIT");
            fclose($fp);

            if (strpos($r, '250') === 0) {
                return ['ok' => true, 'error' => null];
            }
            return ['ok' => false, 'error' => 'ارسال رد شد: ' . substr($r, 0, 100)];
        } catch (Throwable $e) {
            if (is_resource($fp)) fclose($fp);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
