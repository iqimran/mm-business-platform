<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $number }}</title>
    <style>
        /* 80 mm thermal roll: ~72 mm printable width, one continuous page. */
        @page { margin: 4mm 3.5mm; }
        body { font-family: "DejaVu Sans Mono", monospace; font-size: 7.6pt; line-height: 1.3; color: #000; }
        .center { text-align: center; }
        .business { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; font-weight: bold; }
        .small { font-size: 6.8pt; }
        .title { margin: 4px 0 2px; font-weight: bold; letter-spacing: 1px; }
        .rule { border-top: 1px dashed #000; margin: 4px 0; }
        .rule-solid { border-top: 1px solid #000; margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 0; vertical-align: top; }
        .r { text-align: right; white-space: nowrap; }
        .item-name { padding-top: 2px; }
        .muted { color: #333; }
        .big td { font-size: 9pt; font-weight: bold; }
        .void { margin: 4px 0; padding: 3px; border: 1.5px solid #000; text-align: center; font-weight: bold; }
    </style>
</head>
<body>
    <div class="center">
        <div class="business">{{ $business }}</div>
        @foreach ($business_lines as $line)
            <div class="small">{{ $line }}</div>
        @endforeach
        @if ($branch)
            <div class="small">Branch: {{ $branch->name }} ({{ $branch->code }})@if ($branch->phone) · {{ $branch->phone }}@endif</div>
        @endif
        <div class="title">MONEY RECEIPT</div>
    </div>

    @if ($void)
        <div class="void">*** VOID — PAYMENT REVERSED ***</div>
    @endif

    <table>
        <tr><td>Receipt</td><td class="r">{{ $number }}</td></tr>
        @foreach ($document as $label => $value)
            <tr><td>{{ $label }}</td><td class="r">{{ $value }}</td></tr>
        @endforeach
        <tr><td>Paid on</td><td class="r">{{ $date }}</td></tr>
        <tr><td>Customer</td><td class="r">{{ $customer }}</td></tr>
        @if ($customer_detail)
            <tr><td colspan="2" class="r small">{{ $customer_detail }}</td></tr>
        @endif
    </table>

    <div class="rule"></div>
    <table>
        <tr class="muted"><td>Item</td><td class="r">Amount</td></tr>
    </table>
    <div class="rule"></div>
    <table>
        @foreach ($items as $item)
            <tr><td colspan="2" class="item-name">{{ $item['name'] }}</td></tr>
            <tr><td class="muted">&nbsp;&nbsp;{{ $item['quantity'] }} x {{ $item['unit_price'] }}</td><td class="r">{{ $item['line_total'] }}</td></tr>
        @endforeach
    </table>
    <div class="rule"></div>

    <table>
        <tr class="big"><td>{{ $obligation_label }}</td><td class="r">{{ $obligation }}</td></tr>
    </table>
    <div class="rule"></div>
    <table>
        <tr class="big"><td>This payment</td><td class="r">{{ $amount }}</td></tr>
        <tr><td>Method</td><td class="r">{{ $method }}</td></tr>
        @if ($reference)
            <tr><td>Reference</td><td class="r">{{ $reference }}</td></tr>
        @endif
        <tr><td>Received to date</td><td class="r">{{ $paid_to_date }}</td></tr>
        <tr><td><strong>Balance due</strong></td><td class="r"><strong>{{ $outstanding }}</strong></td></tr>
    </table>
    <div class="small" style="margin-top: 3px;">{{ $amount_words }}</div>

    @if ($notes)
        <div class="small" style="margin-top: 3px;">Note: {{ $notes }}</div>
    @endif

    @if ($void)
        <div class="small" style="margin-top: 3px;">
            Reversed {{ $void['at'] }}@if ($void['by']) by {{ $void['by'] }}@endif: {{ $void['reason'] }}. Not valid as proof of payment; excluded from the balance above.
        </div>
    @endif

    <div class="rule-solid"></div>
    <div class="center small">
        Amounts in Tk · Served by {{ $recorded_by ?? '—' }}<br>
        Printed {{ $printed_at }}<br>
        <strong>Thank you! Please come again.</strong>
    </div>
</body>
</html>
