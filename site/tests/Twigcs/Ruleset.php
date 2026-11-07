<?php

declare(strict_types=1);

namespace App\Tests\Twigcs;

use FriendsOfTwig\Twigcs\RegEngine\RulesetBuilder;
use FriendsOfTwig\Twigcs\RegEngine\RulesetConfigurator;
use FriendsOfTwig\Twigcs\Rule;
use FriendsOfTwig\Twigcs\Ruleset\RulesetInterface;
use FriendsOfTwig\Twigcs\Ruleset\TemplateResolverAwareInterface;
use FriendsOfTwig\Twigcs\TemplateResolver\NullResolver;
use FriendsOfTwig\Twigcs\TemplateResolver\TemplateResolverInterface;
use FriendsOfTwig\Twigcs\Validator\Violation;

/**
 * Ruleset проекта: Official без LowerCaseVariable.
 *
 * Переменные в шаблонах — camelCase (`currentPage`, `totalItems` в DS-партиалах,
 * свойства DTO из PHP); правило snake_case дало бы 650 ложных нарушений.
 * UnusedVariable/UnusedMacro остаются предупреждениями: без резолвера include/extends
 * они не видят переменные layout'а (`active_tab`, `app_nav_active`) — гейт на них не падает.
 * Конвенции — docs/workflow/twig-conventions.md.
 */
final class Ruleset implements RulesetInterface, TemplateResolverAwareInterface
{
    private TemplateResolverInterface $resolver;

    public function __construct(private readonly int $twigMajorVersion)
    {
        $this->resolver = new NullResolver();
    }

    /** @return list<Rule\RuleInterface> */
    public function getRules(): array
    {
        $configurator = new RulesetConfigurator();
        $configurator->setTwigMajorVersion($this->twigMajorVersion);
        $builder = new RulesetBuilder($configurator);

        return [
            new Rule\RegEngineRule(Violation::SEVERITY_ERROR, $builder->build()),
            new Rule\TrailingSpace(Violation::SEVERITY_ERROR),
            new Rule\UnusedMacro(Violation::SEVERITY_WARNING, $this->resolver),
            new Rule\UnusedVariable(Violation::SEVERITY_WARNING, $this->resolver),
        ];
    }

    public function setTemplateResolver(TemplateResolverInterface $resolver): void
    {
        $this->resolver = $resolver;
    }
}
