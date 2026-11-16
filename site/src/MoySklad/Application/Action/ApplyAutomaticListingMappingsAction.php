<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Application\Command\ApplyAutomaticMappingsCommand;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Application\DTO\AutomaticMappingApplyItem;
use App\MoySklad\Application\DTO\AutomaticMappingApplyResult;
use App\MoySklad\Application\DTO\AutomaticMappingGuard;
use App\MoySklad\Enum\AutomaticMappingApplyStatus as Status;
use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Exception\ListingMappingException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/** Each existing writer invocation owns its transaction. No batch flush/transaction. */
final readonly class ApplyAutomaticListingMappingsAction
{
    public function __construct(private ChangeListingMappingAction $writer, private EntityManagerInterface $em, private ClockInterface $clock, private LoggerInterface $logger)
    {
    }

    public function __invoke(ApplyAutomaticMappingsCommand $command): AutomaticMappingApplyResult
    {
        $preview = $command->preview;
        if ($preview->expiresAt <= $this->clock->now()->getTimestamp()) {
            throw new ListingMappingException('not_found', 'Предпросмотр истёк. Сформируйте новый.');
        }
        $byId = [];
        foreach ($preview->items as $item) {
            $byId[$item->listingId] = $item;
        }
        $results = [];
        $stopped = !$this->em->isOpen();
        foreach ($command->selectedIds as $id) {
            if ($stopped) {
                $results[] = new AutomaticMappingApplyItem($id, Status::NOT_PROCESSED, 'Обработка остановлена после технической ошибки. Повторите запрос.');
                continue;
            }
            $item = $byId[$id];
            try {
                $state = ($this->writer)(new ChangeListingMappingCommand($command->companyId, $command->actorUserId, $command->connectionId, $id,
                    ListingMappingOperation::SET, $item->targetType, $item->targetExternalId, 0, $item->requestId,
                    new AutomaticMappingGuard($preview->id, $preview->ruleVersion, $item->sourceFingerprint, $preview->sizeCharacteristicId, $item->decision->fingerprint())));
                $results[] = new AutomaticMappingApplyItem($id, Status::ACCEPTED, revisionId: $state->revisionId);
            } catch (ListingMappingException $error) {
                if ('listing_busy' === $error->reason) {
                    $this->logger->warning('MoySklad mapping source is busy.', ['companyId' => $command->companyId, 'listingId' => $id]);
                }
                $results[] = new AutomaticMappingApplyItem($id, Status::CONFLICT, $error->getMessage());
                $stopped = !$this->em->isOpen();
            } catch (\Throwable $error) {
                $results[] = new AutomaticMappingApplyItem($id, Status::FAILED, 'Техническая ошибка. Проверьте историю и повторите запрос.');
                $this->logger->error('Automatic MoySklad mapping row failed.', ['reason' => 'mapping_write_failed', 'companyId' => $command->companyId, 'connectionId' => $command->connectionId, 'listingId' => $id, 'previewId' => $preview->id, 'exceptionClass' => $error::class]);
                $stopped = true;
            }
        }

        return new AutomaticMappingApplyResult($results);
    }
}
