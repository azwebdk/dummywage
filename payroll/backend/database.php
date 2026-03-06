<?php
/**
 * Database initialization and connection for HR Payroll System
 */

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $dbPath = __DIR__ . '/payroll.sqlite';
        $this->pdo = new PDO("sqlite:$dbPath");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("PRAGMA journal_mode=WAL");
        $this->pdo->exec("PRAGMA foreign_keys=ON");
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    public function initSchema(): void {
        $this->pdo->exec("
            -- Wage Rule Sets (Section 1)
            CREATE TABLE IF NOT EXISTS wage_rule_sets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT DEFAULT '',
                overtime_model TEXT NOT NULL DEFAULT 'tiered' CHECK(overtime_model IN ('tiered','flat','none')),
                overtime_trigger_mode TEXT NOT NULL DEFAULT 'combined' CHECK(overtime_trigger_mode IN ('per_day','weekly','combined')),
                balancing_mode TEXT NOT NULL DEFAULT 'none' CHECK(balancing_mode IN ('none','weekly','monthly','custom')),
                balancing_period_weeks INTEGER DEFAULT NULL,
                stacking_mode TEXT NOT NULL DEFAULT 'cumulative' CHECK(stacking_mode IN ('cumulative','highest_wins','supplement_replaces_overtime')),
                holiday_calendar TEXT NOT NULL DEFAULT 'danish',
                breaks_paid INTEGER NOT NULL DEFAULT 0,
                default_break_duration INTEGER NOT NULL DEFAULT 30,
                -- Tier settings (Section 2.2)
                tier1_threshold REAL NOT NULL DEFAULT 3.0,
                tier1_rate REAL NOT NULL DEFAULT 50.0,
                tier2_rate REAL NOT NULL DEFAULT 100.0,
                flat_overtime_rate REAL NOT NULL DEFAULT 50.0,
                schedule_change_enabled INTEGER NOT NULL DEFAULT 1,
                schedule_change_rate REAL NOT NULL DEFAULT 50.0,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            );

            -- Supplement Intervals (Section 4)
            CREATE TABLE IF NOT EXISTS supplement_intervals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rule_set_id INTEGER NOT NULL REFERENCES wage_rule_sets(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                start_time TEXT NOT NULL,
                end_time TEXT NOT NULL,
                applies_to_days TEXT NOT NULL DEFAULT '1,2,3,4,5',
                applies_to_holidays INTEGER NOT NULL DEFAULT 0,
                rate_type TEXT NOT NULL DEFAULT 'percentage' CHECK(rate_type IN ('percentage','fixed')),
                rate_value REAL NOT NULL DEFAULT 0.0,
                stacking_group TEXT NOT NULL DEFAULT 'A' CHECK(stacking_group IN ('A','B','C')),
                priority INTEGER NOT NULL DEFAULT 1,
                wage_code TEXT NOT NULL DEFAULT 'W04',
                created_at TEXT DEFAULT (datetime('now'))
            );

            -- Holiday Calendars (Section 1)
            CREATE TABLE IF NOT EXISTS holidays (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                calendar_name TEXT NOT NULL DEFAULT 'danish',
                date TEXT NOT NULL,
                name TEXT NOT NULL,
                UNIQUE(calendar_name, date)
            );

            -- Employees
            CREATE TABLE IF NOT EXISTS employees (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                email TEXT DEFAULT '',
                employee_number TEXT UNIQUE,
                created_at TEXT DEFAULT (datetime('now'))
            );

            -- Employee Contracts (Section 7)
            CREATE TABLE IF NOT EXISTS employee_contracts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
                contract_type TEXT NOT NULL DEFAULT 'full_time' CHECK(contract_type IN ('full_time','part_time','hourly','zero_hours')),
                start_date TEXT NOT NULL,
                end_date TEXT DEFAULT NULL,
                total_weekly_hours REAL NOT NULL DEFAULT 37.0,
                base_hourly_wage REAL NOT NULL,
                monthly_salary REAL DEFAULT NULL,
                salary_type TEXT NOT NULL DEFAULT 'hourly' CHECK(salary_type IN ('hourly','monthly')),
                rule_set_id INTEGER NOT NULL REFERENCES wage_rule_sets(id),
                holiday_calendar TEXT NOT NULL DEFAULT 'danish',
                collective_agreement TEXT DEFAULT NULL,
                breaks_paid TEXT NOT NULL DEFAULT 'use_rule_set' CHECK(breaks_paid IN ('use_rule_set','yes','no')),
                default_break_duration INTEGER DEFAULT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            );

            -- Employee Schedules (Section 8)
            CREATE TABLE IF NOT EXISTS employee_schedules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
                schedule_name TEXT NOT NULL DEFAULT 'Default',
                rotation_order INTEGER NOT NULL DEFAULT 1,
                rotation_start_date TEXT DEFAULT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT DEFAULT (datetime('now'))
            );

            -- Schedule Days (Section 8.1)
            CREATE TABLE IF NOT EXISTS schedule_days (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                schedule_id INTEGER NOT NULL REFERENCES employee_schedules(id) ON DELETE CASCADE,
                day_of_week INTEGER NOT NULL CHECK(day_of_week BETWEEN 1 AND 7),
                is_active INTEGER NOT NULL DEFAULT 0,
                start_time TEXT DEFAULT NULL,
                end_time TEXT DEFAULT NULL,
                break_duration INTEGER DEFAULT NULL,
                block_number INTEGER NOT NULL DEFAULT 1,
                UNIQUE(schedule_id, day_of_week, block_number)
            );

            -- Time Entries
            CREATE TABLE IF NOT EXISTS time_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
                date TEXT NOT NULL,
                clock_in TEXT NOT NULL,
                clock_out TEXT NOT NULL,
                break_minutes INTEGER DEFAULT NULL,
                is_employer_cancelled INTEGER NOT NULL DEFAULT 0,
                notes TEXT DEFAULT '',
                created_at TEXT DEFAULT (datetime('now'))
            );

            -- Wage Lines (calculation results)
            CREATE TABLE IF NOT EXISTS wage_lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                time_entry_id INTEGER NOT NULL REFERENCES time_entries(id) ON DELETE CASCADE,
                employee_id INTEGER NOT NULL REFERENCES employees(id),
                date TEXT NOT NULL,
                wage_code TEXT NOT NULL,
                wage_type TEXT NOT NULL,
                hours REAL NOT NULL DEFAULT 0,
                base_rate REAL NOT NULL DEFAULT 0,
                multiplier REAL NOT NULL DEFAULT 1.0,
                supplement_pct REAL NOT NULL DEFAULT 0,
                amount REAL NOT NULL DEFAULT 0,
                is_break_time INTEGER NOT NULL DEFAULT 0,
                notes TEXT DEFAULT '',
                created_at TEXT DEFAULT (datetime('now'))
            );

            -- Contract Change Audit Log (Section 9)
            CREATE TABLE IF NOT EXISTS contract_changes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contract_id INTEGER NOT NULL REFERENCES employee_contracts(id),
                employee_id INTEGER NOT NULL,
                field_name TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT,
                effective_date TEXT NOT NULL,
                changed_by TEXT DEFAULT 'system',
                created_at TEXT DEFAULT (datetime('now'))
            );

            -- Warnings / Alerts
            CREATE TABLE IF NOT EXISTS warnings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL REFERENCES employees(id),
                date TEXT NOT NULL,
                warning_type TEXT NOT NULL,
                message TEXT NOT NULL,
                is_resolved INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now'))
            );
        ");

        // Seed data
        $this->seedHolidays();
        $this->seedRuleSets();
        $this->seedTestData();
        $this->calculateSeededEntries();
    }

    // =============================
    // HOLIDAYS
    // =============================
    private function seedHolidays(): void {
        $count = $this->pdo->query("SELECT COUNT(*) FROM holidays WHERE calendar_name='danish'")->fetchColumn();
        if ($count > 0) return;

        $holidays = [
            ['2025-01-01', 'Nytårsdag'],
            ['2025-04-17', 'Skærtorsdag'],
            ['2025-04-18', 'Langfredag'],
            ['2025-04-20', 'Påskedag'],
            ['2025-04-21', '2. Påskedag'],
            ['2025-05-29', 'Kr. Himmelfartsdag'],
            ['2025-06-08', 'Pinsedag'],
            ['2025-06-09', '2. Pinsedag'],
            ['2025-06-05', 'Grundlovsdag'],
            ['2025-12-25', 'Juledag'],
            ['2025-12-26', '2. Juledag'],
            ['2026-01-01', 'Nytårsdag'],
            ['2026-04-02', 'Skærtorsdag'],
            ['2026-04-03', 'Langfredag'],
            ['2026-04-05', 'Påskedag'],
            ['2026-04-06', '2. Påskedag'],
            ['2026-05-14', 'Kr. Himmelfartsdag'],
            ['2026-05-24', 'Pinsedag'],
            ['2026-05-25', '2. Pinsedag'],
            ['2026-06-05', 'Grundlovsdag'],
            ['2026-12-25', 'Juledag'],
            ['2026-12-26', '2. Juledag'],
        ];
        $stmt = $this->pdo->prepare("INSERT OR IGNORE INTO holidays (calendar_name, date, name) VALUES ('danish', ?, ?)");
        foreach ($holidays as $h) {
            $stmt->execute($h);
        }
    }

    // =============================
    // WAGE RULE SETS (5 diverse configs)
    // =============================
    private function seedRuleSets(): void {
        $count = $this->pdo->query("SELECT COUNT(*) FROM wage_rule_sets")->fetchColumn();
        if ($count > 0) return;

        // ===== 1. Default Standard =====
        // Standard Danish full-time: tiered overtime, combined trigger, no balancing, cumulative stacking
        $this->pdo->exec("
            INSERT INTO wage_rule_sets (name, description, overtime_model, overtime_trigger_mode, balancing_mode, stacking_mode, holiday_calendar, breaks_paid, default_break_duration, tier1_threshold, tier1_rate, tier2_rate, flat_overtime_rate, schedule_change_enabled, schedule_change_rate)
            VALUES ('Default Standard', 'Standard Danish full-time rules. Tiered overtime (50% first 3h, then 100%) with combined trigger (per-day + weekly). No balancing period — every daily overrun and weekly excess triggers overtime immediately. Supplements stack cumulatively on top of overtime.', 'tiered', 'combined', 'none', 'cumulative', 'danish', 0, 30, 3.0, 50.0, 100.0, 50.0, 1, 50.0)
        ");
        $rs1 = (int)$this->pdo->lastInsertId();
        $this->insertIntervals($rs1, [
            ['Normal daytime', '06:00', '18:00', '1,2,3,4,5', 0, 'percentage', 0,    'A', 0, 'W01'],
            ['Evening',        '18:00', '23:00', '1,2,3,4,5', 0, 'percentage', 25.0,  'A', 1, 'W04'],
            ['Night',          '23:00', '06:00', '1,2,3,4,5', 0, 'percentage', 50.0,  'A', 2, 'W05'],
            ['Saturday',       '00:00', '24:00', '6',         0, 'percentage', 45.0,  'A', 3, 'W06'],
            ['Sunday',         '00:00', '24:00', '7',         0, 'percentage', 65.0,  'A', 4, 'W07'],
            ['Public holiday', '00:00', '24:00', '',          1, 'percentage', 100.0, 'A', 5, 'W08'],
        ]);

        // ===== 2. Retail Flex =====
        // Part-time retail: per-day trigger, weekly balancing, paid breaks
        // Daily overruns can be offset if total week stays under contract hours
        $this->pdo->exec("
            INSERT INTO wage_rule_sets (name, description, overtime_model, overtime_trigger_mode, balancing_mode, stacking_mode, holiday_calendar, breaks_paid, default_break_duration, tier1_threshold, tier1_rate, tier2_rate, flat_overtime_rate, schedule_change_enabled, schedule_change_rate)
            VALUES ('Retail Flex', 'Retail with weekly balancing and paid 30-min breaks. Per-day trigger detects daily schedule overruns, but weekly balancing forgives them if the total week stays under the contracted weekly hours. Good for part-timers with variable shifts.', 'tiered', 'per_day', 'weekly', 'cumulative', 'danish', 1, 30, 2.0, 50.0, 100.0, 50.0, 1, 50.0)
        ");
        $rs2 = (int)$this->pdo->lastInsertId();
        $this->insertIntervals($rs2, [
            ['Normal daytime', '06:00', '18:00', '1,2,3,4,5', 0, 'percentage', 0,    'A', 0, 'W01'],
            ['Evening',        '18:00', '22:00', '1,2,3,4,5', 0, 'percentage', 30.0,  'A', 1, 'W04'],
            ['Late night',     '22:00', '06:00', '1,2,3,4,5', 0, 'percentage', 50.0,  'A', 2, 'W05'],
            ['Saturday',       '00:00', '24:00', '6',         0, 'percentage', 50.0,  'A', 3, 'W06'],
            ['Sunday',         '00:00', '24:00', '7',         0, 'percentage', 75.0,  'A', 4, 'W07'],
            ['Public holiday', '00:00', '24:00', '',          1, 'percentage', 100.0, 'A', 5, 'W08'],
        ]);

        // ===== 3. Night Shift Industrial =====
        // Flat 50% overtime (no tiers), per-day trigger, highest-wins stacking
        // Only the single highest supplement/overtime rate applies — no stacking
        $this->pdo->exec("
            INSERT INTO wage_rule_sets (name, description, overtime_model, overtime_trigger_mode, balancing_mode, stacking_mode, holiday_calendar, breaks_paid, default_break_duration, tier1_threshold, tier1_rate, tier2_rate, flat_overtime_rate, schedule_change_enabled, schedule_change_rate)
            VALUES ('Night Shift Industrial', 'Industrial night shift rules. Flat 50% overtime (no tiers). Per-day trigger only. Highest-wins stacking: among all applicable rates (overtime, evening, night, weekend supplements), only the single highest percentage applies. Prevents double-dipping.', 'flat', 'per_day', 'none', 'highest_wins', 'danish', 0, 30, 3.0, 50.0, 100.0, 50.0, 1, 50.0)
        ");
        $rs3 = (int)$this->pdo->lastInsertId();
        $this->insertIntervals($rs3, [
            ['Day shift',      '06:00', '18:00', '1,2,3,4,5', 0, 'percentage', 0,    'A', 0, 'W01'],
            ['Evening',        '18:00', '22:00', '1,2,3,4,5', 0, 'percentage', 20.0, 'A', 1, 'W04'],
            ['Night',          '22:00', '06:00', '1,2,3,4,5', 0, 'percentage', 35.0, 'A', 2, 'W05'],
            ['Saturday',       '00:00', '24:00', '6',         0, 'percentage', 50.0, 'A', 3, 'W06'],
            ['Sunday',         '00:00', '24:00', '7',         0, 'percentage', 70.0, 'A', 4, 'W07'],
            ['Public holiday', '00:00', '24:00', '',          1, 'percentage', 100.0,'A', 5, 'W08'],
        ]);

        // ===== 4. Office Monthly Balance =====
        // Monthly balancing: overtime only when total month hours exceed monthly threshold
        // Allows flexible daily hours — long days offset by short days within the month
        $this->pdo->exec("
            INSERT INTO wage_rule_sets (name, description, overtime_model, overtime_trigger_mode, balancing_mode, stacking_mode, holiday_calendar, breaks_paid, default_break_duration, tier1_threshold, tier1_rate, tier2_rate, flat_overtime_rate, schedule_change_enabled, schedule_change_rate)
            VALUES ('Office Monthly Balance', 'Office workers with monthly balancing. Combined trigger detects daily/weekly overruns, but monthly balancing forgives them if the month total stays within threshold (weekly hours x weeks in month). Great for flex-time office arrangements.', 'tiered', 'combined', 'monthly', 'cumulative', 'danish', 0, 30, 3.0, 50.0, 100.0, 50.0, 1, 50.0)
        ");
        $rs4 = (int)$this->pdo->lastInsertId();
        $this->insertIntervals($rs4, [
            ['Normal daytime', '06:00', '18:00', '1,2,3,4,5', 0, 'percentage', 0,    'A', 0, 'W01'],
            ['Evening',        '18:00', '23:00', '1,2,3,4,5', 0, 'percentage', 25.0, 'A', 1, 'W04'],
            ['Night',          '23:00', '06:00', '1,2,3,4,5', 0, 'percentage', 50.0, 'A', 2, 'W05'],
            ['Saturday',       '00:00', '24:00', '6',         0, 'percentage', 45.0, 'A', 3, 'W06'],
            ['Sunday',         '00:00', '24:00', '7',         0, 'percentage', 65.0, 'A', 4, 'W07'],
            ['Public holiday', '00:00', '24:00', '',          1, 'percentage', 100.0,'A', 5, 'W08'],
        ]);

        // ===== 5. Zero Hours Basic =====
        // No overtime model at all — all hours paid at base rate
        // Minimal supplements for evening/night/holiday
        $this->pdo->exec("
            INSERT INTO wage_rule_sets (name, description, overtime_model, overtime_trigger_mode, balancing_mode, stacking_mode, holiday_calendar, breaks_paid, default_break_duration, tier1_threshold, tier1_rate, tier2_rate, flat_overtime_rate, schedule_change_enabled, schedule_change_rate)
            VALUES ('Zero Hours Basic', 'Minimal rules for zero-hours and casual contracts. No overtime model — all hours paid at base rate regardless of total. Small evening/night supplements only. No weekend premium (works all days equally). Holiday supplement at 50%.', 'none', 'per_day', 'none', 'cumulative', 'danish', 0, 30, 3.0, 50.0, 100.0, 50.0, 0, 0.0)
        ");
        $rs5 = (int)$this->pdo->lastInsertId();
        $this->insertIntervals($rs5, [
            ['Normal daytime', '06:00', '18:00', '1,2,3,4,5,6,7', 0, 'percentage', 0,    'A', 0, 'W01'],
            ['Evening',        '18:00', '23:00', '1,2,3,4,5,6,7', 0, 'percentage', 15.0, 'A', 1, 'W04'],
            ['Night',          '23:00', '06:00', '1,2,3,4,5,6,7', 0, 'percentage', 25.0, 'A', 2, 'W05'],
            ['Public holiday', '00:00', '24:00', '',              1, 'percentage', 50.0, 'A', 3, 'W08'],
        ]);
    }

    private function insertIntervals(int $ruleSetId, array $intervals): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO supplement_intervals (rule_set_id, name, start_time, end_time, applies_to_days, applies_to_holidays, rate_type, rate_value, stacking_group, priority, wage_code)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($intervals as $i) {
            $stmt->execute(array_merge([$ruleSetId], $i));
        }
    }

    // =============================
    // TEST EMPLOYEES, CONTRACTS, SCHEDULES & TIME ENTRIES
    // =============================
    private function seedTestData(): void {
        $count = $this->pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
        if ($count > 0) return;

        // Map rule set names to IDs
        $rsRows = $this->pdo->query("SELECT id, name FROM wage_rule_sets ORDER BY id")->fetchAll();
        $rs = [];
        foreach ($rsRows as $r) {
            $rs[$r['name']] = (int)$r['id'];
        }

        // ========================================
        // EMPLOYEE 1: Anders Jensen — Full-time warehouse
        // Rule Set: Default Standard (combined trigger, no balancing, cumulative)
        // Scenario: Normal week + overtime day to show tiered overtime
        // ========================================
        $this->pdo->exec("INSERT INTO employees (first_name, last_name, email, employee_number) VALUES ('Anders', 'Jensen', 'anders.jensen@example.dk', 'EMP001')");
        $emp1 = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO employee_contracts (employee_id, contract_type, start_date, total_weekly_hours, base_hourly_wage, salary_type, rule_set_id) VALUES (?, 'full_time', '2025-01-01', 37.0, 180.00, 'hourly', ?)")
            ->execute([$emp1, $rs['Default Standard']]);

        // Schedule: Mon-Fri 07:00-15:00, 30min break → 7.5h/day, 37.5h/week
        $this->pdo->prepare("INSERT INTO employee_schedules (employee_id, schedule_name) VALUES (?, 'Standard Day')")->execute([$emp1]);
        $sched1 = (int)$this->pdo->lastInsertId();
        $this->insertScheduleDays($sched1, [
            [1, 1, '07:00', '15:00', 30],
            [2, 1, '07:00', '15:00', 30],
            [3, 1, '07:00', '15:00', 30],
            [4, 1, '07:00', '15:00', 30],
            [5, 1, '07:00', '15:00', 30],
            [6, 0, null, null, null],
            [7, 0, null, null, null],
        ]);

        // Time entries — last week (Feb 23-27) + this week (Mar 2-5)
        // Normal days + Wednesday overtime (stays until 17:30 = 2.5h extra)
        $this->insertTimeEntry($emp1, '2026-02-23', '07:00', '15:00', 30);  // Mon - normal
        $this->insertTimeEntry($emp1, '2026-02-24', '07:00', '15:00', 30);  // Tue - normal
        $this->insertTimeEntry($emp1, '2026-02-25', '07:00', '17:30', 30);  // Wed - 2.5h overtime
        $this->insertTimeEntry($emp1, '2026-02-26', '07:00', '15:00', 30);  // Thu - normal
        $this->insertTimeEntry($emp1, '2026-02-27', '07:00', '15:00', 30);  // Fri - normal

        $this->insertTimeEntry($emp1, '2026-03-02', '07:00', '15:00', 30);  // Mon - normal
        $this->insertTimeEntry($emp1, '2026-03-03', '07:00', '15:30', 30);  // Tue - 30min over
        $this->insertTimeEntry($emp1, '2026-03-04', '07:00', '15:00', 30);  // Wed - normal
        $this->insertTimeEntry($emp1, '2026-03-05', '07:00', '15:00', 30);  // Thu - normal (today)

        // ========================================
        // EMPLOYEE 2: Mette Nielsen — Part-time retail
        // Rule Set: Retail Flex (per-day trigger, weekly balancing, paid breaks)
        // Scenario: 3-day week, one day longer to show weekly balancing
        //   Mon 9h worked, but total week 23.5h < 24h threshold → daily overtime forgiven!
        // ========================================
        $this->pdo->exec("INSERT INTO employees (first_name, last_name, email, employee_number) VALUES ('Mette', 'Nielsen', 'mette.nielsen@example.dk', 'EMP002')");
        $emp2 = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO employee_contracts (employee_id, contract_type, start_date, total_weekly_hours, base_hourly_wage, salary_type, rule_set_id, breaks_paid) VALUES (?, 'part_time', '2025-03-15', 24.0, 155.00, 'hourly', ?, 'yes')")
            ->execute([$emp2, $rs['Retail Flex']]);

        // Schedule: Mon/Wed/Fri 09:00-17:00, 30min paid break → 7.5h net/day
        $this->pdo->prepare("INSERT INTO employee_schedules (employee_id, schedule_name) VALUES (?, 'Retail 3-day')")->execute([$emp2]);
        $sched2 = (int)$this->pdo->lastInsertId();
        $this->insertScheduleDays($sched2, [
            [1, 1, '09:00', '17:00', 30],
            [2, 0, null, null, null],
            [3, 1, '09:00', '17:00', 30],
            [4, 0, null, null, null],
            [5, 1, '09:00', '17:00', 30],
            [6, 0, null, null, null],
            [7, 0, null, null, null],
        ]);

        // Last week — Mon overtime (1.5h extra), Wed normal, Fri evening shift
        $this->insertTimeEntry($emp2, '2026-02-23', '09:00', '18:30', 30);  // Mon - 1.5h over schedule (but week = 23.5h < 24h → balanced!)
        $this->insertTimeEntry($emp2, '2026-02-25', '09:00', '17:00', 30);  // Wed - normal
        $this->insertTimeEntry($emp2, '2026-02-27', '12:00', '20:00', 30);  // Fri - evening shift (18:00-20:00 = 2h evening supplement)

        // This week — normal + Saturday pickup shift (non-contracted day)
        $this->insertTimeEntry($emp2, '2026-03-02', '09:00', '17:00', 30);  // Mon - normal
        $this->insertTimeEntry($emp2, '2026-03-04', '09:00', '17:00', 30);  // Wed - normal
        $this->insertTimeEntry($emp2, '2026-03-06', '09:00', '17:00', 30);  // Fri - normal

        // ========================================
        // EMPLOYEE 3: Lars Petersen — Full-time night shift
        // Rule Set: Night Shift Industrial (flat OT, per-day, highest_wins)
        // Scenario: Night shifts get night supplement. One long night shows
        //   how overtime (50%) beats night supplement (35%) under highest-wins
        // ========================================
        $this->pdo->exec("INSERT INTO employees (first_name, last_name, email, employee_number) VALUES ('Lars', 'Petersen', 'lars.petersen@example.dk', 'EMP003')");
        $emp3 = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO employee_contracts (employee_id, contract_type, start_date, total_weekly_hours, base_hourly_wage, salary_type, rule_set_id) VALUES (?, 'full_time', '2024-06-01', 37.0, 195.00, 'hourly', ?)")
            ->execute([$emp3, $rs['Night Shift Industrial']]);

        // Schedule: Mon-Fri 22:00-06:00, 30min break → 7.5h net/night
        $this->pdo->prepare("INSERT INTO employee_schedules (employee_id, schedule_name) VALUES (?, 'Night Rotation')")->execute([$emp3]);
        $sched3 = (int)$this->pdo->lastInsertId();
        $this->insertScheduleDays($sched3, [
            [1, 1, '22:00', '06:00', 30],
            [2, 1, '22:00', '06:00', 30],
            [3, 1, '22:00', '06:00', 30],
            [4, 1, '22:00', '06:00', 30],
            [5, 1, '22:00', '06:00', 30],
            [6, 0, null, null, null],
            [7, 0, null, null, null],
        ]);

        // Night shifts — date = the day the shift starts (clock_in 22:00, clock_out 06:00 crosses midnight)
        $this->insertTimeEntry($emp3, '2026-02-23', '22:00', '06:00', 30);  // Mon night - normal
        $this->insertTimeEntry($emp3, '2026-02-24', '22:00', '06:00', 30);  // Tue night - normal
        $this->insertTimeEntry($emp3, '2026-02-25', '22:00', '07:00', 30);  // Wed night - 1h overtime (highest-wins: OT 50% > night 35%)
        $this->insertTimeEntry($emp3, '2026-02-26', '22:00', '06:00', 30);  // Thu night - normal
        $this->insertTimeEntry($emp3, '2026-02-27', '22:00', '06:00', 30);  // Fri night - normal

        $this->insertTimeEntry($emp3, '2026-03-02', '22:00', '06:00', 30);  // Mon night - normal
        $this->insertTimeEntry($emp3, '2026-03-03', '22:00', '06:00', 30);  // Tue night - normal
        $this->insertTimeEntry($emp3, '2026-03-04', '22:00', '06:00', 30);  // Wed night - normal

        // ========================================
        // EMPLOYEE 4: Sofie Andersen — Office with monthly balance
        // Rule Set: Office Monthly Balance (combined trigger, monthly balancing)
        // Scenario: Varied daily hours — some long, some short. Monthly total
        //   stays under threshold, so ALL daily overruns are forgiven
        // ========================================
        $this->pdo->exec("INSERT INTO employees (first_name, last_name, email, employee_number) VALUES ('Sofie', 'Andersen', 'sofie.andersen@example.dk', 'EMP004')");
        $emp4 = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO employee_contracts (employee_id, contract_type, start_date, total_weekly_hours, base_hourly_wage, monthly_salary, salary_type, rule_set_id) VALUES (?, 'full_time', '2025-08-01', 37.0, 210.00, 35000.00, 'monthly', ?)")
            ->execute([$emp4, $rs['Office Monthly Balance']]);

        // Schedule: Mon-Fri 08:30-16:30, 30min break → 7.5h/day
        $this->pdo->prepare("INSERT INTO employee_schedules (employee_id, schedule_name) VALUES (?, 'Standard Office')")->execute([$emp4]);
        $sched4 = (int)$this->pdo->lastInsertId();
        $this->insertScheduleDays($sched4, [
            [1, 1, '08:30', '16:30', 30],
            [2, 1, '08:30', '16:30', 30],
            [3, 1, '08:30', '16:30', 30],
            [4, 1, '08:30', '16:30', 30],
            [5, 1, '08:30', '16:30', 30],
            [6, 0, null, null, null],
            [7, 0, null, null, null],
        ]);

        // Last week — varied hours: long Mon, short Tue, normal Wed, long Thu, short Fri
        // Total: 8.5+6.5+7.5+9+5 = 36.5h (under 37h, monthly balance forgives overruns)
        $this->insertTimeEntry($emp4, '2026-02-23', '08:30', '17:30', 30);  // Mon - 8.5h (1h over)
        $this->insertTimeEntry($emp4, '2026-02-24', '08:30', '15:30', 30);  // Tue - 6.5h (1h under)
        $this->insertTimeEntry($emp4, '2026-02-25', '08:30', '16:30', 30);  // Wed - 7.5h (normal)
        $this->insertTimeEntry($emp4, '2026-02-26', '08:30', '18:00', 30);  // Thu - 9h (1.5h over)
        $this->insertTimeEntry($emp4, '2026-02-27', '08:30', '14:00', 30);  // Fri - 5h (2.5h under)

        // This week — normal days
        $this->insertTimeEntry($emp4, '2026-03-02', '08:30', '16:30', 30);  // Mon - normal
        $this->insertTimeEntry($emp4, '2026-03-03', '08:00', '17:00', 30);  // Tue - 8h (30min over)
        $this->insertTimeEntry($emp4, '2026-03-04', '08:30', '16:30', 30);  // Wed - normal
        $this->insertTimeEntry($emp4, '2026-03-05', '08:30', '16:30', 30);  // Thu - normal (today)

        // ========================================
        // EMPLOYEE 5: Emil Christensen — Zero-hours contractor
        // Rule Set: Zero Hours Basic (no overtime, minimal supplements)
        // Scenario: Sporadic shifts, including evening work showing supplements
        //   No schedule defined — no schedule-change supplement applies
        // ========================================
        $this->pdo->exec("INSERT INTO employees (first_name, last_name, email, employee_number) VALUES ('Emil', 'Christensen', 'emil.christensen@example.dk', 'EMP005')");
        $emp5 = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO employee_contracts (employee_id, contract_type, start_date, total_weekly_hours, base_hourly_wage, salary_type, rule_set_id) VALUES (?, 'zero_hours', '2025-11-01', 0.0, 175.00, 'hourly', ?)")
            ->execute([$emp5, $rs['Zero Hours Basic']]);

        // No schedule for zero-hours worker

        // Sporadic entries with varying hours
        $this->insertTimeEntry($emp5, '2026-02-24', '10:00', '14:00', null); // Tue - 4h short shift (no break needed <6h)
        $this->insertTimeEntry($emp5, '2026-02-26', '08:00', '16:00', 30);   // Thu - 7.5h normal day
        $this->insertTimeEntry($emp5, '2026-03-02', '09:00', '13:00', null); // Mon - 4h short shift
        $this->insertTimeEntry($emp5, '2026-03-04', '14:00', '22:00', 30);   // Wed - afternoon into evening (18:00-22:00 = 4h evening supplement)
    }

    private function insertScheduleDays(int $scheduleId, array $days): void {
        $stmt = $this->pdo->prepare("INSERT INTO schedule_days (schedule_id, day_of_week, is_active, start_time, end_time, break_duration, block_number) VALUES (?, ?, ?, ?, ?, ?, 1)");
        foreach ($days as $day) {
            $stmt->execute(array_merge([$scheduleId], $day));
        }
    }

    private function insertTimeEntry(int $employeeId, string $date, string $clockIn, string $clockOut, ?int $breakMinutes): void {
        $stmt = $this->pdo->prepare("INSERT INTO time_entries (employee_id, date, clock_in, clock_out, break_minutes) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$employeeId, $date, $clockIn, $clockOut, $breakMinutes]);
    }

    // =============================
    // AUTO-CALCULATE ALL SEEDED ENTRIES
    // =============================
    private function calculateSeededEntries(): void {
        // Only calculate entries that don't have wage lines yet
        $entries = $this->pdo->query("
            SELECT te.id FROM time_entries te
            LEFT JOIN wage_lines wl ON wl.time_entry_id = te.id
            WHERE wl.id IS NULL
            ORDER BY te.date ASC, te.clock_in ASC
        ")->fetchAll();

        if (empty($entries)) return;

        require_once __DIR__ . '/lib/WageCalculator.php';
        $calc = new WageCalculator();

        foreach ($entries as $entry) {
            try {
                $calc->calculate((int)$entry['id']);
            } catch (Exception $e) {
                // Skip entries that can't be calculated (e.g., missing contract)
                // Log for debugging: error_log("Seed calc error for entry {$entry['id']}: " . $e->getMessage());
            }
        }
    }
}
