<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Entity\ConsolidationMembership;
use App\Company\Exception\InvalidConsolidationGroupException;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\ConsolidationMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Включает или выключает группу консолидации головной компании и задаёт её состав.
 * Владеет транзакцией; изменения кабинета владельца сериализует advisory-блокировка.
 * Отказ проверяется до записи и возвращается из транзакции значением: исключение внутри
 * wrapInTransaction закрыло бы EntityManager.
 */
final class ConfigureConsolidationGroupAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConsolidationMembershipRepository $memberships,
        private readonly CompanyRepository $companies,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $memberIds участники без головной компании
     *
     * @throws InvalidConsolidationGroupException
     */
    public function __invoke(string $headCompanyId, string $ownerUserId, bool $enabled, array $memberIds): void
    {
        Assert::uuid($headCompanyId);
        Assert::uuid($ownerUserId);
        Assert::allUuid($memberIds);
        $memberIds = $enabled ? array_values(array_unique($memberIds)) : [];
        sort($memberIds);

        $names = [];
        foreach ($this->companies->findByUserId($ownerUserId) as $company) {
            $names[(string) $company->getId()] = (string) $company->getName();
        }
        if (!isset($names[$headCompanyId])) {
            throw new InvalidConsolidationGroupException('Головная компания не принадлежит владельцу кабинета.');
        }
        if (\in_array($headCompanyId, $memberIds, true)) {
            throw new InvalidConsolidationGroupException('Головная компания не может быть в составе: она сама головная.');
        }
        foreach ($memberIds as $memberId) {
            if (!isset($names[$memberId])) {
                throw new InvalidConsolidationGroupException('В группу можно добавить только компании кабинета: одна из выбранных компаний вам не принадлежит.');
            }
        }

        /** @var string|null $refusal */
        $refusal = $this->entityManager->wrapInTransaction(function () use ($headCompanyId, $ownerUserId, $enabled, $memberIds, $names): ?string {
            $this->memberships->lockCabinet($ownerUserId);

            $own = $this->memberships->findOneByCompany($headCompanyId);
            if (null !== $own && !$own->isHead()) {
                return sprintf('Компания уже входит в группу компании «%s» — состав меняется в её настройках.', $names[$own->getHeadCompanyId()] ?? 'другого владельца');
            }
            foreach ($this->memberships->findByCompanies($memberIds) as $row) {
                if ($row->getHeadCompanyId() !== $headCompanyId) {
                    return sprintf('«%s» уже входит в группу компании «%s».', $names[$row->getCompanyId()], $names[$row->getHeadCompanyId()] ?? 'другого владельца');
                }
            }

            $current = [];
            foreach ($this->memberships->findGroupOfHead($headCompanyId) as $row) {
                $current[$row->getCompanyId()] = $row;
            }
            $wanted = $enabled ? [$headCompanyId, ...$memberIds] : [];
            foreach ($current as $companyId => $row) {
                if (!\in_array($companyId, $wanted, true)) {
                    $this->entityManager->remove($row);
                }
            }
            $now = $this->clock->now();
            foreach ($wanted as $companyId) {
                if (!isset($current[$companyId])) {
                    $this->entityManager->persist(new ConsolidationMembership($companyId, $headCompanyId, $now));
                }
            }
            // UnitOfWork вставляет раньше, чем удаляет; ключи вставок и удалений не пересекаются.
            $this->entityManager->flush();

            return null;
        });

        if (null !== $refusal) {
            throw new InvalidConsolidationGroupException($refusal);
        }

        $this->logger->info('Consolidation group configured', [
            'companyId' => $headCompanyId,
            'enabled' => $enabled,
            'members' => \count($memberIds),
        ]);
    }
}
