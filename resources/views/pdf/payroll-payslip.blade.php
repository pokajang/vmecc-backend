<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip {{ (string) data_get($payslip, 'reference', '-') }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * {
            box-sizing: border-box;
            border-radius: 0 !important;
        }
        body {
            font-family: Helvetica, Arial, sans-serif;
            color: #111827;
            font-size: 10.8px;
            line-height: 1.25;
            margin: 0;
        }
        .payslip { width: 100%; }
        .title {
            font-size: 17px;
            font-weight: 700;
            margin: 0 0 6px;
        }
        .header-note {
            color: #4b5563;
            margin-bottom: 8px;
        }
        .card {
            border: 1.2px solid #b8c5d6;
            background: #ffffff;
            overflow: hidden;
            margin-bottom: 8px;
            page-break-inside: avoid;
        }
        .card-head {
            background: #f8fafc;
            border-bottom: 1px solid #dbe3ef;
            font-weight: 700;
            font-size: 11px;
            padding: 6px 8px;
        }
        .card-body { padding: 6px 8px; }
        .grid {
            display: table;
            width: 100%;
            table-layout: fixed;
        }
        .col {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 6px;
        }
        .col:last-child {
            padding-right: 0;
            padding-left: 6px;
        }
        table.meta, table.data {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        table.data {
            border: 1px solid #dbe3ef;
        }
        .meta td {
            padding: 3px 2px;
            vertical-align: top;
        }
        .meta td:first-child {
            color: #4b5563;
            width: 38%;
        }
        .data th, .data td {
            border: 1px solid #dbe3ef;
            padding: 4px 6px;
            vertical-align: top;
        }
        .data th {
            background: #f8fafc;
            text-align: left;
            font-weight: 700;
        }
        .right { text-align: right; }
        .total {
            background: #eef2f7;
            font-weight: 700;
        }
        .footer {
            margin-top: 6px;
            color: #4b5563;
            font-size: 10px;
            border-top: 1px solid #dbe3ef;
            padding-top: 4px;
        }
    </style>
</head>
<body>
@php
    $safeDate = function ($value, $fallback = '-') {
        $raw = trim((string) $value);
        if ($raw === '') return $fallback;
        try {
            return \Carbon\Carbon::parse($raw)->format('d M Y');
        } catch (\Throwable) {
            return $raw;
        }
    };
    $safeDateTime = function ($value, $fallback = '-') {
        $raw = trim((string) $value);
        if ($raw === '') return $fallback;
        try {
            return \Carbon\Carbon::parse($raw)->format('d M Y, h:i A');
        } catch (\Throwable) {
            return $raw;
        }
    };
    $money = function ($value) { return number_format((float) $value, 2, '.', ','); };
    $normalizeLabel = function ($key) {
        $raw = trim((string) $key);
        if ($raw === '') return '';
        $lower = strtolower($raw);
        return match ($lower) {
            'epf' => 'EPF',
            'perkeso', 'socso', 'perkeso (socso)' => 'PERKESO (SOCSO)',
            'sip', 'eis' => 'SIP',
            default => strtoupper(str_replace(['_', '-'], ' ', $raw)),
        };
    };

    $periodLabel = (string) data_get($payslip, 'period.label', '-');
    $periodValue = (string) data_get($payslip, 'period.value', '-');
    $periodStartDate = (string) data_get($payslip, 'period.startDate', '');
    $periodEndDate = (string) data_get($payslip, 'period.endDate', '');
    $reference = (string) data_get($payslip, 'reference', '-');
    $paymentDate = (string) data_get($payslip, 'paymentDate', '');

    $employeeName = (string) data_get($payslip, 'employeeName', '-');
    $employeeIcNumber = (string) data_get($payslip, 'employeeProfile.icNumber', '-');
    $employeeRoles = collect(data_get($payslip, 'employeeRoles', []))
        ->filter(fn ($entry) => is_string($entry) && trim($entry) !== '')
        ->implode(', ');
    $epfNo = (string) data_get($payslip, 'employeeStatutory.epfNo', '-');
    $perkesoNo = (string) data_get($payslip, 'employeeStatutory.perkesoNo', '-');
    $incomeTaxNo = (string) data_get($payslip, 'employeeStatutory.incomeTaxNo', '-');

    $employerName = trim((string) data_get($payslip, 'employer.name', ''));
    $employerRegNo = trim((string) data_get($payslip, 'employer.registrationNumber', ''));
    $employerMyTaxNo = trim((string) data_get($payslip, 'employer.myTaxNumber', ''));
    $employerEmail = trim((string) data_get($payslip, 'employer.email', ''));
    $employerPhone = trim((string) data_get($payslip, 'employer.phone', ''));
    $financeContactName = trim((string) data_get($payslip, 'employer.financeContactName', ''));
    $financeContactEmail = trim((string) data_get($payslip, 'employer.financeContactEmail', ''));
    $financeContactPhone = trim((string) data_get($payslip, 'employer.financeContactPhone', ''));

    $totals = is_array(data_get($payslip, 'totals')) ? data_get($payslip, 'totals') : [];
    $baselineNetSalary = (float) data_get($totals, 'baselineNetSalary', 0);
    $adjustmentsTotal = (float) data_get($totals, 'adjustmentsTotal', 0);
    $approvedOvertimePayout = (float) data_get($totals, 'approvedOvertimePayout', 0);
    $netPayable = (float) data_get($totals, 'netPayable', 0);

    $baseline = is_array(data_get($payslip, 'baseline')) ? data_get($payslip, 'baseline') : [];
    $employeeContributionsRaw = is_array(data_get($baseline, 'employeeContributions'))
        ? data_get($baseline, 'employeeContributions')
        : [];
    $employerContributionsRaw = is_array(data_get($baseline, 'employerContributions'))
        ? data_get($baseline, 'employerContributions')
        : [];
    $deductionItems = is_array(data_get($baseline, 'deductionItems')) ? data_get($baseline, 'deductionItems') : [];

    $canonical = [];
    foreach ($employeeContributionsRaw as $key => $amount) {
        $normKey = strtolower(trim((string) $key));
        if ($normKey === '') continue;
        $canonical[$normKey] = [
            'label' => $normalizeLabel($key),
            'employee' => round((float) $amount, 2),
            'employer' => 0.0,
        ];
    }
    foreach ($employerContributionsRaw as $key => $amount) {
        $normKey = strtolower(trim((string) $key));
        if ($normKey === '') continue;
        if (! array_key_exists($normKey, $canonical)) {
            $canonical[$normKey] = [
                'label' => $normalizeLabel($key),
                'employee' => 0.0,
                'employer' => 0.0,
            ];
        }
        $canonical[$normKey]['employer'] = round((float) $amount, 2);
    }

    $contributionRows = [];
    if (! empty($canonical)) {
        $priority = ['epf', 'perkeso', 'socso', 'sip', 'eis'];
        $keys = array_keys($canonical);
        usort($keys, function ($a, $b) use ($priority) {
            $aIndex = array_search($a, $priority, true);
            $bIndex = array_search($b, $priority, true);
            $aOrder = $aIndex === false ? 999 : $aIndex;
            $bOrder = $bIndex === false ? 999 : $bIndex;
            if ($aOrder !== $bOrder) return $aOrder <=> $bOrder;
            return strcmp($a, $b);
        });
        foreach ($keys as $key) {
            $contributionRows[] = $canonical[$key];
        }
    } else {
        foreach ($deductionItems as $item) {
            if (! is_array($item)) continue;
            $label = trim((string) ($item['label'] ?? 'Item'));
            if ($label === '') $label = 'Item';
            $contributionRows[] = [
                'label' => $label,
                'employee' => round((float) ($item['amount'] ?? 0), 2),
                'employer' => null,
            ];
        }
    }

    $totalEmployee = round((float) collect($contributionRows)->sum(fn ($row) => (float) ($row['employee'] ?? 0)), 2);
    $totalEmployer = round((float) collect($contributionRows)->sum(fn ($row) => (float) ($row['employer'] ?? 0)), 2);
    $generatedAt = (string) data_get($payslip, 'generatedAt', now()->toIso8601String());
    $documentTitle = trim($periodLabel) !== '' && trim($periodLabel) !== '-'
        ? "Employee Payslip - {$periodLabel}"
        : 'Employee Payslip';
@endphp

<div class="payslip">
    <div class="title" style="text-align: center;">{{ $documentTitle }}</div>

    <div class="card">
        <div class="card-head">Company Information</div>
        <div class="card-body">
            <div class="grid">
                <div class="col">
                    <table class="meta">
                        <tr><td>Company Name</td><td>{{ $employerName !== '' ? $employerName : '-' }}</td></tr>
                        <tr><td>SSM Number</td><td>{{ $employerRegNo !== '' ? $employerRegNo : '-' }}</td></tr>
                        <tr><td>MYTax Number</td><td>{{ $employerMyTaxNo !== '' ? $employerMyTaxNo : '-' }}</td></tr>
                        <tr><td>Company Email</td><td>{{ $employerEmail !== '' ? $employerEmail : '-' }}</td></tr>
                        <tr><td>Company Phone</td><td>{{ $employerPhone !== '' ? $employerPhone : '-' }}</td></tr>
                    </table>
                </div>
                <div class="col">
                    <table class="meta">
                        <tr><td>Finance Contact</td><td>{{ $financeContactName !== '' ? $financeContactName : '-' }}</td></tr>
                        <tr><td>Finance Email</td><td>{{ $financeContactEmail !== '' ? $financeContactEmail : '-' }}</td></tr>
                        <tr><td>Finance Phone</td><td>{{ $financeContactPhone !== '' ? $financeContactPhone : '-' }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">Employee & Payroll Period</div>
        <div class="card-body">
            <div class="grid">
                <div class="col">
                    <table class="meta">
                        <tr><td>Employee</td><td>{{ $employeeName !== '' ? $employeeName : '-' }}</td></tr>
                        <tr><td>IC Number</td><td>{{ $employeeIcNumber !== '' ? $employeeIcNumber : '-' }}</td></tr>
                        <tr><td>Role</td><td>{{ $employeeRoles !== '' ? $employeeRoles : '-' }}</td></tr>
                        <tr><td>EPF Number</td><td>{{ $epfNo !== '' ? $epfNo : '-' }}</td></tr>
                        <tr><td>PERKESO Number</td><td>{{ $perkesoNo !== '' ? $perkesoNo : '-' }}</td></tr>
                        <tr><td>Income Tax Number</td><td>{{ $incomeTaxNo !== '' ? $incomeTaxNo : '-' }}</td></tr>
                    </table>
                </div>
                <div class="col">
                    <table class="meta">
                        <tr><td>Reference</td><td>{{ $reference }}</td></tr>
                        <tr><td>Payroll Month</td><td>{{ $periodLabel }} ({{ $periodValue }})</td></tr>
                        <tr><td>Wage Period</td><td>{{ $safeDate($periodStartDate) }} to {{ $safeDate($periodEndDate) }}</td></tr>
                        <tr><td>Payment Date</td><td>{{ $safeDate($paymentDate) }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">Payroll Totals</div>
        <div class="card-body">
            <table class="data">
                <tr><th>Component</th><th class="right">Amount (MYR)</th></tr>
                <tr><td>Baseline Net</td><td class="right">{{ $money($baselineNetSalary) }}</td></tr>
                <tr><td>Adjustments Total</td><td class="right">{{ $money($adjustmentsTotal) }}</td></tr>
                <tr><td>Overtime Payout</td><td class="right">{{ $money($approvedOvertimePayout) }}</td></tr>
                <tr class="total"><td>Net Payable</td><td class="right">{{ $money($netPayable) }}</td></tr>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head">Statutory Contributions</div>
        <div class="card-body">
            <table class="data">
                <tr><th>Item</th><th class="right">Employee (MYR)</th><th class="right">Employer (MYR)</th></tr>
                @forelse ($contributionRows as $row)
                    <tr>
                        <td>{{ (string) ($row['label'] ?? '-') }}</td>
                        <td class="right">{{ $money((float) ($row['employee'] ?? 0)) }}</td>
                        <td class="right">
                            @if ($row['employer'] === null)
                                -
                            @else
                                {{ $money((float) ($row['employer'] ?? 0)) }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3">No statutory contribution data.</td></tr>
                @endforelse
                <tr class="total">
                    <td>Total</td>
                    <td class="right">{{ $money($totalEmployee) }}</td>
                    <td class="right">{{ $money($totalEmployer) }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="footer">
        Generated at {{ $safeDateTime($generatedAt) }}. This is a computer-generated document; no signature is required.
    </div>
</div>
</body>
</html>
