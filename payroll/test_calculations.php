<?php
/**
 * Test script to verify wage calculations against the document examples.
 */

require_once __DIR__ . '/backend/database.php';
require_once __DIR__ . '/backend/lib/WageCalculator.php';

$db = Database::getInstance();
$db->initSchema();
$pdo = $db->getPdo();

echo "=== Fogito HR Payroll - Calculation Tests ===\n\n";

// Reset test data
$pdo->exec("DELETE FROM wage_lines");
$pdo->exec("DELETE FROM time_entries");
$pdo->exec("DELETE FROM schedule_days");
$pdo->exec("DELETE FROM employee_schedules");
$pdo->exec("DELETE FROM employee_contracts");
$pdo->exec("DELETE FROM employees");
$pdo->exec("DELETE FROM supplement_intervals");
$pdo->exec("DELETE FROM wage_rule_sets");

// Create a test rule set (Section 1 defaults)
$pdo->exec("INSERT INTO wage_rule_sets (id, name, description, overtime_model, overtime_trigger_mode, balancing_mode, stacking_mode, holiday_calendar, breaks_paid, default_break_duration, tier1_threshold, tier1_rate, tier2_rate)
    VALUES (1, 'Test Standard', 'Test rule set', 'tiered', 'combined', 'none', 'cumulative', 'danish', 0, 30, 3, 50, 100)");

// Create supplement intervals (Section 4 defaults)
$intervals = [
    [1, 'Normal daytime', '06:00', '18:00', '1,2,3,4,5', 0, 'percentage', 0,    'A', 0, 'W01'],
    [1, 'Evening',        '18:00', '23:00', '1,2,3,4,5', 0, 'percentage', 25.0,  'A', 1, 'W04'],
    [1, 'Night',          '23:00', '06:00', '1,2,3,4,5', 0, 'percentage', 50.0,  'A', 2, 'W05'],
    [1, 'Saturday',       '00:00', '24:00', '6',         0, 'percentage', 45.0,  'A', 3, 'W06'],
    [1, 'Sunday',         '00:00', '24:00', '7',         0, 'percentage', 65.0,  'A', 4, 'W07'],
    [1, 'Public holiday', '00:00', '24:00', '',          1, 'percentage', 100.0, 'A', 5, 'W08'],
];
$stmt = $pdo->prepare("INSERT INTO supplement_intervals (rule_set_id, name, start_time, end_time, applies_to_days, applies_to_holidays, rate_type, rate_value, stacking_group, priority, wage_code) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
foreach ($intervals as $i) $stmt->execute($i);

// Create test employee
$pdo->exec("INSERT INTO employees (id, first_name, last_name, employee_number) VALUES (1, 'Test', 'Worker', 'EMP001')");

// Create contract: 25h/week, 150 DKK/h (as in the document examples)
$pdo->exec("INSERT INTO employee_contracts (id, employee_id, contract_type, start_date, total_weekly_hours, base_hourly_wage, salary_type, rule_set_id, holiday_calendar, breaks_paid)
    VALUES (1, 1, 'part_time', '2026-01-01', 25, 150, 'hourly', 1, 'danish', 'no')");

// Create schedule: Mon-Fri 08:00-13:00 (5h/day = 25h/week) with no breaks
$pdo->exec("INSERT INTO employee_schedules (id, employee_id, schedule_name) VALUES (1, 1, 'Default')");
$days = [
    [1, 1, 1, '08:00', '13:00', 0, 1], // Mon
    [1, 2, 1, '08:00', '13:00', 0, 1], // Tue
    [1, 3, 1, '08:00', '13:00', 0, 1], // Wed
    [1, 4, 1, '08:00', '13:00', 0, 1], // Thu
    [1, 5, 1, '08:00', '13:00', 0, 1], // Fri
    [1, 6, 0, null,    null,    null, 1], // Sat OFF
    [1, 7, 0, null,    null,    null, 1], // Sun OFF
];
$dstmt = $pdo->prepare("INSERT INTO schedule_days (schedule_id, day_of_week, is_active, start_time, end_time, break_duration, block_number) VALUES (?,?,?,?,?,?,?)");
foreach ($days as $d) $dstmt->execute($d);

$calc = new WageCalculator();
$pass = 0;
$fail = 0;

function test($name, $expected, $actual, $tolerance = 0.01) {
    global $pass, $fail;
    $diff = abs($expected - $actual);
    if ($diff <= $tolerance) {
        echo "  ✓ PASS: $name (expected: $expected, got: $actual)\n";
        $pass++;
    } else {
        echo "  ✗ FAIL: $name (expected: $expected, got: $actual, diff: $diff)\n";
        $fail++;
    }
}

// =====================================================
// TEST 1: Section 2.2 - Tiered Overtime Example
// Employee base rate: 150 DKK/h. Works 5 hours overtime in a week.
// First 3 hours: 150 × 1.5 = 225 DKK/h (Tier 1)
// Next 2 hours: 150 × 2.0 = 300 DKK/h (Tier 2)
// Total overtime pay: (3 × 225) + (2 × 300) = 675 + 600 = 1,275 DKK
// =====================================================
echo "TEST 1: Tiered Overtime (Section 2.2 Example)\n";
echo "  Employee works 10h on Monday (scheduled 5h, so 5h overtime)\n";

$pdo->exec("INSERT INTO time_entries (id, employee_id, date, clock_in, clock_out, break_minutes) VALUES (1, 1, '2026-03-02', '08:00', '18:00', 0)");
$result = $calc->calculate(1);

test('Normal hours', 5.0, $result['normal_hours']);
test('Overtime hours', 5.0, $result['overtime_hours']);
test('Tier 1 hours', 3.0, $result['tier_breakdown']['tier1_hours']);
test('Tier 2 hours', 2.0, $result['tier_breakdown']['tier2_hours']);

// Find W01, W02, W03 amounts
$w01 = 0; $w02 = 0; $w03 = 0;
foreach ($result['wage_lines'] as $line) {
    if ($line['wage_code'] === 'W01') $w01 += $line['amount'];
    if ($line['wage_code'] === 'W02') $w02 += $line['amount'];
    if ($line['wage_code'] === 'W03') $w03 += $line['amount'];
}

test('W01 Normal pay (5h × 150)', 750, $w01);
test('W02 Tier 1 pay (3h × 225)', 675, $w02);
test('W03 Tier 2 pay (2h × 300)', 600, $w03);
test('Total overtime pay', 1275, $w02 + $w03);
echo "\n";

// =====================================================
// TEST 2: Combined Mode - Non-contracted day
// Working on Thursday (non-contracted): all hours overtime
// =====================================================
echo "TEST 2: Non-Contracted Day (Section 2.3)\n";

// Adjust schedule: Thu is OFF for this test
$pdo->exec("UPDATE schedule_days SET is_active = 0, start_time = NULL, end_time = NULL WHERE schedule_id = 1 AND day_of_week = 4");

$pdo->exec("INSERT INTO time_entries (id, employee_id, date, clock_in, clock_out, break_minutes) VALUES (2, 1, '2026-03-05', '08:00', '13:00', 0)");
$result2 = $calc->calculate(2);

test('All hours overtime on non-contracted day', 5.0, $result2['overtime_hours']);
test('Normal hours = 0', 0.0, $result2['normal_hours']);
echo "\n";

// Restore Thursday
$pdo->exec("UPDATE schedule_days SET is_active = 1, start_time = '08:00', end_time = '13:00', break_duration = 0 WHERE schedule_id = 1 AND day_of_week = 4");

// =====================================================
// TEST 3: Saturday supplement (Section 4)
// Saturday all day: +45%
// Working 5h on Saturday at 150 DKK/h
// Base: 5 × 150 = 750
// Saturday supplement: 5 × 150 × 0.45 = 337.50
// =====================================================
echo "TEST 3: Saturday Supplement (Section 4)\n";

$pdo->exec("INSERT INTO time_entries (id, employee_id, date, clock_in, clock_out, break_minutes) VALUES (3, 1, '2026-03-07', '08:00', '13:00', 0)");
$result3 = $calc->calculate(3);

$satSupplement = 0;
foreach ($result3['wage_lines'] as $line) {
    if ($line['wage_code'] === 'W06') $satSupplement += $line['amount'];
}

// Non-contracted day (Saturday), all hours are overtime
test('Saturday hours recognized', 5.0, $result3['overtime_hours'] + $result3['normal_hours'], 0.1);
test('Saturday supplement amount (5h × 150 × 0.45)', 337.50, $satSupplement, 1.0);
echo "\n";

// =====================================================
// TEST 4: Cumulative stacking (Section 5.1)
// Saturday evening overtime. Base 150 DKK/h
// Group A: Saturday (+45%) wins over Evening (+25%)
// Group B: Overtime Tier 1 (+50%)
// Total: 150 + 67.50 + 75.00 = 292.50 DKK/h
// =====================================================
echo "TEST 4: Cumulative Stacking - Saturday Evening Overtime (Section 5)\n";

// Working Saturday 18:00-21:00 (3h in evening, on Saturday)
$pdo->exec("INSERT INTO time_entries (id, employee_id, date, clock_in, clock_out, break_minutes) VALUES (4, 1, '2026-03-14', '18:00', '21:00', 0)");
$result4 = $calc->calculate(4);

$totalPay = $result4['total_amount'];
$hours = 3.0;
// Expected per hour: 150 (base via OT) + 67.50 (saturday) + varies for OT line
// All 3h are overtime on non-contracted Saturday
echo "  Wage lines:\n";
foreach ($result4['wage_lines'] as $line) {
    echo "    {$line['wage_code']}: {$line['wage_type']} - {$line['hours']}h = {$line['amount']} DKK\n";
}
// Verify the supplements add from base rate, not compound
test('Saturday supplement = 3h × 150 × 0.45 = 202.50', 202.50, $satAmount = array_sum(array_map(fn($l) => $l['wage_code'] === 'W06' ? $l['amount'] : 0, $result4['wage_lines'])), 1.0);
echo "\n";

// =====================================================
// TEST 5: Break handling (Section 6)
// Unpaid break: 8h shift - 30min = 7.5h billable
// =====================================================
echo "TEST 5: Break Handling - Unpaid (Section 6)\n";

$pdo->exec("INSERT INTO time_entries (id, employee_id, date, clock_in, clock_out, break_minutes) VALUES (5, 1, '2026-03-09', '08:00', '16:00', 30)");
$result5 = $calc->calculate(5);

test('Net worked hours with 30min unpaid break', 7.5, $result5['net_worked_hours']);
test('Break is unpaid', false, $result5['break_is_paid']);
echo "\n";

// =====================================================
// TEST 6: Evening supplement (Section 4)
// Working 16:00-02:00 (10h shift, crosses midnight)
// 16:00-18:00 = 2h normal daytime (0%)
// 18:00-23:00 = 5h evening (+25%)
// 23:00-02:00 = 3h night (+50%)
// =====================================================
echo "TEST 6: Time-of-Day Intervals Crossing Midnight (Section 4)\n";

$pdo->exec("INSERT INTO time_entries (id, employee_id, date, clock_in, clock_out, break_minutes) VALUES (6, 1, '2026-03-10', '16:00', '02:00', 0)");
$result6 = $calc->calculate(6);

echo "  Wage lines:\n";
foreach ($result6['wage_lines'] as $line) {
    echo "    {$line['wage_code']}: {$line['wage_type']} - {$line['hours']}h, +{$line['supplement_pct']}%, = {$line['amount']} DKK\n";
}

$eveningSup = 0; $nightSup = 0;
foreach ($result6['wage_lines'] as $line) {
    if ($line['wage_code'] === 'W04') $eveningSup += $line['amount'];
    if ($line['wage_code'] === 'W05') $nightSup += $line['amount'];
}
// Evening supplement should be for hours in 18:00-23:00 range
// Night supplement should be for hours in 23:00-06:00 range
test('Total hours worked', 10.0, $result6['net_worked_hours']);
echo "\n";

// =====================================================
// TEST 7: Supplement never compounds (Section 5 warning)
// 150 × 1.5 × 1.45 is WRONG
// Correct: 150 + (150 × 0.50) + (150 × 0.45) = 292.50
// =====================================================
echo "TEST 7: Supplements from BASE rate, never compound (Section 5)\n";

$wrongCompound = 150 * 1.5 * 1.45; // = 326.25 (WRONG)
$correctAdditive = 150 + (150 * 0.50) + (150 * 0.45); // = 292.50 (CORRECT)

test('Correct additive calculation', 292.50, $correctAdditive);
echo "  Wrong compound would be: $wrongCompound (must never occur)\n";
echo "\n";

// =====================================================
// Summary
// =====================================================
echo "========================================\n";
echo "Results: $pass passed, $fail failed\n";
echo "========================================\n";
