<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Arial, sans-serif; font-size: 10px; color: #1a1a1a; }

  .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; border-bottom: 3px solid #c0392b; padding-bottom: 10px; }
  .header img { height: 48px; }
  .header-right { text-align: right; }
  .header-right h1 { font-size: 16px; font-weight: bold; color: #c0392b; }
  .header-right .meta { font-size: 9px; color: #666; margin-top: 3px; }

  .alert { background: #fdecea; border: 1px solid #f5c6cb; border-radius: 4px; padding: 8px 12px; margin-bottom: 12px; font-size: 9px; color: #721c24; font-weight: bold; }

  .filters { background: #f5f5f5; border-radius: 4px; padding: 6px 10px; margin-bottom: 12px; font-size: 9px; color: #555; }
  .filters span { margin-right: 16px; }

  table { width: 100%; border-collapse: collapse; font-size: 9px; }
  thead tr { background: #c0392b; color: #fff; }
  thead th { padding: 6px 8px; text-align: left; font-weight: bold; white-space: nowrap; }
  tbody tr:nth-child(even) { background: #fef9f9; }
  tbody tr { border-bottom: 1px solid #f0e0e0; }
  tbody td { padding: 5px 8px; vertical-align: top; }

  .overdue { display: inline-block; padding: 1px 6px; border-radius: 10px; font-size: 8px; font-weight: bold; }
  .overdue-high { background: #f8d7da; color: #721c24; }
  .overdue-low  { background: #ffe8cc; color: #7d4000; }

  .amount { text-align: right; font-family: monospace; }
  .footer { margin-top: 14px; font-size: 8px; color: #999; text-align: center; border-top: 1px solid #ddd; padding-top: 6px; }

  .summary { display: flex; gap: 20px; margin-bottom: 12px; }
  .summary-box { background: #fdecea; border: 1px solid #f5c6cb; border-radius: 4px; padding: 6px 12px; }
  .summary-box .label { font-size: 8px; color: #666; }
  .summary-box .value { font-size: 13px; font-weight: bold; color: #c0392b; }
</style>
</head>
<body>

<div class="header">
  <img src="{{ public_path('images/tecc-banner.png') }}" alt="TECS">
  <div class="header-right">
    <h1>Beyond SLA Report</h1>
    <div class="meta">Generated: {{ $generated }}</div>
    <div class="meta">Total records: {{ $waybills->count() }}</div>
  </div>
</div>

<div class="alert">
  ⚠ {{ $waybills->count() }} parcel(s) beyond SLA — J&T Express is obligated to compensate for these parcels.
</div>

@if(array_filter($filters))
<div class="filters">
  <strong>Filters applied:</strong>
  @if(!empty($filters['from'])) <span>From: {{ $filters['from'] }}</span> @endif
  @if(!empty($filters['to'])) <span>To: {{ $filters['to'] }}</span> @endif
  @if(!empty($filters['search'])) <span>Search: {{ $filters['search'] }}</span> @endif
</div>
@endif

@php
  $totalCod = $waybills->sum(fn($w) => $w->cod_amount ?? $w->amount ?? 0);
@endphp

<div class="summary">
  <div class="summary-box">
    <div class="label">Total Parcels Beyond SLA</div>
    <div class="value">{{ $waybills->count() }}</div>
  </div>
  <div class="summary-box">
    <div class="label">Total COD Value at Risk</div>
    <div class="value">₱{{ number_format($totalCod, 2) }}</div>
  </div>
</div>

<table>
  <thead>
    <tr>
      <th>Waybill #</th>
      <th>Receiver</th>
      <th>Phone</th>
      <th>City</th>
      <th>Province</th>
      <th class="amount">COD Amount</th>
      <th>Return Date</th>
      <th>SLA Deadline</th>
      <th>Days Overdue</th>
    </tr>
  </thead>
  <tbody>
    @forelse($waybills as $w)
    @php
      $returnedAt = $w->returned_at;
      $daysOverdue = $returnedAt ? (int) now()->setTimezone('Asia/Manila')->diffInDays($returnedAt->setTimezone('Asia/Manila')->endOfDay()) : 0;
    @endphp
    <tr>
      <td style="font-family:monospace"><strong>{{ $w->waybill_number }}</strong></td>
      <td>{{ $w->receiver_name }}</td>
      <td style="font-family:monospace">{{ $w->receiver_phone ?? '—' }}</td>
      <td>{{ $w->city ?? '—' }}</td>
      <td>{{ $w->state ?? '—' }}</td>
      <td class="amount">₱{{ number_format($w->cod_amount ?? $w->amount ?? 0, 2) }}</td>
      <td style="white-space:nowrap">{{ $returnedAt?->setTimezone('Asia/Manila')->format('m/d/Y') ?? '—' }}</td>
      <td style="white-space:nowrap">{{ $returnedAt?->setTimezone('Asia/Manila')->addDay()->format('m/d/Y') ?? '—' }}</td>
      <td>
        <span class="overdue {{ $daysOverdue >= 3 ? 'overdue-high' : 'overdue-low' }}">
          {{ $daysOverdue }}d
        </span>
      </td>
    </tr>
    @empty
    <tr><td colspan="9" style="text-align:center;padding:20px;color:#999">No parcels beyond SLA.</td></tr>
    @endforelse
  </tbody>
</table>

<div class="footer">
  Thirdynal E-Commerce System (TECS) — WarehouseOps v5 &bull; Confidential — For internal use only &bull; Page 1
</div>

</body>
</html>
