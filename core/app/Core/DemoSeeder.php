<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/** Data contoh untuk keenam industri (dipakai installer bila opsi demo dicentang). */
final class DemoSeeder
{
    public static function password(int $len = 10): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    private static function j(array $a): string
    {
        return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{owner:array,cashier:array,prescription_id:int} */
    public static function seed(PDO $pdo, int $planId): array
    {
        $ownerPw = self::password();
        $cashPw = self::password();
        $now = date('Y-m-d H:i:s');

        $pdo->prepare('INSERT INTO tenants (plan_id, name, slug, owner_name, email, status, subscription_ends_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$planId, 'Toko Demo', 'toko-demo', 'Pemilik Demo', 'owner@demo.test', 'active', date('Y-m-d H:i:s', strtotime('+30 days'))]);
        $tid = (int) $pdo->lastInsertId();

        $mod = $pdo->prepare('INSERT INTO tenant_modules (tenant_id, module_code, is_active, settings) VALUES (?, ?, 1, ?)');
        foreach (['retail', 'fnb', 'pharmacy', 'laundry', 'printing', 'service'] as $m) {
            $mod->execute([$tid, $m, $m === 'fnb' ? self::j(['service_charge_pct' => 5]) : null]);
        }

        $pdo->prepare('INSERT INTO branches (tenant_id, code, name, address, settings) VALUES (?, ?, ?, ?, ?)')
            ->execute([$tid, 'PST', 'Cabang Pusat', 'Jl. Contoh No. 1', self::j(['tax_pct' => 11, 'tax_mode' => 'exclusive'])]);
        $bid = (int) $pdo->lastInsertId();

        $usr = $pdo->prepare('INSERT INTO users (tenant_id, branch_id, name, email, password_hash, role, permissions) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $usr->execute([$tid, null, 'Pemilik Demo', 'owner@demo.test', password_hash($ownerPw, PASSWORD_DEFAULT), 'owner', null]);
        $usr->execute([$tid, $bid, 'Kasir Demo', 'kasir@demo.test', password_hash($cashPw, PASSWORD_DEFAULT), 'cashier', self::j(['can_discount' => false])]);

        $tbl = $pdo->prepare('INSERT INTO dining_tables (tenant_id, branch_id, name, capacity) VALUES (?, ?, ?, 4)');
        for ($i = 1; $i <= 6; $i++) {
            $tbl->execute([$tid, $bid, 'Meja ' . $i]);
        }

        $insP = $pdo->prepare('INSERT INTO products (tenant_id, name, product_type, pricing_model, unit, base_price, cost_price, track_stock, track_batch, attributes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insV = $pdo->prepare('INSERT INTO product_variants (tenant_id, product_id, name, sku, barcode, price, cost_price, is_default, attributes) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)');
        $insS = $pdo->prepare('INSERT INTO stock_levels (branch_id, variant_id, tenant_id, qty) VALUES (?, ?, ?, ?)');
        $insB = $pdo->prepare('INSERT INTO product_batches (tenant_id, branch_id, variant_id, batch_number, expired_at, qty_initial, qty_remaining, cost_price, supplier_name, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        // [nama, tipe, model, satuan, harga, HPP, lacak_stok, lacak_batch, atribut_produk, varian[nama,sku,barcode,harga|null,atribut_varian], stok]
        $items = [
            ['Mie Instan Goreng', 'goods', 'unit', 'pcs', 3500, 2800, 1, 0, [], ['Default', 'MIE-001', '8999999000099', null, []], 50],
            ['Air Mineral 600ml', 'goods', 'unit', 'botol', 4000, 2500, 1, 0, [], ['Default', 'AIR-600', '8999999000101', null, []], 100],
            ['Nasi Goreng Spesial', 'menu', 'unit', 'porsi', 28000, 12000, 0, 0,
                ['station' => 'dapur', 'prep_minutes' => 10, 'modifier_groups' => [['name' => 'Level pedas', 'options' => ['0', '1', '2', '3']]]],
                ['Default', 'NGS-001', null, null, []], null],
            ['Es Teh Manis', 'menu', 'unit', 'gelas', 6000, 1500, 0, 0, ['station' => 'bar', 'prep_minutes' => 3], ['Default', 'ETM-001', null, null, []], null],
            ['Paracetamol 500 mg', 'medicine', 'unit', 'strip', 5000, 3000, 1, 1,
                ['requires_prescription' => false, 'generic_name' => 'Paracetamol', 'strength' => '500 mg', 'dosage_form' => 'tablet'],
                ['Strip 10 tablet', 'PCT-500', '8999999000201', null, []], 'batch:PCT2611A:+20 months:100'],
            ['Amoxicillin 500 mg', 'medicine', 'unit', 'strip', 15000, 9000, 1, 1,
                ['requires_prescription' => true, 'generic_name' => 'Amoxicillin', 'strength' => '500 mg', 'dosage_form' => 'kapsul'],
                ['Strip 10 kapsul', 'AMX-500', '8999999000202', null, []], 'batch:AMX2610A:+14 months:100'],
            ['Cuci Setrika Kiloan', 'laundry', 'per_kg', 'kg', 7000, 0, 0, 0,
                ['services' => ['cuci', 'setrika', 'cuci_setrika'], 'express_surcharge_pct' => 50, 'est_hours' => 48],
                ['Reguler', 'LDR-KG', null, null, []], null],
            ['Cuci Bed Cover', 'laundry', 'unit', 'pcs', 25000, 0, 0, 0,
                ['services' => ['cuci'], 'express_surcharge_pct' => 50],
                ['Satuan', 'LDR-BC', null, null, []], null],
            ['Spanduk Flexi', 'print', 'per_m2', 'lembar', 25000, 0, 0, 0,
                ['input_unit' => 'cm', 'min_area_m2' => 1, 'max_width_cm' => 320, 'finishing_options' => ['mata_ayam', 'lipat_jahit']],
                ['Flexi 280gr', 'PRT-FLX', null, 25000, ['material' => 'flexi_280gr', 'price_per_m2' => 25000]], null],
            ['Stiker Vinyl', 'print', 'per_m2', 'lembar', 45000, 0, 0, 0,
                ['input_unit' => 'cm', 'min_area_m2' => 0.5, 'max_width_cm' => 100, 'finishing_options' => ['laminating']],
                ['Vinyl Glossy', 'PRT-VNL', null, 45000, ['material' => 'vinyl_glossy', 'price_per_m2' => 45000]], null],
            ['Potong Rambut', 'service', 'fixed', 'kali', 35000, 0, 0, 0, [], ['Standar', 'SVC-CUT', null, null, []], null],
            ['Servis Motor (per jam)', 'service', 'per_hour', 'jam', 80000, 0, 0, 0,
                ['min_duration_minutes' => 30, 'rounding_minutes' => 15, 'requires_staff' => true],
                ['Standar', 'SVC-MTR', null, null, []], null],
        ];

        foreach ($items as $it) {
            [$name, $type, $model, $unit, $price, $cost, $trackStock, $trackBatch, $pattrs, $var, $stock] = $it;
            $insP->execute([$tid, $name, $type, $model, $unit, $price, $cost, $trackStock, $trackBatch, $pattrs ? self::j($pattrs) : null]);
            $pid = (int) $pdo->lastInsertId();
            $insV->execute([$tid, $pid, $var[0], $var[1], $var[2], $var[3], null, $var[4] ? self::j($var[4]) : null]);
            $vid = (int) $pdo->lastInsertId();
            if (is_int($stock)) {
                $insS->execute([$bid, $vid, $tid, $stock]);
            } elseif (is_string($stock) && strpos($stock, 'batch:') === 0) {
                [, $no, $exp, $qty] = explode(':', $stock);
                $insB->execute([$tid, $bid, $vid, $no, date('Y-m-d', strtotime($exp)), $qty, $qty, $cost, 'PBF Demo', date('Y-m-d')]);
                $insS->execute([$bid, $vid, $tid, $qty]);
            }
        }

        $pdo->prepare('INSERT INTO prescriptions (tenant_id, branch_id, prescription_no, patient_name, doctor_name, doctor_license_no, issued_at, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$tid, $bid, 'RX-DEMO-0001', 'Andi Wijaya', 'dr. Maya Sari', 'SIP-DEMO-123', date('Y-m-d'), self::j([['name' => 'Amoxicillin 500 mg', 'qty' => 2, 'instruction' => '3x1 sesudah makan']])]);
        $rxId = (int) $pdo->lastInsertId();

        return [
            'owner' => ['email' => 'owner@demo.test', 'password' => $ownerPw],
            'cashier' => ['email' => 'kasir@demo.test', 'password' => $cashPw],
            'prescription_id' => $rxId,
        ];
    }
}
