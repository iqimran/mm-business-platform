<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    @php($docTitle = $title ?? 'Money Receipt')
    <title>{{ $docTitle }} {{ $number }}</title>
    {{-- Sizes scale down (via $scale) when the content would not fit on one page; see PaymentReceipt::pdf(). --}}
    @php($scale = $scale ?? 1.0)
    @php($z = fn (float $value, string $unit) => round($value * $scale, 2).$unit)
    <style>
        /* A4 voucher */
        @page { margin: 16mm 18mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: {{ $z(10, 'pt') }}; color: #111; }
        .head { text-align: center; border-bottom: 2px solid #111; padding-bottom: {{ $z(6, 'px') }}; margin-bottom: {{ $z(8, 'px') }}; }
        .business { font-size: {{ $z(18, 'pt') }}; font-weight: bold; }
        .business-line { color: #333; font-size: {{ $z(9, 'pt') }}; margin-top: {{ $z(1, 'px') }}; }
        .branch { color: #444; font-size: {{ $z(9, 'pt') }}; margin-top: {{ $z(2, 'px') }}; }
        .title { display: inline-block; margin-top: {{ $z(6, 'px') }}; padding: {{ $z(2, 'px') }} {{ $z(14, 'px') }}; border: 1.5px solid #111; font-weight: bold; letter-spacing: {{ $z(1, 'px') }}; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: {{ $z(2, 'px') }} 0; vertical-align: top; }
        .label { color: #555; width: 38%; }
        .amount-box { margin: {{ $z(10, 'px') }} 0; border: 1.5px solid #111; padding: {{ $z(6, 'px') }} {{ $z(8, 'px') }}; }
        .amount { font-size: {{ $z(18, 'pt') }}; font-weight: bold; }
        .words { font-style: italic; margin-top: {{ $z(2, 'px') }}; }
        .section { margin-top: {{ $z(8, 'px') }}; font-weight: bold; border-bottom: 1px solid #bbb; padding-bottom: {{ $z(2, 'px') }}; }
        .items th { text-align: left; font-weight: normal; color: #555; border-bottom: 1px solid #ddd; padding: {{ $z(2, 'px') }} 0; }
        .items td { padding: {{ $z(2, 'px') }} 0; }
        .items th.num { text-align: right; }
        .num { text-align: right; font-family: "DejaVu Sans Mono", monospace; }
        .figures td { padding: {{ $z(2, 'px') }} 0; }
        .figures td.num { white-space: nowrap; width: 30%; }
        .figures tr.total td { border-top: 1px solid #111; font-weight: bold; }
        .sign { margin-top: {{ $z(50, 'px') }}; }
        .sign td { width: 50%; text-align: center; padding-top: {{ $z(3, 'px') }}; }
        .sign span { display: inline-block; width: 80%; border-top: 1px solid #111; padding-top: {{ $z(3, 'px') }}; }
        .foot { margin-top: {{ $z(12, 'px') }}; font-size: {{ $z(8, 'pt') }}; color: #666; text-align: center; }
        .void { position: fixed; top: 38%; left: 0; right: 0; text-align: center; font-size: {{ $z(90, 'pt') }}; font-weight: bold; color: rgba(185, 28, 28, 0.18); transform: rotate(-25deg); }
        .void-note { margin-top: {{ $z(6, 'px') }}; padding: {{ $z(4, 'px') }} {{ $z(6, 'px') }}; border: 1px solid #b91c1c; color: #b91c1c; font-size: {{ $z(8, 'pt') }}; }
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
        <div class="title">{{ $docTitle }}</div>
    </div>

    <table class="meta">
        <tr><td class="label">{{ $number_label ?? 'Receipt no.' }}</td><td><strong>{{ $number }}</strong></td></tr>
        <tr><td class="label">Date</td><td>{{ $date }}</td></tr>
        <tr><td class="label">{{ $counterparty_label ?? 'Received with thanks from' }}</td><td><strong>{{ $customer }}</strong>@if ($customer_detail)<br><span style="color:#555">{{ $customer_detail }}</span>@endif</td></tr>
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
        <table class="items" style="margin-top: {{ $z(4, 'px') }};">
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

    @if (! empty($charges))
        <div class="section">Booking charges</div>
        <table class="figures">
            @foreach ($charges as $charge)
                <tr><td>{{ $charge['label'] }}</td><td class="num">Tk {{ $charge['amount'] }}</td></tr>
            @endforeach
        </table>
        @if (! empty($package_items))
            <div style="margin-top: {{ $z(2, 'px') }}; color: #444;">Menu: {{ implode(', ', $package_items) }}</div>
        @endif
    @endif

    <div class="section">Account position after this payment</div>
    <table class="figures">
        <tr><td>{{ $obligation_label }}</td><td class="num">Tk {{ $obligation }}</td></tr>
        <tr><td>Total received to date</td><td class="num">Tk {{ $paid_to_date }}</td></tr>
        <tr class="total"><td>Balance due</td><td class="num">Tk {{ $outstanding }}</td></tr>
    </table>

    @if ($notes)
        <div style="margin-top: {{ $z(6, 'px') }};"><span class="label">Note:</span> {{ $notes }}</div>
    @endif

    @if ($void)
        <div class="void-note">
            This payment was REVERSED on {{ $void['at'] }}@if ($void['by']) by {{ $void['by'] }}@endif. Reason: {{ $void['reason'] }}.
            It is not valid as proof of payment and is excluded from the balances above.
        </div>
    @endif

    <table class="sign">
        <tr>
            @foreach ($signatures ?? ['Customer', 'Received by'] as $signature)
                <td><span>{{ $signature }}</span></td>
            @endforeach
        </tr>
    </table>

    <div class="foot">
        Recorded by {{ $recorded_by ?? '—' }} · Printed {{ $printed_at }} · Computer-generated document
    </div>
</body>
</html>
