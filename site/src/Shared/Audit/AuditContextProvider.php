<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack; // <--- Добавили
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AuditContextProvider
{
    public function __construct(
        private readonly Security $security,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly RequestStack $requestStack, // <--- Внедряем RequestStack
    ) {
    }

    public function getActorUserId(): ?string
    {
        // 1. Сначала проверяем, есть ли вообще Request (в консоли его может не быть)
        if (null === $this->requestStack->getMainRequest()) {
            return null; // Это System / Console
        }

        // 2. Только если есть запрос, лезем в Security
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        return $user->getId();
    }

    public function getCompanyId(): ?string
    {
        // 1. Защита от консоли: если нет запроса, не дергаем ActiveCompanyService
        // так как он наверняка полезет в сессию
        if (null === $this->requestStack->getMainRequest()) {
            return null;
        }

        try {
            $company = $this->activeCompanyService->getActiveCompany();
        } catch (NotFoundHttpException) {
            return null;
        }

        return $company->getId();
    }
}
