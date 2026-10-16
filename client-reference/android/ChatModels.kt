package com.syhnwu.posandroid.network

import androidx.annotation.Keep
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName

@Keep
data class ChatRequest(
    @SerializedName("message") val message: String
)

@Keep
data class ChatResponse(
    @SerializedName("status") val status: String,
    @SerializedName("reply") val reply: String? = null,
    @SerializedName("data") val data: ChatData?,
    @SerializedName("proposal") val proposal: CopilotProposal? = null,
    @SerializedName("financial_card") val financialCard: FinancialCardDto? = null
)

@Keep
data class ChatData(
    @SerializedName("reply") val reply: String,
    @SerializedName("proposal") val proposal: CopilotProposal? = null,
    @SerializedName("financial_card") val financialCard: FinancialCardDto? = null
)

@Keep
data class CopilotProposal(
    @SerializedName("proposal_id") val proposalId: String,
    @SerializedName("action") val action: String,
    @SerializedName("parameters") val parameters: JsonObject? = null,
    @SerializedName("required_role") val requiredRole: String? = null,
    @SerializedName("decision") val decision: String,
    @SerializedName("risk_level") val riskLevel: String? = null,
    @SerializedName("expires_at") val expiresAt: String? = null
)

// ==============================================================
// 🛡️ [B.8 / B.10-B2] ACTION APPROVAL & REJECTION CONTRACT
// ==============================================================

@Keep
data class CopilotActionApprovalRequest(
    @SerializedName("proposal_id") val proposalId: String,
    @SerializedName("staff_id") val staffId: Int,
    @SerializedName("pin") val pin: String
)

@Keep
data class CopilotActionRejectionRequest(
    @SerializedName("proposal_id") val proposalId: String,
    @SerializedName("reason") val reason: String? = null
)

@Keep
data class CopilotApproverInfo(
    @SerializedName("staff_id") val staffId: Int,
    @SerializedName("staff_name") val staffName: String? = null,
    @SerializedName("role") val role: String? = null
)

@Keep
data class CopilotActionApprovalData(
    @SerializedName("proposal_id") val proposalId: String,
    @SerializedName("action") val action: String,
    @SerializedName("status") val status: String,
    @SerializedName("executed_at") val executedAt: String? = null,
    @SerializedName("approver") val approver: CopilotApproverInfo? = null,
    @SerializedName("receipt") val receipt: JsonObject? = null
)

@Keep
data class CopilotActionApprovalResponse(
    @SerializedName("status") val status: String,
    @SerializedName("code") val code: String? = null,
    @SerializedName("message") val message: String? = null,
    @SerializedName("data") val data: CopilotActionApprovalData? = null
)

@Keep
data class CopilotActionRejectionData(
    @SerializedName("proposal_id") val proposalId: String,
    @SerializedName("action") val action: String,
    @SerializedName("status") val status: String,
    @SerializedName("rejected_at") val rejectedAt: String? = null,
    @SerializedName("reason") val reason: String? = null
)

@Keep
data class CopilotActionRejectionResponse(
    @SerializedName("status") val status: String,
    @SerializedName("code") val code: String? = null,
    @SerializedName("message") val message: String? = null,
    @SerializedName("data") val data: CopilotActionRejectionData? = null
)

object CopilotErrorCodes {
    const val INVALID_PIN = "INVALID_PIN"
    const val PROPOSAL_NOT_FOUND = "PROPOSAL_NOT_FOUND"
    const val PROPOSAL_EXPIRED = "PROPOSAL_EXPIRED"
    const val UNAUTHORIZED = "UNAUTHORIZED"
    const val RATE_LIMITED = "RATE_LIMITED"
    const val PIN_RATE_LIMITED = "PIN_RATE_LIMITED"
    const val CONCURRENT_APPROVAL = "PROPOSAL_LOCK_CONFLICT"
    const val VALIDATION_ERROR = "VALIDATION_FAILED"
    const val FORBIDDEN_ROLE = "FORBIDDEN_ROLE"
    const val POLICY_DENIED = "POLICY_DENIED"
    const val CROSS_USER_FORBIDDEN = "CROSS_USER_FORBIDDEN"
    const val CROSS_OUTLET_FORBIDDEN = "CROSS_OUTLET_FORBIDDEN"
    const val STAFF_NOT_FOUND = "STAFF_NOT_FOUND"
    const val MUTATION_FAILED = "MUTATION_FAILED"
    const val NETWORK_ERROR = "NETWORK_ERROR"
    const val UNKNOWN_ERROR = "UNKNOWN_ERROR"
}

sealed interface CopilotApprovalState {
    object Idle : CopilotApprovalState
    data class Loading(val proposalId: String) : CopilotApprovalState
    data class Success(val response: CopilotActionApprovalResponse) : CopilotApprovalState
    data class Error(val code: String, val message: String, val proposalId: String? = null) : CopilotApprovalState
}

sealed interface CopilotRejectionState {
    object Idle : CopilotRejectionState
    data class Loading(val proposalId: String) : CopilotRejectionState
    data class Success(val response: CopilotActionRejectionResponse) : CopilotRejectionState
    data class Error(val code: String, val message: String, val proposalId: String? = null) : CopilotRejectionState
}

// ==============================================================
// 🛡️ [C.2-B] FINANCIAL CARD DATA CONTRACT & SAFE PARSERS
// ==============================================================

@Keep
data class FinancialCardDto(
    @SerializedName("type") val type: String, // "summary" | "warning" | "forecast" | "scenario" | "radar"
    @SerializedName("title") val title: String,
    @SerializedName("severity") val severity: String? = null, // "critical" | "warning" | "watch" | "success"
    @SerializedName("data") val data: JsonObject? = null
)

@Keep
data class SummaryCardData(
    val date: String = "",
    val todaySales: Double = 0.0,
    val formattedSales: String = "",
    val currency: String = "IDR"
)

@Keep
data class WarningItemDto(
    val code: String = "",
    val severity: String = "warning",
    val title: String = "",
    val message: String = "",
    val metric: String? = null,
    val actual: Double? = null,
    val baseline: Double? = null,
    val threshold: Double? = null,
    val unit: String? = null
)

@Keep
data class WarningCardData(
    val status: String = "",
    val warningsCount: Int = 0,
    val warnings: List<WarningItemDto> = emptyList()
)

@Keep
data class DailyForecastDto(
    val date: String = "",
    val weekday: String = "",
    val projectedInflow: Double = 0.0,
    val projectedOutflow: Double = 0.0,
    val projectedNetOperatingCashFlow: Double = 0.0,
    val confidence: String = ""
)

@Keep
data class ForecastCardData(
    val status: String = "",
    val horizonDays: Int = 7,
    val startDate: String = "",
    val endDate: String = "",
    val projectedInflow: Double = 0.0,
    val projectedOutflow: Double = 0.0,
    val projectedNetCashFlow: Double = 0.0,
    val dailyForecast: List<DailyForecastDto> = emptyList()
)

@Keep
data class ScenarioMetricsDto(
    val revenue: Double = 0.0,
    val cogs: Double = 0.0,
    val grossProfit: Double = 0.0,
    val expenses: Double = 0.0,
    val netProfit: Double = 0.0,
    val grossMargin: Double = 0.0,
    val netMargin: Double = 0.0
)

@Keep
data class ScenarioDeltaDto(
    val revenue: Double = 0.0,
    val revenuePercent: Double = 0.0,
    val grossProfit: Double = 0.0,
    val netProfit: Double = 0.0,
    val netProfitPercent: Double = 0.0,
    val netMarginPp: Double = 0.0
)

@Keep
data class ScenarioCardData(
    val status: String = "",
    val scenarioType: String = "",
    val baseline: ScenarioMetricsDto? = null,
    val result: ScenarioMetricsDto? = null,
    val delta: ScenarioDeltaDto? = null
)

@Keep
data class RadarSignalDto(
    val id: String = "",
    val category: String = "",
    val headline: String = "",
    val summary: String = "",
    val sentiment: String = "",
    val severity: String = ""
)

@Keep
data class RadarCardData(
    val status: String = "",
    val signalCategory: String = "",
    val signalsCount: Int = 0,
    val matchedItemsCount: Int = 0,
    val sampleMatchedItems: List<String> = emptyList(),
    val signals: List<RadarSignalDto> = emptyList()
)

fun FinancialCardDto.extractSummaryData(): SummaryCardData {
    val d = this.data ?: return SummaryCardData()
    return SummaryCardData(
        date = d.get("date")?.takeIf { !it.isJsonNull }?.asString ?: "",
        todaySales = try {
            d.get("today_sales")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0
        } catch (_: Exception) { 0.0 },
        formattedSales = d.get("formatted_sales")?.takeIf { !it.isJsonNull }?.asString ?: "",
        currency = d.get("currency")?.takeIf { !it.isJsonNull }?.asString ?: "IDR"
    )
}

fun FinancialCardDto.extractWarningData(): WarningCardData {
    val d = this.data ?: return WarningCardData()
    val status = d.get("status")?.takeIf { !it.isJsonNull }?.asString ?: ""
    val count = try {
        d.get("warnings_count")?.takeIf { !it.isJsonNull }?.asInt ?: 0
    } catch (_: Exception) { 0 }

    val warningsList = mutableListOf<WarningItemDto>()
    val arr = d.getAsJsonArray("warnings")
    if (arr != null) {
        for (elem in arr) {
            if (elem.isJsonObject) {
                val obj = elem.asJsonObject
                warningsList.add(
                    WarningItemDto(
                        code = obj.get("code")?.takeIf { !it.isJsonNull }?.asString ?: "",
                        severity = obj.get("severity")?.takeIf { !it.isJsonNull }?.asString ?: "warning",
                        title = obj.get("title")?.takeIf { !it.isJsonNull }?.asString ?: "",
                        message = obj.get("message")?.takeIf { !it.isJsonNull }?.asString ?: "",
                        metric = obj.get("metric")?.takeIf { !it.isJsonNull }?.asString,
                        actual = try { obj.get("actual")?.takeIf { !it.isJsonNull }?.asDouble } catch (_: Exception) { null },
                        baseline = try { obj.get("baseline")?.takeIf { !it.isJsonNull }?.asDouble } catch (_: Exception) { null },
                        threshold = try { obj.get("threshold")?.takeIf { !it.isJsonNull }?.asDouble } catch (_: Exception) { null },
                        unit = obj.get("unit")?.takeIf { !it.isJsonNull }?.asString
                    )
                )
            }
        }
    }
    return WarningCardData(status = status, warningsCount = count, warnings = warningsList)
}

fun FinancialCardDto.extractForecastData(): ForecastCardData {
    val d = this.data ?: return ForecastCardData()
    val status = d.get("status")?.takeIf { !it.isJsonNull }?.asString ?: ""
    val horizon = try { d.get("horizon_days")?.takeIf { !it.isJsonNull }?.asInt ?: 7 } catch (_: Exception) { 7 }
    val start = d.get("start_date")?.takeIf { !it.isJsonNull }?.asString ?: ""
    val end = d.get("end_date")?.takeIf { !it.isJsonNull }?.asString ?: ""
    val inflow = try { d.get("projected_inflow")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 }
    val outflow = try { d.get("projected_outflow")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 }
    val net = try { d.get("projected_net_cash_flow")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 }

    val dailyList = mutableListOf<DailyForecastDto>()
    val arr = d.getAsJsonArray("daily_forecast")
    if (arr != null) {
        for (elem in arr) {
            if (elem.isJsonObject) {
                val obj = elem.asJsonObject
                dailyList.add(
                    DailyForecastDto(
                        date = obj.get("date")?.takeIf { !it.isJsonNull }?.asString ?: "",
                        weekday = obj.get("weekday")?.takeIf { !it.isJsonNull }?.asString ?: "",
                        projectedInflow = try { obj.get("projected_inflow")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
                        projectedOutflow = try { obj.get("projected_outflow")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
                        projectedNetOperatingCashFlow = try { obj.get("projected_net_operating_cash_flow")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
                        confidence = obj.get("confidence")?.takeIf { !it.isJsonNull }?.asString ?: ""
                    )
                )
            }
        }
    }

    return ForecastCardData(
        status = status,
        horizonDays = horizon,
        startDate = start,
        endDate = end,
        projectedInflow = inflow,
        projectedOutflow = outflow,
        projectedNetCashFlow = net,
        dailyForecast = dailyList
    )
}

private fun parseScenarioMetrics(obj: JsonObject?): ScenarioMetricsDto? {
    if (obj == null) return null
    return ScenarioMetricsDto(
        revenue = try { obj.get("revenue")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
        cogs = try { obj.get("cogs")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
        grossProfit = try { obj.get("gross_profit")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
        expenses = try { obj.get("expenses")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
        netProfit = try { obj.get("net_profit")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
        grossMargin = try { obj.get("gross_margin")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
        netMargin = try { obj.get("net_margin")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 }
    )
}

private fun parseScenarioDelta(obj: JsonObject?): ScenarioDeltaDto? {
    if (obj == null) return null
    return ScenarioDeltaDto(
        revenue = try { obj.get("revenue")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
        revenuePercent = try { obj.get("revenue_percent")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
        grossProfit = try { obj.get("gross_profit")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
        netProfit = try { obj.get("net_profit")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
        netProfitPercent = try { obj.get("net_profit_percent")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 },
        netMarginPp = try { obj.get("net_margin_pp")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 }
    )
}

fun FinancialCardDto.extractScenarioData(): ScenarioCardData {
    val d = this.data ?: return ScenarioCardData()
    val status = d.get("status")?.takeIf { !it.isJsonNull }?.asString ?: ""
    val type = d.get("scenario_type")?.takeIf { !it.isJsonNull }?.asString ?: ""
    val baseline = parseScenarioMetrics(d.getAsJsonObject("baseline"))
    val result = parseScenarioMetrics(d.getAsJsonObject("result"))
    val delta = parseScenarioDelta(d.getAsJsonObject("delta"))

    return ScenarioCardData(
        status = status,
        scenarioType = type,
        baseline = baseline,
        result = result,
        delta = delta
    )
}

fun FinancialCardDto.extractRadarData(): RadarCardData {
    val d = this.data ?: return RadarCardData()
    val status = d.get("status")?.takeIf { !it.isJsonNull }?.asString ?: ""
    val cat = d.get("signal_category")?.takeIf { !it.isJsonNull }?.asString ?: ""
    val sigCount = try { d.get("signals_count")?.takeIf { !it.isJsonNull }?.asInt ?: 0 } catch (_: Exception) { 0 }
    val matchCount = try { d.get("matched_items_count")?.takeIf { !it.isJsonNull }?.asInt ?: 0 } catch (_: Exception) { 0 }

    val sampleList = mutableListOf<String>()
    val sampleArr = d.getAsJsonArray("sample_matched_items")
    if (sampleArr != null) {
        for (s in sampleArr) {
            if (!s.isJsonNull) sampleList.add(s.asString)
        }
    }

    val signalsList = mutableListOf<RadarSignalDto>()
    val sigArr = d.getAsJsonArray("signals")
    if (sigArr != null) {
        for (elem in sigArr) {
            if (elem.isJsonObject) {
                val obj = elem.asJsonObject
                signalsList.add(
                    RadarSignalDto(
                        id = obj.get("id")?.takeIf { !it.isJsonNull }?.asString ?: "",
                        category = obj.get("category")?.takeIf { !it.isJsonNull }?.asString ?: "",
                        headline = obj.get("headline")?.takeIf { !it.isJsonNull }?.asString ?: "",
                        summary = obj.get("summary")?.takeIf { !it.isJsonNull }?.asString ?: "",
                        sentiment = obj.get("sentiment")?.takeIf { !it.isJsonNull }?.asString ?: "",
                        severity = obj.get("severity")?.takeIf { !it.isJsonNull }?.asString ?: ""
                    )
                )
            }
        }
    }

    return RadarCardData(
        status = status,
        signalCategory = cat,
        signalsCount = sigCount,
        matchedItemsCount = matchCount,
        sampleMatchedItems = sampleList,
        signals = signalsList
    )
}

@Keep
data class PillarDto(
    val score: Int = 0,
    val weight: Double = 0.0,
    val weightedContribution: Double = 0.0,
    val limitations: List<String> = emptyList()
)

@Keep
data class FinancingReadinessCardData(
    val score: Int = 0,
    val band: String = "",
    val revenueStability: PillarDto = PillarDto(),
    val marginHealth: PillarDto = PillarDto(),
    val cashDiscipline: PillarDto = PillarDto(),
    val operationalIntegrity: PillarDto = PillarDto(),
    val dataCompleteness: PillarDto = PillarDto(),
    val disclaimer: String = "",
    val limitations: List<String> = emptyList()
)

fun FinancialCardDto.extractFinancingReadinessData(): FinancingReadinessCardData {
    val d = this.data ?: return FinancingReadinessCardData()
    val score = try { d.get("score")?.takeIf { !it.isJsonNull }?.asInt ?: 0 } catch (_: Exception) { 0 }
    val band = d.get("band")?.takeIf { !it.isJsonNull }?.asString ?: ""
    val disclaimer = d.get("disclaimer")?.takeIf { !it.isJsonNull }?.asString ?: ""

    val limitationsList = mutableListOf<String>()
    val limArr = d.getAsJsonArray("limitations")
    if (limArr != null) {
        for (item in limArr) {
            if (!item.isJsonNull) limitationsList.add(item.asString)
        }
    }

    fun parsePillar(pillarKey: String): PillarDto {
        val pObj = d.getAsJsonObject("pillars")?.getAsJsonObject(pillarKey) ?: return PillarDto()
        val pScore = try { pObj.get("score")?.takeIf { !it.isJsonNull }?.asInt ?: 0 } catch (_: Exception) { 0 }
        val pWeight = try { pObj.get("weight")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 }
        val pContrib = try { pObj.get("weighted_contribution")?.takeIf { !it.isJsonNull }?.asDouble ?: 0.0 } catch (_: Exception) { 0.0 }
        val pLims = mutableListOf<String>()
        val pLimArr = pObj.getAsJsonArray("limitations")
        if (pLimArr != null) {
            for (elem in pLimArr) {
                if (!elem.isJsonNull) pLims.add(elem.asString)
            }
        }
        return PillarDto(score = pScore, weight = pWeight, weightedContribution = pContrib, limitations = pLims)
    }

    return FinancingReadinessCardData(
        score = score,
        band = band,
        revenueStability = parsePillar("revenue_stability"),
        marginHealth = parsePillar("margin_health"),
        cashDiscipline = parsePillar("cash_discipline"),
        operationalIntegrity = parsePillar("operational_integrity"),
        dataCompleteness = parsePillar("data_completeness"),
        disclaimer = disclaimer,
        limitations = limitationsList
    )
}

