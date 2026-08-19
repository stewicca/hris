@php
    $employee = $salary->employee;
    $rupiah = fn (int $amount): string => 'Rp '.number_format($amount, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Slip Gaji {{ $employee->name }} - {{ $salary->period_label }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; background: #f3f4f6; padding: 24px; font-size: 13px; }
        .sheet { max-width: 720px; margin: 0 auto; background: #fff; padding: 40px; border: 1px solid #e5e7eb; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111827; padding-bottom: 16px; margin-bottom: 20px; }
        .company { font-size: 18px; font-weight: 700; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 16px; text-transform: uppercase; letter-spacing: 1px; }
        .doc-title p { color: #6b7280; margin-top: 2px; }
        .meta { display: flex; gap: 40px; margin-bottom: 24px; }
        .meta dl { flex: 1; }
        .meta dt { color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; margin-top: 8px; }
        .meta dd { font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; border-bottom: 1px solid #e5e7eb; padding: 8px 0; }
        th.amount, td.amount { text-align: right; font-variant-numeric: tabular-nums; }
        td { padding: 6px 0; border-bottom: 1px solid #f3f4f6; }
        .section-label { font-weight: 700; padding-top: 14px; }
        .subtotal td { font-weight: 600; border-top: 1px solid #e5e7eb; }
        .net { display: flex; justify-content: space-between; align-items: center; margin-top: 24px; padding: 16px 20px; background: #111827; color: #fff; border-radius: 8px; }
        .net span:last-child { font-size: 20px; font-weight: 700; }
        .status { display: inline-block; padding: 2px 10px; border-radius: 99px; font-size: 11px; font-weight: 600; }
        .status.paid { background: #d1fae5; color: #065f46; }
        .status.pending { background: #fef3c7; color: #92400e; }
        .foot { margin-top: 32px; color: #9ca3af; font-size: 11px; text-align: center; }
        .toolbar { max-width: 720px; margin: 0 auto 16px; text-align: right; }
        .btn { background: #111827; color: #fff; border: 0; padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { border: 0; padding: 0; max-width: none; }
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Cetak / Simpan PDF</button>
    </div>
    <div class="sheet">
        <div class="head">
            <div>
                <div class="company">{{ config('app.name') }}</div>
                <p style="color:#6b7280">Slip Gaji Karyawan</p>
            </div>
            <div class="doc-title">
                <h1>Slip Gaji</h1>
                <p>Periode {{ $salary->period_label }}</p>
            </div>
        </div>

        <div class="meta">
            <dl>
                <dt>Nama Karyawan</dt>
                <dd>{{ $employee->name }}</dd>
                <dt>Nomor Karyawan</dt>
                <dd>{{ $employee->employee_number }}</dd>
            </dl>
            <dl>
                <dt>Departemen</dt>
                <dd>{{ $employee->department?->name ?? '-' }}</dd>
                <dt>Jabatan</dt>
                <dd>{{ $employee->position?->name ?? '-' }}</dd>
            </dl>
            <dl>
                <dt>Status</dt>
                <dd><span class="status {{ $salary->status }}">{{ $salary->status === 'paid' ? 'Sudah Dibayar' : 'Menunggu' }}</span></dd>
                @if ($salary->paid_at)
                    <dt>Tanggal Bayar</dt>
                    <dd>{{ $salary->paid_at->locale('id')->translatedFormat('d M Y') }}</dd>
                @endif
            </dl>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Komponen</th>
                    <th class="amount">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <tr><td class="section-label" colspan="2">Pendapatan</td></tr>
                @foreach (collect($salary->components)->where('type', 'income') as $c)
                    <tr>
                        <td>{{ $c['label'] }}</td>
                        <td class="amount">{{ $rupiah((int) $c['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal"><td>Total Pendapatan</td><td class="amount">{{ $rupiah($salary->gross) }}</td></tr>

                @if (collect($salary->components)->where('type', 'deduction')->isNotEmpty())
                    <tr><td class="section-label" colspan="2">Potongan</td></tr>
                    @foreach (collect($salary->components)->where('type', 'deduction') as $c)
                        <tr>
                            <td>{{ $c['label'] }}</td>
                            <td class="amount">-{{ $rupiah((int) $c['amount']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="subtotal"><td>Total Potongan</td><td class="amount">-{{ $rupiah($salary->deductions) }}</td></tr>
                @endif
            </tbody>
        </table>

        <div class="net">
            <span>Gaji Bersih</span>
            <span>{{ $rupiah($salary->net) }}</span>
        </div>

        <p class="foot">Dokumen ini dihasilkan secara otomatis oleh sistem {{ config('app.name') }} pada {{ now()->locale('id')->translatedFormat('d M Y H:i') }}.</p>
    </div>
</body>
</html>
