<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Waybill;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BeyondSlaExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(
        private readonly array $filters = [],
    ) {}

    public function collection(): Collection
    {
        $today = now()->setTimezone('Asia/Manila')->startOfDay()->utc();

        $q = Waybill::where('status', 'RETURNED')
            ->where('returned_at', '<', $today)
            ->whereDoesntHave('returnReceipt')
            ->with(['claims'])
            ->when($this->filters['from'] ?? null, fn ($q, $v) => $q->where('returned_at', '>=', $v))
            ->when($this->filters['to'] ?? null, fn ($q, $v) => $q->where('returned_at', '<=', $v . ' 23:59:59'))
            ->when($this->filters['search'] ?? null, fn ($q, $v) =>
                $q->where('waybill_number', 'ILIKE', "%{$v}%")
                  ->orWhere('receiver_name', 'ILIKE', "%{$v}%"))
            ->latest('returned_at');

        return $q->get();
    }

    public function headings(): array
    {
        return [
            'Waybill #',
            'Receiver',
            'Phone',
            'City',
            'Province',
            'Courier',
            'COD Amount',
            'Return Date',
            'Days Overdue',
            'SLA Deadline',
            'Existing Claims',
            'Waybill Status',
        ];
    }

    public function map($waybill): array
    {
        $returnedAt  = $waybill->returned_at;
        $daysOverdue = $returnedAt
            ? (int) now()->setTimezone('Asia/Manila')->diffInDays(
                $returnedAt->setTimezone('Asia/Manila')->endOfDay()
              )
            : 0;
        $slaDeadline = $returnedAt
            ? $returnedAt->setTimezone('Asia/Manila')->addDay()->format('Y-m-d')
            : '';

        return [
            $waybill->waybill_number,
            $waybill->receiver_name,
            $waybill->receiver_phone ?? '',
            $waybill->city ?? '',
            $waybill->state ?? '',
            $waybill->courier_provider ?? '',
            number_format((float) ($waybill->cod_amount ?? $waybill->amount ?? 0), 2),
            $returnedAt?->setTimezone('Asia/Manila')->format('Y-m-d H:i') ?? '',
            $daysOverdue,
            $slaDeadline,
            $waybill->claims?->count() ?? 0,
            is_string($waybill->status) ? $waybill->status : $waybill->status->value,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'C0392B'],
                ],
            ],
        ];
    }
}
