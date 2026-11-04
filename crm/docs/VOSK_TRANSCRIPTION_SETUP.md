# راه‌اندازی تبدیل صدا به متن با Vosk (جایگزین سبک‌تر Whisper)

> این راهنما مخصوص سروری هست که جاکجاست روش هست (**AlmaLinux 8**، نه Ubuntu).
> برخلاف Whisper (`docs/TRANSCRIPTION_SETUP.md`)، اینجا **هیچ کامپایل C++ای لازم نیست**
> — فقط یک سرویس کوچک پایتون با پکیج آماده‌ی Vosk. دقت کمی پایین‌تر از Whisper
> است ولی سبک‌تر، سریع‌تر، و بدون دردسر کامپایلر.

## چرا این جدا از خود CRM اجرا می‌شود؟

مدل Vosk باید فقط **یک‌بار** توی حافظه لود بشه (لود کردنش خودش چند ثانیه طول
می‌کشه)؛ اگه هر بار PHP خودش مدل رو لود کنه، هر تماس چند ثانیه اضافه کند می‌شه.
برای همین یه سرویس پایتون جدا (Flask) اجرا می‌شه که مدل رو یک‌بار لود می‌کنه و
از طریق HTTP (فقط روی `127.0.0.1`، از بیرون قابل‌دسترس نیست) به PHP جواب می‌ده.

```
تماس ضبط‌شده (mp3 از تلفنچی)
        │
        ▼  (توسط CallSyncManager یا دکمه‌ی دستی)
   TranscriptionService::callVoskWorker()
        │  ffmpeg → تبدیل به WAV 16kHz mono
        ▼
   POST http://127.0.0.1:8899/transcribe   (سرویس پایتون، مدل فارسی Vosk)
        │
        ▼
   متن فارسی → ذخیره در calls.transcript
```

## مرحله‌ی ۱: نصب پیش‌نیازها (AlmaLinux 8)

```bash
# پایتون و ابزارهای پایه (احتمالاً از قبل نصبن، ضرری نداره دوباره بزنی)
dnf install -y python3 python3-pip python3-venv unzip curl

# ffmpeg توی ریپازیتوری پیش‌فرض AlmaLinux نیست - نیاز به EPEL + RPM Fusion
dnf install -y epel-release
dnf install -y --nogpgcheck https://mirrors.rpmfusion.org/free/el/rpmfusion-free-release-8.noarch.rpm
dnf install -y ffmpeg
```

اگه دستور آخر خطای گیرکردن (repo not found) داد، این‌رو امتحان کن (بعضی سرورها realm/تحریم-محور برای دانلود مستقیم rpmfusion مشکل دارن):

```bash
dnf install -y https://download1.rpmfusion.org/free/el/rpmfusion-free-release-8.noarch.rpm
dnf install -y ffmpeg
```

## مرحله‌ی ۲: نصب Vosk + دانلود مدل فارسی (به‌عنوان کاربر سایت، نه root)

```bash
su - jakojast -s /bin/bash -c '
cd /home/jakojast/domains/jakojast.com/public_html/crm
mkdir -p vosk-worker && cd vosk-worker
python3 -m venv .venv
./.venv/bin/pip install --quiet --upgrade pip
./.venv/bin/pip install --quiet vosk flask

# دانلود مدل فارسی کوچک (۵۳ مگابایت - برای سرورهای معمولی کافیه)
curl -L -o /tmp/fa-model.zip https://alphacephei.com/vosk/models/vosk-model-small-fa-0.42.zip
mkdir -p models
unzip -q /tmp/fa-model.zip -d models
rm /tmp/fa-model.zip
'
```

> اگه سرور قوی‌تری داری و دقت بالاتر می‌خوای، به‌جای بالا این مدل (۱٫۶ گیگ) رو بگیر:
> `https://alphacephei.com/vosk/models/vosk-model-fa-0.42.zip`

## مرحله‌ی ۳: کپی کردن اسکریپت سرویس

فایل `worker/server.py` (از پروژه‌ی «نویس») باید توی `vosk-worker/server.py` قرار
بگیره. این فایل رو جداگانه (خارج از این چت یا از مخزن نویس) بردار و توی این مسیر بذار:

```
/home/jakojast/domains/jakojast.com/public_html/crm/vosk-worker/server.py
```

## مرحله‌ی ۴: تست دستی (قبل از سرویس دائمی)

```bash
su - jakojast -s /bin/bash -c '
cd /home/jakojast/domains/jakojast.com/public_html/crm/vosk-worker
MODEL_DIR=$(find models -maxdepth 1 -type d -iname "vosk-model*" | head -1)
./.venv/bin/python server.py --model "$MODEL_DIR" --host 127.0.0.1 --port 8899
'
```

توی یه ترمینال دیگه تست کن:

```bash
curl http://127.0.0.1:8899/health
```

اگه یه JSON با `"status":"ok"` برگردوند، عالیه. با `Ctrl+C` متوقفش کن و برو مرحله‌ی بعد.

## مرحله‌ی ۵: راه‌اندازی به‌عنوان سرویس دائمی (systemd)

فایل `/etc/systemd/system/vosk-worker.service` رو بساز (به‌عنوان root):

```ini
[Unit]
Description=Jakojast Vosk ASR worker
After=network.target

[Service]
User=jakojast
WorkingDirectory=/home/jakojast/domains/jakojast.com/public_html/crm/vosk-worker
ExecStart=/home/jakojast/domains/jakojast.com/public_html/crm/vosk-worker/.venv/bin/python server.py --model /home/jakojast/domains/jakojast.com/public_html/crm/vosk-worker/models/vosk-model-small-fa-0.42 --host 127.0.0.1 --port 8899
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

⚠️ مسیر جلوی `--model` رو با نام واقعی پوشه‌ی مدلی که دانلود کردی جایگزین کن
(با `find models -maxdepth 1 -type d` می‌تونی دقیق ببینیش).

```bash
systemctl daemon-reload
systemctl enable --now vosk-worker
systemctl status vosk-worker
```

## مرحله‌ی ۶: فعال‌سازی توی پنل جاکجاست

۱. تنظیمات → «🎙 تبدیل صدا به متن مکالمات» → روش تبدیل رو روی **«محلی و رایگان - Vosk»** بذار.
۲. آدرس سرویس رو چک کن (پیش‌فرض `http://127.0.0.1:8899` باید درست باشه).
۳. ذخیره کن — زیرش باید عبارت «✅ سرویس Vosk worker در دسترس است» ظاهر بشه.
۴. یه تماس واقعی و ضبط‌شده بزن — چند ثانیه بعد از قطع تماس (بدون نیاز به کلیک دستی)، متنش باید توی جزئیات تماس ظاهر شده باشه.

## عیب‌یابی

| مشکل | راه‌حل |
|---|---|
| «سرویس Vosk worker در دسترس نیست» | `systemctl status vosk-worker` رو چک کن؛ `curl http://127.0.0.1:8899/health` رو دستی بزن |
| متن خالی برمی‌گرده | کیفیت ضبط تلفنچی رو چک کن؛ یا مدل بزرگ‌تر (۱٫۶ گیگ) رو امتحان کن |
| ffmpeg پیدا نشد | `which ffmpeg` بزن؛ اگه مسیرش عجیبه، توی تنظیمات یه کلید `ffmpeg_path` (از طریق دیتابیس/API) ست کن |
| بعد از تماس متن نمیاد ولی دکمه‌ی دستی کار می‌کنه | یعنی enrichment فوری `file_id` بعد از hangup شکست خورده - لاگ «📋 لاگ تعاملات با تلفنچی» رو چک کن |
