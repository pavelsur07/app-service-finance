<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Entity\AiAgent;
use App\Ai\Enum\AiAgentType;

interface AiAgentInterface
{
    public function supports(AiAgentType $type): bool;

    public function run(AiAgent $agent): void;
}
