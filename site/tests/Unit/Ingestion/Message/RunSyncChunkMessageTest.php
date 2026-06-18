<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Message;

use App\Ingestion\Message\CompanyAwareMessage;
use App\Ingestion\Message\RunSyncChunkMessage;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class RunSyncChunkMessageTest extends TestCase
{
    public function testMessageCarriesCompanyContext(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $jobId = Uuid::uuid7()->toString();

        $message = new RunSyncChunkMessage($companyId, $jobId);

        self::assertInstanceOf(CompanyAwareMessage::class, $message);
        self::assertSame($companyId, $message->companyId);
        self::assertSame($companyId, $message->getCompanyId());
        self::assertSame($jobId, $message->jobId);
    }
}
