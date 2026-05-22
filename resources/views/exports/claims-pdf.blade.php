<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Arial, sans-serif; font-size: 10px; color: #1a1a1a; }

  .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; border-bottom: 3px solid #f0a500; padding-bottom: 10px; }
  .header img { height: 48px; }
  .header-right { text-align: right; }
  .header-right h1 { font-size: 16px; font-weight: bold; }
  .header-right .meta { font-size: 9px; color: #666; margin-top: 3px; }

  .filters { background: #f5f5f5; border-radius: 4px; padding: 6px 10px; margin-bottom: 12px; font-size: 9px; color: #555; }
  .filters span { margin-right: 16px; }

  table { width: 100%; border-collapse: collapse; font-size: 9px; }
  thead tr { background: #1a1a1a; color: #fff; }
  thead th { padding: 6px 8px; text-align: left; font-weight: bold; white-space: nowrap; }
  tbody tr:nth-child(even) { background: #f9f9f9; }
  tbody tr { border-bottom: 1px solid #e8e8e8; }
  tbody td { padding: 5px 8px; vertical-align: top; }

  .badge { display: inline-block; padding: 1px 6px; border-radius: 10px; font-size: 8px; font-weight: bold; }
  .badge-approved  { background: #d4edda; color: #155724; }
  .badge-settled   { background: #c3e6cb; color: #0c4128; }
  .badge-filed     { background: #cce5ff; color: #004085; }
  .badge-rejected  { background: #f8d7da; color: #721c24; }
  .badge-draft     { background: #e2e3e5; color: #383d41; }
  .badge-review    { background: #fff3cd; color: #856404; }

  .badge-lost      { background: #f8d7da; color: #721c24; }
  .badge-damaged   { background: #ffe8cc; color: #7d4000; }
  .badge-sla       { background: #cce5ff; color: #004085; }

  .amount { text-align: right; font-family: monospace; }
  .footer { margin-top: 14px; font-size: 8px; color: #999; text-align: center; border-top: 1px solid #ddd; padding-top: 6px; }
  .summary { display: flex; gap: 20px; margin-bottom: 12px; }
  .summary-box { background: #f0f0f0; border-radius: 4px; padding: 6px 12px; }
  .summary-box .label { font-size: 8px; color: #666; }
  .summary-box .value { font-size: 13px; font-weight: bold; }
  .green { color: #27ae60; }
</style>
</head>
<body>

<div class="header">
  <img src="{{ public_path('images/tecc-banner.png') }}" alt="TECS">
  <div class="header-right">
    <h1>Claims Report</h1>
    <div class="meta">Generated: {{ $generated }}</div>
    <div class="meta">Total records: {{ $claims->count() }}</div>
  </div>
</div>

@if(array_filter($filters))
<div class="filters">
  <strong>Filters applied:</strong>
  @if(!empty($filters['status'])) <span>Status: {{ $filters['status'] }}</span> @endif
  @if(!empty($filters['type'])) <span>Type: {{ $filters['type'] }}</span> @endif
  @if(!empty($filters['from'])) <span>From: {{ $filters['from'] }}</span> @endif
  @if(!empty($filters['to'])) <span>To: {{ $filters['to'] }}</span> @endif
</div>
@endif

<div class="summary">
  <div class="summary-box">
    <div class="label">Total Claims</div>
    <div class="value">{{ $claims->count() }}</div>
  </div>
  <div class="summary-box">
    <div class="label">Total Claimed</div>
    <div class="value">₱{{ number_format($claims->sum('claim_amount'), 2) }}</div>
  </div>
  <div class="summary-box">
    <div class="label">Total Approved</div>
    <div class="value green">₱{{ number_format($claims->whereIn('status', ['APPROVED','SETTLED'])->sum('approved_amount'), 2) }}</div>
  </div>
  <div class="summary-box">
    <div class="label">Approved / Settled</div>
    <div class="value">{{ $claims->whereIn('status', ['APPROVED','SETTLED'])->count() }}</div>
  </div>
</div>

<table>
  <thead>
    <tr>
      <th>Claim #</th>
      <th>Waybill #</th>
      <th>Receiver</th>
      <th>Type</th>
      <th>Status</th>
      <th class="amount">Claimed</th>
      <th class="amount">Approved</th>
      <th>J&amp;T Ref</th>
      <th>Filed By</th>
      <th>Filed Date</th>
      <th>Resolved</th>
    </tr>
  </thead>
  <tbody>
    @forelse($claims as $claim)
    <tr>
      <td><strong>{{ $claim->claim_number }}</strong></td>
      <td style="font-family:monospace">{{ $claim->waybill?->waybill_number ?? '—' }}</td>
      <td>{{ $claim->waybill?->receiver_name ?? '—' }}</td>
      <td>
        <span class="badge badge-{{ strtolower($claim->type->value === 'BEYOND_SLA' ? 'sla' : $claim->type->value) }}">
          {{ $claim->type->label() }}
        </span>
      </td>
      <td>
        <span class="badge badge-{{ strtolower(str_replace('_','-',$claim->status->value)) }}">
          {{ $claim->status->label() }}
        </span>
      </td>
      <td class="amount">₱{{ number_format($claim->claim_amount, 2) }}</td>
      <td class="amount">{{ $claim->approved_amount !== null ? '₱'.number_format($claim->approved_amount, 2) : '—' }}</td>
      <td style="font-family:monospace;font-size:8px">{{ $claim->jnt_reference_number ?? '—' }}</td>
      <td>{{ $claim->filedBy?->name ?? '—' }}</td>
      <td style="white-space:nowrap">{{ $claim->filed_at?->setTimezone('Asia/Manila')->format('m/d/Y') ?? '—' }}</td>
      <td style="white-space:nowrap">{{ $claim->resolved_at?->setTimezone('Asia/Manila')->format('m/d/Y') ?? '—' }}</td>
    </tr>
    @empty
    <tr><td colspan="11" style="text-align:center;padding:20px;color:#999">No claims found.</td></tr>
    @endforelse
  </tbody>
</table>

<div class="footer">
  Thirdynal E-Commerce System (TECS) — WarehouseOps v5 &bull; Confidential — For internal use only &bull; Page 1
</div>

</body>
</html>
