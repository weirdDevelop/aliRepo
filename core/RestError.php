<?php
/** core/RestError.php — typed REST v2 error (docs/api/error-contract.md). 2.2.0 */
final class RestError extends Exception
{
    public int $status;
    public string $errCode;
    public ?string $field;
    public array $details;

    public function __construct(int $status, string $code, string $message, ?string $field = null, array $details = [])
    {
        parent::__construct($message);
        $this->status = $status; $this->errCode = $code; $this->field = $field; $this->details = $details;
    }
}
