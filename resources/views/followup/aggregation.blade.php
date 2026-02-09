<div class="followup-aggregation">
    <h3>Агрегированные метрики</h3>

    <div class="aggregation-summary">
        <p><strong>Количество followup:</strong> {{ $data['count'] ?? 0 }}</p>
    </div>

    @if(isset($data['average_scores']) && count($data['average_scores']) > 0)
    <div class="aggregation-scores">
        <h4>Средние баллы:</h4>
        <table class="scores-table">
            <thead>
                <tr>
                    <th>Метрика</th>
                    <th>Средний балл</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['average_scores'] as $name => $score)
                <tr>
                    <td>{{ $name }}</td>
                    <td>{{ $score }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
        <p class="no-data">Нет данных для отображения</p>
    @endif
</div>
