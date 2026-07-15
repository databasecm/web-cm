@php
    /** @var \App\Models\Payslip $payslip */
    $employee = $payslip->employee;
    $payroll = $payslip->payroll;

    $rupiah = fn ($v): string => 'Rp '.number_format((float) $v, 2, ',', '.');
    $logoExists = $company['logo_path'] && is_file($company['logo_path']);

    $slipNo = 'SLIP-'.str_pad((string) $payslip->id, 6, '0', STR_PAD_LEFT);
    $periode = $payroll
        ? $payroll->period_start->format('d/m/Y').' — '.$payroll->period_end->format('d/m/Y')
        : '—';
    $statusLabel = $payroll?->status->label() ?? '—';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1a1a1a; margin: 0; }
        .kop { border-bottom: 3px double #444; padding-bottom: 8px; margin-bottom: 14px; }
        .kop .name { font-size: 20px; font-weight: bold; letter-spacing: 1px; }
        .kop .tagline { font-size: 11px; color: #555; }
        .kop .contact { font-size: 10px; color: #555; margin-top: 2px; }
        .doc-title { text-align: center; font-size: 14px; font-weight: bold; text-transform: uppercase;
            margin: 6px 0 14px; letter-spacing: 1px; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 2px 4px; vertical-align: top; }
        .meta .label { width: 140px; color: #555; }
        .lines { margin-top: 12px; }
        .lines th, .lines td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        .lines th { background: #f2f2f2; }
        .lines td.num { text-align: right; }
        .amount-box { margin-top: 14px; border: 2px solid #444; padding: 10px 14px; width: 60%; }
        .amount-box .label { font-size: 10px; color: #555; text-transform: uppercase; }
        .amount-box .value { font-size: 20px; font-weight: bold; }
        .footer { padding-top: 40px; font-size: 10px; color: #555; }
    </style>
</head>
<body>
    <div class="kop">
        @if ($logoExists)
            <img src="{{ $company['logo_path'] }}" alt="logo" style="height: 48px; float: left; margin-right: 12px;">
        @endif
        <div class="name">{{ $company['name'] }}</div>
        @if ($company['tagline'])<div class="tagline">{{ $company['tagline'] }}</div>@endif
        <div class="contact">
            {{ $company['address'] }}@if ($company['phone']) · Telp {{ $company['phone'] }}@endif@if ($company['email']) · {{ $company['email'] }}@endif
        </div>
    </div>

    <div class="doc-title">Slip Gaji</div>

    <table class="meta">
        <tr><td class="label">No. Slip</td><td>: {{ $slipNo }}</td></tr>
        <tr><td class="label">Nama Karyawan</td><td>: {{ $employee?->name ?? '—' }}</td></tr>
        <tr><td class="label">Jabatan</td><td>: {{ $employee?->position ?? '—' }}</td></tr>
        <tr><td class="label">Periode</td><td>: {{ $periode }}</td></tr>
        <tr><td class="label">Status Payroll</td><td>: {{ $statusLabel }}</td></tr>
    </table>

    <table class="lines">
        <thead>
            <tr><th>Komponen</th><th style="text-align:right">Nilai</th></tr>
        </thead>
        <tbody>
            <tr><td>Hari Hadir</td><td class="num">{{ $payslip->days_present }} hari</td></tr>
            <tr><td>Upah Harian</td><td class="num">{{ $rupiah($payslip->daily_wage) }}</td></tr>
            <tr><td>Bruto (hadir × upah)</td><td class="num">{{ $rupiah($payslip->gross) }}</td></tr>
            <tr><td>Potongan</td><td class="num">{{ $rupiah($payslip->deductions) }}</td></tr>
        </tbody>
    </table>

    <div class="amount-box">
        <div class="label">Gaji Bersih (Net)</div>
        <div class="value">{{ $rupiah($payslip->net) }}</div>
    </div>

    <div class="footer">
        Dokumen ini dihasilkan otomatis oleh sistem {{ $company['name'] }}.
    </div>
</body>
</html>
