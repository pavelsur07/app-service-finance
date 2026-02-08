<?php

namespace App\Deals\Service;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Repository\CompanyMemberRepository;
use App\Deals\Entity\Deal;
use App\Deals\Entity\DealAdjustment;
use App\Deals\Entity\DealCharge;
use App\Deals\Entity\DealItem;
use App\Deals\Enum\DealStatus;
use App\Deals\Exception\AccessDenied;
use App\Deals\Exception\DealNotFound;
use App\Deals\Exception\InvalidDealState;
use App\Deals\Exception\ValidationFailed;
use App\Deals\Repository\ChargeTypeRepository;
use App\Deals\Service\Request\AddDealAdjustmentRequest;
use App\Deals\Service\Request\AddDealChargeRequest;
use App\Deals\Service\Request\AddDealItemRequest;
use App\Deals\Service\Request\CreateDealRequest;
use App\Deals\Service\Request\RemoveDealChargeRequest;
use App\Deals\Service\Request\RemoveDealItemRequest;
use App\Deals\Service\Request\UpdateDealHeaderRequest;
use App\Deals\Service\Request\UpdateDealItemRequest;
use App\Entity\Counterparty;
use App\Repository\CounterpartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class DealManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DealTotalsCalculator $totalsCalculator,
        private readonly DealNumberGenerator $numberGenerator,
        private readonly CounterpartyRepository $counterpartyRepository,
        private readonly ChargeTypeRepository $chargeTypeRepository,
        private readonly CompanyMemberRepository $companyMemberRepository,
    ) {
    }

    public function createDeal(CreateDealRequest $req, User $user, Company $company): Deal
    {
        $this->assertCompanyAccess($user, $company);

        return $this->transactional(function () use ($req, $company): Deal {
            $number = $this->resolveDealNumber($req->number, $company);

            $deal = new Deal(
                id: Uuid::uuid4()->toString(),
                company: $company,
                number: $number,
                type: $req->type,
                channel: $req->channel,
                recognizedAt: $req->recognizedAt,
            );

            $deal->setTitle($req->title);
            $deal->setOccurredAt($req->occurredAt);
            $deal->setCurrency($req->currency);

            if ($req->counterpartyId) {
                $deal->setCounterparty($this->findCounterparty($req->counterpartyId, $company));
            }

            $this->totalsCalculator->recalc($deal);

            $this->em->persist($deal);

            return $deal;
        });
    }

    public function updateDealHeader(
        UpdateDealHeaderRequest $req,
        Deal $deal,
        User $user,
        Company $company,
    ): Deal {
        $this->assertCompanyAccess($user, $company);
        $this->assertDealAccess($deal, $company);
        $this->assertDealEditable($deal);

        return $this->transactional(function () use ($req, $deal, $company): Deal {
            $number = $this->normalizeNumber($req->number);

            if (!$number) {
                throw new ValidationFailed('Deal number is required.');
            }

            $deal->setNumber($number);
            $deal->setTitle($req->title);
            $deal->setType($req->type);
            $deal->setChannel($req->channel);
            $deal->setRecognizedAt($req->recognizedAt);
            $deal->setOccurredAt($req->occurredAt);
            $deal->setCurrency($req->currency);
            $deal->setUpdatedAt(new \DateTimeImmutable());

            if ($req->counterpartyId) {
                $deal->setCounterparty($this->findCounterparty($req->counterpartyId, $company));
            } else {
                $deal->setCounterparty(null);
            }

            return $deal;
        });
    }

    public function addItem(AddDealItemRequest $req, Deal $deal, User $user, Company $company): Deal
    {
        $this->assertCompanyAccess($user, $company);
        $this->assertDealAccess($deal, $company);
        $this->assertDealEditable($deal);

        return $this->transactional(function () use ($req, $deal): Deal {
            $this->assertLineIndexAvailable($deal, $req->lineIndex);

            $item = new DealItem(
                name: $req->name,
                kind: $req->kind,
                qty: $req->qty,
                price: $req->price,
                amount: $req->amount,
                lineIndex: $req->lineIndex,
                deal: $deal,
                unit: $req->unit,
            );

            $deal->addItem($item);
            $deal->setUpdatedAt(new \DateTimeImmutable());
            $this->totalsCalculator->recalc($deal);

            $this->em->persist($item);

            return $deal;
        });
    }

    public function updateItem(UpdateDealItemRequest $req, Deal $deal, User $user, Company $company): Deal
    {
        $this->assertCompanyAccess($user, $company);
        $this->assertDealAccess($deal, $company);
        $this->assertDealEditable($deal);

        return $this->transactional(function () use ($req, $deal): Deal {
            $item = $this->findItem($deal, $req->itemId);

            if ($item->getLineIndex() !== $req->lineIndex) {
                $this->assertLineIndexAvailable($deal, $req->lineIndex);
            }

            $item->setName($req->name);
            $item->setKind($req->kind);
            $item->setQty($req->qty);
            $item->setPrice($req->price);
            $item->setAmount($req->amount);
            $item->setLineIndex($req->lineIndex);
            $item->setUnit($req->unit);

            $deal->setUpdatedAt(new \DateTimeImmutable());
            $this->totalsCalculator->recalc($deal);

            return $deal;
        });
    }

    public function removeItem(RemoveDealItemRequest $req, Deal $deal, User $user, Company $company): Deal
    {
        $this->assertCompanyAccess($user, $company);
        $this->assertDealAccess($deal, $company);
        $this->assertDealEditable($deal);

        return $this->transactional(function () use ($req, $deal): Deal {
            $item = $this->findItem($deal, $req->itemId);

            $deal->removeItem($item);
            $deal->setUpdatedAt(new \DateTimeImmutable());
            $this->totalsCalculator->recalc($deal);

            $this->em->remove($item);

            return $deal;
        });
    }

    public function addCharge(AddDealChargeRequest $req, Deal $deal, User $user, Company $company): Deal
    {
        $this->assertCompanyAccess($user, $company);
        $this->assertDealAccess($deal, $company);
        $this->assertDealEditable($deal);

        return $this->transactional(function () use ($req, $deal, $company): Deal {
            $chargeType = $this->chargeTypeRepository->find($req->chargeTypeId);
            if (!$chargeType || $chargeType->getCompany() !== $company || !$chargeType->isActive()) {
                throw new ValidationFailed('Charge type is invalid.');
            }

            $charge = new DealCharge(
                recognizedAt: $req->recognizedAt,
                amount: $req->amount,
                chargeType: $chargeType,
                deal: $deal,
                comment: $req->comment,
            );

            $deal->addCharge($charge);
            $deal->setUpdatedAt(new \DateTimeImmutable());
            $this->totalsCalculator->recalc($deal);

            $this->em->persist($charge);

            return $deal;
        });
    }

    public function removeCharge(RemoveDealChargeRequest $req, Deal $deal, User $user, Company $company): Deal
    {
        $this->assertCompanyAccess($user, $company);
        $this->assertDealAccess($deal, $company);
        $this->assertDealEditable($deal);

        return $this->transactional(function () use ($req, $deal): Deal {
            $charge = $this->findCharge($deal, $req->chargeId);

            $deal->removeCharge($charge);
            $deal->setUpdatedAt(new \DateTimeImmutable());
            $this->totalsCalculator->recalc($deal);

            $this->em->remove($charge);

            return $deal;
        });
    }

    public function addAdjustment(AddDealAdjustmentRequest $req, Deal $deal, User $user, Company $company): Deal
    {
        $this->assertCompanyAccess($user, $company);
        $this->assertDealAccess($deal, $company);
        $this->assertDealAllowsAdjustment($deal);

        return $this->transactional(function () use ($req, $deal): Deal {
            $adjustment = new DealAdjustment(
                recognizedAt: $req->recognizedAt,
                amount: $req->amount,
                type: $req->type,
                deal: $deal,
                comment: $req->comment,
            );

            $deal->addAdjustment($adjustment);
            $deal->setUpdatedAt(new \DateTimeImmutable());
            $this->totalsCalculator->recalc($deal);

            $this->em->persist($adjustment);

            return $deal;
        });
    }

    public function confirmDeal(Deal $deal, User $user, Company $company): Deal
    {
        $this->assertCompanyAccess($user, $company);
        $this->assertDealAccess($deal, $company);

        return $this->transactional(function () use ($deal): Deal {
            if ($deal->isConfirmed()) {
                throw new InvalidDealState('Deal is already confirmed.');
            }

            if ($deal->isCancelled() || DealStatus::CLOSED === $deal->getStatus()) {
                throw new InvalidDealState('Deal cannot be confirmed in the current state.');
            }

            $deal->markConfirmed();

            return $deal;
        });
    }

    public function cancelDeal(Deal $deal, User $user, Company $company): Deal
    {
        $this->assertCompanyAccess($user, $company);
        $this->assertDealAccess($deal, $company);

        return $this->transactional(function () use ($deal): Deal {
            if ($deal->isCancelled() || DealStatus::CLOSED === $deal->getStatus()) {
                throw new InvalidDealState('Deal cannot be cancelled in current state.');
            }

            $deal->markCancelled();

            return $deal;
        });
    }

    private function transactional(callable $operation): Deal
    {
        $this->em->beginTransaction();

        try {
            $result = $operation();
            $this->em->flush();
            $this->em->commit();

            return $result;
        } catch (\Throwable $exception) {
            $this->rollbackSafely();

            throw $exception;
        }
    }

    private function rollbackSafely(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            $this->em->rollback();
        }
    }

    private function assertCompanyAccess(User $user, Company $company): void
    {
        if ($company->getUser() === $user) {
            return;
        }

        $member = $this->companyMemberRepository->findActiveOneByCompanyAndUser($company, $user);
        if (!$member) {
            throw new AccessDenied('User has no access to the company.');
        }
    }

    private function assertDealAccess(Deal $deal, Company $company): void
    {
        if (!$deal->getId()) {
            throw new DealNotFound('Deal not found.');
        }

        if ($deal->getCompany() !== $company) {
            throw new AccessDenied('Deal does not belong to the company.');
        }
    }

    private function assertDealEditable(Deal $deal): void
    {
        if ($deal->isConfirmed()) {
            throw new InvalidDealState('Confirmed deals can only be adjusted.');
        }

        if ($deal->isCancelled() || DealStatus::CLOSED === $deal->getStatus()) {
            throw new InvalidDealState('Deal cannot be changed in the current state.');
        }
    }

    private function assertDealAllowsAdjustment(Deal $deal): void
    {
        if ($deal->isCancelled() || DealStatus::CLOSED === $deal->getStatus()) {
            throw new InvalidDealState('Deal cannot be adjusted in the current state.');
        }
    }

    private function resolveDealNumber(?string $number, Company $company): string
    {
        if (null === $number) {
            return $this->numberGenerator->generate($company);
        }

        $normalized = $this->normalizeNumber($number);

        if (!$normalized) {
            throw new ValidationFailed('Deal number is required.');
        }

        return $normalized;
    }

    private function normalizeNumber(string $number): string
    {
        return trim($number);
    }

    private function findCounterparty(string $counterpartyId, Company $company): Counterparty
    {
        $counterparty = $this->counterpartyRepository->find($counterpartyId);

        if (!$counterparty || $counterparty->getCompany() !== $company || $counterparty->isArchived()) {
            throw new ValidationFailed('Counterparty is invalid.');
        }

        return $counterparty;
    }

    private function assertLineIndexAvailable(Deal $deal, int $lineIndex): void
    {
        if ($lineIndex < 1) {
            throw new ValidationFailed('Line index must be positive.');
        }

        foreach ($deal->getItems() as $existing) {
            if ($existing->getLineIndex() === $lineIndex) {
                throw new ValidationFailed('Line index is already used.');
            }
        }
    }

    private function findItem(Deal $deal, string $itemId): DealItem
    {
        foreach ($deal->getItems() as $item) {
            if ($item->getId() === $itemId) {
                return $item;
            }
        }

        throw new ValidationFailed('Deal item not found.');
    }

    private function findCharge(Deal $deal, string $chargeId): DealCharge
    {
        foreach ($deal->getCharges() as $charge) {
            if ($charge->getId() === $chargeId) {
                return $charge;
            }
        }

        throw new ValidationFailed('Deal charge not found.');
    }
}
