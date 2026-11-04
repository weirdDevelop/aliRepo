# پچ ۲.۰.۶ — «Idempotency-Key header is required» (درایور روبوکلیک)

پایه: ۲.۰.۵ (یا ۲.۰.۴؛ هر دو فایل درایور در این پچ هست).

## مشکل
پیج شما با سرویس «روبوکلیک» وصل است، نه یار API. درایور آن فقط برای چند عمل (دایرکت، ویترین، ویس…) هدر `Idempotency-Key` می‌فرستاد و پاسخ عمومی زیر کامنت و آپلود فایل را بدون آن می‌فرستاد؛ سرویس هم «Idempotency-Key header is required» برمی‌گرداند. پچ ۲.۰.۵ فقط درایور یار API را درست کرده بود.

## اصلاح
- `channels/InstagramRoboclickDriver.php`: هر درخواست غیر GET و هر آپلود، کلید یکتا می‌فرستد.
- `channels/InstagramApichiDriver.php` (از ۲.۰.۵): همین رفتار برای یار API.
- `index.php`: نسخه.
