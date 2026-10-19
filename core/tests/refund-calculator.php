<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Core/RefundCalculator.php';
require dirname(__DIR__) . '/app/Core/Environment.php';
require dirname(__DIR__) . '/app/Core/TaxCalculator.php';
require dirname(__DIR__) . '/app/Core/ReportProfitCalculator.php';

use App\Core\Environment;
use App\Core\RefundCalculator;
use App\Core\ReportProfitCalculator;
use App\Core\TaxCalculator;

$assertSame = static function (int $expected, int $actual, string $case): void {
    if ($expected !== $actual) {
        throw new RuntimeException($case . ': expected ' . $expected . ', got ' . $actual);
    }
};

$singleItem = RefundCalculator::allocate(
    [['id' => 1, 'line_cents' => 9000, 'item_discount_cents' => 1000]],
    2000,
    0,
    880
);
$assertSame(8880, $singleItem[1], 'Tax and both levels of discount');

$multipleItems = RefundCalculator::allocate(
    [
        ['id' => 1, 'line_cents' => 9000, 'item_discount_cents' => 1000],
        ['id' => 2, 'line_cents' => 9000, 'item_discount_cents' => 1000],
    ],
    3000,
    500,
    1925
);
$assertSame(9713, $multipleItems[1], 'Deterministic rounding remainder');
$assertSame(9712, $multipleItems[2], 'Second proportional refund');
$assertSame(19425, array_sum($multipleItems), 'Refundable total equals transaction total');

$firstRefund = RefundCalculator::forQuantity(8880, 3000, 0, 1000, 0);
$finalRefund = RefundCalculator::forQuantity(8880, 3000, 1000, 2000, $firstRefund);
$assertSame(2960, $firstRefund, 'Partial quantity refund');
$assertSame(5920, $finalRefund, 'Final partial refund takes remaining cents');
$assertSame(8880, $firstRefund + $finalRefund, 'Split refunds equal full refundable amount');

$inclusiveRefund = RefundCalculator::allocate(
    [
        ['id' => 1, 'line_cents' => 9000, 'item_discount_cents' => 1000],
        ['id' => 2, 'line_cents' => 9000, 'item_discount_cents' => 1000],
    ],
    3000,
    500,
    1000,
    true
);
$assertSame(17500, array_sum($inclusiveRefund), 'Inclusive tax must not be added a second time on refund');

$noTaxTotals = TaxCalculator::calculate(10000, 11, 'none');
$assertSame(0, $noTaxTotals['tax_cents'], 'No-tax mode records no tax');
$assertSame(10000, $noTaxTotals['grand_cents'], 'No-tax mode leaves checkout total unchanged');
$exclusiveTotals = TaxCalculator::calculate(10000, 11, 'exclusive');
$assertSame(1100, $exclusiveTotals['tax_cents'], 'Exclusive tax amount');
$assertSame(11100, $exclusiveTotals['grand_cents'], 'Exclusive tax is added to checkout');
$inclusiveTotals = TaxCalculator::calculate(10000, 11, 'inclusive');
$assertSame(991, $inclusiveTotals['tax_cents'], 'Inclusive tax is extracted from price');
$assertSame(10000, $inclusiveTotals['grand_cents'], 'Inclusive tax does not increase checkout total');

$saleOnlyProfit = ReportProfitCalculator::calculate(
    [10 => [
        'created_at' => '2026-10-17 10:00:00',
        'discount_cents' => 2000,
        'service_cents' => 0,
        'tax_cents' => 880,
        'tax_inclusive' => false,
    ]],
    [[
        'id' => 101,
        'transaction_id' => 10,
        'item_name' => 'Barang',
        'item_type' => 'goods',
        'qty_milli' => 1000,
        'line_cents' => 9000,
        'item_discount_cents' => 1000,
        'cost_cents_per_unit' => 4000,
    ]],
    [],
    '2026-10-17',
    '2026-10-17'
);
$assertSame(8000, $saleOnlyProfit['net_revenue_cents'], 'Report revenue applies item and transaction discounts once');
$assertSame(4000, $saleOnlyProfit['gross_profit_cents'], 'Report gross profit does not subtract discounts twice');

$returnPeriodProfit = ReportProfitCalculator::calculate(
    [10 => [
        'created_at' => '2026-10-01 10:00:00',
        'discount_cents' => 2000,
        'service_cents' => 0,
        'tax_cents' => 880,
        'tax_inclusive' => false,
    ]],
    [[
        'id' => 101,
        'transaction_id' => 10,
        'item_name' => 'Barang',
        'item_type' => 'goods',
        'qty_milli' => 1000,
        'line_cents' => 9000,
        'item_discount_cents' => 1000,
        'cost_cents_per_unit' => 4000,
    ]],
    [['id' => 201, 'item_id' => 101, 'qty_milli' => 1000, 'returned_at' => '2026-10-17 12:00:00', 'restocked' => true]],
    '2026-10-17',
    '2026-10-17'
);
$assertSame(-8000, $returnPeriodProfit['net_revenue_cents'], 'A return is recognized on the return date, independently of sale date');
$assertSame(-4000, $returnPeriodProfit['gross_profit_cents'], 'Restocked returned goods reverse their cost in the return period');
$assertSame(-8000, $returnPeriodProfit['top_products'][0]['sales_cents'], 'Product return is recorded in the return period');

$notRestockedProfit = ReportProfitCalculator::calculate(
    [13 => [
        'created_at' => '2026-10-01 10:00:00',
        'discount_cents' => 0,
        'service_cents' => 0,
        'tax_cents' => 0,
        'tax_inclusive' => false,
    ]],
    [[
        'id' => 131,
        'transaction_id' => 13,
        'item_name' => 'Barang rusak',
        'item_type' => 'goods',
        'qty_milli' => 1000,
        'line_cents' => 8000,
        'item_discount_cents' => 0,
        'cost_cents_per_unit' => 4000,
    ]],
    [['id' => 231, 'item_id' => 131, 'qty_milli' => 1000, 'returned_at' => '2026-10-17 12:00:00', 'restocked' => false]],
    '2026-10-17',
    '2026-10-17'
);
$assertSame(-8000, $notRestockedProfit['gross_profit_cents'], 'A returned item not restored to stock does not reverse its cost');

$partialReturnProfit = ReportProfitCalculator::calculate(
    [11 => [
        'created_at' => '2026-10-01 10:00:00',
        'discount_cents' => 0,
        'service_cents' => 0,
        'tax_cents' => 0,
        'tax_inclusive' => false,
    ]],
    [[
        'id' => 111,
        'transaction_id' => 11,
        'item_name' => 'Produk parsial',
        'item_type' => 'goods',
        'qty_milli' => 1000,
        'line_cents' => 8000,
        'item_discount_cents' => 0,
        'cost_cents_per_unit' => 4000,
    ]],
    [
        ['id' => 211, 'item_id' => 111, 'qty_milli' => 500, 'returned_at' => '2026-10-16 12:00:00', 'restocked' => true],
        ['id' => 212, 'item_id' => 111, 'qty_milli' => 500, 'returned_at' => '2026-10-17 12:00:00', 'restocked' => true],
    ],
    '2026-10-17',
    '2026-10-17'
);
$assertSame(-4000, $partialReturnProfit['net_revenue_cents'], 'Partial refund allocation carries prior return quantities into the selected period');
$assertSame(-2000, $partialReturnProfit['gross_profit_cents'], 'Partial returned quantity restores only its matching cost');

$inclusiveProfit = ReportProfitCalculator::calculate(
    [12 => [
        'created_at' => '2026-10-17 10:00:00',
        'discount_cents' => 0,
        'service_cents' => 0,
        'tax_cents' => 1000,
        'tax_inclusive' => true,
    ]],
    [[
        'id' => 121,
        'transaction_id' => 12,
        'item_name' => 'Harga termasuk pajak',
        'item_type' => 'goods',
        'qty_milli' => 1000,
        'line_cents' => 10000,
        'item_discount_cents' => 0,
        'cost_cents_per_unit' => 0,
    ]],
    [],
    '2026-10-17',
    '2026-10-17'
);
$assertSame(9000, $inclusiveProfit['net_revenue_cents'], 'Inclusive tax is excluded from report merchandise revenue');

$rejectedInvalidQuantity = false;
try {
    RefundCalculator::forQuantity(8880, 3000, 1000, 2500, 0);
} catch (\InvalidArgumentException) {
    $rejectedInvalidQuantity = true;
}
if (!$rejectedInvalidQuantity) {
    throw new RuntimeException('Invalid partial return quantity was accepted.');
}

$password = "p a\"s\\s#word ";
if (Environment::parseValue(Environment::quote($password)) !== $password) {
    throw new RuntimeException('.env password quoting did not preserve special characters.');
}

echo "Refund calculator and .env checks passed.\n";
