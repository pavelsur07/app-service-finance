<?php

declare(strict_types=1);

namespace App\Telegram\Application;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Company\Facade\CompanyFacade;
use App\Telegram\Entity\ClientBinding;
use Doctrine\ORM\EntityManagerInterface;

/** Выбор кассы и право записи/чтения Telegram-привязки по правам её пользователя в компании. */
final readonly class TelegramBindingAction
{
    public function __construct(private EntityManagerInterface $em, private CompanyFacade $companies)
    {
    }

    public function selectMoneyAccount(ClientBinding $selected, MoneyAccount $account): void
    {
        if ($account->getCompany()->getId() !== $selected->getCompany()->getId() || !$this->mayUseBinding($selected)) {
            throw new \DomainException('Касса недоступна.');
        }
        $this->em->wrapInTransaction(function () use ($selected, $account): void {
            $key = 'telegram-selection:'.$selected->getBot()->getId().':'.$selected->getTelegramUser()->getId();
            $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => $key]);
            foreach ($this->em->getRepository(ClientBinding::class)->findBy(['telegramUser' => $selected->getTelegramUser(), 'bot' => $selected->getBot()]) as $binding) {
                $binding->select(false);
            }
            $this->em->flush();
            $selected->setMoneyAccount($account);
            $selected->select(true);
        });
    }

    public function mayUseBinding(ClientBinding $binding, bool $write = true): bool
    {
        return null === $binding->getActorUserId() || $this->companies->financialAccess((string) $binding->getCompany()->getId(), $binding->getActorUserId())[$write ? 'write' : 'read'];
    }
}
