<h2>Attribuzione AI per commit</h2>
@php
    $attributionLabels = [
        'human' => 'Solo umano',
        'ai_assisted' => 'AI-assistito',
        'ai_authored' => 'AI-autorato',
        'mixed' => 'Misto',
    ];
    $aiAttribution = (array) ($summary['ai_attribution'] ?? []);
@endphp
<table>
    <thead>
    <tr>
        <th>Categoria</th>
        <th>Frazione</th>
    </tr>
    </thead>
    <tbody>
    @foreach($attributionLabels as $key => $label)
        @php $value = (float) ($aiAttribution[$key] ?? 0); @endphp
        <tr>
            <td>{{ $label }}</td>
            <td>{{ number_format($value * 100, 1, ',', '.') }} %</td>
        </tr>
    @endforeach
    </tbody>
</table>
<p class="small">
    L'attribuzione AI è ricavata dai trailer Co-Authored-By e dal
    pattern dell'email del committer (vedi AiAttributionExtractor).
    I commit privi di trailer sono classificati come «solo umano».
</p>
