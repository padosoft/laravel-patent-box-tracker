<h2>Sintesi esecutiva</h2>
<table>
    <thead>
    <tr>
        <th>Indicatore</th>
        <th>Valore</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td>Ore qualificate stimate</td>
        <td>{{ number_format((float) ($summary['total_qualified_hours_estimate'] ?? 0), 2, ',', '.') }}</td>
    </tr>
    <tr>
        <td>Costo qualificato totale (EUR)</td>
        <td>€ {{ number_format((float) ($summary['total_qualified_cost_eur'] ?? 0), 2, ',', '.') }}</td>
    </tr>
    <tr>
        <td>Super-deduzione 110% (EUR)</td>
        <td>€ {{ number_format(((float) ($summary['total_qualified_cost_eur'] ?? 0)) * 1.10, 2, ',', '.') }}</td>
    </tr>
    </tbody>
</table>
<p class="small">
    Le ore qualificate sono stimate con l'algoritmo proxy v0.1
    (1 ora per commit qualificato); l'allocazione finale verrà
    raffinata da BranchSemanticsCollector + cadence model in W4.D.
</p>
