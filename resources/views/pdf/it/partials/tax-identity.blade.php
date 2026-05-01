<h2>Identificazione contribuente e regime</h2>
<table class="meta-table">
    <tr>
        <th>Denominazione</th>
        <td>{{ $taxIdentity['denomination'] ?? '—' }}</td>
    </tr>
    <tr>
        <th>Partita IVA</th>
        <td>{{ $taxIdentity['p_iva'] ?? '—' }}</td>
    </tr>
    <tr>
        <th>Anno fiscale</th>
        <td>{{ $taxIdentity['fiscal_year'] ?? '—' }}</td>
    </tr>
    <tr>
        <th>Regime</th>
        <td>{{ $taxIdentity['regime'] ?? '—' }}</td>
    </tr>
    <tr>
        <th>Periodo di riferimento</th>
        <td>
            dal {{ $reportingPeriod['from'] ?? '—' }}
            al {{ $reportingPeriod['to'] ?? '—' }}
        </td>
    </tr>
    <tr>
        <th>Generato il</th>
        <td>{{ $generatedAt }}</td>
    </tr>
    <tr>
        <th>Hash dossier (head)</th>
        <td><span class="hash">{{ $hashHead }}</span></td>
    </tr>
</table>
