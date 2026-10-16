@php
    $isTestingUser = Auth::check() && (
        str_contains(strtolower(Auth::user()->email), 'testing') || 
        str_contains(strtolower(Auth::user()->email), 'test')
    );
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <title>Kaching POS - @yield('title', 'Dashboard')</title>
    <link rel="icon" type="image/png" href="{{ asset('img/logo.png?v=' . time()) }}">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.2/dist/purify.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @stack('styles')

    <style>
        [x-cloak] {
            display: none !important;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
            /* slate-50 */
        }

        .hide-scroll::-webkit-scrollbar {
            display: none;
        }

        .hide-scroll {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .sidebar-transition {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ── Copilot Markdown & Table Styling ─────────────────────── */
        .copilot-markdown {
            word-break: break-word;
        }
        .copilot-markdown .table-wrapper {
            width: 100%;
            max-width: 100%;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
            margin: 0.75rem 0;
            border-radius: 0.75rem;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
            display: block;
        }
        .copilot-markdown table {
            min-width: 100%;
            width: max-content !important;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.75rem;
            line-height: 1.25rem;
        }
        .copilot-markdown thead {
            background-color: #f8fafc;
            color: #334155;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.65rem;
            letter-spacing: 0.03em;
            border-bottom: 1px solid #e2e8f0;
        }
        .copilot-markdown th {
            padding: 0.6rem 0.85rem;
            white-space: nowrap !important;
            word-break: normal !important;
            overflow-wrap: normal !important;
            border-right: 1px solid #f1f5f9;
        }
        .copilot-markdown th:last-child {
            border-right: none;
        }
        .copilot-markdown tbody {
            color: #334155;
            background-color: #ffffff;
        }
        .copilot-markdown tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: background-color 0.15s ease;
        }
        .copilot-markdown tbody tr:last-child {
            border-bottom: none;
        }
        .copilot-markdown tbody tr:hover {
            background-color: #f8fafc;
        }
        .copilot-markdown td {
            padding: 0.55rem 0.85rem;
            color: #1e293b;
            border-right: 1px solid #f8fafc;
            white-space: nowrap !important;
            word-break: normal !important;
            overflow-wrap: normal !important;
        }
        .copilot-markdown td:last-child {
            border-right: none;
        }
        .copilot-markdown .table-wrapper::-webkit-scrollbar {
            height: 6px;
        }
        .copilot-markdown .table-wrapper::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 9999px;
        }
        .copilot-markdown .table-wrapper::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 9999px;
        }
        .copilot-markdown .table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        .copilot-markdown strong {
            font-weight: 700;
            color: #0f172a;
        }
        .copilot-markdown em {
            font-style: italic;
            color: #334155;
        }
        .copilot-markdown code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.75rem;
            font-weight: 600;
            background-color: #f1f5f9;
            color: #4338ca;
            padding: 0.125rem 0.375rem;
            border-radius: 0.25rem;
        }
        .copilot-markdown pre {
            background-color: #0f172a;
            color: #f8fafc;
            padding: 0.75rem;
            border-radius: 0.5rem;
            overflow-x: auto;
            margin: 0.5rem 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.75rem;
        }
        .copilot-markdown pre code {
            background-color: transparent;
            color: inherit;
            padding: 0;
        }
        .copilot-markdown h1 {
            font-size: 1.125rem;
            font-weight: 800;
            color: #0f172a;
            margin-top: 1rem;
            margin-bottom: 0.5rem;
            letter-spacing: -0.01em;
        }
        .copilot-markdown h2 {
            font-size: 1rem;
            font-weight: 800;
            color: #0f172a;
            margin-top: 0.875rem;
            margin-bottom: 0.375rem;
            letter-spacing: -0.01em;
        }
        .copilot-markdown h3 {
            font-size: 0.875rem;
            font-weight: 700;
            color: #0f172a;
            margin-top: 0.75rem;
            margin-bottom: 0.25rem;
        }
        .copilot-markdown ul {
            list-style-type: disc;
            padding-left: 1.25rem;
            margin: 0.5rem 0;
        }
        .copilot-markdown ol {
            list-style-type: decimal;
            padding-left: 1.25rem;
            margin: 0.5rem 0;
        }
        .copilot-markdown li {
            margin: 0.25rem 0;
            line-height: 1.5;
        }
        .copilot-markdown p {
            margin-top: 0.375rem;
            margin-bottom: 0.375rem;
            line-height: 1.55;
        }
        .copilot-markdown p:first-child {
            margin-top: 0;
        }
        .copilot-markdown p:last-child {
            margin-bottom: 0;
        }
        .copilot-markdown blockquote {
            border-left: 3px solid #6366f1;
            padding-left: 0.75rem;
            margin: 0.5rem 0;
            color: #475569;
            font-style: italic;
            background-color: #f8fafc;
            border-radius: 0 0.375rem 0.375rem 0;
            padding-top: 0.25rem;
            padding-bottom: 0.25rem;
        }
    </style>
</head>

<body class="text-slate-800 antialiased">

    {{-- 🕵️ IMPERSONATION BANNER — Muncul saat Superadmin sedang menyamar sebagai Mitra --}}
    @if(session()->has('impersonated_by'))
    <a href="{{ route('superadmin.impersonate.leave') }}"
        style="display:flex; align-items:center; justify-content:center; gap:12px; position:fixed; top:0; left:0; right:0; z-index:9999; background:linear-gradient(90deg,#dc2626,#b91c1c); color:#fff; font-family:'Plus Jakarta Sans',sans-serif; font-size:13px; font-weight:700; letter-spacing:0.02em; padding:10px 20px; text-decoration:none; box-shadow:0 4px 20px rgba(220,38,38,0.5); cursor:pointer;"
        onmouseover="this.style.background='linear-gradient(90deg,#b91c1c,#991b1b)'"
        onmouseout="this.style.background='linear-gradient(90deg,#dc2626,#b91c1c)'">
        <span style="font-size:18px; line-height:1;">🕵️</span>
        <span>{{ __('backoffice.impersonation.banner_prefix') }} <strong>{{ Auth::user()->name }}</strong> &mdash; {{ __('backoffice.impersonation.banner_suffix') }}</span>
        <span style="background:rgba(255,255,255,0.2); border-radius:6px; padding:3px 10px; font-size:11px; letter-spacing:0.05em; text-transform:uppercase;">{{ __('backoffice.impersonation.leave') }}</span>
    </a>
    <div style="height:43px;"></div>{{-- Spacer agar konten tidak tertutup banner --}}
    @endif

    <div class="flex h-screen overflow-hidden">

        <aside id="sidebar" class="bg-white w-72 flex-shrink-0 border-r border-slate-200/80 flex flex-col sidebar-transition hidden md:flex z-50 fixed md:relative h-full shadow-[4px_0_24px_rgba(0,0,0,0.02)]">

            <div class="h-20 flex items-center px-8 border-b border-slate-100">
                <div class="text-2xl font-black text-slate-800 flex items-center gap-2 tracking-tight">
                    <span class="w-8 h-8 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 flex items-center justify-center text-white shadow-lg shadow-blue-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                    </span>
                    Kaching<span class="text-blue-600">.</span>
                </div>
            </div>

            <nav id="sidebarNav" class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto hide-scroll">

                <a href="{{ route('dashboard') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('dashboard') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                    <svg class="w-5 h-5 {{ request()->routeIs('dashboard') ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                    </svg>
                    <span>{{ __('backoffice.nav.dashboard') }}</span>
                </a>

                <!-- 🛒 POS WEB (CADANGAN KASIR) -->
                @if($isTestingUser)
                    <a href="{{ route('backoffice.pos.index') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('backoffice.pos.*') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                        <span class="text-xl">🛒</span>
                        <span>{{ __('backoffice.nav.pos_web') }}</span>
                    </a>
                @else
                    <div class="group flex items-center justify-between px-4 py-3.5 rounded-[1rem] opacity-50 cursor-not-allowed text-slate-400 font-semibold" title="{{ __('backoffice.tooltips.coming_soon_testing') }}">
                        <div class="flex items-center gap-3.5">
                            <span class="text-xl">🛒</span>
                            <span>{{ __('backoffice.nav.pos_web') }}</span>
                        </div>
                        <span class="text-[9px] bg-slate-200 text-slate-600 px-2 py-0.5 rounded font-bold uppercase tracking-wider">{{ __('backoffice.common.coming_soon') }}</span>
                    </div>
                @endif

                @if($isTestingUser)
                    <a href="{{ route('kds.index') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('kds.*') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                        <span class="text-xl">🍳</span>
                        <span>{{ __('backoffice.nav.kds') }}</span>
                    </a>
                @else
                    <div class="group flex items-center justify-between px-4 py-3.5 rounded-[1rem] opacity-50 cursor-not-allowed text-slate-400 font-semibold" title="{{ __('backoffice.tooltips.coming_soon_testing') }}">
                        <div class="flex items-center gap-3.5">
                            <span class="text-xl">🍳</span>
                            <span>{{ __('backoffice.nav.kds') }}</span>
                        </div>
                        <span class="text-[9px] bg-slate-200 text-slate-600 px-2 py-0.5 rounded font-bold uppercase tracking-wider">{{ __('backoffice.common.coming_soon') }}</span>
                    </div>
                @endif

                <div class="px-4 pt-6 pb-2 text-[11px] font-extrabold text-slate-400 uppercase tracking-widest">{{ __('backoffice.nav_sections.operational') }}</div>

                <div x-data="{ open: {{ request()->routeIs('menus.*') ? 'true' : 'false' }} }" class="space-y-1">
                    <button @click="open = !open"
                        class="w-full group flex items-center justify-between px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('menus.*') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">

                        <div class="flex items-center gap-3.5">
                            <svg class="w-5 h-5 {{ request()->routeIs('menus.*') ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                            <span>{{ __('backoffice.nav.manage_menu') }}</span>
                        </div>

                        <svg class="w-4 h-4 transition-transform duration-200" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>

                    <div x-show="open"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-2"
                        class="pl-12 pr-4 py-1 space-y-1">

                        <a href="{{ route('menus.index') }}"
                            class="block py-2 text-sm font-semibold transition-colors {{ request()->routeIs('menus.index') ? 'text-blue-600' : 'text-slate-400 hover:text-blue-600' }}">
                            • {{ __('backoffice.nav.product_list') }}
                        </a>

                        <a href="{{ route('menus.section-manager') }}"
                            class="block py-2 text-sm font-semibold transition-colors {{ request()->routeIs('menus.section-manager') ? 'text-blue-600' : 'text-slate-400 hover:text-blue-600' }}">
                            • {{ __('backoffice.nav.menu_sections') }}
                        </a>
                    </div>
                </div>

                <a href="{{ route('transactions.history') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('transactions.*') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                    <svg class="w-5 h-5 {{ request()->routeIs('transactions.*') ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                    <span>{{ __('backoffice.nav.transactions') }}</span>
                </a>

                @if(Route::has('shifts.index'))
                <a href="{{ route('shifts.index') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('shifts.*') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                    <svg class="w-5 h-5 {{ request()->routeIs('shifts.*') ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>{{ __('backoffice.nav.shifts') }}</span>
                </a>
                @endif

                <a href="{{ route('reservations.index') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('reservations.*') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                    <svg class="w-5 h-5 {{ request()->routeIs('reservations.*') ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <span>{{ __('backoffice.nav.reservations') }}</span>
                </a>

                <div x-data="{ open: {{ request()->routeIs('staff.*') || request()->routeIs('admin.attendance.*') || request()->routeIs('admin.payroll.*') || request()->routeIs('schedules.*') ? 'true' : 'false' }} }" class="space-y-1">
                    <button @click="open = !open"
                        class="w-full group flex items-center justify-between px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('staff.*') || request()->routeIs('admin.attendance.*') || request()->routeIs('admin.payroll.*') || request()->routeIs('schedules.*') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">

                        <div class="flex items-center gap-3.5">
                            <svg class="w-5 h-5 {{ request()->routeIs('staff.*') || request()->routeIs('admin.attendance.*') || request()->routeIs('admin.payroll.*') || request()->routeIs('schedules.*') ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                            <span>{{ __('backoffice.nav.team_and_staff') }}</span>
                        </div>

                        <svg class="w-4 h-4 transition-transform duration-200" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>

                    <div x-show="open"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-2"
                        class="pl-12 pr-4 py-1 space-y-1">

                        <a href="{{ route('staff.index') }}"
                            class="block py-2 text-sm font-semibold transition-colors {{ request()->routeIs('staff.index') ? 'text-blue-600' : 'text-slate-400 hover:text-blue-600' }}">
                            • {{ __('backoffice.nav.staff_list') }}
                        </a>

                        <a href="{{ route('admin.attendance.index') }}"
                            class="block py-2 text-sm font-semibold transition-colors {{ request()->routeIs('admin.attendance.index') ? 'text-blue-600' : 'text-slate-400 hover:text-blue-600' }}">
                            • {{ __('backoffice.nav.attendance_history') }}
                        </a>

                        <a href="{{ route('admin.payroll.index') }}"
                            class="block py-2 text-sm font-semibold transition-colors {{ request()->routeIs('admin.payroll.index') ? 'text-blue-600' : 'text-slate-400 hover:text-blue-600' }}">
                            • {{ __('backoffice.nav.payroll') }}
                        </a>

                        <a href="{{ route('schedules.index') }}"
                            class="block py-2 text-sm font-semibold transition-colors {{ request()->routeIs('schedules.*') ? 'text-blue-600' : 'text-slate-400 hover:text-blue-600' }}">
                            • {{ __('backoffice.nav.shift_schedules') }}
                        </a>
                    </div>
                </div>

                <a href="{{ route('inventory.index') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('inventory.*') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                    <svg class="w-5 h-5 {{ request()->routeIs('inventory.*') ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                    <span>{{ __('backoffice.nav.inventory') }}</span>
                </a>

                <a href="{{ route('suppliers.index') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('suppliers.*') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                    <svg class="w-5 h-5 {{ request()->routeIs('suppliers.*') ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                    </svg>
                    <span>{{ __('backoffice.nav.suppliers') }}</span>
                </a>

                <a href="{{ route('expenses.index') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('expenses.*') ? 'bg-red-50 text-red-600 font-bold ring-1 ring-red-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                    <svg class="w-5 h-5 {{ request()->routeIs('expenses.*') ? 'text-red-500' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941"></path>
                    </svg>
                    <span>{{ __('backoffice.nav.expenses') }}</span>
                </a>

                <div class="px-4 pt-6 pb-2 text-[11px] font-extrabold text-slate-400 uppercase tracking-widest">{{ __('backoffice.nav_sections.finance') }}</div>

                @if($isTestingUser)
                    <a href="{{ route('copilot.copilot_chat') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('copilot.copilot_chat') ? 'bg-indigo-50 text-indigo-700 font-bold ring-1 ring-indigo-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                        <svg class="w-5 h-5 {{ request()->routeIs('copilot.copilot_chat') ? 'text-indigo-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                        </svg>
                        <span>{{ __('backoffice.nav.copilot') }}</span>
                    </a>

                    <a href="{{ route('copilot.intelligence') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('copilot.intelligence*') ? 'bg-indigo-50 text-indigo-700 font-bold ring-1 ring-indigo-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                        <svg class="w-5 h-5 {{ request()->routeIs('copilot.intelligence*') ? 'text-indigo-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                        </svg>
                        <span>{{ __('backoffice.nav.intelligence') }}</span>
                    </a>
                @else
                    <div class="group flex items-center justify-between px-4 py-3.5 rounded-[1rem] opacity-50 cursor-not-allowed text-slate-400 font-semibold" title="{{ __('backoffice.tooltips.testing_preview') }}">
                        <div class="flex items-center gap-3.5">
                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                            </svg>
                            <span>{{ __('backoffice.nav.intelligence') }}</span>
                        </div>
                        <span class="text-[9px] bg-slate-200 text-slate-600 px-2 py-0.5 rounded font-bold uppercase tracking-wider">{{ __('backoffice.common.coming_soon') }}</span>
                    </div>
                @endif

                <a href="{{ route('reports.index') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('reports.index') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                    <svg class="w-5 h-5 {{ request()->routeIs('reports.index') ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    <span>{{ __('backoffice.nav.profit_loss') }}</span>
                </a>

                <a href="{{ route('cash_flows.index') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('cash_flows.*') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                    <svg class="w-5 h-5 {{ request()->routeIs('cash_flows.*') ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>{{ __('backoffice.nav.cash_flows') }}</span>
                </a>

                <a href="{{ route('reports.menu-analysis') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('reports.menu-analysis') ? 'bg-amber-50 text-amber-700 font-bold ring-1 ring-amber-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                    <svg class="w-5 h-5 {{ request()->routeIs('reports.menu-analysis') ? 'text-amber-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path>
                    </svg>
                    <span>{{ __('backoffice.nav.menu_analysis') }}</span>
                </a>

                <!-- FINTECHATHON: RAW MATERIAL INTELLIGENCE (TESTING ONLY) -->
                @if($isTestingUser)
                    <a href="{{ route('copilot.raw-material-intelligence') }}" class="group flex items-center justify-between px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('copilot.raw-material-intelligence') ? 'bg-emerald-50 text-emerald-800 font-bold ring-1 ring-emerald-200/60 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                        <div class="flex items-center gap-3.5">
                            <svg class="w-5 h-5 {{ request()->routeIs('copilot.raw-material-intelligence') ? 'text-emerald-700' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                            <span>{{ __('backoffice.nav.raw_material_intel') }}</span>
                        </div>
                        <span class="text-[9px] bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded font-bold uppercase tracking-wider">{{ __('backoffice.common.preview') }}</span>
                    </a>
                @endif

                <div class="px-4 pt-6 pb-2 text-[11px] font-extrabold text-slate-400 uppercase tracking-widest">{{ __('backoffice.nav_sections.system') }}</div>

                <a href="{{ route('tables.index') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('tables.*') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                    <svg class="w-5 h-5 {{ request()->routeIs('tables.*') ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    <span>{{ __('backoffice.nav.tables') }}</span>
                </a>

                <a href="{{ route('settings.index') }}" class="group flex items-center gap-3.5 px-4 py-3.5 rounded-[1rem] transition-all duration-200 {{ request()->routeIs('settings.*') ? 'bg-blue-50 text-blue-700 font-bold ring-1 ring-blue-100/50 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800 font-semibold' }}">
                    <svg class="w-5 h-5 {{ request()->routeIs('settings.*') ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span>{{ __('backoffice.nav.settings') }}</span>
                </a>
            </nav>

            <div class="p-5 border-t border-slate-100 bg-slate-50/50 m-2 rounded-2xl mb-4 shadow-sm border">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3 overflow-hidden">
                        <div class="w-10 h-10 rounded-[10px] bg-blue-100 flex items-center justify-center text-blue-600 font-black uppercase flex-shrink-0 text-lg">
                            {{ substr(Auth::user()?->name ?? 'O', 0, 1) }}
                        </div>
                        <div class="truncate">
                            <div class="text-sm font-black text-slate-800 truncate leading-tight">{{ Auth::user()?->name ?? 'Owner / Admin' }}</div>
                            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-widest mt-0.5">{{ __('backoffice.common.administrator') }}</div>
                        </div>
                    </div>

                    <form action="{{ route('web.logout') }}" method="POST" class="flex-shrink-0 ml-1">
                        @csrf
                        <button type="submit" class="p-2.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-xl transition-all active:scale-95" title="{{ __('backoffice.common.logout') }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="flex-1 flex flex-col overflow-hidden relative w-full bg-slate-50/50">

            <header class="h-20 bg-white/80 backdrop-blur-lg border-b border-slate-200/80 flex items-center justify-between px-4 sm:px-8 z-40 sticky top-0">
                <div class="flex items-center gap-3 sm:gap-4 min-w-0 flex-1 mr-2 sm:mr-4">
                    <button onclick="toggleSidebar()" class="p-2 -ml-2 rounded-xl text-slate-500 hover:bg-slate-100 md:hidden active:scale-95 transition-all flex-shrink-0" aria-label="Toggle Navigation">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"></path>
                        </svg>
                    </button>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-800 tracking-tight truncate">@yield('title', __('backoffice.nav.dashboard'))</h1>
                </div>

                <div class="flex items-center gap-2.5 sm:gap-3 flex-shrink-0">
                    {{-- Language Toggle ID | EN (Header Kanan Atas) --}}
                    <div class="inline-flex items-center bg-white border border-slate-200 rounded-full p-1 shadow-sm flex-shrink-0">
                        <span class="pl-2 pr-1.5 flex items-center text-blue-600" aria-hidden="true">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zM3.6 9h16.8M3.6 15h16.8M12 3a15.3 15.3 0 014 9 15.3 15.3 0 01-4 9 15.3 15.3 0 014-9z"></path>
                            </svg>
                        </span>
                        <div class="flex items-center gap-0.5">
                            <a href="{{ route('lang.switch', 'id') }}"
                               class="px-2.5 py-1 text-xs font-black rounded-full transition-all {{ app()->getLocale() === 'id' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' }}"
                               title="{{ __('backoffice.common.indonesian') }}"
                               aria-label="Bahasa Indonesia">
                                ID
                            </a>
                            <a href="{{ route('lang.switch', 'en') }}"
                               class="px-2.5 py-1 text-xs font-black rounded-full transition-all {{ app()->getLocale() === 'en' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' }}"
                               title="{{ __('backoffice.common.english') }}"
                               aria-label="English">
                                EN
                            </a>
                        </div>
                    </div>

                    {{-- Dynamic Carbon Date Badge (Desktop only) --}}
                    <div class="bg-white px-4 py-2 rounded-full border border-slate-200 shadow-sm flex items-center gap-2 hidden sm:flex flex-shrink-0">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="text-xs font-bold text-slate-600 tracking-wide uppercase">{{ \Carbon\Carbon::now()->translatedFormat('d F Y') }}</span>
                    </div>
                </div>
            </header>

            <main class="flex-1 overflow-x-hidden overflow-y-auto p-4 sm:p-8">
                <div class="max-w-7xl mx-auto">
                    {{-- Global Alert Banner --}}
                    @if(session('success'))
                        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex items-start gap-3 shadow-sm font-sans">
                            <span class="text-xl">✅</span>
                            <div>
                                <h4 class="font-bold text-sm">{{ __('backoffice.common.success') }}</h4>
                                <p class="text-xs text-emerald-700/90 mt-0.5">{{ session('success') }}</p>
                            </div>
                        </div>
                    @endif

                    @if(session('warning'))
                        <div class="mb-6 p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl flex items-start gap-3 shadow-sm font-sans">
                            <span class="text-xl">⚠️</span>
                            <div>
                                <h4 class="font-bold text-sm font-sans">{{ __('backoffice.common.info') }}</h4>
                                <p class="text-xs text-amber-700/90 mt-0.5 font-sans">{{ session('warning') }}</p>
                            </div>
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="mb-6 p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl flex items-start gap-3 shadow-sm font-sans">
                            <span class="text-xl">❌</span>
                            <div>
                                <h4 class="font-bold text-sm">Error</h4>
                                <p class="text-xs text-rose-700/90 mt-0.5">{{ session('error') }}</p>
                            </div>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </main>

        </div>
    </div>

    <div id="sidebarOverlay" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 hidden md:hidden opacity-0 transition-opacity duration-300" onclick="toggleSidebar()"></div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');

            sidebar.classList.toggle('hidden');

            // Animasi overlay untuk mobile
            if (sidebar.classList.contains('hidden')) {
                overlay.classList.add('opacity-0');
                setTimeout(() => overlay.classList.add('hidden'), 300);
            } else {
                overlay.classList.remove('hidden');
                setTimeout(() => overlay.classList.remove('opacity-0'), 10);
            }
        }

        // ── Copilot Universal Markdown & Table Renderer ────────────
        window.renderCopilotMarkdown = function(content) {
            if (!content) return '';

            // 1. Try Marked.js + DOMPurify ONLY if BOTH are loaded and functional
            if (typeof window.marked !== 'undefined' && typeof window.marked.parse === 'function' &&
                typeof window.DOMPurify !== 'undefined' && typeof window.DOMPurify.sanitize === 'function') {
                try {
                    let rawHtml = window.marked.parse(String(content), {
                        breaks: true,
                        gfm: true
                    });
                    if (!rawHtml.includes('class="table-wrapper"')) {
                        rawHtml = rawHtml.replace(/<table(\s*[^>]*)>/gi, '<div class="table-wrapper"><table class="copilot-table"$1>').replace(/<\/table>/gi, '</table></div>');
                    }
                    return window.DOMPurify.sanitize(rawHtml);
                } catch (e) {
                    console.warn('marked parse error:', e);
                }
            }

            // 2. High-Fidelity Fallback Parser (zero CDN dependency)
            let text = String(content)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            const lines = text.split(/\r?\n/);
            const output = [];
            let inTable = false;
            let tableBuffer = [];
            let inList = false;
            let listType = null;

            function flushList() {
                if (inList) {
                    output.push(listType === 'ul' ? '</ul>' : '</ol>');
                    inList = false;
                    listType = null;
                }
            }

            function formatInline(str) {
                if (!str) return '';
                return str
                    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s\)\"\'<>]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-bold text-indigo-600 hover:text-indigo-800 underline decoration-indigo-300 hover:decoration-indigo-600">$1 ↗</a>')
                    .replace(/\*\*(.+?)\*\*/g, '<strong class="font-bold text-slate-900">$1</strong>')
                    .replace(/__(.+?)__/g, '<strong class="font-bold text-slate-900">$1</strong>')
                    .replace(/\*(.+?)\*/g, '<em class="italic text-slate-700">$1</em>')
                    .replace(/_([^_]+)_/g, '<em class="italic text-slate-700">$1</em>')
                    .replace(/`([^`]+)`/g, '<code class="px-1.5 py-0.5 bg-slate-100 text-indigo-700 rounded font-mono text-[11px] font-semibold">$1</code>');
            }

            function flushTable(buf) {
                if (buf.length < 2) {
                    return buf.map(l => '<p class="my-1">' + formatInline(l) + '</p>').join('');
                }
                const parseRow = (line) => {
                    const raw = line.trim();
                    const trimmed = raw.startsWith('|') ? raw.slice(1) : raw;
                    const stripped = trimmed.endsWith('|') ? trimmed.slice(0, -1) : trimmed;
                    return stripped.split('|').map(s => s.trim());
                };
                const headerCells = parseRow(buf[0]);
                const sepCells = parseRow(buf[1]);
                if (!sepCells.some(s => s.includes('-'))) {
                    return buf.map(l => '<p class="my-1">' + formatInline(l) + '</p>').join('');
                }
                const aligns = sepCells.map(s => {
                    if (s.startsWith(':') && s.endsWith(':')) return 'center';
                    if (s.endsWith(':')) return 'right';
                    return 'left';
                });

                let html = '<div class="table-wrapper"><table class="copilot-table"><thead><tr>';
                headerCells.forEach((c, idx) => {
                    const align = aligns[idx] || 'left';
                    const alignClass = align === 'right' ? 'text-right' : (align === 'center' ? 'text-center' : 'text-left');
                    html += `<th class="${alignClass}">${formatInline(c)}</th>`;
                });
                html += '</tr></thead><tbody>';

                for (let r = 2; r < buf.length; r++) {
                    const rowCells = parseRow(buf[r]);
                    if (rowCells.length === 0 || (rowCells.length === 1 && rowCells[0] === '')) continue;
                    html += '<tr>';
                    rowCells.forEach((c, idx) => {
                        const align = aligns[idx] || 'left';
                        const alignClass = align === 'right' ? 'text-right' : (align === 'center' ? 'text-center' : 'text-left');
                        html += `<td class="${alignClass}">${formatInline(c)}</td>`;
                    });
                    html += '</tr>';
                }
                html += '</tbody></table></div>';
                return html;
            }

            for (let i = 0; i < lines.length; i++) {
                const line = lines[i];
                const trimmed = line.trim();

                if (trimmed.startsWith('|') && (trimmed.endsWith('|') || trimmed.includes('|'))) {
                    flushList();
                    inTable = true;
                    tableBuffer.push(line);
                    continue;
                } else if (inTable) {
                    output.push(flushTable(tableBuffer));
                    tableBuffer = [];
                    inTable = false;
                }

                if (!trimmed) {
                    flushList();
                    continue;
                }

                if (trimmed.startsWith('### ')) {
                    flushList();
                    output.push(`<h3 class="font-extrabold text-slate-900 text-sm mt-3 mb-1.5 tracking-tight">${formatInline(trimmed.slice(4))}</h3>`);
                    continue;
                }
                if (trimmed.startsWith('## ')) {
                    flushList();
                    output.push(`<h2 class="font-black text-slate-900 text-base mt-4 mb-2 tracking-tight">${formatInline(trimmed.slice(3))}</h2>`);
                    continue;
                }
                if (trimmed.startsWith('# ')) {
                    flushList();
                    output.push(`<h1 class="font-black text-slate-900 text-lg mt-4 mb-2 tracking-tight">${formatInline(trimmed.slice(2))}</h1>`);
                    continue;
                }

                if (/^[-*]\s+/.test(trimmed)) {
                    if (!inList || listType !== 'ul') {
                        flushList();
                        output.push('<ul class="list-disc pl-5 my-1.5 space-y-1 text-slate-700">');
                        inList = true;
                        listType = 'ul';
                    }
                    const itemContent = trimmed.replace(/^[-*]\s+(\[[\sxX]?\]\s+)?/, '');
                    output.push(`<li class="leading-relaxed">${formatInline(itemContent)}</li>`);
                    continue;
                }

                if (/^\d+\.\s+/.test(trimmed)) {
                    if (!inList || listType !== 'ol') {
                        flushList();
                        output.push('<ol class="list-decimal pl-5 my-1.5 space-y-1 text-slate-700">');
                        inList = true;
                        listType = 'ol';
                    }
                    output.push(`<li class="leading-relaxed">${formatInline(trimmed.replace(/^\d+\.\s+/, ''))}</li>`);
                    continue;
                }

                flushList();
                output.push(`<p class="my-1 leading-relaxed">${formatInline(line)}</p>`);
            }

            if (inTable) {
                output.push(flushTable(tableBuffer));
            }
            flushList();

            return output.join('');
        };

        // ── Sidebar Scroll State Persistence ───────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            const nav = document.getElementById('sidebarNav');
            if (!nav) return;

            // Kembalikan posisi scroll yang tersimpan
            const savedScroll = sessionStorage.getItem('sidebarScroll');
            if (savedScroll !== null) {
                nav.scrollTop = parseInt(savedScroll, 10);
            }

            // Simpan posisi scroll setiap kali berubah
            nav.addEventListener('scroll', function() {
                sessionStorage.setItem('sidebarScroll', nav.scrollTop);
            });
        });
    </script>

    @unless(View::hasSection('hide_copilot_widget') || request()->routeIs('copilot.copilot_chat') || request()->is('copilot/copilot_chat*') || request()->is('*copilot_chat*'))
        @include('components.copilot-widget')
    @endunless

    @stack('scripts')
</body>

</html>