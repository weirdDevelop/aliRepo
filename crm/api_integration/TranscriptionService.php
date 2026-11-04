<?php
/**
 * جاکجاست - سرویس تبدیل صدای مکالمات به متن
 * Jakojast - Call Transcription Service
 *
 * چهار حالت پشتیبانی می‌شود (از تنظیمات قابل انتخاب است):
 *
 *   1) local  - رایگان و کاملاً آفلاین، با b7s/whisper-php (مدل medium
 *      روی خود سرور اجرا می‌شود). کیفیت متوسط، بدون هیچ هزینه‌ای.
 *      ⚠️ نیاز به کامپایل C++ (whisper.cpp) دارد - روی بعضی سرورها
 *      (مثلاً AlmaLinux 8 با GCC قدیمی) ممکن است کامپایل با خطای
 *      `undefined reference to std::filesystem` شکست بخورد.
 *
 *   2) vosk   - رایگان و کاملاً آفلاین، بدون هیچ کامپایلی (فقط یک
 *      سرویس کوچک پایتون با پکیج آماده‌ی Vosk). کیفیت کمی پایین‌تر از
 *      Whisper ولی سبک‌تر و سریع‌تر است - برای تبدیل خودکار و فوری
 *      بلافاصله بعد از قطع هر تماس مناسب‌تر است. نیاز به راه‌اندازی
 *      سرویس worker (نگاه کنید به docs/VOSK_TRANSCRIPTION_SETUP.md).
 *
 *   3) groq   - ابری، از مدل واقعی Whisper large-v3 روی سرویس Groq
 *      استفاده می‌کند (بهترین کیفیت واقعی Whisper، خیلی سریع). هزینه
 *      تقریبی: ~$0.04 در ساعت صدا (برای حجم متوسط یک کسب‌وکار،
 *      چیزی حدود چند دلار در ماه). نیاز به کلید API از console.groq.com.
 *
 *   4) openai - ابری، مدل Whisper رسمی OpenAI. کیفیت مشابه Groq ولی
 *      قیمتش تقریباً ۹ برابر بیشتر ($0.006 در دقیقه). فقط برای مقایسه/
 *      جایگزین اضطراری نگه داشته شده.
 *
 * ⚠️ نکته‌ی حریم خصوصی: در حالت‌های ابری (groq/openai)، فایل صوتی
 * مکالمه به سرور خارجی ارسال می‌شود. اگر مکالمات حاوی اطلاعات حساس
 * مشتری هستند، این را با سیاست‌های داخلی کسب‌وکار بسنجید. حالت‌های
 * local و vosk هیچ داده‌ای را از سرور شما خارج نمی‌کنند.
 */

class TranscriptionService
{
    /** مدل پیشنهادی برای Whisper محلی */
    const DEFAULT_LOCAL_MODEL = 'medium';

    /** آدرس پیش‌فرض سرویس Vosk worker (روی همون سرور، فقط localhost) */
    const DEFAULT_VOSK_URL = 'http://127.0.0.1:8899';

    /**
     * کدام روش تبدیل صدا به متن الان فعال است؟ (از تنظیمات خوانده می‌شود)
     *
     * @return string local|vosk|groq|openai
     */
    public static function getProvider()
    {
        return Report::getSetting('transcription_provider') ?: 'local';
    }

    /**
     * آیا روش انتخاب‌شده‌ی فعلی آماده‌ی استفاده است؟
     *
     * @return bool
     */
    public static function isAvailable()
    {
        $provider = self::getProvider();
        if ($provider === 'groq') {
            return !empty(Report::getSetting('groq_api_key'));
        }
        if ($provider === 'gapgpt') {
            return !empty(Report::getSetting('gapgpt_api_key'));
        }
        if ($provider === 'openai') {
            return !empty(Report::getSetting('openai_api_key'));
        }
        if ($provider === 'vosk') {
            // ⚠️ روش vosk برای تبدیل فایل به WAV به ffmpeg از طریق exec()
            // نیاز دارد. روی بعضی سرورها (به‌خصوص سرورهایی که برای امنیت
            // سخت‌گیرانه تنظیم شده‌اند) این تابع در php.ini غیرفعال است
            // (disable_functions). بدون آن، اصلاً امکان اجرای ffmpeg نیست.
            if (!self::isExecEnabled()) return false;
            return self::checkVoskWorkerHealth();
        }
        // local
        // ⚠️ namespace واقعی طبق مستندات رسمی b7s/whisper-php: WhisperPHP\Whisper
        // (نگاه کنید به توضیح کامل در callLocalWhisperLibrary) - بقیه از قبل
        // به‌عنوان fallback برای نسخه‌های احتمالاً متفاوت نگه داشته شده‌اند.
        return class_exists('\WhisperPHP\Whisper') || class_exists('\B7s\WhisperPhp\Whisper') || class_exists('\Whisper\Whisper') || class_exists('Whisper');
    }

    /**
     * پیام دقیق و درست برای هر provider وقتی isAvailable() false است -
     * جدا شده از transcribeFile() تا Call::transcribe() هم بتواند همین
     * پیام دقیق را نشان دهد (قبلاً آنجا یک پیام ثابت و گمراه‌کننده
     * «سرویس نصب نشده» بود، حتی برای providerهای ابری که فقط کلید API
     * کم داشتند).
     *
     * @return string
     */
    public static function getUnavailableMessage()
    {
        $provider = self::getProvider();
        if ($provider === 'local') {
            return 'کتابخانه‌ی Whisper محلی نصب نشده. راهنما: docs/TRANSCRIPTION_SETUP.md';
        }
        if ($provider === 'vosk') {
            return !self::isExecEnabled()
                ? 'تابع exec() روی این سرور در php.ini غیرفعال است (disable_functions) - برای اجرای ffmpeg لازم است. آن را از لیست disable_functions حذف کنید و php-fpm را ری‌استارت کنید.'
                : 'سرویس Vosk worker در دسترس نیست (' . (Report::getSetting('vosk_worker_url') ?: self::DEFAULT_VOSK_URL) . '). راهنما: docs/VOSK_TRANSCRIPTION_SETUP.md';
        }
        // groq/gapgpt/openai
        return "کلید API سرویس {$provider} در تنظیمات وارد نشده.";
    }

    /**
     * آیا تابع exec() روی این سرور فعال است؟ (هم برای ffmpeg در Vosk، هم
     * برای تریگر پس‌زمینه‌ی تبدیل خودکار بعد از هر تماس لازم است)
     *
     * @return bool
     */
    public static function isExecEnabled()
    {
        if (!function_exists('exec')) return false;
        $disabled = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
        return !in_array('exec', $disabled, true);
    }

    /**
     * چک سلامت سرویس Vosk worker (یک درخواست سبک و سریع با تایم‌اوت کوتاه،
     * چون این متد ممکن است زیاد صدا زده شود - نباید کند باشد)
     *
     * @return bool
     */
    public static function checkVoskWorkerHealth()
    {
        $url = rtrim(Report::getSetting('vosk_worker_url') ?: self::DEFAULT_VOSK_URL, '/') . '/health';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode === 200 && !empty($response);
    }

    /**
     * تبدیل یک فایل صوتی محلی به متن فارسی - بر اساس روش انتخاب‌شده در تنظیمات
     *
     * @param string $audioFilePath مسیر فایل صوتی (mp3/wav/...) روی دیسک سرور
     * @return array {success, text, message, provider}
     */
    public static function transcribeFile($audioFilePath)
    {
        if (!file_exists($audioFilePath)) {
            return ['success' => false, 'message' => 'فایل صوتی یافت نشد: ' . $audioFilePath];
        }

        $provider = self::getProvider();
        if (!self::isAvailable()) {
            return ['success' => false, 'message' => self::getUnavailableMessage()];
        }

        $startTime = microtime(true);
        try {
            switch ($provider) {
                case 'groq':
                    $text = self::transcribeViaCloudAPI($audioFilePath, 'https://api.groq.com/openai/v1/audio/transcriptions', Report::getSetting('groq_api_key'), 'whisper-large-v3');
                    break;
                case 'gapgpt':
                    // ⚠️ endpoint کاملاً هم‌شکل OpenAI است (همان transcribeViaCloudAPI
                    // قابل استفاده مجدد است) - GapGPT یه reseller/proxy ایرانی برای
                    // چند مدل API از جمله Whisper است. مدل ارزان‌تر (gapgpt/whisper-1
                    // با پیشوند gapgpt/) به‌عنوان پیش‌فرض انتخاب شده؛ اگر کاربر مدل
                    // دیگری در تنظیمات انتخاب کرده باشد همان استفاده می‌شود.
                    $gapgptModel = Report::getSetting('gapgpt_transcription_model') ?: 'gapgpt/whisper-1';
                    $text = self::transcribeViaCloudAPI($audioFilePath, 'https://api.gapgpt.app/v1/audio/transcriptions', Report::getSetting('gapgpt_api_key'), $gapgptModel);
                    break;
                case 'openai':
                    $text = self::transcribeViaCloudAPI($audioFilePath, 'https://api.openai.com/v1/audio/transcriptions', Report::getSetting('openai_api_key'), 'whisper-1');
                    break;
                case 'vosk':
                    $text = self::callVoskWorker($audioFilePath);
                    break;
                default:
                    $text = self::callLocalWhisperLibrary($audioFilePath, self::DEFAULT_LOCAL_MODEL);
            }
            $durationMs = (int)round((microtime(true) - $startTime) * 1000);
            Logger::info('Transcription completed', ['provider' => $provider, 'file' => basename($audioFilePath), 'duration_ms' => $durationMs, 'chars' => mb_strlen($text)]);
            return ['success' => true, 'text' => trim($text), 'provider' => $provider];
        } catch (\Throwable $e) {
            Logger::error('Transcription failed: ' . $e->getMessage(), ['provider' => $provider, 'file' => $audioFilePath]);
            return ['success' => false, 'message' => 'خطا در تبدیل صدا به متن (' . $provider . '): ' . $e->getMessage()];
        }
    }

    /**
     * تبدیل صدا به متن از طریق یک API ابری سازگار با فرمت OpenAI
     * (هم Groq و هم OpenAI دقیقاً همین فرمت درخواست/پاسخ را دارند)
     *
     * @param string $audioFilePath
     * @param string $endpoint
     * @param string $apiKey
     * @param string $model
     * @return string متن پیاده‌شده
     */
    private static function transcribeViaCloudAPI($audioFilePath, $endpoint, $apiKey, $model)
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS     => [
                'file'     => new \CURLFile($audioFilePath),
                'model'    => $model,
                'language' => 'fa', // فارسی صریح - دقت را بالا می‌برد
                'response_format' => 'json',
            ],
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('خطای اتصال: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($httpCode !== 200) {
            $apiMsg = $decoded['error']['message'] ?? $response;
            throw new \Exception("خطای HTTP {$httpCode}: {$apiMsg}");
        }

        return $decoded['text'] ?? '';
    }

    /**
     * فراخوانی سرویس Vosk worker (پایتون، محلی روی همین سرور).
     *
     * برخلاف whisper-php که مستقیم داخل PHP اجرا می‌شود، Vosk یک سرویس
     * HTTP جداست (چون مدل باید فقط یک‌بار لود شود، نه هر بار). این متد:
     *   ۱) فایل صوتی (mp3 از تلفنچی) را با ffmpeg به WAV تک‌کاناله ۱۶
     *      کیلوهرتزی تبدیل می‌کند (فرمتی که Vosk نیاز دارد)
     *   ۲) بدنه‌ی WAV را مستقیم POST می‌کند به {vosk_worker_url}/transcribe
     *
     * @param string $audioFilePath
     * @return string متن پیاده‌شده
     * @throws \Exception
     */
    private static function callVoskWorker($audioFilePath)
    {
        $ffmpegBin = Report::getSetting('ffmpeg_path') ?: 'ffmpeg';
        $wavPath = sys_get_temp_dir() . '/vosk_' . uniqid() . '.wav';

        $cmd = escapeshellcmd($ffmpegBin) . ' -y -i ' . escapeshellarg($audioFilePath)
            . ' -ar 16000 -ac 1 -f wav ' . escapeshellarg($wavPath) . ' 2>&1';
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($wavPath)) {
            throw new \Exception('تبدیل فایل صوتی با ffmpeg ناموفق بود: ' . implode(' | ', array_slice($output, -5)));
        }

        try {
            $url = rtrim(Report::getSetting('vosk_worker_url') ?: self::DEFAULT_VOSK_URL, '/') . '/transcribe';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => file_get_contents($wavPath),
                CURLOPT_HTTPHEADER     => ['Content-Type: audio/wav'],
                CURLOPT_TIMEOUT        => 120,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \Exception('خطای اتصال به سرویس Vosk: ' . $error);
            }
            $decoded = json_decode($response, true);
            if ($httpCode !== 200) {
                throw new \Exception("خطای HTTP {$httpCode} از سرویس Vosk: " . ($decoded['error'] ?? $response));
            }
            return $decoded['text'] ?? '';
        } finally {
            @unlink($wavPath);
        }
    }

    /**
     * فراخوانی کتابخانه‌ی Whisper محلی (b7s/whisper-php)
     *
     * ⚠️ باگ واقعی پیدا و رفع‌شده (علت اصلی نتایج نامرتبط/بی‌معنی مثل
     * «سلام وقتتون بخیر... جزیی عرض می‌کنم... نظام قضایی...» که هیچ
     * ربطی به مکالمه‌ی واقعی نداشت): طبق مستندات رسمی خودِ کتابخانه
     * (github.com/b7s/whisper-php)، متد تنظیم زبان `fromLanguage('fa')`
     * است، نه `language('fa')` که قبلاً اینجا صدا زده می‌شد. چون کلاس
     * واقعی چنین متدی به نام `language` ندارد، `method_exists($whisper,
     * 'language')` همیشه false برمی‌گشت و کد بی‌سروصدا به حالت بدون
     * تعیین زبان می‌افتاد - یعنی Whisper مجبور بود زبان را از روی صدای
     * کم‌کیفیت ۸کیلوهرتزی تلفن خودش حدس بزند. تشخیص اشتباه/نامطمئن زبان
     * دقیقاً همین الگوی کلاسیک Whisper را ایجاد می‌کند: چند کلمه‌ی اول
     * درست («سلام وقتتون بخیر») و بعد hallucination کامل (جملات روان اما
     * کاملاً بی‌ربط) - چون بدون context زبانی درست، مدل روی قسمت‌های
     * نامفهوم صدا شروع به «تولید» متن باورپذیر اما ساختگی می‌کند.
     *
     * همچنین namespace واقعی کلاس طبق مستندات `WhisperPHP\Whisper` است
     * (نه `B7s\WhisperPhp\Whisper` که قبلاً حدس زده شده بود) - آن حدس هم
     * به‌عنوان fallback نگه داشته شده، چون نسخه‌ی نصب‌شده‌ی واقعی روی سرور
     * ممکن است کمی متفاوت باشد.
     *
     * @param string $audioFilePath
     * @param string $model
     * @return string متن پیاده‌شده
     */
    private static function callLocalWhisperLibrary($audioFilePath, $model)
    {
        $candidates = ['\WhisperPHP\Whisper', '\B7s\WhisperPhp\Whisper', 'Whisper'];
        $whisperClass = null;
        foreach ($candidates as $c) {
            if (class_exists($c)) { $whisperClass = $c; break; }
        }
        if (!$whisperClass) {
            throw new \Exception('کلاس Whisper پیدا نشد (هیچ‌کدام از ' . implode(', ', $candidates) . ' موجود نیست). vendor/autoload.php را چک کنید.');
        }

        $whisper = new $whisperClass();
        if (method_exists($whisper, 'useModel')) {
            $whisper->useModel($model);
        }

        $langSet = false;
        if (method_exists($whisper, 'fromLanguage')) {
            $result = $whisper->audio($audioFilePath)->fromLanguage('fa')->toText();
            $langSet = true;
        } elseif (method_exists($whisper, 'language')) {
            // نگه‌داشته شده برای سازگاری با نسخه‌های احتمالاً متفاوت
            $result = $whisper->audio($audioFilePath)->language('fa')->toText();
            $langSet = true;
        } else {
            $result = $whisper->audio($audioFilePath)->toText();
        }

        Logger::info('callLocalWhisperLibrary: تشخیص کلاس/متد', [
            'class_used' => $whisperClass, 'model' => $model, 'persian_language_set' => $langSet,
        ]);

        return $result;
    }

    /**
     * دانلود فایل صوتی یک تماس از تلفنچی به یک فایل موقت محلی
     *
     * @param array $call  رکورد تماس (باید file_id و cuid داشته باشد)
     * @return string|null مسیر فایل موقت، یا null در صورت شکست
     */
    public static function downloadCallRecordingToTemp(array $call)
    {
        if (empty($call['file_id']) || empty($call['cuid'])) return null;

        $api = new TelefonchyAPI();
        $result = $api->downloadRecording($call['file_id'], $call['cuid']);
        if (!$result['success']) {
            Logger::error('Transcription: failed to download recording', ['call_id' => $call['id'], 'message' => $result['message'] ?? '']);
            return null;
        }

        $tempPath = sys_get_temp_dir() . '/call_' . $call['cuid'] . '_' . uniqid() . '.mp3';
        file_put_contents($tempPath, $result['content']);
        return $tempPath;
    }

    /**
     * تخمین هزینه‌ی ماهانه بر اساس حجم تماس‌های اخیر (برای نمایش در تنظیمات)
     *
     * @return array {provider, monthly_minutes, estimated_cost_usd}
     */
    public static function estimateMonthlyCost()
    {
        $provider = self::getProvider();
        $totalSeconds = (int)Database::getInstance()->fetchColumn(
            "SELECT COALESCE(SUM(time_talk), 0) FROM calls WHERE started_at >= (NOW() - INTERVAL 30 DAY) AND file_record IS NOT NULL"
        );
        $minutes = round($totalSeconds / 60);

        // ⚠️ GapGPT بر اساس توکن قیمت‌گذاری می‌کند ($۲۴-۳۰ در میلیون توکن)، نه
        // بر اساس دقیقه مثل Groq/OpenAI - چون نسبت توکن-به-دقیقه‌ی صوت برای
        // این سرویس مشخص نیست، فعلاً در این تخمین لحاظ نمی‌شود (rate=0).
        $rates = ['groq' => 0.04 / 60, 'openai' => 0.006, 'gapgpt' => 0, 'local' => 0, 'vosk' => 0];
        $rate = isset($rates[$provider]) ? $rates[$provider] : 0;

        return [
            'provider'           => $provider,
            'monthly_minutes'    => $minutes,
            'estimated_cost_usd' => round($minutes * $rate, 2),
        ];
    }
}
