# Kaching Copilot Architecture Specification
**Fintechathon 2026 — International Track, Topic E: SME Finance Copilot**  
**Theme:** *AI as a Financial Participant*

---

## 1. Core Architecture Thesis
Kaching Copilot is built on a strict financial governance principle: **The Large Language Model (LLM) is NOT a calculator, NOT an autonomous executor, and NOT an ungrounded oracle.**

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                             PRESENTATION LAYER                              │
│   Web Backoffice (Blade/Alpine.js)  │  Android POS (Jetpack Compose Cards)  │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ POST /api/chatbot/message
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    ORCHESTRATION & SECURITY LAYER                           │
│  - Sanctum Multi-Tenant Isolation (user_id & outlet_id scoping)             │
│  - Fail-Closed Security Gate (HTTP 403 on tenant mismatch)                  │
│  - Dynamic Prompt Builder (Clustering 25 tools into 7 semantic domains)     │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                     ┌─────────────────┴─────────────────┐
                     ▼                                   ▼
          [Upstream Inference]                 [Deterministic Engine]
         Groq / Llama-3.3-70b               PHP / MySQL Grounding Layer
       (Intent & Function Call)            (Pure Math, Zero Hallucination)
                     │                                   │
                     └─────────────────┬─────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                  FOUR FUNCTIONAL PILLARS OF INTELLIGENCE                    │
├───────────────────────┬────────────────────────┬────────────────────────────┤
│  1. CONTROL           │  2. PREDICT            │  3. ACT                    │
│  - Net Revenue & HPP  │  - Cash Flow Forecast  │  - What-If Simulation      │
│  - Drawer Variance    │    (DOW & WMA-14)      │  - Purchase Feasibility    │
│  - 6 Financial Alerts │  - Demand Velocity     │  - 2-Phase Action Firewall │
│    (Void, Margin)     │  - Material Trend      │    (Manager PIN Guard)     │
├───────────────────────┴────────────────────────┴────────────────────────────┤
│  4. PREPARE                                                                 │
│  - Objective SME Financing Readiness Index (0–100 Score across 5 Pillars)   │
│  - Machine-Readable Evidence Envelope & Exportable Financial Pack Dossier   │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Four Functional Pillars

### Pillar 1: CONTROL (Financial Operations & Anomaly Surveillance)
- **Deterministic Math (`FinancialCalculationService`):** Computes net revenue, COGS (Cost of Goods Sold), gross profit, operational expenses, burn rate, and runway strictly via database queries.
- **Anomaly Detection (`FinancialWarningService`):** Scans 6 distinct operational risks:
  1. *Revenue Decline:* Flags >20% drops against trailing baseline.
  2. *Expense Spike:* Flags operational costs >150% of historical average.
  3. *Margin Compression:* Flags erosion in menu gross margins.
  4. *Abnormal Voids/Refunds:* Flags cashier cancellations exceeding 5% of volume.
  5. *Cash Drawer Discrepancy:* Compares physical cash count against recorded payments.
  6. *Cash Depletion:* Alerts when projected runway falls below 7 days.

### Pillar 2: PREDICT (Short-Term Liquidity & Demand Forecasting)
- **Cash Flow Forecasting (`CashFlowForecastService`):** 7-day Operational Cash-Flow Proxy using a two-tier forecasting model:
  - *Tier 1 (Seasonal Baseline):* Day-of-Week (DOW) median observations across 28 days to eliminate single-day outliers.
  - *Tier 2 (Weighted Moving Average):* WMA-14 fallback when historical span is between 14 and 27 days.
  - *Tier 3 (Fail-Closed):* Returns explicit `INSUFFICIENT_HISTORY` rather than hallucinating estimates.
- **Demand Velocity (`StockoutIntelligenceService`):** Predicts stock runout velocity and flags stockout risks before inventory depletes.

### Pillar 3: ACT (Decision Simulation & Two-Phase Human Firewall)
- **What-If Simulation (`FinancialScenarioService`):** Simulates price changes, cost fluctuations, or inventory purchases without database mutation:
  $$\text{Projected Cash} = \text{Current Cash} + \text{Projected Inflow}_{\text{tomorrow}} - \text{Purchase Amount}$$
  $$\text{Buffer Required} = 3 \times \text{Daily Operating Burn}$$
  Verdicts: `WITHIN_BUFFER` (Safe), `BELOW_BUFFER` (Caution), or `BUFFER_BREACH` (Danger).
- **Two-Phase Action Firewall (`ActionPolicyService`):**
  - *Phase 1 (Proposal):* Copilot creates a structured proposal (`prop_*`) with risk classification and 15-minute TTL. No database changes occur.
  - *Phase 2 (Execution):* Execution requires explicit owner authorization via 6-digit Manager PIN. Replay attacks are mitigated by immediate token invalidation.

### Pillar 4: PREPARE (SME Financing Readiness & Objective Data Preparation)
- **Financing Readiness Index (`FinancingReadinessService`):** Generates an objective 0–100 composite score across 5 weighted pillars:
  1. *Revenue Stability (25%):* Coefficient of variation and consistency.
  2. *Margin Health (20%):* Gross margin stability and COGS tracking completeness.
  3. *Cash Discipline (25%):* Frequency and magnitude of drawer variances.
  4. *Operational Integrity (15%):* Low void/refund ratios (<3%).
  5. *Data Governance (15%):* Active operational tenure and menu recipe coverage.
- **Regulatory Disclaimer:** Explicitly stated as an operational data readiness assessment, NOT a credit underwriting decision or loan guarantee.

---

## 3. Dynamic Tool Clustering & Token Governance
To guarantee resilience under strict upstream provider limits, tools are clustered dynamically into 7 domains by `ChatbotPromptBuilder`:
- `core`: Today's sales, drawer status, active warnings.
- `sales`: Historical sales, volume leaders, velocity.
- `menu`: Menu engineering and margin optimization.
- `cashflow`: 7-day cash forecast and liquidity runway.
- `expenses`: Cost breakdowns and supplier records.
- `financing`: Readiness index and purchase feasibility.
- `risk`: Anomaly radar and material price trends.

**Result:** Tool definition payload is reduced from ~5,200 tokens to 1,200–2,400 tokens (51–88% reduction), preserving context space for multi-turn dialogue.

---

## 4. Explainability & Evidence Envelope
Every quantitative claim emitted by Kaching Copilot is wrapped in a standardized evidence envelope (`FinancialEvidenceService`):
```json
{
  "claim": "Net Operating Cash Flow 7-Day Forecast",
  "value": 24500000.00,
  "calculation_method": "DOW_SEASONAL_MEDIAN_28D",
  "source_tables": ["transactions", "expenses", "shifts"],
  "sample_size": 28,
  "confidence_level": "HIGH",
  "limitations": "Assumes standard operational hours without holiday disruptions.",
  "time_window": {
    "start": "2026-09-01T00:00:00+07:00",
    "end": "2026-09-28T23:59:59+07:00"
  }
}
```

---

## 5. Resilience & Upstream Fallback
1. **HTTP 429 Rate-Limit Mitigation:** Inspects `retry-after` header. Sleeps and retries if $\le 3.0\text{s}$; aborts immediately to local fallback if $> 3.0\text{s}$.
2. **Deterministic Fallback Engine:** If external AI inference times out or fails, local PHP generators construct structured KPI summary cards directly from cached metrics, guaranteeing merchant operational continuity.
