<?php
/**
 * Main API Router for HR Payroll System
 * Handles all REST endpoints with CORS support
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../lib/WageCalculator.php';

$db = Database::getInstance();
$db->initSchema();
$pdo = $db->getPdo();

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
// Strip base path
$path = preg_replace('#^.*/api/#', '/', $path);
$segments = array_values(array_filter(explode('/', $path)));

$body = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    $result = routeRequest($pdo, $method, $segments, $body);
    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

function routeRequest(PDO $pdo, string $method, array $seg, array $body) {
    $resource = $seg[0] ?? '';
    $id = $seg[1] ?? null;
    $subResource = $seg[2] ?? null;
    $subId = $seg[3] ?? null;

    switch ($resource) {
        case 'rule-sets':
            return handleRuleSets($pdo, $method, $id, $subResource, $subId, $body);
        case 'employees':
            return handleEmployees($pdo, $method, $id, $subResource, $subId, $body);
        case 'contracts':
            return handleContracts($pdo, $method, $id, $body);
        case 'contract-changes':
            return handleContractChanges($pdo, $method, $id, $body);
        case 'schedules':
            return handleSchedules($pdo, $method, $id, $subResource, $body);
        case 'time-entries':
            return handleTimeEntries($pdo, $method, $id, $body);
        case 'calculate':
            return handleCalculate($pdo, $method, $id, $seg, $body);
        case 'wage-lines':
            return handleWageLines($pdo, $method, $id, $body);
        case 'holidays':
            return handleHolidays($pdo, $method, $id, $body);
        case 'warnings':
            return handleWarnings($pdo, $method, $id, $body);
        case 'dashboard':
            return handleDashboard($pdo, $method, $body);
        default:
            return ['status' => 'ok', 'message' => 'HR Payroll API v1.0'];
    }
}

// ===== WAGE RULE SETS =====
function handleRuleSets(PDO $pdo, string $method, ?string $id, ?string $sub, ?string $subId, array $body) {
    if ($sub === 'intervals') {
        return handleIntervals($pdo, $method, $id, $subId, $body);
    }

    switch ($method) {
        case 'GET':
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM wage_rule_sets WHERE id = ?");
                $stmt->execute([$id]);
                $rs = $stmt->fetch();
                if (!$rs) throw new Exception("Rule set not found");
                // Count employees
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM employee_contracts WHERE rule_set_id = ? AND is_active = 1");
                $countStmt->execute([$id]);
                $rs['employee_count'] = (int)$countStmt->fetchColumn();
                // Load intervals
                $intStmt = $pdo->prepare("SELECT * FROM supplement_intervals WHERE rule_set_id = ? ORDER BY priority");
                $intStmt->execute([$id]);
                $rs['intervals'] = $intStmt->fetchAll();
                return $rs;
            }
            $rows = $pdo->query("SELECT w.*, (SELECT COUNT(*) FROM employee_contracts c WHERE c.rule_set_id = w.id AND c.is_active = 1) as employee_count FROM wage_rule_sets w ORDER BY w.name")->fetchAll();
            return $rows;

        case 'POST':
            $stmt = $pdo->prepare("
                INSERT INTO wage_rule_sets (name, description, overtime_model, overtime_trigger_mode, balancing_mode, balancing_period_weeks, stacking_mode, holiday_calendar, breaks_paid, default_break_duration, tier1_threshold, tier1_rate, tier2_rate, flat_overtime_rate)
                VALUES (:name, :description, :overtime_model, :overtime_trigger_mode, :balancing_mode, :balancing_period_weeks, :stacking_mode, :holiday_calendar, :breaks_paid, :default_break_duration, :tier1_threshold, :tier1_rate, :tier2_rate, :flat_overtime_rate)
            ");
            $stmt->execute([
                'name' => $body['name'] ?? 'New Rule Set',
                'description' => $body['description'] ?? '',
                'overtime_model' => $body['overtime_model'] ?? 'tiered',
                'overtime_trigger_mode' => $body['overtime_trigger_mode'] ?? 'combined',
                'balancing_mode' => $body['balancing_mode'] ?? 'none',
                'balancing_period_weeks' => $body['balancing_period_weeks'] ?? null,
                'stacking_mode' => $body['stacking_mode'] ?? 'cumulative',
                'holiday_calendar' => $body['holiday_calendar'] ?? 'danish',
                'breaks_paid' => $body['breaks_paid'] ?? 0,
                'default_break_duration' => $body['default_break_duration'] ?? 30,
                'tier1_threshold' => $body['tier1_threshold'] ?? 3.0,
                'tier1_rate' => $body['tier1_rate'] ?? 50.0,
                'tier2_rate' => $body['tier2_rate'] ?? 100.0,
                'flat_overtime_rate' => $body['flat_overtime_rate'] ?? 50.0,
            ]);
            return ['id' => $pdo->lastInsertId(), 'message' => 'Created'];

        case 'PUT':
            if (!$id) throw new Exception("ID required");
            $fields = ['name','description','overtime_model','overtime_trigger_mode','balancing_mode','balancing_period_weeks','stacking_mode','holiday_calendar','breaks_paid','default_break_duration','tier1_threshold','tier1_rate','tier2_rate','flat_overtime_rate'];
            $sets = [];
            $params = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $body)) {
                    $sets[] = "$f = :$f";
                    $params[$f] = $body[$f];
                }
            }
            if (empty($sets)) throw new Exception("No fields to update");
            $sets[] = "updated_at = datetime('now')";
            $params['id'] = $id;
            $pdo->prepare("UPDATE wage_rule_sets SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
            return ['message' => 'Updated'];

        case 'DELETE':
            if (!$id) throw new Exception("ID required");
            $pdo->prepare("DELETE FROM wage_rule_sets WHERE id = ?")->execute([$id]);
            return ['message' => 'Deleted'];
    }
}

// ===== SUPPLEMENT INTERVALS =====
function handleIntervals(PDO $pdo, string $method, string $ruleSetId, ?string $id, array $body) {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->prepare("SELECT * FROM supplement_intervals WHERE rule_set_id = ? ORDER BY priority");
            $stmt->execute([$ruleSetId]);
            return $stmt->fetchAll();

        case 'POST':
            $stmt = $pdo->prepare("
                INSERT INTO supplement_intervals (rule_set_id, name, start_time, end_time, applies_to_days, applies_to_holidays, rate_type, rate_value, stacking_group, priority, wage_code)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $ruleSetId,
                $body['name'] ?? 'New Interval',
                $body['start_time'] ?? '00:00',
                $body['end_time'] ?? '24:00',
                $body['applies_to_days'] ?? '1,2,3,4,5',
                $body['applies_to_holidays'] ?? 0,
                $body['rate_type'] ?? 'percentage',
                $body['rate_value'] ?? 0,
                $body['stacking_group'] ?? 'A',
                $body['priority'] ?? 1,
                $body['wage_code'] ?? 'W04',
            ]);
            return ['id' => $pdo->lastInsertId(), 'message' => 'Created'];

        case 'PUT':
            if (!$id) throw new Exception("Interval ID required");
            $fields = ['name','start_time','end_time','applies_to_days','applies_to_holidays','rate_type','rate_value','stacking_group','priority','wage_code'];
            $sets = [];
            $params = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $body)) {
                    $sets[] = "$f = :$f";
                    $params[$f] = $body[$f];
                }
            }
            if (empty($sets)) throw new Exception("No fields");
            $params['id'] = $id;
            $pdo->prepare("UPDATE supplement_intervals SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
            return ['message' => 'Updated'];

        case 'DELETE':
            if (!$id) throw new Exception("Interval ID required");
            $pdo->prepare("DELETE FROM supplement_intervals WHERE id = ?")->execute([$id]);
            return ['message' => 'Deleted'];
    }
}

// ===== EMPLOYEES =====
function handleEmployees(PDO $pdo, string $method, ?string $id, ?string $sub, ?string $subId, array $body) {
    switch ($method) {
        case 'GET':
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
                $stmt->execute([$id]);
                $emp = $stmt->fetch();
                if (!$emp) throw new Exception("Employee not found");
                // Load active contract
                $cStmt = $pdo->prepare("SELECT c.*, w.name as rule_set_name FROM employee_contracts c LEFT JOIN wage_rule_sets w ON w.id = c.rule_set_id WHERE c.employee_id = ? AND c.is_active = 1 ORDER BY c.start_date DESC LIMIT 1");
                $cStmt->execute([$id]);
                $emp['contract'] = $cStmt->fetch() ?: null;
                // Load schedules
                $sStmt = $pdo->prepare("SELECT * FROM employee_schedules WHERE employee_id = ? AND is_active = 1 ORDER BY rotation_order");
                $sStmt->execute([$id]);
                $schedules = $sStmt->fetchAll();
                foreach ($schedules as &$s) {
                    $dStmt = $pdo->prepare("SELECT * FROM schedule_days WHERE schedule_id = ? ORDER BY day_of_week, block_number");
                    $dStmt->execute([$s['id']]);
                    $s['days'] = $dStmt->fetchAll();
                }
                $emp['schedules'] = $schedules;
                return $emp;
            }
            return $pdo->query("
                SELECT e.*, c.contract_type, c.total_weekly_hours, c.base_hourly_wage, w.name as rule_set_name
                FROM employees e
                LEFT JOIN employee_contracts c ON c.employee_id = e.id AND c.is_active = 1
                LEFT JOIN wage_rule_sets w ON w.id = c.rule_set_id
                ORDER BY e.last_name, e.first_name
            ")->fetchAll();

        case 'POST':
            $stmt = $pdo->prepare("INSERT INTO employees (first_name, last_name, email, employee_number) VALUES (?, ?, ?, ?)");
            $stmt->execute([$body['first_name'] ?? '', $body['last_name'] ?? '', $body['email'] ?? '', $body['employee_number'] ?? null]);
            return ['id' => $pdo->lastInsertId(), 'message' => 'Created'];

        case 'PUT':
            if (!$id) throw new Exception("ID required");
            $fields = ['first_name','last_name','email','employee_number'];
            $sets = [];
            $params = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $body)) {
                    $sets[] = "$f = :$f";
                    $params[$f] = $body[$f];
                }
            }
            $params['id'] = $id;
            $pdo->prepare("UPDATE employees SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
            return ['message' => 'Updated'];

        case 'DELETE':
            if (!$id) throw new Exception("ID required");
            $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
            return ['message' => 'Deleted'];
    }
}

// ===== CONTRACTS =====
function handleContracts(PDO $pdo, string $method, ?string $id, array $body) {
    switch ($method) {
        case 'GET':
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM employee_contracts WHERE id = ?");
                $stmt->execute([$id]);
                return $stmt->fetch() ?: throw new Exception("Contract not found");
            }
            $empId = $_GET['employee_id'] ?? null;
            if ($empId) {
                $stmt = $pdo->prepare("SELECT * FROM employee_contracts WHERE employee_id = ? ORDER BY start_date DESC");
                $stmt->execute([$empId]);
                return $stmt->fetchAll();
            }
            return $pdo->query("SELECT * FROM employee_contracts ORDER BY start_date DESC")->fetchAll();

        case 'POST':
            $stmt = $pdo->prepare("
                INSERT INTO employee_contracts (employee_id, contract_type, start_date, end_date, total_weekly_hours, base_hourly_wage, monthly_salary, salary_type, rule_set_id, holiday_calendar, collective_agreement, breaks_paid, default_break_duration)
                VALUES (:employee_id, :contract_type, :start_date, :end_date, :total_weekly_hours, :base_hourly_wage, :monthly_salary, :salary_type, :rule_set_id, :holiday_calendar, :collective_agreement, :breaks_paid, :default_break_duration)
            ");
            $stmt->execute([
                'employee_id' => $body['employee_id'],
                'contract_type' => $body['contract_type'] ?? 'full_time',
                'start_date' => $body['start_date'],
                'end_date' => $body['end_date'] ?? null,
                'total_weekly_hours' => $body['total_weekly_hours'] ?? 37,
                'base_hourly_wage' => $body['base_hourly_wage'],
                'monthly_salary' => $body['monthly_salary'] ?? null,
                'salary_type' => $body['salary_type'] ?? 'hourly',
                'rule_set_id' => $body['rule_set_id'],
                'holiday_calendar' => $body['holiday_calendar'] ?? 'danish',
                'collective_agreement' => $body['collective_agreement'] ?? null,
                'breaks_paid' => $body['breaks_paid'] ?? 'use_rule_set',
                'default_break_duration' => $body['default_break_duration'] ?? null,
            ]);
            return ['id' => $pdo->lastInsertId(), 'message' => 'Created'];

        case 'PUT':
            if (!$id) throw new Exception("ID required");
            // Log contract changes for audit (Section 9)
            $stmt = $pdo->prepare("SELECT * FROM employee_contracts WHERE id = ?");
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old) throw new Exception("Contract not found");

            $trackFields = ['total_weekly_hours','base_hourly_wage','contract_type','breaks_paid'];
            foreach ($trackFields as $f) {
                if (array_key_exists($f, $body) && $body[$f] != $old[$f]) {
                    $pdo->prepare("INSERT INTO contract_changes (contract_id, employee_id, field_name, old_value, new_value, effective_date, changed_by) VALUES (?, ?, ?, ?, ?, ?, 'admin')")
                        ->execute([$id, $old['employee_id'], $f, $old[$f], $body[$f], $body['effective_date'] ?? date('Y-m-d')]);
                }
            }

            $fields = ['contract_type','start_date','end_date','total_weekly_hours','base_hourly_wage','monthly_salary','salary_type','rule_set_id','holiday_calendar','collective_agreement','breaks_paid','default_break_duration','is_active'];
            $sets = [];
            $params = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $body)) {
                    $sets[] = "$f = :$f";
                    $params[$f] = $body[$f];
                }
            }
            $sets[] = "updated_at = datetime('now')";
            $params['id'] = $id;
            $pdo->prepare("UPDATE employee_contracts SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
            return ['message' => 'Updated'];

        case 'DELETE':
            if (!$id) throw new Exception("ID required");
            $pdo->prepare("DELETE FROM employee_contracts WHERE id = ?")->execute([$id]);
            return ['message' => 'Deleted'];
    }
}

// ===== CONTRACT CHANGES (Audit Trail - Section 9) =====
function handleContractChanges(PDO $pdo, string $method, ?string $id, array $body) {
    if ($method !== 'GET') throw new Exception("Only GET supported");
    $empId = $_GET['employee_id'] ?? null;
    $sql = "SELECT * FROM contract_changes";
    $params = [];
    if ($empId) {
        $sql .= " WHERE employee_id = ?";
        $params[] = $empId;
    }
    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ===== SCHEDULES =====
function handleSchedules(PDO $pdo, string $method, ?string $id, ?string $sub, array $body) {
    switch ($method) {
        case 'GET':
            $empId = $_GET['employee_id'] ?? null;
            if ($empId) {
                $stmt = $pdo->prepare("SELECT * FROM employee_schedules WHERE employee_id = ? ORDER BY rotation_order");
                $stmt->execute([$empId]);
                $schedules = $stmt->fetchAll();
                foreach ($schedules as &$s) {
                    $dStmt = $pdo->prepare("SELECT * FROM schedule_days WHERE schedule_id = ? ORDER BY day_of_week, block_number");
                    $dStmt->execute([$s['id']]);
                    $s['days'] = $dStmt->fetchAll();
                }
                return $schedules;
            }
            return [];

        case 'POST':
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO employee_schedules (employee_id, schedule_name, rotation_order, rotation_start_date) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $body['employee_id'],
                $body['schedule_name'] ?? 'Default',
                $body['rotation_order'] ?? 1,
                $body['rotation_start_date'] ?? null,
            ]);
            $scheduleId = $pdo->lastInsertId();

            // Insert days
            if (!empty($body['days'])) {
                $dayStmt = $pdo->prepare("INSERT INTO schedule_days (schedule_id, day_of_week, is_active, start_time, end_time, break_duration, block_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($body['days'] as $day) {
                    $dayStmt->execute([
                        $scheduleId,
                        $day['day_of_week'],
                        $day['is_active'] ?? 0,
                        $day['start_time'] ?? null,
                        $day['end_time'] ?? null,
                        $day['break_duration'] ?? null,
                        $day['block_number'] ?? 1,
                    ]);
                }
            }
            $pdo->commit();
            return ['id' => $scheduleId, 'message' => 'Created'];

        case 'PUT':
            if (!$id) throw new Exception("ID required");
            $pdo->beginTransaction();

            if (isset($body['schedule_name'])) {
                $pdo->prepare("UPDATE employee_schedules SET schedule_name = ?, rotation_order = ?, rotation_start_date = ? WHERE id = ?")
                    ->execute([$body['schedule_name'], $body['rotation_order'] ?? 1, $body['rotation_start_date'] ?? null, $id]);
            }

            if (!empty($body['days'])) {
                $pdo->prepare("DELETE FROM schedule_days WHERE schedule_id = ?")->execute([$id]);
                $dayStmt = $pdo->prepare("INSERT INTO schedule_days (schedule_id, day_of_week, is_active, start_time, end_time, break_duration, block_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($body['days'] as $day) {
                    $dayStmt->execute([
                        $id,
                        $day['day_of_week'],
                        $day['is_active'] ?? 0,
                        $day['start_time'] ?? null,
                        $day['end_time'] ?? null,
                        $day['break_duration'] ?? null,
                        $day['block_number'] ?? 1,
                    ]);
                }
            }
            $pdo->commit();
            return ['message' => 'Updated'];

        case 'DELETE':
            if (!$id) throw new Exception("ID required");
            $pdo->prepare("DELETE FROM employee_schedules WHERE id = ?")->execute([$id]);
            return ['message' => 'Deleted'];
    }
}

// ===== TIME ENTRIES =====
function handleTimeEntries(PDO $pdo, string $method, ?string $id, array $body) {
    switch ($method) {
        case 'GET':
            if ($id) {
                $stmt = $pdo->prepare("SELECT t.*, e.first_name, e.last_name FROM time_entries t JOIN employees e ON e.id = t.employee_id WHERE t.id = ?");
                $stmt->execute([$id]);
                $entry = $stmt->fetch();
                if (!$entry) throw new Exception("Time entry not found");
                // Load wage lines
                $wStmt = $pdo->prepare("SELECT * FROM wage_lines WHERE time_entry_id = ? ORDER BY wage_code");
                $wStmt->execute([$id]);
                $entry['wage_lines'] = $wStmt->fetchAll();
                return $entry;
            }
            $empId = $_GET['employee_id'] ?? null;
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $_GET['date_to'] ?? date('Y-m-d');
            $sql = "SELECT t.*, e.first_name, e.last_name FROM time_entries t JOIN employees e ON e.id = t.employee_id WHERE t.date >= ? AND t.date <= ?";
            $params = [$dateFrom, $dateTo];
            if ($empId) {
                $sql .= " AND t.employee_id = ?";
                $params[] = $empId;
            }
            $sql .= " ORDER BY t.date DESC, t.clock_in DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();

        case 'POST':
            $stmt = $pdo->prepare("INSERT INTO time_entries (employee_id, date, clock_in, clock_out, break_minutes, is_employer_cancelled, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $body['employee_id'],
                $body['date'],
                $body['clock_in'],
                $body['clock_out'],
                $body['break_minutes'] ?? null,
                $body['is_employer_cancelled'] ?? 0,
                $body['notes'] ?? '',
            ]);
            $entryId = $pdo->lastInsertId();

            // Auto-calculate
            $calc = new WageCalculator();
            $result = $calc->calculate($entryId);

            return ['id' => $entryId, 'calculation' => $result];

        case 'PUT':
            if (!$id) throw new Exception("ID required");
            $fields = ['employee_id','date','clock_in','clock_out','break_minutes','is_employer_cancelled','notes'];
            $sets = [];
            $params = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $body)) {
                    $sets[] = "$f = :$f";
                    $params[$f] = $body[$f];
                }
            }
            $params['id'] = $id;
            $pdo->prepare("UPDATE time_entries SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
            // Recalculate
            $calc = new WageCalculator();
            $result = $calc->calculate((int)$id);
            return ['message' => 'Updated', 'calculation' => $result];

        case 'DELETE':
            if (!$id) throw new Exception("ID required");
            $pdo->prepare("DELETE FROM wage_lines WHERE time_entry_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM time_entries WHERE id = ?")->execute([$id]);
            return ['message' => 'Deleted'];
    }
}

// ===== CALCULATE =====
function handleCalculate(PDO $pdo, string $method, ?string $id, array $seg, array $body) {
    $calc = new WageCalculator();

    if ($method === 'POST') {
        if ($id === 'entry') {
            $entryId = $seg[2] ?? $body['time_entry_id'] ?? null;
            if (!$entryId) throw new Exception("time_entry_id required");
            return $calc->calculate((int)$entryId);
        }
        if ($id === 'week') {
            $empId = $body['employee_id'] ?? throw new Exception("employee_id required");
            $weekStart = $body['week_start'] ?? throw new Exception("week_start required");
            return $calc->calculateWeek((int)$empId, $weekStart);
        }
        if ($id === 'simulate') {
            // Simulation: create temp entry, calculate, return result without saving
            $stmt = $pdo->prepare("INSERT INTO time_entries (employee_id, date, clock_in, clock_out, break_minutes, is_employer_cancelled) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $body['employee_id'],
                $body['date'],
                $body['clock_in'],
                $body['clock_out'],
                $body['break_minutes'] ?? null,
                $body['is_employer_cancelled'] ?? 0,
            ]);
            $entryId = $pdo->lastInsertId();
            $result = $calc->calculate((int)$entryId);
            // Clean up: remove temp entry, wage lines, and any warnings created during simulation
            $pdo->prepare("DELETE FROM wage_lines WHERE time_entry_id = ?")->execute([$entryId]);
            $pdo->prepare("DELETE FROM warnings WHERE employee_id = ? AND date = ? AND created_at >= datetime('now', '-10 seconds')")->execute([$body['employee_id'], $body['date']]);
            $pdo->prepare("DELETE FROM time_entries WHERE id = ?")->execute([$entryId]);
            return $result;
        }
    }
    throw new Exception("Invalid calculate endpoint");
}

// ===== WAGE LINES =====
function handleWageLines(PDO $pdo, string $method, ?string $id, array $body) {
    if ($method !== 'GET') throw new Exception("Only GET supported");

    $empId = $_GET['employee_id'] ?? null;
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');

    $sql = "SELECT wl.*, e.first_name, e.last_name FROM wage_lines wl JOIN employees e ON e.id = wl.employee_id WHERE wl.date >= ? AND wl.date <= ?";
    $params = [$dateFrom, $dateTo];
    if ($empId) {
        $sql .= " AND wl.employee_id = ?";
        $params[] = $empId;
    }
    $sql .= " ORDER BY wl.date, wl.wage_code";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ===== HOLIDAYS =====
function handleHolidays(PDO $pdo, string $method, ?string $id, array $body) {
    switch ($method) {
        case 'GET':
            $cal = $_GET['calendar'] ?? 'danish';
            $stmt = $pdo->prepare("SELECT * FROM holidays WHERE calendar_name = ? ORDER BY date");
            $stmt->execute([$cal]);
            return $stmt->fetchAll();
        case 'POST':
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO holidays (calendar_name, date, name) VALUES (?, ?, ?)");
            $stmt->execute([$body['calendar_name'] ?? 'danish', $body['date'], $body['name']]);
            return ['id' => $pdo->lastInsertId(), 'message' => 'Created'];
        case 'DELETE':
            if (!$id) throw new Exception("ID required");
            $pdo->prepare("DELETE FROM holidays WHERE id = ?")->execute([$id]);
            return ['message' => 'Deleted'];
    }
}

// ===== WARNINGS =====
function handleWarnings(PDO $pdo, string $method, ?string $id, array $body) {
    if ($method === 'GET') {
        $empId = $_GET['employee_id'] ?? null;
        $sql = "SELECT w.*, e.first_name, e.last_name FROM warnings w JOIN employees e ON e.id = w.employee_id WHERE w.is_resolved = 0";
        $params = [];
        if ($empId) {
            $sql .= " AND w.employee_id = ?";
            $params[] = $empId;
        }
        $sql .= " ORDER BY w.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    if ($method === 'PUT' && $id) {
        $pdo->prepare("UPDATE warnings SET is_resolved = 1 WHERE id = ?")->execute([$id]);
        return ['message' => 'Resolved'];
    }
    return [];
}

// ===== DASHBOARD =====
function handleDashboard(PDO $pdo, string $method, array $body) {
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('monday this week'));
    $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('sunday this week'));

    $empCount = $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
    $ruleSetCount = $pdo->query("SELECT COUNT(*) FROM wage_rule_sets")->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM time_entries WHERE date >= ? AND date <= ?");
    $stmt->execute([$dateFrom, $dateTo]);
    $entryCount = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM wage_lines WHERE date >= ? AND date <= ?");
    $stmt->execute([$dateFrom, $dateTo]);
    $totalWages = round((float)$stmt->fetchColumn(), 2);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM warnings WHERE is_resolved = 0");
    $unresolvedWarnings = $stmt->fetchColumn();

    // Per wage code summary
    $stmt = $pdo->prepare("
        SELECT wage_code, wage_type, SUM(hours) as total_hours, SUM(amount) as total_amount
        FROM wage_lines WHERE date >= ? AND date <= ?
        GROUP BY wage_code ORDER BY wage_code
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $wageCodeSummary = $stmt->fetchAll();

    return [
        'employee_count' => (int)$empCount,
        'rule_set_count' => (int)$ruleSetCount,
        'period' => ['from' => $dateFrom, 'to' => $dateTo],
        'time_entry_count' => (int)$entryCount,
        'total_wages' => $totalWages,
        'unresolved_warnings' => (int)$unresolvedWarnings,
        'wage_code_summary' => $wageCodeSummary,
    ];
}
