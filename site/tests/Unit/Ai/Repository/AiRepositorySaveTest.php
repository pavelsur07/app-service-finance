<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Repository;

use App\Ai\Entity\AiAgent;
use App\Ai\Entity\AiRun;
use App\Ai\Entity\AiSuggestion;
use App\Ai\Enum\AiAgentType;
use App\Ai\Enum\AiSuggestionSeverity;
use App\Ai\Repository\AiRunRepository;
use App\Ai\Repository\AiSuggestionRepository;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Регрессия: репозитории модуля Ai обращались к `$this->_em`.
 *
 * В Doctrine ORM 3 `EntityRepository` хранит менеджер в приватном `$em`, а
 * свойства `$_em` не существует — это шаблон MakerBundle времён ORM 2,
 * переживший миграцию. Обращение к несуществующему свойству даёт `null`, и
 * следом «Call to a member function persist() on null». Отказ детерминированный:
 * падал каждый вызов `save()`, а вызывают его `AiAgentRunner` и `CashflowAgent`.
 *
 * Тест проверяет наблюдаемое поведение — что `save()` доводит сущность до
 * менеджера, — а не то, каким именно способом менеджер получен.
 */
final class AiRepositorySaveTest extends TestCase
{
    public function testRunRepositoryPersistsWithoutFlushByDefault(): void
    {
        $run = $this->createRun();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($run);
        $entityManager->expects(self::never())->method('flush');

        $this->repositoryFor(AiRunRepository::class, AiRun::class, $entityManager)->save($run);
    }

    public function testRunRepositoryFlushesWhenAsked(): void
    {
        $run = $this->createRun();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($run);
        $entityManager->expects(self::once())->method('flush');

        $this->repositoryFor(AiRunRepository::class, AiRun::class, $entityManager)->save($run, true);
    }

    public function testSuggestionRepositoryPersistsWithoutFlushByDefault(): void
    {
        $suggestion = $this->createSuggestion();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($suggestion);
        $entityManager->expects(self::never())->method('flush');

        $repository = $this->repositoryFor(AiSuggestionRepository::class, AiSuggestion::class, $entityManager);
        $repository->save($suggestion);
    }

    public function testSuggestionRepositoryFlushesWhenAsked(): void
    {
        $suggestion = $this->createSuggestion();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($suggestion);
        $entityManager->expects(self::once())->method('flush');

        $repository = $this->repositoryFor(AiSuggestionRepository::class, AiSuggestion::class, $entityManager);
        $repository->save($suggestion, true);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $repositoryClass
     * @param class-string $entityClass
     *
     * @return T
     */
    private function repositoryFor(
        string $repositoryClass,
        string $entityClass,
        EntityManagerInterface&MockObject $entityManager,
    ): object {
        $entityManager->method('getClassMetadata')->willReturn(new ClassMetadata($entityClass));

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return new $repositoryClass($registry);
    }

    private function createRun(): AiRun
    {
        return new AiRun($this->createAgent());
    }

    private function createSuggestion(): AiSuggestion
    {
        $agent = $this->createAgent();

        return new AiSuggestion(
            $agent->getCompany(),
            $agent,
            new AiRun($agent),
            'Заголовок',
            'Описание',
            AiSuggestionSeverity::MEDIUM,
        );
    }

    private function createAgent(): AiAgent
    {
        $user = new User('11111111-1111-7111-8111-111111111111');
        $company = new Company('22222222-2222-7222-8222-222222222222', $user);

        return new AiAgent($company, AiAgentType::CASHFLOW);
    }
}
