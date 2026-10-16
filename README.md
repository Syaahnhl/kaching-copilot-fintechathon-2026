# Kaching Copilot — Fintechathon 2026
**Track:** International Track  
**Topic:** E — SME Finance Copilot (*Theme: AI as a Financial Participant*)

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat&logo=php)](https://www.php.net)
[![Framework](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat&logo=laravel)](https://laravel.com)
[![Topic](https://img.shields.io/badge/FinTechathon-Topic%20E%20(SME%20Finance)-blue?style=flat)](https://kaching.id)
[![Architecture](https://img.shields.io/badge/Governance-Deterministic%20Grounding-green?style=flat)](docs/ARCHITECTURE.md)
[![License](https://img.shields.io/badge/License-Competition%20Evaluation%20Only-yellow.svg)](LICENSE)

---

## 1. About the Project
Kaching Copilot helps small and medium-sized businesses understand their financial performance, identify operational risks, forecast short-term cash flow, simulate business decisions, and assess financing readiness.

The system separates language-model reasoning from deterministic financial calculations. Financial outputs are grounded in application data and calculation services, while consequential actions require human authorization.

### Core Value Proposition for SMEs
* **Grounded Intelligence:** Free-form LLMs frequently make calculation errors when performing multi-digit arithmetic. Kaching Copilot eliminates financial hallucinations by offloading all math to deterministic SQL and PHP engines.
* **Proactive Risk Radar:** Continuously evaluates 6 critical operational risks (unusual voids, drawer cash variances, margin erosion, expense spikes, revenue drops, and runway depletion).
* **Two-Phase Action Authorization:** The AI acts as a proposer, never an autonomous executor. Operational changes (e.g., price updates, vendor restocks) require explicit 6-digit Manager PIN verification.
* **Financing Readiness (5 Pillars):** Transforms messy point-of-sale cash records into an objective 0–100 data readiness index with exportable audit dossiers to accelerate SME micro-credit underwriting.

---

## 2. Live Demo
* **Demo URL:** [https://kaching.id](https://kaching.id)
* **Demo Account:** Provided separately by the project team.
* **Security Notice:** For security, demo credentials are shared privately with authorized evaluators and must not be committed to this repository.

### How to Evaluate the Live Demo:
1. Open the demo URL (`https://kaching.id`).
2. Sign in using the supplied testing account.
3. Open the Copilot interface.
4. Try the scenarios listed in `docs/DEMO_SCENARIOS.md`.
5. Compare the responses against the documented expected behavior.

*The demo account uses approved testing data and does not provide unauthorized access to real customer records or production transactions.*

---

## 3. Evaluation Scenarios
The evaluation covers financial calculations, anomaly detection, cash-flow forecasting, what-if simulations, financing readiness, evidence transparency, AI-provider fallback, and human approval controls.

Each scenario specifies its input, expected behavior, verification method, and actual test status.

| Scene | Name | Focus Dimension | Target Persona | Verification Status |
|:---|:---|:---|:---|:---:|
| **Scene A** | Financial Investigation | Multi-step causal diagnosis & grounding | Tenant 902 (Warung Kopi Senja) | ✅ Verified (PASS) |
| **Scene B** | What-If Pricing Simulation | Revenue/margin sensitivity without DB mutation | Tenant 901 (Popoyo Coffee) | ✅ Verified (PASS) |
| **Scene C** | Capital Purchase Feasibility | Cash flow + forecast - purchase vs buffer | Tenant 901 / 903 | ✅ Verified (PASS) |
| **Scene D** | Two-Phase Action Authorization | Proposal generation & Manager PIN firewall | Tenant 901 (Popoyo Coffee) | ✅ Verified (PASS) |
| **Scene E** | Replay Attack Defense | Single-use proposal token invalidation (HTTP 404/410) | Tenant 901 (Popoyo Coffee) | ✅ Verified (PASS) |
| **Scene F** | Cross-Tenant Isolation | Multi-outlet boundary enforcement (HTTP 403) | Tenant 902 vs 901 | ✅ Verified (PASS) |
| **Scene G** | Financing Readiness Assessment | 5-pillar objective scoring & dossier export | Tenant 901 (Popoyo Coffee) | ✅ Verified (PASS) |

*Full scenario protocols and verification steps are detailed in [`docs/DEMO_SCENARIOS.md`](docs/DEMO_SCENARIOS.md).*

---

## 4. Architecture & Submission Scope
The system uses an LLM for intent interpretation and natural-language responses. Financial calculations are performed by application services rather than delegated to free-form model arithmetic.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                             PRESENTATION LAYER                              │
│   Web Backoffice (Blade/Alpine.js)  │  Android POS (Jetpack Compose Cards)  │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ POST /copilot/chat
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

### Purpose of this Repository
1. **Reference Implementation:** This repository contains the reference codebase, architecture contracts, and test suites for the **SME Finance Copilot** component of Kaching.
2. **Evaluator Verification:** Independent evaluators can inspect the exact mathematical algorithms, action policy firewalls, and prompt clustering logic.
3. **Exclusion of Unrelated Proprietary Systems:** To protect commercial assets while providing full transparency for the competition, unrelated point-of-sale hardware modules (Kitchen Display System/KDS, raw offline-sync workers, proprietary WhatsApp parsers, payroll, and SaaS superadmin consoles) have been excluded. Supporting data models (`Transaction`, `Shift`, `Expense`, `Menu`, `Item`, `Supplier`) are preserved to ground the financial calculation services.

*See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for full architectural diagrams, data flows, and design invariants.*

---

## 5. Testing and Reproducibility
Automated tests and benchmark results are documented separately from manual demo results.

Tests requiring external AI credentials, network access, or a configured database are identified explicitly. Only verified results are reported as passing.

### Test Verification Commands
Evaluators can execute the automated unit and feature test suites using PHPUnit:
```bash
# 1. What-If Purchase Simulation (Deterministic Math)
php artisan test tests/Unit/SimulatePurchaseTest.php

# 2. Autonomous Financial Agent & Two-Phase Action Firewall (Manager PIN)
php artisan test tests/Feature/AutonomousFinancialAgentIntegrationTest.php

# 3. Financing Readiness Index (5-Pillar Score & Statutory Disclaimer)
php artisan test tests/Feature/FinancingReadinessServiceTest.php

# 4. Copilot Tool Orchestration & Explainability Contract
php artisan test tests/Unit/FinanceCopilotOrchestrationServiceTest.php

# 5. Financial Evidence Dossier PDF Export
php artisan test tests/Feature/FinancialEvidenceDossierExportTest.php
```

*See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) for environment requirements and test execution details.*

---

## 6. Limitations
* **Decision Support Only:** Forecasts and readiness scores are decision-support outputs, not guarantees of future financial performance or official credit underwriting decisions.
* **External AI Dependency:** External AI services (Groq / OpenAI) may experience transient network latency or rate-limiting. A local Deterministic Fallback Engine is provided to ensure continuous access to key financial summaries during upstream outages.
* **Source Data Dependency:** The quality of financial insights and anomaly detection depends directly on the completeness and correctness of source transaction and expense records entered by the merchant.
* **Accounts Payable Scope:** Upcoming vendor invoices outside direct POS procurement records are excluded from liquidity forecasting.

---

## 7. Security & Licensing
* **Zero Credential Exposure:** This repository contains no production credentials, private keys, signing keystores, live customer database dumps, or real merchant `.env` files.
* **Sanitized Testing Fixtures:** All benchmarks and demonstrations use deterministic synthetic merchant profiles (Tenants 901, 902, 903).
* **Tenant Isolation:** Enforces strict database-level `user_id` and `outlet_id` scoping across all copilot queries with fail-closed HTTP 403 defenses.
* **Action Safety Firewall:** Consequential actions cannot be executed directly by the LLM; every mutation requires human authorization with Manager PIN validation.
* **License Notice:** The proprietary software, algorithms, and documentation in this repository are licensed solely for evaluation and judging purposes of Fintechathon 2026. Third-party open-source components are subject to their respective open-source licenses. See [`LICENSE`](LICENSE) for details.

---

## Repository Structure Overview
```
├── README.md                      # Project overview and competition brief
├── LICENSE                        # Competition Evaluation & Research License
├── .env.example                   # Sanitized configuration template
├── composer.json                  # PHP dependency manifest
├── app/
│   ├── Http/Controllers/         # ChatbotController & Action Approval
│   ├── Services/Financial/        # Deterministic financial engines & services
│   ├── Services/Policy/           # ActionPolicyService (Two-Phase Firewall)
│   ├── Models/                    # Supporting data models (Transaction, Shift, etc.)
│   └── Helpers/TimezoneContract   # Standardized UTC/WIB time contracts
├── docs/
│   ├── ARCHITECTURE.md            # Detailed architecture and data flows
│   ├── DEMO_SCENARIOS.md          # Evaluation scenarios and expected outputs
│   └── DEPLOYMENT.md              # Evaluator environment and test guide
├── resources/views/backoffice/    # Interactive Web Copilot interface
├── scripts/
│   ├── seed_copilot_synthetic_data.php   # Synthetic testbed seeder (Tenants 901-903)
│   ├── verify_copilot_synthetic_data.php # Synthetic data verification
│   └── benchmark_copilot_phase_d2_4.php  # Canonical 45-query evaluation harness
├── tests/
│   ├── Feature/                   # Integration and firewall test suites
│   └── Unit/                      # Unit tests for calculations and evidence
└── client-reference/android/      # Native Jetpack Compose card reference
```
