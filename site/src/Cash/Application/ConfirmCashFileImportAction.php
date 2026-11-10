<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Exception\CashFileImportConfirmationException;
use App\Cash\Exception\CashFileImportSelectionException;
use App\Cash\Service\Import\File\CashFileDescriptionPlan;
use App\Cash\Service\Import\File\CashFileMappingValidator;

final readonly class ConfirmCashFileImportAction
{
    public function __construct(private CashFileDescriptionPlan $plans, private CashFileMappingValidator $mappingValidator, private StartCashFileImportJobAction $startJob)
    {
    }

    /** @param array<string, mixed> $mapping
     * @param array<array-key, mixed> $currentSelection
     * @param array<array-key, mixed> $visible
     * @param array<array-key, mixed> $selected
     */
    public function __invoke(MoneyAccount $account, string $fileName, string $fileHash, array $mapping, ?string $extension, ?string $actor, string $planHash, array $currentSelection, array $visible, array $selected): CashFileImportJob
    {
        $this->mappingValidator->validateForImport($mapping);
        $plan = $this->plans->load((string) $account->getCompany()->getId(), $planHash);
        if ($plan['accountId'] !== $account->getId() || $plan['fileHash'] !== $fileHash || $plan['actor'] !== $actor
            || $plan['mappingHash'] !== hash('sha256', json_encode($mapping, JSON_THROW_ON_ERROR))) {
            throw new CashFileImportSelectionException('План импорта не соответствует текущему файлу.');
        }
        $selection = $this->plans->selectDescriptions($plan, $currentSelection, '', $visible, $selected);
        $options = null !== $extension && '' !== $extension ? ['stored_ext' => $extension] : [];
        $options['description_plan'] = $planHash;
        $options['selected_descriptions'] = $selection;
        try {
            return ($this->startJob)($account->getCompany(), $account, 'cash:file', $fileName, $fileHash, $mapping, $options, $actor, $plan['id']);
        } catch (\DomainException $exception) {
            throw new CashFileImportConfirmationException('Это подтверждение уже использовано или настройки изменились. Проверьте новый план и повторно выберите обновления.', 0, $exception);
        }
    }
}
