<?php

namespace App\Services;

use App\Exceptions\InvalidFinancialFile;

class CsvReader
{
    public function rows(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new \RuntimeException('CSV unavailable');
        }
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            if ($header !== ['date', 'description', 'amount', 'type']) {
                throw new InvalidFinancialFile(['Cabeçalho esperado: date,description,amount,type.']);
            }
            $line = 1;
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($row === [null]) {
                    continue;
                }
                if ($line > 100001) {
                    throw new InvalidFinancialFile(['Limite de 100.000 linhas excedido.']);
                }
                yield $line => $row;
            }
        } finally {
            fclose($handle);
        }
    }

    public function parse(array $row, int $line): array
    {
        if (count($row) !== 4) {
            throw new InvalidFinancialFile(["Linha {$line}: informe quatro colunas."]);
        }[$date,$description,$amount,$type] = array_map('trim', $row);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $d || $d->format('Y-m-d') !== $date) {
            throw new InvalidFinancialFile(["Linha {$line}: data inválida (AAAA-MM-DD)."]);
        }
        if (! mb_check_encoding($description, 'UTF-8') || mb_strlen($description) < 1 || mb_strlen($description) > 500) {
            throw new InvalidFinancialFile(["Linha {$line}: descrição inválida (1 a 500 caracteres UTF-8)."]);
        }
        if (! preg_match('/^[0-9]{1,11}$/D', $amount) || (int) $amount < 1 || (int) $amount > 10000000000) {
            throw new InvalidFinancialFile(["Linha {$line}: valor deve ser um inteiro positivo em centavos, até 10 bilhões."]);
        }
        $types = ['Receita' => 'income', 'Despesa' => 'expense'];
        if (! isset($types[$type])) {
            throw new InvalidFinancialFile(["Linha {$line}: tipo deve ser Receita ou Despesa."]);
        }

        return ['date' => $date, 'description' => $description, 'amount' => (int) $amount, 'type' => $types[$type], 'row_number' => $line];
    }

    public function validate(string $path): int
    {
        $count = 0;
        $errors = [];
        foreach ($this->rows($path) as $line => $row) {
            try {
                $this->parse($row, $line);
            } catch (InvalidFinancialFile $e) {
                $errors = array_merge($errors, $e->errors);
                if (count($errors) >= 20) {
                    break;
                }
            }
            $count++;
        }
        if ($errors) {
            throw new InvalidFinancialFile($errors);
        }
        if (! $count) {
            throw new InvalidFinancialFile(['O CSV não possui transações.']);
        }

        return $count;
    }
}
