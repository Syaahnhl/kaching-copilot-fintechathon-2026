@extends('backoffice.master')

@section('title', 'Kaching Copilot')
@section('hide_copilot_widget', true)

@section('content')
<div class="flex flex-col h-[calc(100dvh-10.5rem)] sm:h-[calc(100vh-12rem)] md:h-[calc(100vh-8rem)]" x-data="copilotChatPage()" x-init="initPage()">

    {{-- HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-5 border-b border-slate-200/80 flex-shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600 shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                </svg>
            </div>
            <div>
                <h1 class="text-xl md:text-2xl font-black text-slate-900 tracking-tight">Kaching Copilot</h1>
                <p class="text-xs text-slate-500 font-medium">{{ __('copilot.chat.subtitle') }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" @click="resetChat()"
                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 transition-all shadow-sm">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                {{ __('copilot.widget.reset_title') }}
            </button>
            <a href="{{ route('copilot.intelligence') }}"
                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold text-indigo-700 bg-indigo-50 border border-indigo-100 hover:bg-indigo-100 transition-all shadow-sm">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                </svg>
                Kaching Intelligence
            </a>
        </div>
    </div>

    {{-- CONVERSATION AREA --}}
    <div x-ref="chatContainer" class="flex-1 overflow-y-auto py-6 space-y-4 hide-scroll">

        {{-- EMPTY STATE --}}
        <div x-show="messages.length === 0 && !loading" class="flex flex-col items-center justify-start sm:justify-center h-full text-center px-4 pt-4 sm:pt-0">
            <div class="w-16 h-16 rounded-2xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-500 mb-4 sm:mb-5 shadow-sm">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                </svg>
            </div>
            <h2 class="text-lg font-black text-slate-800 tracking-tight">{{ __('copilot.widget.empty_state_title') }}</h2>
            <p class="text-sm text-slate-500 mt-1 max-w-md">{{ __('copilot.widget.empty_state_desc') }}</p>

            <div class="mt-6 w-full max-w-lg grid grid-cols-1 sm:grid-cols-2 gap-2">
                <button type="button" @click="sendQuickPrompt(@js(__('copilot.widget.quick_sales')))"
                    class="text-left p-3 rounded-xl bg-white border border-slate-200 hover:border-indigo-300 hover:bg-indigo-50/50 transition-all text-xs text-slate-700 font-medium shadow-sm">
                    <span class="font-bold text-slate-800 block mb-0.5">{{ __('copilot.widget.quick_sales_title') }}</span>
                    {{ __('copilot.widget.quick_sales') }}
                </button>
                <button type="button" @click="sendQuickPrompt(@js(__('copilot.widget.quick_menu')))"
                    class="text-left p-3 rounded-xl bg-white border border-slate-200 hover:border-indigo-300 hover:bg-indigo-50/50 transition-all text-xs text-slate-700 font-medium shadow-sm">
                    <span class="font-bold text-slate-800 block mb-0.5">{{ __('copilot.widget.quick_menu_title') }}</span>
                    {{ __('copilot.widget.quick_menu') }}
                </button>
                <button type="button" @click="sendQuickPrompt(@js(__('copilot.widget.quick_risk')))"
                    class="text-left p-3 rounded-xl bg-white border border-slate-200 hover:border-indigo-300 hover:bg-indigo-50/50 transition-all text-xs text-slate-700 font-medium shadow-sm">
                    <span class="font-bold text-slate-800 block mb-0.5">{{ __('copilot.widget.quick_risk_title') }}</span>
                    {{ __('copilot.widget.quick_risk') }}
                </button>
                <button type="button" @click="sendQuickPrompt(@js(__('copilot.widget.quick_cashflow')))"
                    class="text-left p-3 rounded-xl bg-white border border-slate-200 hover:border-indigo-300 hover:bg-indigo-50/50 transition-all text-xs text-slate-700 font-medium shadow-sm">
                    <span class="font-bold text-slate-800 block mb-0.5">{{ __('copilot.widget.quick_cashflow_title') }}</span>
                    {{ __('copilot.widget.quick_cashflow') }}
                </button>
            </div>
        </div>

        {{-- MESSAGES --}}
        <template x-for="(msg, index) in messages" :key="index">
            <div>
                {{-- User message --}}
                <template x-if="msg.role === 'user'">
                    <div class="flex justify-end px-2">
                        <div class="max-w-[80%] md:max-w-[60%] bg-indigo-600 text-white rounded-2xl rounded-tr-sm px-4 py-3 text-sm shadow-sm leading-relaxed whitespace-pre-line"
                             x-text="msg.content"></div>
                    </div>
                </template>

                {{-- Assistant message --}}
                <template x-if="msg.role === 'assistant'">
                    <div class="flex flex-col items-start px-2 space-y-2 max-w-[95%] md:max-w-[85%] lg:max-w-[75%] min-w-0">
                        <div class="w-full min-w-0 bg-white border border-slate-200/80 text-slate-800 rounded-2xl rounded-tl-sm p-4 text-sm shadow-sm leading-relaxed overflow-hidden">
                            <div class="copilot-markdown text-slate-700 leading-relaxed text-xs sm:text-sm select-text" x-html="renderCopilotMarkdown(msg.content)"></div>

                            {{-- Financial Card: Menu Intelligence --}}
                            <template x-if="msg.financial_card && msg.financial_card.type === 'menu_intelligence'">
                                <div class="mt-3 p-3 bg-slate-50 border border-indigo-100 rounded-xl space-y-2 text-xs">
                                    <div class="flex items-center justify-between pb-1.5 border-b border-indigo-100/70">
                                        <div class="font-bold text-indigo-900" x-text="msg.financial_card.title"></div>
                                        <span class="text-[10px] font-semibold bg-indigo-100 text-indigo-800 px-1.5 py-0.5 rounded"
                                              x-text="msg.financial_card.data?.period?.label || @js(__('copilot.chat.default_30_days'))"></span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-1.5 pt-1">
                                        <div class="p-2 bg-emerald-50 border border-emerald-200 rounded-lg">
                                            <div class="text-[10px] font-bold text-emerald-800 uppercase tracking-wider">{{ __('copilot.widget.quadrant_star') }}</div>
                                            <div class="text-sm font-extrabold text-emerald-900 mt-0.5"
                                                 x-text="(msg.financial_card.data?.summary?.quadrant_counts?.STAR || 0) + ' ' + @js(__('copilot.widget.menu_unit'))"></div>
                                        </div>
                                        <div class="p-2 bg-blue-50 border border-blue-200 rounded-lg">
                                            <div class="text-[10px] font-bold text-blue-800 uppercase tracking-wider">{{ __('copilot.widget.quadrant_cash_cow') }}</div>
                                            <div class="text-sm font-extrabold text-blue-900 mt-0.5"
                                                 x-text="(msg.financial_card.data?.summary?.quadrant_counts?.CASH_COW || 0) + ' ' + @js(__('copilot.widget.menu_unit'))"></div>
                                        </div>
                                        <div class="p-2 bg-amber-50 border border-amber-200 rounded-lg">
                                            <div class="text-[10px] font-bold text-amber-800 uppercase tracking-wider">{{ __('copilot.widget.quadrant_puzzle') }}</div>
                                            <div class="text-sm font-extrabold text-amber-900 mt-0.5"
                                                 x-text="(msg.financial_card.data?.summary?.quadrant_counts?.PUZZLE || 0) + ' ' + @js(__('copilot.widget.menu_unit'))"></div>
                                        </div>
                                        <div class="p-2 bg-rose-50 border border-rose-200 rounded-lg">
                                            <div class="text-[10px] font-bold text-rose-800 uppercase tracking-wider">{{ __('copilot.widget.quadrant_dog') }}</div>
                                            <div class="text-sm font-extrabold text-rose-900 mt-0.5"
                                                 x-text="(msg.financial_card.data?.summary?.quadrant_counts?.DOG || 0) + ' ' + @js(__('copilot.widget.menu_unit'))"></div>
                                        </div>
                                    </div>
                                    <template x-if="msg.financial_card.data?.summary?.top_stars && msg.financial_card.data.summary.top_stars.length > 0">
                                        <div class="mt-2 pt-2 border-t border-slate-200">
                                            <div class="text-[11px] font-bold text-slate-800 mb-1">{{ __('copilot.widget.top_menu_title') }}</div>
                                            <div class="space-y-1">
                                                <template x-for="item in msg.financial_card.data.summary.top_stars.slice(0, 3)" :key="item.name">
                                                    <div class="flex items-center justify-between text-[11px] bg-white p-1.5 rounded border border-slate-200">
                                                        <span class="font-medium text-slate-800" x-text="item.name"></span>
                                                        <span class="text-emerald-700 font-semibold" x-text="item.stats || 'STAR'"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Financial Card: Warning --}}
                            <template x-if="msg.financial_card && msg.financial_card.type === 'warning'">
                                <div class="mt-3 p-3 bg-amber-50/70 border border-amber-200 rounded-xl space-y-1.5 text-xs">
                                    <div class="flex items-center justify-between font-bold text-amber-900">
                                        <span x-text="msg.financial_card.title"></span>
                                        <span class="text-[10px] bg-amber-200 text-amber-900 px-1.5 py-0.5 rounded font-semibold uppercase">{{ __('copilot.widget.warning_label') }}</span>
                                    </div>
                                    <template x-if="msg.financial_card.data?.warnings">
                                        <div class="space-y-1 pt-1">
                                            <template x-for="(w, idx) in msg.financial_card.data.warnings" :key="idx">
                                                <div class="p-2 bg-white border border-amber-200 rounded text-[11px] text-slate-700" x-text="w.message || w.title"></div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Financial Card: Scenario --}}
                            <template x-if="msg.financial_card && msg.financial_card.type === 'scenario'">
                                <div class="mt-3 p-3 bg-sky-50/70 border border-sky-200 rounded-xl space-y-1.5 text-xs">
                                    <div class="font-bold text-sky-900" x-text="msg.financial_card.title"></div>
                                    <div class="grid grid-cols-2 gap-1.5 pt-1 text-[11px]">
                                        <div class="bg-white p-2 rounded border border-sky-100">
                                            <div class="text-slate-500">{{ __('copilot.chat.baseline_net_profit') }}</div>
                                            <div class="font-bold text-slate-800 mt-0.5" x-text="'Rp ' + Number(msg.financial_card.data?.baseline?.net_profit || 0).toLocaleString('id-ID')"></div>
                                        </div>
                                        <div class="bg-white p-2 rounded border border-sky-100">
                                            <div class="text-slate-500">{{ __('copilot.chat.scenario_net_profit') }}</div>
                                            <div class="font-bold mt-0.5" :class="(msg.financial_card.data?.delta?.net_profit || 0) >= 0 ? 'text-emerald-700' : 'text-rose-700'"
                                                 x-text="'Rp ' + Number(msg.financial_card.data?.result?.net_profit || 0).toLocaleString('id-ID')"></div>
                                        </div>
                                    </div>
                                    <template x-if="msg.financial_card.data?.delta">
                                        <div class="text-[11px] pt-1">
                                            <span class="text-slate-500">{{ __('copilot.chat.impact_label') }}</span>
                                            <span class="font-bold" :class="(msg.financial_card.data.delta.net_profit || 0) >= 0 ? 'text-emerald-700' : 'text-rose-700'"
                                                  x-text="((msg.financial_card.data.delta.net_profit || 0) >= 0 ? '+' : '') + 'Rp ' + Number(msg.financial_card.data.delta.net_profit || 0).toLocaleString('id-ID') + ' (' + (msg.financial_card.data.delta.net_profit_percent || 0).toFixed(1) + '%)'"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Financial Card: Forecast --}}
                            <template x-if="msg.financial_card && msg.financial_card.type === 'forecast'">
                                <div class="mt-3 p-3 bg-emerald-50/70 border border-emerald-200 rounded-xl space-y-1.5 text-xs">
                                    <div class="font-bold text-emerald-900" x-text="msg.financial_card.title"></div>
                                    <div class="grid grid-cols-3 gap-1.5 pt-1 text-[11px]">
                                        <div class="bg-white p-2 rounded border border-emerald-100">
                                            <div class="text-slate-500">{{ __('copilot.chat.cash_inflow') }}</div>
                                            <div class="font-bold text-emerald-800 mt-0.5" x-text="'Rp ' + Number(msg.financial_card.data?.projected_inflow || 0).toLocaleString('id-ID')"></div>
                                        </div>
                                        <div class="bg-white p-2 rounded border border-emerald-100">
                                            <div class="text-slate-500">{{ __('copilot.chat.cash_outflow') }}</div>
                                            <div class="font-bold text-rose-700 mt-0.5" x-text="'Rp ' + Number(msg.financial_card.data?.projected_outflow || 0).toLocaleString('id-ID')"></div>
                                        </div>
                                        <div class="bg-white p-2 rounded border border-emerald-100">
                                            <div class="text-slate-500">{{ __('copilot.chat.net_cash') }}</div>
                                            <div class="font-extrabold mt-0.5" :class="(msg.financial_card.data?.projected_net_cash_flow || 0) >= 0 ? 'text-emerald-800' : 'text-rose-700'"
                                                 x-text="'Rp ' + Number(msg.financial_card.data?.projected_net_cash_flow || 0).toLocaleString('id-ID')"></div>
                                        </div>
                                    </div>
                                    <template x-if="msg.financial_card.data?.daily_forecast && msg.financial_card.data.daily_forecast.length > 0">
                                        <div class="mt-2 pt-2 border-t border-emerald-200/70">
                                            <div class="text-[11px] font-bold text-slate-700 mb-1">{{ __('copilot.chat.daily_forecast_title') }}</div>
                                            <div class="space-y-1 max-h-32 overflow-y-auto hide-scroll">
                                                <template x-for="(d, di) in msg.financial_card.data.daily_forecast" :key="di">
                                                    <div class="flex items-center justify-between text-[11px] bg-white p-1.5 rounded border border-slate-200">
                                                        <span class="font-medium text-slate-600" x-text="d.weekday + ', ' + d.date"></span>
                                                        <span class="font-bold" :class="(d.projected_net_operating_cash_flow || 0) >= 0 ? 'text-emerald-700' : 'text-rose-600'"
                                                              x-text="'Rp ' + Number(d.projected_net_operating_cash_flow || 0).toLocaleString('id-ID')"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Financial Card: Summary (Today Sales) --}}
                            <template x-if="msg.financial_card && msg.financial_card.type === 'summary'">
                                <div class="mt-3 p-3 bg-blue-50/70 border border-blue-200 rounded-xl text-xs">
                                    <div class="font-bold text-blue-900" x-text="msg.financial_card.title"></div>
                                    <div class="bg-white p-2.5 rounded-lg border border-blue-100 mt-1.5">
                                        <div class="text-slate-500 text-[11px]">{{ __('copilot.chat.total_sales') }}</div>
                                        <div class="text-lg font-extrabold text-blue-900 mt-0.5" x-text="msg.financial_card.data?.formatted_sales || 'Rp 0'"></div>
                                        <div class="text-[10px] text-slate-400 mt-0.5" x-text="msg.financial_card.data?.date || ''"></div>
                                    </div>
                                </div>
                            </template>

                            {{-- Financial Card: Radar --}}
                            <template x-if="msg.financial_card && msg.financial_card.type === 'radar'">
                                <div class="mt-3 p-3 bg-violet-50/70 border border-violet-200 rounded-xl space-y-1.5 text-xs">
                                    <div class="flex items-center justify-between font-bold text-violet-900">
                                        <span x-text="msg.financial_card.title"></span>
                                        <span class="text-[10px] bg-violet-200 text-violet-900 px-1.5 py-0.5 rounded font-semibold"
                                              x-text="(msg.financial_card.data?.matched_items_count > 0 ? (msg.financial_card.data.matched_items_count + ' ' + @js(__('copilot.chat.exposed_materials_badge')) + ' • ') : '') + (msg.financial_card.data?.raw_signals_count || msg.financial_card.data?.signals_count || 0) + ' ' + @js(__('copilot.chat.news_unit'))"></span>
                                    </div>
                                    <template x-if="msg.financial_card.data?.signals">
                                        <div class="space-y-1.5 pt-1">
                                            <template x-for="(s, si) in (msg.financial_card.data.signals || []).slice(0, 3)" :key="si">
                                                <div class="p-2.5 bg-white border border-violet-200 rounded text-[11px] space-y-1">
                                                    <div class="flex items-start justify-between gap-1.5">
                                                        <div class="font-bold text-slate-800 flex-1 leading-snug" x-text="s.headline"></div>
                                                        <span x-show="s.trend_label" class="text-[9px] px-1.5 py-0.5 rounded font-semibold shrink-0"
                                                              :class="s.price_direction === 'UP' ? 'bg-amber-100 text-amber-800 border border-amber-200' : (s.price_direction === 'DOWN' ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-slate-100 text-slate-700')"
                                                              x-text="s.trend_label"></span>
                                                    </div>
                                                    <div class="text-slate-600 text-[10px] leading-relaxed" x-text="s.summary"></div>
                                                    <div class="text-[9px] text-slate-400 flex items-center justify-between pt-0.5" x-show="s.source_name">
                                                        <span x-text="@js(__('copilot.widget.source_prefix')) + s.source_name"></span>
                                                        <span x-show="s.freshness_status" x-text="s.freshness_status"></span>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Financial Card: Financing Readiness --}}
                            <template x-if="msg.financial_card && msg.financial_card.type === 'financing_readiness'">
                                <div class="mt-3 p-3.5 bg-purple-50/70 border border-purple-200 rounded-xl space-y-2.5 text-xs">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="font-bold text-purple-950 text-sm" x-text="msg.financial_card.title"></div>
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full"
                                              :class="msg.financial_card.severity === 'success' ? 'bg-emerald-100 text-emerald-800' : (msg.financial_card.severity === 'watch' ? 'bg-purple-100 text-purple-800' : 'bg-amber-100 text-amber-800')"
                                              x-text="msg.financial_card.data?.band || ''"></span>
                                    </div>

                                    <!-- Score & Period Banner -->
                                    <div class="bg-white p-3 rounded-lg border border-purple-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                        <div>
                                            <div class="text-slate-500 text-[10px] font-semibold">{{ __('copilot.widget.readiness_score') }}</div>
                                            <div class="flex items-baseline gap-1 mt-0.5">
                                                <span class="text-2xl font-black text-purple-950" x-text="msg.financial_card.data?.score || 0"></span>
                                                <span class="text-xs text-slate-400">/ 100</span>
                                            </div>
                                        </div>
                                        <div class="text-left sm:text-right text-[10px] text-slate-500" x-show="msg.financial_card.data?.evaluation_period?.start_date">
                                            <div class="font-semibold text-slate-700">{{ __('copilot.chat.evaluation_period') }}</div>
                                            <div class="font-mono text-slate-600" x-text="(msg.financial_card.data?.evaluation_period?.start_date || '') + ' ' + @js(__('copilot.chat.to_separator')) + ' ' + (msg.financial_card.data?.evaluation_period?.end_date || '')"></div>
                                        </div>
                                    </div>

                                    <!-- 5 Pilar Mini Grid -->
                                    <template x-if="msg.financial_card.data?.pillars">
                                        <div class="space-y-1.5">
                                            <div class="text-[10px] font-bold text-slate-600 uppercase tracking-wider">{{ __('copilot.widget.five_pillars_title') }}</div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                                                <template x-for="(pillar, pKey) in msg.financial_card.data.pillars" :key="pKey">
                                                    <div class="bg-white p-2 rounded-lg border border-purple-100 flex items-center justify-between text-[11px]">
                                                        <div>
                                                            <div class="font-bold text-slate-800 capitalize" x-text="pKey.replace('_', ' ')"></div>
                                                            <div class="text-[9px] text-slate-400 font-mono" x-text="@js(__('copilot.chat.weight_prefix')) + Math.round(pillar.weight * 100) + '%'"></div>
                                                        </div>
                                                        <div class="text-right">
                                                            <span class="font-black text-purple-950" x-text="pillar.score"></span>
                                                            <span class="text-[9px] text-slate-400">/100</span>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Evidence Summary Checklist -->
                                    <template x-if="msg.financial_card.data?.evidence_summary && msg.financial_card.data.evidence_summary.length > 0">
                                        <div class="space-y-1.5 pt-1">
                                            <div class="flex items-center justify-between">
                                                <div class="text-[10px] font-bold text-slate-600 uppercase tracking-wider">{{ __('copilot.widget.financial_evidence_title') }}</div>
                                                <span class="text-[9px] text-slate-400">{{ __('copilot.chat.verified_pos_db') }}</span>
                                            </div>
                                            <div class="space-y-1.5">
                                                <template x-for="item in msg.financial_card.data.evidence_summary" :key="item.key">
                                                    <div class="bg-white p-2.5 rounded-lg border border-slate-200 text-xs space-y-1">
                                                        <div class="flex items-center justify-between gap-1">
                                                            <span class="font-bold text-slate-800" x-text="item.label"></span>
                                                            <span class="text-[9px] font-bold px-2 py-0.5 rounded-full"
                                                                  :class="item.status === 'AVAILABLE' ? 'bg-emerald-100 text-emerald-800' : (item.status === 'PARTIAL' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600')"
                                                                  x-text="item.status"></span>
                                                        </div>
                                                        <div class="text-[11px] text-slate-700 font-medium" x-text="item.summary"></div>
                                                        <div class="flex flex-wrap items-center gap-x-2 text-[9px] text-slate-400 font-mono">
                                                            <span x-text="@js(__('copilot.widget.source_prefix')) + item.source"></span>
                                                            <span x-text="@js(__('copilot.chat.period_prefix')) + item.period"></span>
                                                        </div>
                                                        <div class="text-[10px] text-slate-500 italic" x-text="item.explainability"></div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Limitations list -->
                                    <template x-if="msg.financial_card.data?.limitations && msg.financial_card.data.limitations.length > 0">
                                        <div class="p-2.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] text-slate-600 space-y-1">
                                            <div class="text-[10px] font-bold text-slate-600 uppercase tracking-wider">{{ __('copilot.widget.data_limitations_title') }}</div>
                                            <ul class="list-disc list-inside space-y-0.5 text-[10px]">
                                                <template x-for="(lim, idx) in msg.financial_card.data.limitations" :key="idx">
                                                    <li x-text="lim"></li>
                                                </template>
                                            </ul>
                                        </div>
                                    </template>

                                    <!-- Disclaimer Box (Mandatory) -->
                                    <div class="p-2.5 bg-amber-50 border border-amber-200 rounded-lg text-[10px] text-amber-900 flex items-start gap-1.5"
                                         x-show="msg.financial_card.data?.disclaimer">
                                        <svg class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                        </svg>
                                        <span class="leading-relaxed" x-text="msg.financial_card.data?.disclaimer"></span>
                                    </div>
                                </div>
                            </template>

                            {{-- Financial Card: Action Recommendations (Kaching Action Center Phase 2) --}}
                            <template x-if="msg.financial_card && msg.financial_card.type === 'action_recommendations'">
                                <div class="mt-3 p-3.5 bg-blue-50/70 border border-blue-200 rounded-xl space-y-3 text-xs">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-1.5">
                                            <div class="font-bold text-blue-950 text-sm" x-text="msg.financial_card.title || @js(__('copilot.cards.action_recommendations'))"></div>
                                        </div>
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 border border-amber-300">
                                                Propose ≠ Execute
                                            </span>
                                            <span class="text-[10px] font-semibold bg-blue-200 text-blue-900 px-1.5 py-0.5 rounded"
                                                  x-text="(msg.financial_card.recommendations ? msg.financial_card.recommendations.length : 0) + ' ' + @js(__('copilot.chat.recommendations_unit'))"></span>
                                        </div>
                                    </div>

                                    <template x-if="msg.financial_card.recommendations && msg.financial_card.recommendations.length > 0">
                                        <div class="space-y-2.5">
                                            <template x-for="(rec, rIdx) in msg.financial_card.recommendations" :key="rIdx">
                                                <div class="bg-white p-3 rounded-lg border border-blue-100 shadow-sm space-y-2">
                                                    <!-- Header: Priority & Action Type -->
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded uppercase tracking-wider"
                                                              :class="rec.priority === 'HIGH' ? 'bg-rose-100 text-rose-800 border border-rose-200' : (rec.priority === 'MEDIUM' ? 'bg-amber-100 text-amber-800 border border-amber-200' : 'bg-slate-100 text-slate-700 border border-slate-200')"
                                                              x-text="rec.priority + ' PRIORITY'"></span>
                                                        <span class="text-[10px] font-mono text-slate-500 font-semibold" x-text="rec.action_type"></span>
                                                    </div>

                                                    <!-- What & Why -->
                                                    <div>
                                                        <div class="font-bold text-slate-900 text-xs" x-text="rec.what"></div>
                                                        <div class="text-slate-600 text-[11px] mt-1 leading-relaxed" x-text="rec.why"></div>
                                                    </div>

                                                    <!-- Evidence -->
                                                    <template x-if="rec.evidence && Object.keys(rec.evidence).length > 0">
                                                        <div class="bg-slate-50 p-2 rounded border border-slate-200 text-[10px] space-y-1">
                                                            <div class="font-semibold text-slate-700">{{ __('copilot.chat.verified_evidence') }}</div>
                                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-2 gap-y-0.5">
                                                                <template x-for="(val, key) in rec.evidence" :key="key">
                                                                    <div class="flex justify-between text-slate-600 border-b border-slate-100 py-0.5">
                                                                        <span class="capitalize" x-text="String(key).replace(/_/g, ' ') + ':'"></span>
                                                                        <span class="font-mono font-medium text-slate-800" x-text="typeof val === 'number' ? Number(val).toLocaleString('id-ID') : val"></span>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </div>
                                                    </template>

                                                    <!-- Expected Impact & Limitations -->
                                                    <div class="text-[10px] space-y-1">
                                                        <div class="text-emerald-700" x-show="rec.expected_impact">
                                                            <span class="font-semibold">{{ __('copilot.chat.expected_impact') }}</span>
                                                            <span x-text="rec.expected_impact"></span>
                                                        </div>
                                                        <div class="text-slate-500" x-show="rec.limitations">
                                                            <span class="font-semibold">{{ __('copilot.chat.limitations') }}</span>
                                                            <span x-text="rec.limitations"></span>
                                                        </div>
                                                    </div>

                                                    <!-- Footer: Source & Suggested Action CTA (Review / Simulate Only) -->
                                                    <div class="pt-2 border-t border-slate-100 flex items-center justify-between gap-2">
                                                        <span class="text-[9px] text-slate-400 font-mono" x-text="@js(__('copilot.widget.source_prefix')) + (rec.source || 'Engine')"></span>
                                                        <template x-if="rec.action_type === 'MENU_MARGIN_REVIEW'">
                                                            <button type="button"
                                                                    @click="toggleMenuMarginPanel(rec)"
                                                                    class="inline-flex items-center gap-1 px-2.5 py-1 text-[10px] font-bold rounded bg-blue-600 text-white hover:bg-blue-700 transition shadow-sm">
                                                                <span x-text="rec._showCandidates ? @js(__('copilot.chat.close_review')) : @js(__('copilot.chat.review_menu_candidates'))"></span>
                                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                                                </svg>
                                                            </button>
                                                        </template>
                                                    </div>

                                                    <!-- MENU_MARGIN_REVIEW: Interactive Candidate Review & Price Proposal Panel (Phase 3.2D) -->
                                                    <template x-if="rec.action_type === 'MENU_MARGIN_REVIEW' && rec._showCandidates">
                                                        <div class="mt-3 p-3 bg-slate-50 border border-blue-200 rounded-lg space-y-3">
                                                            <!-- Mandatory Semantic Header -->
                                                            <div class="space-y-1">
                                                                <div class="font-bold text-slate-800 text-xs flex items-center justify-between">
                                                                    <span>{{ __('copilot.widget.low_margin_review_title') }}</span>
                                                                    <span class="text-[9px] bg-blue-100 text-blue-800 px-2 py-0.5 rounded font-mono font-semibold">{{ __('copilot.chat.owner_review_badge') }}</span>
                                                                </div>
                                                                <div class="text-[10px] text-slate-500 italic leading-snug">
                                                                    {{ __('copilot.widget.low_margin_review_desc') }}
                                                                </div>
                                                            </div>

                                                            <!-- Candidates List -->
                                                            <template x-if="rec.candidates && rec.candidates.length > 0">
                                                                <div class="space-y-1.5">
                                                                    <div class="text-[10px] font-semibold text-slate-600">{{ __('copilot.widget.select_one_menu') }}</div>
                                                                    <div class="space-y-1 max-h-48 overflow-y-auto pr-1">
                                                                        <template x-for="(cand, cIdx) in rec.candidates" :key="cand.menu_id">
                                                                            <label class="flex items-center justify-between p-2 rounded border transition cursor-pointer text-[11px]"
                                                                                   :class="rec._selectedMenu && rec._selectedMenu.menu_id === cand.menu_id ? 'bg-blue-50 border-blue-400 font-medium' : 'bg-white border-slate-200 hover:border-blue-200'">
                                                                                <div class="flex items-center gap-2">
                                                                                    <input type="radio"
                                                                                           :name="'cand_' + rIdx"
                                                                                           :value="cand.menu_id"
                                                                                           :checked="rec._selectedMenu && rec._selectedMenu.menu_id === cand.menu_id"
                                                                                           @change="selectMenuCandidate(rec, cand)"
                                                                                           class="text-blue-600 focus:ring-blue-500">
                                                                                    <div>
                                                                                        <span class="text-slate-900" x-text="cand.name"></span>
                                                                                        <span class="text-[9px] text-slate-400 ml-1" x-show="cand.category" x-text="'(' + cand.category + ')'"></span>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="text-right">
                                                                                    <div class="text-slate-800 font-mono" x-text="formatRp(cand.current_price)"></div>
                                                                                    <div class="text-[9px] text-slate-500">
                                                                                        <span>{{ __('copilot.widget.cogs_price_label') }} </span><span class="font-mono" x-text="formatRp(cand.cost_price)"></span>
                                                                                        <span class="mx-1">•</span>
                                                                                        <span>{{ __('copilot.widget.margin_label') }} </span><span class="font-mono font-bold text-amber-600" x-text="cand.current_margin_pct + '%'"></span>
                                                                                    </div>
                                                                                </div>
                                                                            </label>
                                                                        </template>
                                                                    </div>
                                                                </div>
                                                            </template>

                                                            <!-- Empty State -->
                                                            <template x-if="!rec.candidates || rec.candidates.length === 0">
                                                                <div class="p-2.5 bg-amber-50 border border-amber-200 rounded text-amber-800 text-[11px]">
                                                                    {{ __('copilot.widget.no_candidates_eval') }}
                                                                </div>
                                                            </template>

                                                            <!-- Selected Menu & Price Selection Flow -->
                                                            <template x-if="rec._selectedMenu">
                                                                <div class="pt-2 border-t border-slate-200 space-y-2.5">
                                                                    <div class="bg-white p-2.5 rounded border border-slate-200 text-[11px] space-y-1">
                                                                        <div class="font-semibold text-slate-700 flex justify-between">
                                                                            <span>{{ __('copilot.widget.selected_menu_label') }}</span>
                                                                            <span class="font-bold text-slate-900" x-text="rec._selectedMenu.name"></span>
                                                                        </div>
                                                                        <div class="grid grid-cols-3 gap-2 pt-1 text-slate-600 border-t border-slate-100 text-[10px]">
                                                                            <div>
                                                                                <span class="text-slate-400">{{ __('copilot.widget.selling_price_label') }}</span>
                                                                                <div class="font-mono font-bold text-slate-800" x-text="formatRp(rec._selectedMenu.current_price)"></div>
                                                                            </div>
                                                                            <div>
                                                                                <span class="text-slate-400">{{ __('copilot.widget.cogs_price_label') }}</span>
                                                                                <div class="font-mono font-bold text-slate-800" x-text="formatRp(rec._selectedMenu.cost_price)"></div>
                                                                            </div>
                                                                            <div>
                                                                                <span class="text-slate-400">{{ __('copilot.widget.margin_label') }}</span>
                                                                                <div class="font-mono font-bold text-amber-600" x-text="rec._selectedMenu.current_margin_pct + '%'"></div>
                                                                            </div>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Owner Price Input -->
                                                                    <div class="space-y-1">
                                                                        <label class="block text-[11px] font-bold text-slate-800">
                                                                            {{ __('copilot.widget.owner_new_price_label') }}
                                                                        </label>
                                                                        <div class="text-[10px] text-slate-500">
                                                                            {{ __('copilot.widget.owner_new_price_sub') }}
                                                                        </div>
                                                                        <input type="number"
                                                                               x-model.number="rec._ownerNewPrice"
                                                                               @input="runMenuSimulation(rec)"
                                                                               min="1"
                                                                               step="100"
                                                                               placeholder="{{ __('copilot.widget.owner_new_price_placeholder') }}"
                                                                               class="w-full px-2.5 py-1.5 border border-slate-300 rounded text-xs focus:ring-1 focus:ring-blue-500 focus:border-blue-500 font-mono">
                                                                    </div>

                                                                    <!-- Simulation Output -->
                                                                    <template x-if="rec._simulation">
                                                                        <div class="p-2.5 bg-blue-50/90 border border-blue-200 rounded text-[11px] space-y-1.5">
                                                                            <div class="font-bold text-blue-950 flex justify-between items-center">
                                                                                <span>{{ __('copilot.widget.margin_impact_title') }}</span>
                                                                                <span class="text-[9px] font-mono font-normal text-blue-700">{{ __('copilot.chat.deterministic_badge') }}</span>
                                                                            </div>
                                                                            <div class="grid grid-cols-2 gap-2 text-[10px]">
                                                                                <div>
                                                                                    <span class="text-slate-500">{{ __('copilot.widget.diff_label') }}</span>
                                                                                    <div class="font-mono font-semibold"
                                                                                         :class="rec._simulation.price_delta >= 0 ? 'text-emerald-700' : 'text-rose-700'"
                                                                                         x-text="(rec._simulation.price_delta >= 0 ? '+' : '') + formatRp(rec._simulation.price_delta)"></div>
                                                                                </div>
                                                                                <div>
                                                                                    <span class="text-slate-500">{{ __('copilot.widget.change_label') }}</span>
                                                                                    <div class="font-mono font-semibold"
                                                                                         :class="rec._simulation.price_change_pct >= 0 ? 'text-emerald-700' : 'text-rose-700'"
                                                                                         x-text="(rec._simulation.price_change_pct >= 0 ? '+' : '') + rec._simulation.price_change_pct + '%'"></div>
                                                                                </div>
                                                                                <div>
                                                                                    <span class="text-slate-500">{{ __('copilot.widget.new_margin_label') }}</span>
                                                                                    <div class="font-mono font-bold text-blue-900" x-text="rec._simulation.resulting_margin_pct + '%'"></div>
                                                                                </div>
                                                                                <div>
                                                                                    <span class="text-slate-500">{{ __('copilot.widget.delta_label') }}</span>
                                                                                    <div class="font-mono font-semibold"
                                                                                         :class="rec._simulation.margin_delta_pp >= 0 ? 'text-emerald-700' : 'text-rose-700'"
                                                                                         x-text="(rec._simulation.margin_delta_pp >= 0 ? '+' : '') + rec._simulation.margin_delta_pp + ' pp'"></div>
                                                                                </div>
                                                                            </div>
                                                                            <div class="text-[9px] text-slate-500 italic pt-1 border-t border-blue-100">
                                                                                {{ __('copilot.chat.macro_sim_note') }}
                                                                            </div>
                                                                        </div>
                                                                    </template>

                                                                    <!-- Submit Proposal Action -->
                                                                    <template x-if="!rec._proposal">
                                                                        <button type="button"
                                                                                @click="submitMenuProposal(rec)"
                                                                                :disabled="!rec._ownerNewPrice || rec._ownerNewPrice <= 0 || rec._isProposing"
                                                                                class="w-full py-2 px-3 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 text-white font-bold text-xs rounded transition flex items-center justify-center gap-1.5 shadow-sm">
                                                                            <svg x-show="rec._isProposing" class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24">
                                                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                                                            </svg>
                                                                            <span x-text="rec._isProposing ? @js(__('copilot.widget.submitting')) : @js(__('copilot.widget.submit_proposal_btn'))"></span>
                                                                        </button>
                                                                    </template>

                                                                    <!-- Proposal Success State (PENDING) -->
                                                                    <template x-if="rec._proposal">
                                                                        <div class="p-3 bg-indigo-50/80 border border-indigo-200 rounded-lg space-y-1.5 text-xs">
                                                                            <div class="flex items-center justify-between font-bold text-indigo-950">
                                                                                <span>{{ __('copilot.widget.pending_owner_approval') }}</span>
                                                                                <span class="text-[10px] bg-indigo-200 text-indigo-900 px-1.5 py-0.5 rounded font-semibold"
                                                                                      x-text="rec._proposal.decision || 'PENDING'"></span>
                                                                            </div>
                                                                            <div class="text-slate-600 text-[11px] leading-relaxed">
                                                                                {{ __('copilot.chat.proposal_submitted_intro') }} <strong x-text="rec._selectedMenu.name"></strong> {{ __('copilot.chat.proposal_submitted_to') }} <strong class="font-mono" x-text="formatRp(rec._proposal.new_price || rec._ownerNewPrice)"></strong> {{ __('copilot.chat.proposal_submitted_outro') }} (ID: <span class="font-mono text-indigo-800" x-text="rec._proposal.proposal_id"></span>).
                                                                            </div>
                                                                            <div class="text-[10px] text-slate-500 bg-white p-2 rounded border border-indigo-100">
                                                                                <strong>{{ __('copilot.chat.execution_status') }}:</strong> {{ __('copilot.chat.execution_status_desc') }}
                                                                            </div>
                                                                        </div>
                                                                    </template>

                                                                    <!-- Error Display -->
                                                                    <template x-if="rec._error">
                                                                        <div class="p-2 bg-rose-50 border border-rose-200 rounded text-rose-800 text-[11px]" x-text="rec._error"></div>
                                                                    </template>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Proposal Action Card --}}
                            <template x-if="msg.proposal">
                                <div class="mt-3 p-3 bg-indigo-50/70 border border-indigo-200 rounded-xl space-y-1 text-xs">
                                    <div class="flex items-center justify-between font-bold text-indigo-950">
                                        <span>{{ __('copilot.widget.system_action_proposal') }}</span>
                                        <span class="text-[10px] bg-indigo-200 text-indigo-900 px-1.5 py-0.5 rounded font-semibold"
                                              x-text="msg.proposal.status || 'PENDING'"></span>
                                    </div>
                                    <div class="text-slate-700 font-medium text-[11px]" x-text="msg.proposal.action"></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        {{-- Loading --}}
        <div x-show="loading" class="flex items-center gap-2.5 px-2">
            <div class="bg-white border border-slate-200/80 rounded-2xl rounded-tl-sm p-4 shadow-sm max-w-[60%]">
                <div class="flex items-center gap-2.5 text-sm text-slate-500">
                    <div class="flex space-x-1">
                        <div class="w-2 h-2 bg-indigo-500 rounded-full animate-bounce"></div>
                        <div class="w-2 h-2 bg-indigo-500 rounded-full animate-bounce [animation-delay:0.15s]"></div>
                        <div class="w-2 h-2 bg-indigo-500 rounded-full animate-bounce [animation-delay:0.3s]"></div>
                    </div>
                    <span>{{ __('copilot.widget.analyzing') }}</span>
                </div>
            </div>
        </div>

        {{-- Error --}}
        <div x-show="error" class="px-2">
            <div class="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700 flex items-start gap-2 max-w-[70%]">
                <svg class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <div>
                    <span class="font-bold">{{ __('copilot.chat.failed_prefix') }}:</span>
                    <span x-text="error"></span>
                </div>
            </div>
        </div>
    </div>

    {{-- COMPOSER --}}
    <div class="flex-shrink-0 pt-4 border-t border-slate-200/80">
        <form @submit.prevent="sendMessage()" class="flex items-end gap-3">
            <div class="relative flex-1">
                <textarea x-ref="composerInput"
                    x-model="input"
                    :disabled="loading"
                    @keydown="if ($event.key === 'Enter' && !$event.shiftKey && !$event.isComposing) { $event.preventDefault(); sendMessage(); }"
                    @input="autoResize($event)"
                    @paste="handlePaste($event)"
                    rows="1"
                    placeholder="{{ __('copilot.widget.input_placeholder') }}"
                    class="w-full text-sm bg-white border border-slate-300 rounded-xl px-4 py-3 text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 disabled:opacity-50 transition-all resize-none overflow-y-auto"
                    style="max-height: 120px;"></textarea>
            </div>
            <button type="submit"
                @click.prevent="sendMessage()"
                :disabled="loading || !(input && input.trim())"
                title="{{ __('copilot.widget.send_label') }}"
                aria-label="{{ __('copilot.widget.send_label') }}"
                class="p-3 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white rounded-xl shadow-sm transition-colors disabled:bg-slate-200 disabled:text-slate-400 disabled:shadow-none disabled:cursor-not-allowed shrink-0 mb-0.5 cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"></path>
                </svg>
            </button>
        </form>
        <div class="mt-1.5 flex items-center justify-between text-[10px] text-slate-400 px-1">
            <span><kbd class="px-1 py-0.5 bg-slate-100 border border-slate-300 rounded text-[9px] font-mono">Enter</kbd> {{ __('copilot.chat.kbd_send') }} &bull; <kbd class="px-1 py-0.5 bg-slate-100 border border-slate-300 rounded text-[9px] font-mono">Shift+Enter</kbd> {{ __('copilot.chat.kbd_newline') }}</span>
            <span>Kaching Decision Support System</span>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
function copilotChatPage() {
    return {
        loading: false,
        input: '',
        error: null,
        messages: [],
        history: [],

        renderMarkdown(text) {
            if (window.renderCopilotMarkdown) {
                return window.renderCopilotMarkdown(text);
            }
            return (text && String(text).trim())
                ? String(text)
                : '<p class="text-slate-500 italic text-xs">' + @json(__('copilot.widget.no_content')) + '</p>';
        },

        renderCopilotMarkdown(text) {
            return this.renderMarkdown(text);
        },

        initPage() {
            this.$nextTick(() => {
                if (this.$refs.composerInput) {
                    this.$refs.composerInput.focus();
                }
            });
        },

        resetChat() {
            this.messages = [];
            this.history = [];
            this.error = null;
            this.input = '';
            this.$nextTick(() => {
                if (this.$refs.composerInput) {
                    this.$refs.composerInput.focus();
                }
            });
        },

        scrollToBottom() {
            const box = this.$refs.chatContainer;
            if (box) {
                this.$nextTick(() => {
                    box.scrollTop = box.scrollHeight;
                });
            }
        },

        autoResize(event) {
            const el = event.target;
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 120) + 'px';
        },

        handlePaste(event) {
            const clipboardData = event.clipboardData || window.clipboardData;
            if (!clipboardData) return;
            const pastedText = clipboardData.getData('text/plain');
            if (!pastedText) return;

            // Strip leading checkbox artifacts copied from rendered task lists (e.g. "[ ] ", "[x] ", "[X] ")
            // only at the start of the pasted text, preserving intentional brackets inside text.
            if (/^\s*\[[\sxX]?\]\s+/.test(pastedText)) {
                event.preventDefault();
                const cleanedText = pastedText.replace(/^\s*\[[\sxX]?\]\s+/, '');
                const target = event.target;
                const start = target.selectionStart || 0;
                const end = target.selectionEnd || 0;
                const currentVal = target.value || '';
                const newVal = currentVal.substring(0, start) + cleanedText + currentVal.substring(end);
                target.value = newVal;
                this.input = newVal;
                this.$nextTick(() => {
                    target.selectionStart = target.selectionEnd = start + cleanedText.length;
                    this.autoResize({ target });
                });
            }
        },

        sendQuickPrompt(text) {
            this.input = text;
            this.sendMessage();
        },

        async sendMessage() {
            const text = (this.input || '').trim();
            if (!text || this.loading) return;

            this.error = null;
            this.messages.push({
                role: 'user',
                content: text,
                financial_card: null,
                proposal: null
            });
            this.input = '';
            this.loading = true;

            // Reset textarea height
            if (this.$refs.composerInput) {
                this.$refs.composerInput.style.height = 'auto';
            }

            this.scrollToBottom();

            // Helper sanitasi teks untuk mencegah kebocoran raw translation key
            const sanitizeText = (str, fallback) => {
                if (!str || typeof str !== 'string') return fallback;
                const trimmed = str.trim();
                if (/^copilot\.[a-z0-9_.-]+$/i.test(trimmed)) {
                    return fallback;
                }
                return trimmed;
            };

            const defaultFallbackText = sanitizeText(@js(__('copilot.widget.default_analysis_reply')), 'Mohon maaf, pesan belum dapat dianalisis.');
            const defaultFailedText = sanitizeText(@js(__('copilot.widget.process_failed')), 'Terjadi kesalahan saat memproses permintaan.');

            try {
                const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                const csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

                const response = await fetch("{{ route('copilot.chat') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf
                    },
                    body: JSON.stringify({
                        message: text,
                        history: this.history
                    })
                });

                if (response.status === 401 || response.status === 419) {
                    window.location.reload();
                    return;
                }

                if (response.status === 429) {
                    throw new Error(sanitizeText(@js(__('copilot.chat.too_many_requests')), 'Terlalu banyak permintaan. Mohon tunggu sejenak.'));
                }

                if (!response.ok) {
                    let errJson = {};
                    try {
                        errJson = await response.json();
                    } catch (e) {
                        // Respon non-JSON (misal 502/504 Bad Gateway HTML)
                    }
                    const rawErrMsg = errJson.message || errJson.reply || (@js(__('copilot.widget.request_failed_status')) + response.status + ')');
                    throw new Error(sanitizeText(rawErrMsg, defaultFailedText));
                }

                let data;
                try {
                    data = await response.json();
                } catch (e) {
                    throw new Error(defaultFailedText);
                }

                // Deteksi apakah respons adalah Fallback Percakapan
                // Mendukung Controller baru (status: success, is_fallback: true ATAU status: error dengan is_fallback/code)
                // DAN Controller lama (status: error dengan HTTP 200 dan reply teks valid non-fatal)
                const fatalCodes = ['UNAUTHENTICATED', 'UNAUTHORIZED_OUTLET', 'UNAUTHORIZED_SUPPLIER', 'CONFIG_MISSING', 'VALIDATION_ERROR'];
                const responseCode = data.code || (data.data && data.data.code);

                const isConversationalFallback = Boolean(
                    data.is_fallback ||
                    (data.data && data.data.is_fallback) ||
                    ['CLARIFICATION_NEEDED', 'AI_PROVIDER_UNAVAILABLE', 'RESTOCK_CONTEXT_MISSING', 'NO_TENANT_SUPPLIERS', 'SUPPLIER_SPECIFICATION_NEEDED'].includes(responseCode) ||
                    (data.status === 'error' && (data.reply || (data.data && data.data.reply)) && !fatalCodes.includes(responseCode))
                );

                if (data.status === 'error' && !isConversationalFallback) {
                    const rawError = data.message || data.reply || (data.data && data.data.reply) || defaultFailedText;
                    throw new Error(sanitizeText(rawError, defaultFailedText));
                }

                const rawReply = data.reply || (data.data && data.data.reply);
                const reply = sanitizeText(rawReply, defaultFallbackText);
                const financialCard = isConversationalFallback ? null : (data.financial_card || (data.data && data.data.financial_card) || null);
                const proposal = isConversationalFallback ? null : (data.proposal || (data.data && data.data.proposal) || null);

                this.messages.push({
                    role: 'assistant',
                    content: reply,
                    financial_card: financialCard,
                    proposal: proposal,
                    is_fallback: isConversationalFallback
                });

                this.history.push({ role: 'user', content: text });
                this.history.push({ role: 'assistant', content: reply });
                if (this.history.length > 8) {
                    this.history = this.history.slice(-8);
                }
            } catch (err) {
                this.error = err.message || sanitizeText(@js(__('copilot.widget.contact_error')), 'Tidak dapat terhubung ke server.');
            } finally {
                this.loading = false;
                this.scrollToBottom();
            }
        },

        formatRp(val) {
            const num = Number(val || 0);
            return 'Rp ' + num.toLocaleString('id-ID');
        },

        async toggleMenuMarginPanel(rec) {
            rec._showCandidates = !rec._showCandidates;
            if (rec._showCandidates && (!rec.candidates || rec.candidates.length === 0)) {
                try {
                    const res = await fetch("{{ route('copilot.menu-margin.candidates') }}", {
                        headers: { 'Accept': 'application/json' }
                    });
                    if (res.ok) {
                        const json = await res.json();
                        rec.candidates = json.candidates || [];
                    }
                } catch (e) {
                    console.error('Gagal mengambil kandidat menu', e);
                }
            }
        },

        selectMenuCandidate(rec, cand) {
            rec._selectedMenu = cand;
            rec._ownerNewPrice = '';
            rec._simulation = null;
            rec._proposal = null;
            rec._error = null;
        },

        runMenuSimulation(rec) {
            if (!rec._selectedMenu || !rec._ownerNewPrice || rec._ownerNewPrice <= 0) {
                rec._simulation = null;
                return;
            }

            const currentPrice = Number(rec._selectedMenu.current_price);
            const costPrice = Number(rec._selectedMenu.cost_price);
            const newPrice = Number(rec._ownerNewPrice);

            const delta = Math.round((newPrice - currentPrice) * 100) / 100;
            const changePct = currentPrice > 0 ? Math.round((delta / currentPrice) * 10000) / 100 : 0;
            const resultingMarginPct = newPrice > 0 ? Math.round(((newPrice - costPrice) / newPrice) * 10000) / 100 : 0;
            const currentMarginPct = Number(rec._selectedMenu.current_margin_pct);
            const marginDeltaPp = Math.round((resultingMarginPct - currentMarginPct) * 100) / 100;

            rec._simulation = {
                price_delta: delta,
                price_change_pct: changePct,
                resulting_margin_pct: resultingMarginPct,
                margin_delta_pp: marginDeltaPp
            };
        },

        async submitMenuProposal(rec) {
            if (!rec._selectedMenu || !rec._ownerNewPrice || rec._ownerNewPrice <= 0 || rec._isProposing) {
                return;
            }

            rec._isProposing = true;
            rec._error = null;

            try {
                const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                const csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

                const res = await fetch("{{ route('copilot.menu-margin.propose') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf
                    },
                    body: JSON.stringify({
                        menu_id: rec._selectedMenu.menu_id,
                        new_price: rec._ownerNewPrice,
                        reason: @js(__('copilot.widget.proposal_reason_default'))
                    })
                });

                const data = await res.json();
                if (!res.ok || data.status !== 'SUCCESS') {
                    throw new Error(data.message || @js(__('copilot.widget.proposal_submit_failed')));
                }

                rec._proposal = data;
            } catch (err) {
                rec._error = err.message || @js(__('copilot.widget.proposal_submit_failed'));
            } finally {
                rec._isProposing = false;
                this.scrollToBottom();
            }
        }
    };
}
</script>
@endpush
