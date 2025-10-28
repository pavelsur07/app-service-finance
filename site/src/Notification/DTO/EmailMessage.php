<?php

// src/Notification/DTO/EmailMessage.php

namespace App\Notification\DTO;

final class EmailMessage
{
    public function __construct(
        public readonly string $to,                    // "user@example.com"
        public readonly ?string $subject,       // если null — возьмём из шаблона/переменных
        public readonly string $htmlTemplate,          // 'notifications/email/default.html.twig'
        public readonly ?string $textTemplate = null,  // 'notifications/email/default.txt.twig'
        public readonly array $vars = [],              // переменные для шаблонов
        public readonly ?string $replyTo = null,
        public readonly array $attachments = [],        // [['path'=>'/tmp/a.pdf','name'=>'...']]
    ) {
    }
}
