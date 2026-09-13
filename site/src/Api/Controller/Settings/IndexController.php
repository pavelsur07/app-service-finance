<?php

declare(strict_types=1);

namespace App\Api\Controller\Settings;

use App\Api\Application\Service\ApiOwnerGuard;
use App\Api\Repository\ApiKeyRepository;
use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class IndexController extends AbstractController
{
    public function __construct(private readonly ActiveCompanyService $activeCompany, private readonly ApiOwnerGuard $owner, private readonly ApiKeyRepository $keys, private readonly ClockInterface $clock)
    {
    }

    #[Route('/settings/api', name: 'settings_api_index', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $company = $this->activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $userId = (string) $user->getId();
        $this->owner->assertOwner($companyId, $userId);
        $parameters = $request->query->all();
        $page = filter_var($parameters['page'] ?? '1', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $limit = filter_var($parameters['limit'] ?? '50', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        if (false === $page || false === $limit) {
            throw new UnprocessableEntityHttpException('Номер страницы и размер списка должны быть целыми числами: страница от 1, размер от 1 до 200.');
        }
        try {
            $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(new QueryAdapter($this->keys->createListQueryBuilder($companyId)), $page, $limit);
        } catch (OutOfRangeCurrentPageException $exception) {
            throw new UnprocessableEntityHttpException('Страница не найдена.', $exception);
        }

        return $this->render('api/settings/index.html.twig', ['activeCompany' => $company, 'pager' => $pager, 'now' => $this->clock->now()]);
    }
}
