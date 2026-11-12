<?php

namespace App\Services;

use App\Exceptions\InvalidFinancialFile;

class OfxReader
{
    private function field(string $block, string $tag): string
    {
        if (! preg_match('~<'.preg_quote($tag, '~').'(?:\s[^>]*)?>\s*([^<]*)~i', $block, $m)) {
            return '';
        } $v = trim($m[1]);
        if (! mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
        }

        return html_entity_decode($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public function rows(string $path): \Generator
    {
        $file = fopen($path, 'rb');
        if (! $file) {
            throw new \RuntimeException('OFX unavailable');
        }
        $buffer = '';
        $account = null;
        $currency = '';
        $institution = '';
        $count = 0;
        $found = false;
        try {
            while (! feof($file)) {
                $buffer .= fread($file, 65536);
                if (preg_match('/<!\s*(DOCTYPE|ENTITY)|<CORRECTFITID\b/i', $buffer)) {
                    throw new InvalidFinancialFile(['OFX com entidades externas ou correções automáticas não é aceito. Exporte um extrato bancário comum.']);
                }
                while (preg_match('~<STMTTRN(?:\s[^>]*)?>~i', $buffer, $start, PREG_OFFSET_CAPTURE)) {
                    $offset = $start[0][1];
                    $prefix = substr($buffer, 0, $offset);
                    $institution = $this->field($prefix, 'FID') ?: $this->field($prefix, 'ORG') ?: $institution;
                    if (preg_match('~<(?:BANKACCTFROM|CCACCTFROM)>~i', $prefix)) {
                        $id = $this->field($prefix, 'ACCTID');
                        $bank = $this->field($prefix, 'BANKID') ?: $institution;
                        $kind = str_contains(strtoupper($prefix), '<CCACCTFROM>') ? 'credit' : $this->field($prefix, 'ACCTTYPE');
                        if (! $id || ! $bank) {
                            throw new InvalidFinancialFile(['OFX sem identificação do banco ou da conta.']);
                        }
                        $account = hash('sha256', json_encode([$bank, $this->field($prefix, 'BRANCHID'), $id, strtoupper($kind)]));
                        $currency = strtoupper($this->field($prefix, 'CURDEF'));
                    }
                    $buffer = substr($buffer, $offset);
                    if (! preg_match('~</STMTTRN\s*>~i', $buffer, $end, PREG_OFFSET_CAPTURE)) {
                        if (strlen($buffer) > 65536) {
                            throw new InvalidFinancialFile(['Transação OFX excessivamente longa ou incompleta.']);
                        }break;
                    }
                    $length = $end[0][1] + strlen($end[0][0]);
                    $block = substr($buffer, 0, $length);
                    $buffer = substr($buffer, $length);
                    $count++;
                    $found = true;
                    if ($count > 100000) {
                        throw new InvalidFinancialFile(['Limite de 100.000 transações excedido.']);
                    }
                    if (! $account || $currency !== 'BRL') {
                        throw new InvalidFinancialFile(['O OFX deve identificar a conta e informar moeda BRL.']);
                    }
                    $id = $this->field($block, 'FITID');
                    if ($id === '' || mb_strlen($id) > 255) {
                        throw new InvalidFinancialFile(["Transação {$count}: FITID ausente ou inválido."]);
                    }
                    $rawDate = $this->field($block, 'DTPOSTED');
                    if (! preg_match('/^\d{8}(?:\d{6}(?:\.\d+)?(?:\[[^\]]+\])?)?$/D', $rawDate)) {
                        throw new InvalidFinancialFile(["Transação {$count}: data OFX inválida."]);
                    }
                    $date = substr($rawDate, 0, 4).'-'.substr($rawDate, 4, 2).'-'.substr($rawDate, 6, 2);
                    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                    if (! $d || $d->format('Y-m-d') !== $date) {
                        throw new InvalidFinancialFile(["Transação {$count}: data inválida."]);
                    }
                    $amount = $this->field($block, 'TRNAMT');
                    if (! preg_match('/^([+-]?)(\d{1,9})(?:[.,](\d{1,2}))?$/D', $amount, $m)) {
                        throw new InvalidFinancialFile(["Transação {$count}: valor OFX inválido."]);
                    }
                    $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');
                    if ($cents < 1 || $cents > 10000000000) {
                        throw new InvalidFinancialFile(["Transação {$count}: valor fora do limite."]);
                    }
                    $type = $m[1] === '-' ? 'expense' : 'income';
                    $name = $this->field($block, 'NAME');
                    $memo = $this->field($block, 'MEMO');
                    $description = $memo ?: $name;
                    if ($description === '' || mb_strlen($description) > 500) {
                        throw new InvalidFinancialFile(["Transação {$count}: descrição ausente ou longa demais."]);
                    }
                    yield ['date' => $date, 'description' => $description, 'amount' => $cents, 'type' => $type, 'row_number' => $count, 'source_account' => $account, 'external_key' => hash('sha256', 'ofx:'.$account.':'.$id)];
                }
                if (strlen($buffer) > 131072) {
                    throw new InvalidFinancialFile(['OFX inválido ou sem transações reconhecíveis.']);
                }
            }
            if (! $found || preg_match('~<STMTTRN(?:\s[^>]*)?>~i', $buffer)) {
                throw new InvalidFinancialFile(['OFX vazio ou com transação incompleta.']);
            }
        } finally {
            fclose($file);
        }
    }
}
