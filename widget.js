/*!
 * widget.js — ابزارهای سایت اعلان‌بان: دکمه‌های شناور تماس + گفتگوی آنلاین
 *
 * نصب در سایت (یک خط، برای هر دو ابزار):
 *   <script src="https://panel.example.com/widget.js" async></script>
 *
 * نکته‌های طراحی:
 *   ۱) Shadow DOM: استایل سایت روی ویجت اثر ندارد و برعکس.
 *   ۲) آدرس سرور از src خود اسکریپت خوانده می‌شود.
 *   ۳) نشست گفتگو فقط وقتی ساخته می‌شود که بازدیدکننده پنجره را باز کند (یا گفتگوی قبلی داشته باشد)؛
 *      بازدید ساده هیچ ردیفی در دیتابیس نمی‌سازد.
 *   ۴) حالت پیش‌نمایش پنل: اگر window.EBW_PREVIEW تعریف شده باشد، بدون سرور و با همان تنظیمات رندر می‌شود.
 */
(function () {
  'use strict';
  if (window.__ebWidgetLoaded) return;
  window.__ebWidgetLoaded = true;

  var me = document.currentScript || (function () { var s = document.getElementsByTagName('script'); return s[s.length - 1]; })();
  var BASE = (me && me.src) ? me.src.replace(/\/widget\.js.*$/, '') : '';
  var SITE = (me && me.getAttribute && me.getAttribute('data-site')) || (me && me.src && (/[?&]site=([^&#]+)/.exec(me.src) || [])[1]) || '';   // ۲.۱.۱: کلید سایت (چندسایته)
  var SQ = SITE ? '&site=' + encodeURIComponent(SITE) : '';
  var ENDPOINT = BASE + '/chat.php';
  var PREVIEW = window.EBW_PREVIEW || null;

  var IC={"chat-circle-dots":"<path d=\"M140,128a12,12,0,1,1-12-12A12,12,0,0,1,140,128ZM84,116a12,12,0,1,0,12,12A12,12,0,0,0,84,116Zm88,0a12,12,0,1,0,12,12A12,12,0,0,0,172,116Zm60,12A104,104,0,0,1,79.12,219.82L45.07,231.17a16,16,0,0,1-20.24-20.24l11.35-34.05A104,104,0,1,1,232,128Zm-16,0A88,88,0,1,0,51.81,172.06a8,8,0,0,1,.66,6.54L40,216,77.4,203.53a7.85,7.85,0,0,1,2.53-.42,8,8,0,0,1,4,1.08A88,88,0,0,0,216,128Z\"></path>","chat-teardrop-dots":"<path d=\"M132,24A100.11,100.11,0,0,0,32,124v84a16,16,0,0,0,16,16h84a100,100,0,0,0,0-200Zm0,184H48V124a84,84,0,1,1,84,84Zm12-80a12,12,0,1,1-12-12A12,12,0,0,1,144,128Zm-44,0a12,12,0,1,1-12-12A12,12,0,0,1,100,128Zm88,0a12,12,0,1,1-12-12A12,12,0,0,1,188,128Z\"></path>","headset":"<path d=\"M201.89,54.66A103.43,103.43,0,0,0,128.79,24H128A104,104,0,0,0,24,128v56a24,24,0,0,0,24,24H64a24,24,0,0,0,24-24V144a24,24,0,0,0-24-24H40.36A88.12,88.12,0,0,1,190.54,65.93,87.39,87.39,0,0,1,215.65,120H192a24,24,0,0,0-24,24v40a24,24,0,0,0,24,24h24a24,24,0,0,1-24,24H136a8,8,0,0,0,0,16h56a40,40,0,0,0,40-40V128A103.41,103.41,0,0,0,201.89,54.66ZM64,136a8,8,0,0,1,8,8v40a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V136Zm128,56a8,8,0,0,1-8-8V144a8,8,0,0,1,8-8h24v56Z\"></path>","phone":"<path d=\"M222.37,158.46l-47.11-21.11-.13-.06a16,16,0,0,0-15.17,1.4,8.12,8.12,0,0,0-.75.56L134.87,160c-15.42-7.49-31.34-23.29-38.83-38.51l20.78-24.71c.2-.25.39-.5.57-.77a16,16,0,0,0,1.32-15.06l0-.12L97.54,33.64a16,16,0,0,0-16.62-9.52A56.26,56.26,0,0,0,32,80c0,79.4,64.6,144,144,144a56.26,56.26,0,0,0,55.88-48.92A16,16,0,0,0,222.37,158.46ZM176,208A128.14,128.14,0,0,1,48,80,40.2,40.2,0,0,1,82.87,40a.61.61,0,0,0,0,.12l21,47L83.2,111.86a6.13,6.13,0,0,0-.57.77,16,16,0,0,0-1,15.7c9.06,18.53,27.73,37.06,46.46,46.11a16,16,0,0,0,15.75-1.14,8.44,8.44,0,0,0,.74-.56L168.89,152l47,21.05h0s.08,0,.11,0A40.21,40.21,0,0,1,176,208Z\"></path>","plus":"<path d=\"M224,128a8,8,0,0,1-8,8H136v80a8,8,0,0,1-16,0V136H40a8,8,0,0,1,0-16h80V40a8,8,0,0,1,16,0v80h80A8,8,0,0,1,224,128Z\"></path>","question":"<path d=\"M140,180a12,12,0,1,1-12-12A12,12,0,0,1,140,180ZM128,72c-22.06,0-40,16.15-40,36v4a8,8,0,0,0,16,0v-4c0-11,10.77-20,24-20s24,9,24,20-10.77,20-24,20a8,8,0,0,0-8,8v8a8,8,0,0,0,16,0v-.72c18.24-3.35,32-17.9,32-35.28C168,88.15,150.06,72,128,72Zm104,56A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z\"></path>","hand-waving":"<path d=\"M220.17,100,202.86,70a28,28,0,0,0-38.24-10.25,27.69,27.69,0,0,0-9,8.34L138.2,38a28,28,0,0,0-48.48,0A28,28,0,0,0,48.15,74l1.59,2.76A27.67,27.67,0,0,0,38,80.41a28,28,0,0,0-10.24,38.25l40,69.32a87.47,87.47,0,0,0,53.43,41,88.56,88.56,0,0,0,22.92,3,88,88,0,0,0,76.06-132Zm-6.66,62.64A72,72,0,0,1,81.62,180l-40-69.32a12,12,0,0,1,20.78-12L81.63,132a8,8,0,1,0,13.85-8L62,66A12,12,0,1,1,82.78,54L114,108a8,8,0,1,0,13.85-8L103.57,58h0a12,12,0,1,1,20.78-12l33.42,57.9a48,48,0,0,0-5.54,60.6,8,8,0,0,0,13.24-9A32,32,0,0,1,172.78,112a8,8,0,0,0,2.13-10.4L168.23,90A12,12,0,1,1,189,78l17.31,30A71.56,71.56,0,0,1,213.51,162.62ZM184.25,31.71A8,8,0,0,1,194,26a59.62,59.62,0,0,1,36.53,28l.33.57a8,8,0,1,1-13.85,8l-.33-.57a43.67,43.67,0,0,0-26.8-20.5A8,8,0,0,1,184.25,31.71ZM80.89,237a8,8,0,0,1-11.23,1.33A119.56,119.56,0,0,1,40.06,204a8,8,0,0,1,13.86-8,103.67,103.67,0,0,0,25.64,29.72A8,8,0,0,1,80.89,237Z\"></path>","paper-plane-tilt":"<path d=\"M227.32,28.68a16,16,0,0,0-15.66-4.08l-.15,0L19.57,82.84a16,16,0,0,0-2.49,29.8L102,154l41.3,84.87A15.86,15.86,0,0,0,157.74,248q.69,0,1.38-.06a15.88,15.88,0,0,0,14-11.51l58.2-191.94c0-.05,0-.1,0-.15A16,16,0,0,0,227.32,28.68ZM157.83,231.85l-.05.14,0-.07-40.06-82.3,48-48a8,8,0,0,0-11.31-11.31l-48,48L24.08,98.25l-.07,0,.14,0L216,40Z\"></path>","lifebuoy":"<path d=\"M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm39.1,131.79a47.84,47.84,0,0,0,0-55.58l28.5-28.49a87.83,87.83,0,0,1,0,112.56ZM96,128a32,32,0,1,1,32,32A32,32,0,0,1,96,128Zm88.28-67.6L155.79,88.9a47.84,47.84,0,0,0-55.58,0L71.72,60.4a87.83,87.83,0,0,1,112.56,0ZM60.4,71.72l28.5,28.49a47.84,47.84,0,0,0,0,55.58L60.4,184.28a87.83,87.83,0,0,1,0-112.56ZM71.72,195.6l28.49-28.5a47.84,47.84,0,0,0,55.58,0l28.49,28.5a87.83,87.83,0,0,1-112.56,0Z\"></path>","chats-circle":"<path d=\"M232.07,186.76a80,80,0,0,0-62.5-114.17A80,80,0,1,0,23.93,138.76l-7.27,24.71a16,16,0,0,0,19.87,19.87l24.71-7.27a80.39,80.39,0,0,0,25.18,7.35,80,80,0,0,0,108.34,40.65l24.71,7.27a16,16,0,0,0,19.87-19.86ZM62,159.5a8.28,8.28,0,0,0-2.26.32L32,168l8.17-27.76a8,8,0,0,0-.63-6,64,64,0,1,1,26.26,26.26A8,8,0,0,0,62,159.5Zm153.79,28.73L224,216l-27.76-8.17a8,8,0,0,0-6,.63,64.05,64.05,0,0,1-85.87-24.88A79.93,79.93,0,0,0,174.7,89.71a64,64,0,0,1,41.75,92.48A8,8,0,0,0,215.82,188.23Z\"></path>","storefront":"<path d=\"M232,96a7.89,7.89,0,0,0-.3-2.2L217.35,43.6A16.07,16.07,0,0,0,202,32H54A16.07,16.07,0,0,0,38.65,43.6L24.31,93.8A7.89,7.89,0,0,0,24,96h0v16a40,40,0,0,0,16,32v72a8,8,0,0,0,8,8H208a8,8,0,0,0,8-8V144a40,40,0,0,0,16-32V96ZM54,48H202l11.42,40H42.61Zm50,56h48v8a24,24,0,0,1-48,0Zm-16,0v8a24,24,0,0,1-35.12,21.26,7.88,7.88,0,0,0-1.82-1.06A24,24,0,0,1,40,112v-8ZM200,208H56V151.2a40.57,40.57,0,0,0,8,.8,40,40,0,0,0,32-16,40,40,0,0,0,64,0,40,40,0,0,0,32,16,40.57,40.57,0,0,0,8-.8Zm4.93-75.8a8.08,8.08,0,0,0-1.8,1.05A24,24,0,0,1,168,112v-8h48v8A24,24,0,0,1,204.93,132.2Z\"></path>","sparkle":"<path d=\"M197.58,129.06,146,110l-19-51.62a15.92,15.92,0,0,0-29.88,0L78,110l-51.62,19a15.92,15.92,0,0,0,0,29.88L78,178l19,51.62a15.92,15.92,0,0,0,29.88,0L146,178l51.62-19a15.92,15.92,0,0,0,0-29.88ZM137,164.22a8,8,0,0,0-4.74,4.74L112,223.85,91.78,169A8,8,0,0,0,87,164.22L32.15,144,87,123.78A8,8,0,0,0,91.78,119L112,64.15,132.22,119a8,8,0,0,0,4.74,4.74L191.85,144ZM144,40a8,8,0,0,1,8-8h16V16a8,8,0,0,1,16,0V32h16a8,8,0,0,1,0,16H184V64a8,8,0,0,1-16,0V48H152A8,8,0,0,1,144,40ZM248,88a8,8,0,0,1-8,8h-8v8a8,8,0,0,1-16,0V96h-8a8,8,0,0,1,0-16h8V72a8,8,0,0,1,16,0v8h8A8,8,0,0,1,248,88Z\"></path>","whatsapp-logo":"<path d=\"M187.58,144.84l-32-16a8,8,0,0,0-8,.5l-14.69,9.8a40.55,40.55,0,0,1-16-16l9.8-14.69a8,8,0,0,0,.5-8l-16-32A8,8,0,0,0,104,64a40,40,0,0,0-40,40,88.1,88.1,0,0,0,88,88,40,40,0,0,0,40-40A8,8,0,0,0,187.58,144.84ZM152,176a72.08,72.08,0,0,1-72-72A24,24,0,0,1,99.29,80.46l11.48,23L101,118a8,8,0,0,0-.73,7.51,56.47,56.47,0,0,0,30.15,30.15A8,8,0,0,0,138,155l14.61-9.74,23,11.48A24,24,0,0,1,152,176ZM128,24A104,104,0,0,0,36.18,176.88L24.83,210.93a16,16,0,0,0,20.24,20.24l34.05-11.35A104,104,0,1,0,128,24Zm0,192a87.87,87.87,0,0,1-44.06-11.81,8,8,0,0,0-6.54-.67L40,216,52.47,178.6a8,8,0,0,0-.66-6.54A88,88,0,1,1,128,216Z\"></path>","telegram-logo":"<path d=\"M228.88,26.19a9,9,0,0,0-9.16-1.57L17.06,103.93a14.22,14.22,0,0,0,2.43,27.21L72,141.45V200a15.92,15.92,0,0,0,10,14.83,15.91,15.91,0,0,0,17.51-3.73l25.32-26.26L165,220a15.88,15.88,0,0,0,10.51,4,16.3,16.3,0,0,0,5-.79,15.85,15.85,0,0,0,10.67-11.63L231.77,35A9,9,0,0,0,228.88,26.19Zm-61.14,36L78.15,126.35l-49.6-9.73ZM88,200V152.52l24.79,21.74Zm87.53,8L92.85,135.5l119-85.29Z\"></path>","chat-centered-dots":"<path d=\"M116,120a12,12,0,1,1,12,12A12,12,0,0,1,116,120ZM84,132a12,12,0,1,0-12-12A12,12,0,0,0,84,132Zm88,0a12,12,0,1,0-12-12A12,12,0,0,0,172,132Zm60-76V184a16,16,0,0,1-16,16H155.57l-13.68,23.94a16,16,0,0,1-27.78,0L100.43,200H40a16,16,0,0,1-16-16V56A16,16,0,0,1,40,40H216A16,16,0,0,1,232,56Zm-16,0H40V184h65.07a8,8,0,0,1,7,4l16,28,16-28a8,8,0,0,1,7-4H216Z\"></path>","chat-circle-text":"<path d=\"M168,112a8,8,0,0,1-8,8H96a8,8,0,0,1,0-16h64A8,8,0,0,1,168,112Zm-8,24H96a8,8,0,0,0,0,16h64a8,8,0,0,0,0-16Zm72-8A104,104,0,0,1,79.12,219.82L45.07,231.17a16,16,0,0,1-20.24-20.24l11.35-34.05A104,104,0,1,1,232,128Zm-16,0A88,88,0,1,0,51.81,172.06a8,8,0,0,1,.66,6.54L40,216,77.4,203.53a7.85,7.85,0,0,1,2.53-.42,8,8,0,0,1,4,1.08A88,88,0,0,0,216,128Z\"></path>","instagram-logo":"<path d=\"M128,80a48,48,0,1,0,48,48A48.05,48.05,0,0,0,128,80Zm0,80a32,32,0,1,1,32-32A32,32,0,0,1,128,160ZM176,24H80A56.06,56.06,0,0,0,24,80v96a56.06,56.06,0,0,0,56,56h96a56.06,56.06,0,0,0,56-56V80A56.06,56.06,0,0,0,176,24Zm40,152a40,40,0,0,1-40,40H80a40,40,0,0,1-40-40V80A40,40,0,0,1,80,40h96a40,40,0,0,1,40,40ZM192,76a12,12,0,1,1-12-12A12,12,0,0,1,192,76Z\"></path>","chat-text":"<path d=\"M216,48H40A16,16,0,0,0,24,64V224a15.85,15.85,0,0,0,9.24,14.5A16.13,16.13,0,0,0,40,240a15.89,15.89,0,0,0,10.25-3.78l.09-.07L83,208H216a16,16,0,0,0,16-16V64A16,16,0,0,0,216,48ZM40,224h0ZM216,192H80a8,8,0,0,0-5.23,1.95L40,224V64H216ZM88,112a8,8,0,0,1,8-8h64a8,8,0,0,1,0,16H96A8,8,0,0,1,88,112Zm0,32a8,8,0,0,1,8-8h64a8,8,0,1,1,0,16H96A8,8,0,0,1,88,144Z\"></path>","envelope-simple":"<path d=\"M224,48H32a8,8,0,0,0-8,8V192a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V56A8,8,0,0,0,224,48ZM203.43,64,128,133.15,52.57,64ZM216,192H40V74.19l82.59,75.71a8,8,0,0,0,10.82,0L216,74.19V192Z\"></path>","map-pin":"<path d=\"M128,64a40,40,0,1,0,40,40A40,40,0,0,0,128,64Zm0,64a24,24,0,1,1,24-24A24,24,0,0,1,128,128Zm0-112a88.1,88.1,0,0,0-88,88c0,31.4,14.51,64.68,42,96.25a254.19,254.19,0,0,0,41.45,38.3,8,8,0,0,0,9.18,0A254.19,254.19,0,0,0,174,200.25c27.45-31.57,42-64.85,42-96.25A88.1,88.1,0,0,0,128,16Zm0,206c-16.53-13-72-60.75-72-118a72,72,0,0,1,144,0C200,161.23,144.53,209,128,222Z\"></path>","link-simple":"<path d=\"M165.66,90.34a8,8,0,0,1,0,11.32l-64,64a8,8,0,0,1-11.32-11.32l64-64A8,8,0,0,1,165.66,90.34ZM215.6,40.4a56,56,0,0,0-79.2,0L106.34,70.45a8,8,0,0,0,11.32,11.32l30.06-30a40,40,0,0,1,56.57,56.56l-30.07,30.06a8,8,0,0,0,11.31,11.32L215.6,119.6a56,56,0,0,0,0-79.2ZM138.34,174.22l-30.06,30.06a40,40,0,1,1-56.56-56.57l30.05-30.05a8,8,0,0,0-11.32-11.32L40.4,136.4a56,56,0,0,0,79.2,79.2l30.06-30.07a8,8,0,0,0-11.32-11.31Z\"></path>","x":"<path d=\"M205.66,194.34a8,8,0,0,1-11.32,11.32L128,139.31,61.66,205.66a8,8,0,0,1-11.32-11.32L116.69,128,50.34,61.66A8,8,0,0,1,61.66,50.34L128,116.69l66.34-66.35a8,8,0,0,1,11.32,11.32L139.31,128Z\"></path>","paper-plane-right":"<path d=\"M231.87,114l-168-95.89A16,16,0,0,0,40.92,37.34L71.55,128,40.92,218.67A16,16,0,0,0,56,240a16.15,16.15,0,0,0,7.93-2.1l167.92-96.05a16,16,0,0,0,.05-27.89ZM56,224a.56.56,0,0,0,0-.12L85.74,136H144a8,8,0,0,0,0-16H85.74L56.06,32.16A.46.46,0,0,0,56,32l168,95.83Z\"></path>","caret-down":"<path d=\"M213.66,101.66l-80,80a8,8,0,0,1-11.32,0l-80-80A8,8,0,0,1,53.66,90.34L128,164.69l74.34-74.35a8,8,0,0,1,11.32,11.32Z\"></path>","calendar-blank":"<path d=\"M208,32H184V24a8,8,0,0,0-16,0v8H88V24a8,8,0,0,0-16,0v8H48A16,16,0,0,0,32,48V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V48A16,16,0,0,0,208,32ZM72,48v8a8,8,0,0,0,16,0V48h80v8a8,8,0,0,0,16,0V48h24V80H48V48ZM208,208H48V96H208V208Z\"></path>","caret-left":"<path d=\"M165.66,202.34a8,8,0,0,1-11.32,11.32l-80-80a8,8,0,0,1,0-11.32l80-80a8,8,0,0,1,11.32,11.32L91.31,128Z\"></path>","caret-right":"<path d=\"M181.66,133.66l-80,80a8,8,0,0,1-11.32-11.32L164.69,128,90.34,53.66a8,8,0,0,1,11.32-11.32l80,80A8,8,0,0,1,181.66,133.66Z\"></path>","arrow-square-out":"<path d=\"M224,104a8,8,0,0,1-16,0V59.32l-66.33,66.34a8,8,0,0,1-11.32-11.32L196.68,48H152a8,8,0,0,1,0-16h64a8,8,0,0,1,8,8Zm-40,24a8,8,0,0,0-8,8v72H48V80h72a8,8,0,0,0,0-16H48A16,16,0,0,0,32,80V208a16,16,0,0,0,16,16H176a16,16,0,0,0,16-16V136A8,8,0,0,0,184,128Z\"></path>","copy":"<path d=\"M216,32H88a8,8,0,0,0-8,8V80H40a8,8,0,0,0-8,8V216a8,8,0,0,0,8,8H168a8,8,0,0,0,8-8V176h40a8,8,0,0,0,8-8V40A8,8,0,0,0,216,32ZM160,208H48V96H160Zm48-48H176V88a8,8,0,0,0-8-8H96V48H208Z\"></path>","device-mobile":"<path d=\"M176,16H80A24,24,0,0,0,56,40V216a24,24,0,0,0,24,24h96a24,24,0,0,0,24-24V40A24,24,0,0,0,176,16Zm8,200a8,8,0,0,1-8,8H80a8,8,0,0,1-8-8V40a8,8,0,0,1,8-8h96a8,8,0,0,1,8,8ZM140,60a12,12,0,1,1-12-12A12,12,0,0,1,140,60Z\"></path>","phone-call":"<path d=\"M144.27,45.93a8,8,0,0,1,9.8-5.66,86.22,86.22,0,0,1,61.66,61.66,8,8,0,0,1-5.66,9.8A8.23,8.23,0,0,1,208,112a8,8,0,0,1-7.73-5.94,70.35,70.35,0,0,0-50.33-50.33A8,8,0,0,1,144.27,45.93Zm-2.33,41.8c13.79,3.68,22.65,12.54,26.33,26.33A8,8,0,0,0,176,120a8.23,8.23,0,0,0,2.07-.27,8,8,0,0,0,5.66-9.8c-5.12-19.16-18.5-32.54-37.66-37.66a8,8,0,1,0-4.13,15.46Zm81.94,95.35A56.26,56.26,0,0,1,168,232C88.6,232,24,167.4,24,88A56.26,56.26,0,0,1,72.92,32.12a16,16,0,0,1,16.62,9.52l21.12,47.15,0,.12A16,16,0,0,1,109.39,104c-.18.27-.37.52-.57.77L88,129.45c7.49,15.22,23.41,31,38.83,38.51l24.34-20.71a8.12,8.12,0,0,1,.75-.56,16,16,0,0,1,15.17-1.4l.13.06,47.11,21.11A16,16,0,0,1,223.88,183.08Zm-15.88-2s-.07,0-.11,0h0l-47-21.05-24.35,20.71a8.44,8.44,0,0,1-.74.56,16,16,0,0,1-15.75,1.14c-18.73-9.05-37.4-27.58-46.46-46.11a16,16,0,0,1,1-15.7,6.13,6.13,0,0,1,.57-.77L96,95.15l-21-47a.61.61,0,0,1,0-.12A40.2,40.2,0,0,0,40,88,128.14,128.14,0,0,0,168,216,40.21,40.21,0,0,0,208,181.07Z\"></path>","arrows-out":"<path d=\"M216,48V96a8,8,0,0,1-16,0V67.31l-50.34,50.35a8,8,0,0,1-11.32-11.32L188.69,56H160a8,8,0,0,1,0-16h48A8,8,0,0,1,216,48ZM106.34,138.34,56,188.69V160a8,8,0,0,0-16,0v48a8,8,0,0,0,8,8H96a8,8,0,0,0,0-16H67.31l50.35-50.34a8,8,0,0,0-11.32-11.32Z\"></path>","check":"<path d=\"M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z\"></path>","user":"<path d=\"M230.92,212c-15.23-26.33-38.7-45.21-66.09-54.16a72,72,0,1,0-73.66,0C63.78,166.78,40.31,185.66,25.08,212a8,8,0,1,0,13.85,8c18.84-32.56,52.14-52,89.07-52s70.23,19.44,89.07,52a8,8,0,1,0,13.85-8ZM72,96a56,56,0,1,1,56,56A56.06,56.06,0,0,1,72,96Z\"></path>"};

  var TOKEN_KEY = 'ebw_token', LASTID_KEY = 'ebw_last_id';
  var host = null, root = null, CFG = null;
  var chat = { token: null, lastId: 0, open: false, sending: false, identified: false, askedIdentity: false, unread: 0, pollTimer: null, slowTimer: null, started: false, el: {} };

  /* ------------------------------------------------------------ کمکی‌ها */
  function esc(t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function svg(name, cls) { return '<svg class="' + (cls || 'i') + '" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true">' + (IC[name] || IC['chat-circle-dots']) + '</svg>'; }
  function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function sstore(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
  function sload(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function isMobile() { return window.matchMedia ? window.matchMedia('(max-width: 767px)').matches : window.innerWidth < 768; }
  function img(rel) { return /^https?:|^data:/.test(rel) ? rel : BASE + '/' + rel; }
  function hexToRgb(h) { var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(h || ''); return m ? [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)] : [29, 92, 77]; }
  function rgba(h, a) { var c = hexToRgb(h); return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }
  function readable(h) { var c = hexToRgb(h); return (c[0] * 299 + c[1] * 587 + c[2] * 114) / 1000 > 160 ? '#1F2A26' : '#FFFFFF'; }

  function request(action, data, done) {
    if (PREVIEW) { done({ ok: false, preview: true }); return; }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', ENDPOINT + '?wc=' + encodeURIComponent(action) + SQ, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      var res = null; try { res = JSON.parse(xhr.responseText); } catch (e) {}
      done(res || { ok: false, error: 'ارتباط با سرور برقرار نشد' });
    };
    var body = data || {}; body.wc = action;
    try { xhr.send(JSON.stringify(body)); } catch (e) { done({ ok: false, error: 'ارتباط با سرور برقرار نشد' }); }
  }

  /** قوانین نمایش: دستگاه، صفحه‌ها */
  function pathMatch(list) {
    var lines = String(list || '').split(/[\r\n,]+/).map(function (x) { return x.trim(); }).filter(Boolean);
    if (!lines.length) return null;
    var url = location.pathname + location.search;
    for (var i = 0; i < lines.length; i++) {
      var p = lines[i];
      if (p.indexOf('*') > -1) {
        var re = new RegExp('^' + p.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');
        if (re.test(location.pathname) || re.test(url)) return true;
      } else if (url.indexOf(p) > -1) return true;
    }
    return false;
  }
  function allowed(r) {
    if (!r) return true;
    if (r.devices === 'mobile' && !isMobile()) return false;
    if (r.devices === 'desktop' && isMobile()) return false;
    if (r.include && pathMatch(r.include) === false) return false;
    if (r.exclude && pathMatch(r.exclude) === true) return false;
    if (r.hours_only && CFG && CFG.online === false) return false;
    return true;
  }

  function beep() {
    try {
      var A = window.AudioContext || window.webkitAudioContext; if (!A) return;
      var ctx = new A(), o = ctx.createOscillator(), g = ctx.createGain();
      o.type = 'sine'; o.frequency.value = 880; g.gain.value = 0.0001;
      o.connect(g); g.connect(ctx.destination); o.start();
      g.gain.exponentialRampToValueAtTime(0.12, ctx.currentTime + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
      o.stop(ctx.currentTime + 0.4);
    } catch (e) {}
  }

  /* --------------------------------------------------------------- استایل */
  function shapeRadius(shape, size) { return shape === 'square' ? '10px' : shape === 'rounded' ? Math.round(size * 0.3) + 'px' : '999px'; }

  function css() {
    var f = CFG.fab, c = CFG.chat, d = c ? c.design : null;
    var font = (d && d.font.family) ? esc(d.font.family) + ',' : '';
    var out = [
      ':host{all:initial}',
      '*{box-sizing:border-box;font-family:' + font + 'Vazirmatn,"Vazir",Tahoma,system-ui,sans-serif;-webkit-tap-highlight-color:transparent}',
      'button{font:inherit;cursor:pointer}',
      '.i{width:55%;height:55%;display:block}',
      '@keyframes ebw-pulse{0%{box-shadow:0 0 0 0 var(--pc)}70%{box-shadow:0 0 0 14px rgba(0,0,0,0)}100%{box-shadow:0 0 0 0 rgba(0,0,0,0)}}',
      '@keyframes ebw-bounce{0%,100%{transform:translateY(0)}30%{transform:translateY(-8px)}50%{transform:translateY(0)}65%{transform:translateY(-4px)}}',
      '@keyframes ebw-shake{0%,100%{transform:rotate(0)}20%{transform:rotate(-12deg)}40%{transform:rotate(10deg)}60%{transform:rotate(-6deg)}80%{transform:rotate(4deg)}}',
      '@keyframes ebw-glow{0%,100%{filter:drop-shadow(0 0 0 transparent)}50%{filter:drop-shadow(0 0 10px var(--pc))}}',
      '@keyframes ebw-wiggle{0%,90%,100%{transform:scale(1)}93%{transform:scale(1.12)}96%{transform:scale(.96)}}',
      '@keyframes ebw-dot{0%,60%,100%{opacity:.25;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}',
      '@keyframes ebw-in{from{opacity:0;transform:translateY(8px) scale(.96)}to{opacity:1;transform:none}}',
      '.an-pulse{animation:ebw-pulse 2.2s infinite}.an-bounce{animation:ebw-bounce 2.6s infinite}.an-shake{animation:ebw-shake 3s infinite}',
      '.an-glow{animation:ebw-glow 2.4s infinite}.an-wiggle{animation:ebw-wiggle 4s infinite}',
      '@media (prefers-reduced-motion:reduce){.an-pulse,.an-bounce,.an-shake,.an-glow,.an-wiggle{animation:none}}',
      '.dock{position:fixed;z-index:2147483000;direction:rtl;display:flex;flex-direction:column;align-items:flex-end;gap:12px}',
      '.dock.left{align-items:flex-start}',
      '.lhint{position:absolute;bottom:50%;transform:translateY(50%);white-space:nowrap;background:#fff;color:#1F2A26;font-size:13px;font-weight:600;padding:7px 12px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.16);pointer-events:none;opacity:0;transition:opacity .18s}',
      '.dock.right .lhint{right:calc(100% + 10px)}.dock.left .lhint{left:calc(100% + 10px)}',
      '.dock:hover .lhint,.lhint.on{opacity:1}',
      '.greet{position:relative;max-width:240px;background:#fff;color:#1F2A26;border-radius:14px;padding:10px 32px 10px 14px;font-size:13px;line-height:1.7;box-shadow:0 10px 30px rgba(0,0,0,.16);animation:ebw-in .25s ease;cursor:pointer}',
      '.greet .gx{position:absolute;top:6px;right:6px;width:20px;height:20px;border:0;border-radius:50%;background:#EEF0EC;color:#5A6560;display:grid;place-items:center;padding:0}',
      '.greet .gx svg{width:11px;height:11px}',
      '.left .greet{padding:10px 14px 10px 32px}.left .greet .gx{right:auto;left:6px}',
      '.badge{position:absolute;top:-3px;inset-inline-end:-3px;min-width:19px;height:19px;padding:0 5px;border-radius:10px;background:#D0342C;color:#fff;font-size:11px;line-height:19px;text-align:center;font-weight:700;box-shadow:0 0 0 2px #fff}'
    ];
    if (f) {
      var M = f.main, I = f.items;
      out.push(
        '.fab-main{position:relative;--pc:' + rgba(M.color, .45) + ';height:' + M.size + 'px;min-width:' + M.size + 'px;border:0;border-radius:' + shapeRadius(M.shape, M.size) + ';background:' + M.color + ';color:' + M.text_color + ';display:flex;align-items:center;justify-content:center;gap:8px;padding:0 ' + (M.shape === 'pill' ? '20px' : '0') + ';box-shadow:0 10px 28px ' + rgba(M.color, .38) + ';transition:transform .18s,box-shadow .18s}',
        '.fab-main:hover{transform:translateY(-2px)}',
        '.fab-main .i{width:' + Math.round(M.size * .48) + 'px;height:' + Math.round(M.size * .48) + 'px;transition:transform .25s}',
        '.fab-main img{width:100%;height:100%;object-fit:cover;border-radius:inherit}',
        '.fab-main .ml{font-size:14px;font-weight:700;white-space:nowrap}',
        '.fab-main.open .i{transform:rotate(90deg)}',
        '.fab-tip{position:absolute;top:50%;transform:translateY(-50%);right:calc(100% + 10px);background:' + I.label_bg + ';color:' + I.label_text + ';font-size:13px;font-weight:600;white-space:nowrap;padding:6px 11px;border-radius:9px;box-shadow:0 6px 18px rgba(0,0,0,.14);pointer-events:none;opacity:0;transition:opacity .15s}',
        '.left .fab-tip{right:auto;left:calc(100% + 10px)}',
        '.fab-main:hover .fab-tip.hov{opacity:1}',
        '.fab-list{display:flex;flex-direction:column;gap:' + I.gap + 'px;align-items:inherit}',
        '.fab-list.menu{display:none}.fab-list.menu.on{display:flex}',
        '.fab-list.menu.on .fab-it{animation:ebw-in .22s ease both}',
        '.fab-it{position:relative;display:flex;align-items:center;gap:10px;text-decoration:none;flex-direction:row}',
        '.left .fab-it{flex-direction:row-reverse}',
        '.fab-ic{width:' + I.size + 'px;height:' + I.size + 'px;border-radius:' + shapeRadius(I.shape, I.size) + ';display:grid;place-items:center;color:#fff;box-shadow:0 6px 18px rgba(0,0,0,.18);flex:0 0 auto;transition:transform .15s}',
        '.fab-it:hover .fab-ic{transform:scale(1.07)}',
        '.fab-ic .i{width:' + Math.round(I.size * .5) + 'px;height:' + Math.round(I.size * .5) + 'px}',
        '.fab-lb{background:' + I.label_bg + ';color:' + I.label_text + ';font-size:13px;font-weight:600;white-space:nowrap;padding:6px 11px;border-radius:9px;box-shadow:0 6px 18px rgba(0,0,0,.12)}',
        '.fab-lb.hov{opacity:0;transition:opacity .15s;pointer-events:none}.fab-it:hover .fab-lb.hov{opacity:1}',
        '.fab-bar{position:fixed;left:0;right:0;bottom:0;z-index:2147483000;direction:rtl;display:flex;background:#fff;box-shadow:0 -6px 24px rgba(0,0,0,.12);padding:6px 6px calc(6px + env(safe-area-inset-bottom))}',
        '.fab-bar a,.fab-bar button{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;text-decoration:none;color:#1F2A26;font-size:11px;font-weight:600;padding:6px 2px;border:0;background:none}',
        '.fab-bar .bi{width:34px;height:34px;border-radius:11px;display:grid;place-items:center;color:#fff}.fab-bar .bi .i{width:19px;height:19px}'
      );
    }
    if (c) {
      var D = d, L = D.launcher, P = D.panel, C = D.colors, acc = L.color || c.color || '#1D5C4D';
      var hb = C.header_bg || acc, vb = C.visitor_bubble || acc, fs = D.font.size;
      var sh = P.shadow === 'none' ? 'none' : P.shadow === 'strong' ? '0 24px 70px rgba(0,0,0,.32)' : '0 18px 50px rgba(0,0,0,.2)';
      out.push(
        '.cl{position:relative;--pc:' + rgba(acc, .45) + ';height:' + L.size + 'px;min-width:' + L.size + 'px;border:0;border-radius:' + shapeRadius(L.style, L.size) + ';background:' + acc + ';color:' + L.text_color + ';display:flex;align-items:center;justify-content:center;gap:8px;padding:0 ' + (L.style === 'pill' ? '20px' : '0') + ';box-shadow:0 10px 28px ' + rgba(acc, .38) + ';transition:transform .18s}',
        '.cl:hover{transform:translateY(-2px)}',
        '.cl .i{width:' + Math.round(L.size * .48) + 'px;height:' + Math.round(L.size * .48) + 'px}',
        '.cl img{width:100%;height:100%;object-fit:cover;border-radius:inherit}',
        '.cl .ml{font-size:14px;font-weight:700;white-space:nowrap}',
        '.panel{position:fixed;z-index:2147483001;direction:rtl;width:' + P.width + 'px;height:' + P.height + 'px;max-height:calc(100vh - 110px);max-width:calc(100vw - 24px);background:' + C.body_bg + ';border-radius:' + P.radius + 'px;box-shadow:' + sh + ';display:none;flex-direction:column;overflow:hidden;font-size:' + fs + 'px}',
        '.panel.on{display:flex;animation:ebw-in .22s ease}',
        '.hd{background:' + hb + ';color:' + C.header_text + ';padding:16px 16px 14px;display:flex;align-items:center;gap:11px}',
        '.hd .av{width:40px;height:40px;border-radius:50%;object-fit:cover;background:rgba(255,255,255,.18);flex:0 0 auto}',
        '.faces{display:flex;flex:0 0 auto}.faces span{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:700;font-size:14px;border:2px solid ' + hb + ';margin-inline-start:-10px}.faces span:first-child{margin-inline-start:0}',
        '.hd .tt{min-width:0;flex:1}.hd .t{font-size:15px;font-weight:700;margin:0;line-height:1.5}',
        '.hd .s{font-size:12px;opacity:.85;margin:1px 0 0;display:flex;align-items:center;gap:5px}',
        '.live{width:7px;height:7px;border-radius:50%;background:#4ADE80;display:inline-block}.live.off{background:#CBD5D1}',
        '.hd .x{background:rgba(255,255,255,.14);border:0;color:inherit;width:32px;height:32px;border-radius:10px;display:grid;place-items:center;padding:0;flex:0 0 auto}',
        '.hd .x svg{width:16px;height:16px}',
        '.body{flex:1;overflow-y:auto;padding:16px 14px;display:flex;flex-direction:column;gap:8px;scroll-behavior:smooth}',
        '.msg{max-width:82%;padding:9px 13px;border-radius:16px;line-height:1.75;white-space:pre-wrap;word-break:break-word;animation:ebw-in .18s ease}',
        '.msg.a{background:' + C.agent_bubble + ';color:' + C.agent_text + ';align-self:flex-end;border-bottom-left-radius:5px;box-shadow:0 1px 2px rgba(0,0,0,.06)}',
        '.msg.v{background:' + vb + ';color:' + C.visitor_text + ';align-self:flex-start;border-bottom-right-radius:5px}',   /* ۱.۰.۱۶: پیام مشتری راست، پیام ما چپ */
        '.msg img{max-width:100%;border-radius:10px;display:block;margin-top:6px}',
        '.who{align-self:flex-end;display:flex;align-items:center;gap:6px;font-size:11.5px;color:#5A6560;margin:6px 2px -2px}.who img,.who b{width:22px;height:22px;border-radius:50%;object-fit:cover;flex:0 0 22px}.who b{display:grid;place-items:center;color:#fff;font-size:11px;background:' + acc + '}',
        '.hd .avi{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.22);font-weight:700;font-size:17px;flex:0 0 auto}',
        '.typing{align-self:flex-end;display:flex;align-items:center;gap:8px;padding:9px 13px;border-radius:16px;border-bottom-left-radius:5px;background:' + C.agent_bubble + ';color:' + C.agent_text + ';font-size:12px;opacity:.9;box-shadow:0 1px 2px rgba(0,0,0,.06)}',
        '.typing i{display:inline-flex;gap:3px}.typing b{width:6px;height:6px;border-radius:50%;background:currentColor;display:block;animation:ebw-dot 1.2s infinite}.typing b:nth-child(2){animation-delay:.15s}.typing b:nth-child(3){animation-delay:.3s}',
        '.note{align-self:center;font-size:11.5px;color:#5A6560;background:rgba(0,0,0,.05);padding:4px 11px;border-radius:20px;text-align:center}',
        '.qr{display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end}',
        '.qr button{border:1px solid ' + rgba(acc, .35) + ';background:#fff;color:' + acc + ';border-radius:20px;padding:6px 12px;font-size:12.5px;font-weight:600}',
        '.qr button:hover{background:' + rgba(acc, .08) + '}',
        '.card{background:#fff;border:1px solid #E4E2DA;border-radius:14px;padding:12px;display:flex;flex-direction:column;gap:8px}',
        '.card p{margin:0;font-size:12.5px;color:#4B5651;line-height:1.8}',
        'input,textarea{width:100%;border:1px solid #D8D5CB;border-radius:11px;padding:10px 12px;font-size:13.5px;outline:none;background:' + C.input_bg + ';color:#1F2A26}',
        'input:focus,textarea:focus{border-color:' + acc + ';box-shadow:0 0 0 3px ' + rgba(acc, .14) + '}',
        '.btn{background:' + acc + ';color:' + readable(acc) + ';border:0;border-radius:11px;padding:10px 14px;font-size:13.5px;font-weight:700}',
        '.btn.ghost{background:transparent;color:#5A6560;font-weight:500}.btn:disabled{opacity:.55;cursor:default}',
        '.ft{border-top:1px solid #E7E4DC;padding:10px;background:' + C.input_bg + ';display:flex;gap:8px;align-items:flex-end}',
        '.ft textarea{resize:none;max-height:100px;min-height:42px;border-radius:21px;border-color:transparent;background:' + rgba('#000000', .04) + '}',
        '.ft .send{border-radius:50%;width:42px;height:42px;padding:0;flex:0 0 42px;display:grid;place-items:center}',
        '.ft .send svg{width:19px;height:19px;transform:scaleX(-1)}',
        '.err{color:#B42318;font-size:12px;padding:6px 14px;background:#FDECEA}',
        '.gate{padding:18px;display:flex;flex-direction:column;gap:10px;flex:1;overflow:auto}',
        '.gate h4{margin:0;font-size:15px;color:#1F2A26}.gate p{margin:0;font-size:13px;color:#5A6560;line-height:1.85}',
        '.pw{font-size:10.5px;color:#8A938F;text-align:center;padding:5px 0 7px;background:' + C.input_bg + '}',
        '.pw a{color:inherit;text-decoration:none}',
        /* ۱.۰.۱۴: لینک‌های قابل کلیک و دکمه‌ای */
        '.msg a.il{color:inherit;font-weight:700;text-decoration:underline;text-underline-offset:3px;white-space:nowrap}.msg a.il.raw{white-space:normal;word-break:break-all;font-weight:600}',
        '.lbs{display:flex;flex-direction:column;gap:6px;margin-top:8px}',
        '.lb{display:flex;align-items:center;justify-content:center;gap:7px;text-decoration:none;background:' + acc + ';color:' + readable(acc) + ';border:0;border-radius:11px;padding:9px 12px;font-size:13px;font-weight:700;line-height:1.4;white-space:normal;text-align:center;box-shadow:0 2px 8px ' + rgba(acc, .22) + ';transition:transform .12s,box-shadow .12s}',
        '.lb:hover{transform:translateY(-1px);box-shadow:0 5px 14px ' + rgba(acc, .3) + '}',
        '.lb svg{width:16px;height:16px;flex:0 0 16px}',
        '.lb.alt{background:#fff;color:' + acc + ';border:1.5px solid ' + rgba(acc, .45) + ';box-shadow:none}',
        '.lb.alt:hover{background:' + rgba(acc, .07) + '}',
        '.lb:disabled{opacity:.5;cursor:default;transform:none}',
        '.msg.v .lb{background:rgba(255,255,255,.2);color:inherit;box-shadow:none}',
        /* کروسل اقامتگاه‌ها */
        '.crw{align-self:stretch;max-width:100%;position:relative;animation:ebw-in .2s ease}',
        '.cr-h{font-size:12.5px;color:#4B5651;margin:0 2px 7px;display:flex;align-items:center;gap:6px;font-weight:600}',
        '.cr-h svg{width:15px;height:15px;color:' + acc + '}',
        '.cr{display:flex;gap:10px;overflow-x:auto;scroll-snap-type:x mandatory;padding:2px 2px 10px;scrollbar-width:thin;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain}',
        '.cr::-webkit-scrollbar{height:5px}.cr::-webkit-scrollbar-thumb{background:' + rgba(acc, .3) + ';border-radius:4px}',
        '.cd{flex:0 0 76%;max-width:228px;scroll-snap-align:start;background:#fff;border:1px solid #E6E3DA;border-radius:16px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 2px 10px rgba(0,0,0,.06)}',
        '.cd-i{position:relative;display:block;height:128px;background:linear-gradient(135deg,' + rgba(acc, .18) + ',' + rgba(acc, .06) + ');overflow:hidden}',
        '.cd-i img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s}',
        '.cd:hover .cd-i img{transform:scale(1.04)}',
        '.cd-i .ph{position:absolute;inset:0;display:grid;place-items:center;color:' + rgba(acc, .55) + '}.cd-i .ph svg{width:42px;height:42px}',
        '.cd-n{position:absolute;top:8px;right:8px;background:rgba(17,24,22,.72);color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;backdrop-filter:blur(4px)}',
        '.cd-b{padding:10px 11px 11px;display:flex;flex-direction:column;gap:5px;flex:1}',
        '.cd-t{font-size:13.5px;font-weight:800;color:#1F2A26;line-height:1.55;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.9em}',
        '.cd-m{font-size:11.5px;color:#66716C;display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
        '.cd-m svg{width:13px;height:13px;flex:0 0 13px}',
        '.cd-pr{display:flex;align-items:baseline;justify-content:space-between;gap:6px;margin-top:2px;padding-top:7px;border-top:1px dashed #E6E3DA}',
        '.cd-pr small{font-size:10.5px;color:#7A847F}.cd-pr b{font-size:13.5px;color:#1F2A26;font-weight:800;white-space:nowrap}',
        '.cd .lb{margin-top:7px;padding:8px 10px;font-size:12.5px}',
        '.cd-acts{display:flex;gap:6px;margin-top:7px}.cd-acts .lb{margin:0;flex:1;padding:8px 6px;font-size:12px}.cd-acts .lb svg{width:14px;height:14px;flex-basis:14px}',
        '.cd-x{font-size:11px;color:#7A847F;line-height:1.6;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}',
        '@media (min-width:768px){.panel.big{width:min(760px,calc(100vw - 40px))!important;height:calc(100vh - 40px)!important;max-height:none!important;bottom:20px!important}.panel.big .cd{flex-basis:31%;max-width:240px}}',
        '.hd .x.cl-big{display:none}@media (min-width:768px){.hd .x.cl-big{display:grid}}',
        '.cr-nav{position:absolute;top:calc(50% + 2px);transform:translateY(-50%);width:32px;height:32px;border-radius:50%;border:0;background:#fff;color:#1F2A26;box-shadow:0 3px 12px rgba(0,0,0,.18);display:none;place-items:center;padding:0;z-index:2}',
        '.cr-nav svg{width:16px;height:16px}.cr-nav.pv{right:-6px}.cr-nav.nx{left:-6px}',
        '@media (hover:hover) and (pointer:fine){.crw:hover .cr-nav{display:grid}}',
        /* تماس سریع با پشتیبان */
        '.hd .x.cl-call{background:rgba(255,255,255,.2);animation:ebw-wiggle 5s infinite}',
        '.csh{position:absolute;inset:0;z-index:5;background:rgba(15,20,18,.38);display:flex;align-items:flex-end;animation:ebw-in .18s ease}',
        '.csh-in{background:#fff;width:100%;border-radius:20px 20px 0 0;padding:18px 16px 16px;display:flex;flex-direction:column;gap:10px;box-shadow:0 -10px 30px rgba(0,0,0,.18)}',
        '.csh-in h4{margin:0;font-size:15px;color:#1F2A26;display:flex;align-items:center;gap:8px}.csh-in h4 svg{width:20px;height:20px;color:' + acc + '}',
        '.csh-in p{margin:0;font-size:12.5px;color:#5A6560;line-height:1.8}',
        '.csh-num{display:flex;align-items:center;justify-content:space-between;gap:8px;background:' + rgba(acc, .07) + ';border-radius:12px;padding:9px 12px;font-size:16px;font-weight:800;color:#1F2A26;direction:ltr}',
        '.csh-num i{font-style:normal}.csh-num button{border:0;background:#fff;color:' + acc + ';border-radius:9px;padding:5px 9px;font-size:11.5px;font-weight:700;display:flex;align-items:center;gap:4px;direction:rtl}.csh-num button svg{width:14px;height:14px}',
        '.lb.big{padding:13px 14px;font-size:14.5px;border-radius:13px}',
        '.csh .btn.ghost{align-self:center}',
        '.wh{display:flex;flex-wrap:wrap;gap:6px}.wh button{border:1px solid #D8D5CB;background:#fff;border-radius:20px;padding:5px 11px;font-size:12px;color:#4B5651}.wh button.on{border-color:' + acc + ';background:' + rgba(acc, .1) + ';color:' + acc + ';font-weight:700}',
        '.cl-ph{position:relative;width:46px;height:46px;border-radius:50%;border:0;background:#fff;color:' + acc + ';display:grid;place-items:center;box-shadow:0 8px 22px rgba(0,0,0,.16);padding:0;transition:transform .15s}',
        '.cl-ph:hover{transform:scale(1.07)}.cl-ph svg{width:22px;height:22px}'
      );
      if (P.mobile_full) out.push('@media(max-width:767px){.panel{inset:0!important;width:100%!important;height:100%!important;max-height:none;max-width:none;border-radius:0}}');
    }
    return out.join('');
  }

  /* -------------------------------------------------------------- ساختار */
  function mount() {
    if (host) host.remove();
    host = document.createElement('div');
    host.setAttribute('data-elanban-widget', '1');
    document.body.appendChild(host);
    root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    var st = document.createElement('style'); st.textContent = css(); root.appendChild(st);
    // فونت وزیرمتن روی خود صفحه (فونت داخل Shadow DOM ثبت نمی‌شود)
    // اگر سایت خودش وزیرمتن دارد، چیزی بارگذاری نمی‌شود؛ وگرنه از CDN (فونت از دامنه‌ی دیگر بدون CORS بارگذاری نمی‌شود)
    var hasVazir = false;
    try { hasVazir = document.fonts && document.fonts.check('12px Vazirmatn'); } catch (e) {}
    if (!hasVazir && !document.querySelector('link[data-ebw-font]') && !(CFG.chat && CFG.chat.design.font.family)) {
      var l = document.createElement('link'); l.rel = 'stylesheet'; l.setAttribute('data-ebw-font', '1');
      l.href = PREVIEW ? BASE + '/assets/fonts.css' : 'https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css';
      document.head.appendChild(l);
    }
  }

  function dockPos(el, pos) {
    var m = isMobile();
    el.style.bottom = (m ? pos.m_bottom : pos.bottom) + 'px';
    el.style[pos.side === 'left' ? 'left' : 'right'] = (m ? pos.m_offset : pos.offset) + 'px';
    el.classList.add(pos.side === 'left' ? 'left' : 'right');
  }

  /* ----------------------------------------------------- دکمه‌های شناور */
  var fabEl = {};
  function track(b) {
    if (PREVIEW || !CFG.fab || !CFG.fab.tracking) return;
    var payload = JSON.stringify({ key: b.key, kind: b.type, page: location.href, device: isMobile() ? 'mobile' : 'desktop' });
    try {
      if (navigator.sendBeacon) navigator.sendBeacon(ENDPOINT + '?wc=fabclick' + SQ, new Blob([payload], { type: 'text/plain' }));
      else request('fabclick', JSON.parse(payload), function () {});
    } catch (e) {}
  }

  function fabButtons() {
    var f = CFG.fab, out = [];
    for (var i = 0; i < f.buttons.length; i++) {
      var b = f.buttons[i];
      if (b.devices === 'mobile' && !isMobile()) continue;
      if (b.devices === 'desktop' && isMobile()) continue;
      if (b.hours_only && CFG.online === false) continue;
      if (b.type === 'webchat' && !CFG.chat) continue;
      if (b.type !== 'webchat' && !b.href) continue;
      out.push(b);
    }
    return out;
  }

  function itemHtml(b, labelMode) {
    var tag = b.type === 'webchat' ? 'button type="button"' : 'a href="' + esc(b.href) + '"' + (/^https?:/.test(b.href) ? ' target="_blank" rel="noopener"' : '');
    var close = b.type === 'webchat' ? 'button' : 'a';
    var lb = labelMode === 'never' ? '' : '<span class="fab-lb' + (labelMode === 'hover' ? ' hov' : '') + '">' + esc(b.label) + '</span>';
    return '<' + tag + ' class="fab-it" data-k="' + esc(b.key) + '" aria-label="' + esc(b.label) + '" style="background:none;border:0;padding:0">' + lb +
      '<span class="fab-ic" style="background:' + esc(b.color) + '">' + svg(b.icon) + '</span></' + close + '>';
  }

  function buildFab() {
    var f = CFG.fab; if (!f || !allowed(f.rules)) return;
    var items = fabButtons(); if (!items.length) return;
    var layout = f.layout === 'bar' && !isMobile() ? 'menu' : f.layout;
    if (layout === 'menu' && items.length === 1) layout = 'stack';

    if (layout === 'bar') {
      var bar = document.createElement('div'); bar.className = 'fab-bar';
      bar.innerHTML = items.map(function (b) {
        var tag = b.type === 'webchat' ? 'button type="button"' : 'a href="' + esc(b.href) + '"' + (/^https?:/.test(b.href) ? ' target="_blank" rel="noopener"' : '');
        return '<' + tag + ' data-k="' + esc(b.key) + '"><span class="bi" style="background:' + esc(b.color) + '">' + svg(b.icon) + '</span>' + esc(b.label) + '</' + (b.type === 'webchat' ? 'button' : 'a') + '>';
      }).join('');
      root.appendChild(bar);
      bindItems(bar, items);
      fabEl.dock = null;
      return show(bar, f.rules);
    }

    var dock = document.createElement('div'); dock.className = 'dock';
    dockPos(dock, f.position);
    var list = '<div class="fab-list ' + (layout === 'menu' ? 'menu' : '') + '">' + items.map(function (b) { return itemHtml(b, f.items.show_label); }).join('') + '</div>';
    var M = f.main, main = '';
    if (layout === 'menu') {
      var inner = M.image ? '<img src="' + esc(img(M.image)) + '" alt="">' : svg(M.icon) + (M.shape === 'pill' && M.label ? '<span class="ml">' + esc(M.label) + '</span>' : '');
      var tip = (M.shape !== 'pill' && M.label && M.show_label !== 'never') ? '<span class="fab-tip' + (M.show_label === 'hover' ? ' hov' : '') + '" style="' + (M.show_label === 'always' ? 'opacity:1' : '') + '">' + esc(M.label) + '</span>' : '';
      main = '<button class="fab-main an-' + esc(M.animation) + '" type="button" aria-label="' + esc(M.label || 'تماس با ما') + '" aria-expanded="false">' + inner + tip + '</button>';
    }
    dock.innerHTML = list + main;
    root.appendChild(dock);
    fabEl.dock = dock; fabEl.list = dock.querySelector('.fab-list'); fabEl.main = dock.querySelector('.fab-main'); fabEl.mainHtml = fabEl.main ? fabEl.main.innerHTML : '';
    bindItems(dock, items);
    if (fabEl.main) {
      fabEl.main.addEventListener('click', function () { toggleFab(); });
      greet(dock, f.greeting, 'ebw_fab_greet', function () { toggleFab(true); });
    }
    show(dock, f.rules);
  }

  function toggleFab(force) {
    if (!fabEl.list) return;
    var on = typeof force === 'boolean' ? force : !fabEl.list.classList.contains('on');
    fabEl.list.classList.toggle('on', on);
    fabEl.main.classList.toggle('open', on);
    fabEl.main.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (CFG.fab.main.close_icon && !CFG.fab.main.image) {
      var i = fabEl.main.querySelector('svg.i');
      if (i) i.outerHTML = svg(on ? 'x' : CFG.fab.main.icon);
    }
    if (on) { var g = fabEl.dock.querySelector('.greet'); if (g) g.remove(); }
  }

  function bindItems(scope, items) {
    var map = {}; items.forEach(function (b) { map[b.key] = b; });
    Array.prototype.forEach.call(scope.querySelectorAll('[data-k]'), function (a) {
      a.addEventListener('click', function (e) {
        var b = map[a.getAttribute('data-k')]; if (!b) return;
        track(b);
        if (b.type === 'webchat') { e.preventDefault(); if (fabEl.list && fabEl.list.classList.contains('on')) toggleFab(false); openChat(); }
        if (PREVIEW && b.type !== 'webchat') e.preventDefault();
      });
    });
  }

  /** نمایش با تأخیر یا بعد از اسکرول */
  function show(el, r) {
    var delay = (r && r.delay ? r.delay : 0) * 1000, scroll = r && r.scroll ? r.scroll : 0;
    if (PREVIEW) { delay = 0; scroll = 0; }
    if (!delay && !scroll) return;
    el.style.display = 'none';
    var shown = false, timeOk = !delay, scrollOk = !scroll;
    function go() { if (!shown && timeOk && scrollOk) { shown = true; el.style.display = ''; } }
    if (delay) setTimeout(function () { timeOk = true; go(); }, delay);
    if (scroll) window.addEventListener('scroll', function () {
      var h = document.documentElement.scrollHeight - innerHeight;
      if (h <= 0 || (scrollY / h) * 100 >= scroll) { scrollOk = true; go(); }
    }, { passive: true });
  }

  function greet(dock, g, key, onClick) {
    if (!g || !g.enabled || !g.text) return;
    if (!PREVIEW && g.once && sload(key)) return;
    setTimeout(function () {
      if (!dock.isConnected || dock.querySelector('.greet')) return;
      if (fabEl.list && fabEl.list.classList.contains('on')) return;
      if (chat.open) return;
      var el = document.createElement('div'); el.className = 'greet';
      el.innerHTML = '<button class="gx" type="button" aria-label="بستن">' + svg('x', '') + '</button>' + esc(g.text);
      dock.insertBefore(el, dock.lastChild);
      el.addEventListener('click', function (e) {
        sstore(key, '1');
        el.remove();
        if (!e.target.closest('.gx')) onClick();
      });
      if (CFG.chat && CFG.chat.design.behaviour.sound && !PREVIEW) beep();
    }, PREVIEW ? 300 : (g.delay || 0) * 1000);
  }

  /* -------------------------------------------------------- گفتگوی آنلاین */
  function chatVisible() {
    var c = CFG.chat; if (!c) return false;
    var B = c.design.behaviour;
    if (B.hide_offline && CFG.online === false) return false;
    return allowed({ devices: B.devices, include: B.include, exclude: B.exclude });
  }

  function launcherHidden() {
    // اگر دکمه‌های شناور، دکمه‌ی «گفتگوی آنلاین» دارند، دکمه‌ی جدای گفتگو نمایش داده نمی‌شود
    return !!(CFG.fab && fabEl.dock !== undefined && fabButtons().some(function (b) { return b.type === 'webchat'; }) && allowed(CFG.fab.rules));
  }

  function buildChat() {
    var c = CFG.chat; if (!c || !chatVisible()) return;
    var D = c.design, L = D.launcher, H = D.header, el = chat.el;
    // پنجره
    var panel = document.createElement('div'); panel.className = 'panel'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', c.title || 'گفتگو');
    var side = D.position.side === 'left' ? 'left' : 'right';
    panel.style[side] = (isMobile() ? D.position.m_offset : D.position.offset) + 'px';
    panel.style.bottom = ((isMobile() ? D.position.m_bottom : D.position.bottom) + L.size + 14) + 'px';
    var av = '', AG = c.agent || null;
    if (AG) av = AG.avatar ? '<img class="av" src="' + esc(img(AG.avatar)) + '" alt="">' : '<span class="avi">' + esc(AG.initial || '') + '</span>';
    else if (H.avatar === 'logo' && c.logo) av = '<img class="av" src="' + esc(img(c.logo)) + '" alt="">';
    else if (H.avatar === 'custom' && H.avatar_url) av = '<img class="av" src="' + esc(img(H.avatar_url)) + '" alt="">';
    else if (H.avatar === 'team' && c.team && c.team.length) av = '<div class="faces">' + c.team.map(function (t) { return t.a ? '<span style="background:' + esc(t.c) + ';overflow:hidden"><img src="' + esc(img(t.a)) + '" alt="" style="width:100%;height:100%;object-fit:cover"></span>' : '<span style="background:' + esc(t.c) + '">' + esc(t.i) + '</span>'; }).join('') + '</div>';
    panel.innerHTML =
      '<div class="hd">' + av + '<div class="tt"><p class="t">' + esc(AG ? AG.name : (c.title || 'گفتگو با پشتیبانی')) + '</p><p class="s"></p></div>' +
      (c.call ? '<button class="x cl-call" type="button" aria-label="' + esc(c.call.label || 'تماس با پشتیبان') + '" title="' + esc(c.call.label || 'تماس با پشتیبان') + '">' + svg('phone-call', '') + '</button>' : '') +
      '<button class="x cl-big" type="button" aria-label="بزرگ کردن پنجره" title="بزرگ / کوچک کردن پنجره">' + svg('arrows-out', '') + '</button>' +
      '<button class="x xc" type="button" aria-label="بستن">' + svg('x', '') + '</button></div>' +
      '<div class="gate" style="display:none"></div><div class="body" aria-live="polite"></div><div class="err" style="display:none"></div>' +
      '<div class="ft"><textarea rows="1" placeholder="پیامتان را بنویسید…" aria-label="پیام"></textarea><button class="btn send" type="button" aria-label="ارسال">' + svg('paper-plane-right', '') + '</button></div>' +
      (D.footer.branding || D.footer.text ? '<div class="pw">' + esc(D.footer.text || ('گفتگو با ' + (c.brandName || 'اعلان‌بان'))) + '</div>' : '');
    root.appendChild(panel);
    el.panel = panel; el.body = panel.querySelector('.body'); el.gate = panel.querySelector('.gate'); el.err = panel.querySelector('.err');
    el.ft = panel.querySelector('.ft'); el.input = panel.querySelector('textarea'); el.send = panel.querySelector('.send'); el.sub = panel.querySelector('.s');
    updateLive(CFG.online !== false);

    // دکمه‌ی باز کردن
    if (!launcherHidden()) {
      var dock = document.createElement('div'); dock.className = 'dock';
      dockPos(dock, D.position);
      var inner = L.image ? '<img src="' + esc(img(L.image)) + '" alt="">' : svg(L.icon) + (L.style === 'pill' && L.label ? '<span class="ml">' + esc(L.label) + '</span>' : '');
      dock.innerHTML = (c.call && c.call.fab ? '<button class="cl-ph" type="button" title="' + esc(c.call.label || 'تماس با پشتیبان') + '" aria-label="' + esc(c.call.label || 'تماس با پشتیبان') + '">' + svg('phone-call', '') + '</button>' : '') +
        '<button class="cl an-' + esc(L.animation) + '" type="button" title="' + esc(L.label || 'گفتگوی آنلاین') + '" aria-label="' + esc(L.label || 'گفتگوی آنلاین') + '">' + inner + '</button>';
      var phb = dock.querySelector('.cl-ph'); if (phb) phb.addEventListener('click', function () { if (!chat.open) openChat(); setTimeout(showCall, 60); });
      // ۱.۰.۱۳: متن کنار دکمه (روی هاور، یا همیشه) تا معلوم باشد دکمه برای چیست
      if (L.style !== 'pill' && L.label && L.show_label !== 'never') {
        dock.style.position = 'fixed';
        var hl = document.createElement('span'); hl.className = 'lhint' + (L.show_label === 'always' ? ' on' : ''); hl.textContent = L.label;
        dock.appendChild(hl);
        if (L.show_label !== 'always' && isMobile() && !load('ebw_hint_seen')) { store('ebw_hint_seen', '1'); setTimeout(function () { hl.classList.add('on'); setTimeout(function () { hl.classList.remove('on'); }, 6000); }, 2500); }
      }
      root.appendChild(dock);
      el.dock = dock; el.launcher = dock.querySelector('.cl');
      el.launcher.addEventListener('click', function () { chat.open ? closeChat() : openChat(); });
      greet(dock, D.greeting, 'ebw_chat_greet', openChat);
      proactive(dock);
    } else if (fabEl.dock) {
      proactive(fabEl.dock);
    }

    panel.querySelector('.xc').addEventListener('click', closeChat);
    var callBtn = panel.querySelector('.cl-call'); if (callBtn) callBtn.addEventListener('click', function () { showCall(); });
    var bigBtn = panel.querySelector('.cl-big');
    if (load('ebw_big') === '1') panel.classList.add('big');
    if (bigBtn) bigBtn.addEventListener('click', function () { var on = panel.classList.toggle('big'); store('ebw_big', on ? '1' : '0'); scroll(); });
    el.send.addEventListener('click', function () { var v = el.input.value; el.input.value = ''; el.input.style.height = 'auto'; submit(v); });
    el.input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); el.send.click(); } });
    el.input.addEventListener('input', function () { el.input.style.height = 'auto'; el.input.style.height = Math.min(100, el.input.scrollHeight) + 'px'; });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && chat.open) closeChat(); });

    // گفتگوی قبلی: بدون باز کردن پنجره، پیام‌های تازه را برای نشان‌دادن شمارنده بگیر
    var saved = load(TOKEN_KEY);
    if (saved && !PREVIEW) { chat.token = saved; chat.lastId = +(load(LASTID_KEY) || 0); slowPoll(); }

    if (D.behaviour.auto_open && !PREVIEW && !load('ebw_auto_opened')) {
      setTimeout(function () { if (!chat.open) { store('ebw_auto_opened', '1'); openChat(); } }, D.behaviour.auto_open * 1000);
    }
    if (PREVIEW && PREVIEW.open === 'chat') openChat();
  }

  function proactive(dock) {
    var list = CFG.chat.design.proactive || [];
    for (var i = 0; i < list.length; i++) {
      var p = list[i];
      if (p.path && pathMatch(p.path) !== true) continue;
      (function (p) {
        setTimeout(function () {
          if (chat.open || sload('ebw_pro_' + i)) return;
          sstore('ebw_pro_' + i, '1');
          if (p.open) { openChat(); addMsg('agent', p.text); return; }
          greet(dock, { enabled: true, text: p.text, delay: 0, once: false }, 'ebw_pro', openChat);
        }, PREVIEW ? 500 : p.delay * 1000);
      })(p);
      break;   // فقط اولین قانونِ منطبق
    }
  }

  function updateLive(online) {
    var c = CFG.chat; if (!c || !chat.el.sub) return;
    var H = c.design.header;
    var txt = online ? (c.subtitle || '') : 'خارج از ساعت کاری؛ پیام بگذارید';
    if (H.show_team && H.team_text && online) txt = H.team_text + (c.subtitle ? ' · ' + c.subtitle : '');
    if (c.agent) { online = true; txt = (c.agent.role || '') + ' · آنلاین'; }
    chat.el.sub.innerHTML = (H.show_status ? '<span class="live' + (online ? '' : ' off') + '"></span>' : '') + esc(txt);
  }

  function setBadge(n) {
    chat.unread = n;
    var target = chat.el.launcher || (fabEl.main || null);
    if (!target) return;
    var b = target.querySelector('.badge');
    if (!n || (CFG.chat && !CFG.chat.design.launcher.badge)) { if (b) b.remove(); return; }
    if (!b) { b = document.createElement('span'); b.className = 'badge'; target.appendChild(b); }
    b.textContent = n > 9 ? '9+' : String(n);
  }

  function addMsg(from, text, media, id, ai, by, x) {
    // ۱.۰.۱۶: یک پیام دو بار نمایش داده نشود (پرسش‌های هم‌زمان)
    if (id && chat.el.body && chat.el.body.querySelector('[data-mid="' + (+id) + '"]')) return;
    // دکمه‌های پاسخ سریع فقط زیر آخرین پیام دستیار می‌مانند
    if (from === 'agent' && chat.el.body) { var oq = chat.el.body.querySelectorAll('.qr:not(.csat)'); for (var oi = 0; oi < oq.length; oi++) oq[oi].remove(); }
    // ۱.۰.۱۴: پیام کارت اقامتگاه‌ها (کروسل)
    if (x && x.cards && x.cards.length && from === 'agent') { setTyping(false);
      if (x.cards[0].g) { var gt = String(text || '').split(/\n*🎠 کروسل \(/)[0].trim(); if (gt) addMsg(from, gt, media, null, ai, by, null); }   // ۲.۱.۰: متن مقدمه‌ی کروسل (بدون خلاصه‌ی ویژه‌ی همکاران)
      var cw = cardsEl(x); if (id) cw.setAttribute('data-mid', String(id)); chat.el.body.appendChild(cw); if (x.qr) qrBox(x.qr); scroll(); return; }
    var AG = CFG.chat && CFG.chat.agent;
    if (from === 'agent' && !ai && by && by.name) AG = by;   // ۱.۰.۱۳: پیام همکار: نام و عکس خودش
    if (from === 'agent' && (ai || (by && by.name)) && AG) {
      var prev = chat.el.body.lastElementChild;
      while (prev && (prev.classList.contains('typing') || prev.classList.contains('qr'))) prev = prev.previousElementSibling;
      var key = ai ? 'ai' : ('u' + (by && by.name || ''));
      if (!(prev && prev.classList.contains('msg') && prev.classList.contains('a') && prev.getAttribute('data-who') === key)) {
        var w = document.createElement('div'); w.className = 'who';
        w.innerHTML = (AG.avatar ? '<img src="' + esc(img(AG.avatar)) + '" alt="">' : '<b>' + esc(AG.initial || '') + '</b>') + '<span>' + esc(AG.name || '') + '</span>';
        setTyping(false); chat.el.body.appendChild(w);
      }
    }
    var d = document.createElement('div');
    if (ai) d.setAttribute('data-ai', '1');
    if (from === 'agent') d.setAttribute('data-who', ai ? 'ai' : ('u' + (by && by.name || '')));
    d.className = 'msg ' + (from === 'agent' ? 'a' : 'v');
    if (id) d.setAttribute('data-mid', String(id));
    richText(d, text || '', from === 'agent');
    if (x && from === 'agent') payloadButtons(d, x);
    if (!d.childNodes.length) d.style.display = 'none';
    if (from === 'agent') setTyping(false);
    if (media && media.type === 'photo' && media.path) {
      var im = document.createElement('img'); im.src = img(media.path); im.alt = ''; d.appendChild(im);
    }
    chat.el.body.appendChild(d);
    if (x && x.qr && from === 'agent') qrBox(x.qr);
    scroll();
  }
  function csatChips(opts) {
    var box = document.createElement('div'); box.className = 'qr csat';
    box.innerHTML = opts.map(function (x) { return '<button type="button">' + esc(String(x)) + '</button>'; }).join('');
    Array.prototype.forEach.call(box.querySelectorAll('button'), function (b) { b.addEventListener('click', function () { box.remove(); submit(b.textContent); }); });
    chat.el.body.appendChild(box); scroll();
  }
  // ۱.۰.۸: «در حال نوشتن…» تا وقتی پاسخ آماده می‌شود
  function setTyping(on) {
    if (!chat.el || !chat.el.body) return;
    var t = chat.el.body.querySelector('.typing');
    if (!on) { if (t) t.remove(); return; }
    if (!t) { t = document.createElement('div'); t.className = 'typing'; t.innerHTML = '<i><b></b><b></b><b></b></i><span>در حال نوشتن…</span>'; }
    chat.el.body.appendChild(t); scroll();
  }
  function addNote(t) { var d = document.createElement('div'); d.className = 'note'; d.textContent = t; chat.el.body.appendChild(d); scroll(); }
  function scroll() { chat.el.body.scrollTop = chat.el.body.scrollHeight; }
  function showErr(t) { var e = chat.el.err; if (!t) { e.style.display = 'none'; return; } e.textContent = t; e.style.display = 'block'; setTimeout(function () { e.style.display = 'none'; }, 5000); }
  function validPhone(v) { var p = String(v || '').replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/[^\d+]/g, ''); return /^(\+?98|0)?9\d{9}$/.test(p); }
  function validEmail(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(v || '').trim()); }

  function quickReplies() {
    var q = CFG.chat.design.behaviour.quick_replies || [];
    if (!q.length || chat.el.body.querySelector('.msg.v')) return;
    var box = document.createElement('div'); box.className = 'qr';
    box.innerHTML = q.map(function (x) { return '<button type="button">' + esc(x) + '</button>'; }).join('');
    Array.prototype.forEach.call(box.querySelectorAll('button'), function (b) { b.addEventListener('click', function () { box.remove(); submit(b.textContent); }); });
    chat.el.body.appendChild(box); scroll();
  }

  /* ---------------------------------------------- ۱.۰.۱۴: لینک‌ها، دکمه‌ها، کروسل، تماس سریع */
  var URL_RE = /(https?:\/\/[^\s<>"'«»]+[^\s<>"'«».,،؛:!?)\]]|www\.[^\s<>"'«»]+[^\s<>"'«».,،؛:!?)\]])/gi;
  function fixUrl(u) { return /^www\./i.test(u) ? 'https://' + u : u; }
  function linkLabel(u) {
    var l = u.toLowerCase();
    if (/checkout|payment|\/pay\b|\/pay\/|invoice|gateway|zarinpal|idpay|zibal|nextpay|\/ipg/.test(l)) return 'پرداخت آنلاین';
    if (/booking|reserv|\/order|voucher/.test(l)) return 'مشاهده رزرو';
    if (/\/(space|spaces|villa|villas|room|rooms|property|stay|s|p|home|accommodation)\/|[?&](space_?id|id)=/.test(l)) return 'مشاهده اقامتگاه';
    return 'باز کردن لینک';
  }
  function linkBtn(u, label, alt) {
    var a = document.createElement('a');
    a.className = 'lb' + (alt ? ' alt' : ''); a.href = fixUrl(u); a.target = '_blank'; a.rel = 'noopener noreferrer';
    a.innerHTML = svg('arrow-square-out', '') + '<span>' + esc(label) + '</span>';
    return a;
  }
  /** متن پیام: لینکِ تنها در یک خط ← دکمه‌ی شکیل؛ لینک وسط جمله ← لینک قابل کلیک */
  function richText(el, text, asButtons) {
    var lines = String(text).split('\n'), out = [], btns = [];
    for (var i = 0; i < lines.length; i++) {
      var ln = lines[i], m = ln.match(URL_RE);
      if (asButtons && m && m.length === 1) {
        var rest = ln.replace(m[0], '').replace(/^[\s\-–—•*·\d۰-۹.)]+/, '').replace(/[\s:：\-–—👈👉🔗➡️⬅️]+$/u, '').trim();
        if (rest.length <= 34) { btns.push([m[0], rest || linkLabel(m[0])]); continue; }
      }
      out.push(ln);
    }
    while (out.length && !out[out.length - 1].trim()) out.pop();
    var html = esc(out.join('\n')).replace(URL_RE, function (u) {
      var raw = u.replace(/&amp;/g, '&');
      return '<a class="il' + (asButtons ? '' : ' raw') + '" href="' + esc(fixUrl(raw)) + '" target="_blank" rel="noopener noreferrer">' + esc(asButtons ? linkLabel(raw) : raw) + '</a>';
    });
    if (html) { var sp = document.createElement('span'); sp.innerHTML = html; el.appendChild(sp); }
    if (btns.length) {
      var box = document.createElement('div'); box.className = 'lbs';
      btns.forEach(function (b, k) { box.appendChild(linkBtn(b[0], b[1], k > 0)); });
      el.appendChild(box);
    }
  }
  /** دکمه‌های ذخیره‌شده‌ی پیام (منوی بات، پاسخ خودکار، درخواست شماره) */
  function payloadButtons(d, x) {
    var list = x.buttons || [];
    if (!list.length && !x.contact) return;
    var box = document.createElement('div'); box.className = 'lbs';
    list.forEach(function (b, k) {
      if (b.u) { box.appendChild(linkBtn(b.u, b.t, k > 0)); return; }
      var bt = document.createElement('button'); bt.type = 'button'; bt.className = 'lb alt'; bt.textContent = b.t;
      bt.addEventListener('click', function () {
        if (chat.sending) return;
        Array.prototype.forEach.call(box.querySelectorAll('button'), function (z) { z.disabled = true; });
        bt.classList.remove('alt');
        submit(b.t, '', '', b.c || '');
      });
      box.appendChild(bt);
    });
    if (x.contact) {
      var cb = document.createElement('button'); cb.type = 'button'; cb.className = 'lb';
      cb.innerHTML = (/^[\u0600-\u06FFa-z0-9]/i.test(x.contact) ? svg('device-mobile', '') : '') + '<span>' + esc(x.contact) + '</span>';
      cb.addEventListener('click', function () { phoneCard(); });
      box.appendChild(cb);
    }
    d.appendChild(box);
  }
  function qrBox(opts) {
    if (!opts || !opts.length) return;
    var old = chat.el.body.querySelectorAll('.qr'); for (var i = 0; i < old.length; i++) old[i].remove();
    var box = document.createElement('div'); box.className = 'qr';
    box.innerHTML = opts.map(function (o) { return '<button type="button">' + esc(String(o)) + '</button>'; }).join('');
    Array.prototype.forEach.call(box.querySelectorAll('button'), function (b) { b.addEventListener('click', function () { box.remove(); submit(b.textContent); }); });
    chat.el.body.appendChild(box);
  }
  /** کروسل کارت اقامتگاه‌ها با اسکرول افقی */
  /** ۲.۱.۰: کروسل عمومی (ساخته‌شده در پنل): عکس، عنوان، زیرعنوان و دکمه‌های دلخواه */
  function genCardsEl(x) {
    var w = document.createElement('div'); w.className = 'crw';
    var cards = x.cards.map(function (c) {
      var bts = (c.b || []).map(function (b, k) {
        return b.u ? '<a class="lb' + (k ? ' alt' : '') + '" href="' + esc(fixUrl(b.u)) + '" target="_blank" rel="noopener noreferrer"><span>' + esc(b.t) + '</span></a>'
                   : '<button class="lb alt gb" type="button" data-k="' + k + '"><span>' + esc(b.t) + '</span></button>';
      }).join('');
      return '<div class="cd">' + (c.img ? '<span class="cd-i"><img src="' + esc(c.img) + '" alt="" loading="lazy" referrerpolicy="no-referrer"></span>' : '') +
        '<div class="cd-b"><div class="cd-t" title="' + esc(c.t) + '">' + esc(c.t) + '</div>' + (c.d ? '<div class="cd-x">' + esc(c.d) + '</div>' : '') +
        (bts ? '<div class="cd-acts" style="flex-direction:column">' + bts + '</div>' : '') + '</div></div>';
    }).join('');
    w.innerHTML = '<div class="cr" role="list">' + cards + '</div>' +
      (x.cards.length > 1 ? '<button class="cr-nav pv" type="button" aria-label="قبلی">' + svg('caret-right', '') + '</button><button class="cr-nav nx" type="button" aria-label="بعدی">' + svg('caret-left', '') + '</button>' : '');
    Array.prototype.forEach.call(w.querySelectorAll('.cd-i img'), function (im) { im.addEventListener('error', function () { im.remove(); }); });
    Array.prototype.forEach.call(w.querySelectorAll('.cd'), function (el, i) {
      Array.prototype.forEach.call(el.querySelectorAll('.gb'), function (bt) {
        bt.addEventListener('click', function () {
          var b = (x.cards[i].b || [])[+bt.getAttribute('data-k')] || {};
          if (chat.sending) return; submit(b.t, '', '', b.c || '');
        });
      });
    });
    var track = w.querySelector('.cr');
    var step = function (dir) { var cd = track.querySelector('.cd'); track.scrollBy({ left: dir * ((cd ? cd.offsetWidth : 200) + 10), behavior: 'smooth' }); };
    var pv = w.querySelector('.pv'), nx = w.querySelector('.nx');
    if (pv) pv.addEventListener('click', function () { step(1); });
    if (nx) nx.addEventListener('click', function () { step(-1); });
    return w;
  }
  function cardsEl(x) {
    if (x.cards && x.cards[0] && x.cards[0].g) return genCardsEl(x);
    var w = document.createElement('div'); w.className = 'crw';
    var c0 = x.cards[0] || {};
    var head = '<div class="cr-h">' + svg('calendar-blank', '') + '<span>' + esc(x.cards.length > 1 ? x.cards.length.toLocaleString('fa-IR') + ' اقامتگاه پیشنهادی' : 'اقامتگاه پیشنهادی') + (c0.d ? ' · ' + esc(c0.d) : '') + '</span></div>';
    var btn = esc(x.btn || 'ثبت رزرو');
    var cards = x.cards.map(function (c, i) {
      var href = esc(fixUrl(c.u));
      return '<div class="cd"><a class="cd-i" href="' + href + '" target="_blank" rel="noopener noreferrer" tabindex="-1" aria-hidden="true">' +
        '<span class="ph">' + svg('storefront', '') + '</span>' + (c.img ? '<img src="' + esc(c.img) + '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '') +
        '<span class="cd-n">' + (i + 1).toLocaleString('fa-IR') + '</span></a>' +
        '<div class="cd-b"><div class="cd-t" title="' + esc(c.t) + '">' + esc(c.t) + '</div>' +
        (c.pl2 ? '<div class="cd-m">' + svg('map-pin', '') + '<span>' + esc(c.pl2) + '</span></div>' : '') +
        (c.d ? '<div class="cd-m">' + svg('calendar-blank', '') + '<span>' + esc(c.d) + '</span></div>' : '') +
        (c.n ? '<div class="cd-x">' + esc(c.n) + '</div>' : '') +
        (c.pf ? '<div class="cd-pr"><small>' + esc(c.pl || 'قیمت') + '</small><b>' + esc(c.pf) + '</b></div>' : '') +
        // ۱.۰.۱۶: «مشاهده» صفحه را در تب تازه باز می‌کند؛ «ثبت رزرو» رزرو را همین‌جا در گفتگو جلو می‌برد
        '<div class="cd-acts"><a class="lb alt" href="' + href + '" target="_blank" rel="noopener noreferrer">' + svg('arrow-square-out', '') + '<span>مشاهده</span></a>' +
        '<button class="lb cd-book" type="button" data-i="' + i + '">' + svg('check', '') + '<span>' + btn + '</span></button></div></div></div>';
    }).join('');
    w.innerHTML = head + '<div class="cr" role="list">' + cards + '</div>' +
      (x.cards.length > 1 ? '<button class="cr-nav pv" type="button" aria-label="قبلی">' + svg('caret-right', '') + '</button><button class="cr-nav nx" type="button" aria-label="بعدی">' + svg('caret-left', '') + '</button>' : '');
    Array.prototype.forEach.call(w.querySelectorAll('.cd-i img'), function (im) { im.addEventListener('error', function () { im.remove(); }); });
    Array.prototype.forEach.call(w.querySelectorAll('.cd-book'), function (b) {
      b.addEventListener('click', function () {
        var c = x.cards[+b.getAttribute('data-i')] || {};
        if (chat.sending) return;
        b.disabled = true; setTimeout(function () { b.disabled = false; }, 4000);
        submit('می‌خواهم «' + (c.t || '') + '»' + (c.id ? ' (کد ' + c.id + ')' : '') + ' را رزرو کنم' + (c.d ? '، ' + c.d : '') + '.');
      });
    });
    var track = w.querySelector('.cr');
    var step = function (dir) { var cd = track.querySelector('.cd'); track.scrollBy({ left: dir * ((cd ? cd.offsetWidth : 200) + 10), behavior: 'smooth' }); };
    var pv = w.querySelector('.pv'), nx = w.querySelector('.nx');
    if (pv) pv.addEventListener('click', function () { step(1); });
    if (nx) nx.addEventListener('click', function () { step(-1); });
    return w;
  }
  /** کارت ارسال شماره (دکمه‌ی «ارسال شماره» منوی بات) */
  function phoneCard() {
    if (chat.el.body.querySelector('.card.pc')) return;
    var card = document.createElement('div'); card.className = 'card pc';
    card.innerHTML = '<p>شماره‌ی موبایلتان را وارد کنید:</p><input class="c-phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="0912 123 4567" style="text-align:right">' +
      '<div style="display:flex;gap:8px"><button class="btn c-go" type="button">ارسال</button><button class="btn ghost c-no" type="button">انصراف</button></div>';
    chat.el.body.appendChild(card); scroll();
    var inp = card.querySelector('input'); try { inp.focus({ preventScroll: true }); } catch (e) {}
    card.querySelector('.c-no').addEventListener('click', function () { card.remove(); });
    card.querySelector('.c-go').addEventListener('click', function () {
      if (!validPhone(inp.value)) { showErr('شماره موبایل را درست وارد کنید (مثل 09121234567)'); return; }
      card.remove(); submit(inp.value.trim(), '', inp.value.trim());
    });
  }
  /** پنجره‌ی تماس سریع با پشتیبان (Click to Call) + «با من تماس بگیرید» */
  function showCall() {
    var c = CFG.chat, k = c && c.call; if (!k || !chat.el.panel) return;
    var old = chat.el.panel.querySelector('.csh'); if (old) { old.remove(); return; }
    var sh = document.createElement('div'); sh.className = 'csh';
    // ۱.۰.۱۴: یک یا چند شماره‌ی شرکت (خود مشتری تماس می‌گیرد) + «همکاران با من تماس بگیرند»
    var phones = (k.phones && k.phones.length) ? k.phones : (k.phone ? [{ label: '', num: k.phone }] : []);
    var nums = phones.map(function (p, i) {
      var shown = String(p.num).replace(/^\+98/, '0');
      return '<a class="lb big' + (i ? ' alt' : '') + '" href="tel:' + esc(p.num) + '">' + svg('phone-call', '') + '<span>' + esc(p.label ? 'تماس با ' + p.label : (k.label || 'تماس با ما')) + '</span></a>' +
        '<div class="csh-num"><span>' + esc(shown) + '</span><button type="button" class="cp" data-n="' + esc(shown) + '">' + svg('copy', '') + '<i>کپی</i></button></div>';
    }).join('');
    sh.innerHTML = '<div class="csh-in" role="dialog" aria-label="' + esc(k.label || 'تماس با ما') + '"><h4>' + svg('headset', '') + esc(k.label || 'تماس با ما') + '</h4>' +
      (k.note ? '<p>' + esc(k.note) + '</p>' : '') + nums +
      (k.callback ? (phones.length ? '<p style="text-align:center;margin-top:2px">یا</p>' : '') + '<button class="lb' + (phones.length ? ' alt' : ' big') + ' cbk" type="button">' + svg('phone', '') + '<span>همکاران با من تماس بگیرند</span></button>' : '') +
      '<div class="cbf"></div><button class="btn ghost csx" type="button">بستن</button></div>';
    chat.el.panel.appendChild(sh);
    sh.addEventListener('click', function (e) { if (e.target === sh) sh.remove(); });
    sh.querySelector('.csx').addEventListener('click', function () { sh.remove(); });
    Array.prototype.forEach.call(sh.querySelectorAll('.cp'), function (cp) {
      cp.addEventListener('click', function () {
        var n = cp.getAttribute('data-n'), lab = cp.querySelector('i');
        try { navigator.clipboard.writeText(n).then(function () { lab.textContent = 'کپی شد'; }); }
        catch (e) { var t = document.createElement('textarea'); t.value = n; sh.appendChild(t); t.select(); try { document.execCommand('copy'); lab.textContent = 'کپی شد'; } catch (e2) {} t.remove(); }
      });
    });
    var cbk = sh.querySelector('.cbk');
    if (cbk) cbk.addEventListener('click', function () { cbk.style.display = 'none'; callbackForm(sh.querySelector('.cbf'), sh); });
  }
  function callbackForm(box, sh) {
    var whens = ['همین الان', 'امروز عصر', 'فردا صبح'], when = whens[0];
    box.innerHTML = '<div class="card" style="border:0;padding:0;gap:8px"><input class="f-name" type="text" autocomplete="name" placeholder="نام شما">' +
      '<input class="f-phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="شماره موبایل، مثل 09121234567" style="text-align:right">' +
      '<div class="wh">' + whens.map(function (w, i) { return '<button type="button" class="' + (i ? '' : 'on') + '">' + esc(w) + '</button>'; }).join('') + '</div>' +
      '<button class="lb f-go" type="button">' + svg('check', '') + '<span>ثبت درخواست تماس</span></button></div>';
    Array.prototype.forEach.call(box.querySelectorAll('.wh button'), function (b) {
      b.addEventListener('click', function () { Array.prototype.forEach.call(box.querySelectorAll('.wh button'), function (z) { z.classList.remove('on'); }); b.classList.add('on'); when = b.textContent; });
    });
    var go = box.querySelector('.f-go');
    go.addEventListener('click', function () {
      var n = box.querySelector('.f-name').value, p = box.querySelector('.f-phone').value;
      if (!validPhone(p)) { showErr('شماره موبایل را درست وارد کنید (مثل 09121234567)'); return; }
      if (PREVIEW) { sh.remove(); addNote('درخواست تماس ثبت شد (پیش‌نمایش)'); return; }
      go.disabled = true;
      ensureSession(function (ok) {
        if (!ok) { go.disabled = false; return; }
        request('callback', { token: chat.token, name: n, phone: p, when: when }, function (r) {
          go.disabled = false;
          if (!r || !r.ok) { showErr((r && r.error) || 'ثبت نشد'); return; }
          chat.identified = true; sh.remove();
          addNote('درخواست تماس ثبت شد؛ همکاران ما به‌زودی با شما تماس می‌گیرند 📞');
          setTimeout(poll, 800);
        });
      });
    });
  }

  function needGate() {
    var c = CFG.chat;
    return (c.startMode === 'form' || c.startMode === 'require_phone' || c.design.prechat.email_required) && !chat.lastId && !chat.el.body.querySelector('.msg.v');
  }

  function showGate() {
    var c = CFG.chat, Q = c.design.prechat, el = chat.el;
    el.gate.style.display = 'flex'; el.body.style.display = 'none'; el.ft.style.display = 'none';
    var f = '';
    if (c.askName) f += '<input class="g-name" type="text" autocomplete="name" placeholder="نام شما">';
    if (c.askPhone || c.startMode === 'require_phone') f += '<input class="g-phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="شماره موبایل' + (c.startMode === 'require_phone' ? ' (لازم)' : '') + '">';
    if (Q.email) f += '<input class="g-email" type="email" autocomplete="email" dir="ltr" placeholder="ایمیل' + (Q.email_required ? ' (لازم)' : '') + '" style="text-align:right">';
    el.gate.innerHTML = '<h4>' + esc(c.title || 'گفتگو با پشتیبانی') + '</h4><p>' + esc(CFG.online !== false ? (c.welcome || '') : (c.offlineText || '')) + '</p>' +
      (Q.note ? '<p style="font-size:12px">' + esc(Q.note) + '</p>' : '') + f +
      '<textarea class="g-text" rows="3" placeholder="پیام شما…"></textarea><button class="btn g-go" type="button">شروع گفتگو</button>';
    el.gate.querySelector('.g-go').addEventListener('click', function () {
      var n = el.gate.querySelector('.g-name'), p = el.gate.querySelector('.g-phone'), m = el.gate.querySelector('.g-email');
      var text = el.gate.querySelector('.g-text').value.trim();
      if (!text) { showErr('پیامتان را بنویسید'); return; }
      if (c.startMode === 'require_phone' && (!p || !validPhone(p.value))) { showErr('برای شروع گفتگو شماره موبایل لازم است'); return; }
      if (p && p.value && !validPhone(p.value)) { showErr('شماره موبایل را درست وارد کنید'); return; }
      if (m && Q.email_required && !validEmail(m.value)) { showErr('ایمیل را درست وارد کنید'); return; }
      if (m && m.value && !validEmail(m.value)) { showErr('ایمیل را درست وارد کنید'); return; }
      if (m && m.value) text = text + '\n\nایمیل: ' + m.value.trim();
      hideGate(); submit(text, n ? n.value : '', p ? p.value : '');
    });
  }
  function hideGate() { var el = chat.el; el.gate.style.display = 'none'; el.gate.innerHTML = ''; el.body.style.display = 'flex'; el.ft.style.display = 'flex'; }

  function maybeAskIdentity() {
    var c = CFG.chat;
    if (chat.askedIdentity || chat.identified || c.startMode !== 'open' || (!c.askName && !c.askPhone)) return;
    chat.askedIdentity = true;
    var card = document.createElement('div'); card.className = 'card';
    card.innerHTML = '<p>اگر شماره‌تان را بگذارید، اگر گفتگو قطع شد پیگیری می‌کنیم.</p>' +
      (c.askName ? '<input class="c-name" type="text" placeholder="نام شما">' : '') +
      (c.askPhone ? '<input class="c-phone" type="tel" inputmode="tel" placeholder="شماره موبایل">' : '') +
      '<div style="display:flex;gap:8px"><button class="btn c-go" type="button">ثبت</button><button class="btn ghost c-no" type="button">بعداً</button></div>';
    chat.el.body.appendChild(card); scroll();
    card.querySelector('.c-no').addEventListener('click', function () { card.remove(); });
    card.querySelector('.c-go').addEventListener('click', function () {
      var n = card.querySelector('.c-name'), p = card.querySelector('.c-phone');
      if (p && p.value && !validPhone(p.value)) { showErr('شماره موبایل را درست وارد کنید'); return; }
      request('send', { token: chat.token, text: 'اطلاعات تماس ثبت شد', name: n ? n.value : '', phone: p ? p.value : '', page_url: location.href }, function (r) {
        if (r && r.ok) { chat.identified = true; card.remove(); addNote('اطلاعات تماس شما ثبت شد'); }
        else showErr((r && r.error) || 'ثبت نشد');
      });
    });
  }

  /** ساخت/ادامه‌ی نشست — فقط وقتی لازم است */
  function ensureSession(done) {
    if (PREVIEW) { done(true); return; }
    if (chat.started) { done(true); return; }
    request('init', { token: chat.token || '', page_url: location.href, referer: document.referrer || '' }, function (r) {
      if (!r || !r.ok) { showErr((r && r.error) || 'گفتگو در دسترس نیست'); done(false); return; }
      chat.started = true; chat.token = r.token; store(TOKEN_KEY, r.token);
      if (typeof r.settings === 'object' && r.settings) CFG.online = r.settings.online;
      chat.el.body.innerHTML = '';
      if (r.messages && r.messages.length) {
        r.messages.forEach(function (m) { addMsg(m.from, m.text, m.media, m.id, m.ai, m.by, m.x); });
        chat.identified = true;
      }
      chat.lastId = r.last_id || chat.lastId || 0; store(LASTID_KEY, String(chat.lastId));
      done(true);
    });
  }

  function welcome() {
    var c = CFG.chat;
    if (chat.el.body.children.length) return;
    if (c.startMode === 'open' || !needGate()) addMsg('agent', CFG.online !== false ? (c.welcome || '') : (c.offlineText || ''));
    quickReplies();
  }

  function openChat() {
    if (!CFG.chat || !chat.el.panel) return;
    chat.open = true;
    chat.el.panel.classList.add('on');
    if (chat.el.dock) chat.el.dock.style.visibility = isMobile() && CFG.chat.design.panel.mobile_full ? 'hidden' : '';
    var g = root.querySelectorAll('.greet'); for (var i = 0; i < g.length; i++) g[i].remove();
    setBadge(0);
    ensureSession(function (ok) {
      if (!ok) return;
      if (needGate()) showGate(); else { welcome(); try { chat.el.input.focus({ preventScroll: true }); } catch (e) {} }
      if (!PREVIEW) { poll(); startPoll(); }
    });
  }

  function closeChat() {
    chat.open = false;
    chat.el.panel.classList.remove('on');
    if (chat.el.dock) chat.el.dock.style.visibility = '';
    stopPoll(); slowPoll();
  }

  function submit(text, name, phone, cb) {
    text = String(text || '').trim();
    if (!text || chat.sending) return;
    var q = chat.el.body.querySelectorAll('.qr'); for (var qi = 0; qi < q.length; qi++) q[qi].remove();
    addMsg('visitor', text);
    if (PREVIEW) { setTimeout(function () { addMsg('agent', 'این پیش‌نمایش است؛ پاسخ اپراتور اینجا نمایش داده می‌شود.'); }, 700); return; }
    chat.sending = true; chat.el.send.disabled = true;
    request('send', { token: chat.token, text: text, name: name || '', phone: phone || '', page_url: location.href, cb: cb || '' }, function (r) {
      chat.sending = false; chat.el.send.disabled = false;
      if (!r || !r.ok) { showErr((r && r.error) || 'پیام ارسال نشد'); return; }
      if (phone) chat.identified = true;
      if (CFG.online === false) addNote(CFG.chat.offlineText || '');
      setTimeout(poll, 700);
    });
  }

  function poll() {
    if (!chat.token || PREVIEW) return;
    if (chat.polling) { chat.pollAgain = true; return; }   // ۱.۰.۱۶: یک درخواست در هر لحظه
    chat.polling = true;
    var active = chat.open && (typeof document.hasFocus !== 'function' || document.hasFocus());
    request('poll', { token: chat.token, after: chat.lastId, active: active }, function (r) {
      chat.polling = false;
      if (chat.pollAgain) { chat.pollAgain = false; setTimeout(poll, 300); }
      if (!r || !r.ok) return;
      var n = (r.messages || []).length;
      (r.messages || []).forEach(function (m) { if (chat.open) addMsg('agent', m.text, m.media, m.id, m.ai, m.by, m.x); });
      (r.events || []).forEach(function (ev) {
        if (!ev || !ev.data) return;
        // ویرایش پیام توسط اپراتور
        if (ev.type === 'edit' && ev.data.id) { var el = chat.el && chat.el.body.querySelector('[data-mid="' + (+ev.data.id) + '"]'); if (el) { var img0 = el.querySelector('img'); el.innerHTML = ''; richText(el, String(ev.data.text || ''), true); if (img0) el.appendChild(img0); el.setAttribute('data-edited', '1'); } return; }
        // نظرسنجی رضایت: گزینه‌ها به‌صورت دکمه
        if (ev.type === 'csat' && chat.open && ev.data.opts) { csatChips(ev.data.opts); return; }
        // ۱.۰.۱۱: دکمه‌های پاسخ سریع دستیار
        if (ev.type === 'chips' && chat.open && ev.data.opts) { var old = chat.el.body.querySelector('.qr'); if (old) old.remove(); csatChips(ev.data.opts); return; }
        if (chat.open && ev.data.note) addNote(String(ev.data.note));
      });
      if (n) {
        if (chat.open) maybeAskIdentity();
        else setBadge(n);
        if ((!chat.open || document.hidden) && CFG.chat.design.behaviour.sound) beep();
      }
      if (typeof r.last_id === 'number' && r.last_id > chat.lastId && (chat.open || !n)) { chat.lastId = r.last_id; store(LASTID_KEY, String(chat.lastId)); }
      if (typeof r.online === 'boolean') { CFG.online = r.online; updateLive(r.online); }
      // در حال نوشتن: نشان بده و تا رسیدن پاسخ تندتر بپرس
      if (chat.open) setTyping(!!r.typing && !n);
      if (r.typing && chat.open) { clearTimeout(chat.fastT); chat.fastT = setTimeout(poll, 1500); }
    });
  }
  function startPoll() { stopPoll(); chat.pollTimer = setInterval(poll, Math.max(2, CFG.chat.pollSeconds || 4) * 1000); }
  function stopPoll() { if (chat.pollTimer) clearInterval(chat.pollTimer); chat.pollTimer = null; if (chat.slowTimer) clearInterval(chat.slowTimer); chat.slowTimer = null; }
  function slowPoll() {
    if (!chat.token || PREVIEW) return;
    if (chat.slowTimer) clearInterval(chat.slowTimer);
    chat.slowTimer = setInterval(function () { if (!chat.open && !document.hidden) poll(); }, 30000);
  }

  /* -------------------------------------------------------------- راه‌اندازی */
  function render(cfg) {
    stopPoll();
    CFG = cfg; fabEl = {}; chat.el = {}; chat.open = false;
    if (!CFG || (!CFG.fab && !CFG.chat)) { if (host) host.remove(); host = null; return; }
    mount();
    buildFab();
    buildChat();
  }

  function boot() {
    if (PREVIEW) { render(PREVIEW); window.EBW = { render: function (c) { PREVIEW = c; render(c); } }; return; }
    request('boot', {}, function (r) {
      if (!r || !r.ok) return;
      render({ fab: r.fab, chat: r.chat, online: r.online });
      window.EBW = { open: openChat, close: closeChat };
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
