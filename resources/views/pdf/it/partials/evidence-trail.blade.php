<h2>Evidenze documentali (PLAN/ADR/SPEC)</h2>
@if(empty($evidenceLinks))
    <p class="small">Nessuna evidenza documentale registrata.</p>
@else
    <table>
        <thead>
        <tr>
            <th>Tipo</th>
            <th>Slug</th>
            <th>Titolo</th>
            <th>Path</th>
            <th>Visto la prima volta</th>
            <th>Commit collegati</th>
        </tr>
        </thead>
        <tbody>
        @foreach($evidenceLinks as $evidence)
            <tr>
                <td><span class="pill">{{ $evidence['kind'] ?? '—' }}</span></td>
                <td>{{ $evidence['slug'] ?? '—' }}</td>
                <td>{{ $evidence['title'] ?? '—' }}</td>
                <td class="small">{{ $evidence['path'] ?? '—' }}</td>
                <td>{{ $evidence['first_seen_at'] ?? '—' }}</td>
                <td>{{ (int) ($evidence['linked_commit_count'] ?? 0) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
