<div class="followup-card">
    <div class="followup-header">
        <div class="followup-title">
            <h3>Followup #{{ $followup->id }}</h3>
            <span class="followup-date">{{ $followup->created_at->format('d.m.Y H:i') }}</span>
        </div>
        <div class="followup-actions">
            <a href="/api/v1/followups/{{ $followup->id }}/export?format=pdf" class="export-btn" target="_blank" title="Скачать PDF">📄 PDF</a>
            <a href="/api/v1/followups/{{ $followup->id }}/export?format=html" class="export-btn" target="_blank" title="Скачать HTML">🌐 HTML</a>
        </div>
    </div>

    @if(isset($data['total']))
    <div class="followup-score">
        <span class="score-label">{{ $data['total']['display_name'] ?? 'Общий балл' }}:</span>
        <span class="score-value">{{ $data['total']['current_value'] ?? 0 }} / {{ $data['total']['max_value'] ?? 0 }}</span>
    </div>
    @endif

    @if(isset($data['metrics']) && is_array($data['metrics']))
    <div class="followup-metrics">
        <h4>Метрики:</h4>
        <table class="metrics-table">
            <thead>
                <tr>
                    <th>Метрика</th>
                    <th>Балл</th>
                    <th>Макс.</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['metrics'] as $metric)
                <tr>
                    <td><strong>{{ $metric['display_name'] ?? 'N/A' }}</strong></td>
                    <td>{{ $metric['current_value'] ?? 0 }}</td>
                    <td>{{ $metric['max_value'] ?? 0 }}</td>
                </tr>
                @if(isset($metric['submetrics']) && is_array($metric['submetrics']))
                    @foreach($metric['submetrics'] as $sub)
                    <tr>
                        <td style="padding-left: 20px;">— {{ $sub['display_name'] ?? 'N/A' }}</td>
                        <td>{{ $sub['current_value'] ?? 0 }}</td>
                        <td>{{ $sub['max_value'] ?? 0 }}</td>
                    </tr>
                    @endforeach
                @endif
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if(isset($data['feedback']))
    <div class="followup-section">
        <h4>Обратная связь:</h4>
        <p>{{ $data['feedback'] }}</p>
    </div>
    @endif

    {{-- New format: conclusion with nested sections --}}
    @if(isset($data['conclusion']) && is_array($data['conclusion']))
    <div class="followup-conclusion">
        <h4>{{ $data['conclusion']['display_name'] ?? 'Результаты' }}:</h4>
        @if(isset($data['conclusion']['value']) && is_array($data['conclusion']['value']))
            @foreach($data['conclusion']['value'] as $section)
            <div class="followup-section">
                <h5>{{ $section['display_name'] ?? 'Раздел' }}:</h5>
                @if(isset($section['value']) && is_array($section['value']))
                <ul>
                    @foreach($section['value'] as $item)
                    <li>{{ is_array($item) ? ($item['text'] ?? json_encode($item)) : $item }}</li>
                    @endforeach
                </ul>
                @endif
            </div>
            @endforeach
        @endif
    </div>
    @endif

    {{-- Legacy format: separate strengths/areas/action_plan fields --}}
    @if(isset($data['strengths']) && is_array($data['strengths']))
    <div class="followup-section">
        <h4>Сильные стороны:</h4>
        <ul>
            @foreach($data['strengths'] as $strength)
            <li>{{ is_array($strength) ? ($strength['text'] ?? json_encode($strength)) : $strength }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    @if(isset($data['areas_for_development']) && is_array($data['areas_for_development']))
    <div class="followup-section">
        <h4>Зоны для развития:</h4>
        <ul>
            @foreach($data['areas_for_development'] as $area)
            <li>{{ is_array($area) ? ($area['text'] ?? json_encode($area)) : $area }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    @if(isset($data['action_plan']) && is_array($data['action_plan']))
    <div class="followup-section">
        <h4>План действий:</h4>
        <ul>
            @foreach($data['action_plan'] as $action)
            <li>{{ is_array($action) ? ($action['text'] ?? json_encode($action)) : $action }}</li>
            @endforeach
        </ul>
    </div>
    @endif
</div>
