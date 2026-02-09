<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Followup #{{ $followup->id }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        h1 {
            font-size: 18px;
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 8px;
        }
        h2 {
            font-size: 14px;
            color: #2c3e50;
            margin-top: 20px;
        }
        .score-box {
            background: #3498db;
            color: white;
            padding: 10px;
            text-align: center;
            margin: 15px 0;
        }
        .score-value {
            font-size: 24px;
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            font-size: 11px;
        }
        th {
            background: #f5f5f5;
        }
        ul {
            padding-left: 15px;
            margin: 5px 0;
        }
        li {
            margin: 4px 0;
        }
        .footer {
            margin-top: 30px;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <h1>Followup #{{ $followup->id }}</h1>
    <p>Дата: {{ $followup->created_at->format('d.m.Y H:i') }}</p>

    @if($totalScore !== null)
    <div class="score-box">
        <div>Общий балл</div>
        <div class="score-value">{{ $totalScore }}</div>
    </div>
    @endif

    @if(count($metrics) > 0)
    <h2>Метрики</h2>
    <table>
        <thead>
            <tr>
                <th>Метрика</th>
                <th>Балл</th>
                <th>Комментарий</th>
            </tr>
        </thead>
        <tbody>
            @foreach($metrics as $metric)
            <tr>
                <td>{{ $metric['name'] }}</td>
                <td>{{ $metric['score'] }}</td>
                <td>{{ $metric['comment'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if(count($strengths) > 0)
    <h2>Сильные стороны</h2>
    <ul>
        @foreach($strengths as $strength)
        <li>{{ $strength }}</li>
        @endforeach
    </ul>
    @endif

    @if(count($areas) > 0)
    <h2>Зоны для развития</h2>
    <ul>
        @foreach($areas as $area)
        <li>{{ $area }}</li>
        @endforeach
    </ul>
    @endif

    @if(count($actionPlan) > 0)
    <h2>План действий</h2>
    <ul>
        @foreach($actionPlan as $action)
        <li>{{ $action }}</li>
        @endforeach
    </ul>
    @endif

    <div class="footer">
        <p>Сгенерировано: {{ now()->format('d.m.Y H:i') }}</p>
    </div>
</body>
</html>
