@if ($shouldRenderDescriptionCard)
    <div class="card card--compact">
        <div class="card-head">Inspection Description</div>
        <div class="card-body">
            <div class="text-block-label">Summary</div>
            <div class="text-block-value">{{ trim($description) !== '' ? $description : 'No description provided.' }}</div>
        </div>
    </div>
@endif

@if ($shouldRenderChecklistCard)
    <div class="card card--compact">
        <div class="card-head">Checklist</div>
        <div class="card-body">
            <ul class="checklist-list">
                @foreach ($checklist as $item)
                    <li>{{ trim((string) ($item['label'] ?? '')) }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
