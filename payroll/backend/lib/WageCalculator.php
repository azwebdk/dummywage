<?php
/**
 * Wage Calculation Engine
 * Implements the full 11-step calculation flow from the Fogito Wage Module Configuration Guide.
 *
 * All supplement percentages are applied to the BASE rate, never compounded.
 * e.g., base 150, overtime 50% + saturday 45% = 150 + 75 + 67.50 = 292.50
 */

require_once __DIR__ . '/../database.php';

class WageCalculator {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getPdo();
    }

    /**
     * Main entry: calculate wages for a time entry
     */
    public function calculate(int $timeEntryId): array {
        $entry = $this->getTimeEntry($timeEntryId);
        if (!$entry) throw new Exception("Time entry not found");

        $employee = $this->getEmployee($entry['employee_id']);
        $contract = $this->getActiveContract($entry['employee_id'], $entry['date']);
        if (!$contract) throw new Exception("No active contract for employee on date {$entry['date']}");

        $ruleSet = $this->getRuleSet($contract['rule_set_id']);
        $schedule = $this->getScheduleForDate($entry['employee_id'], $entry['date']);
        $intervals = $this->getSupplementIntervals($contract['rule_set_id']);

        // Delete old wage lines for this entry
        $this->pdo->prepare("DELETE FROM wage_lines WHERE time_entry_id = ?")->execute([$timeEntryId]);
        // Delete old warnings for this entry date
        $this->pdo->prepare("DELETE FROM warnings WHERE employee_id = ? AND date = ?")->execute([$entry['employee_id'], $entry['date']]);

        // === STEP 1: Identify date and day type ===
        $dayType = $this->classifyDay($entry['date'], $contract, $schedule, $ruleSet['holiday_calendar']);

        // === STEP 2: Load applicable schedule (with mid-week contract handling) ===
        $scheduledHours = $this->getScheduledHoursForDay($schedule, $entry['date']);

        // === STEP 3: Handle breaks ===
        $breakInfo = $this->resolveBreaks($entry, $contract, $ruleSet, $schedule);
        $workedMinutes = $this->calcWorkedMinutes($entry['clock_in'], $entry['clock_out']);
        $netWorkedMinutes = $workedMinutes;
        $paidBreakMinutes = 0;

        // EU Working Time Directive: auto-deduct break if none registered and 6+ hours worked
        if ($workedMinutes >= 360 && ($entry['break_minutes'] === null || $entry['break_minutes'] == 0)) {
            $this->addWarning($entry['employee_id'], $entry['date'], 'no_break',
                'Employee worked 6+ hours without a registered break. Auto-deducting default break duration.');
            // Force default break duration when no break was registered
            $breakInfo['duration'] = $ruleSet['default_break_duration'];
            $breakInfo['paid_duration'] = $breakInfo['is_paid'] ? $ruleSet['default_break_duration'] : 0;
        }

        if ($breakInfo['is_paid']) {
            // Paid break: use capped paid_duration (Section 6.2)
            $paidBreakMinutes = $breakInfo['paid_duration'];
            // Deduct unpaid excess if break exceeds default (e.g., 45min break, 30min default = 15min unpaid)
            $unpaidExcess = $breakInfo['duration'] - $breakInfo['paid_duration'];
            if ($unpaidExcess > 0) {
                $netWorkedMinutes = max(0, $workedMinutes - $unpaidExcess);
            }
        } else {
            // Unpaid break: deducted from hours
            $netWorkedMinutes = max(0, $workedMinutes - $breakInfo['duration']);
        }

        $netWorkedHours = round($netWorkedMinutes / 60, 4);

        // === STEP 4: Split into time-of-day intervals ===
        $timeSlices = $this->splitIntoIntervals($entry['clock_in'], $entry['clock_out'], $intervals, $dayType);

        // === STEP 5: Determine normal vs overtime ===
        $overtimeInfo = $this->determineOvertime($entry, $contract, $ruleSet, $schedule, $netWorkedHours);
        $normalHours = $overtimeInfo['normal_hours'];
        $overtimeHours = $overtimeInfo['overtime_hours'];

        // === STEP 6: Apply overtime tiers ===
        $tierBreakdown = $this->applyOvertimeTiers($overtimeHours, $ruleSet, $entry['employee_id'], $entry['date']);

        // === STEPS 7-9: Apply supplements with stacking ===
        $wageLines = $this->buildWageLines(
            $entry, $contract, $ruleSet, $timeSlices, $normalHours,
            $tierBreakdown, $dayType, $intervals, $paidBreakMinutes
        );

        // === STEP 10: Special supplements ===
        // Schedule-change supplement if working non-contracted day
        // Only applies when: (a) enabled in rule set, (b) employee HAS a schedule, (c) not a holiday
        if (!empty($ruleSet['schedule_change_enabled']) && $dayType['is_non_contracted'] && !$dayType['is_holiday'] && $schedule !== null) {
            $schedChangeRate = (float)($ruleSet['schedule_change_rate'] ?? 50.0);
            if ($schedChangeRate > 0) {
                $wageLines[] = [
                    'wage_code' => 'W09',
                    'wage_type' => 'Schedule-change supplement',
                    'hours' => $netWorkedHours,
                    'base_rate' => $contract['base_hourly_wage'],
                    'multiplier' => 1.0,
                    'supplement_pct' => $schedChangeRate,
                    'amount' => round($netWorkedHours * $contract['base_hourly_wage'] * ($schedChangeRate / 100), 2),
                    'is_break_time' => 0,
                    'notes' => 'Non-contracted day supplement'
                ];
            }
        }

        // Guaranteed hours (employer cancellation)
        if ($entry['is_employer_cancelled'] && $scheduledHours > $netWorkedHours) {
            $guaranteedHours = $scheduledHours - $netWorkedHours;
            $wageLines[] = [
                'wage_code' => 'W11',
                'wage_type' => 'Guaranteed hours',
                'hours' => $guaranteedHours,
                'base_rate' => $contract['base_hourly_wage'],
                'multiplier' => 1.0,
                'supplement_pct' => 0,
                'amount' => round($guaranteedHours * $contract['base_hourly_wage'], 2),
                'is_break_time' => 0,
                'notes' => 'Employer cancellation - guaranteed pay'
            ];
        }

        // === STEP 11: Store wage lines ===
        $totalAmount = 0;
        $stmt = $this->pdo->prepare("
            INSERT INTO wage_lines (time_entry_id, employee_id, date, wage_code, wage_type, hours, base_rate, multiplier, supplement_pct, amount, is_break_time, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($wageLines as $line) {
            $stmt->execute([
                $timeEntryId, $entry['employee_id'], $entry['date'],
                $line['wage_code'], $line['wage_type'], $line['hours'],
                $line['base_rate'], $line['multiplier'], $line['supplement_pct'],
                $line['amount'], $line['is_break_time'], $line['notes'] ?? ''
            ]);
            $totalAmount += $line['amount'];
        }

        return [
            'time_entry_id' => $timeEntryId,
            'employee_id' => $entry['employee_id'],
            'date' => $entry['date'],
            'day_type' => $dayType,
            'gross_worked_minutes' => $workedMinutes,
            'break_minutes' => $breakInfo['duration'],
            'break_is_paid' => $breakInfo['is_paid'],
            'net_worked_hours' => $netWorkedHours,
            'normal_hours' => $normalHours,
            'overtime_hours' => $overtimeHours,
            'tier_breakdown' => $tierBreakdown,
            'wage_lines' => $wageLines,
            'total_amount' => round($totalAmount, 2),
        ];
    }

    /**
     * Step 1: Classify the day type
     */
    private function classifyDay(string $date, array $contract, ?array $schedule, string $calendarName): array {
        $isHoliday = $this->isPublicHoliday($date, $calendarName);
        $dayOfWeek = (int)date('N', strtotime($date)); // 1=Mon, 7=Sun
        $isWeekend = $dayOfWeek >= 6;
        $isScheduled = false;

        if ($schedule) {
            foreach ($schedule['days'] as $day) {
                if ($day['day_of_week'] == $dayOfWeek && $day['is_active']) {
                    $isScheduled = true;
                    break;
                }
            }
        }

        return [
            'date' => $date,
            'day_of_week' => $dayOfWeek,
            'day_name' => date('l', strtotime($date)),
            'is_holiday' => $isHoliday,
            'is_weekend' => $isWeekend,
            'is_saturday' => $dayOfWeek === 6,
            'is_sunday' => $dayOfWeek === 7,
            'is_scheduled' => $isScheduled,
            'is_non_contracted' => !$isScheduled,
        ];
    }

    /**
     * Check if date is a public holiday
     */
    private function isPublicHoliday(string $date, string $calendar): bool {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM holidays WHERE calendar_name = ? AND date = ?");
        $stmt->execute([$calendar, $date]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Step 3: Resolve break settings using cascade
     */
    private function resolveBreaks(array $entry, array $contract, array $ruleSet, ?array $schedule): array {
        // Break duration cascade: entry → schedule day → contract → rule set
        $duration = $ruleSet['default_break_duration'];

        if ($contract['default_break_duration'] !== null) {
            $duration = $contract['default_break_duration'];
        }

        if ($schedule) {
            $dayOfWeek = (int)date('N', strtotime($entry['date']));
            foreach ($schedule['days'] as $day) {
                if ($day['day_of_week'] == $dayOfWeek && $day['break_duration'] !== null) {
                    $duration = $day['break_duration'];
                    break;
                }
            }
        }

        if ($entry['break_minutes'] !== null) {
            $duration = $entry['break_minutes'];
        }

        // Paid/unpaid cascade: contract → rule set
        $isPaid = (bool)$ruleSet['breaks_paid'];
        if ($contract['breaks_paid'] === 'yes') $isPaid = true;
        elseif ($contract['breaks_paid'] === 'no') $isPaid = false;

        // Paid break capped at default duration (Section 6.2)
        $paidDuration = $duration;
        if ($isPaid && $duration > $ruleSet['default_break_duration']) {
            $paidDuration = $ruleSet['default_break_duration'];
        }

        return [
            'duration' => $duration,
            'is_paid' => $isPaid,
            'paid_duration' => $isPaid ? $paidDuration : 0,
        ];
    }

    /**
     * Calculate raw worked minutes between clock in/out
     */
    private function calcWorkedMinutes(string $clockIn, string $clockOut): int {
        $in = strtotime($clockIn);
        $out = strtotime($clockOut);
        if ($out <= $in) $out += 86400; // crosses midnight
        return (int)(($out - $in) / 60);
    }

    /**
     * Step 4: Split work period into time-of-day intervals
     */
    private function splitIntoIntervals(string $clockIn, string $clockOut, array $intervals, array $dayType): array {
        $slices = [];
        $inTime = strtotime($clockIn);
        $outTime = strtotime($clockOut);
        if ($outTime <= $inTime) $outTime += 86400;

        $baseDate = date('Y-m-d', $inTime);

        // For each minute of the shift, determine which interval it belongs to
        // We'll work in hour granularity for efficiency
        $current = $inTime;
        while ($current < $outTime) {
            $currentHour = (int)date('G', $current);
            $currentMin = (int)date('i', $current);
            $timeStr = sprintf('%02d:%02d', $currentHour, $currentMin);

            $matchedInterval = $this->findMatchingInterval($timeStr, $intervals, $dayType);

            // Find how long this interval lasts
            $sliceEnd = $outTime;
            // Check next minute boundary where interval might change
            $nextBoundary = $this->findNextIntervalBoundary($current, $outTime, $intervals, $dayType);
            if ($nextBoundary < $sliceEnd) $sliceEnd = $nextBoundary;

            $sliceMinutes = ($sliceEnd - $current) / 60;
            $sliceHours = round($sliceMinutes / 60, 4);

            if ($sliceHours > 0) {
                $key = $matchedInterval ? $matchedInterval['id'] . '_' . $matchedInterval['name'] : 'normal';
                if (!isset($slices[$key])) {
                    $slices[$key] = [
                        'interval' => $matchedInterval,
                        'hours' => 0,
                    ];
                }
                $slices[$key]['hours'] += $sliceHours;
            }

            $current = $sliceEnd;
        }

        return array_values($slices);
    }

    /**
     * Find which supplement interval matches a given time and day type
     */
    private function findMatchingInterval(string $time, array $intervals, array $dayType): ?array {
        $timeMinutes = $this->timeToMinutes($time);
        $bestMatch = null;
        $bestPriority = -1;

        foreach ($intervals as $interval) {
            if (!$this->intervalAppliesToDay($interval, $dayType)) continue;

            $startMin = $this->timeToMinutes($interval['start_time']);
            $endMin = $this->timeToMinutes($interval['end_time']);

            $inRange = false;
            if ($endMin > $startMin) {
                $inRange = $timeMinutes >= $startMin && $timeMinutes < $endMin;
            } else {
                // Crosses midnight
                $inRange = $timeMinutes >= $startMin || $timeMinutes < $endMin;
            }

            if ($inRange && $interval['priority'] > $bestPriority) {
                $bestMatch = $interval;
                $bestPriority = $interval['priority'];
            }
        }

        return $bestMatch;
    }

    private function intervalAppliesToDay(array $interval, array $dayType): bool {
        if ($dayType['is_holiday'] && $interval['applies_to_holidays']) return true;
        $days = array_filter(explode(',', $interval['applies_to_days']));
        return in_array((string)$dayType['day_of_week'], $days);
    }

    private function findNextIntervalBoundary(int $current, int $max, array $intervals, array $dayType): int {
        $boundaries = [];
        foreach ($intervals as $interval) {
            if (!$this->intervalAppliesToDay($interval, $dayType)) continue;
            $startMin = $this->timeToMinutes($interval['start_time']);
            $endMin = $this->timeToMinutes($interval['end_time']);
            $boundaries[] = $startMin;
            $boundaries[] = $endMin;
        }
        $boundaries = array_unique($boundaries);
        sort($boundaries);

        $currentMin = (int)date('G', $current) * 60 + (int)date('i', $current);
        $baseDay = strtotime(date('Y-m-d', $current));

        foreach ($boundaries as $bMin) {
            $bTime = $baseDay + $bMin * 60;
            if ($bTime <= $current) {
                // Try next day
                $bTime += 86400;
            }
            if ($bTime > $current && $bTime < $max) {
                return $bTime;
            }
        }

        return $max;
    }

    private function timeToMinutes(string $time): int {
        if ($time === '24:00') return 1440;
        $parts = explode(':', $time);
        return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
    }

    /**
     * Step 5: Determine normal vs overtime hours
     */
    private function determineOvertime(array $entry, array $contract, array $ruleSet, ?array $schedule, float $netWorkedHours): array {
        $triggerMode = $ruleSet['overtime_trigger_mode'];
        $balancingMode = $ruleSet['balancing_mode'];
        $overtimeModel = $ruleSet['overtime_model'];

        if ($overtimeModel === 'none') {
            return ['normal_hours' => $netWorkedHours, 'overtime_hours' => 0];
        }

        $scheduledHoursToday = $this->getScheduledHoursForDay($schedule, $entry['date']);
        $dayType = $this->classifyDay($entry['date'], $contract, $schedule, $ruleSet['holiday_calendar']);

        $overtimeHours = 0;
        $normalHours = $netWorkedHours;

        // === Per-day trigger ===
        if ($triggerMode === 'per_day' || $triggerMode === 'combined') {
            if ($dayType['is_non_contracted']) {
                // All hours on non-contracted day = overtime
                $overtimeHours = $netWorkedHours;
                $normalHours = 0;
            } else {
                // Hours beyond scheduled = overtime
                $perDayOvertime = max(0, $netWorkedHours - $scheduledHoursToday);
                $overtimeHours = $perDayOvertime;
                $normalHours = $netWorkedHours - $perDayOvertime;
            }
        }

        // === Weekly/period threshold trigger ===
        if ($triggerMode === 'weekly' || ($triggerMode === 'combined' && $overtimeHours === 0)) {
            // Always check weekly for the weekly trigger component
            $weeklyResult = $this->checkWeeklyOvertime($entry, $contract, $ruleSet, $netWorkedHours);
            if ($weeklyResult['overtime'] > 0) {
                $overtimeHours = max($overtimeHours, $weeklyResult['overtime']);
                $normalHours = $netWorkedHours - $overtimeHours;
            }
        }

        // Balancing mode: can offset daily overruns within the balancing period
        if ($balancingMode !== 'none') {
            $periodResult = $this->checkPeriodOvertime($entry, $contract, $ruleSet, $netWorkedHours);
            if ($periodResult && $periodResult['total_period_hours'] <= $periodResult['period_threshold']) {
                // Total period is under contract, no overtime despite daily overruns
                $overtimeHours = 0;
                $normalHours = $netWorkedHours;
            }
        }

        return [
            'normal_hours' => round(max(0, $normalHours), 4),
            'overtime_hours' => round(max(0, $overtimeHours), 4),
        ];
    }

    /**
     * Check weekly cumulative hours for overtime threshold
     */
    private function checkWeeklyOvertime(array $entry, array $contract, array $ruleSet, float $todayHours): array {
        $date = $entry['date'];
        $dayOfWeek = (int)date('N', strtotime($date));
        $monday = date('Y-m-d', strtotime($date . ' -' . ($dayOfWeek - 1) . ' days'));
        $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));

        // Use PHP-based calculation (SQLite strftime doesn't work with bare HH:MM time strings)
        $priorHours = $this->getPriorHoursInRange($entry['employee_id'], $monday, $sunday, $date, $contract, $ruleSet);

        // Check for mid-week contract changes
        $weeklyThreshold = $this->getBlendedWeeklyThreshold($contract, $entry['employee_id'], $monday, $sunday);

        $totalWeekHours = $priorHours + $todayHours;
        $weeklyOvertime = max(0, $totalWeekHours - $weeklyThreshold);
        // Only the portion from today
        $todayOvertime = min($todayHours, $weeklyOvertime);

        return [
            'total_week_hours' => round($totalWeekHours, 4),
            'weekly_threshold' => $weeklyThreshold,
            'overtime' => round(max(0, $todayOvertime), 4),
        ];
    }

    /**
     * Section 9: Get blended weekly threshold for mid-week contract changes
     */
    private function getBlendedWeeklyThreshold(array $currentContract, int $employeeId, string $monday, string $sunday): float {
        // Check for contract changes within this week
        $stmt = $this->pdo->prepare("
            SELECT * FROM contract_changes
            WHERE employee_id = ? AND field_name = 'total_weekly_hours'
            AND effective_date >= ? AND effective_date <= ?
            ORDER BY effective_date ASC
        ");
        $stmt->execute([$employeeId, $monday, $sunday]);
        $changes = $stmt->fetchAll();

        if (empty($changes)) {
            return $currentContract['total_weekly_hours'];
        }

        // Pro-rata calculation
        $totalThreshold = 0;
        $currentStart = $monday;
        $currentWeeklyHours = (float)($changes[0]['old_value'] ?? $currentContract['total_weekly_hours']);

        foreach ($changes as $change) {
            $changeDate = $change['effective_date'];
            $daysInSegment = $this->daysBetween($currentStart, $changeDate);
            $dailyRate = $currentWeeklyHours / 5; // Assuming 5-day work week
            $totalThreshold += $daysInSegment * $dailyRate;

            $currentWeeklyHours = (float)$change['new_value'];
            $currentStart = $changeDate;
        }

        // Remaining days
        $daysRemaining = $this->daysBetween($currentStart, date('Y-m-d', strtotime($sunday . ' +1 day')));
        $dailyRate = $currentWeeklyHours / 5;
        $totalThreshold += $daysRemaining * $dailyRate;

        return round($totalThreshold, 4);
    }

    private function daysBetween(string $from, string $to): int {
        return max(0, (int)((strtotime($to) - strtotime($from)) / 86400));
    }

    /**
     * Step 6: Apply overtime tiers
     */
    private function applyOvertimeTiers(float $overtimeHours, array $ruleSet, int $employeeId, string $date): array {
        if ($overtimeHours <= 0 || $ruleSet['overtime_model'] === 'none') {
            return ['tier1_hours' => 0, 'tier2_hours' => 0];
        }

        if ($ruleSet['overtime_model'] === 'flat') {
            return [
                'tier1_hours' => $overtimeHours,
                'tier1_rate' => $ruleSet['flat_overtime_rate'],
                'tier2_hours' => 0,
                'tier2_rate' => 0,
            ];
        }

        // Tiered: check cumulative overtime this week
        $weeklyOvertimeSoFar = $this->getWeeklyOvertimeSoFar($employeeId, $date);
        $tier1Threshold = $ruleSet['tier1_threshold'];

        $tier1Remaining = max(0, $tier1Threshold - $weeklyOvertimeSoFar);
        $tier1Hours = min($overtimeHours, $tier1Remaining);
        $tier2Hours = max(0, $overtimeHours - $tier1Hours);

        return [
            'tier1_hours' => round($tier1Hours, 4),
            'tier1_rate' => $ruleSet['tier1_rate'],
            'tier2_hours' => round($tier2Hours, 4),
            'tier2_rate' => $ruleSet['tier2_rate'],
        ];
    }

    private function getWeeklyOvertimeSoFar(int $employeeId, string $date): float {
        $dayOfWeek = (int)date('N', strtotime($date));
        $monday = date('Y-m-d', strtotime($date . ' -' . ($dayOfWeek - 1) . ' days'));

        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(hours), 0) FROM wage_lines
            WHERE employee_id = ? AND date >= ? AND date < ?
            AND wage_code IN ('W02', 'W03')
        ");
        $stmt->execute([$employeeId, $monday, $date]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Steps 7-9: Build wage lines with supplement stacking
     */
    private function buildWageLines(
        array $entry, array $contract, array $ruleSet, array $timeSlices,
        float $normalHours, array $tierBreakdown, array $dayType, array $intervals,
        int $paidBreakMinutes
    ): array {
        $lines = [];
        $baseRate = $contract['base_hourly_wage'];
        $stackingMode = $ruleSet['stacking_mode'];

        // Normal wage line (W01)
        if ($normalHours > 0) {
            $lines[] = [
                'wage_code' => 'W01',
                'wage_type' => 'Normal wage',
                'hours' => round($normalHours, 4),
                'base_rate' => $baseRate,
                'multiplier' => 1.0,
                'supplement_pct' => 0,
                'amount' => round($normalHours * $baseRate, 2),
                'is_break_time' => 0,
                'notes' => 'Base rate hours'
            ];
        }

        // Overtime lines (W02, W03)
        if ($tierBreakdown['tier1_hours'] > 0) {
            $rate = $tierBreakdown['tier1_rate'] ?? $ruleSet['tier1_rate'];
            $lines[] = [
                'wage_code' => 'W02',
                'wage_type' => 'Overtime Tier 1',
                'hours' => round($tierBreakdown['tier1_hours'], 4),
                'base_rate' => $baseRate,
                'multiplier' => 1 + ($rate / 100),
                'supplement_pct' => $rate,
                'amount' => round($tierBreakdown['tier1_hours'] * $baseRate * (1 + $rate / 100), 2),
                'is_break_time' => 0,
                'notes' => "Tier 1 at +{$rate}%"
            ];
        }
        if (($tierBreakdown['tier2_hours'] ?? 0) > 0) {
            $rate = $tierBreakdown['tier2_rate'] ?? $ruleSet['tier2_rate'];
            $lines[] = [
                'wage_code' => 'W03',
                'wage_type' => 'Overtime Tier 2',
                'hours' => round($tierBreakdown['tier2_hours'], 4),
                'base_rate' => $baseRate,
                'multiplier' => 1 + ($rate / 100),
                'supplement_pct' => $rate,
                'amount' => round($tierBreakdown['tier2_hours'] * $baseRate * (1 + $rate / 100), 2),
                'is_break_time' => 0,
                'notes' => "Tier 2 at +{$rate}%"
            ];
        }

        // Time-of-day & day-type supplements
        $totalWorkedHours = $normalHours + ($tierBreakdown['tier1_hours'] ?? 0) + ($tierBreakdown['tier2_hours'] ?? 0);
        $supplementLines = $this->calculateSupplements($timeSlices, $baseRate, $stackingMode, $ruleSet, $tierBreakdown, $totalWorkedHours);

        // Apply stacking mode resolution
        if ($stackingMode === 'highest_wins') {
            // Among ALL rates (supplements + overtime), only highest applies
            $allRates = [];
            foreach ($supplementLines as $sl) {
                $allRates[] = ['type' => 'supplement', 'pct' => $sl['supplement_pct'], 'line' => $sl];
            }
            if ($tierBreakdown['tier1_hours'] > 0) {
                $allRates[] = ['type' => 'overtime', 'pct' => $tierBreakdown['tier1_rate'] ?? $ruleSet['tier1_rate']];
            }
            if (($tierBreakdown['tier2_hours'] ?? 0) > 0) {
                $allRates[] = ['type' => 'overtime', 'pct' => $tierBreakdown['tier2_rate'] ?? $ruleSet['tier2_rate']];
            }

            if (!empty($allRates)) {
                usort($allRates, fn($a, $b) => $b['pct'] <=> $a['pct']);
                $highest = $allRates[0];

                // Remove overtime lines, replace with single highest
                $lines = array_filter($lines, fn($l) => !in_array($l['wage_code'], ['W02', 'W03']));
                $lines = array_values($lines);
                // Remove normal wage amount and recalculate
                $lines = array_filter($lines, fn($l) => $l['wage_code'] !== 'W01');
                $lines = array_values($lines);

                $totalHrs = $totalWorkedHours;
                $lines[] = [
                    'wage_code' => 'W01',
                    'wage_type' => 'Normal wage',
                    'hours' => round($totalHrs, 4),
                    'base_rate' => $baseRate,
                    'multiplier' => 1.0,
                    'supplement_pct' => 0,
                    'amount' => round($totalHrs * $baseRate, 2),
                    'is_break_time' => 0,
                    'notes' => 'Base rate (highest wins mode)'
                ];

                $winnerLine = $highest['type'] === 'supplement' ? $highest['line'] : null;
                if ($winnerLine) {
                    $winnerLine['hours'] = round($totalHrs, 4);
                    $winnerLine['amount'] = round($totalHrs * $baseRate * ($highest['pct'] / 100), 2);
                    $lines[] = $winnerLine;
                } else {
                    // Overtime winner: base rate already in W01, so supplement-only amount here
                    $code = $highest['pct'] >= ($ruleSet['tier2_rate'] ?? 100) ? 'W03' : 'W02';
                    $lines[] = [
                        'wage_code' => $code,
                        'wage_type' => $code === 'W03' ? 'Overtime Tier 2' : 'Overtime Tier 1',
                        'hours' => round($totalHrs, 4),
                        'base_rate' => $baseRate,
                        'multiplier' => $highest['pct'] / 100,
                        'supplement_pct' => $highest['pct'],
                        'amount' => round($totalHrs * $baseRate * ($highest['pct'] / 100), 2),
                        'is_break_time' => 0,
                        'notes' => "Highest wins: +{$highest['pct']}%"
                    ];
                }
                $supplementLines = []; // Already handled
            }
        } elseif ($stackingMode === 'supplement_replaces_overtime') {
            // If day supplement applies, use it instead of overtime
            if (!empty($supplementLines)) {
                // Remove overtime lines, keep supplements
                $lines = array_filter($lines, fn($l) => !in_array($l['wage_code'], ['W02', 'W03']));
                $lines = array_values($lines);
                // Recalculate normal hours to include overtime hours
                $overtimeHrs = ($tierBreakdown['tier1_hours'] ?? 0) + ($tierBreakdown['tier2_hours'] ?? 0);
                if ($overtimeHrs > 0) {
                    foreach ($lines as &$l) {
                        if ($l['wage_code'] === 'W01') {
                            $l['hours'] = round($l['hours'] + $overtimeHrs, 4);
                            $l['amount'] = round($l['hours'] * $baseRate, 2);
                            $l['notes'] = 'Supplement replaces overtime';
                        }
                    }
                    unset($l);
                }
            }
        }
        // Cumulative mode: supplements stack normally (already separate lines)

        $lines = array_merge($lines, $supplementLines);

        // Paid break line (W10)
        if ($paidBreakMinutes > 0) {
            $breakHours = round($paidBreakMinutes / 60, 4);
            $lines[] = [
                'wage_code' => 'W10',
                'wage_type' => 'Paid break',
                'hours' => $breakHours,
                'base_rate' => $baseRate,
                'multiplier' => 1.0,
                'supplement_pct' => 0,
                'amount' => round($breakHours * $baseRate, 2),
                'is_break_time' => 1,
                'notes' => 'Break paid at normal rate'
            ];
        }

        return $lines;
    }

    /**
     * Calculate supplement lines from time slices.
     *
     * Each time slice already has its winning interval (resolved by findMatchingInterval
     * which picks highest priority for overlapping intervals at each time point).
     * We aggregate by wage_code so each time-of-day supplement gets its correct hours.
     *
     * Note: For multi-group overlap (e.g., Group A + Group C at same time),
     * findMatchingInterval only returns one winner. Full multi-group stacking
     * would require returning all matching intervals per slice.
     */
    private function calculateSupplements(array $timeSlices, float $baseRate, string $stackingMode, array $ruleSet, array $tierBreakdown, float $totalHours): array {
        $supplementLines = [];

        // Aggregate hours by wage code from already-resolved time slices
        $byWageCode = [];
        foreach ($timeSlices as $slice) {
            $interval = $slice['interval'];
            if (!$interval || $interval['rate_value'] <= 0) continue;

            $wageCode = $interval['wage_code'];
            if (!isset($byWageCode[$wageCode])) {
                $byWageCode[$wageCode] = [
                    'interval' => $interval,
                    'hours' => 0,
                ];
            }
            $byWageCode[$wageCode]['hours'] += $slice['hours'];
        }

        // Each wage code produces a supplement line
        foreach ($byWageCode as $wageCode => $data) {
            $interval = $data['interval'];
            $hours = $data['hours'];
            $pct = $interval['rate_value'];

            if ($interval['rate_type'] === 'percentage') {
                $amount = round($hours * $baseRate * ($pct / 100), 2);
            } else {
                $amount = round($hours * $pct, 2); // Fixed DKK/h
            }

            $supplementLines[] = [
                'wage_code' => $wageCode,
                'wage_type' => $interval['name'] . ' supplement',
                'hours' => round($hours, 4),
                'base_rate' => $baseRate,
                'multiplier' => 1.0,
                'supplement_pct' => $pct,
                'amount' => $amount,
                'is_break_time' => 0,
                'notes' => "Group {$interval['stacking_group']}: {$interval['name']} +{$pct}" . ($interval['rate_type'] === 'percentage' ? '%' : ' DKK/h'),
            ];
        }

        return $supplementLines;
    }

    /**
     * Route to the correct period overtime check based on balancing mode
     */
    private function checkPeriodOvertime(array $entry, array $contract, array $ruleSet, float $todayHours): ?array {
        switch ($ruleSet['balancing_mode']) {
            case 'weekly':
                $result = $this->checkWeeklyOvertime($entry, $contract, $ruleSet, $todayHours);
                return [
                    'total_period_hours' => $result['total_week_hours'],
                    'period_threshold' => $result['weekly_threshold'],
                    'overtime' => $result['overtime'],
                ];
            case 'monthly':
                return $this->checkMonthlyOvertime($entry, $contract, $ruleSet, $todayHours);
            case 'custom':
                return $this->checkCustomPeriodOvertime($entry, $contract, $ruleSet, $todayHours);
            default:
                return null;
        }
    }

    /**
     * Section 3: Monthly balancing — hours balance within the calendar month
     */
    private function checkMonthlyOvertime(array $entry, array $contract, array $ruleSet, float $todayHours): array {
        $date = $entry['date'];
        $monthStart = date('Y-m-01', strtotime($date));
        $monthEnd = date('Y-m-t', strtotime($date));

        // Use PHP-based calculation with proper break deduction
        $priorHours = $this->getPriorHoursInRange($entry['employee_id'], $monthStart, $monthEnd, $date, $contract, $ruleSet);

        // Monthly threshold: weekly hours * weeks in month
        $daysInMonth = (int)date('t', strtotime($date));
        $weeksInMonth = $daysInMonth / 7;
        $monthlyThreshold = $contract['total_weekly_hours'] * $weeksInMonth;

        $totalMonthHours = $priorHours + $todayHours;
        $monthlyOvertime = max(0, $totalMonthHours - $monthlyThreshold);
        $todayOvertime = min($todayHours, $monthlyOvertime);

        return [
            'total_period_hours' => round($totalMonthHours, 4),
            'period_threshold' => round($monthlyThreshold, 4),
            'overtime' => round(max(0, $todayOvertime), 4),
        ];
    }

    /**
     * Section 3: Custom period balancing — admin-defined period in weeks
     */
    private function checkCustomPeriodOvertime(array $entry, array $contract, array $ruleSet, float $todayHours): array {
        $periodWeeks = $ruleSet['balancing_period_weeks'] ?? 4;
        $date = $entry['date'];

        // Determine period boundaries using Jan 1 of current year as reference
        $dayOfWeek = (int)date('N', strtotime($date));
        $monday = date('Y-m-d', strtotime($date . ' -' . ($dayOfWeek - 1) . ' days'));
        $yearStart = date('Y-01-01', strtotime($date));
        $yearStartMonday = date('Y-m-d', strtotime('monday this week', strtotime($yearStart)));

        $daysDiff = (strtotime($monday) - strtotime($yearStartMonday)) / 86400;
        $weeksDiff = (int)floor($daysDiff / 7);
        $periodIndex = (int)floor($weeksDiff / $periodWeeks);
        $periodStartWeek = $periodIndex * $periodWeeks;
        $periodStart = date('Y-m-d', strtotime($yearStartMonday . " +{$periodStartWeek} weeks"));
        $periodEnd = date('Y-m-d', strtotime($periodStart . " +{$periodWeeks} weeks -1 day"));

        // Use PHP-based calculation with proper break deduction
        $priorHours = $this->getPriorHoursInRange($entry['employee_id'], $periodStart, $periodEnd, $date, $contract, $ruleSet);

        $periodThreshold = $contract['total_weekly_hours'] * $periodWeeks;

        $totalPeriodHours = $priorHours + $todayHours;
        $periodOvertime = max(0, $totalPeriodHours - $periodThreshold);
        $todayOvertime = min($todayHours, $periodOvertime);

        return [
            'total_period_hours' => round($totalPeriodHours, 4),
            'period_threshold' => round($periodThreshold, 4),
            'overtime' => round(max(0, $todayOvertime), 4),
        ];
    }

    /**
     * Get total net worked hours for an employee in a date range (before a given date).
     * Uses PHP-based calculation to avoid SQLite strftime issues with bare time strings.
     */
    private function getPriorHoursInRange(int $employeeId, string $startDate, string $endDate, string $beforeDate, array $contract, array $ruleSet): float {
        $stmt = $this->pdo->prepare("
            SELECT clock_in, clock_out, break_minutes
            FROM time_entries
            WHERE employee_id = ? AND date >= ? AND date <= ? AND date < ?
        ");
        $stmt->execute([$employeeId, $startDate, $endDate, $beforeDate]);
        $entries = $stmt->fetchAll();

        $breaksPaid = $this->areBreaksPaid($contract, $ruleSet);
        $totalHours = 0;

        foreach ($entries as $e) {
            $mins = $this->calcWorkedMinutes($e['clock_in'], $e['clock_out']);
            if (!$breaksPaid && $e['break_minutes']) {
                $mins -= $e['break_minutes'];
            }
            $totalHours += max(0, $mins / 60);
        }

        return $totalHours;
    }

    private function areBreaksPaid(array $contract, array $ruleSet): bool {
        if ($contract['breaks_paid'] === 'yes') return true;
        if ($contract['breaks_paid'] === 'no') return false;
        return (bool)$ruleSet['breaks_paid'];
    }

    private function getScheduledHoursForDay(?array $schedule, string $date): float {
        if (!$schedule) return 0;
        $dayOfWeek = (int)date('N', strtotime($date));
        $total = 0;
        foreach ($schedule['days'] as $day) {
            if ($day['day_of_week'] == $dayOfWeek && $day['is_active']) {
                $start = $this->timeToMinutes($day['start_time']);
                $end = $this->timeToMinutes($day['end_time']);
                if ($end <= $start) $end += 1440;
                $minutes = $end - $start;
                // Deduct break if unpaid
                if ($day['break_duration']) $minutes -= $day['break_duration'];
                $total += $minutes / 60;
            }
        }
        return $total;
    }

    // === Data fetchers ===

    private function getTimeEntry(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM time_entries WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function getEmployee(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function getActiveContract(int $employeeId, string $date): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM employee_contracts
            WHERE employee_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?) AND is_active = 1
            ORDER BY start_date DESC LIMIT 1
        ");
        $stmt->execute([$employeeId, $date, $date]);
        return $stmt->fetch() ?: null;
    }

    private function getRuleSet(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM wage_rule_sets WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function getSupplementIntervals(int $ruleSetId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM supplement_intervals WHERE rule_set_id = ? ORDER BY priority DESC");
        $stmt->execute([$ruleSetId]);
        return $stmt->fetchAll();
    }

    private function getScheduleForDate(int $employeeId, string $date): ?array {
        // Get active schedule(s) for employee
        $stmt = $this->pdo->prepare("
            SELECT * FROM employee_schedules WHERE employee_id = ? AND is_active = 1 ORDER BY rotation_order ASC
        ");
        $stmt->execute([$employeeId]);
        $schedules = $stmt->fetchAll();

        if (empty($schedules)) return null;

        // Determine which rotation applies
        $schedule = $schedules[0]; // Default to first
        if (count($schedules) > 1 && $schedules[0]['rotation_start_date']) {
            $rotationStart = strtotime($schedules[0]['rotation_start_date']);
            $targetDate = strtotime($date);
            $daysDiff = (int)(($targetDate - $rotationStart) / 86400);
            $totalRotations = count($schedules);
            $weeksDiff = (int)floor($daysDiff / 7);
            $rotationIndex = $weeksDiff % $totalRotations;
            $schedule = $schedules[$rotationIndex];
        }

        // Load days
        $stmt = $this->pdo->prepare("SELECT * FROM schedule_days WHERE schedule_id = ? ORDER BY day_of_week, block_number");
        $stmt->execute([$schedule['id']]);
        $schedule['days'] = $stmt->fetchAll();

        return $schedule;
    }

    private function addWarning(int $employeeId, string $date, string $type, string $message): void {
        $stmt = $this->pdo->prepare("INSERT INTO warnings (employee_id, date, warning_type, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$employeeId, $date, $type, $message]);
    }

    /**
     * Calculate wages for an entire week
     */
    public function calculateWeek(int $employeeId, string $weekStartDate): array {
        $monday = $weekStartDate;
        $results = [];
        $stmt = $this->pdo->prepare("
            SELECT id FROM time_entries WHERE employee_id = ? AND date >= ? AND date <= ?
            ORDER BY date ASC
        ");
        $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
        $stmt->execute([$employeeId, $monday, $sunday]);
        $entries = $stmt->fetchAll();

        foreach ($entries as $entry) {
            $results[] = $this->calculate($entry['id']);
        }

        return $results;
    }
}
