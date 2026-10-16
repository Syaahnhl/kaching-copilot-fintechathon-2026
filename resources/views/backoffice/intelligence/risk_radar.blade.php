@extends('backoffice.master')

@section('title', 'Kaching Risk Radar')

@section('content')
<div class="space-y-6">
    <!-- HEADER BAR -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm">
        <div>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
                <div>
                    <h1 class="text-xl font-black text-slate-900">Kaching Risk Radar</h1>
                    <p class="text-xs text-slate-500 font-medium">Pantau sinyal risiko operasional dan keuangan dari data Kaching.</p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                <span>Deterministik — Berbasis Data Operasional</span>
            </span>

            @if(isset($outlets) && $outlets->count() > 1)
                <div class="relative">
                    <select onchange="window.location.href = '{{ route('copilot.intelligence.risk-radar') }}?outlet_id=' + this.value"
                            class="text-xs font-bold text-slate-700 bg-white border border-slate-200 rounded-xl px-3 py-2 pr-8 shadow-sm focus:outline-none focus:ring-2 focus:ring-rose-500 appearance-none cursor-pointer">
                        @foreach($outlets as $o)
                            <option value="{{ $o->id }}" {{ ($activeOutlet && $activeOutlet->id == $o->id) ? 'selected' : '' }}>
                                Outlet: {{ $o->name }}
                            </option>
                        @endforeach
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2.5 text-slate-400">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </div>
                </div>
            @elseif($activeOutlet)
                <span class="text-xs font-bold text-slate-600 px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl">
                    Outlet: {{ $activeOutlet->name }}
                </span>
            @endif

            <a href="{{ route('copilot.intelligence') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs shadow-sm transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"></path>
                </svg>
                <span>Kembali ke Overview</span>
            </a>
        </div>
    </div>

    <!-- 6 PRIMARY CARDS GRID -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        @php
            $cardDefinitions = [
                'REVENUE_DECLINE' => [
                    'label' => 'Revenue Decline',
                    'icon_bg' => 'bg-amber-50 text-amber-600 border-amber-200',
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path>',
                ],
                'EXPENSE_SPIKE' => [
                    'label' => 'Expense Spike',
                    'icon_bg' => 'bg-rose-50 text-rose-600 border-rose-200',
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>',
                ],
                'MARGIN_COMPRESSION' => [
                    'label' => 'Margin Compression',
                    'icon_bg' => 'bg-purple-50 text-purple-600 border-purple-200',
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>',
                ],
                'ABNORMAL_VOID_REFUND' => [
                    'label' => 'Void / Refund Anomaly',
                    'icon_bg' => 'bg-orange-50 text-orange-600 border-orange-200',
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z"></path>',
                ],
                'DRAWER_DISCREPANCY' => [
                    'label' => 'Drawer Discrepancy',
                    'icon_bg' => 'bg-indigo-50 text-indigo-600 border-indigo-200',
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>',
                ],
            ];
        @endphp

        <!-- 1-5. FINANCIAL WARNING SERVICE CARDS -->
        @foreach($cardDefinitions as $code => $meta)
            @php
                $item = $warnings[$code] ?? null;
                $status = $item['status'] ?? 'INSUFFICIENT_DATA';
                $severity = $item['severity'] ?? 'INFO';

                // Status Badge Classes
                $statusClasses = [
                    'CRITICAL' => 'bg-rose-50 text-rose-700 border-rose-200',
                    'WARNING' => 'bg-amber-50 text-amber-700 border-amber-200',
                    'WATCH' => 'bg-blue-50 text-blue-700 border-blue-200',
                    'NORMAL' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    'INSUFFICIENT_DATA' => 'bg-slate-100 text-slate-700 border-slate-300',
                    'SUSPENDED' => 'bg-purple-50 text-purple-700 border-purple-200',
                ];
                $badgeClass = $statusClasses[$status] ?? 'bg-slate-100 text-slate-700 border-slate-300';
            @endphp

            <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm flex flex-col justify-between hover:border-slate-300 transition">
                <div>
                    <!-- CARD HEADER -->
                    <div class="flex items-start justify-between gap-4 mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl {{ $meta['icon_bg'] }} border flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                    {!! $meta['icon'] !!}
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-base font-black text-slate-900">{{ $meta['label'] }}</h2>
                                <p class="text-[11px] text-slate-400 font-mono">{{ $code }}</p>
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-1">
                            <span class="px-2.5 py-1 rounded-full text-[11px] font-extrabold border {{ $badgeClass }}">
                                {{ $status }}
                            </span>
                            @if(!empty($severity) && $severity !== $status)
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                                    SEVERITY: {{ $severity }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- CARD BODY: TITLE & SUMMARY -->
                    <div class="mb-4">
                        <h3 class="text-sm font-bold text-slate-800 mb-1">
                            {{ $item['title'] ?? 'Evaluasi Indikator' }}
                        </h3>
                        <p class="text-xs text-slate-600 leading-relaxed">
                            {{ $item['message'] ?? 'Tidak ada pesan evaluasi.' }}
                        </p>
                    </div>

                    <!-- INSUFFICIENT DATA TRANSPARENT NOTICE -->
                    @if($status === 'INSUFFICIENT_DATA')
                        <div class="mb-4 p-3.5 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 flex items-start gap-2.5">
                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div class="text-[11px] leading-relaxed">
                                <span class="font-bold text-slate-700">Data belum cukup untuk mengevaluasi indikator ini.</span>
                                @if(!empty($item['evidence']['reason']))
                                    <span class="block text-slate-500 mt-0.5">{{ $item['evidence']['reason'] }}</span>
                                @endif
                            </div>
                        </div>
                    @endif

                    <!-- DATA QUALITY CALLOUT -->
                    @if(!empty($item['evidence']['untracked_cogs_ratio_pct']) && $item['evidence']['untracked_cogs_ratio_pct'] > 0)
                        <div class="mb-4 p-3 rounded-xl bg-amber-50/70 border border-amber-200/80 text-[11px] text-amber-900 leading-snug">
                            <div class="flex items-center gap-1.5 font-bold mb-0.5">
                                <svg class="w-3.5 h-3.5 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                <span>Kualitas Data HPP: {{ number_format($item['evidence']['untracked_cogs_ratio_pct'], 1) }}% Belum Tercatat</span>
                            </div>
                            <span>Terdapat menu terjual tanpa data HPP (cost price). Indikator margin membutuhkan minimal 85% cakupan HPP agar valid.</span>
                        </div>
                    @endif

                    <!-- METRICS GRID -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2.5 p-3.5 rounded-xl bg-slate-50 border border-slate-200/70 mb-4 text-xs">
                        <div>
                            <span class="block text-[10px] font-bold text-slate-400 uppercase">Metrik</span>
                            <span class="font-bold text-slate-700 truncate block" title="{{ $item['metric'] ?? '-' }}">
                                {{ $item['metric'] ?? '-' }}
                            </span>
                        </div>
                        <div>
                            <span class="block text-[10px] font-bold text-slate-400 uppercase">Aktual</span>
                            <span class="font-extrabold text-slate-900">
                                @if(isset($item['actual']) && $item['actual'] !== null)
                                    @if(is_numeric($item['actual']))
                                        {{ ($item['unit'] ?? '') === 'IDR' ? 'Rp ' . number_format($item['actual'], 0, ',', '.') : number_format($item['actual'], 1) . ' ' . ($item['unit'] ?? '') }}
                                    @else
                                        {{ $item['actual'] }}
                                    @endif
                                @else
                                    -
                                @endif
                            </span>
                        </div>
                        <div>
                            <span class="block text-[10px] font-bold text-slate-400 uppercase">Baseline</span>
                            <span class="font-bold text-slate-600">
                                @if(isset($item['baseline']) && $item['baseline'] !== null)
                                    @if(is_numeric($item['baseline']))
                                        {{ ($item['unit'] ?? '') === 'IDR' ? 'Rp ' . number_format($item['baseline'], 0, ',', '.') : number_format($item['baseline'], 1) . ' ' . ($item['unit'] ?? '') }}
                                    @else
                                        {{ $item['baseline'] }}
                                    @endif
                                @else
                                    -
                                @endif
                            </span>
                        </div>
                        <div>
                            <span class="block text-[10px] font-bold text-slate-400 uppercase">Threshold</span>
                            <span class="font-bold text-slate-600">
                                @if(isset($item['threshold']) && $item['threshold'] !== null)
                                    @if(is_numeric($item['threshold']))
                                        {{ ($item['unit'] ?? '') === 'IDR' ? 'Rp ' . number_format($item['threshold'], 0, ',', '.') : number_format($item['threshold'], 1) . ' ' . ($item['unit'] ?? '') }}
                                    @else
                                        {{ $item['threshold'] }}
                                    @endif
                                @else
                                    -
                                @endif
                            </span>
                        </div>
                    </div>

                    <!-- EXPLAINABILITY SECTION (WHAT / WHY / WHEN / EVIDENCE / LIMITATION) -->
                    <div class="space-y-2.5 text-[11px] border-t border-slate-100 pt-3">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                            <div>
                                <span class="font-extrabold text-slate-700">WHAT:</span>
                                <span class="text-slate-600 ml-1">{{ $item['title'] ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="font-extrabold text-slate-700">WHEN:</span>
                                <span class="text-slate-600 ml-1">
                                    @if(!empty($item['period']['start_date']) && !empty($item['period']['end_date']))
                                        {{ $item['period']['start_date'] }} s/d {{ $item['period']['end_date'] }} ({{ $item['period']['days'] ?? '-' }} hari)
                                    @else
                                        Periode rolling sistem
                                    @endif
                                </span>
                            </div>
                        </div>

                        <div>
                            <span class="font-extrabold text-slate-700">WHY:</span>
                            <span class="text-slate-600 ml-1">{{ $item['message'] ?? '-' }}</span>
                        </div>

                        @if(!empty($item['limitations']))
                            <div>
                                <span class="font-extrabold text-slate-700">LIMITATION:</span>
                                <ul class="list-disc list-inside text-slate-500 mt-0.5 space-y-0.5 pl-1">
                                    @foreach($item['limitations'] as $limitation)
                                        <li>{{ $limitation }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- CARD FOOTER: REUSE RECOMMENDATION IF SUPPLIED -->
                @if(!empty($item['action']) || !empty($item['recommendation']))
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px]">
                        <span class="font-bold text-slate-700">Rekomendasi Operasional:</span>
                        <span class="text-indigo-600 font-semibold">{{ $item['action'] ?? $item['recommendation'] }}</span>
                    </div>
                @endif
            </div>
        @endforeach

        <!-- 6. FINANCING READINESS CARD -->
        @php
            $frScore = $financingReadiness['score'] ?? 0;
            $frBand = $financingReadiness['band'] ?? 'N/A';
            $frPillars = $financingReadiness['pillars'] ?? [];
            $frPeriod = $financingReadiness['evaluation_period'] ?? [];
            $frDisclaimer = $financingReadiness['disclaimer'] ?? 'Financing Readiness Index mengukur kelengkapan data operasional dan kedisiplinan pembukuan UMKM, bukan persetujuan kredit (credit score) dan tidak menjamin penerimaan pinjaman oleh lembaga keuangan.';
            $frLimitations = $financingReadiness['limitations'] ?? [];

            // Score Badge
            $frBadgeClass = 'bg-slate-100 text-slate-700 border-slate-300';
            if ($frScore >= 80) {
                $frBadgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
            } elseif ($frScore >= 60) {
                $frBadgeClass = 'bg-blue-50 text-blue-700 border-blue-200';
            } elseif ($frScore >= 40) {
                $frBadgeClass = 'bg-amber-50 text-amber-700 border-amber-200';
            } else {
                $frBadgeClass = 'bg-rose-50 text-rose-700 border-rose-200';
            }
        @endphp

        <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm flex flex-col justify-between hover:border-slate-300 transition">
            <div>
                <!-- CARD HEADER -->
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-teal-50 border border-teal-200 text-teal-600 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-base font-black text-slate-900">Financing Readiness</h2>
                            <p class="text-[11px] text-slate-400 font-mono">FINANCING_READINESS_INDEX</p>
                        </div>
                    </div>

                    <div class="flex flex-col items-end gap-1">
                        <span class="px-2.5 py-1 rounded-full text-[11px] font-extrabold border {{ $frBadgeClass }}">
                            SKOR: {{ $frScore }}/100
                        </span>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                            BAND: {{ $frBand }}
                        </span>
                    </div>
                </div>

                <!-- CARD BODY -->
                <div class="mb-4">
                    <h3 class="text-sm font-bold text-slate-800 mb-1">Kesiapan Pembukuan untuk Pengajuan Pembiayaan</h3>
                    <p class="text-xs text-slate-600 leading-relaxed">
                        Evaluasi komprehensif terhadap 5 pilar integritas pencatatan kasir, stabilitas omzet, dan kedisiplinan pembukuan UMKM.
                    </p>
                </div>

                <!-- 5 PILLARS METRICS -->
                <div class="grid grid-cols-2 md:grid-cols-5 gap-2 p-3.5 rounded-xl bg-slate-50 border border-slate-200/70 mb-4 text-xs">
                    <div>
                        <span class="block text-[9px] font-bold text-slate-400 uppercase truncate">1. Omzet</span>
                        <span class="font-extrabold text-slate-900">{{ $frPillars['revenue_stability']['score'] ?? 0 }}<span class="text-[10px] font-normal text-slate-400">/25</span></span>
                    </div>
                    <div>
                        <span class="block text-[9px] font-bold text-slate-400 uppercase truncate">2. Margin</span>
                        <span class="font-extrabold text-slate-900">{{ $frPillars['margin_health']['score'] ?? 0 }}<span class="text-[10px] font-normal text-slate-400">/20</span></span>
                    </div>
                    <div>
                        <span class="block text-[9px] font-bold text-slate-400 uppercase truncate">3. Kas Laci</span>
                        <span class="font-extrabold text-slate-900">{{ $frPillars['cash_discipline']['score'] ?? 0 }}<span class="text-[10px] font-normal text-slate-400">/20</span></span>
                    </div>
                    <div>
                        <span class="block text-[9px] font-bold text-slate-400 uppercase truncate">4. Data HPP</span>
                        <span class="font-extrabold text-slate-900">{{ $frPillars['data_completeness']['score'] ?? 0 }}<span class="text-[10px] font-normal text-slate-400">/20</span></span>
                    </div>
                    <div>
                        <span class="block text-[9px] font-bold text-slate-400 uppercase truncate">5. Integritas</span>
                        <span class="font-extrabold text-slate-900">{{ $frPillars['operational_integrity']['score'] ?? 0 }}<span class="text-[10px] font-normal text-slate-400">/15</span></span>
                    </div>
                </div>

                <!-- EXPLAINABILITY SECTION -->
                <div class="space-y-2.5 text-[11px] border-t border-slate-100 pt-3">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <div>
                            <span class="font-extrabold text-slate-700">WHAT:</span>
                            <span class="text-slate-600 ml-1">Financing Readiness Index (0-100)</span>
                        </div>
                        <div>
                            <span class="font-extrabold text-slate-700">WHEN:</span>
                            <span class="text-slate-600 ml-1">
                                @if(!empty($frPeriod['start_date']) && !empty($frPeriod['end_date']))
                                    {{ $frPeriod['start_date'] }} s/d {{ $frPeriod['end_date'] }}
                                @else
                                    Observasi 30-90 hari
                                @endif
                            </span>
                        </div>
                    </div>

                    <div>
                        <span class="font-extrabold text-slate-700">WHY:</span>
                        <span class="text-slate-600 ml-1">Menilai apakah data operasional POS memenuhi standar keterbacaan oleh analis perbankan atau fintech p2p lending.</span>
                    </div>

                    @if(!empty($frLimitations))
                        <div>
                            <span class="font-extrabold text-slate-700">LIMITATION:</span>
                            <ul class="list-disc list-inside text-slate-500 mt-0.5 space-y-0.5 pl-1">
                                @foreach($frLimitations as $limitation)
                                    <li>{{ $limitation }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>

            <!-- MANDATORY LEGAL DISCLAIMER -->
            <div class="mt-4 pt-3 border-t border-slate-100">
                <div class="p-3 rounded-xl bg-slate-50 border border-slate-200/80 text-[10px] text-slate-500 leading-relaxed flex items-start gap-2">
                    <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>
                        <strong class="text-slate-700 font-bold">Catatan Kepatuhan Finansial:</strong>
                        {{ $frDisclaimer }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- KACHING ACTION CENTER: BOUNDED DETERMINISTIC RECOMMENDATIONS -->
    @php
        $recList = $recommendations ?? [];
    @endphp
    <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-100">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-black text-slate-900 tracking-tight">Kaching Action Center</h2>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-blue-50 text-blue-700 border border-blue-200">
                        {{ count($recList) }} Rekomendasi
                    </span>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">Rekomendasi tindakan berdasarkan sinyal dan bukti operasional.</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-bold bg-slate-100 text-slate-600 border border-slate-200">
                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                    Propose ≠ Execute
                </span>
            </div>
        </div>

        @if(empty($recList))
            <div class="py-8 text-center">
                <div class="w-12 h-12 rounded-full bg-slate-50 border border-slate-200 flex items-center justify-center mx-auto mb-3 text-slate-400">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <p class="text-sm font-bold text-slate-700">Tidak Ada Tindakan Kritis yang Mendesak</p>
                <p class="text-xs text-slate-400 mt-1 max-w-md mx-auto">
                    Seluruh sinyal operasional berada dalam parameter normal atau data masih berstatus baseline.
                </p>
            </div>
        @else
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-5">
                @foreach($recList as $rec)
                    @php
                        $priority = $rec['priority'] ?? 'MEDIUM';
                        $priorityBadgeClass = match($priority) {
                            'CRITICAL' => 'bg-rose-50 text-rose-700 border-rose-200',
                            'HIGH'     => 'bg-amber-50 text-amber-700 border-amber-200',
                            'MEDIUM'   => 'bg-blue-50 text-blue-700 border-blue-200',
                            default    => 'bg-slate-50 text-slate-700 border-slate-200',
                        };
                        $isProposalCapable = !empty($rec['proposal_capable']);
                    @endphp
                    <div class="p-4 rounded-xl border border-slate-200/90 bg-white hover:border-slate-300 transition-all flex flex-col justify-between">
                        <div>
                            <!-- Header: Priority & Action Type -->
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide border {{ $priorityBadgeClass }}">
                                    Prioritas {{ $priority }}
                                </span>
                                <span class="text-[10px] font-mono text-slate-400 font-semibold">
                                    {{ $rec['action_type'] ?? 'REVIEW' }}
                                </span>
                            </div>

                            <!-- What -->
                            <h3 class="text-xs font-bold text-slate-900 leading-snug mb-2">
                                {{ $rec['what'] ?? '-' }}
                            </h3>

                            <!-- Why -->
                            <div class="p-2.5 rounded-lg bg-slate-50 border border-slate-100 text-[11px] text-slate-600 mb-3 leading-relaxed">
                                <strong class="text-slate-800 font-semibold block mb-0.5">Rasional / Alasan:</strong>
                                {{ $rec['why'] ?? '-' }}
                            </div>

                            <!-- Evidence Summary -->
                            @if(!empty($rec['evidence']))
                                <div class="mb-3 text-[11px]">
                                    <span class="text-slate-400 font-semibold block mb-1">Bukti Terverifikasi:</span>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($rec['evidence'] as $eKey => $eVal)
                                            @if(is_scalar($eVal) && $eVal !== '' && $eVal !== null)
                                                <span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 font-mono text-[10px] border border-slate-200">
                                                    {{ $eKey }}: {{ is_numeric($eVal) && is_float($eVal + 0) ? number_format((float)$eVal, 2) : $eVal }}
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <!-- Expected Impact -->
                            <div class="mb-3 text-[11px] text-slate-600">
                                <span class="text-slate-400 font-semibold block mb-0.5">Ekspektasi Dampak:</span>
                                <p class="italic text-slate-700">
                                    {{ is_array($rec['expected_impact']) ? ($rec['expected_impact']['description'] ?? '-') : $rec['expected_impact'] }}
                                </p>
                            </div>

                            <!-- Limitations -->
                            @if(!empty($rec['limitations']))
                                <div class="mb-3 p-2 rounded-lg bg-amber-50/50 border border-amber-100 text-[10px] text-amber-800">
                                    <strong class="font-bold block mb-0.5">Batasan:</strong>
                                    <ul class="list-disc list-inside space-y-0.5">
                                        @foreach($rec['limitations'] as $lim)
                                            <li>{{ $lim }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>

                        <!-- Card Footer -->
                        <div class="pt-3 border-t border-slate-100 flex items-center justify-between gap-2 mt-2">
                            <span class="text-[10px] text-slate-400 font-mono truncate">
                                Sumber: {{ $rec['source']['service'] ?? 'Engine' }} ({{ $rec['source']['table'] ?? 'pos' }})
                            </span>

                            @if($isProposalCapable)
                                <button type="button" class="px-3 py-1 rounded-lg text-xs font-bold bg-blue-600 hover:bg-blue-700 text-white shadow-xs transition-colors">
                                    Prepare Action Proposal
                                </button>
                            @else
                                <span class="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                    {{ $rec['suggested_action'] ?? 'Review / Simulate' }}
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- CASH DEPLETION: EXPLICITLY SUSPENDED INDICATOR BOX -->
    @php
        $cashDepletion = $warnings['CASH_DEPLETION'] ?? null;
    @endphp
    <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm">
        <div class="flex items-start justify-between gap-4 mb-3">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-purple-50 border border-purple-200 text-purple-600 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-sm font-black text-slate-900">Cash Depletion (Suspended by Design)</h3>
                    <p class="text-[11px] text-slate-400 font-mono">CASH_DEPLETION — STATUS_SUSPENDED</p>
                </div>
            </div>
            <span class="px-2.5 py-1 rounded-full text-[11px] font-extrabold bg-purple-50 text-purple-700 border border-purple-200">
                SUSPENDED
            </span>
        </div>
        <p class="text-xs text-slate-600 leading-relaxed mb-3">
            {{ $cashDepletion['message'] ?? 'Aturan Cash Depletion ditangguhkan secara eksplisit karena kas laci POS bukan representasi likuiditas total bisnis atau rekening bank.' }}
        </p>
        <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200 text-[11px] text-slate-500 leading-relaxed">
            <span class="font-bold text-slate-700 block mb-0.5">Penjelasan Batasan Arsitektur:</span>
            <span>Kaching POS membatasi evaluasi likuiditas kas laci agar tidak menimbulkan alarm palsu (false positive). Kas laci kasir secara rutin disetor (cash pick-up / banking) sehingga saldo fisik kasir tidak mencerminkan total kas riil merchant di perbankan.</span>
        </div>
    </div>
</div>
@endsection
