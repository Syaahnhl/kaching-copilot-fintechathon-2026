# Kaching Copilot: Evaluation & Demo Scenarios
**Fintechathon 2026 — International Track, Topic E: SME Finance Copilot**

This document specifies the canonical test and evaluation scenarios for verifying Kaching Copilot. Each scenario includes input, expected behavior, verification method, and confirmed status.

---

## Scenario Summary Table

| Scene | Name | Focus Dimension | Target Persona | Expected Status |
|:---|:---|:---|:---|:---:|
| **A** | Financial Investigation | Multi-step causal diagnosis & grounding | Tenant 902 (Warung Kopi Senja) | ✅ Verified (PASS) |
| **B** | What-If Pricing Simulation | Revenue/margin sensitivity without DB mutation | Tenant 901 (Popoyo Coffee) | ✅ Verified (PASS) |
| **C** | Capital Purchase Feasibility | Cash flow + forecast - purchase vs buffer | Tenant 901 / 903 | ✅ Verified (PASS) |
| **D** | Two-Phase Action Authorization | Proposal generation & Manager PIN firewall | Tenant 901 (Popoyo Coffee) | ✅ Verified (PASS) |
| **E** | Security: Replay Attack Defense | Immediate proposal token invalidation | Tenant 901 (Popoyo Coffee) | ✅ Verified (PASS) |
| **F** | Security: Cross-Tenant Isolation | Strict scoping & 403 fail-closed gate | Tenant 902 vs 901 | ✅ Verified (PASS) |
| **G** | Financing Readiness Assessment | 5-pillar objective scoring & dossier export | Tenant 901 (Popoyo Coffee) | ✅ Verified (PASS) |

---

## Detailed Scenario Protocols

### Scene A: Financial Investigation (Root Cause Analysis)
* **Context:** Merchant notices lower bank balance and asks Copilot for an explanation.
* **Input Prompt:** `"Kenapa laba bersih dan kas saya turun minggu ini?"`
* **Expected Behavior:**
  1. Copilot selects `get_financial_summary`, `get_active_financial_warnings`, and `get_sales_summary`.
  2. Identifies specific root causes from real ledger data: revenue drop (-38.5% vs baseline) combined with expense spike (2.0x normal).
  3. Presents exact figures grounded in database records without fabricating numbers.
  4. Delivers actionable corrective recommendations (review supplier costs, monitor shift voids).
* **Verification Method:** Inspect HTTP JSON response; check `tool_calls_executed` array and `data_cards` payload.
* **Actual Test Status:** ✅ **PASS** (Latency < 1.5s, 100% grounded in synthetic data).

---

### Scene B: What-If Pricing Simulation
* **Context:** Merchant wants to test increasing the price of a core menu item before committing.
* **Input Prompt:** `"Simulasikan kalau harga Ayam Geprek naik Rp 2.000, dampaknya ke laba kotor apa?"`
* **Expected Behavior:**
  1. Copilot triggers `simulate_financial_scenario` with parameter `PRICE_CHANGE`.
  2. Computes projected monthly gross profit uplift based on 30-day sales volume.
  3. Labels output clearly as `SIMULASI / WHAT-IF`.
  4. **Strict Guarantee:** Zero database changes occur in the `menus` or `items` table.
* **Verification Method:** Query database `SELECT price FROM menus WHERE name = 'Ayam Geprek'`. Value must remain unchanged.
* **Actual Test Status:** ✅ **PASS** (Zero database mutation confirmed).

---

### Scene C: Capital Purchase Feasibility Check
* **Context:** Merchant needs to buy raw materials tomorrow and checks whether operating cash allows it.
* **Input Prompt:** `"Apakah saya aman belanja bahan baku Rp 3.000.000 besok?"`
* **Expected Behavior:**
  1. Copilot queries current cash drawer (`getCashPosition()`) and Day 1 forecast inflow (`CashFlowForecastService`).
  2. Subtracts proposed purchase amount and compares against safety buffer ($3 \times \text{daily burn rate}$).
  3. Returns clear verdict: `WITHIN_BUFFER` (Safe) or `BELOW_BUFFER` (Caution).
* **Verification Method:** Confirm formula execution: $\text{Current Cash} + \text{Inflow} - \text{Purchase} \ge \text{Buffer}$.
* **Actual Test Status:** ✅ **PASS** (Unit test `tests/Unit/SimulatePurchaseTest.php` 6/6 pass).

---

### Scene D: Two-Phase Action Authorization (Human-in-the-Loop Firewall)
* **Context:** Copilot proposes a price adjustment based on margin erosion, but requires owner sign-off.
* **Input Prompt:** `"Buatkan draf penyesuaian harga Ayam Geprek naik Rp 2.000."`
* **Expected Behavior:**
  1. Phase 1: Copilot registers a structured proposal (`prop_xxxxxxxx`) with status `WAITING_FOR_APPROVAL` and 15-minute TTL.
  2. Returns a proposal confirmation card requesting the 6-digit Manager PIN.
  3. Phase 2: User submits PIN via `POST /api/copilot/actions/approve`.
  4. System verifies PIN, updates menu price, logs audit event in `audit_logs`, and marks proposal `ACTION_EXECUTED`.
* **Verification Method:** Check `audit_logs` table for `COPILOT_ACTION_APPROVE` and confirm menu price update.
* **Actual Test Status:** ✅ **PASS** (Integrated test `AutonomousFinancialAgentIntegrationTest.php` pass).

---

### Scene E: Security — Replay Attack Defense
* **Context:** Malicious or duplicate request re-submits an already-executed proposal token.
* **Input Action:** Submit identical proposal token to `POST /api/copilot/actions/approve`.
* **Expected Behavior:**
  1. System detects token has already been consumed or invalidated.
  2. Immediately rejects request with `HTTP 404 Not Found` or `HTTP 410 Gone`.
  3. Prevents double-mutation of prices or inventory.
* **Verification Method:** Assert second HTTP POST response code equals 404/410.
* **Actual Test Status:** ✅ **PASS** (Confirmed zero secondary mutations).

---

### Scene F: Security — Cross-Tenant Isolation
* **Context:** User authenticated under Tenant 902 attempts to inspect or approve an action belonging to Tenant 901.
* **Input Action:** Request `/api/chatbot/message` or `/api/copilot/actions/approve` with mismatched `outlet_id` or proposal token.
* **Expected Behavior:**
  1. Tenant scoping firewall intercepts request.
  2. Rejects request with `HTTP 403 Forbidden` (`UNAUTHORIZED_OUTLET` or `CROSS_USER_FORBIDDEN`).
  3. No financial records or metadata are exposed in the error response.
* **Verification Method:** Feature test asserting 403 response on mismatched session credentials.
* **Actual Test Status:** ✅ **PASS** (100% tenant isolation verified).

---

### Scene G: Financing Readiness Assessment & Dossier Export
* **Context:** SME owner prepares financial records to seek working-capital financing.
* **Input Prompt:** `"Berapa skor kesiapan pembiayaan usaha saya dan bagaimana rinciannya?"`
* **Expected Behavior:**
  1. Copilot triggers `calculate_financing_readiness`.
  2. Evaluates 5 pillars: Revenue Stability, Margin Health, Cash Discipline, Operational Reliability, Data Governance.
  3. Returns composite score (e.g., `92 / 100 — HIGH READINESS`) with clear breakdowns.
  4. Provides link to export formal PDF dossier (`/copilot/financing-readiness/export`).
  5. Includes mandatory compliance disclaimer: *"Indeks kesiapan data operasional, bukan jaminan persetujuan kredit."*
* **Verification Method:** Verify score math against `FinancingReadinessService` and check PDF export route.
* **Actual Test Status:** ✅ **PASS** (Feature test `FinancingReadinessServiceTest.php` 6/6 pass).
