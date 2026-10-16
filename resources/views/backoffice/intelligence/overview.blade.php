@extends('backoffice.master')

@section('title', 'Kaching Intelligence')

@section('content')
<div class="space-y-8 pb-12" x-data="kachingIntelligenceHub()">
    <!-- PAGE HEADER -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-200/80 pb-6">
        <div>
            <div class="flex items-center gap-2.5">
                <div class="w-10 h-10 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600 shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                    </svg>
                </div>
                <div>
                    <h1 class="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">Kaching Intelligence</h1>
                    <p class="text-xs md:text-sm text-slate-500 font-medium mt-0.5">Insight bisnis dari data Kaching</p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200/80">
                <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <span>Periode: 30 Hari Terakhir</span>
            </span>
            <a href="{{ route('copilot.intelligence.risk-radar') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-sm transition">
                <span>Risk Radar</span>
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path>
                </svg>
            </a>
            <a href="{{ route('copilot.intelligence.scenario') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs shadow-sm transition">
                <span>Scenario Simulator</span>
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path>
                </svg>
            </a>
            <a href="{{ route('copilot.intelligence.menu') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-sm transition">
                <span>Buka Menu Intelligence</span>
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path>
                </svg>
            </a>
        </div>
    </div>

    <!-- MAIN GRID CARDS -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- 1. MENU INTELLIGENCE CARD -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm flex flex-col justify-between hover:border-slate-300 transition">
            <div>
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-50 border border-amber-100 flex items-center justify-center text-amber-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-black text-slate-900">Menu Intelligence</h2>
                            <p class="text-xs text-slate-500 font-medium">Analisis performa menu berdasarkan penjualan dan kontribusi.</p>
                        </div>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                        BCG & SAW
                    </span>
                </div>

                @if($menuSummary['state'] === 'SUCCESS')
                    <!-- BCG QUADRANT SUMMARY (2x2 GRID) -->
                    <div class="grid grid-cols-2 gap-3 my-4">
                        <div class="p-3.5 rounded-xl bg-emerald-50/60 border border-emerald-100">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-[11px] font-bold text-emerald-800 tracking-wider">STAR</span>
                                <span class="text-xs font-extrabold text-emerald-700 bg-emerald-100/80 px-2 py-0.5 rounded-md">{{ $menuSummary['star_count'] }} Menu</span>
                            </div>
                            <p class="text-[11px] text-slate-500 line-clamp-1 font-medium">
                                @if(!empty($menuSummary['star_examples']))
                                    {{ implode(', ', $menuSummary['star_examples']) }}
                                @else
                                    Tinggi Jual • Tinggi Margin
                                @endif
                            </p>
                        </div>

                        <div class="p-3.5 rounded-xl bg-amber-50/60 border border-amber-100">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-[11px] font-bold text-amber-800 tracking-wider">PUZZLE</span>
                                <span class="text-xs font-extrabold text-amber-700 bg-amber-100/80 px-2 py-0.5 rounded-md">{{ $menuSummary['puzzle_count'] }} Menu</span>
                            </div>
                            <p class="text-[11px] text-slate-500 line-clamp-1 font-medium">
                                @if(!empty($menuSummary['puzzle_examples']))
                                    {{ implode(', ', $menuSummary['puzzle_examples']) }}
                                @else
                                    Rendah Jual • Tinggi Margin
                                @endif
                            </p>
                        </div>

                        <div class="p-3.5 rounded-xl bg-blue-50/60 border border-blue-100">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-[11px] font-bold text-blue-800 tracking-wider">CASH COW</span>
                                <span class="text-xs font-extrabold text-blue-700 bg-blue-100/80 px-2 py-0.5 rounded-md">{{ $menuSummary['cash_cow_count'] }} Menu</span>
                            </div>
                            <p class="text-[11px] text-slate-500 line-clamp-1 font-medium">
                                @if(!empty($menuSummary['cash_cow_examples']))
                                    {{ implode(', ', $menuSummary['cash_cow_examples']) }}
                                @else
                                    Tinggi Jual • Rendah Margin
                                @endif
                            </p>
                        </div>

                        <div class="p-3.5 rounded-xl bg-rose-50/60 border border-rose-100">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-[11px] font-bold text-rose-800 tracking-wider">DOG</span>
                                <span class="text-xs font-extrabold text-rose-700 bg-rose-100/80 px-2 py-0.5 rounded-md">{{ $menuSummary['dog_count'] }} Menu</span>
                            </div>
                            <p class="text-[11px] text-slate-500 line-clamp-1 font-medium">
                                @if(!empty($menuSummary['dog_examples']))
                                    {{ implode(', ', $menuSummary['dog_examples']) }}
                                @else
                                    Rendah Jual • Rendah Margin
                                @endif
                            </p>
                        </div>
                    </div>
                @else
                    <div class="my-4 p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 flex items-start gap-3">
                        <svg class="w-5 h-5 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <div class="text-xs">
                            <p class="font-bold text-slate-800 mb-0.5">Data Belum Mencukupi</p>
                            <p class="text-slate-500">Data belum cukup untuk menghasilkan analisis. Analisis menu membutuhkan minimal 7 hari data transaksi.</p>
                        </div>
                    </div>
                @endif
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-between">
                <span class="text-[11px] font-medium text-slate-400">Total Teranalisis: {{ $menuSummary['total_menus'] }} Menu</span>
                <a href="{{ route('copilot.intelligence.menu') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs shadow-sm transition">
                    <span>Lihat Analisis Menu</span>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path>
                    </svg>
                </a>
            </div>
        </div>

        <!-- 2. FINANCIAL INTELLIGENCE CARD -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm flex flex-col justify-between hover:border-slate-300 transition">
            <div>
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-black text-slate-900">Financial Intelligence</h2>
                            <p class="text-xs text-slate-500 font-medium">Monitoring kondisi likuiditas, rasio kas, dan deteksi anomali.</p>
                        </div>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold {{ $financialSummary['has_active_warnings'] ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-blue-50 text-blue-700 border border-blue-200' }}">
                        {{ $financialSummary['condition_label'] }}
                    </span>
                </div>

                @if($financialSummary['state'] === 'SUCCESS')
                    <div class="space-y-3 my-4">
                        <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200/70">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-bold text-slate-700">Status Peringatan Likuiditas</span>
                                @if($financialSummary['has_active_warnings'])
                                    <span class="font-extrabold text-amber-700">{{ $financialSummary['warning_count'] }} Catatan Aktif</span>
                                @else
                                    <span class="font-extrabold text-emerald-700">Normal & Aman</span>
                                @endif
                            </div>
                            @if(!empty($financialSummary['active_warnings']))
                                <ul class="mt-2 space-y-1 text-[11px] text-slate-600">
                                    @foreach($financialSummary['active_warnings'] as $warning)
                                        <li class="flex items-start gap-1.5">
                                            <span class="text-amber-500 font-bold">•</span>
                                            <span>{{ $warning['title'] ?? ($warning['metric_name'] ?? 'Indikator rasio perlu dipantau') }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="text-[11px] text-slate-500">Tidak ada deviasi pengeluaran atau ancaman kehabisan saldo kas operasional.</p>
                            @endif
                        </div>

                        @if($financialSummary['forecast_summary'])
                            <div class="p-3 rounded-xl bg-indigo-50/50 border border-indigo-100 text-[11px] text-indigo-900 font-medium flex items-center gap-2">
                                <svg class="w-4 h-4 text-indigo-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                                </svg>
                                <span>{{ $financialSummary['forecast_summary'] }}</span>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="my-4 p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 flex items-start gap-3">
                        <svg class="w-5 h-5 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        <div class="text-xs">
                            <p class="font-bold text-slate-800 mb-0.5">Informasi Evaluasi Finansial</p>
                            <p class="text-slate-500">{{ $financialSummary['message'] }}</p>
                        </div>
                    </div>
                @endif
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-between">
                <span class="text-[11px] font-medium text-slate-400">Algoritma Cash Flow Kaching</span>
                <button type="button" @click="openCopilotPrompt('financial_analysis')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs shadow-sm transition">
                    <span>Konsultasi Arus Kas</span>
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- 3. RAW MATERIAL INTELLIGENCE CARD -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm flex flex-col justify-between hover:border-slate-300 transition">
            <div>
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-black text-slate-900">Raw Material Intelligence</h2>
                            <p class="text-xs text-slate-500 font-medium">Monitoring tren harga komoditas dan dampak margin HPP.</p>
                        </div>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-800 border border-emerald-200">
                        Pasar & HPP
                    </span>
                </div>

                <div class="my-4 space-y-3">
                    <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200/70">
                        <p class="text-[11px] text-slate-700 font-semibold leading-relaxed">
                            {{ $rawMaterialSummary['headline_impact'] }}
                        </p>
                    </div>

                    <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-50 border border-amber-200/70 w-fit">
                        <svg class="w-3.5 h-3.5 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="text-[10px] font-bold text-amber-700 uppercase tracking-wide">Dataset Sampel Acuan</span>
                    </div>

                    <div class="space-y-1.5">
                        @foreach($rawMaterialSummary['top_commodities'] as $com)
                            <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-white border border-slate-100 text-xs">
                                <span class="font-bold text-slate-800">{{ $com['name'] }}</span>
                                <div class="flex items-center gap-2">
                                    <span class="text-slate-600 font-medium">Rp {{ number_format($com['price'], 0, ',', '.') }} / {{ $com['unit'] }}</span>
                                    @if($com['change'] > 0)
                                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-rose-50 text-rose-700 border border-rose-200">
                                            +{{ $com['change'] }}%
                                        </span>
                                    @else
                                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 border border-slate-200">
                                            Stabil
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-between">
                <span class="text-[11px] font-medium text-slate-400">Data Komoditas Nasional</span>
                @if($rawMaterialSummary['is_testing_user'])
                    <a href="{{ $rawMaterialSummary['cta_url'] }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-sm transition">
                        <span>Lihat Raw Material Intelligence</span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                @else
                    <a href="{{ $rawMaterialSummary['cta_url'] }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs shadow-sm transition">
                        <span>Lihat Raw Material Intelligence</span>
                        <span class="text-[9px] bg-emerald-100 text-emerald-800 px-1.5 py-0.5 rounded font-bold uppercase">Preview</span>
                    </a>
                @endif
            </div>
        </div>

        <!-- 4. FINANCING READINESS CARD -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm flex flex-col justify-between hover:border-slate-300 transition">
            <div>
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-purple-50 border border-purple-100 flex items-center justify-center text-purple-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-black text-slate-900">Financing Readiness</h2>
                            <p class="text-xs text-slate-500 font-medium">Evaluasi kesiapan kelayakan permodalan dan pembukuan usaha.</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-purple-50 text-purple-700 border border-purple-200">
                            {{ $financingSummary['band'] }}
                        </span>
                        <a href="{{ route('copilot.financing-readiness.export') }}" target="_blank" title="Unduh Berkas Bukti Finansial (PDF)" class="p-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-600 transition inline-flex items-center justify-center">
                            <svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                            </svg>
                        </a>
                    </div>
                </div>

                @if($financingSummary['state'] === 'SUCCESS')
                    <div class="my-4 space-y-4">
                        <!-- SCORE & PERIOD BANNER -->
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 rounded-xl bg-slate-50 border border-slate-200/70">
                            <div>
                                <p class="text-[11px] text-slate-500 font-semibold">Skor Kesiapan Pembukuan</p>
                                <div class="flex items-baseline gap-1.5 mt-0.5">
                                    <span class="text-2xl font-black text-slate-900">{{ $financingSummary['score'] ?? '-' }}</span>
                                    <span class="text-xs font-normal text-slate-400">/ 100</span>
                                </div>
                            </div>
                            <div class="text-left sm:text-right">
                                <span class="text-[10px] font-bold px-2.5 py-1 rounded-lg bg-white border border-slate-200 text-slate-700 inline-block">
                                    5 Pilar Evaluasi Finansial
                                </span>
                                @if(!empty($financingSummary['evaluation_period']['start_date']))
                                    <p class="text-[10px] text-slate-400 mt-1 font-mono">
                                        Periode: {{ $financingSummary['evaluation_period']['start_date'] }} s/d {{ $financingSummary['evaluation_period']['end_date'] }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        <!-- 5 PILAR BREAKDOWN -->
                        @if(!empty($financingSummary['pillars']))
                            <div class="space-y-1.5">
                                <p class="text-[11px] font-bold text-slate-700 tracking-wide uppercase">Skor per Pilar Operasional</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    @foreach($financingSummary['pillars'] as $pKey => $pillar)
                                        <div class="p-2.5 rounded-xl bg-white border border-slate-200/70 text-xs flex items-center justify-between">
                                            <div>
                                                <p class="font-bold text-slate-800 capitalize">{{ str_replace('_', ' ', $pKey) }}</p>
                                                <p class="text-[10px] text-slate-400 font-mono">Bobot: {{ round(($pillar['weight'] ?? 0) * 100) }}%</p>
                                            </div>
                                            <div class="text-right">
                                                <span class="text-sm font-black text-purple-950">{{ $pillar['score'] ?? 0 }}</span>
                                                <span class="text-[10px] text-slate-400">/100</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <!-- FINANCIAL EVIDENCE COVERAGE CHECKLIST -->
                        @if(!empty($financingSummary['evidence_summary']))
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <p class="text-[11px] font-bold text-slate-700 tracking-wide uppercase">Cakupan Bukti Finansial (Evidence Coverage)</p>
                                    <span class="text-[10px] text-slate-400 font-medium">Terverifikasi Database POS</span>
                                </div>
                                <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                                    @foreach($financingSummary['evidence_summary'] as $evidence)
                                        <div class="p-3 rounded-xl bg-white border border-slate-200/80 text-xs space-y-1 hover:border-slate-300 transition">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="font-bold text-slate-900">{{ $evidence['label'] ?? '' }}</span>
                                                @php
                                                    $st = $evidence['status'] ?? 'INSUFFICIENT';
                                                    $badgeClass = $st === 'AVAILABLE' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($st === 'PARTIAL' ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-slate-100 text-slate-600 border-slate-200');
                                                @endphp
                                                <span class="text-[9px] font-bold px-2 py-0.5 rounded-full border {{ $badgeClass }}">
                                                    {{ $st }}
                                                </span>
                                            </div>
                                            <p class="text-[11px] text-slate-700 font-medium">{{ $evidence['summary'] ?? '' }}</p>
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[10px] text-slate-400 pt-0.5 border-t border-slate-100">
                                                <span>Sumber: <span class="font-mono text-slate-600">{{ $evidence['source'] ?? '' }}</span></span>
                                                <span>Periode: <span class="font-mono text-slate-600">{{ $evidence['period'] ?? '' }}</span></span>
                                            </div>
                                            <p class="text-[10px] text-slate-500 italic">{{ $evidence['explainability'] ?? '' }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <!-- LIMITATIONS / CATATAN AUDIT -->
                        @if(!empty($financingSummary['limitations']))
                            <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs space-y-1">
                                <p class="text-[10px] font-bold text-slate-600 uppercase tracking-wider">Catatan Keterbatasan Data</p>
                                <ul class="list-disc list-inside text-[11px] text-slate-600 space-y-0.5">
                                    @foreach($financingSummary['limitations'] as $limitation)
                                        <li>{{ $limitation }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <!-- DISCLAIMER BOX (MANDATORY) -->
                        <div class="p-3 rounded-xl bg-amber-50/60 border border-amber-200/70 text-[11px] text-amber-900 flex items-start gap-2">
                            <svg class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                            <p class="leading-relaxed">{{ $financingSummary['disclaimer'] }}</p>
                        </div>
                    </div>
                @else
                    <div class="my-4 space-y-3">
                        <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 flex items-start gap-3">
                            <svg class="w-5 h-5 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div class="text-xs">
                                <p class="font-bold text-slate-800 mb-0.5">Penilaian Membutuhkan Konsistensi</p>
                                <p class="text-slate-500">{{ $financingSummary['message'] }}</p>
                            </div>
                        </div>

                        <!-- DISCLAIMER BOX (MANDATORY) -->
                        <div class="p-3 rounded-xl bg-amber-50/60 border border-amber-200/70 text-[11px] text-amber-900 flex items-start gap-2">
                            <svg class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                            <p class="leading-relaxed">{{ $financingSummary['disclaimer'] }}</p>
                        </div>
                    </div>
                @endif
            </div>

            <div class="pt-4 border-t border-slate-100 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2.5">
                <span class="text-[11px] font-medium text-slate-400">Kerangka Audit Finansial</span>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('copilot.financing-readiness.export') }}" target="_blank" class="inline-flex items-center justify-center gap-1.5 px-3.5 py-2 rounded-xl bg-purple-900 hover:bg-purple-950 text-white font-bold text-xs shadow-sm transition">
                        <svg class="w-3.5 h-3.5 text-purple-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span>Ekspor Bukti Finansial (PDF)</span>
                    </a>
                    <button type="button" @click="openCopilotPrompt('financing_readiness')" class="inline-flex items-center justify-center gap-1.5 px-3.5 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs shadow-sm transition">
                        <span>Tanya Copilot</span>
                        <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- 5. DEMAND FORECAST INTELLIGENCE CARD -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm flex flex-col justify-between hover:border-slate-300 transition">
            <div>
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-black text-slate-900">Demand Forecast</h2>
                            <p class="text-xs text-slate-500 font-medium">Prediksi kebutuhan menu harian berbasis model statistik.</p>
                        </div>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                        {{ ($forecastSummary['state'] ?? '') === 'SUCCESS' ? 'Tersedia' : 'Menunggu Data' }}
                    </span>
                </div>

                @if(($forecastSummary['state'] ?? '') === 'SUCCESS')
                    <div class="my-4 space-y-3">
                        <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200/70 flex items-center justify-between">
                            <div>
                                <p class="text-[11px] text-slate-500 font-semibold">Total Prediksi Tercatat</p>
                                <p class="text-xl font-black text-slate-900">{{ $forecastSummary['total_forecasts'] ?? 0 }} Record</p>
                            </div>
                            <span class="text-[10px] font-bold px-2 py-1 rounded-lg bg-indigo-50 text-indigo-700 border border-indigo-200">
                                80% Conf. Interval (P10–P90)
                            </span>
                        </div>

                        @if(!empty($forecastSummary['top_demanded_items']))
                        <div class="space-y-1.5">
                            <p class="text-[11px] font-bold text-slate-700 tracking-wide uppercase">Estimasi Permintaan Tertinggi</p>
                            @foreach($forecastSummary['top_demanded_items'] as $item)
                                <div class="p-2.5 rounded-xl bg-white border border-slate-200/70 text-xs flex items-center justify-between">
                                    <div>
                                        <p class="font-bold text-slate-800">{{ $item['name'] }}</p>
                                        <p class="text-[10px] text-slate-400">Target: {{ $item['target_date'] }}</p>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-sm font-black text-indigo-950">{{ $item['predicted_quantity'] % 1 === 0 ? $item['predicted_quantity'] : number_format($item['predicted_quantity'], 1) }}</span>
                                        <span class="text-[10px] text-slate-500 font-bold">{{ $item['uom'] }}</span>
                                        <p class="text-[9px] text-slate-400 font-mono">P10-P90: {{ $item['p10'] }}-{{ $item['p90'] }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @endif

                        <div class="p-3 rounded-xl bg-amber-50/60 border border-amber-200/70 text-[11px] text-amber-900 flex items-start gap-2">
                            <svg class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <p class="leading-relaxed">{{ $forecastSummary['disclaimer'] ?? 'Prediksi permintaan berbasis model statistik historis.' }}</p>
                        </div>
                    </div>
                @else
                    <div class="my-4 space-y-3">
                        <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 flex items-start gap-3">
                            <svg class="w-5 h-5 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div class="text-xs">
                                <p class="font-bold text-slate-800 mb-0.5">Belum Ada Data Prediksi</p>
                                <p class="text-slate-500">Jalankan proses peramalan atau buka halaman demand forecast untuk meninjau proyeksi kebutuhan cabang.</p>
                            </div>
                        </div>

                        <div class="p-3 rounded-xl bg-amber-50/60 border border-amber-200/70 text-[11px] text-amber-900 flex items-start gap-2">
                            <svg class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <p class="leading-relaxed">{{ $forecastSummary['disclaimer'] ?? 'Prediksi permintaan berbasis model statistik historis.' }}</p>
                        </div>
                    </div>
                @endif
            </div>

            <div class="pt-4 border-t border-slate-100 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2.5">
                <span class="text-[11px] font-medium text-slate-400">Model Peramalan Native</span>
                <a href="{{ route('copilot.intelligence.demand-forecast') }}" class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-sm transition">
                    <span>Buka Demand Forecast</span>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path>
                    </svg>
                </a>
            </div>
        </div>

    </div>

    <!-- 5. AI COPILOT SECTION (TANYA KACHI) -->
    <div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 rounded-2xl p-6 md:p-8 text-white shadow-md relative overflow-hidden">
        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="max-w-xl">
                <div class="flex items-center gap-2.5 mb-2">
                    <div class="w-7 h-7 rounded-lg bg-indigo-500/20 border border-indigo-400/30 flex items-center justify-center text-indigo-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                        </svg>
                    </div>
                    <span class="text-xs font-bold tracking-wider uppercase text-indigo-300">AI Copilot</span>
                </div>
                <h3 class="text-xl md:text-2xl font-black text-white tracking-tight">Kachi Business Advisor</h3>
                <p class="text-xs md:text-sm text-slate-300 font-normal mt-1 leading-relaxed">
                    Gunakan Kachi untuk memahami kondisi bisnis, mengeksplorasi risiko, dan melakukan simulasi keputusan.
                </p>
            </div>

            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                <button type="button" @click="openCopilotPrompt('general')" class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-indigo-500 hover:bg-indigo-600 text-white font-bold text-xs shadow-lg transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                    </svg>
                    <span>Tanya Kachi</span>
                </button>
            </div>
        </div>

        <!-- QUICK PROMPTS BADGES -->
        <div class="mt-6 pt-6 border-t border-white/10 flex flex-wrap gap-2 text-xs">
            <span class="text-[11px] font-semibold text-slate-400 py-1">Topik Cepat:</span>
            <button type="button" @click="openCopilotPrompt('menu_analysis')" class="px-3 py-1 rounded-lg bg-white/10 hover:bg-white/20 text-slate-200 font-medium text-[11px] transition">
                Analisis Menu Puzzle
            </button>
            <button type="button" @click="openCopilotPrompt('financial_analysis')" class="px-3 py-1 rounded-lg bg-white/10 hover:bg-white/20 text-slate-200 font-medium text-[11px] transition">
                Proyeksi Arus Kas 7 Hari
            </button>
            <button type="button" @click="openCopilotPrompt('raw_material_intelligence')" class="px-3 py-1 rounded-lg bg-white/10 hover:bg-white/20 text-slate-200 font-medium text-[11px] transition">
                Dampak Margin Komoditas
            </button>
            <button type="button" @click="openCopilotPrompt('financing_readiness')" class="px-3 py-1 rounded-lg bg-white/10 hover:bg-white/20 text-slate-200 font-medium text-[11px] transition">
                Evaluasi Kesiapan Kredit
            </button>
        </div>
    </div>

    <!-- COPILOT MODAL DIALOG -->
    <div x-show="modalOpen" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" x-cloak>
        <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-200" @click.outside="modalOpen = false">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                        </svg>
                    </div>
                    <h4 class="text-base font-bold text-slate-900">Tanya Kachi Copilot</h4>
                </div>
                <button type="button" @click="modalOpen = false" class="text-slate-400 hover:text-slate-600 p-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="py-4 space-y-3">
                <p class="text-xs text-slate-500 font-medium">
                    Konteks Analisis: <span class="font-bold text-slate-700" x-text="currentContextLabel"></span>
                </p>
                <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200">
                    <p class="text-xs text-slate-700 font-mono" x-text="currentPromptText"></p>
                </div>
                <p class="text-[11px] text-slate-400">
                    Prompt rekomendasi di atas dapat disalin untuk konsultasi langsung via Kachi Copilot pada aplikasi Android atau Web POS.
                </p>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" @click="copyPrompt()" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-sm transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    <span x-text="copied ? 'Tersalin!' : 'Salin Pertanyaan'"></span>
                </button>
                <button type="button" @click="modalOpen = false" class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-slate-700 font-bold text-xs hover:bg-slate-50 transition">
                    Tutup
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function kachingIntelligenceHub() {
    return {
        modalOpen: false,
        copied: false,
        currentContext: 'general',
        currentContextLabel: 'Umum',
        currentPromptText: 'Analisis kondisi bisnis toko saya bulan ini dan berikan rekomendasi aksi strategis.',

        prompts: {
            'general': {
                label: 'Bisnis & Operasional Umum',
                text: 'Analisis performa bisnis toko saya bulan ini dan berikan 3 langkah prioritas untuk memaksimalkan laba operasional.'
            },
            'menu_analysis': {
                label: 'Menu Intelligence (BCG & SAW)',
                text: 'Berdasarkan evaluasi BCG Matrix dan metode SAW 30 hari terakhir, bagaimana strategi terbaik untuk mendongkrak penjualan menu kategori Puzzle dan meminimalkan kerugian menu Dog?'
            },
            'financial_analysis': {
                label: 'Financial Intelligence & Likuiditas',
                text: 'Evaluasi rasio arus kas toko saya dan jelaskan apakah ada risiko defisit kas dalam proyeksi 7 hari ke depan.'
            },
            'raw_material_intelligence': {
                label: 'Raw Material Intelligence & HPP',
                text: 'Bagaimana tren kenaikan harga komoditas pangan saat ini berdampak terhadap HPP dan marjin keuntungan menu utama saya?'
            },
            'financing_readiness': {
                label: 'Financing Readiness',
                text: 'Jelaskan pilar pembukuan dan operasional mana yang perlu saya tingkatkan agar memenuhi standar kelayakan permodalan usaha.'
            }
        },

        openCopilotPrompt(context) {
            this.currentContext = context;
            this.copied = false;
            if (this.prompts[context]) {
                this.currentContextLabel = this.prompts[context].label;
                this.currentPromptText = this.prompts[context].text;
            } else {
                this.currentContextLabel = 'Umum';
                this.currentPromptText = this.prompts['general'].text;
            }
            this.modalOpen = true;
        },

        copyPrompt() {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(this.currentPromptText).then(() => {
                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 2000);
                });
            } else {
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2000);
            }
        }
    };
}
</script>
@endsection
