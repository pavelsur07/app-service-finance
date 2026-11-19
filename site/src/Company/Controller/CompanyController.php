<?php

declare(strict_types=1);

namespace App\Company\Controller;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Application\Service\CompanyTaxProfileWriter;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Company\Event\CompanyCreatedEvent;
use App\Company\Form\CompanyTaxProfileType;
use App\Company\Form\CompanyType;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyTaxProfileRepository;
use App\Company\Repository\ConsolidationMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/company')]
class CompanyController extends AbstractController
{
    private const PER_PAGE = 20;

    #[Route('/', name: 'company_index', methods: ['GET'])]
    public function index(
        Request $request,
        CompanyRepository $companyRepository,
        CompanyTaxProfileRepository $taxProfileRepository,
        ClockInterface $clock,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $page = filter_var($request->query->all()['page'] ?? '1', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        /** @var Pagerfanta<Company> $pager */
        $pager = new Pagerfanta(new QueryAdapter($companyRepository->createOwnedActiveQueryBuilder($user), false));
        $pager->setMaxPerPage(self::PER_PAGE);
        $pager->setCurrentPage(min($page, $pager->getNbPages()));
        $companies = iterator_to_array($pager->getCurrentPageResults(), false);

        return $this->render('company/index.html.twig', [
            'companies' => $companies,
            'pager' => $pager,
            'taxProfiles' => $taxProfileRepository->findEffectiveForCompanies(
                array_map(static fn (Company $company): string => (string) $company->getId(), $companies),
                $clock->now(),
            ),
            'deletionRequested' => $companyRepository->findDeletionRequestedByUser($user),
        ]);
    }

    #[Route('/active', name: 'company_set_active', methods: ['POST'])]
    public function setActive(Request $request, CompanyRepository $companyRepository): Response
    {
        $id = $request->request->get('company_id');
        $company = $companyRepository->find($id);

        if (!$company instanceof Company || $company->getUser() !== $this->getUser() || $company->isDeletionRequested()) {
            throw $this->createNotFoundException();
        }

        $request->getSession()->set('active_company_id', $company->getId());

        if ('company_edit' === ($request->request->all()['returnTo'] ?? null)) {
            return $this->redirectToRoute('company_edit', ['id' => $company->getId()]);
        }

        $referer = $request->headers->get('referer');

        if (null !== $referer) {
            $refererHost = parse_url($referer, \PHP_URL_HOST);
            if ($refererHost === $request->getHost()) {
                return $this->redirect($referer);
            }
        }

        return $this->redirectToRoute('app_home_index');
    }

    #[Route('/new', name: 'company_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        EventDispatcherInterface $eventDispatcher,
        CompanyOwnerMembershipCreator $companyOwnerMembershipCreator,
        CompanyTaxProfileWriter $taxProfileWriter,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $company = new Company(id: Uuid::uuid7()->toString(), user: $user);
        $company->setUser($user); // Автоматически проставляем владельца

        $form = $this->createForm(CompanyType::class, $company, ['with_tax_profile' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{taxRegime: ?TaxRegime, vatMode: ?VatMode, taxRatePercent: ?string} $taxProfile */
            $taxProfile = $form->get('taxProfile')->getData();

            // Подписчики события (Balance) ищут данные через SQL, поэтому им нужен flush до события.
            // Общая транзакция держит создание компании со стартовыми данными атомарным.
            $em->wrapInTransaction(function () use ($company, $user, $em, $eventDispatcher, $companyOwnerMembershipCreator, $taxProfileWriter, $taxProfile): void {
                $companyOwnerMembershipCreator->persistCompanyWithOwnerMembership($company, $user);
                // Начальный налоговый профиль; форма пропускает только оба поля или ни одного.
                if (null !== $taxProfile['taxRegime'] && null !== $taxProfile['vatMode']) {
                    $taxProfileWriter->upsert((string) $company->getId(), $taxProfile['taxRegime'], $taxProfile['vatMode'], null, $taxProfile['taxRatePercent']);
                }
                $em->flush();
                $eventDispatcher->dispatch(new CompanyCreatedEvent((string) $company->getId(), $company->getBusinessProfile()));
            });

            return $this->redirectToRoute('company_index');
        }

        return $this->render('company/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'company_show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(string $id, CompanyRepository $companyRepository): Response
    {
        $company = $companyRepository->find($id);

        if (!$company || $company->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        return $this->render('company/show.html.twig', [
            'company' => $company,
        ]);
    }

    #[Route('/{id}/edit', name: 'company_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function edit(
        string $id,
        Request $request,
        CompanyRepository $companyRepository,
        CompanyTaxProfileRepository $taxProfileRepository,
        ConsolidationMembershipRepository $consolidationRepository,
        EntityManagerInterface $em,
        LoggerInterface $logger,
    ): Response {
        $company = $companyRepository->find($id);

        if (!$company || $company->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        \assert($company instanceof Company);
        $profileBefore = $company->getBusinessProfile();
        $form = $this->createForm(CompanyType::class, $company, ['business_profile_help' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            if ($company->getBusinessProfile() !== $profileBefore) {
                $logger->info('Company business profile changed', ['companyId' => $id, 'from' => $profileBefore->value, 'to' => $company->getBusinessProfile()->value]);
            }
            $this->addFlash('success', 'Настройки компании сохранены.');

            return $this->redirectToRoute('company_edit', ['id' => $id, '_fragment' => 'company']);
        }

        $taxProfileForm = $this->createForm(CompanyTaxProfileType::class, null, [
            'action' => $this->generateUrl('company_tax_profile_save', ['id' => $id]),
        ]);

        $cabinet = $companyRepository->findByUserId((string) $company->getUser()?->getId());
        $cabinetNames = [];
        foreach ($cabinet as $cabinetCompany) {
            $cabinetNames[(string) $cabinetCompany->getId()] = (string) $cabinetCompany->getName();
        }

        return $this->render('company/edit.html.twig', [
            'form' => $form->createView(),
            'company' => $company,
            'taxProfiles' => $taxProfileRepository->findByCompany($id),
            'taxProfileForm' => $taxProfileForm->createView(),
            'cabinetNames' => $cabinetNames,
            'consolidation' => $consolidationRepository->findByCompanies(array_keys($cabinetNames)),
        ]);
    }
}
