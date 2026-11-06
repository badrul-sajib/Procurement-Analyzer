<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
    h1 { font-size: 14px; margin: 0 0 4px; }
    .meta { color: #555; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #ddd; padding: 3px 4px; text-align: left; }
    th { background: #f3f4f6; }
    td.num, th.num { text-align: right; }
    tfoot td { font-weight: bold; border-top: 1px solid #999; }
</style>
</head>
<body>
    <h1>{{ $meta['title'] }}</h1>
    <div class="meta">
        Date basis: {{ $meta['date_basis'] }}
        @if ($meta['range']) · {{ $meta['range']['from'] }} to {{ $meta['range']['to'] }} @else · All dates @endif
        · Generated {{ $generated_at->format('d M Y H:i') }} ({{ config('app.business_timezone') }})
        @if ($truncated) · Showing first {{ count($rows) }} of {{ $total_rows }} rows (use Excel/CSV for everything) @endif
    </div>
    <table>
        <thead><tr>
            @foreach ($meta['columns'] as $c)<th class="{{ in_array($c['type'], ['money','qty','int']) ? 'num' : '' }}">{{ $c['label'] }}</th>@endforeach
        </tr></thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>@foreach ($meta['columns'] as $c)<td class="{{ in_array($c['type'], ['money','qty','int']) ? 'num' : '' }}">{{ $row[$c['key']] }}</td>@endforeach</tr>
        @endforeach
        </tbody>
        @if ($totals)
        <tfoot><tr>
            @foreach ($meta['columns'] as $i => $c)<td class="num">{{ $totals[$c['key']] ?? ($i === 0 ? 'Total' : '') }}</td>@endforeach
        </tr></tfoot>
        @endif
    </table>
</body>
</html>
