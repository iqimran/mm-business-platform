<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Money Receipt {{ $number }}</title>
    <style>
        @page { margin: 12mm 11mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #111; }
        .head { text-align: center; border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 8px; }
        .business { font-size: 15pt; font-weight: bold; }
        .business-line { color: #333; font-size: 8pt; margin-top: 1px; }
        .branch { color: #444; font-size: 8pt; margin-top: 2px; }
        .title { display: inline-block; margin-top: 6px; padding: 2px 14px; border: 1.5px solid #111; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 2px 0; vertical-align: top; }
        .label { color: #555; width: 38%; }
        .amount-box { margin: 10px 0; border: 1.5px solid #111; padding: 6px 8px; }
        .amount { font-size: 15pt; font-weight: bold; }
        .words { font-style: italic; margin-top: 2px; }
        .section { margin-top: 8px; font-weight: bold; border-bottom: 1px solid #bbb; padding-bottom: 2px; }
        .items th { text-align: left; font-weight: normal; color: #555; border-bottom: 1px solid #ddd; padding: 2px 0; }
        .items td { padding: 2px 0; }
        .items th.num { text-align: right; }
        .num { text-align: right; font-family: "DejaVu Sans Mono", monospace; }
        .figures td { padding: 2px 0; }
        .figures tr.total td { border-top: 1px solid #111; font-weight: bold; }
        .sign { margin-top: 34px; }
        .sign td { width: 50%; text-align: center; padding-top: 3px; }
        .sign span { display: inline-block; width: 80%; border-top: 1px solid #111; padding-top: 3px; }
        .foot { margin-top: 10px; font-size: 7pt; color: #666; text-align: center; }
        .void { position: fixed; top: 38%; left: 0; right: 0; text-align: center; font-size: 60pt; font-weight: bold; color: rgba(185, 28, 28, 0.18); transform: rotate(-25deg); }
        .void-note { margin-top: 6px; padding: 4px 6px; border: 1px solid #b91c1c; color: #b91c1c; font-size: 8pt; }
    </style>
</head>
<body>
    @if ($void)
        <div class="void">VOID</div>
    @endif

    <div class="head">
        <div class="business">{{ $business }}</div>
        @foreach ($business_lines as $line)
            <div class="business-line">{{ $line }}</div>
        @endforeach
        @if ($branch)
            <div class="branch">
                Branch: {{ $branch->name }} ({{ $branch->code }})@if ($branch->address) · {{ $branch->address }}@endif @if ($branch->phone) · {{ $branch->phone }}@endif
            </div>
        @endif
        <div class="title">Money Receipt</div>
    </div>

    <table class="meta">
        <tr><td class="label">Receipt no.</td><td><strong>{{ $number }}</strong></td></tr>
        <tr><td class="label">Date</td><td>{{ $date }}</td></tr>
        <tr><td class="label">Received with thanks from</td><td><strong>{{ $customer }}</strong>@if ($customer_detail)<br><span style="color:#555">{{ $customer_detail }}</span>@endif</td></tr>
        <tr><td class="label">Payment method</td><td>{{ $method }}@if ($reference) · Ref. {{ $reference }}@endif</td></tr>
    </table>

    <div class="amount-box">
        <div class="amount">Tk {{ $amount }}</div>
        <div class="words">{{ $amount_words }}</div>
    </div>

    <div class="section">{{ $purpose }}</div>
    <table class="meta">
        @foreach ($document as $label => $value)
            <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>

    @if (count($items) > 0)
        <table class="items" style="margin-top: 4px;">
            <tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item['name'] }}</td>
                    <td class="num">{{ $item['quantity'] }}</td>
                    <td class="num">{{ $item['unit_price'] }}</td>
                    <td class="num">{{ $item['line_total'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="section">Account position after this payment</div>
    <table class="figures">
        <tr><td>{{ $obligation_label }}</td><td class="num">Tk {{ $obligation }}</td></tr>
        <tr><td>Total received to date</td><td class="num">Tk {{ $paid_to_date }}</td></tr>
        <tr class="total"><td>Balance due</td><td class="num">Tk {{ $outstanding }}</td></tr>
    </table>

    @if ($notes)
        <div style="margin-top: 6px;"><span class="label">Note:</span> {{ $notes }}</div>
    @endif

    @if ($void)
        <div class="void-note">
            This payment was REVERSED on {{ $void['at'] }}@if ($void['by']) by {{ $void['by'] }}@endif. Reason: {{ $void['reason'] }}.
            It is not valid as proof of payment and is excluded from the balances above.
        </div>
    @endif

    <table class="sign">
        <tr>
            <td><span>Customer</span></td>
            <td><span>Received by</span></td>
        </tr>
    </table>

    <div class="foot">
        Recorded by {{ $recorded_by ?? '—' }} · Printed {{ $printed_at }} · Computer-generated document
    </div>
</body>
</html>
