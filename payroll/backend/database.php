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

        // Seed Danish holidays for 2026
        $this->seedHolidays();
        // Seed default supplement intervals if none exist
        $this->seedDefaultSupplements();
    }

    private function seedHolidays(): void {
        $count = $this->pdo->query("SELECT COUNT(*) FROM holidays WHERE calendar_name='danish'")->fetchColumn();
        if ($count > 0) return;

        $holidays = [
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

    private function seedDefaultSupplements(): void {
        // Check if any rule sets exist, if not create a default one with intervals
        $count = $this->pdo->query("SELECT COUNT(*) FROM wage_rule_sets")->fetchColumn();
        if ($count > 0) return;

        // Create a default rule set
        $this->pdo->exec("
            INSERT INTO wage_rule_sets (name, description) VALUES ('Default Standard', 'Default wage rule set for standard employees')
        ");
        $ruleSetId = $this->pdo->lastInsertId();

        $intervals = [
            ['Normal daytime', '06:00', '18:00', '1,2,3,4,5', 0, 'percentage', 0,    'A', 0, 'W01'],
            ['Evening',        '18:00', '23:00', '1,2,3,4,5', 0, 'percentage', 25.0,  'A', 1, 'W04'],
            ['Night',          '23:00', '06:00', '1,2,3,4,5', 0, 'percentage', 50.0,  'A', 2, 'W05'],
            ['Saturday',       '00:00', '24:00', '6',         0, 'percentage', 45.0,  'A', 3, 'W06'],
            ['Sunday',         '00:00', '24:00', '7',         0, 'percentage', 65.0,  'A', 4, 'W07'],
            ['Public holiday', '00:00', '24:00', '',          1, 'percentage', 100.0, 'A', 5, 'W08'],
        ];

        $stmt = $this->pdo->prepare("
            INSERT INTO supplement_intervals (rule_set_id, name, start_time, end_time, applies_to_days, applies_to_holidays, rate_type, rate_value, stacking_group, priority, wage_code)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($intervals as $i) {
            $stmt->execute(array_merge([$ruleSetId], $i));
        }
    }
}
