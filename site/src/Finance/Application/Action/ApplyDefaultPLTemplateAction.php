<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Company\Enum\BusinessProfile;
use App\Company\Facade\CompanyFacade;
use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Application\DTO\ImportPLCategoryTreeResult;
use App\Finance\Entity\PLTemplateApplication;
use App\Finance\Enum\PLTemplateApplicationSource;
use App\Finance\Exception\PLTemplateProfileMismatchException;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Finance\Repository\PLCategoryRepository;
use App\Finance\Repository\PLTemplateApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Применяет стартовый шаблон ОПиУ профиля компании (только недостающие статьи) и записывает
 * применение, создавшее статьи, — происхождение дерева. Единый путь онбординга, экрана «Базовый
 * шаблон ОПиУ» и `app:finance:pl-tree:apply-default`.
 *
 * Шаблон другого профиля к непустому дереву с записанным происхождением не применяется
 * (PLTemplateProfileMismatchException): итоги, совпавшие по коду, считаются по формулам исходного
 * шаблона, и статьи нового выпали бы из них. Пустое дерево смешивать не с чем; дерево без записи
 * (компании до учёта) — без этой проверки, от второго дерева его защищает сам импорт.
 *
 * Применение: сначала проверка и предпросмотр без транзакции (отказ не закрывает EntityManager),
 * затем под блокировкой по компании — повторная проверка происхождения и запись: два одновременных
 * применения разных профилей (смена профиля посреди применения) не смешают шаблоны.
 */
final readonly class ApplyDefaultPLTemplateAction
{
    public function __construct(
        private ImportPLCategoryTreeAction $import,
        private DefaultPLCategoryTreeProvider $templates,
        private PLTemplateApplicationRepository $applications,
        private PLCategoryRepository $categories,
        private CompanyFacade $companies,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param BusinessProfile|null $profile профиль, если вызывающий его уже знает (событие создания); иначе — профиль компании
     */
    public function __invoke(string $companyId, bool $dryRun, PLTemplateApplicationSource $source, ?BusinessProfile $profile = null): ImportPLCategoryTreeResult
    {
        $profile ??= $this->companies->getBusinessProfile($companyId);
        $this->assertSameOrigin($companyId, $profile);

        $command = new ImportPLCategoryTreeCommand($this->templates->nodes($profile), $companyId, true, onlyCreate: true);
        $preview = ($this->import)($command);
        if ($dryRun) {
            return $preview;
        }

        $version = $this->templates->templateVersion($profile);
        // Статьи и запись о них — одной транзакцией: без записи дерево осталось бы без происхождения.
        $result = $this->em->wrapInTransaction(function () use ($command, $companyId, $profile, $version, $source): ImportPLCategoryTreeResult {
            $this->em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:key))', ['key' => 'pl-template:'.$companyId]);
            $this->assertSameOrigin($companyId, $profile);
            $result = ($this->import)(new ImportPLCategoryTreeCommand($command->sourceNodes, $companyId, false, onlyCreate: true));
            if ([] !== $result->created) {
                $this->em->persist(new PLTemplateApplication($companyId, $profile, $version, \count($result->created), $source, new \DateTimeImmutable()));
                $this->em->flush();
            }

            return $result;
        });

        $this->logger->info('Default P&L tree applied to company', [
            'companyId' => $companyId,
            'profile' => $profile->value,
            'templateVersion' => $version,
            'source' => $source->value,
            'created' => \count($result->created),
            'kept' => $result->unchangedCount,
            'similar' => \count($result->similar),
        ]);

        return $result;
    }

    private function assertSameOrigin(string $companyId, BusinessProfile $profile): void
    {
        $origin = $this->applications->findLatestByCompanyId($companyId);
        if (null !== $origin && $origin->getProfile() !== $profile && $this->categories->existsByCompanyId($companyId)) {
            throw PLTemplateProfileMismatchException::between($origin->getProfile(), $profile);
        }
    }
}
