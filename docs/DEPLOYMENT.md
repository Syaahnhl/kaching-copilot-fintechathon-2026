# Kaching Copilot: Evaluation & Reference Guide
**Fintechathon 2026 — International Track, Topic E: SME Finance Copilot**

---

## 1. Live Web Demo vs. Reference Repository

### Primary Evaluation Path: Live Web Demo
The primary, full-featured Kaching SME application is deployed and hosted live on:
* **Demo URL:** [https://kaching.id](https://kaching.id)
* **Access Credentials:** Provided separately by the project team for authorized competition judges.

Evaluators are strongly encouraged to evaluate the live web application to test the interactive Copilot chat, scenario simulator, risk radar, and action approval dialogs within a full production-like environment.

### Secondary Evaluation Path: Reference Architecture & Local Test Verification
This repository provides:
1. The **exact reference implementation** of the Finance Copilot orchestration engine (`ChatbotController`, `FinanceCopilotOrchestrationService`, and `ActionPolicyService`).
2. The **deterministic financial calculation algorithms** (`FinancialCalculationService`, `CashFlowForecastService`, `FinancingReadinessService`, `FinancialWarningService`, `FinancialScenarioService`).
3. The **automated test suites** validating mathematical correctness, multi-tenant isolation, replay defense, and the two-phase approval firewall.

Evaluators are not required to set up a standalone commercial POS environment. The instructions below describe how to inspect the code and execute the automated test suites locally.

---

## 2. Environment & Test Execution

### Prerequisites
- **PHP:** Version 8.2 or higher with standard extensions (`pdo`, `curl`, `mbstring`, `bcmath`).
- **Composer:** Version 2.0 or higher.
- **Database (for test execution):** SQLite (in-memory or file) or MySQL. Tests run using SQLite by default with transactional isolation (`RefreshDatabase`).

### Quick Setup for Running Test Suites
```bash
# 1. Clone or extract the repository
cd kaching-fintechathon-copilot

# 2. Setup environment configuration
cp .env.example .env

# 3. Install dependencies
composer install --no-interaction --prefer-dist

# 4. Generate application key
php artisan key:generate
```

---

## 3. Running Verified Automated Tests

The following core test suites verify the core FinTechathon Topic E claims:

### 1. What-If Purchase Simulation (Deterministic Math)
Validates that purchase simulations evaluate current drawer cash, projected operational inflows, and required safety buffers without financial hallucinations:
```bash
php artisan test tests/Unit/SimulatePurchaseTest.php
```
*Expected Result:* 6 tests passed (31 assertions).

### 2. Autonomous Financial Agent & Two-Phase Approval Firewall
Validates that the AI acts strictly as a proposer, consequential price mutations require Manager PIN verification, replay attacks are prevented (HTTP 404), and cross-tenant probes fail closed (HTTP 403):
```bash
php artisan test tests/Feature/AutonomousFinancialAgentIntegrationTest.php
```
*Expected Result:* 19 tests passed (80 assertions).

### 3. Financing Readiness Index
Validates the 5-pillar composite scoring algorithm (0–100 score across Revenue Stability, Margin Health, Cash Discipline, Operational Reliability, and Data Governance) with statutory regulatory disclaimers:
```bash
php artisan test tests/Feature/FinancingReadinessServiceTest.php
```
*Expected Result:* 6 tests passed (110 assertions).

### 4. Copilot Orchestration & Explainability Contract
Validates tool schema registration, context clustering, and the standardized explainability envelope (`[WHAT, WHY, CALCULATION, SOURCE, TIME_WINDOW]`):
```bash
php artisan test tests/Unit/FinanceCopilotOrchestrationServiceTest.php
```
*Expected Result:* 22 tests passed (118 assertions).

### 5. Financial Evidence Dossier PDF Export
Validates that the SME financing pack export generates clean PDF dossiers with complete tenant isolation:
```bash
php artisan test tests/Feature/FinancialEvidenceDossierExportTest.php
```
*Expected Result:* 6 tests passed (24 assertions).

---

## 4. Architectural Boundaries & Dependencies

| Copilot Intelligence Component | Supporting POS Data Layer Entity | Role in SME Finance Copilot |
|:---|:---|:---|
| `FinancialCalculationService` | `Transaction`, `TransactionItem` | Calculates factual gross revenue, COGS, and gross margins. |
| `CashFlowForecastService` | `Transaction`, `Shift`, `Expense` | Computes historical baseline and 7-day operating cash flow proxy. |
| `FinancialWarningService` | `Shift` (Drawer reconciliation) | Detects cash drawer variances and abnormal cashier voids/refunds. |
| `FinancialScenarioService` | `Menu`, `Item` | Simulates price/cost sensitivity without mutating active menu records. |
| `ActionPolicyService` | `Staff` (PIN & Role verification) | Enforces human-in-the-loop authorization via 6-digit Manager PIN. |
| `FinancingReadinessService` | Multi-dimensional POS History | Aggregates 5 weighted pillars into an objective readiness index. |
