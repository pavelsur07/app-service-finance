<?php

declare(strict_types=1);

namespace App\Shared\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:make:module',
    description: 'Генерирует эталонную структуру папок и базовые классы для нового модуля (v2)',
)]
class MakeModuleCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Filesystem $filesystem = new Filesystem()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'name',
            InputArgument::REQUIRED,
            'Название модуля (например: Sales, Chat, Billing)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $moduleName = ucfirst(trim($input->getArgument('name')));
        $modulePath = sprintf('%s/src/%s', $this->projectDir, $moduleName);

        if ($this->filesystem->exists($modulePath)) {
            $io->error(sprintf('Модуль "%s" уже существует по пути %s!', $moduleName, $modulePath));
            return Command::FAILURE;
        }

        $io->text(sprintf('Создание архитектуры для модуля <info>%s</info>...', $moduleName));

        // 1. Создание директорий
        $directories = [
            'Application',
            'Controller',
            'Domain',
            'Infrastructure/Repository',
            'Infrastructure/Query',
            'Infrastructure/Client',
            'Entity',
            'Api/Request',
            'Api/Response',
            'DTO',
            'Enum',
            'Facade',
            'Form',
        ];

        foreach ($directories as $dir) {
            $path = sprintf('%s/%s', $modulePath, $dir);
            $this->filesystem->mkdir($path);
            $this->filesystem->touch($path . '/.gitkeep');
        }

        // 2. Генерация базовых классов
        $this->generateClasses($moduleName, $modulePath);

        // 3. Создание папки для Twig
        $templatesPath = sprintf('%s/templates/%s', $this->projectDir, strtolower($moduleName));
        if (!$this->filesystem->exists($templatesPath)) {
            $this->filesystem->mkdir($templatesPath);
            $this->filesystem->touch($templatesPath . '/.gitkeep');
        }

        $io->success([
            sprintf('Модуль "%s" успешно сгенерирован!', $moduleName),
            'Сгенерированы классы: Controller, Action, FacadeInterface, Request DTO, Enum',
            sprintf('Путь: src/%s', $moduleName)
        ]);

        return Command::SUCCESS;
    }

    private function generateClasses(string $moduleName, string $modulePath): void
    {
        $moduleNameLower = strtolower($moduleName);

        // --- 1. Facade Interface ---
        $facadeContent = <<<PHP
<?php

declare(strict_types=1);

namespace App\\{$moduleName}\Facade;

/**
 * Публичный контракт модуля {$moduleName} для использования в других модулях.
 */
interface {$moduleName}FacadeInterface
{
    // public function getSomethingById(int \$id): SomeDTO;
}

PHP;
        $this->filesystem->dumpFile($modulePath . "/Facade/{$moduleName}FacadeInterface.php", $facadeContent);

        // --- 2. Enum ---
        $enumContent = <<<PHP
<?php

declare(strict_types=1);

namespace App\\{$moduleName}\Enum;

enum {$moduleName}Status: string
{
    case NEW = 'new';
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}

PHP;
        $this->filesystem->dumpFile($modulePath . "/Enum/{$moduleName}Status.php", $enumContent);

        // --- 3. DTO (Api Request) ---
        $dtoContent = <<<PHP
<?php

declare(strict_types=1);

namespace App\\{$moduleName}\Api\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class Create{$moduleName}Request
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly string \$title,

        // public readonly ?string \$description = null,
    ) {}
}

PHP;
        $this->filesystem->dumpFile($modulePath . "/Api/Request/Create{$moduleName}Request.php", $dtoContent);

        // --- 4. Application Action ---
        $actionContent = <<<PHP
<?php

declare(strict_types=1);

namespace App\\{$moduleName}\Application;

use App\\{$moduleName}\Api\Request\Create{$moduleName}Request;

/**
 * Пример Use-Case (Action) класса.
 * Инкапсулирует одну бизнес-транзакцию.
 */
final class Create{$moduleName}Action
{
    public function __construct(
        // inject repositories, policies, etc.
    ) {}

    public function __invoke(Create{$moduleName}Request \$request): void
    {
        // TODO: Implement domain logic orchestration using \$request
    }
}

PHP;
        $this->filesystem->dumpFile($modulePath . "/Application/Create{$moduleName}Action.php", $actionContent);

        // --- 5. Controller ---
        $controllerContent = <<<PHP
<?php

declare(strict_types=1);

namespace App\\{$moduleName}\Controller;

use App\\{$moduleName}\Api\Request\Create{$moduleName}Request;
use App\\{$moduleName}\Application\Create{$moduleName}Action;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;

final class {$moduleName}Controller extends AbstractController
{
    public function __construct(
        private readonly Create{$moduleName}Action \$createAction
    ) {}

    #[Route('/api/{$moduleNameLower}', name: 'api_{$moduleNameLower}_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] Create{$moduleName}Request \$request
    ): JsonResponse {
        // Вызов Application слоя
        (\$this->createAction)(\$request);

        return \$this->json(['status' => 'success'], 201);
    }
}

PHP;
        $this->filesystem->dumpFile($modulePath . "/Controller/{$moduleName}Controller.php", $controllerContent);
    }
}
