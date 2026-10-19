<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class TaxCalculator
{
    /**
     * @return array{tax_cents:int,grand_cents:int}
     */
    public static function calculate(int $taxBaseCents, float $taxPct, string $mode): array
    {
        if ($taxBaseCents < 0 || !is_finite($taxPct) || $taxPct < 0 || $taxPct > 100
            || !in_array($mode, ['none', 'exclusive', 'inclusive'], true)) {
            throw new InvalidArgumentException('Tax calculation input is invalid.');
        }

        if ($mode === 'none' || $taxPct === 0.0) {
            return ['tax_cents' => 0, 'grand_cents' => $taxBaseCents];
        }

        $taxCents = $mode === 'inclusive'
            ? (int) round($taxBaseCents * $taxPct / (100 + $taxPct))
            : (int) round($taxBaseCents * $taxPct / 100);

        return [
            'tax_cents' => $taxCents,
            'grand_cents' => $mode === 'inclusive' ? $taxBaseCents : $taxBaseCents + $taxCents,
        ];
    }
}
