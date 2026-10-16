@extends('backoffice.master')

@section('title', 'Kaching Scenario Simulator')

@section('content')
<div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto" x-data="scenarioSimulatorApp()">

    <!-- HEADER -->
    <div class="mb-8">
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
                    <span class="text-xs font-semibold text-slate-400">Simulator</span>
                </div>
                <h1 class="text-2xl font-black text-slate-900 tracking-tight">Kaching Scenario Simulator</h1>
                <p class="text-sm text-slate-500 mt-1 font-medium">Simulasikan perubahan finansial tanpa mengubah data operasional.</p>
            </div>

            <!-- CONTROLS HEADER (OUTLET & PERIOD) -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span>Deterministik (Tanpa Mutasi Data)</span>
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

                <select x-model="baselinePeriod" @change="runSimulation()" class="text-xs font-bold bg-white border border-slate-200 rounded-xl px-3 py-2 text-slate-700 shadow-sm focus:ring-2 focus:ring-slate-900 focus:outline-none">
                    <option value="30_DAYS">Baseline: 30 Hari Terakhir</option>
                    <option value="7_DAYS">Baseline: 7 Hari Terakhir</option>
                </select>
            </div>
        </div>
    </div>

    <!-- MAIN GRID: CONTROLS (LEFT) & RESULTS (RIGHT) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

        <!-- SECTION 1: SCENARIO CONTROLS (5 COLUMNS) -->
        <div class="lg:col-span-5 flex flex-col gap-6">
            <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-6">
                    <div>
                        <h2 class="text-base font-black text-slate-900">Parameter Skenario</h2>
                        <p class="text-xs text-slate-500 font-medium">Ubah variabel untuk memproyeksikan laba dan margin.</p>
                    </div>
                    <button type="button" @click="resetControls()" class="text-xs font-bold text-slate-500 hover:text-slate-800 transition px-2.5 py-1 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100">
                        Reset
                    </button>
                </div>

                <!-- SCENARIO TYPE SELECTOR -->
                <div class="mb-6">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Tipe Skenario</label>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" @click="setScenarioType('COMBINED_SCENARIO')"
                            :class="scenarioType === 'COMBINED_SCENARIO' ? 'bg-slate-900 text-white font-bold' : 'bg-slate-50 hover:bg-slate-100 text-slate-700 font-medium border border-slate-200/60'"
                            class="text-xs py-2 px-3 rounded-xl transition text-center col-span-2">
                            Multi-Variabel (Kombinasi)
                        </button>
                        <button type="button" @click="setScenarioType('PRICE_CHANGE')"
                            :class="scenarioType === 'PRICE_CHANGE' ? 'bg-slate-900 text-white font-bold' : 'bg-slate-50 hover:bg-slate-100 text-slate-700 font-medium border border-slate-200/60'"
                            class="text-xs py-2 px-3 rounded-xl transition text-center">
                            Harga Jual
                        </button>
                        <button type="button" @click="setScenarioType('COST_CHANGE')"
                            :class="scenarioType === 'COST_CHANGE' ? 'bg-slate-900 text-white font-bold' : 'bg-slate-50 hover:bg-slate-100 text-slate-700 font-medium border border-slate-200/60'"
                            class="text-xs py-2 px-3 rounded-xl transition text-center">
                            Biaya / HPP
                        </button>
                        <button type="button" @click="setScenarioType('REVENUE_CHANGE')"
                            :class="scenarioType === 'REVENUE_CHANGE' ? 'bg-slate-900 text-white font-bold' : 'bg-slate-50 hover:bg-slate-100 text-slate-700 font-medium border border-slate-200/60'"
                            class="text-xs py-2 px-3 rounded-xl transition text-center">
                            Omzet Penjualan
                        </button>
                        <button type="button" @click="setScenarioType('EXPENSE_CHANGE')"
                            :class="scenarioType === 'EXPENSE_CHANGE' ? 'bg-slate-900 text-white font-bold' : 'bg-slate-50 hover:bg-slate-100 text-slate-700 font-medium border border-slate-200/60'"
                            class="text-xs py-2 px-3 rounded-xl transition text-center">
                            Beban Operasional
                        </button>
                    </div>
                </div>

                <!-- CONTROLS LIST -->
                <div class="space-y-6">

                    <!-- CONTROL 1: HARGA JUAL -->
                    <div x-show="scenarioType === 'COMBINED_SCENARIO' || scenarioType === 'PRICE_CHANGE'" class="p-4 rounded-xl bg-slate-50/70 border border-slate-100">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-bold text-slate-800">Harga Jual</label>
                            <span class="text-xs font-black px-2 py-0.5 rounded-md"
                                :class="priceChangePercent > 0 ? 'bg-emerald-100 text-emerald-800' : (priceChangePercent < 0 ? 'bg-rose-100 text-rose-800' : 'bg-slate-200 text-slate-700')"
                                x-text="(priceChangePercent > 0 ? '+' : '') + priceChangePercent + '%'"></span>
                        </div>
                        <p class="text-[11px] text-slate-500 mb-3 font-medium">Batas: -50% s/d +100%. Simulasi mengasumsikan volume transaksi tetap.</p>
                        <input type="range" min="-50" max="100" step="1" x-model.number="priceChangePercent"
                            class="w-full h-1.5 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-slate-900">
                        <div class="flex justify-between text-[10px] text-slate-400 font-semibold mt-1">
                            <span>-50%</span>
                            <span>0%</span>
                            <span>+100%</span>
                        </div>
                    </div>

                    <!-- CONTROL 2: BIAYA / HPP -->
                    <div x-show="scenarioType === 'COMBINED_SCENARIO' || scenarioType === 'COST_CHANGE'" class="p-4 rounded-xl bg-slate-50/70 border border-slate-100">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-bold text-slate-800">Biaya Bahan Baku / HPP</label>
                            <span class="text-xs font-black px-2 py-0.5 rounded-md"
                                :class="costChangePercent > 0 ? 'bg-rose-100 text-rose-800' : (costChangePercent < 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700')"
                                x-text="(costChangePercent > 0 ? '+' : '') + costChangePercent + '%'"></span>
                        </div>
                        <p class="text-[11px] text-slate-500 mb-3 font-medium">Batas: -50% s/d +200%. Kenaikan biaya langsung menekan laba kotor.</p>
                        <input type="range" min="-50" max="200" step="1" x-model.number="costChangePercent"
                            class="w-full h-1.5 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-slate-900">
                        <div class="flex justify-between text-[10px] text-slate-400 font-semibold mt-1">
                            <span>-50%</span>
                            <span>0%</span>
                            <span>+200%</span>
                        </div>
                    </div>

                    <!-- CONTROL 3: OMZET -->
                    <div x-show="scenarioType === 'COMBINED_SCENARIO' || scenarioType === 'REVENUE_CHANGE'" class="p-4 rounded-xl bg-slate-50/70 border border-slate-100">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-bold text-slate-800">Omzet Penjualan</label>
                            <span class="text-xs font-black px-2 py-0.5 rounded-md"
                                :class="revenueChangePercent > 0 ? 'bg-emerald-100 text-emerald-800' : (revenueChangePercent < 0 ? 'bg-rose-100 text-rose-800' : 'bg-slate-200 text-slate-700')"
                                x-text="(revenueChangePercent > 0 ? '+' : '') + revenueChangePercent + '%'"></span>
                        </div>
                        <p class="text-[11px] text-slate-500 mb-3 font-medium">Batas: -90% s/d +300%. Perubahan volume memengaruhi HPP variabel secara proporsional.</p>
                        <input type="range" min="-90" max="300" step="5" x-model.number="revenueChangePercent"
                            class="w-full h-1.5 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-slate-900">
                        <div class="flex justify-between text-[10px] text-slate-400 font-semibold mt-1">
                            <span>-90%</span>
                            <span>0%</span>
                            <span>+300%</span>
                        </div>
                    </div>

                    <!-- CONTROL 4: BEBAN OPERASIONAL -->
                    <div x-show="scenarioType === 'COMBINED_SCENARIO' || scenarioType === 'EXPENSE_CHANGE'" class="p-4 rounded-xl bg-slate-50/70 border border-slate-100">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-bold text-slate-800">Beban Operasional (%)</label>
                            <span class="text-xs font-black px-2 py-0.5 rounded-md"
                                :class="expenseChangePercent > 0 ? 'bg-rose-100 text-rose-800' : (expenseChangePercent < 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700')"
                                x-text="(expenseChangePercent > 0 ? '+' : '') + expenseChangePercent + '%'"></span>
                        </div>
                        <p class="text-[11px] text-slate-500 mb-3 font-medium">Batas: -80% s/d +300%. Penyesuaian beban sewa, utilitas, atau gaji.</p>
                        <input type="range" min="-80" max="300" step="5" x-model.number="expenseChangePercent"
                            class="w-full h-1.5 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-slate-900">
                        <div class="flex justify-between text-[10px] text-slate-400 font-semibold mt-1">
                            <span>-80%</span>
                            <span>0%</span>
                            <span>+300%</span>
                        </div>

                        <!-- NOMINAL ADJUSTMENT (KHUSUS EXPENSE) -->
                        <div class="mt-4 pt-3 border-t border-slate-200/60" x-show="scenarioType === 'EXPENSE_CHANGE'">
                            <label class="block text-[11px] font-bold text-slate-700 mb-1">Atau Nominal Langsung (Rp)</label>
                            <input type="number" step="50000" x-model.number="expenseChangeAmount"
                                placeholder="Contoh: 500000"
                                class="w-full text-xs font-semibold bg-white border border-slate-200 rounded-lg px-3 py-2 text-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-900">
                            <p class="text-[10px] text-slate-400 mt-1">Isi nominal positif untuk menambah biaya, negatif untuk efisiensi.</p>
                        </div>
                    </div>

                </div>

                <!-- ACTION BUTTONS -->
                <div class="mt-8 pt-4 border-t border-slate-100 flex items-center gap-3">
                    <button type="button" @click="runSimulation()" :disabled="isLoading"
                        class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-slate-900 hover:bg-slate-800 disabled:bg-slate-400 text-white font-bold text-sm shadow-sm transition">
                        <template x-if="isLoading">
                            <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </template>
                        <span x-text="isLoading ? 'Memproses Simulasi...' : 'Simulasikan'"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- SECTION 2: RESULTS (7 COLUMNS) -->
        <div class="lg:col-span-7 flex flex-col gap-6">

            <!-- ERROR ALERT -->
            <div x-show="errorMessage" x-cloak class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium flex items-start gap-3">
                <svg class="w-5 h-5 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <div>
                    <p class="font-bold text-rose-900">Simulasi Gagal</p>
                    <p x-text="errorMessage" class="mt-0.5"></p>
                </div>
            </div>

            <!-- DATA QUALITY WARNING BOX -->
            <div x-show="dataQuality && dataQuality !== 'HEALTHY'" x-cloak class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-start gap-3">
                <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <div>
                    <p class="font-bold text-amber-900">Perhatian Kualitas Data Finansial</p>
                    <template x-if="hasUntrackedCogs">
                        <p class="mt-0.5 text-amber-800">
                            Data HPP belum lengkap: Terdapat <span class="font-bold" x-text="untrackedItemsPercentage + '%'"></span> item transaksi tanpa data harga pokok bahan baku (cost price).
                        </p>
                    </template>
                    <template x-if="!hasUntrackedCogs">
                        <p class="mt-0.5 text-amber-800" x-text="'Status data: ' + dataQuality"></p>
                    </template>
                </div>
            </div>

            <!-- COMPARISON CARD (BASELINE VS SCENARIO VS DELTA) -->
            <div class="bg-white border border-slate-200/80 rounded-2xl shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-black text-slate-900">Hasil Perbandingan Finansial</h2>
                        <p class="text-xs text-slate-500 font-medium">Seluruh angka dihitung secara deterministik oleh SSOT backend.</p>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold"
                        :class="dataQuality === 'HEALTHY' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-amber-50 text-amber-800 border border-amber-200'"
                        x-text="dataQuality || 'UNKNOWN'"></span>
                </div>

                <!-- TABLE VIEW -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                <th class="py-3 px-6">Metrik Finansial</th>
                                <th class="py-3 px-4 text-right">Baseline (Aktual)</th>
                                <th class="py-3 px-4 text-right">Skenario (Proyeksi)</th>
                                <th class="py-3 px-6 text-right">Perubahan (Delta)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">

                            <!-- REVENUE -->
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="py-3.5 px-6 font-bold text-slate-900">Revenue (Omzet)</td>
                                <td class="py-3.5 px-4 text-right font-medium text-slate-700" x-text="formatRupiah(baseline.revenue)"></td>
                                <td class="py-3.5 px-4 text-right font-bold text-slate-900" x-text="formatRupiah(scenarioResult.revenue)"></td>
                                <td class="py-3.5 px-6 text-right font-bold"
                                    :class="delta.revenue > 0 ? 'text-emerald-600' : (delta.revenue < 0 ? 'text-rose-600' : 'text-slate-400')">
                                    <span x-text="formatRupiah(delta.revenue)"></span>
                                    <template x-if="delta.revenue_percent !== null && delta.revenue_percent !== undefined">
                                        <span class="text-[10px] font-semibold text-slate-500 ml-1" x-text="'(' + (delta.revenue_percent > 0 ? '+' : '') + delta.revenue_percent + '%)'"></span>
                                    </template>
                                </td>
                            </tr>

                            <!-- COGS / HPP -->
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="py-3.5 px-6 font-bold text-slate-900">COGS / HPP</td>
                                <td class="py-3.5 px-4 text-right font-medium text-slate-700" x-text="formatRupiah(baseline.cogs)"></td>
                                <td class="py-3.5 px-4 text-right font-bold text-slate-900" x-text="formatRupiah(scenarioResult.cogs)"></td>
                                <td class="py-3.5 px-6 text-right font-bold"
                                    :class="delta.cogs > 0 ? 'text-rose-600' : (delta.cogs < 0 ? 'text-emerald-600' : 'text-slate-400')">
                                    <span x-text="formatRupiah(delta.cogs)"></span>
                                    <template x-if="delta.cogs_percent !== null && delta.cogs_percent !== undefined">
                                        <span class="text-[10px] font-semibold text-slate-500 ml-1" x-text="'(' + (delta.cogs_percent > 0 ? '+' : '') + delta.cogs_percent + '%)'"></span>
                                    </template>
                                </td>
                            </tr>

                            <!-- GROSS PROFIT -->
                            <tr class="hover:bg-slate-50/50 transition bg-slate-50/30">
                                <td class="py-3.5 px-6 font-black text-slate-900">Gross Profit (Laba Kotor)</td>
                                <td class="py-3.5 px-4 text-right font-bold text-slate-700" x-text="formatRupiah(baseline.gross_profit)"></td>
                                <td class="py-3.5 px-4 text-right font-black text-slate-900" x-text="formatRupiah(scenarioResult.gross_profit)"></td>
                                <td class="py-3.5 px-6 text-right font-black"
                                    :class="delta.gross_profit > 0 ? 'text-emerald-600' : (delta.gross_profit < 0 ? 'text-rose-600' : 'text-slate-400')">
                                    <span x-text="formatRupiah(delta.gross_profit)"></span>
                                    <template x-if="delta.gross_profit_percent !== null && delta.gross_profit_percent !== undefined">
                                        <span class="text-[10px] font-semibold text-slate-500 ml-1" x-text="'(' + (delta.gross_profit_percent > 0 ? '+' : '') + delta.gross_profit_percent + '%)'"></span>
                                    </template>
                                </td>
                            </tr>

                            <!-- GROSS MARGIN -->
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="py-3 px-6 font-medium text-slate-600">Gross Margin</td>
                                <td class="py-3 px-4 text-right font-medium text-slate-600" x-text="formatPercent(baseline.gross_margin)"></td>
                                <td class="py-3 px-4 text-right font-bold text-slate-800" x-text="formatPercent(scenarioResult.gross_margin)"></td>
                                <td class="py-3 px-6 text-right font-bold"
                                    :class="delta.gross_margin_pp > 0 ? 'text-emerald-600' : (delta.gross_margin_pp < 0 ? 'text-rose-600' : 'text-slate-400')">
                                    <span x-text="formatPP(delta.gross_margin_pp)"></span>
                                </td>
                            </tr>

                            <!-- OPERATING EXPENSES -->
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="py-3.5 px-6 font-bold text-slate-900">Operating Expenses (Beban)</td>
                                <td class="py-3.5 px-4 text-right font-medium text-slate-700" x-text="formatRupiah(baseline.expenses)"></td>
                                <td class="py-3.5 px-4 text-right font-bold text-slate-900" x-text="formatRupiah(scenarioResult.expenses)"></td>
                                <td class="py-3.5 px-6 text-right font-bold"
                                    :class="delta.expenses > 0 ? 'text-rose-600' : (delta.expenses < 0 ? 'text-emerald-600' : 'text-slate-400')">
                                    <span x-text="formatRupiah(delta.expenses)"></span>
                                    <template x-if="delta.expenses_percent !== null && delta.expenses_percent !== undefined">
                                        <span class="text-[10px] font-semibold text-slate-500 ml-1" x-text="'(' + (delta.expenses_percent > 0 ? '+' : '') + delta.expenses_percent + '%)'"></span>
                                    </template>
                                </td>
                            </tr>

                            <!-- NET PROFIT -->
                            <tr class="hover:bg-slate-50/50 transition bg-slate-50/50">
                                <td class="py-4 px-6 font-black text-slate-900">Net Profit (Laba Bersih)</td>
                                <td class="py-4 px-4 text-right font-bold text-slate-700" x-text="formatRupiah(baseline.net_profit)"></td>
                                <td class="py-4 px-4 text-right font-black text-slate-900 text-sm" x-text="formatRupiah(scenarioResult.net_profit)"></td>
                                <td class="py-4 px-6 text-right font-black text-sm"
                                    :class="delta.net_profit > 0 ? 'text-emerald-600' : (delta.net_profit < 0 ? 'text-rose-600' : 'text-slate-400')">
                                    <span x-text="formatRupiah(delta.net_profit)"></span>
                                    <template x-if="delta.net_profit_percent !== null && delta.net_profit_percent !== undefined">
                                        <span class="text-[10px] font-semibold text-slate-500 ml-1" x-text="'(' + (delta.net_profit_percent > 0 ? '+' : '') + delta.net_profit_percent + '%)'"></span>
                                    </template>
                                </td>
                            </tr>

                            <!-- NET MARGIN -->
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="py-3 px-6 font-medium text-slate-600">Net Margin</td>
                                <td class="py-3 px-4 text-right font-medium text-slate-600" x-text="formatPercent(baseline.net_margin)"></td>
                                <td class="py-3 px-4 text-right font-bold text-slate-800" x-text="formatPercent(scenarioResult.net_margin)"></td>
                                <td class="py-3 px-6 text-right font-bold"
                                    :class="delta.net_margin_pp > 0 ? 'text-emerald-600' : (delta.net_margin_pp < 0 ? 'text-rose-600' : 'text-slate-400')">
                                    <span x-text="formatPP(delta.net_margin_pp)"></span>
                                </td>
                            </tr>

                        </tbody>
                    </table>
                </div>

                <!-- FOOTER BASELINE INFO -->
                <div class="p-4 bg-slate-50/60 border-t border-slate-100 flex flex-wrap items-center justify-between text-[11px] text-slate-500">
                    <div>
                        <span class="font-bold text-slate-700">Periode Evaluasi:</span>
                        <span x-text="baselinePeriodText"></span>
                    </div>
                    <div x-show="evidenceFingerprint">
                        <span class="font-mono text-slate-400" x-text="'Evidence: ' + evidenceFingerprint"></span>
                    </div>
                </div>
            </div>

            <!-- SECTION 4 & 5: ASSUMPTIONS & LIMITATIONS -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <!-- ASSUMPTIONS CARD -->
                <div class="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-sm">
                    <div class="flex items-center gap-2 mb-3">
                        <svg class="w-4 h-4 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-800">Asumsi Model Skenario</h3>
                    </div>
                    <ul class="space-y-2 text-xs text-slate-600 font-medium">
                        <template x-for="(assumption, idx) in assumptions" :key="idx">
                            <li class="flex items-start gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-slate-400 shrink-0 mt-1.5"></span>
                                <span x-text="assumption"></span>
                            </li>
                        </template>
                        <template x-if="assumptions.length === 0">
                            <li class="text-slate-400 italic">Tidak ada asumsi spesifik.</li>
                        </template>
                    </ul>
                </div>

                <!-- LIMITATIONS CARD -->
                <div class="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-sm">
                    <div class="flex items-center gap-2 mb-3">
                        <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        <h3 class="text-xs font-black uppercase tracking-wider text-slate-800">Batasan Skenario (Caveats)</h3>
                    </div>
                    <ul class="space-y-2 text-xs text-slate-600 font-medium">
                        <template x-for="(limitation, idx) in limitations" :key="idx">
                            <li class="flex items-start gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400 shrink-0 mt-1.5"></span>
                                <span x-text="limitation"></span>
                            </li>
                        </template>
                        <template x-if="limitations.length === 0">
                            <li class="text-slate-400 italic">Tidak ada batasan terdaftar.</li>
                        </template>
                    </ul>
                </div>

            </div>

        </div>

    </div>

</div>

<script>
function scenarioSimulatorApp() {
    return {
        selectedOutletId: {{ $activeOutlet ? $activeOutlet->id : 'null' }},
        baselinePeriod: '30_DAYS',
        scenarioType: 'COMBINED_SCENARIO',

        // Sliders & inputs
        priceChangePercent: 0,
        costChangePercent: 0,
        revenueChangePercent: 0,
        expenseChangePercent: 0,
        expenseChangeAmount: 0,

        // State
        isLoading: false,
        errorMessage: null,

        // Service payload outputs (SSOT - NO JS ARITHMETIC)
        baseline: {
            revenue: 0,
            cogs: 0,
            gross_profit: 0,
            gross_margin: 0,
            expenses: 0,
            net_profit: 0,
            net_margin: 0
        },
        scenarioResult: {
            revenue: 0,
            cogs: 0,
            gross_profit: 0,
            gross_margin: 0,
            expenses: 0,
            net_profit: 0,
            net_margin: 0
        },
        delta: {
            revenue: 0,
            revenue_percent: 0,
            cogs: 0,
            cogs_percent: 0,
            gross_profit: 0,
            gross_profit_percent: 0,
            gross_margin_pp: 0,
            expenses: 0,
            expenses_percent: 0,
            net_profit: 0,
            net_profit_percent: 0,
            net_margin_pp: 0
        },
        assumptions: [],
        limitations: [],
        dataQuality: 'HEALTHY',
        hasUntrackedCogs: false,
        untrackedItemsPercentage: 0,
        baselinePeriodText: '30 Hari Terakhir',
        evidenceFingerprint: null,

        init() {
            @if(isset($initialSimulation) && ($initialSimulation['status'] ?? '') === 'SUCCESS')
                this.populateFromServiceResult(@json($initialSimulation));
            @elseif(isset($initialSimulation) && ($initialSimulation['status'] ?? '') === 'ERROR')
                this.errorMessage = @json($initialSimulation['message'] ?? 'Data transaksi belum mencukupi untuk baseline.');
            @else
                this.runSimulation();
            @endif
        },

        setScenarioType(type) {
            this.scenarioType = type;
            this.runSimulation();
        },

        resetControls() {
            this.priceChangePercent = 0;
            this.costChangePercent = 0;
            this.revenueChangePercent = 0;
            this.expenseChangePercent = 0;
            this.expenseChangeAmount = 0;
            this.scenarioType = 'COMBINED_SCENARIO';
            this.runSimulation();
        },

        onOutletChange() {
            this.runSimulation();
        },

        async runSimulation() {
            if (this.isLoading) return;

            this.isLoading = true;
            this.errorMessage = null;

            var payload = {
                scenario_type: this.scenarioType,
                baseline_period: this.baselinePeriod,
                outlet_id: this.selectedOutletId
            };

            if (this.scenarioType === 'PRICE_CHANGE') {
                payload.price_change_percent = Number(this.priceChangePercent);
            } else if (this.scenarioType === 'COST_CHANGE') {
                payload.cost_change_percent = Number(this.costChangePercent);
            } else if (this.scenarioType === 'REVENUE_CHANGE') {
                payload.revenue_change_percent = Number(this.revenueChangePercent);
            } else if (this.scenarioType === 'EXPENSE_CHANGE') {
                if (Number(this.expenseChangeAmount) !== 0) {
                    payload.expense_change_amount = Number(this.expenseChangeAmount);
                } else {
                    payload.expense_change_percent = Number(this.expenseChangePercent);
                }
            } else if (this.scenarioType === 'COMBINED_SCENARIO') {
                payload.price_change_percent = Number(this.priceChangePercent);
                payload.cost_change_percent = Number(this.costChangePercent);
                payload.revenue_change_percent = Number(this.revenueChangePercent);
                if (Number(this.expenseChangeAmount) !== 0) {
                    payload.expense_change_amount = Number(this.expenseChangeAmount);
                } else {
                    payload.expense_change_percent = Number(this.expenseChangePercent);
                }
            }

            try {
                var response = await fetch("{{ route('copilot.intelligence.scenario.simulate') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify(payload)
                });

                var data = await response.json();

                if (!response.ok || data.status === 'ERROR') {
                    this.errorMessage = data.message || 'Simulasi tidak dapat dijalankan dengan parameter tersebut.';
                    return;
                }

                this.populateFromServiceResult(data);
            } catch (err) {
                this.errorMessage = 'Terjadi kesalahan komunikasi dengan server simulasi.';
            } finally {
                this.isLoading = false;
            }
        },

        populateFromServiceResult(data) {
            if (!data) return;

            if (data.baseline) {
                this.baseline = {
                    revenue: data.baseline.revenue || 0,
                    cogs: data.baseline.cogs || 0,
                    gross_profit: data.baseline.gross_profit || 0,
                    gross_margin: data.baseline.gross_margin,
                    expenses: data.baseline.expenses || 0,
                    net_profit: data.baseline.net_profit || 0,
                    net_margin: data.baseline.net_margin
                };
                if (data.baseline.period) {
                    this.baselinePeriodText = data.baseline.period.start_date + ' s/d ' + data.baseline.period.end_date + ' (' + (data.baseline.period.days || 30) + ' Hari)';
                }
                if (data.baseline.data_quality) {
                    this.dataQuality = data.baseline.data_quality.status || data.data_quality || 'HEALTHY';
                    this.hasUntrackedCogs = !!data.baseline.data_quality.has_untracked_cogs;
                    this.untrackedItemsPercentage = data.baseline.data_quality.untracked_items_percentage || 0;
                }
            }

            if (data.result) {
                this.scenarioResult = {
                    revenue: data.result.revenue || 0,
                    cogs: data.result.cogs || 0,
                    gross_profit: data.result.gross_profit || 0,
                    gross_margin: data.result.gross_margin,
                    expenses: data.result.expenses || 0,
                    net_profit: data.result.net_profit || 0,
                    net_margin: data.result.net_margin
                };
            }

            if (data.delta) {
                this.delta = {
                    revenue: data.delta.revenue !== undefined ? data.delta.revenue : 0,
                    revenue_percent: data.delta.revenue_percent,
                    cogs: data.delta.cogs !== undefined ? data.delta.cogs : 0,
                    cogs_percent: data.delta.cogs_percent,
                    gross_profit: data.delta.gross_profit !== undefined ? data.delta.gross_profit : 0,
                    gross_profit_percent: data.delta.gross_profit_percent,
                    gross_margin_pp: data.delta.gross_margin_pp,
                    expenses: data.delta.expenses !== undefined ? data.delta.expenses : 0,
                    expenses_percent: data.delta.expenses_percent,
                    net_profit: data.delta.net_profit !== undefined ? data.delta.net_profit : 0,
                    net_profit_percent: data.delta.net_profit_percent,
                    net_margin_pp: data.delta.net_margin_pp
                };
            }

            this.assumptions = Array.isArray(data.assumptions) ? data.assumptions : [];
            this.limitations = Array.isArray(data.limitations) ? data.limitations : [];

            if (data.evidence && data.evidence.fingerprint) {
                this.evidenceFingerprint = data.evidence.fingerprint.substring(0, 16) + '...';
            }
        },

        formatRupiah(num) {
            if (num === null || num === undefined) return '-';
            var sign = num < 0 ? '-Rp ' : 'Rp ';
            return sign + Math.abs(Math.round(num)).toLocaleString('id-ID');
        },

        formatPercent(num) {
            if (num === null || num === undefined) return '-';
            return Number(num).toFixed(2) + '%';
        },

        formatPP(num) {
            if (num === null || num === undefined) return '-';
            var sign = num > 0 ? '+' : '';
            return sign + Number(num).toFixed(2) + ' pp';
        }
    };
}
</script>
@endsection
