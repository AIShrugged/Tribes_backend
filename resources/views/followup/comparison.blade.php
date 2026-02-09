<div class="followup-comparison">
    <h3>Сравнение followup ({{ count($items) }} записей)</h3>

    @if(count($items) > 0)
    <table class="comparison-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Дата</th>
                <th>Общий балл</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            <tr>
                <td>{{ $item['followup']->id }}</td>
                <td>{{ $item['followup']->created_at->format('d.m.Y') }}</td>
                <td>{{ $item['data']['total']['current_value'] ?? 'N/A' }} / {{ $item['data']['total']['max_value'] ?? 'N/A' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="comparison-details">
        @foreach($items as $item)
            @include('followup.single', ['followup' => $item['followup'], 'data' => $item['data']])
        @endforeach
    </div>
    @else
        <p class="no-data">Нет данных для сравнения</p>
    @endif
</div>
