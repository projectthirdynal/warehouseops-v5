<x-mail::message>
# Courier Export Batch {{ $batch->batch_number }}

Hello,

{{ $senderName }} has shared a courier export batch with you.

**Batch Number:** {{ $batch->batch_number }}
**Courier:** {{ $batch->courier_code }}
**Rows:** {{ $batch->row_count }}
@if ($batch->region)
**Region:** {{ $batch->region }}
@endif
@if ($batch->file_size_human)
**File Size:** {{ $batch->file_size_human }}
@endif

@if ($customMessage)
---

{{ $customMessage }}

---
@endif

@if ($shareUrl)
<x-mail::button :url="$shareUrl" color="blue">
    Download CSV
</x-mail::button>

This link will expire. Please download the file at your earliest convenience.
@else
<x-mail::button :url="$encoderUrl" color="blue">
    Open Encoder
</x-mail::button>
@endif

<x-mail::panel>
    If you are unable to click the buttons above, copy and paste this URL into your browser:
    {{ $shareUrl ?? $encoderUrl }}
</x-mail::panel>

Thanks,<br>
{{ config('app.name', 'WarehouseOps') }}
</x-mail::message>
