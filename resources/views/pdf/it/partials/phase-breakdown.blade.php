<h2>Ripartizione per fase di attività R&amp;S</h2>
@php
    $phaseLabels = [
        'research' => 'Ricerca',
        'design' => 'Progettazione',
        'implementation' => 'Implementazione',
        'validation' => 'Validazione',
        'documentation' => 'Documentazione',
        'non_qualified' => 'Non qualificato',
    ];
    $phases = (array) ($summary['phase_breakdown'] ?? []);
    $totalCommits = array_sum(array_map('intval', $phases));
@endphp
<table>
    <thead>
    <tr>
        <th>Fase</th>
        <th>Commit</th>
        <th>%</th>
    </tr>
    </thead>
    <tbody>
    @foreach($phaseLabels as $key => $label)
        @php
            $count = (int) ($phases[$key] ?? 0);
            $pct = $totalCommits > 0 ? ($count / $totalCommits) * 100 : 0;
        @endphp
        <tr>
            <td>{{ $label }}</td>
            <td>{{ $count }}</td>
            <td>{{ number_format($pct, 1, ',', '.') }} %</td>
        </tr>
    @endforeach
    <tr>
        <td><strong>Totale</strong></td>
        <td><strong>{{ $totalCommits }}</strong></td>
        <td>100,0 %</td>
    </tr>
    </tbody>
</table>
