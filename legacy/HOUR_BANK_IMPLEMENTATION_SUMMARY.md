# SISTUR Hour Bank (Banco de Horas) - Complete Implementation Summary

## 1. CURRENT IMPLEMENTATION STATUS

**The hour bank system is FULLY IMPLEMENTED and OPERATIONAL** as of version 1.4.0

The system automatically calculates the employee's hour bank balance by comparing worked time with expected load and provides real-time updates and historical data.

---

## 2. HOUR BANK CALCULATION LOGIC

### A. Calculation Formula

```
Hour Bank Balance = Time Worked - Expected Load
```

### B. Expected Working Hours (Priority Order)

The system determines expected working hours in this order:

1. **Priority 1**: Contract type daily load → `sistur_contract_types.carga_horaria_diaria_minutos`
2. **Priority 2**: Employee-specific load → `sistur_employees.time_expected_minutes`
3. **Priority 3**: Default CLT standard → 480 minutes (8 hours)

**Code Location**: `/includes/class-sistur-punch-processing.php:1305-1324`

```php
private function get_expected_minutes($employee) {
    $expected_minutes = 480; // Default: 8 hours
    
    // Priority 1: Contract type
    if (!empty($employee->carga_horaria_diaria_minutos) && intval($employee->carga_horaria_diaria_minutos) > 0) {
        $expected_minutes = intval($employee->carga_horaria_diaria_minutos);
    }
    // Priority 2: Employee-specific
    elseif (!empty($employee->time_expected_minutes) && intval($employee->time_expected_minutes) > 0) {
        $expected_minutes = intval($employee->time_expected_minutes);
    }
    
    return $expected_minutes;
}
```

### C. Work Time Calculation Algorithm (Paired Punch Algorithm)

The system processes time entries as **closed pairs** (entries and exits):

**Algorithm**: Process punches in pairs (0-1, 2-3, 4-5, etc.)

```
For each pair (entry, exit):
    If both timestamps exist AND exit > entry:
        Calculate: minutes_worked = (exit_time - entry_time) / 60
        Add to total_worked_minutes
    Else:
        Mark day for review (needs_review = 1)
```

**Example**:
```
Punch 1: 08:00 (Entry morning)
Punch 2: 12:00 (Lunch start)  → Morning work = 4h
Punch 3: 13:00 (Lunch end)
Punch 4: 17:00 (Exit)          → Afternoon work = 4h
Total: 8h
```

**Code Location**: `/includes/class-sistur-punch-processing.php:502-700` (method `process_employee_day()`)

### D. Delay Tolerance Application

After calculating the raw balance, a configurable tolerance is applied ONLY to negative balances (delays):

```
If balance < 0 (delay):
    If |balance| <= tolerance_minutes:
        Return 0 (completely forgiven)
    Else:
        Return balance + tolerance (discount only the excess)
Else:
    Return balance unchanged (positive balance not affected)
```

**Code Location**: `/includes/class-sistur-punch-processing.php:789-810`

**Configuration**: `tolerance_minutes_delay` setting (default: 0)

### E. Final Balance

```
Final Balance = Calculated Balance + Manual Adjustment
```

The manual adjustment is stored in `sistur_time_days.bank_minutes_adjustment` and allows supervisors to manually adjust the calculated balance.

---

## 3. DATA STRUCTURES & TABLES

### A. Table: `wp_sistur_employees`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | mediumint | Employee ID |
| `name` | varchar | Employee name |
| `email` | varchar | Employee email |
| `contract_type_id` | mediumint | Reference to contract type (foreign key) |
| `time_expected_minutes` | smallint | Override for expected work minutes (480 = 8h default) |
| `lunch_minutes` | smallint | Lunch break duration (default: 60) |
| `token_qr` | varchar(36) | QR code token for time clock |
| `status` | tinyint | Employee active status (1 = active) |

### B. Table: `wp_sistur_contract_types`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | mediumint | Contract type ID |
| `descricao` | varchar | Description (e.g., "Full-time", "Part-time") |
| `carga_horaria_diaria_minutos` | int | **Daily expected minutes** (default: 480) |
| `carga_horaria_semanal_minutos` | int | Weekly expected minutes (default: 2640 = 8h × 5.5 days) |
| `intervalo_minimo_almoco_minutos` | int | Minimum lunch break (default: 60) |

### C. Table: `wp_sistur_time_entries` (Time Punch Records)

| Column | Type | Purpose |
|--------|------|---------|
| `id` | mediumint | Entry ID |
| `employee_id` | mediumint | Employee reference |
| `punch_type` | varchar(20) | Type: `clock_in`, `lunch_start`, `lunch_end`, `clock_out` |
| `punch_time` | datetime | Exact time of punch |
| `shift_date` | date | Date of the shift |
| `source` | enum | Source: `admin`, `KIOSK`, `MOBILE_APP`, `MANUAL_AJUSTE` |
| `processing_status` | enum | `PENDENTE` (pending) or `PROCESSADO` (processed) |
| `created_at` | datetime | Creation timestamp |

**Key Indices**:
- `employee_id` - Fast employee lookups
- `shift_date` - Fast date range queries
- `processing_status` - Fast lookup of pending entries

### D. Table: `wp_sistur_time_days` (Processed Daily Summary)

| Column | Type | Purpose |
|--------|------|---------|
| `id` | mediumint | Record ID |
| `employee_id` | mediumint | Employee reference |
| `shift_date` | date | Work date |
| `status` | enum | `present`, `absence_no_pay`, `absence_medical`, `bank_used`, `holiday`, `day_off` |
| `minutos_trabalhados` | int | **Total minutes worked that day** |
| `saldo_calculado_minutos` | int | **Calculated balance** (worked - expected) |
| `saldo_final_minutos` | int | **Final balance** (calculated + adjustment) |
| `bank_minutes_adjustment` | int | Manual adjustment by supervisor |
| `needs_review` | tinyint | Flag if day needs review (1 = needs review, 0 = OK) |
| `notes` | text | System notes (e.g., why marked for review) |
| `supervisor_notes` | text | Supervisor comments |
| `supervisor_notes` | text | Supervisor comments |
| `reviewed_at` | datetime | When supervisor reviewed |
| `reviewed_by` | bigint | User ID of reviewer |
| `created_at` | datetime | Record creation |
| `updated_at` | datetime | Last update |

**Unique Constraint**: `(employee_id, shift_date)` - One record per employee per day

### E. Table: `wp_sistur_settings` (Configuration)

| Setting Key | Default | Type | Purpose |
|-------------|---------|------|---------|
| `auto_processing_enabled` | `1` (true) | boolean | Enable automatic nightly processing |
| `processing_time` | `01:00` | string | Time to run nightly job (HH:MM format) |
| `processing_batch_size` | `50` | integer | Employees processed per batch |
| `tolerance_minutes_delay` | `0` | integer | Tolerance in minutes for negative balance |

---

## 4. PROCESSING FLOW

### A. Real-Time Processing (Immediate)

**When**: Employee registers a punch via QR code or API

**Process**:
```
1. Punch recorded in wp_sistur_time_entries (status: PENDENTE)
2. IMMEDIATE: process_employee_day() called
3. Algorithm processes all pairs from today
4. Results saved to wp_sistur_time_days
5. Hour bank updated instantly
```

**Code Location**: `/includes/class-sistur-punch-processing.php:194-196`

### B. Nightly Batch Processing (Backup)

**When**: Daily at 1:00 AM (configurable via `processing_time` setting)

**Process**:
```
1. WP-Cron triggers 'sistur_nightly_processing' hook
2. Processes YESTERDAY's PENDENTE entries
3. Handles large batches with memory management
4. Each employee's day calculated if not already done
5. All entries marked as PROCESSADO
```

**Cron Configuration**:
- **Hook name**: `sistur_nightly_processing`
- **Frequency**: Daily
- **Default time**: 1:00 AM
- **Batch handling**: Processes up to 50 employees per cycle, then schedules next batch

**Code Location**: `/includes/class-sistur-punch-processing.php:415-428` (scheduling), `433-486` (execution)

### C. Manual Reprocessing

**Endpoint**: `GET /wp-json/sistur/v1/cron/process`

**Purpose**: Force immediate processing (useful for debugging or emergency fixes)

**Also Available**:
- **API**: `POST /wp-json/sistur/v1/reprocess` - Reprocess specific date ranges or employees
- **Script**: `/reprocess-timebank.php?days=30&employee_id=10` - CLI-based reprocessing

---

## 5. REST API ENDPOINTS

### A. Get Total Balance

```
GET /wp-json/sistur/v1/balance/{employee_id}
```

**Response**:
```json
{
  "user_id": 123,
  "total_banco_horas_minutos": -120,
  "formatted": "-02:00"
}
```

### B. Get Weekly Data

```
GET /wp-json/sistur/v1/time-bank/{employee_id}/weekly?week=2025-11-18
```

**Response**:
```json
{
  "employee_id": 123,
  "employee_name": "João Silva",
  "week_start": "2025-11-18",
  "week_end": "2025-11-22",
  "days": [
    {
      "date": "2025-11-18",
      "day_name": "Segunda-feira",
      "status": "present",
      "worked_minutes": 480,
      "worked_formatted": "8h",
      "expected_minutes": 480,
      "expected_formatted": "8h",
      "deviation_minutes": 0,
      "deviation_formatted": "+0h",
      "needs_review": false,
      "punches": {
        "clock_in": "08:00",
        "lunch_start": "12:00",
        "lunch_end": "13:00",
        "clock_out": "17:00"
      }
    }
  ],
  "summary": {
    "total_worked_minutes": 2400,
    "total_worked_formatted": "40h",
    "total_expected_minutes": 2400,
    "total_expected_formatted": "40h",
    "week_deviation_minutes": 0,
    "week_deviation_formatted": "+0h",
    "accumulated_bank_minutes": 120,
    "accumulated_bank_formatted": "+2h"
  }
}
```

### C. Get Monthly Data

```
GET /wp-json/sistur/v1/time-bank/{employee_id}/monthly?month=2025-11
```

**Returns**: Daily breakdown for the entire month with summary statistics

### D. Get Current Status/Prediction

```
GET /wp-json/sistur/v1/time-bank/{employee_id}/current
```

**Returns**: Real-time analysis of today's punches with predictions for completing expected hours

---

## 6. USER INTERFACES (Where Hour Bank is Displayed)

### A. Weekly Widget (Employee Dashboard)

**Location**: `/templates/components/time-bank-widget.php`

**Features**:
- 4 summary cards: Hours Worked, Expected Hours, Week Deviation, Total Bank
- Weekly table (Monday-Friday) with daily breakdown
- Navigation between weeks
- Real-time updates via API

**Displayed on**: Employee dashboard/panel

### B. Complete Hour Bank Page

**Location**: `/templates/banco-de-horas.php`

**Features**:
- Header with employee name and logout
- 4 summary cards (Total Balance, Month Worked, Month Deviation, Days Present)
- Filter options: Weekly, Monthly, Custom date range
- Chart.js bar chart visualization
- Detailed table with daily records
- PDF export capability
- Interactive navigation

**Access**: `/banco-de-horas/` (requires employee login)

### C. Admin/Supervisor Views

**Locations**:
- Time tracking management pages
- Employee detail pages
- Timesheet review interface

---

## 7. CALCULATION EXAMPLES

### Example 1: Balanced Day (Saldo = 0)

```
Expected: 480 minutes (8 hours)

Punches:
  08:00 Entry
  12:00 Lunch start  → Morning: (12:00 - 08:00) = 4h = 240 min
  13:00 Lunch end
  17:00 Exit         → Afternoon: (17:00 - 13:00) = 4h = 240 min

Total worked: 240 + 240 = 480 min
Balance: 480 - 480 = 0 min ✅ (ON TIME)
```

### Example 2: Positive Balance (Hour Extra)

```
Expected: 480 minutes (8 hours)

Punches:
  08:00 Entry
  12:00 Lunch start  → Morning: 4h = 240 min
  13:00 Lunch end
  18:00 Exit         → Afternoon: 5h = 300 min

Total worked: 240 + 300 = 540 min
Balance: 540 - 480 = +60 min (+1h) ⬆️ (EXTRA HOUR)
```

### Example 3: Negative Balance (Delay/Absence)

```
Expected: 480 minutes (8 hours)

Punches:
  09:00 Entry        → Arrived 1 hour late
  12:00 Lunch start  → Morning: 3h = 180 min
  13:00 Lunch end
  17:00 Exit         → Afternoon: 4h = 240 min

Total worked: 180 + 240 = 420 min
Balance: 420 - 480 = -60 min (-1h) ⬇️ (SHORT 1 HOUR)

If tolerance_minutes_delay = 15:
  |−60| > 15, so: −60 + 15 = −45 min (−45 minutes still owed)
```

---

## 8. TECHNICAL DETAILS

### A. Transaction Safety (Bug Fix #1)

The `process_employee_day()` method uses database transactions:
```php
$wpdb->query('START TRANSACTION');
try {
    // Process with locking
    $wpdb->query("SELECT id FROM $table_days ... FOR UPDATE");
    // ... calculation
    $wpdb->query('COMMIT');
} catch (Exception $e) {
    $wpdb->query('ROLLBACK');
}
```

**Purpose**: Prevent race conditions when multiple processes try to update the same day simultaneously.

### B. Duplicate Punch Prevention (Bug Fix #2)

Punches within 5 seconds are rejected:
```php
$recent_punch = $wpdb->get_var(
    "SELECT COUNT(*) FROM $table
     WHERE employee_id = %d
     AND punch_time >= DATE_SUB(%s, INTERVAL 5 SECOND)"
);
```

### C. Chronological Order Validation (Bug Fix #6)

Punches are validated to ensure they occur in chronological order:
```php
for ($i = 0; $i < $punch_count - 1; $i++) {
    if ($punches[$i]['time'] >= $punches[$i+1]['time']) {
        $needs_review = true;
    }
}
```

### D. Memory Management (Bug Fix #7)

During batch processing, cache is cleared every 10 employees:
```php
if (($count % 10) === 0) {
    wp_cache_flush();
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
}
```

---

## 9. SETTINGS & CONFIGURATION

### Default Settings (from `class-sistur-activator.php`)

| Setting | Default | Description |
|---------|---------|-------------|
| `tolerance_minutes_delay` | 0 | Minutes of negative balance forgiven (0 = no tolerance) |
| `auto_processing_enabled` | true | Enable automatic nightly processing job |
| `processing_time` | 01:00 | Time to run nightly job (24-hour format) |
| `processing_batch_size` | 50 | Employees per batch in nightly job |
| `cron_secret_key` | *auto-generated* | Security key for cron endpoints |

### How to Modify Settings

Via WordPress admin panel or database:
```sql
UPDATE wp_sistur_settings
SET setting_value = '60'
WHERE setting_key = 'tolerance_minutes_delay';
```

---

## 10. DIAGNOSTIC & TROUBLESHOOTING TOOLS

### A. Diagnostic Script

**File**: `/diagnose-timebank.php?employee_id=10`

**Checks**:
- Employee data and contract hours
- Recent punch records
- Processed days and calculated balances
- Total accumulated balance
- Unprocessed dates
- API test links

### B. Reprocessing Script

**File**: `/reprocess-timebank.php?days=30&employee_id=10`

**Parameters**:
- `days` - Number of days back to reprocess (default: 30)
- `employee_id` - Specific employee (optional; all if omitted)

**Use cases**:
- After importing historical punches
- After fixing bugs
- After updating expected hours
- For data corrections

### C. Complete Test Suite

**File**: `/test-banco-horas-completo.php`

**Tests**:
- Employee and contract types
- Recent punch entries
- Processed days
- Total balances
- Balance interpretation

---

## 11. KEY FILES REFERENCE

| File | Purpose |
|------|---------|
| `/includes/class-sistur-punch-processing.php` | **Main implementation** - All calculation and API logic |
| `/includes/class-sistur-employees.php` | Employee management and contract handling |
| `/includes/class-sistur-activator.php` | Database schema and default settings |
| `/includes/class-sistur-time-tracking.php` | Time entry management (AJAX handlers) |
| `/templates/banco-de-horas.php` | Complete hour bank page UI |
| `/templates/components/time-bank-widget.php` | Weekly summary widget |
| `/diagnose-timebank.php` | Diagnostic tool |
| `/reprocess-timebank.php` | Reprocessing tool |
| `/test-banco-horas-completo.php` | Test suite |

---

## 12. WORKFLOW SUMMARY

```
PUNCH REGISTRATION
    ↓
POST /wp-json/sistur/v1/punch (employee scans QR or via mobile)
    ↓
Entry saved to wp_sistur_time_entries (status: PENDENTE)
    ↓
IMMEDIATE PROCESSING (real-time)
    ↓
process_employee_day() calculates:
  • Expected minutes (from contract)
  • Pairs of punches (entry-exit)
  • Total worked minutes
  • Balance = worked - expected
  • Apply tolerance
    ↓
Results saved to wp_sistur_time_days
    ↓
Hour Bank UPDATED IN REAL-TIME
    ↓
Entry marked PROCESSADO
    ↓
[BACKUP] Nightly job (1:00 AM) processes any remaining PENDENTE entries
    ↓
APIs serve data to UI:
  • GET /balance → total balance
  • GET /weekly → week summary
  • GET /monthly → month summary
    ↓
Employee sees updated balance on:
  • Dashboard widget
  • Full hour bank page
  • Mobile app
```

---

## 13. IMPORTANT NOTES

1. **Real-time updates**: Hour bank updates immediately when employee punches, not at night
2. **Closed pairs only**: System only counts complete entry-exit pairs
3. **Chronological validation**: Punches must occur in time order
4. **No negative hours**: If only 3 punches exist, day marked for review
5. **Tolerance applied**: Only affects negative balances (delays), not positive (extras)
6. **Manual adjustments**: Supervisor can manually adjust calculated balance
7. **Cleanup**: Days marked for review indicate punches that don't form proper pairs
8. **Database integrity**: Transactions prevent race conditions
9. **Performance**: Batch processing prevents large operations from locking database
10. **Backup processing**: Nightly job ensures catch-up if real-time processing fails

---

**Last Updated**: November 19, 2025
**System Version**: 1.4.0
**Documentation**: Fully comprehensive implementation with bug fixes and optimizations
