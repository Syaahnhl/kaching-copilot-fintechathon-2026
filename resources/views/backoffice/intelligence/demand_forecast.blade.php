@extends('backoffice.master')

@section('title', 'Kaching Demand Forecast')

@section('content')
<div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto" x-data="demandForecastApp()">

    <!-- HEADER -->
    <div class="mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <a href="{{ route('copilot.intelligence') }}" class="text-xs font-bold text-slate-500 hover:text-slate-800 transition flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"></path>
                        </svg>
                        <span>Kaching Intelligence</span>
                    </a>
                    <span class="text-slate-300">/</span>
                    <span class="text-xs font-semibold text-slate-400">Demand Forecast</span>
                </div>
                <h1 class="text-2xl font-black text-slate-900 tracking-tight">Prediksi Permintaan Menu (Demand Forecast)</h1>
                <p class="text-sm text-slate-500 mt-1 font-medium">Estimasi kuantitas permintaan menu berdasarkan tren historis dan model statistik native.</p>
            </div>

            <!-- OUTLET SELECTOR & CONTROLS HEADER -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200/70">
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                    <span>Read-Only API (Non-Mutasi)</span>
                </div>

                @if($outlets->count() > 1)
                <select x-model="selectedOutletId" @change="onOutletChange()" class="text-xs font-bold bg-white border border-slate-200 rounded-xl px-3 py-2 text-slate-700 shadow-sm focus:ring-2 focus:ring-slate-900 focus:outline-none">
                    @foreach($outlets as $outlet)
                    <option value="{{ $outlet->id }}" {{ $activeOutlet && $activeOutlet->id == $outlet->id ? 'selected' : '' }}>
                        {{ $outlet->name }}
                    </option>
                    @endforeach
                </select>
                @elseif($activeOutlet)
                <span class="text-xs font-bold bg-white border border-slate-200 rounded-xl px-3 py-2 text-slate-700 shadow-sm">
                    Outlet: {{ $activeOutlet->name }}
                </span>
                @endif
            </div>
        </div>
    </div>

    <!-- METODOLOGI & DISCLAIMER BANNER -->
    <div class="bg-amber-50/70 border border-amber-200/80 rounded-2xl p-4 mb-6 shadow-sm">
        <div class="flex items-start gap-3.5">
            <div class="w-8 h-8 rounded-xl bg-amber-100 border border-amber-200 flex items-center justify-center text-amber-700 shrink-0 mt-0.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <div class="text-xs text-amber-900 leading-relaxed">
                <div class="font-bold mb-0.5">Panduan Interpretasi Prediksi Permintaan (SSOT Analytics)</div>
                <div class="text-amber-800 space-y-1 text-[11px]">
                    <p>• <strong>Prediksi Permintaan (Bukan Penjualan Aktual):</strong> Angka ini merupakan estimasi kebutuhan porsi berdasarkan data transaksi masa lalu, pola musiman, dan algoritma peramalan.</p>
                    <p>• <strong>Interval Keyakinan (P10 – P90):</strong> Rentang estimasi statistik variasi permintaan (80% confidence interval). P10 adalah batas bawah skenario konservatif dan P90 adalah batas atas skenario puncak, bukan jaminan pencapaian.</p>
                    <p>• <strong>Non-Otomatisasi Stok:</strong> Data ini disajikan murni sebagai referensi perencanaan operasional dan evaluasi menu, tidak mengubah stok atau membuat pesanan pembelian secara otomatis.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ERROR & SESSION BANNER -->
    <template x-if="errorMessage">
        <div class="mb-6 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center justify-between gap-3 shadow-sm">
            <div class="flex items-center gap-2.5">
                <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <div>
                    <span class="font-bold" x-text="isSessionExpired ? 'Sesi Login Berakhir' : 'Gagal Memuat Prediksi:'"></span>
                    <span x-text="errorMessage" class="ml-1"></span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <template x-if="isSessionExpired">
                    <a href="{{ route('login') }}" class="px-3 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-sm transition">
                        Login Ulang
                    </a>
                </template>
                <button type="button" @click="fetchForecasts()" class="px-3 py-1.5 rounded-xl bg-white border border-rose-200 hover:bg-rose-100/50 text-rose-800 font-bold text-xs transition">
                    Coba Lagi
                </button>
            </div>
        </div>
    </template>

    <!-- FILTER CARD -->
    <div class="bg-white border border-slate-200/80 rounded-2xl p-5 mb-6 shadow-sm">
        <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
            
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 flex-1">
                <!-- TANGGAL MULAI -->
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Tanggal Mulai</label>
                    <input type="date" x-model="startDate" @change="validateAndFetch()" class="w-full text-xs font-semibold bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-800 focus:bg-white focus:ring-2 focus:ring-slate-900 focus:outline-none">
                </div>

                <!-- TANGGAL AKHIR -->
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Tanggal Akhir</label>
                    <input type="date" x-model="endDate" @change="validateAndFetch()" class="w-full text-xs font-semibold bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-800 focus:bg-white focus:ring-2 focus:ring-slate-900 focus:outline-none">
                </div>

                <!-- FILTER MENU -->
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Filter Menu</label>
                    <select x-model="selectedMenuId" @change="fetchForecasts(1)" class="w-full text-xs font-semibold bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-800 focus:bg-white focus:ring-2 focus:ring-slate-900 focus:outline-none">
                        <option value="">Semua Menu Cabang</option>
                        @foreach($menus as $m)
                        <option value="{{ $m->id }}">{{ $m->name }} ({{ $m->category ?? 'Umum' }})</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <!-- QUICK DATE PRESETS & ACTIONS -->
            <div class="flex flex-wrap items-center gap-2 pt-2 lg:pt-0 border-t lg:border-t-0 border-slate-100">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mr-1">Preset:</span>
                <button type="button" @click="setPresetDays(7)" :class="activePreset === 7 ? 'bg-slate-900 text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 font-semibold'" class="text-xs px-2.5 py-1.5 rounded-lg transition">
                    7 Hari
                </button>
                <button type="button" @click="setPresetDays(14)" :class="activePreset === 14 ? 'bg-slate-900 text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 font-semibold'" class="text-xs px-2.5 py-1.5 rounded-lg transition">
                    14 Hari
                </button>
                <button type="button" @click="setPresetDays(30)" :class="activePreset === 30 ? 'bg-slate-900 text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 font-semibold'" class="text-xs px-2.5 py-1.5 rounded-lg transition">
                    30 Hari
                </button>

                <div class="h-6 w-px bg-slate-200 mx-1 hidden sm:block"></div>

                <button type="button" @click="fetchForecasts(1)" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs shadow-sm transition">
                    <svg class="w-3.5 h-3.5" :class="isLoading ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span>Segarkan</span>
                </button>
            </div>

        </div>
    </div>

    <!-- SUMMARY METRICS BAR -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        
        <div class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-sm">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Total Record Prediksi</div>
            <div class="text-2xl font-black text-slate-900" x-text="pagination.total !== null ? pagination.total : '-'"></div>
            <div class="text-[11px] text-slate-500 mt-1 font-medium">Dalam cakupan filter aktif</div>
        </div>

        <div class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-sm">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Rentang Tanggal</div>
            <div class="text-sm font-bold text-slate-800 truncate" x-text="formatDateRange(startDate, endDate)"></div>
            <div class="text-[11px] text-slate-500 mt-1 font-medium" x-text="calculateDateDiff(startDate, endDate) + ' hari peramalan (Maks. 90 hari)'"></div>
        </div>

        <div class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-sm">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Model & Pipeline</div>
            <div class="text-sm font-bold text-indigo-700 flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                <span x-text="forecasts.length > 0 ? (forecasts[0].serving_origin || 'NATIVE_MODEL') : 'STANDBY'"></span>
            </div>
            <div class="text-[11px] text-slate-500 mt-1 font-medium" x-text="forecasts.length > 0 ? ('Versi: ' + (forecasts[0].model_version || 'v1')) : 'Menunggu data peramalan'"></div>
        </div>

        <div class="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-sm">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Status Kualitas Historis</div>
            <div class="text-sm font-bold text-emerald-700 flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                <span x-text="forecasts.length > 0 ? (forecasts[0].history_status || 'SUFFICIENT') : 'MENUNGGU'"></span>
            </div>
            <div class="text-[11px] text-slate-500 mt-1 font-medium">Integritas data transaksi historis</div>
        </div>

    </div>

    <!-- MAIN TABLE CARD -->
    <div class="bg-white border border-slate-200/80 rounded-2xl shadow-sm overflow-hidden mb-6">
        
        <!-- TABLE HEADER / TOOLBAR -->
        <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/50">
            <div>
                <h3 class="text-sm font-black text-slate-900">Tabel Rincian Prediksi Permintaan per Menu</h3>
                <p class="text-xs text-slate-500 font-medium">Daftar estimasi kuantitas kebutuhan per menu per tanggal target.</p>
            </div>

            <!-- PER PAGE CONTROL -->
            <div class="flex items-center gap-2">
                <span class="text-xs text-slate-500 font-semibold">Tampilkan:</span>
                <select x-model="perPage" @change="fetchForecasts(1)" class="text-xs font-bold bg-white border border-slate-200 rounded-lg px-2.5 py-1 text-slate-700 focus:outline-none">
                    <option value="10">10 / hal</option>
                    <option value="25">25 / hal</option>
                    <option value="50">50 / hal</option>
                    <option value="100">100 / hal</option>
                </select>
            </div>
        </div>

        <!-- TABLE CONTENT -->
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-600">
                <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500 border-b border-slate-100">
                    <tr>
                        <th class="px-6 py-3.5">Menu & Kategori</th>
                        <th class="px-6 py-3.5">Tanggal Target</th>
                        <th class="px-6 py-3.5 text-right">Prediksi Permintaan</th>
                        <th class="px-6 py-3.5 text-center">Rentang Keyakinan (P10 – P90)</th>
                        <th class="px-6 py-3.5 text-center">Status Data</th>
                        <th class="px-6 py-3.5 text-right">Waktu Model</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    
                    <!-- LOADING STATE (SKELETON) -->
                    <template x-if="isLoading">
                        <template x-for="i in 5" :key="i">
                            <tr class="animate-pulse">
                                <td class="px-6 py-4">
                                    <div class="h-3.5 bg-slate-200 rounded w-36 mb-1.5"></div>
                                    <div class="h-2.5 bg-slate-100 rounded w-20"></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="h-3.5 bg-slate-200 rounded w-24 mb-1"></div>
                                    <div class="h-2.5 bg-slate-100 rounded w-16"></div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="h-4 bg-slate-200 rounded w-16 ml-auto mb-1"></div>
                                    <div class="h-2.5 bg-slate-100 rounded w-10 ml-auto"></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="h-3.5 bg-slate-200 rounded w-32 mx-auto mb-1.5"></div>
                                    <div class="h-2 bg-slate-100 rounded-full w-28 mx-auto"></div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="h-5 bg-slate-200 rounded-full w-20 mx-auto"></div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="h-3 bg-slate-200 rounded w-24 ml-auto"></div>
                                </td>
                            </tr>
                        </template>
                    </template>

                    <!-- EMPTY STATE -->
                    <template x-if="!isLoading && forecasts.length === 0">
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <div class="w-12 h-12 rounded-2xl bg-slate-100 border border-slate-200/80 flex items-center justify-center text-slate-400 mx-auto mb-3">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                </div>
                                <h4 class="text-sm font-bold text-slate-800 mb-1">Belum Ada Data Prediksi Permintaan</h4>
                                <p class="text-xs text-slate-500 max-w-md mx-auto mb-4 leading-relaxed">
                                    Tidak ditemukan catatan peramalan untuk outlet dan rentang tanggal yang dipilih. Sesuaikan tanggal filter atau pastikan proses forecast engine telah dijalankan.
                                </p>
                                <button type="button" @click="setPresetDays(30)" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs transition">
                                    Lihat 30 Hari Ke Depan
                                </button>
                            </td>
                        </tr>
                    </template>

                    <!-- DATA ROWS -->
                    <template x-if="!isLoading && forecasts.length > 0">
                        <template x-for="item in forecasts" :key="item.id">
                            <tr class="hover:bg-slate-50/70 transition">
                                
                                <!-- MENU & KATEGORI -->
                                <td class="px-6 py-4">
                                    <div class="font-extrabold text-slate-900" x-text="item.menu_name || ('Menu #' + item.menu_id)"></div>
                                    <div class="text-[11px] text-slate-400 flex items-center gap-1.5 mt-0.5">
                                        <span x-text="item.menu_category || 'Umum'"></span>
                                        <span class="text-slate-300">•</span>
                                        <span class="font-mono text-[10px]" x-text="'ID: ' + item.menu_id"></span>
                                    </div>
                                </td>

                                <!-- TANGGAL TARGET -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-bold text-slate-800" x-text="formatDateDisplay(item.target_date)"></div>
                                    <div class="text-[11px] text-slate-400" x-text="getRelativeDayLabel(item.target_date)"></div>
                                </td>

                                <!-- PREDIKSI PERMINTAAN -->
                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    <div class="inline-flex items-baseline gap-1 font-black text-slate-900 text-sm">
                                        <span x-text="formatQuantity(item.predicted_quantity)"></span>
                                        <span class="text-[11px] font-bold text-slate-500" x-text="item.uom || 'Porsi'"></span>
                                    </div>
                                    <div class="text-[10px] text-slate-400 font-medium">Estimasi Unit</div>
                                </td>

                                <!-- RENTANG KEYAKINAN (P10 - P90) -->
                                <td class="px-6 py-4 text-center">
                                    <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-lg bg-slate-100/80 border border-slate-200/60 font-mono text-[11px] font-bold text-slate-700">
                                        <span class="text-slate-500" x-text="'P10: ' + formatQuantity(item.lower_bound_p10)"></span>
                                        <span class="text-slate-300">—</span>
                                        <span class="text-indigo-700 font-extrabold" x-text="'P90: ' + formatQuantity(item.upper_bound_p90)"></span>
                                    </div>
                                    <div class="text-[10px] text-slate-400 mt-1 font-medium">80% Confidence Interval</div>
                                </td>

                                <!-- STATUS DATA & MODEL -->
                                <td class="px-6 py-4 text-center whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold border"
                                        :class="item.history_status === 'SUFFICIENT' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200'"
                                        x-text="item.history_status || 'UNKNOWN'">
                                    </span>
                                </td>

                                <!-- WAKTU GENERATED -->
                                <td class="px-6 py-4 text-right whitespace-nowrap text-[11px] text-slate-400">
                                    <span x-text="formatDateTime(item.generated_at)"></span>
                                </td>

                            </tr>
                        </template>
                    </template>

                </tbody>
            </table>
        </div>

        <!-- PAGINATION BAR -->
        <template x-if="pagination.total > 0">
            <div class="px-6 py-3.5 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/50">
                <div class="text-xs text-slate-500 font-medium">
                    Menampilkan <span class="font-bold text-slate-800" x-text="getShowingStart()"></span> s/d <span class="font-bold text-slate-800" x-text="getShowingEnd()"></span> dari <span class="font-bold text-slate-800" x-text="pagination.total"></span> record
                </div>

                <div class="flex items-center gap-1.5">
                    <button type="button" @click="changePage(pagination.current_page - 1)" :disabled="pagination.current_page <= 1"
                        :class="pagination.current_page <= 1 ? 'opacity-40 cursor-not-allowed bg-slate-100 text-slate-400' : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'"
                        class="px-2.5 py-1 rounded-lg text-xs font-bold transition">
                        Sebelumnya
                    </button>

                    <span class="text-xs font-bold text-slate-600 px-2" x-text="'Hal ' + pagination.current_page + ' / ' + (pagination.last_page || 1)"></span>

                    <button type="button" @click="changePage(pagination.current_page + 1)" :disabled="!pagination.has_more_pages && pagination.current_page >= pagination.last_page"
                        :class="(!pagination.has_more_pages && pagination.current_page >= pagination.last_page) ? 'opacity-40 cursor-not-allowed bg-slate-100 text-slate-400' : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'"
                        class="px-2.5 py-1 rounded-lg text-xs font-bold transition">
                        Berikutnya
                    </button>
                </div>
            </div>
        </template>

    </div>

</div>

<script>
function demandForecastApp() {
    return {
        selectedOutletId: '{{ $activeOutlet ? $activeOutlet->id : "" }}',
        startDate: '{{ \Carbon\Carbon::today()->format("Y-m-d") }}',
        endDate: '{{ \Carbon\Carbon::today()->addDays(7)->format("Y-m-d") }}',
        selectedMenuId: '',
        perPage: 25,
        activePreset: 7,

        forecasts: [],
        pagination: {
            current_page: 1,
            per_page: 25,
            total: null,
            last_page: 1,
            has_more_pages: false
        },

        isLoading: false,
        errorMessage: null,
        isSessionExpired: false,

        init() {
            if (this.selectedOutletId) {
                this.fetchForecasts(1);
            }
        },

        onOutletChange() {
            var url = new URL(window.location.href);
            url.searchParams.set('outlet_id', this.selectedOutletId);
            window.location.href = url.toString();
        },

        setPresetDays(days) {
            this.activePreset = days;
            var today = new Date();
            var target = new Date();
            target.setDate(today.getDate() + days);

            this.startDate = this.formatDateIso(today);
            this.endDate = this.formatDateIso(target);
            this.fetchForecasts(1);
        },

        formatDateIso(d) {
            var month = '' + (d.getMonth() + 1);
            var day = '' + d.getDate();
            var year = d.getFullYear();

            if (month.length < 2) month = '0' + month;
            if (day.length < 2) day = '0' + day;

            return [year, month, day].join('-');
        },

        validateAndFetch() {
            this.activePreset = null;
            this.fetchForecasts(1);
        },

        async fetchForecasts(page = 1) {
            if (!this.selectedOutletId) return;

            this.isLoading = true;
            this.errorMessage = null;
            this.isSessionExpired = false;

            var params = new URLSearchParams({
                outlet_id: this.selectedOutletId,
                page: page,
                per_page: this.perPage
            });

            if (this.startDate) params.append('start_date', this.startDate);
            if (this.endDate) params.append('end_date', this.endDate);
            if (this.selectedMenuId) params.append('menu_id', this.selectedMenuId);

            var endpointUrl = "{{ route('copilot.intelligence.demand-forecast.data') }}?" + params.toString();

            try {
                var response = await fetch(endpointUrl, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (response.status === 401) {
                    this.isSessionExpired = true;
                    this.errorMessage = 'Sesi login telah berakhir. Silakan login kembali untuk melanjutkan.';
                    return;
                }

                var json = await response.json();

                if (!response.ok || json.status === 'error') {
                    if (json.errors) {
                        var firstError = Object.values(json.errors)[0];
                        this.errorMessage = Array.isArray(firstError) ? firstError[0] : firstError;
                    } else {
                        this.errorMessage = json.message || 'Gagal memuat data prediksi permintaan.';
                    }
                    return;
                }

                var data = json.data || {};
                this.forecasts = data.forecasts || [];
                
                if (data.pagination) {
                    this.pagination = {
                        current_page: data.pagination.current_page || 1,
                        per_page: data.pagination.per_page || this.perPage,
                        total: data.pagination.total !== undefined ? data.pagination.total : this.forecasts.length,
                        last_page: data.pagination.last_page || 1,
                        has_more_pages: !!data.pagination.has_more_pages
                    };
                }

            } catch (err) {
                this.errorMessage = 'Terjadi kesalahan komunikasi jaringan saat memuat prediksi.';
            } finally {
                this.isLoading = false;
            }
        },

        changePage(newPage) {
            if (newPage < 1) return;
            this.fetchForecasts(newPage);
        },

        getShowingStart() {
            if (!this.pagination.total) return 0;
            return ((this.pagination.current_page - 1) * this.pagination.per_page) + 1;
        },

        getShowingEnd() {
            if (!this.pagination.total) return 0;
            var end = this.pagination.current_page * this.pagination.per_page;
            return end > this.pagination.total ? this.pagination.total : end;
        },

        formatQuantity(val) {
            if (val === null || val === undefined) return '0';
            var num = Number(val);
            if (isNaN(num)) return '0';
            // Tampilkan maksimal 1 desimal jika ada pecahan, jika bulat tanpa desimal
            return num % 1 === 0 ? num.toString() : num.toFixed(1);
        },

        formatDateDisplay(dateStr) {
            if (!dateStr) return '-';
            try {
                var d = new Date(dateStr);
                return d.toLocaleDateString('id-ID', {
                    day: 'numeric',
                    month: 'short',
                    year: 'numeric'
                });
            } catch (e) {
                return dateStr;
            }
        },

        formatDateTime(dateTimeStr) {
            if (!dateTimeStr) return '-';
            try {
                var d = new Date(dateTimeStr);
                return d.toLocaleDateString('id-ID', {
                    day: 'numeric',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                return dateTimeStr;
            }
        },

        getRelativeDayLabel(dateStr) {
            if (!dateStr) return '';
            try {
                var today = new Date();
                today.setHours(0,0,0,0);
                var target = new Date(dateStr);
                target.setHours(0,0,0,0);

                var diffDays = Math.round((target - today) / (1000 * 60 * 60 * 24));
                if (diffDays === 0) return 'Hari Ini';
                if (diffDays === 1) return 'Besok';
                if (diffDays === 2) return 'Lusa';
                if (diffDays > 0) return 'Dalam ' + diffDays + ' hari';
                if (diffDays === -1) return 'Kemarin';
                return Math.abs(diffDays) + ' hari lalu';
            } catch (e) {
                return '';
            }
        },

        formatDateRange(start, end) {
            if (!start || !end) return '-';
            return this.formatDateDisplay(start) + ' — ' + this.formatDateDisplay(end);
        },

        calculateDateDiff(start, end) {
            if (!start || !end) return 0;
            try {
                var s = new Date(start);
                var e = new Date(end);
                var diff = Math.round((e - s) / (1000 * 60 * 60 * 24)) + 1;
                return diff > 0 ? diff : 0;
            } catch (err) {
                return 0;
            }
        }
    };
}
</script>
@endsection
