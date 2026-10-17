<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagementReportCsv
{
    public function __construct(private ManagementReportDetailQuery $details) {}

    public function download(array $report): StreamedResponse
    {
        abort_if($report['query'] === null, 503, __('reports.unavailable'));
        /** @var Builder $query */
        $query = $report['query'];

        return response()->streamDownload(function () use ($report, $query): void {
            $handle = fopen('php://output', 'wb');
            try {
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, array_map(fn ($field) => __('reports.columns.'.$field), array_keys($report['columns'])), ',', '"', '');
                foreach ($query->lazy(500) as $row) {
                    $cells = [];
                    foreach ($report['columns'] as $field => $_) {
                        $value = $this->details->cell($row, $field);
                        $numeric = in_array($field, ['amount', 'outstanding', 'credit', 'total', 'delivered', 'version', 'id', 'branch_id', 'captain_id', 'shop_id', 'order_id', 'invoice_id', 'account_id', 'transaction_id', 'line_id'], true);
                        $cells[] = $this->safeCell($value, $numeric);
                    }
                    fputcsv($handle, $cells, ',', '"', '');
                }
            } finally {
                fclose($handle);
            }
        }, 'abshir-'.$report['type'].'-'.$report['filters']['from'].'-'.$report['filters']['to'].'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function safeCell(string $value, bool $numeric = false): string
    {
        // Strict numeric cells preserve signed financial values. All other fields are untrusted text.
        if ($numeric && preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D', $value)) {
            return $value;
        }
        if (preg_match('/\A(?:[\t\r\n]|[\s\x{FEFF}]*[=+\-@])/u', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
