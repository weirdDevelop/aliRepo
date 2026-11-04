# پچ ۲.۰.۵ — «Idempotency-Key header is required» در پاسخ عمومی کامنت

پایه: ۲.۰.۴.

## مشکل
پاسخ عمومی زیر کامنت (`instagram/send-comment` در یار API) بدون هدر `Idempotency-Key` فرستاده می‌شد و یار API آن را رد می‌کرد: «ارسال نشد: Idempotency-Key header is required [upstream_error]». دایرکتِ همان کامنت درست می‌رفت، چون آن مسیر کلید داشت.

## اصلاح
- `channels/InstagramApichiDriver.php`: پاسخ عمومی کامنت کلید می‌گیرد، و `InstagramApichiHttp::request` برای هر درخواستِ غیر GET که کلید ندارد، خودش کلید یکتا می‌سازد (فالو-استاتوس، وب‌هوک‌ها، آپلود هم پوشش داده می‌شوند).
- `index.php`: نسخه.

## بعد از نصب
کامنت‌های ناموفق خودکار دوباره فرستاده نمی‌شوند؛ برای آزمایش یک کامنت تازه بگذارید.
