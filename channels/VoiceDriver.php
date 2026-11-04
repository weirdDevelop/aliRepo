<?php
/**
 * channels/VoiceDriver.php
 * تلفن گویا (تماس صوتی) — ملی‌پیامک، از طریق SOAP.
 *
 * برخلاف SMS که REST بود، وب‌سرویس صوتی ملی‌پیامک SOAP است:
 *   http://api.payamak-panel.com/post/Voice.asmx?wsdl
 *
 * متدهای استفاده‌شده (طبق مستندات رسمی):
 *   SendSpeechText(username,password,to,text)  → تماس صوتی متن‌به‌گفتار
 *   UploadVoiceFile(username,password,title,Base64StringFile) → آپلود فایل wav
 *   SendBulkVoiceSMS(username,password,title,voiceFileId,receivers,DateToSend,repeatCount)
 *   GetSendSMSWithSpeechTextStatus(username,password,recId) → وضعیت
 *
 * احراز هویت: username = نام کاربری حساب، password = APIKey (طبق مستند SendSpeechText).
 * چون فرمت ذخیره‌ی ما برای ملی‌پیامک «username:password» است، همان را می‌شکنیم.
 *
 * نکته: SOAP و تماس صوتی فقط روی سرور واقعی قابل تست است.
 */

class VoiceDriver
{
    private $username;
    private $password;
    const WSDL = 'http://api.payamak-panel.com/post/Voice.asmx?wsdl';

    public function __construct(string $apiKey)
    {
        // apiKey به فرمت username:password (مثل تنظیمات پیامک ملی‌پیامک)
        if (strpos($apiKey, ':') !== false) {
            [$this->username, $this->password] = explode(':', $apiKey, 2);
        } else {
            $this->username = $apiKey;
            $this->password = '';
        }
    }

    public function name(): string { return 'voice'; }
    public function capabilities(): array { return ['voice_call', 'tts', 'bulk_voice']; }

    private function client(): ?SoapClient
    {
        if (!class_exists('SoapClient')) return null;
        try {
            return new SoapClient(self::WSDL, [
                'encoding'           => 'UTF-8',
                'connection_timeout' => 20,
                'cache_wsdl'         => WSDL_CACHE_NONE,
                'exceptions'         => true,
            ]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * تماس صوتی با تبدیل متن به گفتار به یک شماره.
     * خروجی: ['ok'=>bool, 'error'=>?string, 'rec_id'=>?string]
     */
    public function callSpeech(string $to, string $text): array
    {
        // ۱.۰.۱۴: شماره در پنل 09… ذخیره می‌شود؛ وب‌سرویس صوتی همان قالب قبلی (98…) را می‌گیرد
        if (class_exists('Persons')) $to = Persons::intlPhone($to) ?? $to;
        $client = $this->client();
        if (!$client) return ['ok'=>false, 'error'=>'SOAP در دسترس نیست یا اتصال به وب‌سرویس ناموفق'];
        try {
            $res = $client->SendSpeechText([
                'username' => $this->username,
                'password' => $this->password,
                'to'       => $to,
                'text'     => $text,
            ]);
            $val = $res->SendSpeechTextResult ?? null;
            $interpreted = self::interpret((string)$val);
            if (class_exists('ApiLog')) {
                ApiLog::record('voice', 'SendSpeechText',
                    ['to'=>$to, 'text'=>mb_substr($text,0,100)],
                    ['raw_result'=>(string)$val, 'interpreted'=>$interpreted],
                    null, $interpreted['ok'] ?? false, $interpreted['error'] ?? null);
            }
            return $interpreted;
        } catch (Throwable $e) {
            if (class_exists('ApiLog')) {
                ApiLog::record('voice', 'SendSpeechText', ['to'=>$to], null, null, false, $e->getMessage());
            }
            return ['ok'=>false, 'error'=>'خطای SOAP: ' . $e->getMessage()];
        }
    }

    /** آپلود فایل صوتی wav (base64). خروجی: voiceFileId یا خطا. */
    public function uploadVoiceFile(string $title, string $base64Wav): array
    {
        $client = $this->client();
        if (!$client) return ['ok'=>false, 'error'=>'SOAP در دسترس نیست'];
        $params = [
            'username'         => (string)$this->username,
            'password'         => (string)$this->password,
            'title'            => (string)$title,
            'Base64StringFile' => (string)$base64Wav,
        ];
        try {
            $res = $client->UploadVoiceFile((object)$params);
            $val = (int)($res->UploadVoiceFileResult ?? 0);
            if (class_exists('ApiLog')) {
                ApiLog::record('voice', 'UploadVoiceFile',
                    ['username'=>$this->username, 'title'=>$title, 'base64_len'=>strlen($base64Wav), 'base64_sample'=>substr($base64Wav,0,40)],
                    ['result'=>$val], null, $val > 0, $val > 0 ? null : "code_$val");
            }
            if ($val > 0) return ['ok'=>true, 'voice_file_id'=>$val];
            $errs = [0=>'نام کاربری یا رمز اشتباه', -1=>'حجم فایل بیش از ۵ مگابایت', -3=>'فرمت فایل صحیح نیست'];
            return ['ok'=>false, 'error'=>$errs[$val] ?? "خطا ($val)"];
        } catch (Throwable $e) {
            if (class_exists('ApiLog')) {
                ApiLog::record('voice', 'UploadVoiceFile',
                    ['username'=>$this->username, 'title'=>$title, 'base64_len'=>strlen($base64Wav)],
                    null, null, false, $e->getMessage());
            }
            return ['ok'=>false, 'error'=>'خطای SOAP: ' . $e->getMessage()];
        }
    }

    /**
     * ارسال انبوه پیام صوتی با فایل آپلودشده (SendBulkVoiceSMS).
     * voiceFileId از uploadVoiceFile. DateToSend به فرمت ISO. repeatCount حداکثر ۴.
     */
    public function sendBulkVoiceFile(string $title, int $voiceFileId, string $receivers, string $dateToSend, int $repeatCount = 1): array
    {
        if (class_exists('Persons')) $receivers = implode(',', array_map(fn($p) => Persons::intlPhone(trim($p)) ?? trim($p), explode(',', $receivers)));
        $client = $this->client();
        if (!$client) return ['ok'=>false, 'error'=>'SOAP در دسترس نیست'];
        try {
            $res = $client->SendBulkVoiceSMS([
                'username'    => $this->username,
                'password'    => $this->password,
                'title'       => $title,
                'voiceFileId' => $voiceFileId,
                'receivers'   => $receivers,
                'DateToSend'  => $dateToSend,
                'repeatCount' => $repeatCount,
            ]);
            $val = (int)($res->SendBulkVoiceSMSResult ?? 0);
            if (class_exists('ApiLog')) {
                ApiLog::record('voice', 'SendBulkVoiceSMS',
                    ['title'=>$title, 'voiceFileId'=>$voiceFileId, 'receivers'=>$receivers, 'DateToSend'=>$dateToSend, 'repeatCount'=>$repeatCount],
                    ['result'=>$val], null, $val > 0, $val > 0 ? null : "code_$val");
            }
            if ($val > 0) return ['ok'=>true, 'result'=>$val];
            $errs = [0=>'نام کاربری یا رمز اشتباه', -1=>'بیش از ۱ ساعت از زمان ارسال گذشته', -2=>'ساعت نامناسب (مجاز ۸ تا ۲۲)', -3=>'تعداد گیرندگان باید بیش از ۱ نفر باشد', -4=>'اعتبار کافی نیست'];
            return ['ok'=>false, 'error'=>$errs[$val] ?? "خطا ($val)"];
        } catch (Throwable $e) {
            return ['ok'=>false, 'error'=>'خطای SOAP: ' . $e->getMessage()];
        }
    }

    /** استعلام وضعیت یک تماس با recId */
    public function status(string $recId): ?array
    {
        $client = $this->client();
        if (!$client) return null;
        try {
            $res = $client->GetSendSMSWithSpeechTextStatus([
                'username' => $this->username,
                'password' => $this->password,
                'recId'    => $recId,
            ]);
            return ['raw' => $res];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * تفسیر مقدار بازگشتی SendSpeechText.
     * عدد >10 رقم = recId (موفق). وگرنه کد خطا.
     */
    private static function interpret(string $val): array
    {
        $val = trim($val);
        // recId: عدد بلند
        if (preg_match('/^\d{10,}$/', $val)) {
            return ['ok'=>true, 'rec_id'=>$val, 'error'=>null];
        }
        $map = [
            '0'  => 'نام کاربری یا APIKey اشتباه است',
            '1'  => 'درخواست با موفقیت ثبت شد', // طبق مستند گاهی 1 موفق است
            '2'  => 'اعتبار پنل کافی نیست',
            '3'  => 'محدودیت در ارسال روزانه',
            '4'  => 'محدودیت در حجم ارسال',
            '5'  => 'شماره فرستنده معتبر نیست',
            '6'  => 'سامانه در حال به‌روزرسانی است',
            '7'  => 'متن حاوی کلمه فیلترشده است',
            '9'  => 'ارسال از خطوط عمومی ممکن نیست',
            '10' => 'کاربر فعال نیست',
            '11' => 'ارسال نشده',
            '12' => 'مدارک کاربر کامل نیست',
        ];
        if ($val === '1') return ['ok'=>true, 'rec_id'=>null, 'error'=>null];
        return ['ok'=>false, 'rec_id'=>null, 'error'=>$map[$val] ?? "کد خطا: $val"];
    }
}
