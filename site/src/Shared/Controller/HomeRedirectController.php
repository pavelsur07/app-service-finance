<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Company\Security\ModuleAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Лендинг «/»: редирект на первый доступный пользователю модуль.
 * Модульный гейт не применяется (exempt в ModuleAccessMap) — права проверяются здесь явно.
 */
final class HomeRedirectController extends AbstractController
{
    /**
     * Порядок выбора лендинга: атрибут доступа → роут.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const LANDING_BY_MODULE = [
        [ModuleAccess::FINANCE_READ, 'app_finance_index'],
        [ModuleAccess::MARKETPLACE_READ, 'marketplace_index'],
        [ModuleAccess::DEALS_READ, 'deal_index'],
        [ModuleAccess::CATALOG_READ, 'catalog_products_index'],
        [ModuleAccess::ADMIN_READ, 'company_index'],
    ];

    #[Route('/', name: 'app_home_index', methods: ['GET'])]
    public function __invoke(Request $request, AuthorizationCheckerInterface $authorizationChecker): Response
    {
        // «/» раньше отдавал финансовый дашборд, поэтому старые ссылки и закладки вида
        // /?currency=USD должны продолжать работать. Валюту переносим как есть —
        // валидация остаётся в HomeController, он же вернёт RUB при мусорном значении.
        $query = [];
        $currency = $request->query->get('currency');
        if (is_string($currency) && '' !== $currency) {
            $query['currency'] = $currency;
        }

        foreach (self::LANDING_BY_MODULE as [$attribute, $route]) {
            if ($authorizationChecker->isGranted($attribute)) {
                return $this->redirectToRoute($route, $query);
            }
        }

        // Нет доступа ни к одному модулю — страница компаний (работает без активной компании).
        return $this->redirectToRoute('company_index');
    }
}
