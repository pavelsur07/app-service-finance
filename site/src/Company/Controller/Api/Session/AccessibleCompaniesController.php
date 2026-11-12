<?php

declare(strict_types=1);

namespace App\Company\Controller\Api\Session;

use App\Company\Api\Request\AccessibleCompaniesRequest;
use App\Company\Api\Response\AccessibleCompaniesResponse;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Infrastructure\Repository\CompanyRepository;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[OA\Tag(name: 'Account session')]
final class AccessibleCompaniesController extends AbstractController
{
    #[Route('/api/account/v1/companies', name: 'spa_account_companies', methods: ['GET'])]
    #[OA\Get(summary: 'Доступные текущему пользователю компании', servers: [new OA\Server(url: 'https://account.vashfindir.ru')])]
    #[OA\Response(response: 200, description: 'Страница компаний', content: new OA\JsonContent(ref: new Model(type: AccessibleCompaniesResponse::class)))]
    #[OA\Response(response: 401, description: 'Нет сессии')]
    #[OA\Response(response: 422, description: 'Некорректная пагинация')]
    public function __invoke(CompanyRepository $repository, #[MapQueryString(validationFailedStatusCode: 422)] AccessibleCompaniesRequest $input = new AccessibleCompaniesRequest()): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        /** @var Pagerfanta<Company> $pager */
        $pager = new Pagerfanta(new QueryAdapter($repository->createAccessibleForUserQueryBuilder((string) $user->getId())));
        try {
            $pager->setMaxPerPage($input->perPage)->setCurrentPage($input->page);
        } catch (OutOfRangeCurrentPageException $error) {
            throw new UnprocessableEntityHttpException(previous: $error);
        }
        $items = [];
        foreach ($pager->getCurrentPageResults() as $company) {
            $items[] = ['id' => (string) $company->getId(), 'name' => (string) $company->getName()];
        }

        return $this->json((new AccessibleCompaniesResponse($items, $pager->getNbResults(), $pager->getCurrentPage(), $pager->getMaxPerPage(), $pager->getNbPages()))->toArray());
    }
}
