<h2>Beni immateriali oggetto del Patent Box</h2>
@if(empty($ipOutputs))
    <p class="small">
        Nessun bene immateriale dichiarato per la sessione. La sezione
        sarà popolata dal blocco <code>ip_outputs</code> della
        configurazione cross-repo (W4.D) o tramite il
        <code>tax_identity_json.ip_outputs</code> della sessione.
    </p>
@else
    <table>
        <thead>
        <tr>
            <th>Tipo</th>
            <th>Titolo</th>
            <th>Identificativo</th>
            <th>Data deposito</th>
        </tr>
        </thead>
        <tbody>
        @foreach($ipOutputs as $ip)
            <tr>
                <td><span class="pill">{{ $ip['kind'] ?? '—' }}</span></td>
                <td>{{ $ip['title'] ?? '—' }}</td>
                <td>{{ $ip['registration_id'] ?? ($ip['application_id'] ?? '—') }}</td>
                <td>{{ $ip['filing_date'] ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
