<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class ReportProfitCalculator
{
    /**
     * Sales are recognized on transaction date; refunds are recognized on return date.
     *
     * @param array<int,array{created_at:string,discount_cents:int,service_cents:int,tax_cents:int,tax_inclusive:bool}> $transactions
     * @param list<array{id:int,transaction_id:int,item_name:string,item_type:string,qty_milli:int,line_cents:int,item_discount_cents:int,cost_cents_per_unit:int}> $items
     * @param list<array{id:int,item_id:int,qty_milli:int,returned_at:string,restocked:bool}> $returns Chronological return history through the report end date
     * @return array{net_revenue_cents:int,cost_cents:int,gross_profit_cents:int,top_products:list<array{item_name:string,item_type:string,quantity_sold:float,quantity_returned:float,sales_cents:int,cost_cents:int,gross_profit_cents:int}>}
     */
    public static function calculate(array $transactions, array $items, array $returns, string $from, string $to): array
    {
        if ($from > $to) {
            throw new InvalidArgumentException('Report date range is invalid.');
        }

        $itemsByTransaction = [];
        $itemsById = [];
        foreach ($items as $item) {
            if (!isset($item['id'], $item['transaction_id'], $item['qty_milli'], $item['line_cents'],
                $item['item_discount_cents'], $item['cost_cents_per_unit'])
                || $item['id'] <= 0 || $item['transaction_id'] <= 0 || $item['qty_milli'] <= 0
                || $item['line_cents'] < 0 || $item['item_discount_cents'] < 0
                || $item['cost_cents_per_unit'] < 0 || !isset($transactions[$item['transaction_id']])) {
                throw new InvalidArgumentException('Report transaction item data is invalid.');
            }
            $itemsByTransaction[$item['transaction_id']][] = $item;
            $itemsById[$item['id']] = $item;
        }

        $rows = [];
        $componentsByTransaction = [];
        $netRevenueCents = 0;
        $costCents = 0;
        foreach ($itemsByTransaction as $transactionId => $transactionItems) {
            $transaction = $transactions[$transactionId];
            $allocationItems = [];
            foreach ($transactionItems as $item) {
                $allocationItems[] = [
                    'id' => $item['id'],
                    'line_cents' => $item['line_cents'],
                    'item_discount_cents' => $item['item_discount_cents'],
                ];
            }
            $components = RefundCalculator::allocateBreakdown(
                $allocationItems,
                $transaction['discount_cents'],
                $transaction['service_cents'],
                $transaction['tax_cents'],
                $transaction['tax_inclusive']
            );
            $componentsByTransaction[$transactionId] = $components;
            $isSaleInPeriod = substr($transaction['created_at'], 0, 10) >= $from
                && substr($transaction['created_at'], 0, 10) <= $to;

            foreach ($transactionItems as $item) {
                if (!$isSaleInPeriod) {
                    continue;
                }
                $key = json_encode([$item['item_type'], $item['item_name']], JSON_THROW_ON_ERROR);
                $rows[$key] ??= [
                    'item_name' => $item['item_name'],
                    'item_type' => $item['item_type'],
                    'quantity_sold_milli' => 0,
                    'quantity_returned_milli' => 0,
                    'sales_cents' => 0,
                    'cost_cents' => 0,
                ];
                $itemCost = (int) round($item['cost_cents_per_unit'] * $item['qty_milli'] / 1000);
                $itemRevenue = $components[$item['id']]['merchandise_cents'];
                $rows[$key]['quantity_sold_milli'] += $item['qty_milli'];
                $rows[$key]['sales_cents'] += $itemRevenue;
                $rows[$key]['cost_cents'] += $itemCost;
                $netRevenueCents += $itemRevenue;
                $costCents += $itemCost;
            }
        }

        usort($returns, static function (array $left, array $right): int {
            return [$left['item_id'], $left['returned_at'], $left['id']]
                <=> [$right['item_id'], $right['returned_at'], $right['id']];
        });
        $returnedQtyByItem = [];
        $returnedRevenueByItem = [];
        foreach ($returns as $return) {
            $item = $itemsById[$return['item_id']] ?? null;
            if ($item === null || $return['qty_milli'] <= 0 || $return['qty_milli'] > $item['qty_milli']) {
                throw new InvalidArgumentException('Report return data is invalid.');
            }
            $components = $componentsByTransaction[$item['transaction_id']] ?? null;
            if ($components === null || !isset($components[$item['id']])) {
                throw new InvalidArgumentException('Report return transaction allocation is missing.');
            }
            $itemRevenue = $components[$item['id']]['merchandise_cents'];
            $alreadyQty = $returnedQtyByItem[$item['id']] ?? 0;
            $alreadyRevenue = $returnedRevenueByItem[$item['id']] ?? 0;
            $returnedRevenue = RefundCalculator::forQuantity(
                $itemRevenue,
                $item['qty_milli'],
                $alreadyQty,
                $return['qty_milli'],
                $alreadyRevenue
            );
            $returnedQtyByItem[$item['id']] = $alreadyQty + $return['qty_milli'];
            $returnedRevenueByItem[$item['id']] = $alreadyRevenue + $returnedRevenue;

            if (substr($return['returned_at'], 0, 10) < $from || substr($return['returned_at'], 0, 10) > $to) {
                continue;
            }

            $key = json_encode([$item['item_type'], $item['item_name']], JSON_THROW_ON_ERROR);
            $rows[$key] ??= [
                'item_name' => $item['item_name'],
                'item_type' => $item['item_type'],
                'quantity_sold_milli' => 0,
                'quantity_returned_milli' => 0,
                'sales_cents' => 0,
                'cost_cents' => 0,
            ];
            $rows[$key]['quantity_returned_milli'] += $return['qty_milli'];
            $rows[$key]['sales_cents'] -= $returnedRevenue;
            $netRevenueCents -= $returnedRevenue;

            if ($return['restocked']) {
                $restockedCost = (int) round($item['cost_cents_per_unit'] * $return['qty_milli'] / 1000);
                $rows[$key]['cost_cents'] -= $restockedCost;
                $costCents -= $restockedCost;
            }
        }

        $topProducts = [];
        foreach ($rows as $row) {
            $topProducts[] = [
                'item_name' => $row['item_name'],
                'item_type' => $row['item_type'],
                'quantity_sold' => $row['quantity_sold_milli'] / 1000,
                'quantity_returned' => $row['quantity_returned_milli'] / 1000,
                'sales_cents' => $row['sales_cents'],
                'cost_cents' => $row['cost_cents'],
                'gross_profit_cents' => $row['sales_cents'] - $row['cost_cents'],
            ];
        }
        usort($topProducts, static fn(array $left, array $right): int => $right['sales_cents'] <=> $left['sales_cents']);

        return [
            'net_revenue_cents' => $netRevenueCents,
            'cost_cents' => $costCents,
            'gross_profit_cents' => $netRevenueCents - $costCents,
            'top_products' => $topProducts,
        ];
    }
}
