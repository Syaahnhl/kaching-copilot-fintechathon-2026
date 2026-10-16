@unless (View::hasSection('hide_copilot_widget'))
<!-- Kaching Financial Copilot Web Widget -->
<div x-data="kachiCopilotWidget()" 
     x-init="initWidget()" 
     class="relative z-50 font-sans"
     @keydown.escape.window="isOpen ? closeWidget() : null">

    <!-- Floating Launcher Button (Circular FAB) -->
    <div x-show="!isOpen"
         x-cloak
         :class="{ 'hidden pointer-events-none opacity-0 invisible': isOpen }"
         class="fixed bottom-5 right-5 sm:bottom-6 sm:right-6 z-50 pointer-events-auto transition-opacity duration-150">
        <button type="button"
                @click="openWidget()"
                aria-label="{{ __('copilot.widget.launcher_label') }}"
                title="{{ __('copilot.widget.launcher_label') }}"
                class="relative flex items-center justify-center w-14 h-14 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-150 focus:outline-none focus:ring-4 focus:ring-indigo-300 touch-manipulation cursor-pointer">
            
            <!-- Online status indicator dot -->
            <span class="absolute top-0.5 right-0.5 flex h-3 w-3">
                <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500 border-2 border-white"></span>
            </span>

            <!-- Icon: Chat -->
            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
            </svg>
        </button>
    </div>

    <!-- Mobile Backdrop Overlay -->
    <div x-show="isOpen && isMobile"
         x-cloak
         x-transition:enter="transition-opacity ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="closeWidget()"
         class="fixed inset-0 bg-slate-900/60 z-[55] lg:hidden"
         style="display: none;"></div>

    <!-- Chat Slide-out Panel / Modal -->
    <div x-show="isOpen"
         x-cloak
         x-ref="copilotPanel"
         x-transition:enter="transition ease-out duration-250 transform"
         x-transition:enter-start="opacity-0 translate-y-4 lg:translate-y-0 lg:scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 lg:scale-100"
         x-transition:leave="transition ease-in duration-200 transform"
         x-transition:leave-start="opacity-100 translate-y-0 lg:scale-100"
         x-transition:leave-end="opacity-0 translate-y-4 lg:translate-y-0 lg:scale-95"
         class="fixed inset-x-0 top-0 bottom-0 lg:inset-x-auto lg:top-auto lg:right-6 lg:bottom-24 lg:w-[420px] lg:h-[620px] lg:max-h-[620px] bg-white lg:rounded-2xl shadow-2xl border-0 lg:border lg:border-slate-200/90 flex flex-col overflow-hidden z-[60]"
         :style="mobileViewportStyle"
         style="display: none;">

        <!-- Header -->
        <div class="px-4 py-2.5 bg-slate-900 text-white flex items-center justify-between shrink-0">
            <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-lg bg-indigo-500/20 flex items-center justify-center text-indigo-300">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                </div>
                <h3 class="text-sm font-semibold tracking-tight">Kachi Copilot</h3>
            </div>

            <div class="flex items-center gap-0.5">
                <!-- Reset Conversation -->
                <button type="button" 
                        @click="resetChat()"
                        title="{{ __('copilot.widget.reset_title') }}"
                        aria-label="{{ __('copilot.widget.reset_title') }}"
                        class="p-2 text-slate-400 hover:text-white rounded-lg hover:bg-white/10 transition-colors touch-manipulation">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                </button>

                <!-- Close Panel -->
                <button type="button" 
                        @click="closeWidget()"
                        title="{{ __('copilot.widget.close_title') }}"
                        aria-label="{{ __('copilot.widget.close_title') }}"
                        class="p-2 text-slate-300 hover:text-white rounded-lg hover:bg-white/10 active:bg-white/20 transition-colors touch-manipulation cursor-pointer">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Messages Box -->
        <div x-ref="messageBox" 
             class="flex-1 px-4 py-3 overflow-y-auto space-y-4 bg-slate-50/50">

            <template x-for="(msg, index) in messages" :key="index">
                <div>
                    <!-- User Message Bubble -->
                    <template x-if="msg.role === 'user'">
                        <div class="flex justify-end">
                            <div class="max-w-[80%] bg-indigo-600 text-white rounded-2xl rounded-tr-sm px-3.5 py-2.5 text-xs sm:text-sm shadow-xs leading-relaxed whitespace-pre-line"
                                 x-text="msg.content"></div>
                        </div>
                    </template>

                    <!-- Assistant Message Bubble -->
                    <template x-if="msg.role === 'assistant'">
                        <div class="flex flex-col items-start space-y-2 max-w-[90%] min-w-0">
                            <div class="w-full min-w-0 bg-white border border-slate-200/80 text-slate-800 rounded-2xl rounded-tl-sm p-3.5 text-xs sm:text-sm shadow-xs leading-relaxed overflow-hidden">
                                <div class="copilot-markdown text-slate-700 leading-relaxed text-xs sm:text-sm select-text" x-html="renderCopilotMarkdown(msg.content)"></div>

                                <!-- Financial Card: Menu Intelligence -->
                                <template x-if="msg.financial_card && msg.financial_card.type === 'menu_intelligence'">
                                    <div class="mt-3 p-3 bg-slate-50 border border-indigo-100 rounded-xl space-y-2 text-xs">
                                        <div class="flex items-center justify-between pb-1.5 border-b border-indigo-100/70">
                                            <div class="font-bold text-indigo-900" x-text="msg.financial_card.title"></div>
                                            <span class="text-[10px] font-semibold bg-indigo-100 text-indigo-800 px-1.5 py-0.5 rounded"
                                                  x-text="msg.financial_card.data?.period?.label || '30 Hari'"></span>
                                        </div>

                                        <!-- Quadrant Summary Grid -->
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

                                        <!-- Top Stars List -->
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

                                <!-- Financial Card: Warning -->
                                <template x-if="msg.financial_card && msg.financial_card.type === 'warning'">
                                    <div class="mt-3 p-3 bg-amber-50/70 border border-amber-200 rounded-xl space-y-1.5 text-xs">
                                        <div class="flex items-center justify-between font-bold text-amber-900">
                                            <span x-text="msg.financial_card.title"></span>
                                            <span class="text-[10px] bg-amber-200 text-amber-900 px-1.5 py-0.2 rounded font-semibold uppercase">{{ __('copilot.widget.warning_label') }}</span>
                                        </div>
                                        <template x-if="msg.financial_card.data?.warnings">
                                            <div class="space-y-1 pt-1">
                                                <template x-for="(w, idx) in msg.financial_card.data.warnings" :key="idx">
                                                    <div class="p-1.5 bg-white border border-amber-200 rounded text-[11px] text-slate-700" x-text="w.message || w.title"></div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <!-- Financial Card: Scenario Simulation -->
                                <template x-if="msg.financial_card && msg.financial_card.type === 'scenario'">
                                    <div class="mt-3 p-3 bg-sky-50/70 border border-sky-200 rounded-xl space-y-1.5 text-xs">
                                        <div class="font-bold text-sky-900" x-text="msg.financial_card.title"></div>
                                        <div class="grid grid-cols-2 gap-1.5 pt-1 text-[11px]">
                                            <div class="bg-white p-2 rounded border border-sky-100">
                                                <div class="text-slate-500">{{ __('copilot.widget.est_revenue') }}</div>
                                                <div class="font-bold text-slate-800 mt-0.5" x-text="'Rp ' + Number(msg.financial_card.data?.result?.revenue ?? msg.financial_card.data?.baseline?.revenue ?? msg.financial_card.data?.scenario_revenue ?? 0).toLocaleString('id-ID')"></div>
                                            </div>
                                            <div class="bg-white p-2 rounded border border-sky-100">
                                                <div class="text-slate-500">{{ __('copilot.widget.est_net_profit') }}</div>
                                                <div class="font-bold mt-0.5"
                                                     :class="(msg.financial_card.data?.delta?.net_profit ?? 0) >= 0 ? 'text-emerald-700' : 'text-rose-700'"
                                                     x-text="'Rp ' + Number(msg.financial_card.data?.result?.net_profit ?? msg.financial_card.data?.baseline?.net_profit ?? msg.financial_card.data?.scenario_net_profit ?? 0).toLocaleString('id-ID')"></div>
                                            </div>
                                        </div>
                                        <template x-if="msg.financial_card.data?.delta">
                                            <div class="text-[10px] pt-1 flex items-center justify-between text-slate-600 bg-white/75 p-1.5 rounded border border-sky-100">
                                                <span>{{ __('copilot.widget.profit_diff') }}</span>
                                                <span class="font-bold" :class="(msg.financial_card.data.delta.net_profit || 0) >= 0 ? 'text-emerald-700' : 'text-rose-700'"
                                                      x-text="((msg.financial_card.data.delta.net_profit || 0) >= 0 ? '+' : '') + 'Rp ' + Number(msg.financial_card.data.delta.net_profit || 0).toLocaleString('id-ID') + ' (' + (msg.financial_card.data.delta.net_profit_percent || 0).toFixed(2) + '%)'"></span>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <!-- Financial Card: Cash Flow Forecast -->
                                <template x-if="msg.financial_card && msg.financial_card.type === 'forecast'">
                                    <div class="mt-3 p-3 bg-emerald-50/70 border border-emerald-200 rounded-xl space-y-1.5 text-xs">
                                        <div class="font-bold text-emerald-900" x-text="msg.financial_card.title"></div>
                                        <div class="bg-white p-2 rounded border border-emerald-100 text-[11px]">
                                            <div class="text-slate-500">Proyeksi Arus Kas Bersih (7 Hari)</div>
                                            <div class="font-extrabold text-emerald-800 text-sm mt-0.5"
                                                 x-text="'Rp ' + Number(msg.financial_card.data?.projected_net_cash_flow ?? msg.financial_card.data?.projected_net_operating_cash_flow ?? msg.financial_card.data?.projected_net_operating_cash ?? 0).toLocaleString('id-ID')"></div>
                                        </div>
                                    </div>
                                </template>

                                <!-- Financial Card: Summary -->
                                <template x-if="msg.financial_card && msg.financial_card.type === 'summary'">
                                    <div class="mt-3 p-3 bg-blue-50/70 border border-blue-200 rounded-xl space-y-1 text-xs">
                                        <div class="font-bold text-blue-900" x-text="msg.financial_card.title"></div>
                                        <div class="bg-white p-2 rounded border border-blue-100 text-[11px]">
                                            <div class="text-slate-500">Total Penjualan</div>
                                            <div class="font-extrabold text-blue-900 text-sm mt-0.5" x-text="msg.financial_card.data?.formatted_sales || 'Rp 0'"></div>
                                            <div class="text-[10px] text-slate-400 mt-0.5" x-text="msg.financial_card.data?.date || ''"></div>
                                        </div>
                                    </div>
                                </template>

                                <!-- Financial Card: Radar -->
                                <template x-if="msg.financial_card && msg.financial_card.type === 'radar'">
                                    <div class="mt-3 p-3 bg-purple-50/70 border border-purple-200 rounded-xl space-y-1.5 text-xs">
                                        <div class="flex items-center justify-between font-bold text-purple-900">
                                            <span x-text="msg.financial_card.title"></span>
                                            <span class="text-[10px] bg-purple-200 text-purple-900 px-1.5 py-0.5 rounded font-semibold"
                                                  x-text="(msg.financial_card.data?.signals_count || 0) + ' Sinyal'"></span>
                                        </div>
                                        <template x-if="msg.financial_card.data?.signals">
                                            <div class="space-y-1 pt-1">
                                                <template x-for="(s, si) in msg.financial_card.data.signals.slice(0, 3)" :key="si">
                                                    <div class="p-1.5 bg-white border border-purple-100 rounded text-[11px] text-slate-700">
                                                        <div class="font-semibold text-slate-800" x-text="s.headline"></div>
                                                        <div class="text-[10px] text-slate-500 line-clamp-2" x-text="s.summary"></div>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <!-- Financial Card: Financing Readiness & Evidence -->
                                <template x-if="msg.financial_card && msg.financial_card.type === 'financing_readiness'">
                                    <div class="mt-3 p-3 bg-purple-50/70 border border-purple-200 rounded-xl space-y-2 text-xs">
                                        <div class="flex items-center justify-between gap-1.5">
                                            <div class="font-bold text-purple-950 text-[11px]" x-text="msg.financial_card.title"></div>
                                            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full"
                                                  :class="msg.financial_card.severity === 'success' ? 'bg-emerald-100 text-emerald-800' : (msg.financial_card.severity === 'watch' ? 'bg-purple-100 text-purple-800' : 'bg-amber-100 text-amber-800')"
                                                  x-text="msg.financial_card.data?.band || ''"></span>
                                        </div>

                                        <div class="bg-white p-2 rounded-lg border border-purple-100 flex items-center justify-between">
                                            <div>
                                                <div class="text-slate-500 text-[9px] font-semibold">{{ __('copilot.widget.readiness_score') }}</div>
                                                <div class="text-lg font-black text-purple-950" x-text="(msg.financial_card.data?.score || 0) + '/100'"></div>
                                            </div>
                                            <div class="text-right text-[9px] text-slate-500" x-show="msg.financial_card.data?.evaluation_period?.start_date">
                                                <div>{{ __('copilot.widget.period_label') }}</div>
                                                <div class="font-mono text-slate-700" x-text="(msg.financial_card.data?.evaluation_period?.start_date || '') + ' - ' + (msg.financial_card.data?.evaluation_period?.end_date || '')"></div>
                                            </div>
                                        </div>

                                        <!-- 5 Pilar Mini Grid -->
                                        <template x-if="msg.financial_card.data?.pillars">
                                            <div class="space-y-1">
                                                <div class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">{{ __('copilot.widget.five_pillars_title') }}</div>
                                                <div class="grid grid-cols-1 gap-1">
                                                    <template x-for="(pillar, pKey) in msg.financial_card.data.pillars" :key="pKey">
                                                        <div class="bg-white/90 p-1.5 rounded border border-purple-100/70 flex items-center justify-between text-[10px]">
                                                            <span class="font-medium text-slate-700 capitalize" x-text="pKey.replace('_', ' ')"></span>
                                                            <div class="flex items-center gap-1">
                                                                <span class="text-slate-400 font-mono text-[9px]" x-text="'(' + Math.round(pillar.weight * 100) + '%)'"></span>
                                                                <span class="font-bold text-slate-900" x-text="pillar.score + '/100'"></span>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>

                                        <!-- Evidence Summary Checklist -->
                                        <template x-if="msg.financial_card.data?.evidence_summary && msg.financial_card.data.evidence_summary.length > 0">
                                            <div class="space-y-1 pt-0.5">
                                                <div class="flex items-center justify-between">
                                                    <span class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">{{ __('copilot.widget.financial_evidence_title') }}</span>
                                                    <span class="text-[8px] text-slate-400">{{ __('copilot.widget.pos_database_badge') }}</span>
                                                </div>
                                                <div class="space-y-1 max-h-48 overflow-y-auto pr-0.5">
                                                    <template x-for="item in msg.financial_card.data.evidence_summary" :key="item.key">
                                                        <div class="bg-white p-2 rounded border border-slate-200 text-[10px] space-y-0.5">
                                                            <div class="flex items-center justify-between gap-1">
                                                                <span class="font-bold text-slate-800" x-text="item.label"></span>
                                                                <span class="text-[8px] font-bold px-1.5 py-0.2 rounded"
                                                                      :class="item.status === 'AVAILABLE' ? 'bg-emerald-100 text-emerald-800' : (item.status === 'PARTIAL' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600')"
                                                                      x-text="item.status"></span>
                                                            </div>
                                                            <div class="text-[10px] text-slate-700 font-medium" x-text="item.summary"></div>
                                                            <div class="text-[8px] text-slate-400 font-mono" x-text="@js(__('copilot.widget.source_prefix')) + item.source"></div>
                                                            <div class="text-[9px] text-slate-500 italic" x-text="item.explainability"></div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>

                                        <!-- Limitations list -->
                                        <template x-if="msg.financial_card.data?.limitations && msg.financial_card.data.limitations.length > 0">
                                            <div class="p-2 bg-slate-50 border border-slate-200 rounded text-[9px] text-slate-600 space-y-0.5">
                                                <div class="font-bold text-slate-600 uppercase tracking-wider text-[8px]">{{ __('copilot.widget.data_limitations_title') }}</div>
                                                <ul class="list-disc list-inside space-y-0.5">
                                                    <template x-for="(lim, idx) in msg.financial_card.data.limitations" :key="idx">
                                                        <li x-text="lim"></li>
                                                    </template>
                                                </ul>
                                            </div>
                                        </template>

                                        <!-- Disclaimer Box (Mandatory) -->
                                        <div class="p-2 bg-amber-50 border border-amber-200 rounded text-[9px] text-amber-900 flex items-start gap-1.5"
                                             x-show="msg.financial_card.data?.disclaimer">
                                            <svg class="w-3.5 h-3.5 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                            </svg>
                                            <span class="leading-relaxed" x-text="msg.financial_card.data?.disclaimer"></span>
                                        </div>
                                    </div>
                                </template>

                                <!-- Financial Card: Action Recommendations (Action Center Phase 2) -->
                                <template x-if="msg.financial_card && msg.financial_card.type === 'action_recommendations'">
                                    <div class="mt-2.5 p-3 bg-blue-50/80 border border-blue-200 rounded-xl space-y-2.5 text-xs">
                                        <div class="flex items-center justify-between gap-1.5">
                                            <div class="font-bold text-blue-950 text-xs" x-text="msg.financial_card.title || 'Rekomendasi Tindakan'"></div>
                                            <div class="flex items-center gap-1">
                                                <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-300">
                                                    Propose ≠ Execute
                                                </span>
                                                <span class="text-[9px] font-semibold bg-blue-200 text-blue-900 px-1 py-0.5 rounded"
                                                      x-text="(msg.financial_card.recommendations ? msg.financial_card.recommendations.length : 0)"></span>
                                            </div>
                                        </div>

                                        <template x-if="msg.financial_card.recommendations && msg.financial_card.recommendations.length > 0">
                                            <div class="space-y-2">
                                                <template x-for="(rec, rIdx) in msg.financial_card.recommendations" :key="rIdx">
                                                    <div class="bg-white p-2.5 rounded-lg border border-blue-100 shadow-xs space-y-1.5">
                                                        <div class="flex items-center justify-between gap-1">
                                                            <span class="text-[9px] font-bold px-1.5 py-0.2 rounded uppercase"
                                                                  :class="rec.priority === 'HIGH' ? 'bg-rose-100 text-rose-800' : (rec.priority === 'MEDIUM' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700')"
                                                                  x-text="rec.priority"></span>
                                                            <span class="text-[9px] font-mono text-slate-400 font-semibold" x-text="rec.action_type"></span>
                                                        </div>

                                                        <div>
                                                            <div class="font-bold text-slate-800 text-[11px]" x-text="rec.what"></div>
                                                            <div class="text-slate-600 text-[10px] mt-0.5 leading-snug" x-text="rec.why"></div>
                                                        </div>

                                                        <template x-if="rec.evidence && Object.keys(rec.evidence).length > 0">
                                                            <div class="bg-slate-50 p-1.5 rounded border border-slate-200 text-[9px] space-y-0.5">
                                                                <div class="font-semibold text-slate-600 text-[8px] uppercase">Bukti Terverifikasi:</div>
                                                                <template x-for="(val, key) in rec.evidence" :key="key">
                                                                    <div class="flex justify-between text-slate-600">
                                                                        <span class="capitalize" x-text="String(key).replace(/_/g, ' ') + ':'"></span>
                                                                        <span class="font-mono font-medium text-slate-800" x-text="typeof val === 'number' ? Number(val).toLocaleString('id-ID') : val"></span>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </template>

                                                        <div class="text-[9px] space-y-0.5">
                                                            <div class="text-emerald-700" x-show="rec.expected_impact">
                                                                <span class="font-semibold">Dampak:</span> <span x-text="rec.expected_impact"></span>
                                                            </div>
                                                            <div class="text-slate-500" x-show="rec.limitations">
                                                                <span class="font-semibold">Batasan:</span> <span x-text="rec.limitations"></span>
                                                            </div>
                                                        </div>

                                                        <div class="pt-1.5 border-t border-slate-100 flex items-center justify-between gap-1">
                                                            <span class="text-[8px] text-slate-400 font-mono" x-text="'Sumber: ' + (rec.source || 'Engine')"></span>
                                                            <template x-if="rec.action_type === 'MENU_MARGIN_REVIEW'">
                                                                <button type="button"
                                                                        @click="toggleMenuMarginPanel(rec)"
                                                                        class="inline-flex items-center gap-1 px-2 py-0.5 text-[9px] font-bold rounded bg-blue-600 text-white hover:bg-blue-700 transition shadow-xs">
                                                                    <span x-text="rec._showCandidates ? 'Tutup' : 'Tinjau Menu'"></span>
                                                                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                                                    </svg>
                                                                </button>
                                                            </template>
                                                            <template x-if="rec.action_type !== 'MENU_MARGIN_REVIEW' && rec.proposal_capable === false">
                                                                <a href="{{ route('copilot.intelligence.risk-radar') }}"
                                                                   class="inline-flex items-center gap-1 px-2 py-0.5 text-[9px] font-bold rounded bg-slate-900 text-white hover:bg-slate-800 transition">
                                                                    <span>Review / Simulate</span>
                                                                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                                                    </svg>
                                                                </a>
                                                            </template>
                                                        </div>

                                                        <!-- MENU_MARGIN_REVIEW: Interactive Candidate Review & Price Proposal Panel (Phase 3.2D) -->
                                                        <template x-if="rec.action_type === 'MENU_MARGIN_REVIEW' && rec._showCandidates">
                                                            <div class="mt-2 p-2 bg-slate-50 border border-blue-200 rounded-lg space-y-2 text-[10px]">
                                                                <!-- Mandatory Semantic Header -->
                                                                <div class="space-y-0.5">
                                                                    <div class="font-bold text-slate-800 text-[11px] flex items-center justify-between">
                                                                        <span>{{ __('copilot.widget.low_margin_review_title') }}</span>
                                                                        <span class="text-[8px] bg-blue-100 text-blue-800 px-1.5 py-0.2 rounded font-mono font-semibold">{{ __('copilot.widget.review_badge') }}</span>
                                                                    </div>
                                                                    <div class="text-[9px] text-slate-500 italic leading-snug">
                                                                        {{ __('copilot.widget.low_margin_review_desc') }}
                                                                    </div>
                                                                </div>

                                                                <!-- Candidates List -->
                                                                <template x-if="rec.candidates && rec.candidates.length > 0">
                                                                    <div class="space-y-1">
                                                                        <div class="text-[9px] font-semibold text-slate-600">{{ __('copilot.widget.select_one_menu') }}</div>
                                                                        <div class="space-y-1 max-h-36 overflow-y-auto pr-1">
                                                                            <template x-for="(cand, cIdx) in rec.candidates" :key="cand.menu_id">
                                                                                <label class="flex items-center justify-between p-1.5 rounded border transition cursor-pointer text-[10px]"
                                                                                       :class="rec._selectedMenu && rec._selectedMenu.menu_id === cand.menu_id ? 'bg-blue-50 border-blue-400 font-medium' : 'bg-white border-slate-200 hover:border-blue-200'">
                                                                                    <div class="flex items-center gap-1.5">
                                                                                        <input type="radio"
                                                                                               :name="'w_cand_' + rIdx"
                                                                                               :value="cand.menu_id"
                                                                                               :checked="rec._selectedMenu && rec._selectedMenu.menu_id === cand.menu_id"
                                                                                               @change="selectMenuCandidate(rec, cand)"
                                                                                               class="text-blue-600 focus:ring-blue-500">
                                                                                        <span class="text-slate-900" x-text="cand.name"></span>
                                                                                    </div>
                                                                                    <div class="text-right">
                                                                                        <div class="font-mono text-slate-800" x-text="formatRp(cand.current_price)"></div>
                                                                                        <div class="text-[8px] text-slate-400">{{ __('copilot.widget.margin_label') }} <span class="font-bold text-amber-600" x-text="cand.current_margin_pct + '%'"></span></div>
                                                                                    </div>
                                                                                </label>
                                                                            </template>
                                                                        </div>
                                                                    </div>
                                                                </template>

                                                                <!-- Empty State -->
                                                                <template x-if="!rec.candidates || rec.candidates.length === 0">
                                                                    <div class="p-2 bg-amber-50 border border-amber-200 rounded text-amber-800 text-[10px]">
                                                                        {{ __('copilot.widget.no_candidates_eval') }}
                                                                    </div>
                                                                </template>

                                                                <!-- Selected Menu & Price Selection Flow -->
                                                                <template x-if="rec._selectedMenu">
                                                                    <div class="pt-1.5 border-t border-slate-200 space-y-1.5">
                                                                        <div class="bg-white p-2 rounded border border-slate-200 space-y-1">
                                                                            <div class="font-semibold text-slate-700 flex justify-between">
                                                                                <span>{{ __('copilot.widget.selected_menu_label') }}</span>
                                                                                <span class="font-bold text-slate-900" x-text="rec._selectedMenu.name"></span>
                                                                            </div>
                                                                            <div class="grid grid-cols-3 gap-1 text-[9px] text-slate-600 border-t border-slate-100 pt-1">
                                                                                <div>{{ __('copilot.widget.selling_price_label') }} <strong class="font-mono" x-text="formatRp(rec._selectedMenu.current_price)"></strong></div>
                                                                                <div>{{ __('copilot.widget.cogs_price_label') }} <strong class="font-mono" x-text="formatRp(rec._selectedMenu.cost_price)"></strong></div>
                                                                                <div>{{ __('copilot.widget.margin_label') }} <strong class="font-mono text-amber-600" x-text="rec._selectedMenu.current_margin_pct + '%'"></strong></div>
                                                                            </div>
                                                                        </div>

                                                                        <div class="space-y-0.5">
                                                                            <label class="block text-[10px] font-bold text-slate-800">
                                                                                {{ __('copilot.widget.owner_new_price_label') }}
                                                                            </label>
                                                                            <div class="text-[8px] text-slate-400">
                                                                                {{ __('copilot.widget.owner_new_price_sub') }}
                                                                            </div>
                                                                            <input type="number"
                                                                                   x-model.number="rec._ownerNewPrice"
                                                                                   @input="runMenuSimulation(rec)"
                                                                                   min="1"
                                                                                   step="100"
                                                                                   placeholder="{{ __('copilot.widget.owner_new_price_placeholder') }}"
                                                                                   class="w-full px-2 py-1 border border-slate-300 rounded text-[10px] font-mono">
                                                                        </div>

                                                                        <template x-if="rec._simulation">
                                                                            <div class="p-1.5 bg-blue-50/90 border border-blue-200 rounded space-y-1 text-[9px]">
                                                                                <div class="font-bold text-blue-950">{{ __('copilot.widget.margin_impact_title') }}</div>
                                                                                <div class="grid grid-cols-2 gap-1">
                                                                                    <div>{{ __('copilot.widget.diff_label') }} <span class="font-mono font-semibold" x-text="(rec._simulation.price_delta >= 0 ? '+' : '') + formatRp(rec._simulation.price_delta)"></span></div>
                                                                                    <div>{{ __('copilot.widget.change_label') }} <span class="font-mono font-semibold" x-text="(rec._simulation.price_change_pct >= 0 ? '+' : '') + rec._simulation.price_change_pct + '%'"></span></div>
                                                                                    <div>{{ __('copilot.widget.new_margin_label') }} <span class="font-mono font-bold text-blue-900" x-text="rec._simulation.resulting_margin_pct + '%'"></span></div>
                                                                                    <div>{{ __('copilot.widget.delta_label') }} <span class="font-mono font-semibold" x-text="(rec._simulation.margin_delta_pp >= 0 ? '+' : '') + rec._simulation.margin_delta_pp + ' pp'"></span></div>
                                                                                </div>
                                                                                <div class="text-[8px] text-slate-500 italic">{{ __('copilot.widget.unit_margin_note') }}</div>
                                                                            </div>
                                                                        </template>

                                                                        <template x-if="!rec._proposal">
                                                                            <button type="button"
                                                                                    @click="submitMenuProposal(rec)"
                                                                                    :disabled="!rec._ownerNewPrice || rec._ownerNewPrice <= 0 || rec._isProposing"
                                                                                    class="w-full py-1.5 px-2 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 text-white font-bold text-[10px] rounded transition">
                                                                                <span x-text="rec._isProposing ? @js(__('copilot.widget.submitting')) : @js(__('copilot.widget.submit_proposal_btn'))"></span>
                                                                            </button>
                                                                        </template>

                                                                        <template x-if="rec._proposal">
                                                                            <div class="p-2 bg-indigo-50 border border-indigo-200 rounded space-y-1 text-[9px]">
                                                                                <div class="flex justify-between font-bold text-indigo-950">
                                                                                    <span>{{ __('copilot.widget.pending_owner_approval') }}</span>
                                                                                    <span class="bg-indigo-200 text-indigo-900 px-1 py-0.2 rounded font-semibold" x-text="rec._proposal.decision || 'PENDING'"></span>
                                                                                </div>
                                                                                <div class="text-slate-600">
                                                                                    {{ __('copilot.widget.proposal_id_prefix') }}<span class="font-mono text-indigo-800" x-text="rec._proposal.proposal_id"></span>{{ __('copilot.widget.proposal_unverified_notice') }}
                                                                                </div>
                                                                            </div>
                                                                        </template>

                                                                        <template x-if="rec._error">
                                                                            <div class="p-1 bg-rose-50 border border-rose-200 rounded text-rose-800 text-[9px]" x-text="rec._error"></div>
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

                                <!-- Proposal Action Card -->
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

            <!-- Loading Indicator -->
            <div x-show="loading" class="flex items-center gap-2 p-3 bg-white border border-slate-200/80 rounded-2xl rounded-tl-sm max-w-[70%] text-xs text-slate-500">
                <div class="flex space-x-1">
                    <div class="w-1.5 h-1.5 bg-indigo-400 rounded-full animate-bounce"></div>
                    <div class="w-1.5 h-1.5 bg-indigo-400 rounded-full animate-bounce [animation-delay:0.15s]"></div>
                    <div class="w-1.5 h-1.5 bg-indigo-400 rounded-full animate-bounce [animation-delay:0.3s]"></div>
                </div>
                <span>{{ __('copilot.widget.analyzing') }}</span>
            </div>

            <!-- Error Banner -->
            <div x-show="error" 
                 class="p-3 bg-rose-50 border border-rose-200/80 rounded-xl text-xs text-rose-700 flex items-start gap-2">
                <svg class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <span x-text="error"></span>
            </div>

            <!-- Quick Suggestion Prompts (Only show if messages length <= 2) -->
            <div x-show="messages.length <= 2 && !loading" class="pt-3" x-data="{ showMore: false }">
                <div class="text-[11px] font-medium text-slate-400 mb-2">{{ __('copilot.widget.try_asking') }}</div>
                <div class="flex flex-col gap-2">
                    <button type="button" 
                            @click="sendQuickPrompt(@js(__('copilot.widget.quick_sales')))"
                            class="text-left text-xs text-slate-600 bg-white hover:bg-indigo-50 p-2.5 rounded-xl border border-slate-200/80 hover:border-indigo-200 transition-colors">
                        {{ __('copilot.widget.quick_sales') }}
                    </button>
                    <button type="button" 
                            @click="sendQuickPrompt(@js(__('copilot.widget.quick_menu')))"
                            class="text-left text-xs text-slate-600 bg-white hover:bg-indigo-50 p-2.5 rounded-xl border border-slate-200/80 hover:border-indigo-200 transition-colors">
                        {{ __('copilot.widget.quick_menu') }}
                    </button>
                    <template x-if="showMore">
                        <button type="button" 
                                @click="sendQuickPrompt(@js(__('copilot.widget.quick_simulation')))"
                                class="text-left text-xs text-slate-600 bg-white hover:bg-indigo-50 p-2.5 rounded-xl border border-slate-200/80 hover:border-indigo-200 transition-colors">
                            {{ __('copilot.widget.quick_simulation') }}
                        </button>
                    </template>
                    <button type="button"
                            x-show="!showMore"
                            @click="showMore = true"
                            class="text-[11px] text-indigo-500 hover:text-indigo-700 font-medium text-left px-1 transition-colors cursor-pointer">
                        {!! __('copilot.widget.see_more') !!}
                    </button>
                </div>
            </div>
        </div>

        <!-- Input Area -->
        <div x-ref="copilotComposer" class="p-3 bg-white border-t border-slate-100 shrink-0">
            <form @submit.prevent="sendMessage()" class="flex items-end gap-2">
                <div class="relative flex-1">
                    <textarea 
                           x-ref="copilotInput"
                           x-model="input"
                           :disabled="loading"
                           rows="1"
                           @focus="handleInputFocus($event)"
                           @keydown="if ($event.key === 'Enter' && !$event.shiftKey && !$event.isComposing) { $event.preventDefault(); sendMessage(); }"
                           @input="autoResize($event)"
                           placeholder="{{ __('copilot.widget.input_placeholder') }}" 
                           class="w-full text-sm bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 disabled:opacity-50 transition-colors resize-none overflow-y-auto"
                           style="max-height: 100px;"></textarea>
                </div>

                <button type="submit" 
                        @click.prevent="sendMessage()"
                        :disabled="loading || !(input && input.trim())"
                        aria-label="{{ __('copilot.widget.send_label') }}"
                        class="p-2.5 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white rounded-xl transition-colors disabled:opacity-30 disabled:cursor-not-allowed shrink-0 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"></path>
                    </svg>
                </button>
            </form>

        </div>
    </div>
</div>

<script>
function kachiCopilotWidget() {
    return {
        isOpen: false,
        loading: false,
        input: '',
        error: null,
        isMobile: (window.innerWidth < 1024) || ('ontouchstart' in window) || (navigator.maxTouchPoints > 0),
        viewportHeight: window.visualViewport ? Math.round(window.visualViewport.height) : window.innerHeight,
        viewportTop: window.visualViewport ? Math.round(window.visualViewport.offsetTop) : 0,
        savedScrollY: 0,
        messages: [
            {
                role: 'assistant',
                content: @json(__('copilot.greeting.default')),
                financial_card: null,
                proposal: null
            }
        ],
        history: [],

        get mobileViewportStyle() {
            if (!this.isMobile) {
                return '';
            }
            return {
                top: `${this.viewportTop}px`,
                left: '0px',
                right: '0px',
                width: '100%',
                height: `${this.viewportHeight}px`,
                maxHeight: `${this.viewportHeight}px`,
                bottom: 'auto',
            };
        },

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

        updateViewport() {
            this.isMobile = (window.innerWidth < 1024) || ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
            if (window.visualViewport) {
                this.viewportHeight = Math.round(window.visualViewport.height);
                this.viewportTop = Math.round(window.visualViewport.offsetTop);
            } else {
                this.viewportHeight = window.innerHeight;
                this.viewportTop = 0;
            }
            this.logDiagnostics();
        },

        logDiagnostics() {
            if (!this.isOpen || !this.isMobile) return;
            try {
                const panelEl = this.$refs.copilotPanel;
                const composerEl = this.$refs.copilotComposer;
                const inputEl = this.$refs.copilotInput;

                const panelRect = panelEl ? panelEl.getBoundingClientRect() : null;
                const composerRect = composerEl ? composerEl.getBoundingClientRect() : null;
                const isInputFocused = (document.activeElement === inputEl);

                const diag = {
                    timestamp: new Date().toISOString(),
                    windowInnerHeight: window.innerHeight,
                    visualViewportHeight: window.visualViewport ? Math.round(window.visualViewport.height) : null,
                    visualViewportOffsetTop: window.visualViewport ? Math.round(window.visualViewport.offsetTop) : null,
                    visualViewportPageTop: window.visualViewport ? Math.round(window.visualViewport.pageTop) : null,
                    windowScrollY: Math.round(window.scrollY || window.pageYOffset || 0),
                    docClientHeight: document.documentElement ? document.documentElement.clientHeight : null,
                    panelRect: panelRect ? {
                        top: Math.round(panelRect.top),
                        bottom: Math.round(panelRect.bottom),
                        height: Math.round(panelRect.height),
                        left: Math.round(panelRect.left),
                        right: Math.round(panelRect.right)
                    } : null,
                    composerRect: composerRect ? {
                        top: Math.round(composerRect.top),
                        bottom: Math.round(composerRect.bottom),
                        height: Math.round(composerRect.height)
                    } : null,
                    panelInlineStyle: panelEl ? {
                        top: panelEl.style.top,
                        height: panelEl.style.height,
                        bottom: panelEl.style.bottom,
                        maxHeight: panelEl.style.maxHeight
                    } : null,
                    activeElementTag: document.activeElement ? document.activeElement.tagName : null,
                    isTextareaFocused: isInputFocused
                };

                window.__KACHI_COPILOT_DIAG__ = diag;
                console.log('[KachiCopilot:Diag]', JSON.stringify(diag));
            } catch (err) {
                console.warn('[KachiCopilot:DiagError]', err);
            }
        },

        handleInputFocus(event) {
            if (this.isMobile) {
                window.scrollTo(0, 0);
                setTimeout(() => {
                    this.updateViewport();
                    this.scrollToBottom();
                }, 50);
                setTimeout(() => {
                    this.updateViewport();
                    this.scrollToBottom();
                }, 250);
            }
        },

        initWidget() {
            this.updateViewport();

            const onViewportChange = () => {
                this.updateViewport();
                if (this.isOpen && this.isMobile) {
                    this.scrollToBottom();
                }
            };

            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', onViewportChange);
                window.visualViewport.addEventListener('scroll', onViewportChange);
            }
            window.addEventListener('resize', onViewportChange);

            window.openKachiCopilot = (promptText) => {
                this.openWidget();
                if (promptText) {
                    this.input = promptText;
                }
            };

            window.addEventListener('open-copilot', (e) => {
                const prompt = e.detail?.prompt || '';
                window.openKachiCopilot(prompt);
            });
        },

        toggleWidget() {
            if (this.isOpen) {
                this.closeWidget();
            } else {
                this.openWidget();
            }
        },

        openWidget() {
            this.isOpen = true;
            this.savedScrollY = window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0;
            this.updateViewport();
            if (this.isMobile) {
                document.documentElement.classList.add('overflow-hidden');
                document.body.classList.add('overflow-hidden');
                window.scrollTo(0, 0);
            }
            this.$nextTick(() => {
                this.scrollToBottom();
                if (!this.isMobile && this.$refs.copilotInput) {
                    this.$refs.copilotInput.focus();
                }
            });
        },

        closeWidget() {
            this.isOpen = false;
            if (this.isMobile) {
                document.documentElement.classList.remove('overflow-hidden');
                document.body.classList.remove('overflow-hidden');
                document.documentElement.style.overflow = '';
                document.body.style.overflow = '';
                if (typeof this.savedScrollY === 'number') {
                    window.scrollTo(0, this.savedScrollY);
                }
            }
            if (this.$refs.copilotInput) {
                this.$refs.copilotInput.blur();
            }
        },

        resetChat() {
            this.messages = [
                {
                    role: 'assistant',
                    content: @json(__('copilot.widget.reset_confirm')),
                    financial_card: null,
                    proposal: null
                }
            ];
            this.history = [];
            this.error = null;
            this.input = '';
        },

        scrollToBottom() {
            const box = this.$refs.messageBox;
            if (box) {
                box.scrollTop = box.scrollHeight;
            }
        },

        autoResize(event) {
            const el = event ? event.target : this.$refs.copilotInput;
            if (!el) return;
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 100) + 'px';
        },

        sendQuickPrompt(promptText) {
            this.input = promptText;
            this.sendMessage();
        },

        async sendMessage() {
            const text = (this.input || '').trim();
            if (!text || this.loading) {
                return;
            }

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
            if (this.$refs.copilotInput) {
                this.$refs.copilotInput.style.height = 'auto';
            }

            this.$nextTick(() => this.scrollToBottom());

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
                const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
                const csrfToken = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';

                const response = await fetch("{{ route('copilot.chat') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        message: text,
                        history: this.history
                    })
                });

                if (!response.ok) {
                    if (response.status === 419 || response.status === 401) {
                        this.error = sanitizeText(@json(__('copilot.widget.session_expired')), 'Sesi telah berakhir. Silakan muat ulang halaman.');
                        return;
                    }
                    let errorJson = {};
                    try {
                        errorJson = await response.json();
                    } catch (e) {
                        // Non-JSON response
                    }
                    const errMsg = errorJson.message || errorJson.reply || (@js(__('copilot.widget.request_failed_status')) + response.status);
                    throw new Error(sanitizeText(errMsg, defaultFailedText));
                }

                let resData;
                try {
                    resData = await response.json();
                } catch (e) {
                    throw new Error(defaultFailedText);
                }

                // Deteksi apakah respons adalah Fallback Percakapan
                // Mendukung Controller baru (status: success, is_fallback: true ATAU status: error dengan is_fallback/code)
                // DAN Controller lama (status: error dengan HTTP 200 dan reply teks valid non-fatal)
                const fatalCodes = ['UNAUTHENTICATED', 'UNAUTHORIZED_OUTLET', 'UNAUTHORIZED_SUPPLIER', 'CONFIG_MISSING', 'VALIDATION_ERROR'];
                const responseCode = resData.code || (resData.data && resData.data.code);

                const isConversationalFallback = Boolean(
                    resData.is_fallback ||
                    (resData.data && resData.data.is_fallback) ||
                    ['CLARIFICATION_NEEDED', 'AI_PROVIDER_UNAVAILABLE', 'RESTOCK_CONTEXT_MISSING', 'NO_TENANT_SUPPLIERS', 'SUPPLIER_SPECIFICATION_NEEDED'].includes(responseCode) ||
                    (resData.status === 'error' && (resData.reply || (resData.data && resData.data.reply)) && !fatalCodes.includes(responseCode))
                );

                if (resData.status === 'error' && !isConversationalFallback) {
                    const rawError = resData.message || resData.reply || (resData.data && resData.data.reply) || defaultFailedText;
                    throw new Error(sanitizeText(rawError, defaultFailedText));
                }

                const rawReply = resData.reply || (resData.data && resData.data.reply);
                const reply = sanitizeText(rawReply, defaultFallbackText);
                const financialCard = isConversationalFallback ? null : (resData.financial_card || (resData.data && resData.data.financial_card) || null);
                const proposal = isConversationalFallback ? null : (resData.proposal || (resData.data && resData.data.proposal) || null);

                this.messages.push({
                    role: 'assistant',
                    content: reply,
                    financial_card: financialCard,
                    proposal: proposal,
                    is_fallback: isConversationalFallback
                });

                // Simpan multi-turn history client-side (maksimal 8 pesan / 4 turn)
                this.history.push({ role: 'user', content: text });
                this.history.push({ role: 'assistant', content: reply });
                if (this.history.length > 8) {
                    this.history = this.history.slice(-8);
                }
            } catch (err) {
                this.error = err.message || sanitizeText(@js(__('copilot.widget.contact_error')), 'Tidak dapat terhubung ke server.');
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollToBottom());
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
                this.$nextTick(() => this.scrollToBottom());
            }
        }
    };
}
</script>
@endunless
