<?php
/**
 * webhook/_ig_bootstrap.php
 * بارگذاری مشترک وابستگی‌های پایپ‌لاین اینستاگرام چندپیجی (webhook/roboclick.php، webhook/apichi.php
 * و polling یار API در cron.php). همه require_once هستند تا از هر نقطه‌ی ورودی بی‌خطر include شود.
 */
$root = dirname(__DIR__);
require_once $root . '/core/Database.php';
require_once $root . '/core/Url.php';
require_once $root . '/modules/NetProxy.php';
require_once $root . '/channels/ChannelInterface.php';
require_once $root . '/channels/ChannelManager.php';
require_once $root . '/channels/InstagramRoboclickDriver.php';
require_once $root . '/channels/InstagramApichiDriver.php';
require_once $root . '/channels/InstagramRoboclickCommentAdapter.php';
require_once $root . '/modules/InstagramRoboclick.php';
require_once $root . '/modules/InstagramApichi.php';
require_once $root . '/modules/Persons.php';
require_once $root . '/modules/Personalize.php';
require_once $root . '/modules/ApiLog.php';
require_once $root . '/modules/Inbox.php';
require_once $root . '/modules/Notifier.php';
require_once $root . '/modules/Templates.php';
require_once $root . '/modules/BotReply.php';
require_once $root . '/modules/IgCommentAutomation.php';
require_once $root . '/modules/IgPhoneGate.php';
require_once $root . '/modules/Support.php';
require_once $root . '/modules/AiKb.php';
require_once $root . '/modules/Ai.php';
require_once $root . '/modules/AutoReply.php';
require_once $root . '/modules/BotMenu.php';
require_once $root . '/modules/Forms.php';
require_once $root . '/modules/IgWebhookPipeline.php';
