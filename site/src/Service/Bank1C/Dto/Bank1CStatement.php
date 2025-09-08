<?php

namespace App\Service\Bank1C\Dto;

/**
 * @template-immutable
 */
class Bank1CStatement
{
    /** @param array<string,string> $header */
    public function __construct(
        public array $header,
        /** @param array<string,string> $account */
        public array $account,
        /** @var Bank1CDocument[] */
        public array $documents
    ) {
    }
}
