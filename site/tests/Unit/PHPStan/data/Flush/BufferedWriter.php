<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Flush;

final class BufferedWriter
{
    public function flush(): void
    {
    }

    public function close(): void
    {
        $this->flush();
    }
}
