<?php

declare(strict_types=1);

namespace App\Shared\Messenger;

enum FailedQueueVerdict: string
{
    /** Очередь пуста. */
    case OK = 'ok';

    /** Есть сообщения, но они свежие и объём невелик: оператор ещё может их не успеть разобрать. */
    case WARNING = 'warning';

    /** Сообщение пролежало дольше допустимого или очередь разрослась: проблема остаётся без внимания. */
    case ERROR = 'error';
}
