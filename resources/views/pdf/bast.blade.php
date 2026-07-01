@php
    /** @var \App\Models\Bast $bast */
    $project = $bast->project;
    $konsumen = $project?->konsumen;
    $logoExists = $company['logo_path'] && is_file($company['logo_path']);

    $bastNo = 'BAST-'.str_pad((string) $bast->id, 6, '0', STR_PAD_LEFT);
    $signedAt = $bast->signed_at?->format('d/m/Y H:i') ?? '—';
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
        .doc-title { text-align: center; font-size: 15px; font-weight: bold; text-transform: uppercase;
            margin: 6px 0 4px; letter-spacing: 1px; }
        .doc-no { text-align: center; font-size: 10px; color: #555; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 2px 4px; vertical-align: top; }
        .meta .label { width: 120px; color: #555; }
        .statement { margin: 14px 0; line-height: 1.5; text-align: justify; }
        .status { font-weight: bold; color: #1a7f37; }
        .sign-row { width: 100%; margin-top: 30px; }
        .sign-cell { width: 50%; vertical-align: top; text-align: center; }
        .sign-cell .role { font-size: 10px; color: #555; text-transform: uppercase; }
        .sign-cell .name { margin-top: 46px; font-weight: bold; border-top: 1px solid #444;
            padding-top: 4px; display: inline-block; min-width: 60%; }
        .sign-cell .when { font-size: 9px; color: #777; }
        .footer { padding-top: 30px; font-size: 10px; color: #555; }
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

    <div class="doc-title">Berita Acara Serah Terima</div>
    <div class="doc-no">No. {{ $bastNo }}</div>

    <table class="meta">
        <tr>
            <td class="label">Proyek</td><td><strong>{{ $project?->title ?? '—' }}</strong></td>
            <td class="label">Bidang</td><td>{{ $project?->bidang?->label() ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Konsumen</td><td>{{ $konsumen?->name ?? '—' }}</td>
            <td class="label">Telepon</td><td>{{ $konsumen?->phone ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Status</td><td class="status">{{ $bast->status->label() }}</td>
            <td class="label">Tanggal TTD</td><td>{{ $signedAt }}</td>
        </tr>
    </table>

    <div class="statement">
        Pada hari ini, kedua belah pihak menyatakan bahwa pekerjaan pada proyek
        <strong>{{ $project?->title ?? '—' }}</strong> atas nama <strong>{{ $konsumen?->name ?? '—' }}</strong>
        telah <strong>diserahterimakan</strong> dan diterima dengan baik. Berita acara ini menjadi dasar
        penyelesaian (pelunasan) pembayaran sesuai ketentuan yang berlaku.
    </div>

    <table class="sign-row">
        <tr>
            <td class="sign-cell">
                <div class="role">Konsumen</div>
                <div class="name">{{ $bast->customerSigner?->name ?? $konsumen?->name ?? '(belum)' }}</div>
                <div class="when">Ditandatangani: {{ $bast->signed_customer ? $signedAt : '—' }}</div>
            </td>
            <td class="sign-cell">
                <div class="role">{{ $company['name'] }}</div>
                <div class="name">{{ $bast->companySigner?->name ?? '(belum)' }}</div>
                <div class="when">Ditandatangani: {{ $bast->signed_company ? $signedAt : '—' }}</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Dokumen ini dihasilkan otomatis oleh sistem {{ $company['name'] }} dan sah sebagai bukti serah terima digital.
    </div>
</body>
</html>
