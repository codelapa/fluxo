<?php

namespace App\Exceptions;

class InvalidFinancialFile extends \RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('O arquivo contém dados inválidos. Nenhuma transação foi importada.');
    }
}
