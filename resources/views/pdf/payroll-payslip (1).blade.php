<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip {{ (string) data_get($payslip, 'reference', '-') }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #111827;
            font-size: 12px;
            line-height: 1.4;
            margin: 0;
            padding: 24px;
        }
        .header {
            border-bottom: 1px solid #d1d5db;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        h1 {
            font-size: 20px;
            margin: 0 0 4px;
        }
        h2 {
            font-size: 14px;
            margin: 16px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e7eb;
        }
        .meta {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        .meta td {
            padding: 3px 0;
            vertical-align: top;
        }
        .meta td:first-child {
            color: #4b5563;
            width: 130px;
        }
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        table.data th, table.data td {
            border: 1px solid #e5e7eb;
            padding: 6px 8px;
            vertical-align: top;
        }
        table.data th {
            background: #f9fafb;
            text-align: left;
            font-weight: 700;
        }
        .right { text-align: right; }
        .total {
            font-weight: 700;
            background: #f3f4f6;
        }
        .muted {
            color: #6b7280;
            font-size: 11px;
        }
    </style>
</head>
<body>
@php
    $periodLabel = (string) data_get($payslip, 'period.label', '-');
    $periodValue = (string) data_get($payslip, 'period.value', '-');
    $reference = (string) data_get($payslip, 'reference', '-');
    $status = (string) data_get($payslip, 'status', '-');
    $baseline = is_array(data_get($payslip, 'baseline')) ? data_get($payslip, 'baseline') : [];
    $adjustments = is_array(data_get($payslip, 'adjustments')) ? data_get($payslip, 'adjustments') : [];
    $overtimeRows = is_array(data_get($payslip, 'overtime.rows')) ? data_get($payslip, 'overtime.rows') : [];
    $totals = is_array(data_get($payslip, 'totals')) ? data_get($payslip, 'totals') : [];
    $fmt = function ($value) { return number_format((float) $value, 2, '.', ','); };
@endphp

<div class="header">
    <h1>Payroll Payslip</h1>
    <div class="muted">Generated at {{ (string) data_get($payslip, 'generatedAt', now()->toIso8601String()) }}</div>
    <table class="meta">
        <tr><td>Reference</td><td>{{ $reference }}</td></tr>
        <tr><td>Period</td><td>{{ $periodLabel }} ({{ $periodValue }})</td></tr>
        <tr><td>Status</td><td>{{ $status }}</td></tr>
        <tr><td>Issued At</td><td>{{ (string) data_get($payslip, 'issuedAt', '-') }}</td></tr>
        <tr><td>Employee ID</td><td>{{ (string) data_get($payslip, 'employeeId', '-') }}</td></tr>
    </table>
</div>

<h2>Baseline</h2>
<table class="data">
    <tr><th>Component</th><th class="right">Amount</th></tr>
    <tr><td>Basic Salary</td><td class="right">{{ $fmt(data_get($baseline, 'basicSalary', 0)) }}</td></tr>
    <tr><td>Total Allowance</td><td class="right">{{ $fmt(data_get($baseline, 'allowanceTotal', 0)) }}</td></tr>
    <tr><td>Gross Salary</td><td class="right">{{ $fmt(data_get($baseline, 'grossSalary', 0)) }}</td></tr>
    <tr><td>Employee Deductions</td><td class="right">{{ $fmt(data_get($baseline, 'employeeDeductionsTotal', 0)) }}</td></tr>
    <tr class="total"><td>Net Salary</td><td class="right">{{ $fmt(data_get($baseline, 'netSalary', 0)) }}</td></tr>
</table>

<h2>Adjustments</h2>
<table class="data">
    <tr><th>Line</th><th>Title</th><th>Type</th><th class="right">Amount</th></tr>
    @forelse ($adjustments as $row)
        <tr>
            <td>{{ (int) data_get($row, 'lineNo', 0) }}</td>
            <td>{{ (string) data_get($row, 'title', '-') }}</td>
            <td>{{ (string) data_get($row, 'direction', '-') }}</td>
            <td class="right">{{ $fmt(data_get($row, 'signedAmount', 0)) }}</td>
        </tr>
    @empty
        <tr><td colspan="4">No adjustment items.</td></tr>
    @endforelse
    <tr class="total">
        <td colspan="3">Adjustments Total</td>
        <td class="right">{{ $fmt(data_get($totals, 'adjustmentsTotal', 0)) }}</td>
    </tr>
</table>

<h2>Overtime</h2>
<table class="data">
    <tr><th>Date</th><th>Type</th><th class="right">Hours</th><th>Status</th><th class="right">Payout</th></tr>
    @forelse ($overtimeRows as $row)
        <tr>
            <td>{{ (string) data_get($row, 'claimDate', '-') }}</td>
            <td>{{ (string) data_get($row, 'overtimeType', '-') }}</td>
            <td class="right">{{ $fmt(data_get($row, 'hours', 0)) }}</td>
            <td>{{ (string) data_get($row, 'status', '-') }}</td>
            <td class="right">{{ $fmt(data_get($row, 'payoutUsed', 0)) }}</td>
        </tr>
    @empty
        <tr><td colspan="5">No overtime records.</td></tr>
    @endforelse
    <tr class="total">
        <td colspan="4">Approved Overtime Payout</td>
        <td class="right">{{ $fmt(data_get($totals, 'approvedOvertimePayout', 0)) }}</td>
    </tr>
</table>

<h2>Net Payable</h2>
<table class="data">
    <tr><th>Summary</th><th class="right">Amount</th></tr>
    <tr><td>Baseline Net Salary</td><td class="right">{{ $fmt(data_get($totals, 'baselineNetSalary', 0)) }}</td></tr>
    <tr><td>Adjustments Total</td><td class="right">{{ $fmt(data_get($totals, 'adjustmentsTotal', 0)) }}</td></tr>
    <tr><td>Approved Overtime Payout</td><td class="right">{{ $fmt(data_get($totals, 'approvedOvertimePayout', 0)) }}</td></tr>
    <tr class="total"><td>Net Payable</td><td class="right">{{ $fmt(data_get($totals, 'netPayable', 0)) }}</td></tr>
</table>

</body>
</html>
