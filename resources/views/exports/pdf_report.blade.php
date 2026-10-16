<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Laporan Komprehensif - Kaching POS</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            font-size: 12px;
            margin: 0;
            padding: 0;
        }

        .header {
            text-align: center;
            padding-bottom: 15px;
            border-bottom: 2px solid #2563EB;
            margin-bottom: 20px;
        }

        .header h1 {
            color: #2563EB;
            margin: 0;
            font-size: 24px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .header p {
            margin: 5px 0 0 0;
            color: #6B7280;
            font-size: 12px;
        }

        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #1E3A8A;
            margin-top: 30px;
            margin-bottom: 10px;
            text-transform: uppercase;
            border-bottom: 1px solid #E5E7EB;
            padding-bottom: 5px;
        }

        /* Grid Table for Layout */
        .layout-table {
            width: 100%;
            border: none;
            margin-bottom: 20px;
        }

        .layout-table td {
            vertical-align: top;
            padding-right: 20px;
            width: 50%;
        }

        /* Data Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .data-table th {
            background-color: #F3F4F6;
            color: #374151;
            padding: 8px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            border-bottom: 2px solid #D1D5DB;
        }

        .data-table td {
            padding: 8px;
            border-bottom: 1px solid #E5E7EB;
            font-size: 11px;
        }

        .data-table tr:nth-child(even) {
            background-color: #F9FAFB;
        }

        .profit-table th {
            background-color: #2563EB;
            color: white;
            border: none;
        }

        .profit-table .highlight td {
            font-weight: bold;
            font-size: 12px;
        }

        .profit-table .laba-bersih td {
            background-color: #EFF6FF;
            font-weight: bold;
            font-size: 14px;
            color: #1E3A8A;
            border-top: 2px solid #2563EB;
        }

        .text-right {
            text-align: right !important;
        }

        .text-center {
            text-align: center !important;
        }

        .text-danger {
            color: #DC2626;
        }

        .text-success {
            color: #10B981;
        }

        /* Page Break Rule */
        .page-break {
            page-break-before: always;
        }

        .footer-container {
            margin-top: 40px;
            width: 100%;
        }

        .signature-box {
            float: right;
            width: 220px;
            text-align: center;
        }

        .signature-line {
            margin-top: 60px;
            border-bottom: 1px solid #111827;
            padding-bottom: 5px;
            font-weight: bold;
            font-size: 12px;
        }

        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
    </style>
</head>

<body>

    <div class="header">
        <h1>KACHING POS</h1>
        <p>Laporan Analisis Bisnis & Keuangan Komprehensif</p>
        <p style="color: #111827; font-weight: bold; margin-top: 8px;">Periode: {{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }} - {{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}</p>
        <p style="color: #4B5563; font-size: 11px; margin-top: 4px;">Jam Tersibuk: <strong>{{ $peakHourString }}</strong></p>
    </div>

    <table class="layout-table">
        <tr>
            <td>
                <div class="section-title">Ringkasan Laba / Rugi</div>
                <table class="data-table profit-table">
                    <thead>
                        <tr>
                            <th>Keterangan</th>
                            <th class="text-right">Nominal (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Total Pendapatan (Omzet)</td>
                            <td class="text-right">{{ number_format($totalOmset, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td>Rata-rata Penjualan per Struk</td>
                            <td class="text-right">{{ number_format($averageTransactionValue, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td>Total HPP (Modal Barang)</td>
                            <td class="text-right text-danger">- {{ number_format($totalCOGS, 0, ',', '.') }}</td>
                        </tr>
                        <tr class="highlight">
                            <td>Laba Kotor (Gross Profit)</td>
                            <td class="text-right">{{ number_format($totalOmset - $totalCOGS, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td>Pengeluaran Operasional</td>
                            <td class="text-right text-danger">- {{ number_format($totalPengeluaran, 0, ',', '.') }}</td>
                        </tr>
                        <tr class="laba-bersih">
                            <td>Laba Bersih (Net Profit)</td>
                            <td class="text-right">
                                @if($labaBersih < 0)
                                    <span style="color: red;">(Rp {{ number_format(abs($labaBersih), 0, ',', '.') }})</span>
                                @else
                                    Rp {{ number_format($labaBersih, 0, ',', '.') }}
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="section-title" style="margin-top: 15px;">Rincian Pengeluaran Operasional</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Keterangan</th>
                            <th class="text-right">Nominal (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($expenseDetails as $expense)
                        <tr>
                            <td>{{ $expense->name ?? $expense->description ?? 'Pengeluaran' }}</td>
                            <td class="text-right text-danger">- {{ number_format($expense->amount, 0, ',', '.') }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="2" class="text-center">Tidak ada pengeluaran dicatat.</td>
                        </tr>
                        @endforelse
                        @if($expenseDetails->count() > 0)
                        <tr>
                            <td><strong>Total Pengeluaran</strong></td>
                            <td class="text-right text-danger"><strong>- {{ number_format($totalPengeluaran, 0, ',', '.') }}</strong></td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </td>

            <td>
                <div class="section-title">Metode Pembayaran</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Metode</th>
                            <th class="text-center">Persentase</th>
                            <th class="text-right">Total (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($paymentStats as $method => $amount)
                        @php $percent = $totalOmset > 0 ? ($amount / $totalOmset) * 100 : 0; @endphp
                        <tr>
                            <td><strong>{{ $method ?: 'Lainnya' }}</strong></td>
                            <td class="text-center">{{ number_format($percent, 1) }}%</td>
                            <td class="text-right">{{ number_format($amount, 0, ',', '.') }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" class="text-center">Belum ada data pembayaran</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <div class="section-title">Top 10 Produk Terlaris</div>
    <table class="data-table">
        <thead>
            <tr>
                <th width="5%" class="text-center">No</th>
                <th width="45%">Nama Produk</th>
                <th width="20%" class="text-center">Terjual (Porsi)</th>
                <th width="30%" class="text-right">Sumbangan Pendapatan (Rp)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($topProducts as $index => $prod)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td><strong>{{ $prod->menu_name }}</strong></td>
                <td class="text-center">{{ $prod->total_qty }}</td>
                <td class="text-right text-success">{{ number_format($prod->total_revenue, 0, ',', '.') }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="4" class="text-center">Belum ada data penjualan produk</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="page-break"></div>

    <div class="header">
        <h1>DAFTAR TRANSAKSI</h1>
        <p>Rincian Riwayat Transaksi Penjualan</p>
    </div>

    <table class="data-table">
        <thead>
            <tr style="background-color: #2563EB; color: white;">
                <th width="5%" class="text-center" style="color: white;">No</th>
                <th width="15%" style="color: white;">Tgl & Jam</th>
                <th width="15%" style="color: white;">ID Struk</th>
                <th width="20%" style="color: white;">Kasir</th>
                <th width="20%" style="color: white;">Metode Bayar</th>
                <th width="25%" class="text-right" style="color: white;">Total (Rp)</th>
            </tr>
        </thead>
        <tbody>
            @php $no = 1; @endphp
            @forelse($transactions as $trx)
            <tr>
                <td class="text-center">{{ $no++ }}</td>
                <td>{{ \Carbon\Carbon::parse($trx->created_at_device)->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td>
                <td>#{{ $trx->id }}</td>
                <td>{{ $trx->cashier_name ?? 'Owner/Sistem' }}</td>
                <td><strong>{{ $trx->payment_method ?? 'Lainnya' }}</strong></td>
                <td class="text-right">{{ number_format($trx->total_amount, 0, ',', '.') }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="text-center">Tidak ada transaksi tercatat.</td>
            </tr>
            @endforelse

            @if(count($transactions) > 0)
            <tr class="profit-table laba-bersih">
                <td colspan="5" class="text-right">TOTAL TRANSAKSI ({{ $totalTransaksi }} Struk) :</td>
                <td class="text-right">Rp {{ number_format($totalOmset, 0, ',', '.') }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="footer-container clearfix">
        <div class="signature-box">
            <p style="margin: 0; color: #4B5563;">Mengetahui,</p>
            <div class="signature-line">
                {{ Auth::user()->name ?? 'Management' }}
            </div>
        </div>
    </div>

</body>

</html>