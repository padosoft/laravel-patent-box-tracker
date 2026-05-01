{{--
    Italian Patent Box dossier — A4 portrait template (it locale).

    Layout convention:
      - 10–11pt body, 14pt H1, 12pt H2 — A4-friendly, fits ~50 lines/page.
      - All fonts are stack-only (DejaVu Sans / Helvetica) so DomPDF
        can render without internet access.
      - Tables use thin borders for fiscal-style readability.
      - Footer present on every page (browser/CSS-driven; DomPDF
        falls back to a single bottom block).

    Per-section partials live under partials/ so future locales can
    override one section without re-doing the whole template.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Dossier Patent Box {{ $taxIdentity['fiscal_year'] ?? '' }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 18mm 14mm 22mm 14mm;
        }
        body {
            font-family: "DejaVu Sans", Helvetica, Arial, sans-serif;
            font-size: 10pt;
            color: #111;
            line-height: 1.35;
        }
        h1 {
            font-size: 14pt;
            margin: 0 0 6pt 0;
        }
        h2 {
            font-size: 12pt;
            margin: 18pt 0 6pt 0;
            border-bottom: 1px solid #444;
            padding-bottom: 3pt;
        }
        h3 {
            font-size: 11pt;
            margin: 12pt 0 4pt 0;
        }
        p {
            margin: 0 0 6pt 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 4pt 0 8pt 0;
            font-size: 9pt;
        }
        th, td {
            border: 1px solid #888;
            padding: 4pt 5pt;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #eee;
            font-weight: bold;
        }
        .meta-table th {
            width: 30%;
        }
        .small {
            font-size: 8.5pt;
            color: #555;
        }
        .footer-block {
            font-size: 8pt;
            color: #555;
            margin-top: 16pt;
            border-top: 1px solid #aaa;
            padding-top: 4pt;
        }
        .hash {
            font-family: "DejaVu Sans Mono", "Courier New", monospace;
            font-size: 8pt;
            word-break: break-all;
        }
        .pill {
            display: inline-block;
            padding: 1pt 5pt;
            border-radius: 7pt;
            background: #eef;
            border: 1px solid #99c;
            font-size: 8.5pt;
        }
        .qualified-yes { color: #1d6f33; font-weight: bold; }
        .qualified-no  { color: #8a1f1f; }
    </style>
</head>
<body>

@include('patent-box-tracker::pdf.it.partials.header')

@include('patent-box-tracker::pdf.it.partials.tax-identity', [
    'taxIdentity' => $taxIdentity,
    'reportingPeriod' => $reportingPeriod,
    'generatedAt' => $generatedAt,
    'hashHead' => $hashHead,
])

@include('patent-box-tracker::pdf.it.partials.executive-summary', [
    'summary' => $summary,
    'taxIdentity' => $taxIdentity,
])

@include('patent-box-tracker::pdf.it.partials.phase-breakdown', [
    'summary' => $summary,
])

@include('patent-box-tracker::pdf.it.partials.repository-inventory', [
    'repositories' => $repositories,
])

@include('patent-box-tracker::pdf.it.partials.commit-evidence', [
    'commits' => $commits,
])

@include('patent-box-tracker::pdf.it.partials.evidence-trail', [
    'evidenceLinks' => $evidenceLinks,
])

@include('patent-box-tracker::pdf.it.partials.ai-attribution', [
    'commits' => $commits,
    'summary' => $summary,
])

@include('patent-box-tracker::pdf.it.partials.ip-outputs', [
    'ipOutputs' => $ipOutputs,
])

@include('patent-box-tracker::pdf.it.partials.tamper-appendix', [
    'hashChain' => $hashChain,
    'hashHead' => $hashHead,
])

<div class="footer-block">
    {{ $taxIdentity['denomination'] ?? '' }}
    — P.IVA {{ $taxIdentity['p_iva'] ?? '' }}
    — Anno fiscale {{ $taxIdentity['fiscal_year'] ?? '' }}
    — Regime «{{ $taxIdentity['regime'] ?? '' }}»
    — Hash dossier: <span class="hash">{{ $hashHead }}</span>
</div>

</body>
</html>
