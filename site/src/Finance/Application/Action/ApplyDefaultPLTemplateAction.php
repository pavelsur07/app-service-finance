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
use App\Finance\Repository\PLTemplateApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Применяет стартовый шаблон ОПиУ профиля компании (только недостающие статьи) и записывает
 * применение, создавшее статьи, — происхождение дерева. Единый путь онбординга, экрана «Базовый
 * шаблон ОПиУ» и `app:finance:pl-tree:apply-default`.
 *
 * Шаблон другого профиля к дереву с записанным происхождением не применяется
 * (PLTemplateProfileMismatchException): итоги, совпавшие по коду, считаются по формулам исходного
 * шаблона, и статьи нового выпали бы из них. Дерево без записи (компании до учёта) — без этой
 * проверки; от второго дерева его защищает сам импорт.
 */
final readonly class ApplyDefaultPLTemplateAction
{
    public function __construct(
        private ImportPLCategoryTreeAction $import,
        private DefaultPLCategoryTreeProvider $templates,
        private PLTemplateApplicationRepository $applications,
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
        $origin = $this->applications->findFirstByCompanyId($companyId);
        if (null !== $origin && $origin->getProfile() !== $profile) {
            throw PLTemplateProfileMismatchException::between($origin->getProfile(), $profile);
        }

        $command = new ImportPLCategoryTreeCommand($this->templates->nodes($profile), $companyId, $dryRun, onlyCreate: true);
        if ($dryRun) {
            return ($this->import)($command);
        }

        $version = $this->templates->templateVersion($profile);
        // Статьи и запись о них — одной транзакцией: без записи дерево осталось бы без происхождения.
        $result = $this->em->wrapInTransaction(function () use ($command, $companyId, $profile, $version, $source): ImportPLCategoryTreeResult {
            $result = ($this->import)($command);
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
}
