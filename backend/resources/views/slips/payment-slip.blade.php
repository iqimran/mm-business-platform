<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $number }}</title>
    <style>
        @page { margin: 12mm 11mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #111; }
        .head { text-align: center; border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 8px; }
        .business { font-size: 15pt; font-weight: bold; }
        .branch { color: #444; font-size: 8pt; margin-top: 2px; }
        .title { display: inline-block; margin-top: 6px; padding: 2px 14px; border: 1.5px solid #111; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 2px 0; }
        .label { color: #555; width: 38%; }
        .amount-box { margin: 10px 0; border: 1.5px solid #111; padding: 6px 8px; }
        .amount { font-size: 15pt; font-weight: bold; }
        .words { font-style: italic; margin-top: 2px; }
        .section { margin-top: 8px; font-weight: bold; border-bottom: 1px solid #bbb; padding-bottom: 2px; }
        .figures td { padding: 2px 0; }
        .figures td.num { text-align: right; font-family: "DejaVu Sans Mono", monospace; }
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
        @if ($branch)
            <div class="branch">
                {{ $branch->name }} ({{ $branch->code }})@if ($branch->address) · {{ $branch->address }}@endif @if ($branch->phone) · {{ $branch->phone }}@endif
            </div>
        @endif
        <div class="title">{{ $title }}</div>
    </div>

    <table class="meta">
        <tr><td class="label">{{ $title === 'Money Receipt' ? 'Receipt no.' : 'Voucher no.' }}</td><td><strong>{{ $number }}</strong></td></tr>
        <tr><td class="label">Date</td><td>{{ $date }}</td></tr>
        <tr><td class="label">{{ $counterparty_label }}</td><td><strong>{{ $counterparty }}</strong>@if ($counterparty_detail)<br><span style="color:#555">{{ $counterparty_detail }}</span>@endif</td></tr>
        <tr><td class="label">Payment method</td><td>{{ $method }}@if ($reference) · Ref. {{ $reference }}@endif</td></tr>
    </table>

    <div class="amount-box">
        <div class="amount">Tk {{ $amount }}</div>
        <div class="words">{{ $amount_words }}</div>
    </div>

    <div class="section">{{ $purpose }}</div>
    <table class="meta">
        <tr><td class="label">Car</td><td>{{ $car }}</td></tr>
        <tr><td class="label">Chassis no.</td><td>{{ $chassis }}</td></tr>
        @if ($registration)
            <tr><td class="label">Registration no.</td><td>{{ $registration }}</td></tr>
        @endif
    </table>

    <div class="section">Account position after this payment</div>
    <table class="figures">
        <tr><td>{{ $obligation_label }}</td><td class="num">Tk {{ $obligation }}</td></tr>
        <tr><td>{{ $paid_label }}</td><td class="num">Tk {{ $paid_to_date }}</td></tr>
        <tr class="total"><td>{{ $outstanding_label }}</td><td class="num">Tk {{ $outstanding }}</td></tr>
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
            @foreach ($signatures as $signature)
                <td><span>{{ $signature }}</span></td>
            @endforeach
        </tr>
    </table>

    <div class="foot">
        Recorded by {{ $recorded_by ?? '—' }} · Printed {{ $printed_at }} · Computer-generated document
    </div>
</body>
</html>
