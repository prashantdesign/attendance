<?php
// api.php
// Start output buffering to prevent whitespace from breaking JSON
ob_start();

// Enable error reporting for debugging (check logs, don't output to browser if possible)
ini_set('display_errors', 0); // Changed to 0 to prevent HTML errors inside JSON response
error_reporting(E_ALL);

// Set JSON header
header('Content-Type: application/json');

// Include DB connection
require 'db.php'; 

// Constants
const FIXED_BREAK_MINUTES = 60; 

// Helper to send JSON and exit
function sendJsonResponse($data, $code = 200) {
    // Clear the buffer before sending
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($code);
    echo json_encode($data);
    exit; 
}

// Router
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'batchSave':
            handleBatchSave($pdo);
            break;
        case 'getRecords':
            handleGetRecords($pdo);
            break;
        case 'updateRecord':
            handleUpdateRecord($pdo);
            break;
        case 'deleteRecord': 
            sendJsonResponse(['status' => 'error', 'message' => 'Record deletion is currently disabled.']);
            break;
        case 'getDashboardStats':
            handleGetDashboardStats($pdo);
            break;
        case 'getEmployees':
            handleGetEmployees($pdo);
            break;
        case 'getMonthlyAttendance':
            handleGetMonthlyAttendance($pdo);
            break;
        case 'getEmployeeAttendanceSummary': 
            handleGetEmployeeAttendanceSummary($pdo);
            break;
        case 'exportData':
            handleExportData($pdo);
            break;
        case 'getShiftDefinitions':
            handleGetShiftDefinitions($pdo);
            break;
        case 'saveShiftDefinitions':
            handleSaveShiftDefinitions($pdo);
            break;
        case 'getBreakAbuseStats': 
            handleGetBreakAbuseStats($pdo);
            break;
        case 'getDoublePunches':
            handleGetDoublePunches($pdo);
            break;
        case 'deleteSpecificPunch':
            handleDeleteSpecificPunch($pdo);
            break;
        default:
            sendJsonResponse(['status' => 'error', 'message' => 'Invalid action specified.']);
    }
} catch (Exception $e) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'An unexpected error occurred.',
        'error' => $e->getMessage()
    ], 500);
}

// --- Helper Functions ---

function timeToSeconds($timeStr) {
    if (!$timeStr || $timeStr === '00:00:00') return 0;
    $parts = explode(':', $timeStr);
    if (count($parts) < 3) return 0;
    return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
}

function secondsToTime($seconds) {
    if ($seconds <= 0) return '00:00:00';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function calculateActualBreakSeconds($punchString) {
    if (empty($punchString)) return 0;
    $punches = explode(',', $punchString);
    $punchesData = [];
    foreach ($punches as $punch) {
        $parts = explode(':', $punch);
        if (count($parts) >= 4) {
             $timePart = implode(':', array_slice($parts, 0, 3));
             $punchesData[] = ['time' => $timePart, 'type' => $parts[3]];
        }
    }
    usort($punchesData, function($a, $b) {
        return timeToSeconds($a['time']) - timeToSeconds($b['time']);
    });
    $totalBreakSeconds = 0;
    $lastOutTime = null;
    foreach ($punchesData as $punch) {
        $timeSeconds = timeToSeconds($punch['time']);
        if ($punch['type'] === 'out') {
            $lastOutTime = $timeSeconds;
        } elseif ($punch['type'] === 'in' && $lastOutTime !== null) {
            $breakDuration = $timeSeconds - $lastOutTime;
            if ($breakDuration > 0) { $totalBreakSeconds += $breakDuration; }
            $lastOutTime = null; 
        }
    }
    return $totalBreakSeconds;
}

function calculateTimeMetrics($record) {
    $scheduledIn = $record['ScheduledIn'];
    $scheduledOut = $record['ScheduledOut'];
    $actualIn = $record['ActualIn'];
    $actualOut = $record['ActualOut'];
    $punchRecords = $record['PunchRecords'] ?? '';

    $earlyByInSec = 0;
    $lateByOutSec = 0;
    $lateBySec = 0; 
    $earlyGoingBySec = 0; 
    
    $scheduledInSec = timeToSeconds($scheduledIn);
    $scheduledOutSec = timeToSeconds($scheduledOut);
    $actualInSec = timeToSeconds($actualIn);
    $actualOutSec = timeToSeconds($actualOut);
    
    // Core Metrics
    if ($scheduledIn && $actualIn && $scheduledInSec > 0 && $actualInSec > 0) {
        $diff = $scheduledInSec - $actualInSec;
        if ($diff > 0) { $earlyByInSec = $diff; }
    }
    $record['EarlyByIn'] = secondsToTime($earlyByInSec);

    if ($scheduledOut && $actualOut && $scheduledOutSec > 0 && $actualOutSec > 0) {
        $diff = $actualOutSec - $scheduledOutSec;
        if ($diff > 0) { $lateByOutSec = $diff; }
    }
    $record['LateByOut'] = secondsToTime($lateByOutSec);
    
    if ($scheduledIn && $actualIn && $scheduledInSec > 0 && $actualInSec > 0) {
        $diff = $actualInSec - $scheduledInSec;
        if ($diff > 0) { $lateBySec = $diff; }
    }
    $record['LateBy'] = secondsToTime($lateBySec);
    
    if ($scheduledOut && $actualOut && $scheduledOutSec > 0 && $actualOutSec > 0) {
        $diff = $scheduledOutSec - $actualOutSec;
        if ($diff > 0) { $earlyGoingBySec = $diff; }
    }
    $record['EarlyGoingBy'] = secondsToTime($earlyGoingBySec);
    
    // Break Metrics
    $actualBreakSec = calculateActualBreakSeconds($punchRecords);
    $record['ActualBreak'] = secondsToTime($actualBreakSec);

    $fixedBreakSec = FIXED_BREAK_MINUTES * 60;
    $uncoveredExcessBreakSec = max(0, $actualBreakSec - $fixedBreakSec);
    $earlyInUsedForBreak = min($uncoveredExcessBreakSec, $earlyByInSec);
    $record['BreakAdjustment'] = secondsToTime($earlyInUsedForBreak);
    
    $adjustedBreakResultSec = $actualBreakSec - $earlyInUsedForBreak;
    $record['AdjustedBreakResult'] = secondsToTime($adjustedBreakResultSec);
    
    $remainingEarlyInSec = $earlyByInSec - $earlyInUsedForBreak;
    $adjustedOvertimeSec = $lateByOutSec + $remainingEarlyInSec;
    $record['AdjustedOvertime'] = secondsToTime($adjustedOvertimeSec);
    
    // Work Duration
    $actualDurationSec = 0;
    if ($actualInSec > 0 && $actualOutSec > $actualInSec) {
         $actualDurationSec = $actualOutSec - $actualInSec;
    }
    $netWorkSec = max(0, $actualDurationSec - $adjustedBreakResultSec); 
    $record['NetWorkDuration'] = secondsToTime($netWorkSec); 

    // Update legacy fields
    $record['WorkDuration'] = secondsToTime($netWorkSec);
    $record['Overtime'] = secondsToTime($adjustedOvertimeSec);
    $record['TotalDuration'] = secondsToTime($actualDurationSec);

    return $record;
}

function checkMissedPunch($punchString) {
    if (empty($punchString)) return false;
    $punches = explode(',', $punchString);
    $punches = array_filter($punches, 'strlen');
    return count($punches) % 2 !== 0;
}

// --- Late Remarks Logic ---
function calculateMonthlyLateRemarks($pdo, $data, $startDate, $endDate) {
    if (empty($data)) return [];

    $employeeIds = array_unique(array_column($data, 'EmployeeID'));
    if (empty($employeeIds)) return $data;

    $monthStart = date('Y-m-01', strtotime($startDate));
    $monthEnd = date('Y-m-t', strtotime($endDate));
    
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $sql = "SELECT EmployeeID, AttendanceDate, LateBy FROM AttendanceLog 
            WHERE EmployeeID IN ($placeholders) 
            AND AttendanceDate BETWEEN ? AND ? 
            ORDER BY AttendanceDate ASC";
    
    $stmt = $pdo->prepare($sql);
    $params = array_merge($employeeIds, [$monthStart, $monthEnd]);
    $stmt->execute($params);
    $allLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $employeeRemarks = []; 
    $employeeLateCounters = []; 

    foreach ($allLogs as $log) {
        $empId = $log['EmployeeID'];
        $date = $log['AttendanceDate'];
        $lateSec = timeToSeconds($log['LateBy']);
        
        if (!isset($employeeLateCounters[$empId])) {
            $employeeLateCounters[$empId] = 0;
        }

        $remark = null;

        if ($lateSec > 1800) {
            // > 30 mins late: Half Day
            $remark = "Half Day (>30m)";
        } 
        else if ($lateSec > 300) {
            // > 5 mins late: Increments counter
            $employeeLateCounters[$empId]++;
            $count = $employeeLateCounters[$empId];
            
            if ($count === 1) {
                $remark = "1st Late";
            } elseif ($count === 2) {
                $remark = "2nd Late";
            } elseif ($count === 3) {
                $remark = "3rd Late";
            } else {
                $remark = "Half Day (Late Limit)";
            }
        }

        if ($remark) {
            $employeeRemarks["{$empId}_{$date}"] = $remark;
        }
    }

    foreach ($data as &$row) {
        $key = "{$row['EmployeeID']}_{$row['AttendanceDate']}";
        $row['LateRemark'] = $employeeRemarks[$key] ?? '';
    }

    return $data;
}

// --- Request Handlers ---

function handleBatchSave($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data)) { sendJsonResponse(['status' => 'error', 'message' => 'No data received.'], 400); }

    $pdo->beginTransaction();
    $empSql = "INSERT IGNORE INTO Employees (EmployeeID, EmployeeName) VALUES (?, ?)";
    $empStmt = $pdo->prepare($empSql);
    $logSql = "INSERT INTO AttendanceLog (EmployeeID, AttendanceDate, Shift, ScheduledIn, ScheduledOut, ActualIn, ActualOut, WorkDuration, Overtime, TotalDuration, LateBy, EarlyGoingBy, Status, PunchRecords, EarlyByIn, LateByOut, ActualBreak, BreakAdjustment, AdjustedOvertime, AdjustedBreakResult, NetWorkDuration) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE Shift=VALUES(Shift), ScheduledIn=VALUES(ScheduledIn), ScheduledOut=VALUES(ScheduledOut), ActualIn=VALUES(ActualIn), ActualOut=VALUES(ActualOut), WorkDuration=VALUES(WorkDuration), Overtime=VALUES(Overtime), TotalDuration=VALUES(TotalDuration), LateBy=VALUES(LateBy), EarlyGoingBy=VALUES(EarlyGoingBy), Status=VALUES(Status), PunchRecords=VALUES(PunchRecords), EarlyByIn=VALUES(EarlyByIn), LateByOut=VALUES(LateByOut), ActualBreak=VALUES(ActualBreak), BreakAdjustment=VALUES(BreakAdjustment), AdjustedOvertime=VALUES(AdjustedOvertime), AdjustedBreakResult=VALUES(AdjustedBreakResult), NetWorkDuration=VALUES(NetWorkDuration)";
    $logStmt = $pdo->prepare($logSql);
    $processedEmployees = [];

    try {
        foreach ($data as $record) {
            if (!isset($processedEmployees[$record['eCode']])) {
                $empStmt->execute([$record['eCode'], $record['name']]);
                $processedEmployees[$record['eCode']] = true;
            }
            $calculatedRecord = calculateTimeMetrics([
                'ScheduledIn' => $record['sInTime'], 'ScheduledOut' => $record['sOutTime'], 'ActualIn' => $record['aInTime'], 'ActualOut' => $record['aOutTime'], 'PunchRecords' => $record['punchRecords'],
                'WorkDuration' => $record['workDur'], 'Overtime' => $record['ot'], 'TotalDuration' => $record['totDur'], 'LateBy' => $record['lateBy'], 'EarlyGoingBy' => $record['earlyGoingBy'],
            ]);
            $params = [ $record['eCode'], $record['date'], $record['shift'], $record['sInTime'], $record['sOutTime'], $record['aInTime'], $record['aOutTime'], $calculatedRecord['WorkDuration'], $calculatedRecord['Overtime'], $calculatedRecord['TotalDuration'], $calculatedRecord['LateBy'], $calculatedRecord['EarlyGoingBy'], $record['status'], $record['punchRecords'], $calculatedRecord['EarlyByIn'], $calculatedRecord['LateByOut'], $calculatedRecord['ActualBreak'], $calculatedRecord['BreakAdjustment'], $calculatedRecord['AdjustedOvertime'], $calculatedRecord['AdjustedBreakResult'], $calculatedRecord['NetWorkDuration'] ];
            $logStmt->execute($params);
        }
        $pdo->commit();
        sendJsonResponse(['status' => 'success', 'message' => "Batch save complete. Processed " . count($data) . " logs.",]);
    } catch (Exception $e) {
        $pdo->rollBack();
        sendJsonResponse(['status' => 'error', 'message' => 'Batch save failed during transaction.', 'error' => $e->getMessage()], 500);
    }
}

function handleGetRecords($pdo) {
    $employeeName = $_GET['employeeName'] ?? '';
    $dateStart = $_GET['dateStart'] ?? '';
    $dateEnd = $_GET['dateEnd'] ?? '';
    $employeeID = $_GET['employeeID'] ?? '';
    $missedPunchesOnly = ($_GET['missedPunchesOnly'] ?? 'false') === 'true';
    $longBreakOnly = ($_GET['longBreakOnly'] ?? 'false') === 'true'; 
    $statusFilter = $_GET['statusFilter'] ?? '';
    $lateRuleFilter = ($_GET['lateRuleFilter'] ?? 'false') === 'true';
    $remarkFilter = $_GET['remarkFilter'] ?? ''; 

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;

    $sql = "SELECT l.*, e.EmployeeName FROM AttendanceLog l JOIN Employees e ON l.EmployeeID = e.EmployeeID";
    $where = [];
    $params = [];

    if (!empty($employeeName)) { $where[] = "e.EmployeeName LIKE ?"; $params[] = "%$employeeName%"; }
    if (!empty($employeeID)) { $where[] = "l.EmployeeID = ?"; $params[] = $employeeID; }
    if (!empty($dateStart)) { $where[] = "l.AttendanceDate >= ?"; $params[] = $dateStart; }
    if (!empty($dateEnd)) { $where[] = "l.AttendanceDate <= ?"; $params[] = $dateEnd; }
    if (!empty($statusFilter)) {
        if ($statusFilter === 'LeaveOrOff') {
            $where[] = "(l.Status = 'Leave' OR l.Status = 'WeeklyOff' OR l.Status LIKE '%Off%')";
        } else {
            $statusMap = ['Present' => "l.Status LIKE '%Present%'", 'Absent' => "l.Status = 'Absent'", 'Leave' => "l.Status = 'Leave'", 'WeeklyOff' => "l.Status = 'WeeklyOff'"];
            if (isset($statusMap[$statusFilter])) { $where[] = $statusMap[$statusFilter]; }
        }
    }
    if ($missedPunchesOnly) { $where[] = "(l.PunchRecords IS NOT NULL AND l.PunchRecords != '' AND (CHAR_LENGTH(l.PunchRecords) - CHAR_LENGTH(REPLACE(l.PunchRecords, ',', '')) + 1) % 2 != 0)"; }
    if ($longBreakOnly) { $where[] = "l.AdjustedBreakResult IS NOT NULL AND TIME_TO_SEC(l.AdjustedBreakResult) > 3600"; }
    
    if (!empty($where)) { $sql .= " WHERE " . implode(" AND ", $where); }
    
    $sql .= " ORDER BY l.AttendanceDate DESC, e.EmployeeName ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $data = array_map('calculateTimeMetrics', $data);
    
    $calcStart = !empty($dateStart) ? $dateStart : (empty($data) ? date('Y-m-01') : min(array_column($data, 'AttendanceDate')));
    $calcEnd = !empty($dateEnd) ? $dateEnd : (empty($data) ? date('Y-m-t') : max(array_column($data, 'AttendanceDate')));
    
    $data = calculateMonthlyLateRemarks($pdo, $data, $calcStart, $calcEnd);

    if ($lateRuleFilter) {
        $data = array_filter($data, function($row) { return !empty($row['LateRemark']); });
    }
    
    if (!empty($remarkFilter)) {
        $data = array_filter($data, function($row) use ($remarkFilter) {
            if ($remarkFilter === 'Any') return !empty($row['LateRemark']);
            return stripos($row['LateRemark'], $remarkFilter) !== false;
        });
    }

    $totalRecords = count($data);
    $totalPages = ceil($totalRecords / $limit);
    $paginatedData = array_slice($data, $offset, $limit);

    sendJsonResponse([
        'status' => 'success',
        'data' => array_values($paginatedData),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
            'totalRecords' => $totalRecords
        ]
    ]);
}

function handleUpdateRecord($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data) || empty($data['logID'])) { sendJsonResponse(['status' => 'error', 'message' => 'Invalid data.'], 400); }
    $calculatedData = calculateTimeMetrics([
        'ScheduledIn' => $data['ScheduledIn'], 'ScheduledOut' => $data['ScheduledOut'], 'ActualIn' => $data['ActualIn'], 'ActualOut' => $data['ActualOut'], 'PunchRecords' => $data['PunchRecords'],
        'WorkDuration' => $data['WorkDuration'], 'Overtime' => $data['Overtime'], 'TotalDuration' => $data['TotalDuration'], 'LateBy' => $data['LateBy'], 'EarlyGoingBy' => $data['EarlyGoingBy'],
    ]);
    $missedPunch = checkMissedPunch($data['PunchRecords']);
    $sql = "UPDATE AttendanceLog SET Status=?, Shift=?, ScheduledIn=?, ScheduledOut=?, ActualIn=?, ActualOut=?, WorkDuration=?, Overtime=?, TotalDuration=?, LateBy=?, EarlyGoingBy=?, PunchRecords=?, EarlyByIn=?, LateByOut=?, ActualBreak=?, BreakAdjustment=?, AdjustedOvertime=?, AdjustedBreakResult=?, NetWorkDuration=? WHERE LogID=?";
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([
        $data['Status'], $data['Shift'], $data['ScheduledIn'], $data['ScheduledOut'], $data['ActualIn'], $data['ActualOut'], $calculatedData['WorkDuration'], $calculatedData['Overtime'], $calculatedData['TotalDuration'], $calculatedData['LateBy'], $calculatedData['EarlyGoingBy'], $data['PunchRecords'],
        $calculatedData['EarlyByIn'], $calculatedData['LateByOut'], $calculatedData['ActualBreak'], $calculatedData['BreakAdjustment'], $calculatedData['AdjustedOvertime'], $calculatedData['AdjustedBreakResult'], $calculatedData['NetWorkDuration'], $data['logID']
    ]);
    if ($success) {
        $status = $missedPunch ? 'note' : 'success';
        $message = $missedPunch ? 'Record updated, but it still has a missed punch. Check the punches list.' : 'Record updated successfully.';
        sendJsonResponse(['status' => $status, 'message' => $message]);
    } else {
        sendJsonResponse(['status' => 'error', 'message' => 'Failed to update record.'], 500);
    }
}

function handleGetDashboardStats($pdo) {
    $month = $_GET['month'] ?? date('Y-m');
    $employeeID = $_GET['employeeID'] ?? ''; 
    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));

    $stats = [];
    $params = [$startDate, $endDate];
    $whereEmployee = "";
    if (!empty($employeeID)) { $whereEmployee = " AND l.EmployeeID = ? "; $params[] = $employeeID; }
    
    $baseQuery = "FROM AttendanceLog l JOIN Employees e ON l.EmployeeID = e.EmployeeID WHERE l.AttendanceDate BETWEEN ? AND ? {$whereEmployee}";
    
    if (empty($employeeID)) { $stats['totalEmployees'] = $pdo->query("SELECT COUNT(*) FROM Employees")->fetchColumn(); } else { $stats['totalEmployees'] = 1; }
    $stmt = $pdo->prepare("SELECT COUNT(*) {$baseQuery} AND l.Status LIKE '%Present%'"); $stmt->execute($params); $stats['present'] = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) {$baseQuery} AND l.Status = 'Absent'"); $stmt->execute($params); $stats['absent'] = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) {$baseQuery} AND (l.Status = 'Leave' OR l.Status = 'WeeklyOff' OR l.Status LIKE '%Off%')"); $stmt->execute($params); $stats['leaveOrOff'] = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) {$baseQuery} AND (l.PunchRecords IS NOT NULL AND l.PunchRecords != '' AND (CHAR_LENGTH(l.PunchRecords) - CHAR_LENGTH(REPLACE(l.PunchRecords, ',', '')) + 1) % 2 != 0)"); $stmt->execute($params); $stats['missedPunches'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(TIME_TO_SEC(l.LateBy)), 0) {$baseQuery}"); $stmt->execute($params); $stats['totalLateBy'] = secondsToTime($stmt->fetchColumn());
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(TIME_TO_SEC(l.AdjustedOvertime)), 0) {$baseQuery}"); $stmt->execute($params); $stats['totalAdjustedOvertime'] = secondsToTime($stmt->fetchColumn());
    $stmt = $pdo->prepare("SELECT COUNT(*) {$baseQuery} AND l.AdjustedBreakResult IS NOT NULL AND TIME_TO_SEC(l.AdjustedBreakResult) > 3600"); $stmt->execute($params); $stats['longAdjustedBreaks'] = $stmt->fetchColumn();

    // Late Rule Action Count
    $stmt = $pdo->prepare("SELECT l.EmployeeID, l.AttendanceDate, l.LateBy {$baseQuery}");
    $stmt->execute($params);
    $allLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $processedLogs = calculateMonthlyLateRemarks($pdo, $allLogs, $startDate, $endDate);
    $lateActionCount = 0;
    foreach ($processedLogs as $pLog) {
        if (!empty($pLog['LateRemark'])) {
            $lateActionCount++;
        }
    }
    $stats['lateRuleActionCount'] = $lateActionCount;

    sendJsonResponse(['status' => 'success', 'stats' => $stats]);
}

function handleGetEmployeeAttendanceSummary($pdo) {
    $month = $_GET['month'] ?? date('Y-m');
    $employeeID = $_GET['employeeID'] ?? ''; 
    if (empty($employeeID)) { sendJsonResponse(['status' => 'error', 'message' => 'Employee ID is required.'], 400); }
    $startDate = $month . '-01'; $endDate = date('Y-m-t', strtotime($startDate));
    $sql = "SELECT AttendanceDate, TIME_TO_SEC(NetWorkDuration) AS workSeconds, TIME_TO_SEC(AdjustedOvertime) AS otSeconds, TIME_TO_SEC(ActualBreak) AS breakSeconds, TIME_TO_SEC(EarlyByIn) AS earlyInSeconds, TIME_TO_SEC(LateByOut) AS lateOutSeconds, TIME_TO_SEC(LateBy) AS lateBySeconds, TIME_TO_SEC(EarlyGoingBy) AS earlyGoingBySeconds FROM AttendanceLog WHERE EmployeeID = ? AND AttendanceDate BETWEEN ? AND ? ORDER BY AttendanceDate ASC";
    $stmt = $pdo->prepare($sql); $stmt->execute([$employeeID, $startDate, $endDate]); $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $processedData = array_map(function($row) { return [ 'AttendanceDate' => $row['AttendanceDate'], 'workHours' => round($row['workSeconds'] / 3600, 2), 'otHours' => round($row['otSeconds'] / 3600, 2), 'breakHours' => round($row['breakSeconds'] / 3600, 2), 'earlyInMinutes' => round($row['earlyInSeconds'] / 60, 0), 'lateOutMinutes' => round($row['lateOutSeconds'] / 60, 0), 'lateByMinutes' => round($row['lateBySeconds'] / 60, 0), 'earlyGoingByMinutes' => round($row['earlyGoingBySeconds'] / 60, 0), ]; }, $data);
    sendJsonResponse(['status' => 'success', 'data' => $processedData]);
}

function handleGetEmployees($pdo) {
    $stmt = $pdo->query("SELECT EmployeeID, EmployeeName FROM Employees ORDER BY EmployeeName ASC");
    sendJsonResponse(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handleGetMonthlyAttendance($pdo) {
    $month = $_GET['month'] ?? date('Y-m');
    $startDate = $month . '-01'; $endDate = date('Y-m-t', strtotime($startDate));
    $sql = "SELECT AttendanceDate, SUM(CASE WHEN Status LIKE '%Present%' THEN 1 ELSE 0 END) AS present, SUM(CASE WHEN Status = 'Absent' THEN 1 ELSE 0 END) AS absent, SUM(CASE WHEN Status = 'Leave' OR Status = 'WeeklyOff' OR Status LIKE '%Off%' THEN 1 ELSE 0 END) AS leaveOrOff FROM AttendanceLog WHERE AttendanceDate BETWEEN ? AND ? GROUP BY AttendanceDate ORDER BY AttendanceDate ASC";
    $stmt = $pdo->prepare($sql); $stmt->execute([$startDate, $endDate]); $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $filledData = []; $currentDate = new DateTime($startDate); $endDateTime = new DateTime($endDate); $dataMap = [];
    foreach ($data as $row) { $dataMap[$row['AttendanceDate']] = $row; }
    while ($currentDate <= $endDateTime) { $dateStr = $currentDate->format('Y-m-d'); if (isset($dataMap[$dateStr])) { $filledData[] = $dataMap[$dateStr]; } else { if ($currentDate->format('N') != 7) { $filledData[] = [ 'AttendanceDate' => $dateStr, 'present' => 0, 'absent' => 0, 'leaveOrOff' => 0 ]; } } $currentDate->modify('+1 day'); }
    sendJsonResponse(['status' => 'success', 'data' => $filledData]);
}

function handleGetBreakAbuseStats($pdo) {
    $month = $_GET['month'] ?? date('Y-m');
    $startDate = $month . '-01'; $endDate = date('Y-m-t', strtotime($startDate));
    $sql = "SELECT e.EmployeeName, e.EmployeeID, COALESCE(SUM(TIME_TO_SEC(l.AdjustedBreakResult)), 0) AS totalAdjustedBreakSeconds, COUNT(l.LogID) AS totalDays FROM AttendanceLog l JOIN Employees e ON l.EmployeeID = e.EmployeeID WHERE l.AttendanceDate BETWEEN ? AND ? AND l.Status LIKE '%Present%' GROUP BY e.EmployeeID, e.EmployeeName ORDER BY totalAdjustedBreakSeconds DESC LIMIT 5";
    $stmt = $pdo->prepare($sql); $stmt->execute([$startDate, $endDate]); $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data = array_map(function($row) { $totalMinutes = round($row['totalAdjustedBreakSeconds'] / 60, 0); $avgBreakMinutes = $row['totalDays'] > 0 ? round($totalMinutes / $row['totalDays'], 0) : 0; return [ 'EmployeeName' => $row['EmployeeName'], 'totalAdjustedBreakMinutes' => $totalMinutes, 'avgBreakMinutes' => $avgBreakMinutes ]; }, $data);
    sendJsonResponse(['status' => 'success', 'data' => $data]);
}

function handleExportData($pdo) {
    $dateStart = $_GET['dateStart'] ?? null; $dateEnd = $_GET['dateEnd'] ?? null;
    if (empty($dateStart) || empty($dateEnd)) { sendJsonResponse(['status' => 'error', 'message' => 'Start date and end date are required.'], 400); }
    
    // We need to inject the LateRemark into the export
    $sql = "SELECT l.*, e.EmployeeName FROM AttendanceLog l JOIN Employees e ON l.EmployeeID = e.EmployeeID WHERE l.AttendanceDate BETWEEN ? AND ? ORDER BY l.AttendanceDate ASC, e.EmployeeName ASC";
    $stmt = $pdo->prepare($sql); $stmt->execute([$dateStart, $dateEnd]); $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data = array_map('calculateTimeMetrics', $data);
    
    // Apply Late Remark Logic
    $data = calculateMonthlyLateRemarks($pdo, $data, $dateStart, $dateEnd);

    sendJsonResponse(['status' => 'success', 'data' => $data]);
}

function handleGetShiftDefinitions($pdo) {
    $stmt = $pdo->query("SELECT e.EmployeeID, e.EmployeeName, s.ShiftName, s.DefaultScheduledIn, s.DefaultScheduledOut FROM Employees e LEFT JOIN ShiftDefinitions s ON e.EmployeeID = s.EmployeeID ORDER BY e.EmployeeName, s.ShiftName");
    $rawDefinitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $employeeShifts = [];
    foreach ($rawDefinitions as $row) { if (!isset($employeeShifts[$row['EmployeeID']])) { $employeeShifts[$row['EmployeeID']] = [ 'EmployeeID' => $row['EmployeeID'], 'EmployeeName' => $row['EmployeeName'], 'Shifts' => [] ]; } if ($row['ShiftName']) { $employeeShifts[$row['EmployeeID']]['Shifts'][] = [ 'ShiftName' => $row['ShiftName'], 'DefaultScheduledIn' => $row['DefaultScheduledIn'], 'DefaultScheduledOut' => $row['DefaultScheduledOut'] ]; } }
    sendJsonResponse(['status' => 'success', 'data' => array_values($employeeShifts)]);
}

function handleSaveShiftDefinitions($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['EmployeeID']) || empty($data['ShiftName']) || empty($data['DefaultScheduledIn']) || empty($data['DefaultScheduledOut'])) { sendJsonResponse(['status' => 'error', 'message' => 'Missing required shift data.'], 400); }
    $sql = "INSERT INTO ShiftDefinitions (EmployeeID, ShiftName, DefaultScheduledIn, DefaultScheduledOut) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE DefaultScheduledIn = VALUES(DefaultScheduledIn), DefaultScheduledOut = VALUES(DefaultScheduledOut)";
    try { $stmt = $pdo->prepare($sql); $stmt->execute([$data['EmployeeID'], $data['ShiftName'], $data['DefaultScheduledIn'], $data['DefaultScheduledOut']]); sendJsonResponse(['status' => 'success', 'message' => 'Shift saved successfully.']); } catch (Exception $e) { sendJsonResponse(['status' => 'error', 'message' => 'Failed to save shift.'], 500); }
}

function handleGetDoublePunches($pdo) {
    $dateStart = $_GET['dateStart'] ?? '';
    $dateEnd = $_GET['dateEnd'] ?? '';
    $employeeID = $_GET['employeeID'] ?? '';

    $where = [];
    $params = [];

    // Filter to ensure we only look at records with punches
    $where[] = "l.PunchRecords IS NOT NULL AND l.PunchRecords != ''";

    if (!empty($dateStart)) { $where[] = "l.AttendanceDate >= ?"; $params[] = $dateStart; }
    if (!empty($dateEnd)) { $where[] = "l.AttendanceDate <= ?"; $params[] = $dateEnd; }
    if (!empty($employeeID)) { $where[] = "l.EmployeeID = ?"; $params[] = $employeeID; }

    $sql = "SELECT l.LogID, l.EmployeeID, l.AttendanceDate, l.PunchRecords, e.EmployeeName 
            FROM AttendanceLog l 
            JOIN Employees e ON l.EmployeeID = e.EmployeeID 
            WHERE " . implode(" AND ", $where) . " 
            ORDER BY l.AttendanceDate DESC, e.EmployeeName ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $doublePunchIncidents = [];

    foreach ($records as $record) {
        $punchString = $record['PunchRecords'];
        if (empty($punchString)) continue;

        $punches = explode(',', $punchString);
        $punchesData = [];
        foreach ($punches as $punch) {
            $parts = explode(':', $punch);
            if (count($parts) >= 3) { // Expecting HH:MM:SS or HH:MM:SS:type
                $timeStr = implode(':', array_slice($parts, 0, 3));
                $type = count($parts) >= 4 ? $parts[3] : 'unknown';
                $punchesData[] = ['timeStr' => $timeStr, 'type' => $type, 'seconds' => timeToSeconds($timeStr)];
            }
        }

        // Sort by time
        usort($punchesData, function($a, $b) {
            return $a['seconds'] - $b['seconds'];
        });

        // Scan for double punches (<= 60 seconds diff)
        for ($i = 0; $i < count($punchesData) - 1; $i++) {
            $p1 = $punchesData[$i];
            $p2 = $punchesData[$i+1];
            $diff = abs($p2['seconds'] - $p1['seconds']);

            if ($diff <= 60) {
                $doublePunchIncidents[] = [
                    'LogID' => $record['LogID'],
                    'EmployeeID' => $record['EmployeeID'],
                    'EmployeeName' => $record['EmployeeName'],
                    'AttendanceDate' => $record['AttendanceDate'],
                    'Punch1' => $p1,
                    'Punch2' => $p2,
                    'GapSeconds' => $diff
                ];
                // Skip next one to avoid chaining too much, or allow chaining? 
                // Allowing chaining (A, B close; B, C close) is fine.
            }
        }
    }

    sendJsonResponse(['status' => 'success', 'data' => $doublePunchIncidents]);
}

function handleDeleteSpecificPunch($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['logID']) || empty($data['punchTime'])) {
        sendJsonResponse(['status' => 'error', 'message' => 'Missing logID or punchTime.'], 400);
    }
    
    $logID = $data['logID'];
    $punchTime = $data['punchTime']; // Expected HH:MM:SS
    $punchType = $data['punchType'] ?? null; // Optional check

    // 1. Fetch Record
    $stmt = $pdo->prepare("SELECT * FROM AttendanceLog WHERE LogID = ?");
    $stmt->execute([$logID]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        sendJsonResponse(['status' => 'error', 'message' => 'Record not found.'], 404);
    }

    // 2. Parse Punches
    $punchString = $record['PunchRecords'];
    if (empty($punchString)) {
        sendJsonResponse(['status' => 'error', 'message' => 'No punches to delete.'], 400);
    }

    $punches = explode(',', $punchString);
    $newPunches = [];
    $deleted = false;

    // 3. Filter out the punch
    // Strategy: Rebuild the array, skipping the first match.
    foreach ($punches as $p) {
        if ($deleted) {
            $newPunches[] = $p;
            continue;
        }

        $parts = explode(':', $p);
        if (count($parts) >= 3) {
            $pTime = implode(':', array_slice($parts, 0, 3));
            $pType = count($parts) >= 4 ? $parts[3] : null;

            // Check match
            // If type is provided, match both. If not, match time.
            $timeMatch = ($pTime === $punchTime);
            $typeMatch = ($punchType === null || $pType === $punchType);

            if ($timeMatch && $typeMatch) {
                $deleted = true; // Skip this one
                continue;
            }
        }
        $newPunches[] = $p;
    }

    if (!$deleted) {
        sendJsonResponse(['status' => 'error', 'message' => 'Punch not found in record.'], 404);
    }

    $newPunchString = implode(',', $newPunches);

    // 4. Determine new ActualIn / ActualOut
    // We need to re-scan the new punch list to find min and max
    $tempPunchesData = [];
    foreach ($newPunches as $p) {
        $parts = explode(':', $p);
        if (count($parts) >= 3) {
             $timePart = implode(':', array_slice($parts, 0, 3));
             $tempPunchesData[] = $timePart;
        }
    }
    usort($tempPunchesData, function($a, $b) {
        return timeToSeconds($a) - timeToSeconds($b);
    });

    $newActualIn = count($tempPunchesData) > 0 ? $tempPunchesData[0] : null;
    $newActualOut = count($tempPunchesData) > 1 ? $tempPunchesData[count($tempPunchesData) - 1] : null;


    // 5. Recalculate Metrics
    $inputForCalc = [
        'ScheduledIn' => $record['ScheduledIn'],
        'ScheduledOut' => $record['ScheduledOut'],
        'ActualIn' => $newActualIn,
        'ActualOut' => $newActualOut,
        'PunchRecords' => $newPunchString,
        // Legacy fields might be updated by calc function, but we pass current ones just in case
        'WorkDuration' => $record['WorkDuration'], 
        'Overtime' => $record['Overtime'], 
        'TotalDuration' => $record['TotalDuration'], 
        'LateBy' => $record['LateBy'], 
        'EarlyGoingBy' => $record['EarlyGoingBy']
    ];

    $calculated = calculateTimeMetrics($inputForCalc);
    $missedPunch = checkMissedPunch($newPunchString);

    // 6. Update DB
    $updateSql = "UPDATE AttendanceLog SET 
        PunchRecords=?, ActualIn=?, ActualOut=?, 
        WorkDuration=?, Overtime=?, TotalDuration=?, 
        LateBy=?, EarlyGoingBy=?, EarlyByIn=?, LateByOut=?, 
        ActualBreak=?, BreakAdjustment=?, AdjustedOvertime=?, 
        AdjustedBreakResult=?, NetWorkDuration=?, Status=?
        WHERE LogID=?";
    
    // Determine status (simple logic: if no punches, Absent? Or keep existing? 
    // Usually status is manual or derived. Let's keep existing status unless it was Present and now no punches?)
    // For safety, let's keep the status passed or existing, maybe update if needed.
    // The previous update logic took Status from input. Here we might want to preserve it or update it.
    // If we have punches, ensure it is likely 'Present' or similar. 
    // If we delete all punches, maybe set to Absent?
    // Let's keep it simple: Keep existing status unless empty.
    
    $newStatus = $record['Status'];
    if (empty($newPunches) && stripos($newStatus, 'Present') !== false) {
        $newStatus = 'Absent'; // Fallback if all punches deleted
    }

    $updateStmt = $pdo->prepare($updateSql);
    $success = $updateStmt->execute([
        $newPunchString, $newActualIn, $newActualOut,
        $calculated['WorkDuration'], $calculated['Overtime'], $calculated['TotalDuration'],
        $calculated['LateBy'], $calculated['EarlyGoingBy'], $calculated['EarlyByIn'], $calculated['LateByOut'],
        $calculated['ActualBreak'], $calculated['BreakAdjustment'], $calculated['AdjustedOvertime'],
        $calculated['AdjustedBreakResult'], $calculated['NetWorkDuration'], $newStatus,
        $logID
    ]);

    if ($success) {
        sendJsonResponse(['status' => 'success', 'message' => 'Punch deleted and record updated.']);
    } else {
        sendJsonResponse(['status' => 'error', 'message' => 'Failed to update record in database.'], 500);
    }
}