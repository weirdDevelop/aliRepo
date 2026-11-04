<?php
/**
 * channels/InstagramRoboclickCommentAdapter.php
 * modules/IgCommentAutomation.php و modules/IgPhoneGate.php برای دایرکت‌ساز نوشته
 * شده‌اند و متدهایی مثل sendCommentReply($commentId, $text) را صدا می‌زنند —
 * یعنی «کدام پست» را نمی‌دانند، چون در API دایرکت‌ساز لازم نبود. در سرویس‌دهنده
 * SendComment/SendPrivateReplyToComment به postId هم نیاز دارند، پس این آداپتور
 * postId و contactId همان یک رویداد را می‌بندد و امضای «دایرکت‌سازی» را حفظ
 * می‌کند تا کل ماژول اتوماسیون کامنت بدون هیچ تغییری برای سرویس‌دهنده هم کار کند.
 */

class InstagramRoboclickCommentAdapter
{
    private InstagramRoboclickDriver $driver;
    private string $postId;
    private string $contactId;

    public function __construct(InstagramRoboclickDriver $driver, string $postId, string $contactId)
    {
        $this->driver = $driver;
        $this->postId = $postId;
        $this->contactId = $contactId;
    }

    public function name(): string { return 'instagram'; }

    public function capabilities(): array { return $this->driver->capabilities(); }

    /** همان امضای ChannelInterface::send — مستقیم rele می‌شود (channel_uid را خودِ درایور می‌فهمد) */
    public function send(array $recipient, array $message): array
    {
        return $this->driver->send($recipient, $message);
    }

    public function sendCommentReply(string $commentId, string $text): array
    {
        return $this->driver->sendCommentReply($this->postId, $text, $commentId);
    }

    /** $textOrMessage: رشته یا ['text'=>..,'buttons'=>[...]] — امضای دایرکت‌ساز */
    public function sendPrivateReply(string $commentId, $textOrMessage): array
    {
        if (is_array($textOrMessage)) {
            $text = (string)($textOrMessage['text'] ?? '');
            $buttons = is_array($textOrMessage['buttons'] ?? null) ? $textOrMessage['buttons'] : [];
            return $this->driver->sendPrivateReplyToComment($this->contactId, $commentId, $this->postId, $text, $buttons);
        }
        return $this->driver->sendPrivateReplyToComment($this->contactId, $commentId, $this->postId, (string)$textOrMessage);
    }

    /** امضای دایرکت‌ساز: ['ok'=>bool,'follows'=>bool]. forceRefresh همیشه true — تأخیر
     *  کش‌شدنِ وضعیت فالو دقیقاً همان چیزی بود که باعث می‌شد بعد از کلیک واقعی دکمه هم
     *  بگوید «هنوز فالو نکردی». */
    public function followStatus(string $channelUid): array
    {
        $r = $this->driver->followStatus($channelUid !== '' ? $channelUid : $this->contactId, true);
        return ['ok' => !empty($r['ok']), 'follows' => !empty($r['is_follower'])];
    }
}
