<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class RefundCalculator
{
    public static function forQuantity(
        int $refundableCents,
        int $soldMilli,
        int $alreadyReturnedMilli,
        int $requestedMilli,
        int $alreadyRefundedCents
    ): int {
        if ($refundableCents < 0 || $soldMilli <= 0 || $alreadyReturnedMilli < 0
            || $alreadyReturnedMilli >= $soldMilli || $requestedMilli <= 0
            || $requestedMilli > $soldMilli - $alreadyReturnedMilli
            || $alreadyRefundedCents < 0 || $alreadyRefundedCents > $refundableCents) {
            throw new InvalidArgumentException('Returned quantity or amount is invalid.');
        }

        if ($requestedMilli === $soldMilli - $alreadyReturnedMilli) {
            return $refundableCents - $alreadyRefundedCents;
        }

        return (int) round($refundableCents * $requestedMilli / $soldMilli);
    }

    /**
     * @param list<array{id:int,line_cents:int,item_discount_cents:int}> $items
     * @return array<int,int> Refundable cents keyed by transaction item ID
     */
    public static function allocate(
        array $items,
        int $totalDiscountCents,
        int $serviceChargeCents,
        int $taxCents,
        bool $taxInclusive = false
    ): array {
        $breakdown = self::allocateBreakdown($items, $totalDiscountCents, $serviceChargeCents, $taxCents, $taxInclusive);
        $refunds = [];
        foreach ($breakdown as $id => $components) {
            $refunds[$id] = $components['refundable_cents'];
        }
        return $refunds;
    }

    /**
     * @param list<array{id:int,line_cents:int,item_discount_cents:int}> $items
     * @return array<int,array{merchandise_cents:int,service_cents:int,tax_cents:int,refundable_cents:int}>
     */
    public static function allocateBreakdown(
        array $items,
        int $totalDiscountCents,
        int $serviceChargeCents,
        int $taxCents,
        bool $taxInclusive = false
    ): array {
        if ($totalDiscountCents < 0 || $serviceChargeCents < 0 || $taxCents < 0) {
            throw new InvalidArgumentException('Refund amounts cannot be negative.');
        }

        $ids = [];
        $lineCents = [];
        $itemDiscountCents = 0;
        foreach ($items as $item) {
            if (!isset($item['id'], $item['line_cents'], $item['item_discount_cents'])
                || $item['id'] <= 0
                || $item['line_cents'] < 0
                || $item['item_discount_cents'] < 0
                || isset($ids[$item['id']])) {
                throw new InvalidArgumentException('Transaction items are invalid.');
            }
            $ids[$item['id']] = true;
            $lineCents[$item['id']] = $item['line_cents'];
            $itemDiscountCents += $item['item_discount_cents'];
        }

        $headerDiscountCents = $totalDiscountCents - $itemDiscountCents;
        if ($items === [] || $headerDiscountCents < 0 || $headerDiscountCents > array_sum($lineCents)) {
            throw new InvalidArgumentException('Transaction discount exceeds its item totals.');
        }

        $discounts = self::split($headerDiscountCents, $lineCents);
        $taxBases = [];
        foreach ($lineCents as $id => $amount) {
            $taxBases[$id] = $amount - $discounts[$id];
        }

        $serviceCharges = self::split($serviceChargeCents, $taxBases);
        $taxableAmounts = [];
        foreach ($taxBases as $id => $amount) {
            $taxableAmounts[$id] = $amount + $serviceCharges[$id];
        }
        $taxes = self::split($taxCents, $taxableAmounts);

        $breakdown = [];
        foreach ($lineCents as $id => $amount) {
            $merchandiseTax = $taxInclusive && $taxableAmounts[$id] > 0
                ? (int) round($taxes[$id] * $taxBases[$id] / $taxableAmounts[$id])
                : 0;
            $breakdown[$id] = [
                'merchandise_cents' => $taxBases[$id] - $merchandiseTax,
                'service_cents' => $serviceCharges[$id],
                'tax_cents' => $taxes[$id],
                'refundable_cents' => $taxBases[$id] + $serviceCharges[$id] + ($taxInclusive ? 0 : $taxes[$id]),
            ];
        }

        return $breakdown;
    }

    /**
     * Allocate integer cents proportionally, assigning rounding remainders
     * deterministically so the shares always sum to the original amount.
     *
     * @param array<int,int> $weights keyed by transaction item ID
     * @return array<int,int>
     */
    private static function split(int $amount, array $weights): array
    {
        $weightTotal = array_sum($weights);
        if ($amount === 0) {
            return array_fill_keys(array_keys($weights), 0);
        }
        if ($weightTotal <= 0) {
            throw new InvalidArgumentException('Cannot allocate an amount without a positive item total.');
        }

        $shares = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $id => $weight) {
            $exact = $amount * ($weight / $weightTotal);
            $share = (int) floor($exact);
            $shares[$id] = $share;
            $remainders[] = ['id' => $id, 'fraction' => $exact - $share];
            $allocated += $share;
        }

        usort($remainders, static function (array $left, array $right): int {
            $fractionOrder = $right['fraction'] <=> $left['fraction'];
            return $fractionOrder !== 0 ? $fractionOrder : ($left['id'] <=> $right['id']);
        });

        $remaining = $amount - $allocated;
        for ($i = 0; $i < $remaining; $i++) {
            $shares[$remainders[$i]['id']]++;
        }

        return $shares;
    }
}
