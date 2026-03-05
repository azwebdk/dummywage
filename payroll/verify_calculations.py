"""
Verify wage calculations against all document examples.
Tests the mathematical logic independently of PHP.
"""

pass_count = 0
fail_count = 0

def test(name, expected, actual, tolerance=0.01):
    global pass_count, fail_count
    diff = abs(expected - actual)
    if diff <= tolerance:
        print(f"  ✓ PASS: {name} (expected: {expected}, got: {actual})")
        pass_count += 1
    else:
        print(f"  ✗ FAIL: {name} (expected: {expected}, got: {actual}, diff: {diff})")
        fail_count += 1

BASE_RATE = 150  # DKK/h

# =================================================
# TEST 1: Section 2.2 - Tiered Overtime
# =================================================
print("TEST 1: Tiered Overtime (Section 2.2)")
print("  Employee base: 150 DKK/h, works 5h overtime")
tier1_threshold = 3  # hours
tier1_rate = 50      # % = 1.5x
tier2_rate = 100     # % = 2.0x
overtime_hours = 5

tier1_h = min(overtime_hours, tier1_threshold)  # 3
tier2_h = max(0, overtime_hours - tier1_threshold)  # 2

tier1_pay = tier1_h * BASE_RATE * (1 + tier1_rate/100)  # 3 × 225 = 675
tier2_pay = tier2_h * BASE_RATE * (1 + tier2_rate/100)  # 2 × 300 = 600
total_ot_pay = tier1_pay + tier2_pay  # 1275

test("Tier 1 hours", 3.0, tier1_h)
test("Tier 2 hours", 2.0, tier2_h)
test("Tier 1 pay (3h × 225)", 675.0, tier1_pay)
test("Tier 2 pay (2h × 300)", 600.0, tier2_pay)
test("Total overtime pay", 1275.0, total_ot_pay)
print()

# =================================================
# TEST 2: Section 2.3 - Combined Mode
# =================================================
print("TEST 2: Combined Mode (Section 2.3)")
print("  Employee contracted Mon-Fri 08:00-13:00 (25h/week)")

# Non-contracted day: all hours are overtime
thu_hours = 5  # Working 5h on a day they're not scheduled
test("Non-contracted day → all overtime", 5.0, thu_hours)

# Extra hours on scheduled day: only excess is overtime
mon_scheduled = 5
mon_worked = 6
mon_overtime = max(0, mon_worked - mon_scheduled)
test("Extra 1h on Monday = overtime", 1.0, mon_overtime)

# No trigger case
week_total = 5.5 + 5.5 + 5.5 + 5.5  # 22h, under 25h
test("22h total, no weekly trigger", 0, max(0, week_total - 25))
print()

# =================================================
# TEST 3: Section 3 - Balancing Mode
# =================================================
print("TEST 3: Balancing Mode (Section 3)")
print("  Employee contracted 24h/week: Mon-Thu 5h, Fri 4h")
contract_weekly = 24

# None (strict) mode
tue_worked = 2
wed_worked = 7
tue_scheduled = 5
wed_scheduled = 5

# Strict per-day:
tue_undertime = max(0, tue_scheduled - tue_worked)  # 3h undertime
wed_overtime_strict = max(0, wed_worked - wed_scheduled)  # 2h overtime

test("Strict: Tuesday undertime", 3.0, tue_undertime)
test("Strict: Wednesday overtime", 2.0, wed_overtime_strict)

# Weekly balancing:
week_total_bal = 2 + 7 + 5 + 5 + 4  # = 23h
weekly_ot = max(0, week_total_bal - contract_weekly)
test("Weekly balance: 23h total, no overtime", 0.0, weekly_ot)
print()

# =================================================
# TEST 4: Section 4 - Time-of-Day Supplements
# =================================================
print("TEST 4: Time-of-Day Supplements (Section 4)")
print("  Default intervals on weekday")

# Mon-Fri intervals
# 06:00-18:00 Normal (0%)
# 18:00-23:00 Evening (+25%)
# 23:00-06:00 Night (+50%)

evening_rate = 25  # %
night_rate = 50    # %
saturday_rate = 45
sunday_rate = 65
holiday_rate = 100

# Working 18:00-23:00 (5h evening)
evening_hours = 5
evening_supplement = evening_hours * BASE_RATE * (evening_rate / 100)
test("Evening supplement (5h × 150 × 0.25)", 187.50, evening_supplement)

# Saturday 8h
sat_hours = 8
sat_supplement = sat_hours * BASE_RATE * (saturday_rate / 100)
test("Saturday supplement (8h × 150 × 0.45)", 540.0, sat_supplement)

# Sunday 5h
sun_hours = 5
sun_supplement = sun_hours * BASE_RATE * (sunday_rate / 100)
test("Sunday supplement (5h × 150 × 0.65)", 487.50, sun_supplement)
print()

# =================================================
# TEST 5: Section 5 - Stacking Modes
# =================================================
print("TEST 5: Supplement Stacking (Section 5)")

# Cumulative mode with priority groups
# Saturday evening overtime at 20:00
# Group A: Saturday (+45%, priority 2) wins over Evening (+25%, priority 1)
# Group B: Overtime Tier 1 (+50%)
# Total per hour: 150 + 67.50 + 75.00 = 292.50

print("  Cumulative mode: Saturday evening overtime")
group_a_winner = saturday_rate  # 45% (higher priority than evening 25%)
group_b = tier1_rate             # 50%

hourly_cumulative = BASE_RATE + (BASE_RATE * group_a_winner/100) + (BASE_RATE * group_b/100)
test("Cumulative: 150 + 67.50 + 75.00", 292.50, hourly_cumulative)

# Highest wins mode
print("  Highest wins: only OT 50% applies (highest of all)")
all_rates = [saturday_rate, evening_rate, tier1_rate]  # 45, 25, 50
highest = max(all_rates)  # 50%
hourly_highest = BASE_RATE + (BASE_RATE * highest / 100)
test("Highest wins: 150 + 75 = 225", 225.0, hourly_highest)

# Supplement replaces overtime
print("  Supplement replaces overtime: Saturday 45% replaces OT 50%")
hourly_replace = BASE_RATE + (BASE_RATE * saturday_rate / 100)
test("Supplement replaces OT: 150 + 67.50 = 217.50", 217.50, hourly_replace)
print()

# =================================================
# TEST 6: CRITICAL - Never compound supplements
# =================================================
print("TEST 6: Supplements NEVER Compound (Section 5 Warning)")
wrong = BASE_RATE * 1.5 * 1.45
correct = BASE_RATE + (BASE_RATE * 0.50) + (BASE_RATE * 0.45)
test("Wrong compound method", 326.25, wrong)
test("Correct additive method", 292.50, correct)
test("They are NOT equal", True, abs(wrong - correct) > 1)
print()

# =================================================
# TEST 7: Section 6 - Break Handling
# =================================================
print("TEST 7: Break Handling (Section 6)")

# Unpaid break: 8h shift - 30min = 7.5h billable
shift_minutes = 8 * 60
break_minutes = 30
net_unpaid = (shift_minutes - break_minutes) / 60
test("Unpaid break: 8h - 30min = 7.5h", 7.5, net_unpaid)

# Paid break: 8h shift = 8.0h billable
net_paid = shift_minutes / 60
test("Paid break: 8h = 8.0h", 8.0, net_paid)

# Long break: paid capped at default (30min), rest unpaid
long_break = 45
default_break = 30
paid_portion = min(long_break, default_break)
unpaid_excess = long_break - paid_portion
test("Long break paid portion capped at 30min", 30, paid_portion)
test("Unpaid excess: 15min", 15, unpaid_excess)
print()

# =================================================
# TEST 8: Section 9 - Mid-week Contract Change
# =================================================
print("TEST 8: Mid-Week Contract Change (Section 9)")
print("  30h/week → 25h/week effective Thursday")

old_weekly = 30
new_weekly = 25
old_daily = old_weekly / 5  # 6h/day
new_daily = new_weekly / 5  # 5h/day

# Mon-Wed: old contract (3 × 6h = 18h)
old_segment = 3 * old_daily
test("Mon-Wed expected hours", 18.0, old_segment)

# Thu-Fri: new contract (2 × 5h = 10h)
new_segment = 2 * new_daily
test("Thu-Fri expected hours", 10.0, new_segment)

# Blended threshold
blended = old_segment + new_segment
test("Blended weekly threshold", 28.0, blended)

# Employee worked 32h total
worked = 32
overtime_midweek = max(0, worked - blended)
test("Overtime: 32 - 28 = 4h", 4.0, overtime_midweek)
print()

# =================================================
# TEST 9: Full calculation flow (Section 10)
# =================================================
print("TEST 9: Full Calculation - Normal Weekday")
print("  Mon 08:00-13:00, base 150 DKK/h, no break, scheduled 08:00-13:00")

hours_worked = 5
# Normal daytime (06:00-18:00) = 0% supplement
# Scheduled day, exact match = no overtime
normal_pay = hours_worked * BASE_RATE
test("Normal day pay: 5h × 150 = 750 DKK", 750.0, normal_pay)
print()

# =================================================
# TEST 10: Full calculation - Saturday overtime with evening
# =================================================
print("TEST 10: Full Calc - Saturday 18:00-21:00 (Cumulative)")
print("  3h on Saturday evening, non-contracted, base 150 DKK/h")

sat_eve_hours = 3
# Non-contracted → all overtime (Tier 1 at +50%)
# Saturday supplement (+45%, Group A wins over Evening +25%)

ot_pay = sat_eve_hours * BASE_RATE * (1 + tier1_rate/100)  # 3 × 225 = 675
sat_supp = sat_eve_hours * BASE_RATE * (saturday_rate/100)  # 3 × 67.50 = 202.50
total_sat_eve = ot_pay + sat_supp

test("OT Tier 1 pay: 3h × 225 = 675", 675.0, ot_pay)
test("Saturday supplement: 3h × 67.50 = 202.50", 202.50, sat_supp)
test("Total: 877.50 DKK", 877.50, total_sat_eve)
print()

# =================================================
# TEST 11: Wage Codes (Section 10.1)
# =================================================
print("TEST 11: Wage Code Verification")
codes = {
    'W01': 'Normal wage (1.0x)',
    'W02': 'Overtime Tier 1 (1.5x default)',
    'W03': 'Overtime Tier 2 (2.0x default)',
    'W04': 'Evening supplement (+25%)',
    'W05': 'Night supplement (+50%)',
    'W06': 'Saturday supplement (+45%)',
    'W07': 'Sunday supplement (+65%)',
    'W08': 'Public holiday supplement (+100%)',
    'W09': 'Schedule-change supplement (+50%)',
    'W10': 'Paid break (at W01 rate)',
    'W11': 'Guaranteed hours (employer cancel)',
}
test("All 11 wage codes defined", 11, len(codes))

# Verify multipliers
test("W01 rate = 1.0x", 1.0, 1 + 0/100)
test("W02 default = 1.5x", 1.5, 1 + 50/100)
test("W03 default = 2.0x", 2.0, 1 + 100/100)
test("W04 evening = base + 25%", BASE_RATE * 0.25, 37.5)
test("W05 night = base + 50%", BASE_RATE * 0.50, 75.0)
test("W06 saturday = base + 45%", BASE_RATE * 0.45, 67.5)
test("W07 sunday = base + 65%", BASE_RATE * 0.65, 97.5)
test("W08 holiday = base + 100%", BASE_RATE * 1.00, 150.0)
print()

# =================================================
print("=" * 50)
print(f"RESULTS: {pass_count} passed, {fail_count} failed")
print("=" * 50)
