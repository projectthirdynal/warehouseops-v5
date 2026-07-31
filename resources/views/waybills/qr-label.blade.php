<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Label — {{ $waybill_number }}</title>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; background: #f5f5f5; padding: 20px; }
        .label {
            width: 400px; margin: 0 auto; background: white; padding: 20px;
            border: 2px solid #333; border-radius: 8px;
        }
        .label-header {
            text-align: center; border-bottom: 2px dashed #999;
            padding-bottom: 12px; margin-bottom: 12px;
        }
        .label-header h1 { font-size: 18px; font-weight: bold; }
        .label-header p { font-size: 11px; color: #666; margin-top: 2px; }
        .qr-container { text-align: center; margin: 16px 0; }
        .qr-container canvas, .qr-container img { margin: 0 auto; }
        .waybill-number {
            text-align: center; font-size: 20px; font-weight: bold;
            letter-spacing: 2px; margin: 12px 0; word-break: break-all;
        }
        .info-row { display: flex; justify-content: space-between; font-size: 12px; margin: 4px 0; }
        .info-row .label-text { color: #666; }
        .info-row .value { font-weight: bold; text-align: right; }
        .address-box {
            border: 1px solid #ddd; padding: 8px; margin: 8px 0;
            font-size: 12px; line-height: 1.4;
        }
        .courier-badge {
            display: inline-block; padding: 2px 8px; border-radius: 4px;
            background: #e0e0e0; font-size: 11px; font-weight: bold;
        }
        .footer { text-align: center; font-size: 10px; color: #999; margin-top: 12px; border-top: 1px dashed #ccc; padding-top: 8px; }
        @media print { body { background: white; padding: 0; } .label { border: 1px solid #000; } }
        .print-btn {
            display: block; margin: 20px auto; padding: 8px 24px;
            background: #3b82f6; color: white; border: none; border-radius: 6px;
            cursor: pointer; font-size: 14px;
        }
        .print-btn:hover { background: #2563eb; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">Print Label</button>

    <div class="label">
        <div class="label-header">
            <h1>{{ $company }}</h1>
            <p>Shipping Label — Generated {{ $generated_at }}</p>
        </div>

        <div class="qr-container">
            <div id="qrcode"></div>
        </div>

        <div class="waybill-number">{{ $waybill_number }}</div>

        <div class="info-row">
            <span class="label-text">Courier:</span>
            <span class="value"><span class="courier-badge">{{ $shipment['courier_provider'] }}</span></span>
        </div>

        @if($shipment['express_type'])
        <div class="info-row">
            <span class="label-text">Service:</span>
            <span class="value">{{ $shipment['express_type'] }}</span>
        </div>
        @endif

        <div class="info-row">
            <span class="label-text">COD Amount:</span>
            <span class="value">₱{{ number_format($shipment['cod_amount'], 2) }}</span>
        </div>

        @if($shipment['item_name'])
        <div class="info-row">
            <span class="label-text">Item:</span>
            <span class="value">{{ $shipment['item_name'] }} ×{{ $shipment['item_qty'] }}</span>
        </div>
        @endif

        <div class="address-box">
            <strong>To:</strong> {{ $destination['receiver_name'] }}<br>
            <strong>Phone:</strong> {{ $destination['receiver_phone'] }}<br>
            <strong>Address:</strong> {{ $full_address }}
        </div>

        <div class="footer">
            Scan QR code to track shipment<br>
            {{ $tracking_url }}
        </div>
    </div>

    <script>
        new QRCode(document.getElementById('qrcode'), {
            text: {{ json_encode($qr_content) }},
            width: 200,
            height: 200,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    </script>
</body>
</html>
