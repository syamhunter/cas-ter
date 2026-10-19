<?php
declare(strict_types=1);

namespace App\Core;

final class Environment
{
    public static function parseValue(string $value): string
    {
        $value = trim($value);
        if (strlen($value) < 2 || !in_array($value[0], ['"', "'"], true) || substr($value, -1) !== $value[0]) {
            return $value;
        }

        $quote = $value[0];
        $value = substr($value, 1, -1);
        if ($quote === '"') {
            $value = preg_replace('/\\\\(["\\\\])/', '$1', $value) ?? $value;
        }

        return $value;
    }

    public static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
