@extends('backoffice.master')

@section('title', 'Command Center - Kaching POS')

@section('content')
@php
if (!function_exists('formatUangSingkat')) {
function formatUangSingkat($angka) {
$angka = floatval($angka);
if ($angka >= 1000000000) {
return 'Rp ' . rtrim(rtrim(number_format($angka / 1000000000, 2, ',', '.'), '0'), ',') . ' M';
} elseif ($angka >= 1000000) {
return 'Rp ' . rtrim(rtrim(number_format($angka / 1000000, 2, ',', '.'), '0'), ',') . ' Jt';
}
return 'Rp ' . number_format($angka, 0, ',', '.');
}
}
@endphp

<div class="max-w-7xl mx-auto space-y-6">

    <div class="mb-8">
        <h1 id="ui-title" class="text-3xl font-black text-slate-900 tracking-tight transition-all">{{ __('backoffice.dashboard.overview') }} {{ $title ?? __('backoffice.dashboard.filters.today') }}</h1>
        <p class="text-slate-500 font-medium mt-1">{{ __('backoffice.dashboard.subtitle', ['store' => Auth::user()?->setting?->store_name ?? Auth::user()?->name ?? "Kaching POS"]) }}</p>
    </div>

    <div class="mb-6 flex flex-col lg:flex-row gap-3 justify-between items-start lg:items-center">

        <div class="flex gap-2 items-center w-full lg:w-auto">
            @php $currentFilter = request('filter', 'today'); @endphp

            <!-- Shift Sekarang — langsung filter saat diklik -->
            <div class="relative shrink-0" x-data="{ open: false }">
                @if(isset($activeShift) && $activeShift)
                @php
                    $isShiftActive = ($shiftFilter ?? 'all') == $activeShift->id;
                    // start_time dari Android dikirim langsung dalam WIB (Asia/Jakarta), hindari parse ulang dengan timezone()
                    $shiftStartTime = \Carbon\Carbon::parse($activeShift->start_time)->timezone('Asia/Jakarta')->format('H:i');
                @endphp
                <div class="flex items-center gap-1">
                    {{-- Tombol utama: langsung filter shift aktif --}}
                    <a href="{{ route('dashboard', ['filter' => $currentFilter, 'outlet_id' => $outletFilter, 'shift_id' => $activeShift->id]) }}"
                       class="px-3 py-1.5 md:px-5 md:py-2.5 rounded-l-full font-bold text-xs md:text-sm whitespace-nowrap transition-all duration-200 flex items-center gap-1.5 {{ $isShiftActive ? 'bg-emerald-600 text-white shadow-md' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        Shift {{ $shiftStartTime }}
                    </a>
                    {{-- Tombol dropdown: pilih shift lain --}}
                    <button @click="open = !open"
                            class="px-2 py-1.5 md:px-3 md:py-2.5 rounded-r-full border-l {{ $isShiftActive ? 'border-emerald-500 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' }} transition-all duration-200"
                            title="{{ __('backoffice.dashboard.shifts.select_other_shift') }}">
                        <svg class="w-3 h-3 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                </div>
                @else
                <span class="px-3 py-1.5 md:px-5 md:py-2.5 rounded-full font-bold text-xs md:text-sm whitespace-nowrap bg-slate-100 text-slate-400 cursor-not-allowed flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-slate-300"></span>
                    {{ __('backoffice.dashboard.shifts.no_shift') }}
                </span>
                @endif

                <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 top-full mt-2 bg-white rounded-xl shadow-lg border border-slate-200 py-2 z-50 min-w-[220px]">
                    @if(isset($activeShift) && $activeShift)
                    <a href="{{ route('dashboard', ['filter' => $currentFilter, 'outlet_id' => $outletFilter, 'shift_id' => $activeShift->id]) }}" class="block px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-indigo-50 {{ ($shiftFilter ?? '') == $activeShift->id ? 'bg-indigo-50 text-indigo-700' : '' }}">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block mr-2"></span>
                        {{ $activeShift->cashier_name }} — {{ \Carbon\Carbon::parse($activeShift->start_time)->timezone('Asia/Jakarta')->format('H:i') }} ({{ __('backoffice.dashboard.shifts.active_badge') }})
                    </a>
                    @endif
                    @foreach(($recentShifts ?? collect())->where('status', '!=', 'open')->take(4) as $prev)
                    <a href="{{ route('dashboard', ['filter' => $currentFilter, 'outlet_id' => $outletFilter, 'shift_id' => $prev->id]) }}" class="block px-4 py-2.5 text-sm font-medium text-slate-500 hover:bg-slate-50 {{ ($shiftFilter ?? '') == $prev->id ? 'bg-indigo-50 text-indigo-700' : '' }}">
                        {{ $prev->cashier_name }} — {{ \Carbon\Carbon::parse($prev->start_time)->timezone('Asia/Jakarta')->format('d M, H:i') }}
                    </a>
                    @endforeach
                    @if(($shiftFilter ?? 'all') !== 'all')
                    <hr class="my-1 border-slate-100">
                    <a href="{{ route('dashboard', ['filter' => $currentFilter, 'outlet_id' => $outletFilter]) }}" class="block px-4 py-2 text-sm font-bold text-red-500 hover:bg-red-50">
                        ✕ {{ __('backoffice.dashboard.shifts.show_all_data') }}
                    </a>
                    @endif
                </div>
            </div>

            <div class="border-l border-slate-300 h-6 hidden lg:block"></div>

            <div class="flex gap-2 overflow-x-auto pb-2 lg:pb-0 hide-scroll">
            @php $currentFilter = request('filter', 'today'); @endphp

            <a href="{{ route('dashboard', ['filter' => 'today', 'outlet_id' => $outletFilter]) }}" class="px-3 py-1.5 md:px-5 md:py-2.5 rounded-full font-bold text-xs md:text-sm whitespace-nowrap transition-all duration-200 {{ $currentFilter == 'today' ? 'bg-indigo-600 text-white shadow-md' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">{{ __('backoffice.dashboard.filters.today') }}</a>

            <a href="{{ route('dashboard', ['filter' => '7days', 'outlet_id' => $outletFilter]) }}" class="px-3 py-1.5 md:px-5 md:py-2.5 rounded-full font-bold text-xs md:text-sm whitespace-nowrap transition-all duration-200 {{ $currentFilter == '7days' ? 'bg-indigo-600 text-white shadow-md' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">{{ __('backoffice.dashboard.filters.7days') }}</a>

            <a href="{{ route('dashboard', ['filter' => '30days', 'outlet_id' => $outletFilter]) }}" class="px-3 py-1.5 md:px-5 md:py-2.5 rounded-full font-bold text-xs md:text-sm whitespace-nowrap transition-all duration-200 {{ $currentFilter == '30days' ? 'bg-indigo-600 text-white shadow-md' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">{{ __('backoffice.dashboard.filters.30days') }}</a>

            <a href="{{ route('dashboard', ['filter' => 'year', 'outlet_id' => $outletFilter]) }}" class="px-3 py-1.5 md:px-5 md:py-2.5 rounded-full font-bold text-xs md:text-sm whitespace-nowrap transition-all duration-200 {{ $currentFilter == 'year' ? 'bg-indigo-600 text-white shadow-md' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">{{ __('backoffice.dashboard.filters.this_year') }}</a>

            <a href="{{ route('dashboard', ['filter' => 'all', 'outlet_id' => $outletFilter]) }}" class="px-3 py-1.5 md:px-5 md:py-2.5 rounded-full font-bold text-xs md:text-sm whitespace-nowrap transition-all duration-200 {{ $currentFilter == 'all' ? 'bg-indigo-600 text-white shadow-md' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' }}">{{ __('backoffice.dashboard.filters.all_time') }}</a>

            </div><!-- tutup overflow-x-auto -->

            <div class="date-picker-wrapper" style="display: inline-flex; align-items: center; margin-left: 4px; position: relative; flex-shrink: 0;">
                {{-- Tombol visual yang selalu terlihat di semua ukuran layar & browser --}}
                <button type="button" id="customDateBtn" class="kaching-date-btn {{ request('date') ? 'kaching-date-btn--active' : '' }}" title="{{ __('backoffice.dashboard.filters.pick_date_tooltip') }}">
                    {{-- Ikon Kalender SVG --}}
                    <svg xmlns="http://www.w3.org/2000/svg" class="date-btn-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke-linecap="round" stroke-linejoin="round" />
                        <line x1="16" y1="2" x2="16" y2="6" stroke-linecap="round" />
                        <line x1="8" y1="2" x2="8" y2="6" stroke-linecap="round" />
                        <line x1="3" y1="10" x2="21" y2="10" stroke-linecap="round" />
                    </svg>
                    <span class="date-btn-label">
                        @if(request('date'))
                        {{ \Carbon\Carbon::parse(request('date'))->translatedFormat('j M Y') }}
                        @else
                        {{ __('backoffice.dashboard.filters.pick_date') }}
                        @endif
                    </span>
                    @if(request('date'))
                    <a href="{{ route('dashboard', ['filter' => $currentFilter, 'outlet_id' => $outletFilter]) }}" class="date-btn-clear" title="{{ __('backoffice.dashboard.filters.reset_date_tooltip') }}" onclick="event.stopPropagation();">✕</a>
                    @endif
                </button>
                {{-- Input date asli disembunyikan di belakang tombol, tapi tetap bisa diklik --}}
                <input type="date" id="customDateFilter" class="kaching-date-input-hidden" value="{{ request('date') }}" max="{{ now()->format('Y-m-d') }}">
            </div>
        </div>

        <form method="GET" action="{{ route('dashboard') }}" class="flex items-center w-full lg:w-auto shrink-0">
            <input type="hidden" name="filter" value="{{ $currentFilter }}">

            <div class="relative w-full lg:w-56">
                <select name="outlet_id" onchange="this.form.submit()" class="bg-white border border-slate-200 text-indigo-700 text-xs md:text-sm rounded-full focus:ring-indigo-500 focus:border-indigo-500 block w-full px-3 py-1.5 md:px-5 md:py-2.5 shadow-sm font-extrabold cursor-pointer appearance-none transition-all hover:bg-slate-50">
                    @if(isset($outlets) && count($outlets) > 1)
                    <option value="all" {{ $outletFilter === 'all' ? 'selected' : '' }}>🏢 {{ __('backoffice.dashboard.filters.all_outlets') }}</option>
                    @endif
                    @foreach($outlets ?? [] as $outlet)
                    <option value="{{ $outlet->id }}" {{ $outletFilter == $outlet->id ? 'selected' : '' }}>
                        📍 {{ $outlet->name }}
                    </option>
                    @endforeach
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 md:px-4 text-indigo-500">
                    <svg class="w-3 h-3 md:w-4 md:h-4 font-bold" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </div>
            </div>
        </form>
    </div>

    <!-- [BARU] Info Shift Terpilih -->
    @if(isset($selectedShift) && $selectedShift)
    <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4 mb-2 flex flex-col md:flex-row md:items-center gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-indigo-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <div class="font-bold text-indigo-900 text-sm">{{ __('backoffice.dashboard.shifts.shift_prefix') }} {{ $selectedShift->cashier_name }}</div>
                <div class="text-xs text-indigo-600 font-medium">
                    {{ __('backoffice.dashboard.shifts.opened_colon') }} {{ \Carbon\Carbon::parse($selectedShift->start_time)->timezone('Asia/Jakarta')->translatedFormat('d M Y, H:i') }}
                @if($selectedShift->end_time)
                    &bull; {{ __('backoffice.dashboard.shifts.closed_colon') }} {{ \Carbon\Carbon::parse($selectedShift->end_time)->timezone('Asia/Jakarta')->translatedFormat('H:i') }}
                @else
                    &bull; {{ __('backoffice.dashboard.shifts.active_now') }}
                @endif
                </div>
            </div>
        </div>
        <div class="flex gap-3 md:ml-auto">
            <div class="bg-white rounded-lg px-3 py-2 border border-indigo-100">
                <div class="text-[10px] text-indigo-500 font-bold uppercase">{{ __('backoffice.dashboard.shifts.initial_cash') }}</div>
                <div class="text-sm font-black text-indigo-800">Rp {{ number_format($selectedShift->start_cash, 0, ',', '.') }}</div>
            </div>
        </div>
    </div>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3 md:gap-6">

        <div class="bg-slate-900 p-4 md:p-6 rounded-[1.5rem] md:rounded-[2rem] shadow-xl shadow-slate-200/50 border border-slate-800 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-16 h-16 md:w-24 md:h-24 bg-blue-500/20 rounded-full blur-2xl group-hover:bg-blue-500/30 transition-all"></div>
            <p class="text-[9px] md:text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-1">{{ __('backoffice.dashboard.metrics.total_revenue') }}</p>
            @if(($todayOmset ?? 0) > 0)
            <h3 id="ui-omset" class="text-xl md:text-2xl lg:text-[1.7rem] font-black text-white whitespace-nowrap tracking-tight overflow-hidden text-ellipsis transition-all leading-tight" title="Rp {{ number_format($todayOmset ?? 0, 0, ',', '.') }}">{{ formatUangSingkat($todayOmset ?? 0) }}</h3>
            @else
            <h3 id="ui-omset" class="text-xl md:text-2xl lg:text-[1.7rem] font-black text-white whitespace-nowrap tracking-tight overflow-hidden text-ellipsis transition-all leading-tight">Rp 0</h3>
            <p class="text-[8px] text-slate-400 font-medium tracking-tight mt-1">{{ __('backoffice.dashboard.metrics.no_sales_yet') }}</p>
            @endif
            <div class="mt-2 md:mt-4 pt-2 md:pt-4 border-t border-slate-800 flex justify-between items-center">
                <span class="text-[9px] md:text-[10px] font-bold text-emerald-400 flex items-center gap-1">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 15l7-7 7 7"></path>
                    </svg>
                    Live Update
                </span>
            </div>
        </div>

        <div class="bg-blue-600 p-4 md:p-6 rounded-[1.5rem] md:rounded-[2rem] shadow-xl shadow-blue-200 border border-blue-500 relative overflow-hidden">
            <div class="absolute -right-4 -bottom-4 opacity-20">
                <svg class="w-16 h-16 md:w-24 md:h-24 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
            </div>
            <p class="text-[9px] md:text-[10px] font-black text-blue-200 uppercase tracking-[0.2em] mb-1">{{ __('backoffice.dashboard.metrics.total_transactions') }}</p>
            @if(($todayCount ?? 0) > 0)
            <h3 id="ui-nota" class="text-xl md:text-3xl font-black text-white transition-all">{{ $todayCount }} <span class="text-xs md:text-lg font-bold text-white">{{ __('backoffice.dashboard.metrics.receipts_unit') }}</span></h3>
            @else
            <h3 id="ui-nota" class="text-xl md:text-3xl font-black text-white transition-all">0 <span class="text-xs md:text-lg font-bold text-white">{{ __('backoffice.dashboard.metrics.receipts_unit') }}</span></h3>
            <p class="text-[8px] text-blue-200 font-medium tracking-tight mt-1">{{ __('backoffice.dashboard.metrics.start_selling') }}</p>
            @endif
            <div class="mt-2 md:mt-4 pt-2 md:pt-4 border-t border-blue-500/50 flex justify-between items-center relative z-10">
                <a href="{{ route('transactions.history') }}" class="text-[9px] md:text-[10px] font-bold text-white hover:text-blue-100 flex items-center gap-1 transition">
                    {{ __('backoffice.dashboard.metrics.view_history') }} <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                    </svg>
                </a>
            </div>
        </div>

        <div class="bg-white p-4 md:p-6 rounded-[1.5rem] md:rounded-[2rem] shadow-sm border border-slate-100 flex flex-col justify-between hover:-translate-y-1 transition-transform hover:shadow-md">
            <div>
                <div class="flex justify-between items-start mb-2">
                    <div class="p-2 md:p-2.5 bg-orange-50 text-orange-600 rounded-xl"><svg class="w-4 h-4 md:w-5 md:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg></div>
                    <span class="text-[8px] md:text-[10px] font-bold bg-slate-100 text-slate-500 px-2 py-1 rounded-md">{{ __('backoffice.dashboard.metrics.drawer') }}</span>
                </div>
                <p class="text-[9px] md:text-xs font-bold text-slate-400 uppercase tracking-wider">{{ __('backoffice.dashboard.metrics.cash') }}</p>
                <h3 id="ui-cash" class="text-lg lg:text-[1.35rem] font-black text-slate-800 mt-1 whitespace-nowrap tracking-tight overflow-hidden text-ellipsis transition-all leading-tight" title="Rp {{ number_format($cashSales ?? 0, 0, ',', '.') }}">{{ formatUangSingkat($cashSales ?? 0) }}</h3>
            </div>
        </div>

        <div class="bg-white p-4 md:p-6 rounded-[1.5rem] md:rounded-[2rem] shadow-sm border border-slate-100 flex flex-col justify-between hover:-translate-y-1 transition-transform hover:shadow-md">
            <div>
                <div class="flex justify-between items-start mb-2">
                    <div class="p-2 md:p-2.5 bg-emerald-50 text-emerald-600 rounded-xl"><svg class="w-4 h-4 md:w-5 md:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg></div>
                    <span class="text-[8px] md:text-[10px] font-bold bg-slate-100 text-slate-500 px-2 py-1 rounded-md">QRIS</span>
                </div>
                <p class="text-[9px] md:text-xs font-bold text-slate-400 uppercase tracking-wider">{{ __('backoffice.dashboard.metrics.transfer') }}</p>
                <h3 id="ui-digital" class="text-lg lg:text-[1.35rem] font-black text-emerald-600 mt-1 whitespace-nowrap tracking-tight overflow-hidden text-ellipsis transition-all leading-tight" title="Rp {{ number_format($digitalSales ?? 0, 0, ',', '.') }}">{{ formatUangSingkat($digitalSales ?? 0) }}</h3>
            </div>
        </div>

        <div class="bg-orange-50 p-4 md:p-6 rounded-[1.5rem] md:rounded-[2rem] shadow-sm border border-orange-100 flex flex-col justify-between hover:-translate-y-1 transition-transform hover:shadow-md">
            <div>
                <div class="flex justify-between items-start mb-2">
                    <div class="p-2 md:p-2.5 bg-orange-100 text-orange-600 rounded-xl">
                        <svg class="w-4 h-4 md:w-5 md:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <span class="text-[8px] md:text-[10px] font-bold bg-orange-200 text-orange-800 px-2 py-1 rounded-md">{{ __('backoffice.dashboard.metrics.receivable_badge') }}</span>
                </div>
                <p class="text-[9px] md:text-xs font-bold text-orange-500 uppercase tracking-wider">{{ __('backoffice.dashboard.metrics.receivables') }}</p>
                <h3 id="ui-piutang" class="text-lg lg:text-[1.35rem] font-black text-orange-600 mt-1 whitespace-nowrap tracking-tight overflow-hidden text-ellipsis transition-all leading-tight" title="Rp {{ number_format($totalPiutang ?? 0, 0, ',', '.') }}">{{ formatUangSingkat($totalPiutang ?? 0) }}</h3>
            </div>
        </div>
    </div>

    <!-- [SHIFT] Info Shift Aktif & Riwayat Shift -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Shift Aktif --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
            <div class="flex items-center gap-3 mb-4">
                <div class="p-2 bg-indigo-100 rounded-xl">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-sm">{{ __('backoffice.dashboard.shifts.active_shift') }}</h3>
                    <p class="text-xs text-slate-400">{{ __('backoffice.dashboard.shifts.running_now') }}</p>
                </div>
            </div>
            @if($activeShift)
            <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-6 text-sm text-slate-600 space-y-2">
                <div class="flex items-center gap-2 border-b border-indigo-100/50 pb-2 mb-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="font-bold text-indigo-900">{{ $activeShift->cashier_name }}</span>
                    <span class="text-[10px] font-bold bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full">{{ __('backoffice.dashboard.shifts.active_shift') }}</span>
                </div>
                <div class="space-y-1 text-slate-500 font-medium">
                    <p>{{ __('backoffice.dashboard.shifts.opened_colon') }} {{ \Carbon\Carbon::parse($activeShift->start_time)->timezone('Asia/Jakarta')->translatedFormat('d M Y, H:i:s') }}</p>
                    <p>{{ __('backoffice.dashboard.shifts.duration_colon') }} {{ \Carbon\Carbon::parse($activeShift->start_time)->diffForHumans(['parts' => 2, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</p>
                    <p>{{ __('backoffice.dashboard.shifts.initial_cash_colon') }} Rp {{ number_format($activeShift->start_cash, 0, ',', '.') }}</p>
                </div>
            </div>
            @else
                <div class="bg-slate-50 rounded-xl p-4 text-center">
                    <p class="text-sm text-slate-400 font-medium">{{ __('backoffice.dashboard.shifts.no_active_shift') }}</p>
                </div>
            @endif
        </div>

        {{-- Riwayat Shift Terakhir --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
            <div class="flex items-center gap-3 mb-4">
                <div class="p-2 bg-slate-100 rounded-xl">
                    <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 text-sm">{{ __('backoffice.dashboard.shifts.shift_history') }}</h3>
                    <p class="text-xs text-slate-400">{{ __('backoffice.dashboard.shifts.last_10_shifts') }}</p>
                </div>
            </div>
            <div class="space-y-2 max-h-64 overflow-y-auto">
                @forelse($recentShifts ?? [] as $shift)
                    <div class="flex items-center justify-between py-2 px-3 rounded-lg hover:bg-slate-50 transition {{ $shift->status === 'open' ? 'bg-indigo-50' : '' }}">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-sm text-slate-800 truncate">{{ $shift->cashier_name }}</span>
                                @if($shift->status === 'open')
                                    <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-700 text-[9px] font-bold rounded-full">{{ __('backoffice.dashboard.shifts.status_active') }}</span>
                                @endif
                            </div>
                            <div class="text-[11px] text-slate-400 font-medium mt-0.5">
                                {{ \Carbon\Carbon::parse($shift->start_time)->timezone('Asia/Jakarta')->translatedFormat('d M, H:i') }}
                                @if($shift->end_time)
                                    → {{ \Carbon\Carbon::parse($shift->end_time)->timezone('Asia/Jakarta')->translatedFormat('H:i') }}
                                    ({{ \Carbon\Carbon::parse($shift->start_time)->diff(\Carbon\Carbon::parse($shift->end_time))->format('%hj %im') }})
                                @else
                                    → {{ __('backoffice.dashboard.shifts.still_active') }}
                                @endif
                                &bull; {{ __('backoffice.dashboard.shifts.modal_short') }}: Rp {{ number_format($shift->start_cash, 0, ',', '.') }}
                            </div>
                        </div>
                        <div class="text-right ml-3">
                            <div class="text-xs font-bold text-slate-700">Rp {{ number_format($shift->total_cash_sales, 0, ',', '.') }}</div>
                            <div class="text-[10px] text-slate-400">{{ __('backoffice.dashboard.metrics.cash') }}</div>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-6">
                        <p class="text-sm text-slate-400">{{ __('backoffice.dashboard.shifts.no_shift_history') }}</p>
                    </div>
                @endforelse
            </div>
        </div>

    </div>

    <!-- 🚀 KACHING ENTERPRISE ANALYTICS 🚀 -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">

        <!-- Canvas 1: Line Chart 7 Hari Terakhir -->
        <div class="xl:col-span-2 bg-white p-6 md:p-8 rounded-[2.5rem] shadow-sm border border-slate-100 relative group overflow-hidden">
            <div class="absolute -top-10 -right-10 w-40 h-40 bg-indigo-50 rounded-full blur-3xl opacity-50 group-hover:opacity-100 transition-opacity duration-500"></div>

            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-6 gap-4 relative z-10">
                <div>
                    <h3 class="font-black text-slate-800 text-lg md:text-xl flex items-center gap-2">
                        <svg class="w-5 h-5 md:w-6 md:h-6 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                        </svg>
                        {{ __('backoffice.dashboard.charts.revenue_trend_7days') }}
                    </h3>
                    <p class="text-slate-400 text-xs md:text-sm font-semibold mt-1">{{ __('backoffice.dashboard.charts.revenue_trend_weekly_subtitle') }}</p>
                </div>
                <div class="bg-indigo-50 text-indigo-600 px-3 py-1.5 rounded-xl text-xs font-bold border border-indigo-100 flex items-center gap-2 shadow-sm">
                    <div class="w-2 h-2 rounded-full bg-indigo-500 animate-pulse"></div> Live Data
                </div>
            </div>
            <div class="relative h-[250px] md:h-[300px] w-full">
                <canvas id="chart7Days"></canvas>
            </div>
        </div>

        <!-- Canvas 2: Doughnut Chart Payment Method Hari Ini -->
        <div class="xl:col-span-1 bg-white p-6 md:p-8 rounded-[2.5rem] shadow-sm border border-slate-100 relative group overflow-hidden">
            <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-emerald-50 rounded-full blur-3xl opacity-50 group-hover:opacity-100 transition-opacity duration-500"></div>

            <div class="flex flex-col mb-6 relative z-10">
                <h3 class="font-black text-slate-800 text-lg md:text-xl flex items-center gap-2">
                    <svg class="w-5 h-5 md:w-6 md:h-6 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path>
                    </svg>
                    {{ __('backoffice.dashboard.charts.payment_distribution') }}
                </h3>
                <p class="text-slate-400 text-xs md:text-sm font-semibold mt-1">{{ __('backoffice.dashboard.charts.payment_methods_title') }} <span class="text-emerald-600 font-bold">{{ $title ?? __('backoffice.dashboard.filters.today') }}</span></p>
            </div>
            <div class="relative h-[200px] md:h-[250px] w-full flex justify-center items-center">
                @if(empty($chartPaymentMethod['data']))
                <div class="flex flex-col items-center justify-center text-center">
                    <div class="w-12 h-12 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center mb-2 shadow-inner">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                        </svg>
                    </div>
                    <p class="text-slate-400 font-medium text-xs">{{ __('backoffice.dashboard.charts.payment_methods_empty') }}</p>
                </div>
                @else
                <canvas id="chartPayment"></canvas>
                @endif
            </div>
        </div>

    </div>


    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">

        <div class="xl:col-span-2 bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-8 gap-4">
                <div>
                    <h3 class="font-black text-slate-800 text-xl flex items-center gap-2">
                        <svg class="w-6 h-6 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path>
                        </svg>
                        {{ __('backoffice.dashboard.charts.sales_chart') }}
                    </h3>
                    <p id="ui-chart-subtitle" class="text-slate-400 text-sm font-semibold">{{ $chartSubtitle }}</p>
                </div>
                <div class="flex gap-2">
                    <span class="bg-indigo-50 text-indigo-600 border border-indigo-100 text-xs font-bold px-3 py-1.5 rounded-lg flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-indigo-600"></span> {{ __('backoffice.dashboard.charts.gross_revenue') }}
                    </span>
                </div>
            </div>
            <div class="relative h-[300px] w-full">
                <canvas id="salesChart"></canvas>
            </div>
        </div>

        <div class="xl:col-span-1 space-y-6">

            <div class="bg-white rounded-[2rem] shadow-sm border border-slate-100 overflow-hidden flex flex-col">
                <div class="px-6 py-5 border-b border-slate-50 flex justify-between items-center bg-slate-50/50">
                    <h3 class="font-bold text-slate-800 flex items-center gap-2 text-sm">
                        <span class="text-lg">🧑‍🍳</span> {{ __('backoffice.dashboard.team.on_duty_today') }}
                    </h3>
                    <a href="{{ route('schedules.index') }}" class="text-[10px] font-bold text-indigo-600 hover:text-indigo-800">{{ __('backoffice.dashboard.team.view_schedule') }}</a>
                </div>
                <div class="p-6 flex-1 max-h-[280px] overflow-y-auto hide-scroll space-y-3">
                    @forelse($todaySchedules ?? [] as $schedule)
                    <div class="flex items-center justify-between p-3 rounded-xl border border-slate-100 bg-white shadow-sm hover:border-indigo-200 transition">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center font-bold text-sm uppercase">
                                {{ substr($schedule->staff->staff_name ?? 'S', 0, 1) }}
                            </div>
                            <div>
                                <p class="text-sm font-bold text-slate-800">{{ $schedule->staff->staff_name ?? __('backoffice.dashboard.team.default_staff') }}</p>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">{{ $schedule->shift_type }} ({{ substr($schedule->start_time, 0, 5) }})</span>
                                    @if($schedule->attendance_time)
                                        <span class="text-[10px] text-slate-400 font-medium">&bull; {{ $schedule->attendance_time }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        
                        @if($schedule->attendance_status === 'aktif')
                            <span class="px-2 py-1 rounded-md text-[9px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                {{ __('backoffice.dashboard.team.start_shift') }}
                            </span>
                        @elseif($schedule->attendance_status === 'selesai')
                            <span class="px-2 py-1 rounded-md text-[9px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                {{ __('backoffice.dashboard.team.end_shift') }}
                            </span>
                        @else
                            <span class="px-2 py-1 rounded-md text-[9px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                {{ __('backoffice.dashboard.team.not_shifted') }}
                            </span>
                        @endif
                    </div>
                    @empty
                    <div class="text-center py-4">
                        <p class="text-xs text-slate-400 font-medium">{{ __('backoffice.dashboard.team.no_schedule_today') }}</p>
                    </div>
                    @endforelse
                </div>
            </div>

            <div class="bg-white rounded-[2rem] shadow-sm border border-slate-100 overflow-hidden flex flex-col">
                <div class="px-6 py-5 border-b border-slate-50 flex justify-between items-center bg-red-50/30">
                    <h3 class="font-bold text-red-700 flex items-center gap-2 text-sm">
                        <span class="text-lg">⚠️</span> {{ __('backoffice.dashboard.inventory.low_stock') }}
                    </h3>
                    <a href="{{ route('menus.index') }}" class="text-[10px] font-bold text-red-600 hover:text-red-800 transition-colors uppercase tracking-wider">{{ __('backoffice.dashboard.inventory.update_stock') }}</a>
                </div>
                <div class="p-6 flex-1 max-h-[220px] overflow-y-auto hide-scroll space-y-3">
                    @forelse($lowStockMenus ?? [] as $menu)
                    <div class="flex items-center justify-between p-3 rounded-xl border border-red-50 bg-red-50/10 hover:bg-red-50/30 transition shadow-sm group">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-red-100 text-red-600 flex items-center justify-center font-bold text-lg">
                                {!! $menu->category == 'Makanan' ? '🍔' : ($menu->category == 'Minuman' ? '🥤' : '📦') !!}
                            </div>
                            <div>
                                <p class="text-sm font-bold text-slate-800 group-hover:text-red-700 transition">{{ $menu->name }}</p>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{{ $menu->category }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-black text-red-600">{{ $menu->stock }} <span class="text-[10px]">{{ $menu->unit }}</span></p>
                            <p class="text-[9px] font-bold text-red-300 uppercase">{{ __('backoffice.dashboard.inventory.almost_empty') }}</p>
                        </div>
                    </div>
                    @empty
                    <div class="text-center py-8">
                        <div class="w-16 h-16 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center mx-auto mb-3 shadow-inner">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <p class="text-xs text-slate-500 font-bold">{{ __('backoffice.dashboard.inventory.safe_stock_title') }}</p>
                        <p class="text-[10px] text-slate-400 mt-1">{{ __('backoffice.dashboard.inventory.safe_stock_desc') }}</p>
                    </div>
                    @endforelse
                </div>
            </div>

        </div>
    </div>
</div>

@if(session('success') && str_contains(session('success'), 'Masa Trial'))
<div id="onboardingModal" class="fixed inset-0 z-[999] flex items-center justify-center bg-slate-900/70 backdrop-blur-sm px-4">
    <div class="bg-white rounded-[2rem] shadow-2xl max-w-lg w-full overflow-hidden animate-fade-in-up border border-slate-100">

        <div class="bg-indigo-600 p-8 text-center relative overflow-hidden">
            <div class="absolute -top-10 -right-10 w-32 h-32 bg-white/10 rounded-full blur-xl"></div>
            <div class="absolute -bottom-10 -left-10 w-32 h-32 bg-white/10 rounded-full blur-xl"></div>

            <button onclick="document.getElementById('onboardingModal').style.display='none'" class="absolute top-4 right-4 text-white/70 hover:text-white transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
            <div class="w-20 h-20 bg-white rounded-[1.5rem] flex items-center justify-center mx-auto mb-5 shadow-lg shadow-indigo-900/20 transform rotate-3">
                <span class="text-4xl transform -rotate-3">🎉</span>
            </div>
            <h3 class="text-2xl font-black text-white tracking-tight">{{ __('backoffice.dashboard.onboarding.welcome') }}</h3>
            <p class="text-indigo-100 mt-2 font-medium text-sm">{{ __('backoffice.dashboard.onboarding.welcome_desc') }}</p>
        </div>

        <div class="p-8 space-y-8 bg-slate-50">

            <div class="flex gap-5 items-start group">
                <div class="w-12 h-12 rounded-[1rem] bg-white text-indigo-600 flex items-center justify-center font-black flex-shrink-0 border border-slate-200 shadow-sm group-hover:scale-110 group-hover:border-indigo-300 transition-all duration-300 text-xl">1</div>
                <div>
                    <h4 class="font-bold text-slate-800 text-lg">{{ __('backoffice.dashboard.onboarding.step1_title') }}</h4>
                    <p class="text-sm text-slate-500 mb-3 leading-relaxed">{{ __('backoffice.dashboard.onboarding.step1_desc') }}</p>
                    <a href="{{ route('menus.index') }}" class="inline-flex items-center text-indigo-600 font-bold text-sm hover:text-indigo-800 transition-colors">
                        {{ __('backoffice.dashboard.onboarding.step1_link') }}
                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </a>
                </div>
            </div>

            <div class="w-full h-px bg-gradient-to-r from-slate-200 via-slate-200 to-transparent"></div>

            <div class="flex gap-5 items-start group">
                <div class="w-12 h-12 rounded-[1rem] bg-white text-amber-500 flex items-center justify-center font-black flex-shrink-0 border border-slate-200 shadow-sm group-hover:scale-110 group-hover:border-amber-300 transition-all duration-300 text-xl">2</div>
                <div>
                    <h4 class="font-bold text-slate-800 text-lg">{{ __('backoffice.dashboard.onboarding.step2_title') }}</h4>
                    <p class="text-sm text-slate-500 mb-4 leading-relaxed">{{ __('backoffice.dashboard.onboarding.step2_desc') }}</p>
                    <a href="#" target="_blank" class="inline-flex items-center gap-2 bg-slate-900 hover:bg-slate-800 text-white px-5 py-3 rounded-xl text-sm font-bold shadow-lg shadow-slate-900/20 transition-all active:scale-95">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.523 15.3414c-.5511 0-1.0266-.4755-1.0266-1.0267s.4755-1.0266 1.0266-1.0266c.5511 0 1.0266.4754 1.0266 1.0266s-.4755 1.0267-1.0266 1.0267zm-11.046 0c-.5511 0-1.0266-.4755-1.0266-1.0267s.4755-1.0266 1.0266-1.0266c.5511 0 1.0266.4754 1.0266 1.0266s-.4755 1.0267-1.0266 1.0267zm11.4045-3.328l1.3283-2.2618c.0381-.065.0159-.15-.0491-.1881-.065-.0382-.15-.016-.1881.049l-1.3533 2.3045c-1.3789-.6078-2.926-.9512-4.5777-.9512-1.6517 0-3.1988.3434-4.5777.9512l-1.3533-2.3045c-.0382-.065-.1232-.0872-.1881-.049-.065.0381-.0872.1231-.0491.1881l1.3283 2.2618c-2.9463 1.5833-5.006 4.3905-5.3282 7.747h19.0342c-.3222-3.3565-2.3819-6.1637-5.3282-7.747z" />
                        </svg>
                        {{ __('backoffice.dashboard.onboarding.step2_button') }}
                    </a>
                </div>
            </div>

        </div>

        <div class="bg-white p-5 text-center border-t border-slate-100">
            <button onclick="document.getElementById('onboardingModal').style.display='none'" class="text-slate-400 hover:text-slate-700 font-bold text-sm transition-colors px-4 py-2 rounded-lg hover:bg-slate-50">
                {{ __('backoffice.dashboard.onboarding.skip_button') }}
            </button>
        </div>

    </div>
</div>
@endif

<style>
    /* CSS Animasi Pop-Up */
    .animate-fade-in-up {
        animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px) scale(0.95);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    /* =====================================================
       CUSTOM DATE PICKER — Fix tampilan kosong di Android
       ===================================================== */

    /* Tombol visual yang menjadi wajah filter tanggal */
    .kaching-date-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px 6px 10px;
        border-radius: 9999px;
        border: 1px solid #e5e7eb;
        background-color: #ffffff;
        color: #4b5563;
        font-family: 'Inter', 'Plus Jakarta Sans', sans-serif;
        font-size: 0.75rem;
        font-weight: 700;
        white-space: nowrap;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        min-width: 44px;
        /* Pastikan tidak collapse jadi 0px */
        min-height: 34px;
        position: relative;
        z-index: 1;
        /* Tombol di belakang input (visual saja) */
        pointer-events: none;
        /* Klik diteruskan ke input di atasnya */
        user-select: none;
    }

    /* State aktif saat tanggal sudah dipilih */
    .kaching-date-btn--active {
        background-color: #eef2ff;
        border-color: #6366f1;
        color: #4338ca;
    }

    /* Icon kalender SVG */
    .date-btn-icon {
        width: 15px;
        height: 15px;
        flex-shrink: 0;
        stroke: currentColor;
    }

    /* Label teks tombol */
    .date-btn-label {
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1;
    }

    /* Tombol clear (✕) untuk reset */
    .date-btn-clear {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-left: 2px;
        width: 16px;
        height: 16px;
        font-size: 9px;
        line-height: 1;
        border-radius: 50%;
        background: #c7d2fe;
        color: #4338ca;
        text-decoration: none;
        font-weight: 900;
        pointer-events: all;
        /* Bisa diklik meski tombol induk disabled pointer */
        flex-shrink: 0;
        transition: background 0.15s;
    }

    .date-btn-clear:hover {
        background: #a5b4fc;
    }

    /* Input date yang sesungguhnya — transparan & overlay di ATAS tombol */
    .kaching-date-input-hidden {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        /* Transparan */
        cursor: pointer;
        z-index: 10;
        /* Pastikan di paling depan */
        box-sizing: border-box;
    }

    .kaching-date-input-hidden::-webkit-calendar-picker-indicator {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        margin: 0;
        padding: 0;
        cursor: pointer;
        opacity: 0;
        /* Tetap dibuat siluman */
    }

    /* Hover effect pada wrapper, bukan tombol */
    .date-picker-wrapper:hover .kaching-date-btn {
        border-color: #818cf8;
        color: #4338ca;
        background-color: #f5f3ff;
    }

    /* Hilangkan scrollbar jelek di widget */
    .hide-scroll::-webkit-scrollbar {
        display: none;
    }
</style>
@endsection

@push('scripts')
<style>
    /* Efek Skeleton Loading */
    .skeleton-loading {
        background: linear-gradient(90deg, #334155 25%, #475569 50%, #334155 75%) !important;
        background-size: 200% 100% !important;
        animation: loadingPulse 1.5s infinite !important;
        color: transparent !important;
        border-radius: 0.5rem;
    }

    .skeleton-loading * {
        color: transparent !important;
    }

    .skeleton-light {
        background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%) !important;
        background-size: 200% 100% !important;
        animation: loadingPulse 1.5s infinite !important;
        color: transparent !important;
        border-radius: 0.5rem;
    }

    @keyframes loadingPulse {
        0% {
            background-position: 200% 0;
        }

        100% {
            background-position: -200% 0;
        }
    }

    .chart-container-loading {
        opacity: 0.5;
        filter: blur(1px);
        transition: all 0.3s ease;
    }
</style>

<script>
    // FUNGSI PENYINGKAT RUPIAH UNTUK AJAX
    function formatUangSingkatJS(valueString) {
        // Hapus karakter non-angka yang dikirim backend (seperti 'Rp', '.')
        let numStr = String(valueString).replace(/[^0-9]/g, '');
        if (!numStr) return valueString;

        let num = parseInt(numStr);
        if (num >= 1000000000) {
            return 'Rp ' + (num / 1000000000).toLocaleString('id-ID', {
                maximumFractionDigits: 2
            }) + ' M';
        } else if (num >= 1000000) {
            return 'Rp ' + (num / 1000000).toLocaleString('id-ID', {
                maximumFractionDigits: 2
            }) + ' Jt';
        }
        return 'Rp ' + num.toLocaleString('id-ID');
    }

    let mySalesChart;
    let chart7DaysInstance;
    let chartPaymentInstance;

    function initSalesChart(chartLabels, chartData, filterType) {
        if (mySalesChart) {
            mySalesChart.destroy();
        }

        const ctx = document.getElementById('salesChart').getContext('2d');
        const isBar = filterType === 'year' || filterType === 'all';
        
        let background;
        if (isBar) {
            background = '#818cf8'; // Soft Indigo bar
        } else {
            const gradient = ctx.createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(79, 70, 229, 0.25)');
            gradient.addColorStop(1, 'rgba(79, 70, 229, 0)');
            background = gradient;
        }

        mySalesChart = new Chart(ctx, {
            type: isBar ? 'bar' : 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: @json(__('backoffice.dashboard.charts.revenue_label')),
                    data: chartData,
                    borderColor: '#4f46e5',
                    backgroundColor: background,
                    borderWidth: isBar ? 0 : 3.5,
                    borderRadius: isBar ? 8 : 0,
                    borderSkipped: false,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#4f46e5',
                    pointBorderWidth: 2,
                    pointRadius: 0, // Sembunyikan default dot biar ga padat
                    pointHoverRadius: isBar ? 0 : 6, // Hover dot
                    pointHoverBackgroundColor: '#4f46e5',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 2,
                    fill: !isBar,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 800,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        padding: 12,
                        cornerRadius: 12,
                        titleFont: {
                            family: 'Plus Jakarta Sans',
                            size: 12,
                            weight: 'bold'
                        },
                        bodyFont: {
                            family: 'Plus Jakarta Sans',
                            size: 13,
                            weight: '700'
                        },
                        displayColors: false,
                        callbacks: {
                            label: (ctx) => ' Rp ' + ctx.parsed.y.toLocaleString('id-ID')
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            borderDash: [5, 5],
                            color: '#f1f5f9',
                            drawBorder: false
                        },
                        ticks: {
                            maxTicksLimit: 5, // Batasi horizontal grid lines
                            font: {
                                family: 'Plus Jakarta Sans',
                                size: 11,
                                weight: '600'
                            },
                            color: '#94a3b8',
                            callback: (val) => {
                                if (val === 0) return '0';
                                return val >= 1000000 ? (val / 1000000) + 'jt' : (val / 1000) + 'k';
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                family: 'Plus Jakarta Sans',
                                size: 11,
                                weight: '600'
                            },
                            color: '#94a3b8'
                        }
                    }
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        // --- 1. INISIALISASI CHART AWAL ---
        const chartLabels = @json($chartLabels ?? []);
        const chartData = @json($chartData ?? []);
        const initialFilter = '{{ $currentFilter }}';
        
        initSalesChart(chartLabels, chartData, initialFilter);

        // --- 1.1 INISIALISASI CHART 7 HARI ---
        const chart7DaysLabels = @json($chartOmset7Hari["labels"] ?? []);
        const chart7DaysData = @json($chartOmset7Hari["data"] ?? []);
        const ctx7Days = document.getElementById('chart7Days')?.getContext('2d');

        if (ctx7Days) {
            const gradient7Days = ctx7Days.createLinearGradient(0, 0, 0, 300);
            gradient7Days.addColorStop(0, 'rgba(99, 102, 241, 0.4)'); // Indigo 500
            gradient7Days.addColorStop(1, 'rgba(99, 102, 241, 0)');

            chart7DaysInstance = new Chart(ctx7Days, {
                type: 'line',
                data: {
                    labels: chart7DaysLabels,
                    datasets: [{
                        label: @json(__('backoffice.dashboard.charts.revenue_label')),
                        data: chart7DaysData,
                        borderColor: '#6366f1', // Indigo 500
                        backgroundColor: gradient7Days,
                        borderWidth: 4,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#6366f1',
                        pointBorderWidth: 3,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        fill: true,
                        tension: 0.4 // Smooth curve estetik
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 1200,
                        easing: 'easeOutQuart'
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: '#1e293b',
                            padding: 12,
                            titleFont: {
                                size: 13,
                                weight: 'bold'
                            },
                            bodyFont: {
                                size: 14,
                                weight: 'bold'
                            },
                            callbacks: {
                                label: (ctx) => ' Rp ' + ctx.parsed.y.toLocaleString('id-ID')
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                borderDash: [4, 4],
                                color: '#f1f5f9'
                            },
                            ticks: {
                                font: {
                                    size: 10,
                                    weight: '600',
                                    family: 'Inter'
                                },
                                color: '#94a3b8',
                                callback: (val) => 'Rp ' + (val >= 1000000 ? (val / 1000000) + 'jt' : (val / 1000) + 'k')
                            },
                            border: {
                                display: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 11,
                                    weight: 'bold',
                                    family: 'Inter'
                                },
                                color: '#64748b'
                            },
                            border: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // --- 1.2 INISIALISASI CHART PAYMENT (DOUGHNUT) ---
        const chartPaymentLabels = @json($chartPaymentMethod["labels"] ?? []);
        const chartPaymentData = @json($chartPaymentMethod["data"] ?? []);
        const ctxPayment = document.getElementById('chartPayment')?.getContext('2d');

        if (ctxPayment) {
            chartPaymentInstance = new Chart(ctxPayment, {
                type: 'doughnut',
                data: {
                    labels: chartPaymentLabels,
                    datasets: [{
                        data: chartPaymentData,
                        backgroundColor: [
                            '#10b981', // Emerald (QRIS/Transfer)
                            '#3b82f6', // Blue (Tunai)
                            '#f59e0b', // Amber (Lainnya)
                            '#8b5cf6', // Violet
                            '#ef4444' // Red
                        ],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%', // Donut hole estetik
                    animation: {
                        animateScale: true,
                        animateRotate: true,
                        duration: 1200,
                        easing: 'easeOutQuart'
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                font: {
                                    size: 11,
                                    weight: 'bold',
                                    family: 'Inter'
                                },
                                color: '#475569'
                            }
                        },
                        tooltip: {
                            backgroundColor: '#1e293b',
                            padding: 10,
                            bodyFont: {
                                size: 13,
                                weight: 'bold'
                            },
                            callbacks: {
                                label: (ctx) => ' ' + ctx.label + ': ' + ctx.parsed + ' ' + @json(__('backoffice.dashboard.metrics.transactions_unit'))
                            }
                        }
                    }
                }
            });
        }

        // --- 2. EVENT LISTENERS (SPA) ---

        // Tab Filter (Hari Ini, 7 Hari, dll)
        const filterLinks = document.querySelectorAll('a[href*="filter="]');
        filterLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                filterLinks.forEach(btn => {
                    btn.classList.remove('bg-indigo-600', 'text-white', 'shadow-md');
                    btn.classList.add('bg-white', 'text-slate-600', 'border', 'border-slate-200');
                });
                this.classList.remove('bg-white', 'text-slate-600', 'border', 'border-slate-200');
                this.classList.add('bg-indigo-600', 'text-white', 'shadow-md');
                fetchDashboardData(this.href);
            });
        });

        // Dropdown Cabang
        const outletSelect = document.querySelector('select[name="outlet_id"]');
        if (outletSelect) {
            outletSelect.addEventListener('change', function(e) {
                e.preventDefault();
                // Gunakan URL saat ini dan update outlet_id
                const url = new URL(window.location.href);
                url.searchParams.set('outlet_id', this.value);
                fetchDashboardData(url.toString());
            });
        }

        // --- LOGIKA DATE PICKER AJAX (CSS OVERLAY MODE) ---
        const dateInput = document.getElementById('customDateFilter');
        if (dateInput) {
            dateInput.addEventListener('change', function() {
                if (this.value) {
                    // Matikan warna aktif di tab filter (Hari Ini, 7 Hari, dll)
                    document.querySelectorAll('a[href*="filter="]').forEach(btn => {
                        btn.classList.remove('bg-indigo-600', 'text-white', 'shadow-md');
                        btn.classList.add('bg-white', 'text-slate-600', 'border', 'border-slate-200');
                    });

                    // Ganti teks visual tombol
                    const label = document.querySelector('.date-btn-label');
                    if (label) {
                        label.innerText = this.value;
                    }

                    // Tembak AJAX SPA
                    const currentOutlet = document.querySelector('select[name="outlet_id"]')?.value || 'all';
                    const url = `{{ route('dashboard') }}?filter=custom&date=${this.value}&outlet_id=${currentOutlet}`;
                    fetchDashboardData(url);
                }
            });
        }
    });

    // --- 3. FUNGSI FETCH & UPDATE ---
    function fetchDashboardData(url) {
        // Aktifkan Skeleton (null-safe)
        const omsetEl = document.getElementById('ui-omset');
        const notaEl = document.getElementById('ui-nota');
        const cashEl = document.getElementById('ui-cash');
        const digitalEl = document.getElementById('ui-digital');
        const piutangEl = document.getElementById('ui-piutang');
        if (!omsetEl || !notaEl || !cashEl || !digitalEl) {
            console.warn('[fetchDashboardData] Required DOM elements missing, doing full reload instead.');
            window.location.href = url;
            return;
        }
        const chartContainer = document.getElementById('salesChart').parentElement;

        // Animasi loading untuk chart baru
        const chart7DaysContainer = document.getElementById('chart7Days')?.parentElement;
        const chartPaymentContainer = document.getElementById('chartPayment')?.parentElement;

        // Defensive: skip class add if element missing (we already null-checked omsetEl/notaEl/cashEl/digitalEl above)
        omsetEl?.classList.add('skeleton-loading');
        notaEl?.classList.add('skeleton-loading');
        cashEl?.classList.add('skeleton-light');
        digitalEl?.classList.add('skeleton-light');
        if (piutangEl) piutangEl.classList.add('skeleton-light');
        chartContainer.classList.add('chart-container-loading');
        if (chart7DaysContainer) chart7DaysContainer.classList.add('chart-container-loading');
        if (chartPaymentContainer) chartPaymentContainer.classList.add('chart-container-loading');

        fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(res => {
                if (!res.ok) {
                    if (res.status === 401 || res.status === 419) {
                        window.location.href = '/login';
                        return;
                    }
                    throw new Error('HTTP status ' + res.status);
                }
                return res.json();
            })
            .then(data => {
                if (!data) return;
                document.getElementById('ui-title').innerHTML = data.title;

                // Safe updates (we already verified elements exist above)
                omsetEl.classList.remove('skeleton-loading');
                omsetEl.innerHTML = formatUangSingkatJS(data.todayOmset);
                omsetEl.title = String(data.todayOmset).includes('Rp') ? data.todayOmset : 'Rp ' + data.todayOmset;

                notaEl.classList.remove('skeleton-loading');
                notaEl.innerHTML = data.todayCount;

                cashEl.classList.remove('skeleton-light');
                cashEl.innerHTML = formatUangSingkatJS(data.cashSales);
                cashEl.title = String(data.cashSales).includes('Rp') ? data.cashSales : 'Rp ' + data.cashSales;

                digitalEl.classList.remove('skeleton-light');
                digitalEl.innerHTML = formatUangSingkatJS(data.digitalSales);
                digitalEl.title = String(data.digitalSales).includes('Rp') ? data.digitalSales : 'Rp ' + data.digitalSales;

                if (piutangEl) {
                    piutangEl.classList.remove('skeleton-light');
                    piutangEl.innerHTML = formatUangSingkatJS(data.totalPiutang);
                    piutangEl.title = String(data.totalPiutang).includes('Rp') ? data.totalPiutang : 'Rp ' + data.totalPiutang;
                }
                document.getElementById('ui-chart-subtitle').innerHTML = data.chartSubtitle;

                // Update Chart Mulus
                const urlParams = new URLSearchParams(new URL(url).search);
                const filterType = urlParams.get('filter') || 'today';
                initSalesChart(data.chartLabels, data.chartData, filterType);

                // Update Enterprise Charts
                if (chart7DaysInstance && data.chartOmset7Hari) {
                    chart7DaysInstance.data.labels = data.chartOmset7Hari.labels;
                    chart7DaysInstance.data.datasets[0].data = data.chartOmset7Hari.data;
                    chart7DaysInstance.update();
                }

                if (chartPaymentInstance && data.chartPaymentMethod) {
                    chartPaymentInstance.data.labels = data.chartPaymentMethod.labels;
                    chartPaymentInstance.data.datasets[0].data = data.chartPaymentMethod.data;
                    chartPaymentInstance.update();
                } else if (data.chartPaymentMethod && !chartPaymentInstance) {
                    // In case the chart was empty initially and now has data, we would need to initialize it,
                    // but for simplicity, reloading the page handles this edge case best.
                    // However we can just let it be since it only happens if initial data was 0 and now isn't.
                }

                chartContainer.classList.remove('chart-container-loading');
                if (chart7DaysContainer) chart7DaysContainer.classList.remove('chart-container-loading');
                if (chartPaymentContainer) chartPaymentContainer.classList.remove('chart-container-loading');

                window.history.pushState({}, '', url);
            })
            .catch(err => {
                console.error('Fetch error:', err);
                document.querySelectorAll('.skeleton-loading, .skeleton-light').forEach(el => el.classList.remove('skeleton-loading', 'skeleton-light'));
                chartContainer.classList.remove('chart-container-loading');
                if (chart7DaysContainer) chart7DaysContainer.classList.remove('chart-container-loading');
                if (chartPaymentContainer) chartPaymentContainer.classList.remove('chart-container-loading');
            });
    }
</script>
@endpush