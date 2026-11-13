<?php

declare(strict_types=1);

namespace App\Cash\Application\Import\File;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Application\Service\Import\File\CashFileDescriptionPlan;
use App\Cash\Service\Import\File\CashFileMappingValidator;
use App\Shared\Service\Storage\TemporaryLocalFile;

/** @phpstan-import-type Plan from CashFileDescriptionPlan */
final readonly class PrepareCashFileImportAction
{
    public function __construct(private CashFileDescriptionPlan $plans, private TemporaryLocalFile $files, private CashFileMappingValidator $mappingValidator)
    {
    }

    /** @param array<string, mixed> $mapping
     * @return array{plan: Plan, hash: string, context: string, changed: bool}
     */
    public function __invoke(MoneyAccount $account, string $fileHash, string $storageKey, array $mapping, ?string $actor, ?string $storedContext, ?string $storedPlan): array
    {
        $this->mappingValidator->validateForImport($mapping);
        $context = hash('sha256', json_encode([$account->getCompany()->getId(), $account->getId(), $fileHash, $mapping, $actor], \JSON_THROW_ON_ERROR));
        $changed = $storedContext !== $context || null === $storedPlan;
        if ($changed) {
            $plan = $this->files->with($storageKey, fn (string $path): array => $this->plans->build($path, $account, $mapping, $fileHash, $actor));
            $hash = $this->plans->save($plan);
        } else {
            $plan = $this->plans->load((string) $account->getCompany()->getId(), $storedPlan);
            $hash = $storedPlan;
        }

        return ['plan' => $plan, 'hash' => $hash, 'context' => $context, 'changed' => $changed];
    }
}
