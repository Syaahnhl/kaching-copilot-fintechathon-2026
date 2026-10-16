# Kaching Copilot — Fintechathon 2026
**Track:** International Track  
**Topic:** E — SME Finance Copilot (*Theme: AI as a Financial Participant*)

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat&logo=php)](https://www.php.net)
[![Framework](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat&logo=laravel)](https://laravel.com)
[![Topic](https://img.shields.io/badge/FinTechathon-Topic%20E%20(SME%20Finance)-blue?style=flat)](https://kaching.id)
[![Architecture](https://img.shields.io/badge/Governance-Deterministic%20Grounding-green?style=flat)](docs/ARCHITECTURE.md)
[![License](https://img.shields.io/badge/License-Competition%20Evaluation%20Only-yellow.svg)](LICENSE)

---

## 1. Project Summary & Topic E Relevance
**Kaching SME Finance Copilot** transforms operational Point of Sale (POS) and inventory records of micro, small, and medium enterprises (MSMEs) into actionable, audit-grade financial intelligence.

Under the theme **"AI as a Financial Participant"**, Kaching Copilot addresses key financial blind spots faced by culinary and retail small businesses:
* High working-capital bleed and food waste from unforecasted procurement.
* Rapid profit margin compression driven by sudden raw material price inflation.
* Unforeseen liquidity depletion (*cash runway blindness*).
* Information asymmetry that blocks MSMEs from accessing formal bank credit.

### Architectural Core Principles
1. **Separation of Reasoning and Arithmetic:** Large Language Models (LLMs) are utilized strictly for semantic intent extraction, dialogue routing, and natural-language explanation. All financial arithmetic (revenue, COGS, margins, cash runway, and credit readiness) is executed deterministically by PHP and SQL calculation services (*Zero Financial Hallucination*).
2. **Proposer, Never Unilateral Executor:** Consequential actions that modify business state (e.g., changing retail prices, issuing vendor restock orders) cannot be autonomously executed by the agent. They require a two-phase approval protocol gated by a 6-digit Owner/Manager PIN.
3. **Multi-Tenant Strict Isolation:** Every financial aggregation, forecast, and simulation is strictly scoped by `user_id` and `outlet_id` at the database query level, with fail-closed HTTP 403 enforcement.

---

## 2. Feature Availability: Implemented vs. Roadmap

### A. Currently Implemented & Verified in this Submission
* **Grounded Financial Intelligence:** Real-time computation of net sales, cost of goods sold (COGS/HPP), gross profit, operating expenses, net margin, daily burn rate, and projected cash runway.
* **Proactive Operational Risk Radar:** Continuous rule-based anomaly detection across 6 dimensions: revenue drop (>25%), expense spikes (>150%), margin erosion (>5 pp), abnormal voids/refunds (>5%), cash drawer variances (>Rp 50.000), and cash runway exhaustion (<7 days).
* **7-Day Operational Cash-Flow Forecasting:** Multi-tier deterministic forecasting engine utilizing Day-of-Week (DOW) Seasonal Medians (Tier 1 baseline) with Weighted Moving Average (WMA-14 Tier 2) fallback.
* **What-If Decision Simulator:** Real-time simulation of price adjustments, expense shocks, and inventory purchase feasibility against safety cash reserves without mutating production state.
* **Two-Phase Action Authorization Firewall:** Structured action proposal generation (`ActionPolicyService`) with distributed locking, TTL expiration, single-use token consumption, and 6-digit Manager PIN authorization.
* **SME Financing Readiness Index (0–100):** Objective 5-pillar scoring framework (Revenue Stability 25%, Margin Health 20%, Cash Discipline 25%, Operational Reliability 15%, Data Governance 15%) paired with an exportable audit-grade PDF Evidence Dossier.
* **Dynamic Tool Clustering:** Algorithmic clustering of 25 canonical tools into 7 domain clusters (`core`, `sales`, `menu`, `cashflow`, `expenses`, `financing`, `risk`) reducing prompt token consumption by 51–88%.
* **Deterministic Fallback Engine:** Resilient fail-soft mechanism providing essential numerical summaries during upstream LLM latency or HTTP 429 rate-limiting events.

### B. Planned Roadmap (Future Enhancements)
* **Automated Accounts Payable (AP) Ingestion:** OCR and automated parser for recurring offline supplier invoices outside direct POS records.
* **Multi-Outlet Macro Benchmarking:** Anonymized peer cohort benchmarking across business categories.
* **Direct Open Banking Reconciliation:** Automated bank feed synchronization for multi-account liquidity management.

---

## 3. Live Web Demonstration
The primary interactive application is deployed and operational on the Kaching cloud platform. Evaluators are invited to test the live interactive conversational copilot directly on the web platform:

* **Demo URL:** [https://kaching.id](https://kaching.id)
* **Demo Evaluation Account:** Shared privately with the competition committee and evaluators (not committed to public source control for tenant security).
* **Evaluation Data:** The demonstration environment operates against pre-seeded synthetic merchant accounts (Tenants 901, 902, 903) and does not expose real customer or transaction records.

### Recommended Live Evaluation Protocol:
1. Navigate to [https://kaching.id](https://kaching.id) and log in with the provided testing credentials.
2. Access the **Copilot** interface from the backoffice navigation bar or floating assistant widget.
3. Test the documented evaluation scenarios detailed in [`docs/DEMO_SCENARIOS.md`](docs/DEMO_SCENARIOS.md):
   * *Scene A (Root Cause Investigation):* *"Kenapa laba saya turun minggu ini?"*
   * *Scene B (Pricing Simulation):* *"Simulasikan kalau harga Ayam Geprek naik Rp 2.000."*
   * *Scene C (Purchase Feasibility):* *"Apakah aman belanja stok bahan Rp 5.000.000 besok?"*
   * *Scene D (Action Authorization):* Propose price update $\rightarrow$ Verify proposal $\rightarrow$ Authorize via Manager PIN.
   * *Scene E (Financing Readiness):* *"Evaluasi kesiapan pinjaman modal usaha saya"* $\rightarrow$ Export Evidence Dossier PDF.

---

## 4. System Architecture & Workflows

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                             PRESENTATION LAYER                              │
│   Web Backoffice (Blade/Alpine.js)  │  Android POS (Jetpack Compose Cards)  │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ POST /copilot/chat
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    ORCHESTRATION & SECURITY GATEWAY                         │
│  - Multi-Tenant Isolation Middleware (Scoping by user_id & outlet_id)        │
│  - Fail-Closed Security Defense (HTTP 403 on tenant or outlet mismatch)     │
│  - Dynamic Prompt Builder (Clusters 25 tools down to 5-11 relevant tools)    │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                     ┌─────────────────┴─────────────────┐
                     ▼                                   ▼
          [Upstream Inference]                 [Deterministic Engine]
      Groq / OpenAI-compatible API          PHP 8.2+ / MySQL Grounding Services
       (Semantic Intent & Routing)         (Pure Arithmetic, Zero Hallucination)
                     │                                   │
                     └─────────────────┬─────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                     FOUR PILLARS OF SME COPILOT LOGIC                       │
├───────────────────────┬────────────────────────┬────────────────────────────┤
│  1. CONTROL           │  2. PREDICT            │  3. ACT                    │
│  - Net Sales & HPP    │  - 7-Day Cash Forecast │  - What-If Simulation      │
│  - Cash Drawer Varianc│    (DOW & WMA-14)      │  - Purchase Feasibility    │
│  - 6 Anomaly Warnings │  - Demand Velocity     │  - 2-Phase Action Firewall │
│    (Void, Margin Drop)│  - Material Inflation  │    (6-Digit PIN Required)  │
├───────────────────────┴────────────────────────┴────────────────────────────┤
│  4. PREPARE                                                                 │
│  - Objective SME Financing Readiness Index (0–100 Score across 5 Pillars)   │
│  - Explainability Envelope [WHAT, WHY, CALCULATION, SOURCE] + PDF Dossier   │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Action Authorization Firewall
The agent cannot directly mutate database records. When a user requests an operational change:
1. Copilot calls `prepare_action_proposal` to generate an immutable proposal token (`prop_*`) stored in cache with a 15-minute TTL.
2. The UI renders an **Approval Card** showing the projected impact, risk level (`LOW`, `MEDIUM`, `HIGH`), and parameter diffs.
3. The merchant reviews the proposal and submits their **6-digit Manager PIN**.
4. `CopilotActionApprovalController` verifies the PIN, checks tenant ownership, acquires an atomic lock, executes the mutation, invalidates the proposal token (preventing replay attacks), and writes an entry to `audit_logs`.

---

## 5. Local Setup & Reproduction Guide
While live demonstration is hosted at `https://kaching.id`, evaluators may run and verify the codebase locally.

### Prerequisites
* PHP 8.2 or higher with extensions: `pdo_sqlite`, `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`.
* Composer 2.x
* SQLite 3 (default local evaluation database)

### Installation Steps
```bash
# 1. Clone repository & enter directory
cd kaching-copilot-fintechathon-2026

# 2. Copy sanitized environment configuration
cp .env.example .env

# 3. Create SQLite database file
touch database/database.sqlite

# 4. Run database migrations
php artisan migrate --force

# 5. (Optional) Seed synthetic evaluation merchants (Tenants 901, 902, 903)
php artisan db:seed --class=DatabaseSeeder
```

---

## 6. Test Suite & Verification Results

### A. Canonical Test Suites (Verified 100% PASS)
The core financial logic, deterministic algorithms, action policy firewall, and explainability contracts are verified by **9 canonical test suites**.

Evaluators can execute all 9 canonical suites in a single command:
```bash
php artisan test \
  tests/Unit/SimulatePurchaseTest.php \
  tests/Feature/AutonomousFinancialAgentIntegrationTest.php \
  tests/Feature/FinancingReadinessServiceTest.php \
  tests/Unit/FinanceCopilotOrchestrationServiceTest.php \
  tests/Feature/FinancialEvidenceDossierExportTest.php \
  tests/Feature/ChatbotFinancingReadinessIntegrationTest.php \
  tests/Unit/FinancialInvestigationServiceTest.php \
  tests/Unit/FinancingCapacitySimulatorServiceTest.php \
  tests/Feature/ChatbotP0TransportAndFaqRoutingRegressionTest.php
```

### Verified Test Breakdown
| Suite Class | File Path | Focus Area | Result |
|:---|:---|:---|:---:|
| `SimulatePurchaseTest` | `tests/Unit/SimulatePurchaseTest.php` | Purchase safety reserve buffer math | **6 passed** (31 assertions) |
| `AutonomousFinancialAgentIntegrationTest` | `tests/Feature/AutonomousFinancialAgentIntegrationTest.php` | End-to-end multi-turn financial agent | **19 passed** (80 assertions) |
| `FinancingReadinessServiceTest` | `tests/Feature/FinancingReadinessServiceTest.php` | 5-pillar scoring & statutory disclaimers | **6 passed** (110 assertions) |
| `FinanceCopilotOrchestrationServiceTest` | `tests/Unit/FinanceCopilotOrchestrationServiceTest.php` | 19 tools registration & explainability | **22 passed** (118 assertions) |
| `FinancialEvidenceDossierExportTest` | `tests/Feature/FinancialEvidenceDossierExportTest.php` | PDF dossier export & tenant isolation | **6 passed** (24 assertions) |
| `ChatbotFinancingReadinessIntegrationTest` | `tests/Feature/ChatbotFinancingReadinessIntegrationTest.php` | API endpoint integration & AI abstraction | **3 passed** (11 assertions) |
| `FinancialInvestigationServiceTest` | `tests/Unit/FinancialInvestigationServiceTest.php` | Causal profit drop diagnostic engine | **5 passed** (29 assertions) |
| `FinancingCapacitySimulatorServiceTest` | `tests/Unit/FinancingCapacitySimulatorServiceTest.php` | Annuity loan installment feasibility | **5 passed** (33 assertions) |
| `ChatbotP0TransportAndFaqRoutingRegressionTest` | `tests/Feature/ChatbotP0TransportAndFaqRoutingRegressionTest.php` | Intent routing & fallback transport safety | **9 passed** (38 assertions) |
| **CANONICAL TOTAL** | **9 Test Suites** | **SME Finance Copilot Core Systems** | **81 PASSED (474 assertions)** |

*Execution duration: ~4.4 seconds on standard commodity hardware.*

### B. Testing Scope & Global Test Transparency
* **Scope Discipline:** The 81 canonical tests above verify 100% of the active Topic E SME Finance Copilot codebase.
* **Global Test Notice:** Running `php artisan test` across the entire directory without filters executes additional legacy unit tests preserved from early research iterations (e.g., historical failure-reproduction tests such as `SupplierWhatsAppDirectChatReproductionTest` and multi-turn live conversational mocks). Some of these legacy exploration tests require external network mocks or intentionally assert failure conditions, and sequential execution of the entire repository exceeds standard CLI timeout windows (>180 seconds). Evaluators are strongly advised to run the 9 canonical suites listed above.

---

## 7. Limitations & Operational Boundaries
* **Decision Support, Not Underwriting Authority:** Financial forecasts, simulations, and readiness scores are decision-support outputs designed to assist merchant judgment. They do not constitute a legal financial guarantee or formal loan approval.
* **Upstream AI Provider Availability:** In the event of upstream LLM outages or rate limits, the system automatically engages the local Deterministic Fallback Engine to maintain access to critical summaries.
* **Data Input Completeness:** Financial calculations reflect data recorded in the system. Missing expense entries or unrecorded cash transactions will naturally impact calculation precision.
* **Accounts Payable Scope:** Current forecasting captures recorded inventory purchases and logged operational expenses; upcoming unbilled invoices from external suppliers are not yet integrated.

---

## 8. Security & Licensing

### Security & Privacy Controls
* **Zero Production Credentials:** This repository contains no live API keys, signing keystores, `.env` files, or customer database dumps.
* **Isolated Synthetic Data:** Evaluation uses synthetic data fixtures (Popoyo Coffee Senopati, Warung Kopi Senja, Bistro Nusantara).
* **PIN Standard:** All high-impact actions strictly enforce a 6-digit Manager PIN requirement.

### License Notice
* This repository is licensed under the **Kaching Copilot Evaluation & Research License** (`LICENSE`), granting competition judges, organizers, and evaluators a non-exclusive license to inspect, execute, and evaluate the software for the 2026 Shenzhen International FinTechathon (Topic E).
* All third-party open-source components (including the Laravel framework, Symfony packages, and associated libraries) remain the intellectual property of their respective copyright holders under their original open-source licenses (MIT, BSD, LGPL, Apache 2.0).
* *Formal evaluation versus open-source licensing terms remain subject to organizer verification and committee guidance.*

---

## Repository Structure Overview
```
├── README.md                      # Project overview and competition brief
├── LICENSE                        # Competition Evaluation & Research License
├── .env.example                   # Sanitized configuration template
├── composer.json                  # Dependency manifest (proprietary reference)
├── app/
│   ├── Http/Controllers/         # ChatbotController & CopilotActionApprovalController
│   ├── Services/Financial/        # Deterministic financial engines (14 services)
│   ├── Services/Policy/           # ActionPolicyService (Two-Phase Firewall)
│   ├── Models/                    # Supporting data models (Transaction, Shift, Expense, Menu)
│   └── Helpers/TimezoneContract   # Standardized UTC/WIB timezone contracts
├── docs/
│   ├── ARCHITECTURE.md            # In-depth architectural flow & tool clustering
│   ├── DEMO_SCENARIOS.md          # Step-by-step evaluator testing scenarios (Scenes A-G)
│   └── DEPLOYMENT.md              # Detailed local reproduction & testing guide
├── resources/views/backoffice/    # Web backoffice Copilot interface & widgets
├── scripts/
│   ├── seed_copilot_synthetic_data.php   # Synthetic merchant seeder (Tenants 901-903)
│   └── verify_copilot_synthetic_data.php # Synthetic data verification harness
├── tests/
│   ├── Feature/                   # Integration, firewall, and export test suites
│   └── Unit/                      # Unit test suites for calculations & evidence
└── client-reference/android/      # Native Jetpack Compose card reference implementations
```
