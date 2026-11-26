<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class Csv
{
    /** @param iterable<array<string, mixed>> $rows */
    public static function download(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return new StreamedResponse(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($c) => self::cell($row[$c] ?? null), $columns), ',', '"', '');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** Neutralise spreadsheet formula injection. */
    public static function cell(mixed $v): string
    {
        if (is_array($v)) {
            $v = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $v = $v === null ? '' : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);

        return preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
    }
}
