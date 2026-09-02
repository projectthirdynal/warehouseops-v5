<?php

declare(strict_types=1);

namespace App\Exports;

use Modules\Shop\Models\OrderRemark;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrderRemarksExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        private readonly array $filters = [],
    ) {}

    public function collection(): Collection
    {
        $q = OrderRemark::query()
            ->with(['order:id,order_number', 'user:id,name', 'pinnedBy:id,name'])
            ->whereHas('order', function ($sub) {
                $sub->whereIn('source_channel', ['manual_shop', 'facebook_shop']);
            })
            ->when($this->filters['order_id'] ?? null, fn ($q, $v) => $q->where('order_id', $v))
            ->when($this->filters['remark_q'] ?? null, function ($q, $v) {
                $q->where('body', 'like', "%{$v}%");
            })
            ->when($this->filters['remark_type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($this->filters['remark_author'] ?? null, fn ($q, $v) => $q->where('user_id', (int) $v))
            ->when($this->filters['remark_tag'] ?? null, function ($q, $v) {
                $q->whereJsonContains('tags', $v);
            })
            ->orderByDesc('is_pinned')
            ->orderBy('created_at');

        return $q->get();
    }

    public function headings(): array
    {
        return [
            'Order #',
            'Remark ID',
            'Type',
            'Visibility',
            'Author',
            'Body',
            'Tags',
            'Pinned',
            'Pinned By',
            'Pinned At',
            'Created At',
            'Updated At',
        ];
    }

    public function map($remark): array
    {
        return [
            $remark->order?->order_number ?? (string) $remark->order_id,
            $remark->id,
            $remark->type,
            $remark->visibility,
            $remark->user?->name ?? 'System',
            $remark->body,
            implode(', ', $remark->tags ?? []),
            $remark->is_pinned ? 'Yes' : 'No',
            $remark->pinnedBy?->name ?? '',
            $remark->pinned_at?->setTimezone('Asia/Manila')->format('Y-m-d H:i') ?? '',
            $remark->created_at?->setTimezone('Asia/Manila')->format('Y-m-d H:i') ?? '',
            $remark->updated_at?->setTimezone('Asia/Manila')->format('Y-m-d H:i') ?? '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '2563EB'],
                ],
            ],
        ];
    }
}
