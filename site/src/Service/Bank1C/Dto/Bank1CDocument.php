<?php

namespace App\Service\Bank1C\Dto;

class Bank1CDocument
{
    public string $type;
    public ?string $number = null;
    public ?string $date = null;
    public ?string $amount = null;
    public ?string $payerAccount = null;
    public ?string $payeeAccount = null;
    public ?string $dateDebited = null;
    public ?string $dateCredited = null;
    public ?string $payerName = null;
    public ?string $payerInn = null;
    public ?string $payeeName = null;
    public ?string $payeeInn = null;
    public ?string $purpose = null;
    /** @var array<string,string> */
    public array $raw = [];

    public function __construct(string $type)
    {
        $this->type = $type;
    }
}
