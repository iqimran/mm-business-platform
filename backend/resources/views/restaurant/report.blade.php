<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 18mm 12mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: {{ $fontSize }}; color: #111; }
        .letterhead { text-align: center; border-bottom: 1.5px solid #111; padding-bottom: 5px; margin-bottom: 8px; }
        .letterhead .name { font-size: 15pt; font-weight: bold; }
        .letterhead .line { color: #333; font-size: 8pt; margin-top: 1px; }
        h1 { font-size: 14pt; margin: 0 0 4px; }
        .meta { color: #555; margin-bottom: 10px; }
        .meta div { margin: 1px 0; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td, th { word-wrap: break-word; }
        th { background: #f1f1f1; text-align: left; font-weight: bold; }
        th, td { border: 1px solid #ccc; padding: 3px 5px; vertical-align: top; }
        td.num, th.num { text-align: right; }
        td.date { white-space: nowrap; }
        tfoot td { font-weight: bold; background: #f7f7f7; }
        .empty { text-align: center; color: #777; padding: 16px; }
        .note { margin-top: 8px; color: #555; font-size: 7.5pt; }
    </style>
</head>
<body>
    @if (! empty($letterhead))
        <div class="letterhead">
            <div class="name">{{ $letterhead['name'] }}</div>
            @foreach ($letterhead['lines'] as $line)
                <div class="line">{{ $line }}</div>
            @endforeach
        </div>
    @endif
    <h1>{{ $title }}</h1>
    <div class="meta">
        @foreach ($meta as $label => $value)
            <div><strong>{{ $label }}:</strong> {{ $value }}</div>
        @endforeach
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($columns as $c)
                    <th class="{{ in_array($c['type'], ['money', 'int']) ? 'num' : '' }}">{{ $c['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $i => $value)
                        <td class="{{ in_array($columns[$i]['type'], ['money', 'int']) ? 'num' : '' }} {{ $columns[$i]['type'] === 'date' ? 'date' : '' }}">{{ $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}" class="empty">No records match these filters.</td></tr>
            @endforelse
        </tbody>
        @if ($totals && count($rows) > 0)
            <tfoot>
                <tr>
                    @foreach ($totals as $i => $value)
                        <td class="{{ in_array($columns[$i]['type'], ['money', 'int']) ? 'num' : '' }}">{{ $value }}</td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    @if (! empty($note))
        <div class="note">{{ $note }}</div>
    @endif
</body>
</html>
