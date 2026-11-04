-- =====================================================================
--   جاکجاست - Migration: افزودن ستون‌های جدید به جدول leads
--   برای پشتیبانی از سرنخ میهمان با فیلدهای کامل
-- =====================================================================
--   این فایل توسط مدل Lead به‌صورت خودکار اجرا می‌شود.
--   هر ALTER TABLE یک statement جداگانه است (با ; جدا می‌شود).
--   اگر ستون قبلاً وجود داشته باشد، خطا نادیده گرفته می‌شود.
-- =====================================================================

ALTER TABLE `leads` ADD COLUMN `operator` VARCHAR(120) DEFAULT NULL COMMENT 'اپراتور';
ALTER TABLE `leads` ADD COLUMN `status` VARCHAR(40) DEFAULT NULL COMMENT 'وضعیت سرنخ میهمان';
ALTER TABLE `leads` ADD COLUMN `reference` VARCHAR(60) DEFAULT NULL COMMENT 'مرجع';
ALTER TABLE `leads` ADD COLUMN `residence` VARCHAR(120) DEFAULT NULL COMMENT 'محل زندگی مشتری';
ALTER TABLE `leads` ADD COLUMN `requested_neighborhoods` TEXT DEFAULT NULL COMMENT 'محلات درخواستی (JSON)';
ALTER TABLE `leads` ADD COLUMN `property_type` VARCHAR(80) DEFAULT NULL COMMENT 'نوع اقامتگاه درخواستی';
ALTER TABLE `leads` ADD COLUMN `min_price` BIGINT DEFAULT NULL COMMENT 'حداقل قیمت';
ALTER TABLE `leads` ADD COLUMN `max_price` BIGINT DEFAULT NULL COMMENT 'حداکثر قیمت';
ALTER TABLE `leads` ADD COLUMN `recommended_properties` TEXT DEFAULT NULL COMMENT 'اقامتگاه‌های معرفی شده (JSON)';
ALTER TABLE `leads` ADD COLUMN `amenities_basic` TEXT DEFAULT NULL COMMENT 'امکانات پایه (JSON)';
ALTER TABLE `leads` ADD COLUMN `amenities_special` TEXT DEFAULT NULL COMMENT 'امکانات ویژه (JSON)';
ALTER TABLE `leads` ADD COLUMN `description` TEXT DEFAULT NULL COMMENT 'توضیحات';
ALTER TABLE `leads` ADD COLUMN `date` VARCHAR(20) DEFAULT NULL COMMENT 'تاریخ شمسی';
