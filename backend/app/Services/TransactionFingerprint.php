<?php

namespace App\Services;

class TransactionFingerprint
{
    public static function canonical(array $row): string
    {
        $description = \Normalizer::normalize(trim($row['description']), \Normalizer::FORM_KC);
        $description = mb_strtolower(preg_replace('/\s+/u', ' ', $description));

        return hash('sha256', json_encode(['v1', $row['date'], $description, (int) $row['amount'], $row['type']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
