<?php
/**
 * سرویس ارسال پیامک — کاوه‌نگار / ملی‌پیامک / وب‌سرویس ساده
 *
 * تنظیمات (settings):
 *   sms_provider: kavenegar|melipayamak|generic
 *   sms_api_key
 *   sms_sender          (خط فرستنده)
 *   sms_generic_url     (فقط برای generic — POST JSON: {to, message, api_key, sender})
 *   sms_missed_enabled  (1/0)
 *   sms_missed_template متن با {name} و {phone}
 *   sms_missed_delay_minutes  حداقل فاصله از تماس بی‌پاسخ تا ارسال (پیش‌فرض ۲)
 */

class SmsService
{
    /** سیستم یکپارچه: پیامک با سرویس پیامک اعلان‌بان */
    private function bridged()
    {
        return defined('CRM_BRIDGED') && CRM_BRIDGED && defined('CRM_BRIDGE_KEY') && CRM_BRIDGE_KEY !== '';
    }

    /** ارسال از راه اعلان‌بان (crm-bridge.php ← Sender::sendByPhone، کانال پیامک) */
    private function sendViaElanban($mobile, $message)
    {
        $base = ElanbanAPI::bridgeBaseUrl();
        if ($base === '') return ['success' => false, 'message' => 'آدرس اعلان‌بان (base_url در config.php اعلان‌بان) تنظیم نشده است'];
        $ch = curl_init($base . '/crm-bridge.php?action=sms_send');
        curl_setopt_array($ch, ElanbanAPI::curlRedirectOpts());
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['to' => $mobile, 'text' => $message], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Bridge-Key: ' . CRM_BRIDGE_KEY, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) return ['success' => false, 'message' => 'اتصال به اعلان‌بان برقرار نشد: ' . $err];
        $d = json_decode((string)$body, true);
        if (!is_array($d)) return ['success' => false, 'message' => 'پاسخ نامعتبر از اعلان‌بان (کد ' . $code . ')'];
        return ['success' => !empty($d['ok']), 'message' => !empty($d['ok']) ? 'ارسال شد' : ($d['error'] ?? 'ارسال نشد'), 'provider_response' => $d];
    }

    public function isConfigured()
    {
        if ($this->bridged()) return true;
        $provider = Report::getSetting('sms_provider', '');
        $key = Report::getSetting('sms_api_key', '');
        return $provider !== '' && $key !== '';
    }

    /**
     * @param string $to      شماره گیرنده (09…)
     * @param string $message متن
     * @return array{success:bool,message:string,provider_response?:mixed}
     */
    public function send($to, $message)
    {
        $mobile = Helpers::sanitizeMobile($to);
        if ($mobile === false) {
            return ['success' => false, 'message' => 'شماره موبایل نامعتبر است (فقط موبایل برای پیامک)'];
        }
        $message = trim((string)$message);
        if ($message === '') {
            return ['success' => false, 'message' => 'متن پیامک خالی است'];
        }
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'پنل پیامک پیکربندی نشده است'];
        }
        if ($this->bridged()) {
            return $this->sendViaElanban($mobile, $message);
        }

        $provider = Report::getSetting('sms_provider', 'kavenegar');
        try {
            if ($provider === 'kavenegar') {
                return $this->sendKavenegar($mobile, $message);
            }
            if ($provider === 'melipayamak') {
                return $this->sendMelipayamak($mobile, $message);
            }
            if ($provider === 'generic') {
                return $this->sendGeneric($mobile, $message);
            }
            return ['success' => false, 'message' => 'سرویس‌دهنده پیامک ناشناخته: ' . $provider];
        } catch (Exception $e) {
            Logger::error('SmsService::send failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function sendKavenegar($mobile, $message)
    {
        $apiKey = Report::getSetting('sms_api_key');
        $sender = Report::getSetting('sms_sender', '');
        $url = 'https://api.kavenegar.com/v1/' . rawurlencode($apiKey) . '/sms/send.json';
        $post = [
            'receptor' => $mobile,
            'message'  => $message,
        ];
        if ($sender !== '') $post['sender'] = $sender;

        $res = $this->httpPost($url, $post);
        $ok = !empty($res['json']['return']['status']) && (int)$res['json']['return']['status'] === 200;
        return [
            'success' => $ok,
            'message' => $ok ? 'پیامک ارسال شد' : ('خطای کاوه‌نگار: ' . ($res['json']['return']['message'] ?? $res['raw'])),
            'provider_response' => $res['json'] ?? $res['raw'],
        ];
    }

    private function sendMelipayamak($mobile, $message)
    {
        // الگوی رایج REST ملی‌پیامک (username=api_key یا توکن در فیلد username، password جدا در sms_password)
        $username = Report::getSetting('sms_api_key');
        $password = Report::getSetting('sms_password', '');
        $from = Report::getSetting('sms_sender', '');
        $url = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
        $payload = [
            'username' => $username,
            'password' => $password,
            'to'       => $mobile,
            'from'     => $from,
            'text'     => $message,
            'isflash'  => false,
        ];
        $res = $this->httpPostJson($url, $payload);
        $val = $res['json']['Value'] ?? $res['json']['RetStatus'] ?? null;
        $ok = is_numeric($val) ? ((int)$val > 10) : (!empty($res['json']['StrRetStatus']) && $res['json']['StrRetStatus'] === 'Ok');
        // بعضی پاسخ‌ها فقط عدد پیامک‌آی‌دی برمی‌گردانند
        if (!$ok && is_numeric($res['raw']) && (int)$res['raw'] > 10) $ok = true;
        return [
            'success' => $ok,
            'message' => $ok ? 'پیامک ارسال شد' : ('خطای ملی‌پیامک: ' . (is_string($res['raw']) ? $res['raw'] : json_encode($res['json'], JSON_UNESCAPED_UNICODE))),
            'provider_response' => $res['json'] ?? $res['raw'],
        ];
    }

    private function sendGeneric($mobile, $message)
    {
        $url = Report::getSetting('sms_generic_url', '');
        if ($url === '') {
            return ['success' => false, 'message' => 'آدرس وب‌سرویس generic تنظیم نشده'];
        }
        $payload = [
            'to'      => $mobile,
            'message' => $message,
            'api_key' => Report::getSetting('sms_api_key'),
            'sender'  => Report::getSetting('sms_sender', ''),
        ];
        $res = $this->httpPostJson($url, $payload);
        $ok = !empty($res['json']['success']) || (isset($res['http_code']) && $res['http_code'] >= 200 && $res['http_code'] < 300 && empty($res['json']['error']));
        return [
            'success' => $ok,
            'message' => $ok ? 'پیامک ارسال شد' : ('خطای وب‌سرویس: ' . ($res['json']['message'] ?? $res['raw'])),
            'provider_response' => $res['json'] ?? $res['raw'],
        ];
    }

    /**
     * ارسال خودکار به تماس‌های بی‌پاسخ که هنوز پیامک نگرفته‌اند
     * @return array{sent:int,skipped:int,failed:int,errors:array}
     */
    public function processMissedCallSms($limit = 30)
    {
        if (Report::getSetting('sms_missed_enabled', '0') !== '1') {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => ['غیرفعال']];
        }
        if (!$this->isConfigured()) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => ['پیکربندی نشده']];
        }

        self::ensureMissedSmsColumn();
        $delayMin = max(0, (int)Report::getSetting('sms_missed_delay_minutes', '2'));
        $template = Report::getSetting(
            'sms_missed_template',
            'سلام، با جاکجاست تماس گرفته بودید اما پاسخ داده نشد. به‌زودی با شما تماس می‌گیریم. jakojast.com'
        );

        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT id, call_source, contact_id, lead_id, started_at
             FROM calls
             WHERE type = 'inbound'
               AND status IN ('no_answer','busy','failed')
               AND missed_sms_sent_at IS NULL
               AND call_source IS NOT NULL AND call_source != ''
               AND started_at <= DATE_SUB(NOW(), INTERVAL {$delayMin} MINUTE)
               AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY started_at ASC
             LIMIT " . (int)$limit
        );

        $sent = 0; $skipped = 0; $failed = 0; $errors = [];
        foreach ($rows as $call) {
            $mobile = Helpers::sanitizeMobile($call['call_source']);
            if ($mobile === false) {
                // ثابت است — پیامک نمی‌رود؛ علامت بزن تا دوباره تلاش نشود
                $db->update('calls', ['missed_sms_sent_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$call['id']]);
                $skipped++;
                continue;
            }

            $name = 'مهمان گرامی';
            if (!empty($call['contact_id'])) {
                $c = $db->fetchOne('SELECT name FROM customers WHERE id = ?', [(int)$call['contact_id']]);
                if (!empty($c['name'])) $name = $c['name'];
            } elseif (!empty($call['lead_id'])) {
                $l = $db->fetchOne('SELECT name FROM leads WHERE id = ?', [(int)$call['lead_id']]);
                if (!empty($l['name'])) $name = $l['name'];
            }

            $text = str_replace(
                ['{name}', '{phone}', '{NAME}', '{PHONE}'],
                [$name, $mobile, $name, $mobile],
                $template
            );

            $result = $this->send($mobile, $text);
            if ($result['success']) {
                $db->update('calls', ['missed_sms_sent_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$call['id']]);
                $sent++;
            } else {
                $failed++;
                $errors[] = 'call#' . $call['id'] . ': ' . $result['message'];
                // اگر خطای اعتباری/کلید باشد ادامه نده
                if (stripos($result['message'], 'api') !== false || stripos($result['message'], 'auth') !== false) {
                    break;
                }
            }
        }

        return compact('sent', 'skipped', 'failed', 'errors');
    }

    public static function ensureMissedSmsColumn()
    {
        static $done = false;
        if ($done) return;
        try {
            $db = Database::getInstance();
            try {
                $db->fetchColumn('SELECT missed_sms_sent_at FROM calls LIMIT 1');
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `calls` ADD COLUMN `missed_sms_sent_at` DATETIME DEFAULT NULL COMMENT 'زمان ارسال پیامک بی‌پاسخ' AFTER `notes`");
                Logger::info('Auto migration: added calls.missed_sms_sent_at');
            }
        } catch (Exception $e) {
            Logger::error('ensureMissedSmsColumn: ' . $e->getMessage());
        }
        $done = true;
    }

    private function httpPost($url, array $fields)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new Exception('cURL: ' . $err);
        $json = json_decode($raw, true);
        return ['raw' => $raw, 'json' => $json, 'http_code' => $code];
    }

    private function httpPostJson($url, array $payload)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new Exception('cURL: ' . $err);
        $json = json_decode($raw, true);
        return ['raw' => $raw, 'json' => $json, 'http_code' => $code];
    }
}
