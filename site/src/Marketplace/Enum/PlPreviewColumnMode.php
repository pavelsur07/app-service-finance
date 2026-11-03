<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Разрез колонок предпросмотра ОПиУ: по тегам листингов или по проектам ОПиУ.
 */
enum PlPreviewColumnMode: string
{
    case TAGS = 'tags';
    case PROJECTS = 'projects';
}
