<?php

namespace App\Services;

use App\Exceptions\InvalidFinancialFile;

class ImportReader
{
    public function __construct(private CsvReader $csv, private OfxReader $ofx) {}

    public function rows(string $path, string $format): \Generator
    {
        if ($format === 'ofx') {
            yield from $this->ofx->rows($path);

            return;
        }
        if ($format !== 'csv') {
            throw new InvalidFinancialFile(['Formato de arquivo não reconhecido.']);
        }
        foreach ($this->csv->rows($path) as $line => $row) {
            yield $this->csv->parse($row, $line) + ['source_account' => null];
        }
    }

    public function validate(string $path, string $format): int
    {
        if ($format === 'csv') {
            return $this->csv->validate($path);
        }
        $count = 0;
        foreach ($this->rows($path, $format) as $row) {
            $count++;
        }
        if (! $count) {
            throw new InvalidFinancialFile(['O arquivo não possui transações.']);
        }

        return $count;
    }
}
