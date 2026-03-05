<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Application\ProcessRawDocumentAction;
use PHPUnit\Framework\TestCase;

final class ProcessMarketplaceRawDocumentActionTest extends TestCase
{
    public function testDelegatesToProcessRawDocumentAction(): void
    {
        $command = new ProcessMarketplaceRawDocumentCommand('company-1', 'raw-1', 'sales');

        $innerAction = $this->createMock(ProcessRawDocumentAction::class);
        $innerAction
            ->expects(self::once())
            ->method('__invoke')
            ->with($command)
            ->willReturn(11);

        $action = new ProcessMarketplaceRawDocumentAction($innerAction);

        self::assertSame(11, $action($command));
    }
}
