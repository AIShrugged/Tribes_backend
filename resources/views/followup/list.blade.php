<div class="followup-list">
    <h3>Найдено followup: {{ count($items) }}</h3>

    @forelse($items as $item)
        @include('followup.single', ['followup' => $item['followup'], 'data' => $item['data']])
    @empty
        <p class="no-data">Followup не найдены</p>
    @endforelse
</div>
