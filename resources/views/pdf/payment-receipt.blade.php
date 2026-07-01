@php
    /** @var \App\Models\Installment $installment */
    $project = $installment->project;
    $konsumen = $project?->konsumen;

    $rupiah = fn ($v): string => 'Rp '.number_format((float) $v, 2, ',', '.');
    $persen = fn ($v): string => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',').'%';
    $logoExists = $company['logo_path'] && is_file($company['logo_path']);

    $receiptNo = 'KWT-'.str_pad((string) ($transaction->id ?? $installment->id), 6, '0', STR_PAD_LEFT);
    $paidAt = $installment->paid_at?->format('d/m/Y H:i') ?? '—';
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
        .meta .label { width: 120px; color: #555; }
        .amount-box { margin-top: 14px; border: 2px solid #444; padding: 10px 14px; width: 60%; }
        .amount-box .label { font-size: 10px; color: #555; text-transform: uppercase; }
        .amount-box .value { font-size: 20px; font-weight: bold; }
        .lunas { color: #1a7f37; font-weight: bold; }
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

    <div class="doc-title">Kuitansi Pembayaran</div>

    <table class="meta">
        <tr>
            <td class="label">No. Kuitansi</td><td><strong>{{ $receiptNo }}</strong></td>
            <td class="label">Tanggal Bayar</td><td>{{ $paidAt }}</td>
        </tr>
        <tr>
            <td class="label">Proyek</td><td>{{ $project?->title ?? '—' }}</td>
            <td class="label">Bidang</td><td>{{ $project?->bidang?->label() ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Konsumen</td><td>{{ $konsumen?->name ?? '—' }}</td>
            <td class="label">Telepon</td><td>{{ $konsumen?->phone ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Termin</td><td>{{ $installment->label }} ({{ $persen($installment->percentage) }})</td>
            <td class="label">Ref. Bayar</td><td>{{ $installment->va_number ?? $installment->gateway_ref ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Nilai Kontrak</td><td>{{ $rupiah($project?->contract_value ?? 0) }}</td>
            <td class="label">Status</td><td class="lunas">LUNAS</td>
        </tr>
    </table>

    <div class="amount-box">
        <div class="label">Jumlah Dibayar</div>
        <div class="value">{{ $rupiah($installment->amount) }}</div>
    </div>

    <div class="footer">
        Kuitansi ini sah tanpa tanda tangan basah — dihasilkan otomatis oleh sistem {{ $company['name'] }}
        atas pembayaran termin yang telah diterima. Angka mengacu pada catatan pembayaran (buku kas).
    </div>
</body>
</html>
