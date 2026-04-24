<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Waybill\Models\Claim;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ClaimsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(
        private readonly array $filters = [],
        private readonly ?string $generatedBy = null,
    ) {}

    public function collection(): Collection
    {
        $q = Claim::with(['waybill', 'filedBy', 'reviewedBy'])
            ->when($this->filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($this->filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($this->filters['from'] ?? null, fn ($q, $v) => $q->where('filed_at', '>=', $v))
            ->when($this->filters['to'] ?? null, fn ($q, $v) => $q->where('filed_at', '<=', $v . ' 23:59:59'))
            ->latest('filed_at');

        return $q->get();
    }

    public function headings(): array
    {
        return [
            'Claim #',
            'Waybill #',
            'Receiver',
            'City',
            'Claim Type',
            'Status',
            'Claim Amount',
            'Approved Amount',
            'J&T Reference #',
            'Filed By',
            'Filed Date',
            'Resolved Date',
            'Days to Resolve',
            'Resolution Notes',
        ];
    }

    public function map($claim): array
    {
        $filedAt   = $claim->filed_at;
        $resolvedAt = $claim->resolved_at;
        $daysToResolve = ($filedAt && $resolvedAt)
            ? $filedAt->diffInDays($resolvedAt)
            : null;

        return [
            $claim->claim_number,
            $claim->waybill?->waybill_number ?? '',
            $claim->waybill?->receiver_name ?? '',
            $claim->waybill?->city ?? '',
            $claim->type->label(),
            $claim->status->label(),
            number_format((float) $claim->claim_amount, 2),
            $claim->approved_amount !== null ? number_format((float) $claim->approved_amount, 2) : '',
            $claim->jnt_reference_number ?? '',
            $claim->filedBy?->name ?? '',
            $filedAt?->setTimezone('Asia/Manila')->format('Y-m-d H:i') ?? '',
            $resolvedAt?->setTimezone('Asia/Manila')->format('Y-m-d H:i') ?? '',
            $daysToResolve,
            $claim->resolution_notes ?? '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1a1a1a'],
                ],
            ],
        ];
    }
}
