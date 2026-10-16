<?php

namespace App\DTOs;

use App\Enums\EmailType;

readonly class EmailPayload
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $htmlContent,
        public EmailType $type,
        public ?string $from = null,
    ) {}
}
