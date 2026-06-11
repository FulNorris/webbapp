<!doctype html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <title>{{ $documentType }}</title>
    <style>
        @page {
            size: A4;
            margin: 14mm 12mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 10px;
            line-height: 1.35;
            color: #111827;
            background: #ffffff;
        }

        h1,
        h2,
        h3,
        p {
            margin: 0;
            padding: 0;
        }

        .document-header {
            display: table;
            width: 100%;
            padding-bottom: 10px;
            border-bottom: 2px solid #111827;
        }

        .brand,
        .document-meta {
            display: table-cell;
            vertical-align: top;
        }

        .brand {
            width: 42%;
        }

        .document-title {
            color: #d1d5db;
            font-size: 34px;
            font-weight: 800;
            letter-spacing: 1px;
            line-height: 1;
        }

        .document-type {
            margin-top: 6px;
            color: #111827;
            font-size: 15px;
            font-weight: 800;
        }

        .document-meta {
            width: 58%;
            text-align: right;
            font-size: 9px;
        }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta-table th,
        .meta-table td {
            padding: 2px 0 2px 8px;
            vertical-align: top;
            text-align: right;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .meta-table th {
            width: 34%;
            color: #6b7280;
            font-weight: 700;
        }

        .section {
            margin-top: 16px;
            page-break-inside: avoid;
        }

        .section-title {
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 800;
            color: #111827;
        }

        .summary-grid {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .summary-grid td {
            width: 16.66%;
            border: 1px solid #d1d5db;
            padding: 8px;
            vertical-align: top;
            background: #f9fafb;
        }

        .summary-label {
            display: block;
            color: #6b7280;
            font-size: 8.5px;
            font-weight: 700;
        }

        .summary-value {
            display: block;
            margin-top: 3px;
            color: #111827;
            font-size: 16px;
            font-weight: 800;
        }

        .workorder-card {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            margin-top: 12px;
            padding: 10px;
            page-break-inside: avoid;
        }

        .workorder-card.without-workorder {
            border-color: #f59e0b;
            background: #fffbeb;
        }

        .workorder-header {
            display: table;
            width: 100%;
            margin-bottom: 8px;
        }

        .workorder-heading,
        .workorder-facts {
            display: table-cell;
            vertical-align: top;
        }

        .workorder-heading {
            width: 42%;
        }

        .workorder-heading h3 {
            font-size: 13px;
            font-weight: 800;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .workorder-heading span {
            display: block;
            margin-top: 2px;
            color: #6b7280;
            font-size: 9px;
        }

        .workorder-facts {
            width: 58%;
        }

        .facts-table {
            width: 100%;
            border-collapse: collapse;
        }

        .facts-table th,
        .facts-table td {
            padding: 2px 0 2px 8px;
            vertical-align: top;
            text-align: left;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .facts-table th {
            width: 28%;
            color: #6b7280;
            font-weight: 700;
        }

        .pdf-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            table-layout: fixed;
            page-break-inside: auto;
        }

        .pdf-table thead {
            display: table-header-group;
        }

        .pdf-table tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }

        .pdf-table th {
            border: 1px solid #d1d5db;
            padding: 5px;
            background: #f3f4f6;
            color: #111827;
            font-size: 8px;
            font-weight: 800;
            text-align: left;
            vertical-align: top;
        }

        .pdf-table td {
            border: 1px solid #e5e7eb;
            padding: 5px;
            vertical-align: top;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .col-article { width: 20%; }
        .col-unit { width: 6%; }
        .col-number { width: 7%; }
        .col-status { width: 10%; }
        .col-recipient { width: 17%; }
        .col-created { width: 15%; }

        .warning-row td {
            color: #92400e;
            background: #fffbeb;
            font-size: 8.5px;
        }

        .linked-purchases {
            margin-top: 10px;
            page-break-inside: avoid;
        }

        .linked-purchases h4 {
            margin: 0 0 6px;
            color: #0f766e;
            font-size: 10px;
            font-weight: 800;
        }

        .purchase-pdf-table th {
            background: #ecfdf5;
        }

        .col-purchase-item { width: 22%; }
        .col-purchase-store { width: 16%; }
        .col-purchase-price { width: 10%; }
        .col-purchase-stock { width: 17%; }

        .empty-state {
            border: 1px solid #d1d5db;
            padding: 16px;
            border-radius: 6px;
            background: #f9fafb;
            color: #374151;
        }
    </style>
</head>
<body>
    <header class="document-header">
        <div class="brand">
            <h1 class="document-title">{{ $title }}</h1>
            <p class="document-type">{{ $documentType }}</p>
        </div>
        <div class="document-meta">
            <table class="meta-table">
                <tbody>
                    <tr>
                        <th>PDF skapad</th>
                        <td>{{ $createdAt }}</td>
                    </tr>
                    <tr>
                        <th>Skapad av</th>
                        <td>{{ $createdBy }}</td>
                    </tr>
                    <tr>
                        <th>Urval</th>
                        <td>{{ $selection }}</td>
                    </tr>
                    <tr>
                        <th>Vy</th>
                        <td>{{ $viewLabel }}</td>
                    </tr>
                    <tr>
                        <th>Period</th>
                        <td>{{ $periodLabel }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </header>

    <section class="section">
        <h2 class="section-title">Sammanfattning</h2>
        <table class="summary-grid">
            <tbody>
                <tr>
                    <td>
                        <span class="summary-label">Arbetsordrar</span>
                        <span class="summary-value">{{ $summary['workOrders'] }}</span>
                    </td>
                    <td>
                        <span class="summary-label">Leveranser</span>
                        <span class="summary-value">{{ $summary['deliveries'] }}</span>
                    </td>
                    <td>
                        <span class="summary-label">Levererade</span>
                        <span class="summary-value">{{ $summary['delivered'] }}</span>
                    </td>
                    <td>
                        <span class="summary-label">Packade</span>
                        <span class="summary-value">{{ $summary['packed'] }}</span>
                    </td>
                    <td>
                        <span class="summary-label">Utan arbetsorder</span>
                        <span class="summary-value">{{ $summary['withoutWorkOrder'] }}</span>
                    </td>
                    <td>
                        <span class="summary-label">Kopplade inköp</span>
                        <span class="summary-value">{{ $summary['purchases'] ?? 0 }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    @if (! $hasRows)
        <section class="section empty-state">
            Inga leveranser matchar valt urval.
        </section>
    @endif

    @foreach ($sections as $section)
        <section class="workorder-card {{ $section['withoutWorkOrder'] ? 'without-workorder' : '' }}">
            <div class="workorder-header">
                <div class="workorder-heading">
                    <h3>{{ $section['workOrderNumber'] }}</h3>
                    <span>{{ $section['deliveryCount'] }} leverans{{ $section['deliveryCount'] === 1 ? '' : 'er' }}</span>
                </div>
                <div class="workorder-facts">
                    <table class="facts-table">
                        <tbody>
                            <tr>
                                <th>Mottagare</th>
                                <td>{{ $section['recipient'] }}</td>
                            </tr>
                            <tr>
                                <th>Status</th>
                                <td>{{ $section['status'] }}</td>
                            </tr>
                            <tr>
                                <th>Skapad av</th>
                                <td>{{ $section['createdBy'] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <table class="pdf-table">
                <thead>
                    <tr>
                        <th class="col-article">Artikel</th>
                        <th class="col-unit">Enhet</th>
                        <th class="col-number">Beställt</th>
                        <th class="col-number">Ilt</th>
                        <th class="col-number">Denna</th>
                        <th class="col-number">Totalt</th>
                        <th class="col-number">Kvar</th>
                        <th class="col-status">Status</th>
                        <th class="col-recipient">Mottagare</th>
                        <th class="col-created">Skapad av</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['items'] as $item)
                        <tr>
                            <td>{{ $item['article'] }}</td>
                            <td>{{ $item['unit'] }}</td>
                            <td>{{ $item['ordered'] }}</td>
                            <td>{{ $item['previous'] }}</td>
                            <td>{{ $item['current'] }}</td>
                            <td>{{ $item['total'] }}</td>
                            <td>{{ $item['remaining'] }}</td>
                            <td>{{ $item['status'] }}</td>
                            <td>{{ $item['recipient'] }}</td>
                            <td>{{ $item['createdBy'] }}</td>
                        </tr>
                        @if ($item['warning'] !== '')
                            <tr class="warning-row">
                                <td colspan="10">Varning: {{ $item['warning'] }}</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>

            @if (! empty($section['purchases']))
                <div class="linked-purchases">
                    <h4>Inköp kopplade till arbetsorder {{ $section['workOrderNumber'] }}</h4>
                    <table class="pdf-table purchase-pdf-table">
                        <thead>
                            <tr>
                                <th class="col-purchase-item">Artikel</th>
                                <th class="col-unit">Antal</th>
                                <th class="col-purchase-store">Butik</th>
                                <th class="col-purchase-price">Pris exkl.</th>
                                <th class="col-purchase-price">Totalt inkl.</th>
                                <th class="col-purchase-stock">Lagerstatus</th>
                                <th class="col-recipient">Mottagare</th>
                                <th class="col-created">Vald</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($section['purchases'] as $purchase)
                                <tr>
                                    <td>{{ $purchase['item'] }}</td>
                                    <td>{{ $purchase['quantity'] }}</td>
                                    <td>{{ $purchase['store'] }}</td>
                                    <td>{{ $purchase['price'] }}</td>
                                    <td>{{ $purchase['gross'] }}</td>
                                    <td>{{ $purchase['availability'] }}</td>
                                    <td>{{ $purchase['recipient'] }}</td>
                                    <td>{{ $purchase['selectedAt'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endforeach
</body>
</html>
