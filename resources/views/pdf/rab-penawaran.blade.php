@php
    /** @var \App\Models\Rab $rab */
    $project = $rab->project;
    $konsumen = $project?->konsumen;

    $rupiah = fn ($v): string => 'Rp '.number_format((float) $v, 2, ',', '.');
    $persen = fn ($v): string => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',').'%';
    $logoExists = $company['logo_path'] && is_file($company['logo_path']);
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
        .meta td { padding: 1px 4px; vertical-align: top; }
        .meta .label { width: 110px; color: #555; }
        .items { margin-top: 6px; }
        .items th, .items td { border: 1px solid #999; padding: 4px 6px; }
        .items th { background: #f0f0f0; text-align: left; font-size: 10px; text-transform: uppercase; }
        .items td.num, .items th.num { text-align: right; }
        .items td.ctr, .items th.ctr { text-align: center; }
        .summary { margin-top: 10px; width: 55%; float: right; }
        .summary td { padding: 3px 6px; }
        .summary td.num { text-align: right; }
        .summary tr.grand td { border-top: 2px solid #444; font-weight: bold; font-size: 13px; }
        .footer { clear: both; padding-top: 40px; font-size: 10px; color: #555; }
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

    <div class="doc-title">Penawaran — Rencana Anggaran Biaya</div>

    <table class="meta">
        <tr>
            <td class="label">Proyek</td><td><strong>{{ $project?->title ?? '—' }}</strong></td>
            <td class="label">No. RAB</td><td>RAB-{{ str_pad((string) $rab->id, 5, '0', STR_PAD_LEFT) }} / v{{ $rab->version }}</td>
        </tr>
        <tr>
            <td class="label">Bidang</td><td>{{ $project?->bidang?->label() ?? '—' }}</td>
            <td class="label">Tanggal</td><td>{{ $rab->updated_at?->format('d/m/Y') ?? now()->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="label">Konsumen</td><td>{{ $konsumen?->name ?? '—' }}</td>
            <td class="label">Telepon</td><td>{{ $konsumen?->phone ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Email</td><td>{{ $konsumen?->email ?? '—' }}</td>
            <td class="label">Status</td><td>{{ ucfirst($rab->status->value) }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th class="ctr" style="width: 28px;">No</th>
                <th>Uraian Pekerjaan</th>
                <th class="ctr" style="width: 50px;">Satuan</th>
                <th class="num" style="width: 70px;">Volume</th>
                <th class="num" style="width: 110px;">Harga Satuan</th>
                <th class="num" style="width: 120px;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rab->items as $i => $item)
                <tr>
                    <td class="ctr">{{ $i + 1 }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="ctr">{{ $item->unit }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->volume, 4, ',', '.'), '0'), ',') }}</td>
                    <td class="num">{{ $rupiah($item->unit_price) }}</td>
                    <td class="num">{{ $rupiah($item->subtotal) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="ctr">Tidak ada item.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary">
        <tr><td>Total Material</td><td class="num">{{ $rupiah($rab->total_material) }}</td></tr>
        <tr><td>Total Upah</td><td class="num">{{ $rupiah($rab->total_upah) }}</td></tr>
        <tr><td>Overhead ({{ $persen($rab->overhead_percent) }})</td><td class="num">{{ $rupiah($rab->overhead) }}</td></tr>
        <tr><td>Margin ({{ $persen($rab->margin_percent) }})</td><td class="num">{{ $rupiah($rab->margin) }}</td></tr>
        <tr><td>PPN ({{ $persen($rab->ppn_percent) }})</td><td class="num">{{ $rupiah($rab->ppn) }}</td></tr>
        <tr class="grand"><td>Grand Total</td><td class="num">{{ $rupiah($rab->grand_total) }}</td></tr>
    </table>

    <div class="footer">
        Dokumen ini dihasilkan otomatis oleh sistem {{ $company['name'] }}. Angka mengacu pada RAB yang telah ditetapkan (snapshot).
    </div>
</body>
</html>
