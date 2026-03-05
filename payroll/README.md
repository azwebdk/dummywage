# Fogito HR Payroll System

Complete HR/payroll system based on the Fogito Wage Module Configuration Guide.

## Quick Start

```bash
cd payroll/backend
php -S localhost:8080 router.php
```

Then open **http://localhost:8080** in your browser.

## Requirements

- PHP 8.0+ with SQLite3 extension
- Modern web browser

## Project Structure

```
payroll/
├── backend/
│   ├── router.php              # PHP built-in server router
│   ├── database.php            # SQLite schema & seeding
│   ├── api/
│   │   └── index.php           # REST API (all endpoints)
│   └── lib/
│       └── WageCalculator.php  # 11-step calculation engine
├── frontend/
│   └── index.html              # React SPA (single file)
├── test_calculations.php       # PHP integration tests
└── verify_calculations.py      # Math verification (41 tests)
```

## Features Implemented

### Wage Rule Sets (Section 1)
- CRUD for rule sets with all configurable settings
- Overtime model (Tiered / Flat / None)
- Trigger mode (Per-day / Weekly / Combined)
- Balancing mode (None / Weekly / Monthly / Custom)
- Stacking mode (Cumulative / Highest wins / Supplement replaces OT)

### Overtime (Sections 2-3)
- Tiered overtime with configurable threshold and rates
- Flat rate overtime
- Per-day, weekly threshold, and combined trigger modes
- Balancing (strict per-day, weekly, monthly, custom period)
- Mid-week contract change pro-rata blending (Section 9)

### Supplements (Sections 4-5)
- Configurable time-of-day intervals (evening, night, etc.)
- Day-type supplements (Saturday, Sunday, holiday)
- Priority groups (A: Time/Day, B: Overtime, C: Special)
- All three stacking modes implemented
- Supplements always from BASE rate, never compounded

### Breaks (Section 6)
- Cascading resolution: Schedule → Contract → Rule Set
- Paid/unpaid break handling
- Long break capping for paid breaks
- EU Working Time Directive warnings (6h+ without break)

### Employee Management (Sections 7-8)
- Full contract settings with all fields from the guide
- Weekly schedule grid with per-day toggles
- Rotating schedule support
- Zero-hours contract support

### Calculation Engine (Section 10)
All 11 steps implemented:
1. Date/day type classification
2. Schedule loading with rotation support
3. Break handling with cascade
4. Time-of-day interval splitting
5. Normal vs overtime determination
6. Overtime tier application
7. Time-of-day supplements
8. Day-type supplements
9. Stacking resolution
10. Special supplements (schedule-change, guaranteed hours)
11. Wage line storage with all 11 wage codes (W01-W11)

### Additional Features
- Wage Simulator (test calculations without saving)
- Payroll Results view with filtering
- Holiday calendar management
- Warning system (EU Working Time, missing breaks)
- Contract change audit trail
- Dashboard with weekly summary

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET/POST/PUT/DELETE | `/api/rule-sets` | Wage rule set CRUD |
| GET/POST/PUT/DELETE | `/api/rule-sets/:id/intervals` | Supplement intervals |
| GET/POST/PUT/DELETE | `/api/employees` | Employee CRUD |
| GET/POST/PUT/DELETE | `/api/contracts` | Contract CRUD |
| GET/POST/PUT/DELETE | `/api/schedules` | Schedule CRUD |
| GET/POST/PUT/DELETE | `/api/time-entries` | Time entry CRUD |
| POST | `/api/calculate/simulate` | Simulate without saving |
| POST | `/api/calculate/entry/:id` | Recalculate entry |
| POST | `/api/calculate/week` | Calculate full week |
| GET | `/api/wage-lines` | Query wage lines |
| GET | `/api/dashboard` | Dashboard stats |
| GET | `/api/holidays` | Holiday calendar |
| GET | `/api/warnings` | System warnings |
