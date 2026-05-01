<h2>Inventario repository</h2>
@if(empty($repositories))
    <p class="small">Nessun repository registrato per la sessione corrente.</p>
@else
    <table>
        <thead>
        <tr>
            <th>Path</th>
            <th>Ruolo</th>
            <th>Commit totali</th>
            <th>Commit qualificati</th>
            <th>Autori</th>
        </tr>
        </thead>
        <tbody>
        @foreach($repositories as $repo)
            <tr>
                <td>{{ $repo['path'] ?? '—' }}</td>
                <td><span class="pill">{{ $repo['role'] ?? '—' }}</span></td>
                <td>{{ (int) ($repo['commit_count'] ?? 0) }}</td>
                <td>{{ (int) ($repo['qualified_commit_count'] ?? 0) }}</td>
                <td>{{ (int) ($repo['author_count'] ?? 0) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
