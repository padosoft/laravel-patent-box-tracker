<h2>Dettaglio commit qualificati</h2>
@if(empty($commits))
    <p class="small">Nessun commit registrato per la sessione corrente.</p>
@else
    <table>
        <thead>
        <tr>
            <th>SHA</th>
            <th>Data</th>
            <th>Autore</th>
            <th>Fase</th>
            <th>Qualificato</th>
            <th>Conf.</th>
            <th>Motivazione</th>
        </tr>
        </thead>
        <tbody>
        @foreach($commits as $commit)
            @php
                $shaShort = substr((string) ($commit['sha'] ?? ''), 0, 12);
                $rationale = (string) ($commit['rationale'] ?? '');
                if (mb_strlen($rationale) > 200) {
                    $rationale = mb_substr($rationale, 0, 197) . '...';
                }
                $isQualified = (bool) ($commit['is_rd_qualified'] ?? false);
                $confidence = $commit['rd_qualification_confidence'];
            @endphp
            <tr>
                <td><span class="hash">{{ $shaShort }}</span></td>
                <td>{{ $commit['committed_at'] ?? '—' }}</td>
                <td>{{ $commit['author_name'] ?? '—' }}</td>
                <td>{{ $commit['phase'] ?? '—' }}</td>
                <td>
                    @if($isQualified)
                        <span class="qualified-yes">Sì</span>
                    @else
                        <span class="qualified-no">No</span>
                    @endif
                </td>
                <td>
                    @if($confidence !== null)
                        {{ number_format((float) $confidence, 2, ',', '.') }}
                    @else
                        —
                    @endif
                </td>
                <td>{{ $rationale }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
