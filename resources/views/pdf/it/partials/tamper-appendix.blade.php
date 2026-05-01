<h2>Appendice tamper-evidence (catena hash)</h2>
<p class="small">
    Ogni commit della sessione è incatenato con il precedente
    tramite SHA-256 nella forma <code>self = sha256(prev || ':' || sha)</code>.
    Una manomissione di una riga interrompe la catena al punto esatto
    dell'alterazione e rende invalida la testa <code>head</code> di
    seguito riportata. La testa è il digest dell'ultimo nodo, quindi
    firma implicitamente l'intero contenuto del dossier.
</p>
<table>
    <thead>
    <tr>
        <th>#</th>
        <th>Commit SHA</th>
        <th>Hash precedente</th>
        <th>Hash corrente</th>
    </tr>
    </thead>
    <tbody>
    @php $manifest = (array) ($hashChain['manifest'] ?? []); @endphp
    @foreach($manifest as $i => $row)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td><span class="hash">{{ $row['sha'] ?? '—' }}</span></td>
            <td><span class="hash">{{ $row['prev'] ?? 'GENESIS' }}</span></td>
            <td><span class="hash">{{ $row['self'] ?? '—' }}</span></td>
        </tr>
    @endforeach
    </tbody>
</table>
<p>
    <strong>Hash testa (head):</strong> <span class="hash">{{ $hashHead }}</span>
</p>
