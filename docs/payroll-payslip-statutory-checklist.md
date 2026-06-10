# Payroll Payslip Statutory Checklist (Malaysia-Oriented)

Last updated: 2026-04-23

## Included In Current PDF

- Employer name
- Employer registration number (from `PAYROLL_EMPLOYER_REG_NO`)
- Employee name
- Employee identifier
- Wage period label and value
- Wage period start and end date (when period value is valid `YYYY-MM`)
- Payment date
- Baseline salary, allowances, deductions, net salary
- Adjustment lines and totals
- Overtime lines and approved payout
- Net payable summary
- Currency context (`MYR`)

## Operational Notes

- Payslip payload is frozen into `payroll_claims.payslip_snapshot` for approved claims.
- Payment date is persisted in `payroll_claims.payment_date`.
- PDF generation reads immutable snapshot payload by default.

## Required Org Validation Before Production Sign-Off

- Verify exact legal wording and mandatory fields with internal legal/payroll counsel.
- Confirm retention and recordkeeping policy for generated payslips.
- Confirm advances/recoveries naming conventions satisfy internal policy.
- Confirm employer registration number source-of-truth in environment management.

