<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Enum\AiAgentType;
use App\Ai\Service\Agent\AiAgentInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final class AiAgentRegistry
{
    /** @var list<AiAgentInterface> */
    private array $agents = [];

    /**
     * @param iterable<AiAgentInterface> $agents
     */
    public function __construct(
        #[TaggedIterator('app.ai.agent')]
        iterable $agents,
    ) {
        foreach ($agents as $agent) {
            $this->agents[] = $agent;
        }
    }

    public function getAgentForType(AiAgentType $type): ?AiAgentInterface
    {
        foreach ($this->agents as $agent) {
            if ($agent->supports($type)) {
                return $agent;
            }
        }

        return null;
    }
}
