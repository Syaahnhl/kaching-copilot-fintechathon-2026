<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Financial Evidence Dossier - Kaching POS</title>
    <style>
        @page {
            margin: 28px 32px 35px 32px;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1e293b;
            font-size: 10.5px;
            line-height: 1.45;
            margin: 0;
            padding: 0;
        }

        /* Header */
        .header-table {
            width: 100%;
            border-bottom: 2.5px solid #4338ca;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }
        .brand-title {
            font-size: 20px;
            font-weight: 900;
            color: #4338ca;
            letter-spacing: 1px;
            margin: 0;
            text-transform: uppercase;
        }
        .document-title {
            font-size: 13px;
            font-weight: bold;
            color: #0f172a;
            margin: 2px 0 0 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .document-subtitle {
            font-size: 9.5px;
            color: #64748b;
            margin: 2px 0 0 0;
        }
        .meta-table {
            width: 100%;
            text-align: right;
            font-size: 9.5px;
        }
        .meta-label {
            color: #64748b;
            font-weight: normal;
        }
        .meta-value {
            color: #0f172a;
            font-weight: bold;
        }

        /* Statutory Disclaimer Box */
        .disclaimer-box {
            background-color: #fffbeb;
            border: 1px solid #fde68a;
            border-left: 4px solid #d97706;
            padding: 9px 12px;
            border-radius: 4px;
            margin-bottom: 14px;
            font-size: 9px;
            color: #92400e;
        }
        .disclaimer-title {
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
            color: #b45309;
        }

        /* Section Title */
        .section-heading {
            font-size: 11px;
            font-weight: 800;
            color: #312e81;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 1.5px solid #e2e8f0;
            padding-bottom: 4px;
            margin-top: 14px;
            margin-bottom: 8px;
        }

        /* Summary Banner */
        .summary-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px 0;
            margin-bottom: 12px;
        }
        .score-card {
            width: 28%;
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 10px;
            text-align: center;
            vertical-align: middle;
        }
        .score-number {
            font-size: 26px;
            font-weight: 900;
            color: #312e81;
            line-height: 1;
            margin: 4px 0 2px 0;
        }
        .band-badge {
            display: inline-block;
            padding: 3px 8px;
            font-size: 8.5px;
            font-weight: 800;
            border-radius: 4px;
            text-transform: uppercase;
            background-color: #e0e7ff;
            color: #3730a3;
        }
        .summary-desc-card {
            width: 72%;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 14px;
            vertical-align: middle;
            font-size: 10px;
            color: #334155;
        }

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .data-table th {
            background-color: #f1f5f9;
            color: #334155;
            font-weight: 700;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            text-align: left;
        }
        .data-table td {
            padding: 6px 8px;
            border: 1px solid #e2e8f0;
            font-size: 9.5px;
            vertical-align: top;
        }
        .data-table tr:nth-child(even) td {
            background-color: #f8fafc;
        }

        /* Badges */
        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-available {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .status-partial {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .status-insufficient {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        /* Two Column Layout Table */
        .col-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .col-table td {
            width: 50%;
            vertical-align: top;
            padding: 0 6px;
        }
        .col-table td:first-child {
            padding-left: 0;
        }
        .col-table td:last-child {
            padding-right: 0;
        }

        .pillar-card {
            border: 1px solid #e2e8f0;
            background-color: #ffffff;
            border-radius: 5px;
            padding: 7px 10px;
            margin-bottom: 6px;
        }
        .pillar-header {
            width: 100%;
            margin-bottom: 4px;
        }
        .pillar-title {
            font-weight: bold;
            font-size: 10px;
            color: #1e293b;
        }
        .pillar-score {
            font-weight: 800;
            font-size: 11px;
            color: #4338ca;
            text-align: right;
        }
        .pillar-metric-list {
            font-size: 8.5px;
            color: #64748b;
            margin: 0;
            padding-left: 14px;
        }

        /* Limitations Box */
        .limitations-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            padding: 8px 12px;
            margin-bottom: 12px;
        }
        .limitations-box ul {
            margin: 4px 0 0 0;
            padding-left: 16px;
            font-size: 9px;
            color: #475569;
        }

        /* Footer */
        .footer-table {
            width: 100%;
            border-top: 1px solid #cbd5e1;
            padding-top: 8px;
            margin-top: 14px;
            font-size: 8.5px;
            color: #94a3b8;
        }
        .text-right {
            text-align: right;
        }
        .font-mono {
            font-family: 'Courier New', Courier, monospace;
        }
    </style>
</head>
<body>

    <!-- 1. HEADER SECTION -->
    <table class="header-table" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                <div class="brand-title">KACHING</div>
                <div class="document-title">Financial Evidence Dossier</div>
                <div class="document-subtitle">Rekapitulasi Bukti Pembukuan & Kesiapan Finansial Operasional</div>
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table class="meta-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="meta-label">Nama Bisnis:</td>
                        <td class="meta-value">{{ $businessName }}</td>
                    </tr>
                    <tr>
                        <td class="meta-label">Outlet / Lokasi:</td>
                        <td class="meta-value">{{ $outletName }}</td>
                    </tr>
                    <tr>
                        <td class="meta-label">Periode Evaluasi:</td>
                        <td class="meta-value">{{ $evaluationPeriod['start_date'] ?? '-' }} s/d {{ $evaluationPeriod['end_date'] ?? '-' }} ({{ $evaluationPeriod['days'] ?? 30 }} Hari)</td>
                    </tr>
                    <tr>
                        <td class="meta-label">Tanggal Cetak:</td>
                        <td class="meta-value font-mono">{{ $generatedAt }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- 2. STATUTORY DISCLAIMER (MANDATORY) -->
    <div class="disclaimer-box">
        <div class="disclaimer-title">Pernyataan Hukum & Batasan Dokumen (Legal Disclaimer)</div>
        <div>
            Dokumen ini merangkum bukti pembukuan dan integritas transaksi operasional yang tercatat secara nyata pada sistem kasir Kaching POS. 
            Dokumen ini <strong>BUKAN persetujuan pinjaman (loan approval)</strong>, <strong>BUKAN penilaian kelayakan kredit perbankan (creditworthiness/credit score)</strong>, dan <strong>TIDAK MENJAMIN penerimaan pembiayaan</strong> dari lembaga keuangan mitra. 
            Seluruh keputusan pembiayaan, analisis risiko, dan persetujuan kredit merupakan wewenang mutlak lembaga keuangan terkait. Kaching tidak memberikan rekomendasi atau garansi kelayakan pembiayaan.
        </div>
    </div>

    <!-- 3. READINESS SCORE & EXECUTIVE SUMMARY -->
    <div class="section-heading">1. Indeks Kesiapan Pembukuan Operasional (Operational Bookkeeping Readiness)</div>
    <table class="summary-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="score-card">
                <div style="font-size: 9px; font-weight: bold; color: #64748b; text-transform: uppercase;">Skor Kesiapan</div>
                <div class="score-number">{{ $score }}<span style="font-size: 13px; color: #94a3b8; font-weight: normal;">/100</span></div>
                <span class="band-badge">{{ str_replace('_', ' ', $band) }}</span>
            </td>
            <td class="summary-desc-card">
                <div style="font-weight: bold; margin-bottom: 3px; color: #1e293b;">Ringkasan Hasil Evaluasi Operasional:</div>
                <div>{{ $summary }}</div>
                <div style="margin-top: 5px; font-size: 8.5px; color: #64748b;">
                    * Skor dihitung secara otomatis oleh sistem analitik Kaching berbasis algoritma 5 pilar deterministik.
                </div>
            </td>
        </tr>
    </table>

    <!-- 4. 5 PILAR EVALUASI OPERASIONAL -->
    <div class="section-heading">2. Evaluasi 5 Pilar Kesiapan Finansial (Five Core Pillars)</div>
    <table class="col-table" cellpadding="0" cellspacing="0">
        <tr>
            <!-- Kolom Kiri -->
            <td>
                <!-- Pilar 1: Stabilitas Omzet -->
                @php $p1 = $pillars['revenue_stability'] ?? null; @endphp
                <div class="pillar-card">
                    <table class="pillar-header" cellpadding="0" cellspacing="0">
                        <tr>
                            <td class="pillar-title">Stabilitas Omzet (Bobot 25%)</td>
                            <td class="pillar-score">{{ $p1['score'] ?? 0 }} / 100</td>
                        </tr>
                    </table>
                    <ul class="pillar-metric-list">
                        <li>Rasio Hari Aktif Berdagang: {{ round(($p1['details']['active_days_ratio'] ?? 0) * 100) }}%</li>
                        <li>Volatilitas Omzet (CV): {{ $p1['details']['cv_revenue'] ?? 0 }}</li>
                        <li>Tren Mingguan: {{ ($p1['details']['trend_slope'] ?? 0) >= 0 ? '+' : '' }}{{ $p1['details']['trend_slope'] ?? 0 }}</li>
                    </ul>
                </div>

                <!-- Pilar 2: Disiplin Kasir & Kas -->
                @php $p3 = $pillars['cash_discipline'] ?? null; @endphp
                <div class="pillar-card">
                    <table class="pillar-header" cellpadding="0" cellspacing="0">
                        <tr>
                            <td class="pillar-title">Disiplin Kasir & Kas (Bobot 25%)</td>
                            <td class="pillar-score">{{ $p3['score'] ?? 0 }} / 100</td>
                        </tr>
                    </table>
                    <ul class="pillar-metric-list">
                        <li>Kepatuhan Tutup Shift Kasir: {{ round(($p3['details']['shift_compliance_ratio'] ?? 0) * 100) }}%</li>
                        <li>Tingkat Selisih Fisik Laci: {{ round(($p3['details']['cash_discrepancy_ratio'] ?? 0) * 100, 1) }}%</li>
                        <li>Pencatatan Biaya Operasional: {{ round(($p3['details']['expense_recording_ratio'] ?? 0) * 100) }}%</li>
                    </ul>
                </div>

                <!-- Pilar 5: Kelengkapan Data -->
                @php $p5 = $pillars['data_completeness'] ?? null; @endphp
                <div class="pillar-card">
                    <table class="pillar-header" cellpadding="0" cellspacing="0">
                        <tr>
                            <td class="pillar-title">Kelengkapan Data (Bobot 15%)</td>
                            <td class="pillar-score">{{ $p5['score'] ?? 0 }} / 100</td>
                        </tr>
                    </table>
                    <ul class="pillar-metric-list">
                        <li>Rasio Atribusi Pelanggan: {{ round(($p5['details']['customer_attribution_ratio'] ?? 0) * 100) }}%</li>
                        <li>Porsi Pembayaran Non-Tunai: {{ round(($p5['details']['cashless_ratio'] ?? 0) * 100) }}%</li>
                        <li>Presensi Shift Kasir: {{ round(($p5['details']['attendance_coverage_ratio'] ?? 0) * 100) }}%</li>
                    </ul>
                </div>
            </td>

            <!-- Kolom Kanan -->
            <td>
                <!-- Pilar 3: Kesehatan Marjin -->
                @php $p2 = $pillars['margin_health'] ?? null; @endphp
                <div class="pillar-card">
                    <table class="pillar-header" cellpadding="0" cellspacing="0">
                        <tr>
                            <td class="pillar-title">Kesehatan Marjin (Bobot 20%)</td>
                            <td class="pillar-score">{{ $p2['score'] ?? 0 }} / 100</td>
                        </tr>
                    </table>
                    <ul class="pillar-metric-list">
                        <li>Estimasi Margin Kotor: {{ $p2['details']['gross_margin_pct'] ?? 0 }}%</li>
                        <li>Cakupan Menu Ber-HPP: {{ round(($p2['details']['cogs_coverage_ratio'] ?? 0) * 100) }}%</li>
                        <li>Rasio Beban Operasional: {{ round(($p2['details']['expense_ratio'] ?? 0) * 100) }}%</li>
                    </ul>
                </div>

                <!-- Pilar 4: Integritas Operasional -->
                @php $p4 = $pillars['operational_integrity'] ?? null; @endphp
                <div class="pillar-card">
                    <table class="pillar-header" cellpadding="0" cellspacing="0">
                        <tr>
                            <td class="pillar-title">Integritas Operasional (Bobot 15%)</td>
                            <td class="pillar-score">{{ $p4['score'] ?? 0 }} / 100</td>
                        </tr>
                    </table>
                    <ul class="pillar-metric-list">
                        <li>Rasio Pembatalan/Void: {{ round(($p4['details']['cancellation_rate'] ?? 0) * 100, 1) }}%</li>
                        <li>Frekuensi Diskon Transaksi: {{ round(($p4['details']['discount_rate'] ?? 0) * 100, 1) }}%</li>
                        <li>Konsentrasi Omzet Puncak: {{ round(($p4['details']['peak_concentration_rate'] ?? 0) * 100) }}%</li>
                    </ul>
                </div>

                <!-- Box Disclosure Sumber Data -->
                <div style="background-color: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 5px; padding: 7px 10px;">
                    <div style="font-weight: bold; font-size: 9px; color: #334155; text-transform: uppercase;">Basis Basis Data Terverifikasi:</div>
                    <div style="font-size: 8.5px; color: #64748b; margin-top: 3px;">
                        Data bersumber langsung dari tabel operasional terisolasi (Tenant ID: <span class="font-mono">{{ $user->id }}</span>{{ $outlet ? ', Outlet ID: ' . $outlet->id : '' }}). Tidak ada komputasi berbasis data buatan.
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <!-- 5. FINANCIAL EVIDENCE COVERAGE TABLE -->
    <div class="section-heading">3. Cakupan Bukti Finansial Terverifikasi (Evidence Coverage)</div>
    <table class="data-table" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th style="width: 24%;">Kategori Bukti</th>
                <th style="width: 12%;">Status</th>
                <th style="width: 14%;">Tabel Sumber</th>
                <th style="width: 18%;">Jumlah Record & Cakupan</th>
                <th style="width: 32%;">Uraian Bukti & Explainability</th>
            </tr>
        </thead>
        <tbody>
            @foreach($evidenceSummary as $item)
                @php
                    $st = $item['status'] ?? 'INSUFFICIENT';
                    $badgeClass = $st === 'AVAILABLE' ? 'status-available' : ($st === 'PARTIAL' ? 'status-partial' : 'status-insufficient');
                @endphp
                <tr>
                    <td>
                        <strong>{{ $item['label'] ?? '' }}</strong>
                    </td>
                    <td>
                        <span class="status-badge {{ $badgeClass }}">{{ $st }}</span>
                    </td>
                    <td class="font-mono" style="font-size: 8.5px; color: #475569;">
                        {{ $item['source'] ?? '' }}
                    </td>
                    <td style="font-size: 9px;">
                        <div><strong>{{ $item['record_count'] ?? 0 }} record</strong></div>
                        <div style="color: #64748b; font-size: 8px;">{{ $item['coverage'] ?? '' }}</div>
                    </td>
                    <td style="font-size: 8.5px; color: #334155;">
                        <div style="margin-bottom: 2px;">{{ $item['summary'] ?? '' }}</div>
                        <div style="color: #64748b; font-style: italic;">{{ $item['explainability'] ?? '' }}</div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- 6. LIMITATIONS / KETERBATASAN DATA -->
    @if(!empty($limitations))
        <div class="section-heading">4. Keterbatasan Data & Catatan Audit (Data Limitations)</div>
        <div class="limitations-box">
            <div style="font-size: 9px; font-weight: bold; color: #475569; text-transform: uppercase;">
                Keterbatasan Sistem POS Saat Evaluasi Dijalankan:
            </div>
            <ul>
                @foreach($limitations as $limitation)
                    <li>{{ $limitation }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- 7. FOOTER / VERIFICATION FOOTNOTE -->
    <table class="footer-table" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width: 60%;">
                Kaching POS Financial Intelligence &copy; {{ date('Y') }} &bull; FinTechathon 2026 Innovation Track
            </td>
            <td style="width: 40%;" class="text-right font-mono">
                Hash: {{ strtoupper(substr(hash('sha256', $user->id . ($outlet->id ?? 0) . $score . $band . ($evaluationPeriod['start_date'] ?? '')), 0, 16)) }}
            </td>
        </tr>
    </table>

</body>
</html>
