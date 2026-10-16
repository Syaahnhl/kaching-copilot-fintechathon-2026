@extends('backoffice.master')

@section('title', 'Menu Intelligence — BCG Matrix & SAW')

@section('content')
<div class="space-y-8 pb-12" x-data="menuIntelligenceDetail()">
    <!-- PAGE HEADER -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-slate-200/80 pb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('copilot.intelligence') }}" class="w-10 h-10 rounded-xl bg-white border border-slate-200 flex items-center justify-center text-slate-500 hover:text-slate-800 hover:bg-slate-50 shadow-sm transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
            </a>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">Menu Intelligence</h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                        BCG & SAW
                    </span>
                </div>
                <p class="text-xs md:text-sm text-slate-500 font-medium mt-0.5">Analisis BCG Matrix dan SAW</p>
            </div>
        </div>

        <!-- PERIOD FILTER BUTTONS (NO HARI INI, NO SHIFT) -->
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('copilot.intelligence.menu', ['period' => '7days']) }}"
               class="px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm {{ $period === '7days' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">
                7 Hari
            </a>
            <a href="{{ route('copilot.intelligence.menu', ['period' => '30days']) }}"
               class="px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm {{ $period === '30days' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">
                30 Hari (Default)
            </a>
            <a href="{{ route('copilot.intelligence.menu', ['period' => '90days']) }}"
               class="px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm {{ $period === '90days' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">
                90 Hari
            </a>
            <a href="{{ route('copilot.intelligence.menu', ['period' => 'year']) }}"
               class="px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm {{ $period === 'year' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">
                Tahun Ini
            </a>
        </div>
    </div>

    <!-- INFORMATIVE NOTICE: MINIMAL 7 HARI DATA -->
    <div class="p-4 rounded-2xl bg-indigo-50/60 border border-indigo-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs text-indigo-900">
        <div class="flex items-center gap-2.5">
            <div class="w-7 h-7 rounded-lg bg-indigo-100 flex items-center justify-center text-indigo-700 shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <div>
                <span class="font-bold">Ketentuan Analisis Data:</span>
                <span class="text-indigo-800">Analisis menu membutuhkan minimal 7 hari data transaksi. Filter Shift dan Hari Ini ditiadakan demi akurasi bobot SAW.</span>
            </div>
        </div>
        <div class="font-mono text-[11px] font-bold text-indigo-700 bg-white/80 px-2.5 py-1 rounded-lg border border-indigo-200 shrink-0">
            Rentang: {{ $periodDates['start_date'] }} s/d {{ $periodDates['end_date'] }}
        </div>
    </div>

    @if($invalidPeriodRejected)
        <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-start gap-2.5">
            <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
            </svg>
            <div>
                <p class="font-bold">Filter 'Hari Ini' atau 'Shift' Tidak Didukung</p>
                <p class="text-amber-700 mt-0.5">Analisis BCG dan SAW memerlukan rentang historis minimum 7 hari data transaksi untuk mengukur volume dan profit. Sistem secara otomatis menerapkan periode 30 Hari Terakhir.</p>
            </div>
        </div>
    @endif

    <!-- SECTION: BCG MATRIX 2x2 -->
    <div>
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-lg font-black text-slate-900">BCG Matrix</h2>
                <p class="text-xs text-slate-500 font-medium">Pemetaan kuadran produk berdasarkan volume penjualan dan marjin kontribusi laba.</p>
            </div>
            <button type="button" @click="openCopilotPrompt()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs shadow-sm transition">
                <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                </svg>
                <span>Tanya Kachi tentang Analisis Menu</span>
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- QUADRANT 1: STAR (TOP-LEFT) -->
            <div class="bg-white border-2 border-emerald-200/90 rounded-2xl p-6 shadow-sm flex flex-col justify-between">
                <div>
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <div>
                            <span class="px-2.5 py-1 rounded-full text-xs font-black bg-emerald-50 text-emerald-800 border border-emerald-200">
                                STAR
                            </span>
                            <h3 class="text-base font-extrabold text-slate-900 mt-2">Bintang Penjualan</h3>
                            <p class="text-xs text-slate-500 font-medium">{{ $bcgQuadrants['STAR']['description'] }}</p>
                        </div>
                        <div class="text-right">
                            <span class="text-2xl font-black text-emerald-700">{{ $bcgQuadrants['STAR']['count'] }}</span>
                            <span class="text-xs text-slate-400 block font-semibold">Menu</span>
                        </div>
                    </div>

                    <p class="text-xs text-slate-600 mb-4 bg-emerald-50/40 p-3 rounded-xl border border-emerald-100/60 leading-relaxed">
                        {{ $bcgQuadrants['STAR']['recommendation'] }}
                    </p>

                    @if(!empty($bcgQuadrants['STAR']['items']))
                        <div class="space-y-1.5 border-t border-slate-100 pt-3">
                            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Menu Utama:</span>
                            @foreach(array_slice($bcgQuadrants['STAR']['items'], 0, 4) as $item)
                                <div class="flex items-center justify-between text-xs py-1">
                                    <span class="font-bold text-slate-800 truncate mr-2">{{ $item['name'] }}</span>
                                    <span class="text-slate-500 font-mono text-[11px] shrink-0">Skor: {{ number_format($item['score'] ?? 0, 3) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-slate-400 italic py-2">Belum ada menu di kuadran ini.</p>
                    @endif
                </div>
            </div>

            <!-- QUADRANT 2: PUZZLE (TOP-RIGHT) -->
            <div class="bg-white border-2 border-amber-200/90 rounded-2xl p-6 shadow-sm flex flex-col justify-between">
                <div>
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <div>
                            <span class="px-2.5 py-1 rounded-full text-xs font-black bg-amber-50 text-amber-800 border border-amber-200">
                                PUZZLE
                            </span>
                            <h3 class="text-base font-extrabold text-slate-900 mt-2">Peluang Terselubung</h3>
                            <p class="text-xs text-slate-500 font-medium">{{ $bcgQuadrants['PUZZLE']['description'] }}</p>
                        </div>
                        <div class="text-right">
                            <span class="text-2xl font-black text-amber-700">{{ $bcgQuadrants['PUZZLE']['count'] }}</span>
                            <span class="text-xs text-slate-400 block font-semibold">Menu</span>
                        </div>
                    </div>

                    <p class="text-xs text-slate-600 mb-4 bg-amber-50/40 p-3 rounded-xl border border-amber-100/60 leading-relaxed">
                        {{ $bcgQuadrants['PUZZLE']['recommendation'] }}
                    </p>

                    @if(!empty($bcgQuadrants['PUZZLE']['items']))
                        <div class="space-y-1.5 border-t border-slate-100 pt-3">
                            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Menu Utama:</span>
                            @foreach(array_slice($bcgQuadrants['PUZZLE']['items'], 0, 4) as $item)
                                <div class="flex items-center justify-between text-xs py-1">
                                    <span class="font-bold text-slate-800 truncate mr-2">{{ $item['name'] }}</span>
                                    <span class="text-slate-500 font-mono text-[11px] shrink-0">Skor: {{ number_format($item['score'] ?? 0, 3) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-slate-400 italic py-2">Belum ada menu di kuadran ini.</p>
                    @endif
                </div>
            </div>

            <!-- QUADRANT 3: CASH COW (BOTTOM-LEFT) -->
            <div class="bg-white border-2 border-blue-200/90 rounded-2xl p-6 shadow-sm flex flex-col justify-between">
                <div>
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <div>
                            <span class="px-2.5 py-1 rounded-full text-xs font-black bg-blue-50 text-blue-800 border border-blue-200">
                                CASH COW
                            </span>
                            <h3 class="text-base font-extrabold text-slate-900 mt-2">Penopang Arus Kas</h3>
                            <p class="text-xs text-slate-500 font-medium">{{ $bcgQuadrants['CASH_COW']['description'] }}</p>
                        </div>
                        <div class="text-right">
                            <span class="text-2xl font-black text-blue-700">{{ $bcgQuadrants['CASH_COW']['count'] }}</span>
                            <span class="text-xs text-slate-400 block font-semibold">Menu</span>
                        </div>
                    </div>

                    <p class="text-xs text-slate-600 mb-4 bg-blue-50/40 p-3 rounded-xl border border-blue-100/60 leading-relaxed">
                        {{ $bcgQuadrants['CASH_COW']['recommendation'] }}
                    </p>

                    @if(!empty($bcgQuadrants['CASH_COW']['items']))
                        <div class="space-y-1.5 border-t border-slate-100 pt-3">
                            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Menu Utama:</span>
                            @foreach(array_slice($bcgQuadrants['CASH_COW']['items'], 0, 4) as $item)
                                <div class="flex items-center justify-between text-xs py-1">
                                    <span class="font-bold text-slate-800 truncate mr-2">{{ $item['name'] }}</span>
                                    <span class="text-slate-500 font-mono text-[11px] shrink-0">Skor: {{ number_format($item['score'] ?? 0, 3) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-slate-400 italic py-2">Belum ada menu di kuadran ini.</p>
                    @endif
                </div>
            </div>

            <!-- QUADRANT 4: DOG (BOTTOM-RIGHT) -->
            <div class="bg-white border-2 border-rose-200/90 rounded-2xl p-6 shadow-sm flex flex-col justify-between">
                <div>
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <div>
                            <span class="px-2.5 py-1 rounded-full text-xs font-black bg-rose-50 text-rose-800 border border-rose-200">
                                DOG
                            </span>
                            <h3 class="text-base font-extrabold text-slate-900 mt-2">Beban Operasional</h3>
                            <p class="text-xs text-slate-500 font-medium">{{ $bcgQuadrants['DOG']['description'] }}</p>
                        </div>
                        <div class="text-right">
                            <span class="text-2xl font-black text-rose-700">{{ $bcgQuadrants['DOG']['count'] }}</span>
                            <span class="text-xs text-slate-400 block font-semibold">Menu</span>
                        </div>
                    </div>

                    <p class="text-xs text-slate-600 mb-4 bg-rose-50/40 p-3 rounded-xl border border-rose-100/60 leading-relaxed">
                        {{ $bcgQuadrants['DOG']['recommendation'] }}
                    </p>

                    @if(!empty($bcgQuadrants['DOG']['items']))
                        <div class="space-y-1.5 border-t border-slate-100 pt-3">
                            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Menu Utama:</span>
                            @foreach(array_slice($bcgQuadrants['DOG']['items'], 0, 4) as $item)
                                <div class="flex items-center justify-between text-xs py-1">
                                    <span class="font-bold text-slate-800 truncate mr-2">{{ $item['name'] }}</span>
                                    <span class="text-slate-500 font-mono text-[11px] shrink-0">Skor: {{ number_format($item['score'] ?? 0, 3) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-slate-400 italic py-2">Belum ada menu di kuadran ini.</p>
                    @endif
                </div>
            </div>

        </div>
    </div>

    <!-- SECTION: SAW RECOMMENDATIONS TABLE -->
    <div class="bg-white border border-slate-200/80 rounded-2xl shadow-sm overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-black text-slate-900">SAW Recommendations</h2>
                <p class="text-xs text-slate-500 font-medium">Pemeringkatan menu terintegrasi metode Simple Additive Weighting (SAW).</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs text-slate-500 font-semibold">Filter Kategori:</span>
                <select x-model="categoryFilter" class="text-xs font-bold rounded-xl border-slate-200 bg-slate-50 px-3 py-1.5 focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="ALL">Semua Kategori</option>
                    <option value="STAR">Hanya STAR</option>
                    <option value="PUZZLE">Hanya PUZZLE</option>
                    <option value="CASH_COW">Hanya CASH COW</option>
                    <option value="DOG">Hanya DOG</option>
                </select>
            </div>
        </div>

        @if($state === 'SUCCESS' && count($menuItems) > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200/80 text-[11px] font-extrabold uppercase tracking-wider text-slate-500">
                            <th class="py-3.5 px-4 text-center w-16">Rank</th>
                            <th class="py-3.5 px-4">Menu</th>
                            <th class="py-3.5 px-4">Kategori BCG</th>
                            <th class="py-3.5 px-4 text-right">Volume Jual</th>
                            <th class="py-3.5 px-4 text-right">Laba Kotor</th>
                            <th class="py-3.5 px-4 text-center">Skor SAW</th>
                            <th class="py-3.5 px-4">Rekomendasi Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs">
                        @foreach($menuItems as $index => $item)
                            @php
                                $cat = strtoupper($item['category'] ?? '');
                                $badgeClass = match($cat) {
                                    'STAR' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
                                    'PUZZLE' => 'bg-amber-50 text-amber-800 border-amber-200',
                                    'CASH_COW' => 'bg-blue-50 text-blue-800 border-blue-200',
                                    'DOG' => 'bg-rose-50 text-rose-800 border-rose-200',
                                    default => 'bg-slate-100 text-slate-700 border-slate-200',
                                };
                            @endphp
                            <tr class="hover:bg-slate-50/60 transition" x-show="categoryFilter === 'ALL' || categoryFilter === '{{ $cat }}'">
                                <td class="py-3 px-4 text-center font-black text-slate-700 font-mono">
                                    #{{ $index + 1 }}
                                </td>
                                <td class="py-3 px-4 font-bold text-slate-900">
                                    {{ $item['name'] }}
                                </td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black border {{ $badgeClass }}">
                                        {{ str_replace('_', ' ', $cat) }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right font-mono font-medium text-slate-700">
                                    {{ number_format($item['stats']['sales_count'] ?? 0, 0, ',', '.') }} porsi
                                </td>
                                <td class="py-3 px-4 text-right font-mono font-bold text-slate-900">
                                    Rp {{ number_format($item['stats']['gross_profit'] ?? 0, 0, ',', '.') }}
                                </td>
                                <td class="py-3 px-4 text-center font-mono font-extrabold text-indigo-700">
                                    {{ number_format($item['score'] ?? 0, 4) }}
                                </td>
                                <td class="py-3 px-4 text-slate-600 text-[11px] leading-relaxed">
                                    {{ $item['action'] ?? '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <!-- INSUFFICIENT DATA / EMPTY STATE -->
            <div class="p-12 text-center space-y-3">
                <div class="w-12 h-12 rounded-2xl bg-amber-50 border border-amber-200 text-amber-600 flex items-center justify-center mx-auto shadow-sm">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <h3 class="text-base font-bold text-slate-900">Data Transaksi Belum Cukup</h3>
                <p class="text-xs text-slate-500 max-w-md mx-auto leading-relaxed">
                    Data belum cukup untuk menghasilkan analisis. Analisis menu membutuhkan minimal 7 hari data transaksi. Silakan pilih periode yang lebih panjang.
                </p>
                <div class="pt-2">
                    <a href="{{ route('copilot.intelligence.menu', ['period' => '90days']) }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-sm transition">
                        <span>Coba Periode 90 Hari</span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
            </div>
        @endif
    </div>

    <!-- AI COPILOT DIALOG MODAL -->
    <div x-show="modalOpen" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4" x-cloak>
        <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-200" @click.outside="modalOpen = false">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                        </svg>
                    </div>
                    <h4 class="text-base font-bold text-slate-900">Tanya Kachi — Analisis Menu</h4>
                </div>
                <button type="button" @click="modalOpen = false" class="text-slate-400 hover:text-slate-600 p-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="py-4 space-y-3">
                <p class="text-xs text-slate-500 font-medium">
                    Konteks: <span class="font-bold text-slate-700">menu_analysis ({{ $periodDates['label'] }})</span>
                </p>
                <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200">
                    <p class="text-xs text-slate-700 font-mono" x-text="promptText"></p>
                </div>
                <p class="text-[11px] text-slate-400">
                    Salin prompt rekomendasi di atas untuk berdiskusi dengan AI Copilot pada Android atau WhatsApp.
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
function menuIntelligenceDetail() {
    return {
        categoryFilter: 'ALL',
        modalOpen: false,
        copied: false,
        promptText: 'Analisis hasil evaluasi BCG Matrix dan SAW menu saya untuk periode {{ $periodDates['label'] }}. Berikan rekomendasi konkret untuk menu kategori Puzzle dan langkah efisiensi untuk menu kategori Dog.',

        openCopilotPrompt() {
            this.copied = false;
            this.modalOpen = true;
        },

        copyPrompt() {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(this.promptText).then(() => {
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
