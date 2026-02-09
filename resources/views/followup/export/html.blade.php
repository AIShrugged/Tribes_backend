<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Followup #{{ $followup->id }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        h1, h2, h3 {
            color: #2c3e50;
        }
        .header {
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .score-box {
            background: #3498db;
            color: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
        }
        .score-value {
            font-size: 2em;
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        th {
            background: #f5f5f5;
        }
        .section {
            margin: 20px 0;
        }
        .section h3 {
            border-left: 4px solid #3498db;
            padding-left: 10px;
        }
        ul {
            padding-left: 20px;
        }
        li {
            margin: 8px 0;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 0.9em;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Followup #{{ $followup->id }}</h1>
        <p>Дата: {{ $followup->created_at->format('d.m.Y H:i') }}</p>
    </div>

    @if($totalScore !== null)
    <div class="score-box">
        <div>Общий балл</div>
        <div class="score-value">{{ $totalScore }}</div>
    </div>
    @endif

    @if(count($metrics) > 0)
    <div class="section">
        <h3>Метрики</h3>
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
    </div>
    @endif

    @if(count($strengths) > 0)
    <div class="section">
        <h3>Сильные стороны</h3>
        <ul>
            @foreach($strengths as $strength)
            <li>{{ $strength }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    @if(count($areas) > 0)
    <div class="section">
        <h3>Зоны для развития</h3>
        <ul>
            @foreach($areas as $area)
            <li>{{ $area }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    @if(count($actionPlan) > 0)
    <div class="section">
        <h3>План действий</h3>
        <ul>
            @foreach($actionPlan as $action)
            <li>{{ $action }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="footer">
        <p>Сгенерировано: {{ now()->format('d.m.Y H:i') }}</p>
    </div>
</body>
</html>
