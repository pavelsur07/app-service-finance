<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Tests\Support\Kernel\WebTestCaseBase;

final class CashTransactionRouteRequirementTest extends WebTestCaseBase
{
    public function testShowRejectsMalformedUuidBeforeController(): void
    {
        $client = static::createClient();
        $client->request('GET', '/finance/cash-transactions/'.str_repeat('-', 36));

        self::assertResponseStatusCodeSame(404);
    }
}
