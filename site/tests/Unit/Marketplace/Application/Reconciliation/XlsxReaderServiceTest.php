<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Reconciliation;

use App\Marketplace\Application\Reconciliation\XlsxReaderService;
use App\Marketplace\Exception\ReconciliationFileReadException;
use PHPUnit\Framework\TestCase;

final class XlsxReaderServiceTest extends TestCase
{
    /**
     * M4: повреждённый/недоступный файл должен давать понятное доменное
     * исключение, а не падать необработанным низкоуровневым исключением.
     */
    public function testThrowsDomainExceptionWhenFileCannotBeOpened(): void
    {
        $service = new XlsxReaderService();

        $this->expectException(ReconciliationFileReadException::class);

        $service->read('/nonexistent/path/reconciliation-does-not-exist.xlsx');
    }
}
