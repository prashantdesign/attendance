<?php
// admin_api.php - FINAL VERSION

// Start output buffering IMMEDIATELY to capture potential PHP errors/warnings that break JSON parsing
ob_start();

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Set session lifetime to 12 hours (43200 seconds)
$session_lifetime = 43200; 
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params($session_lifetime);

date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- START: MODIFIED HEADERS FOR SESSION PERSISTENCE ---
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');    // cache for 1 day
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}
// --- END: MODIFIED HEADERS ---

require 'db_connect.php';

// Define the user to be excluded (case-insensitive)
const EXCLUDED_USER_FULL_NAME = 'Jaymin Vyas';

function send_json_response($success, $message, $data = null) {
    // Clear the buffer of any previous output (warnings, notices, HTML)
    if (ob_get_length() > 0) {
        ob_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    // End output buffering and flush output
    ob_end_flush();
    exit;
}

// --- NEW FUNCTION: Log a notification for the user ---
function log_user_notification($db, $user_id, $type, $related_id = NULL) {
    // Check against the database user list to ensure only active employees receive notifications
    $stmt_check = $db->prepare("SELECT COUNT(*) FROM users WHERE user_id = ?");
    $stmt_check->bind_param("s", $user_id);
    $stmt_check->execute();
    $stmt_check->bind_result($count);
    $stmt_check->fetch();
    $stmt_check->close();
    
    if ($count == 0) return; // Do not log if user doesn't exist.

    $stmt = $db->prepare("INSERT INTO leave_notifications (user_id, related_id, type) VALUES (?, ?, ?)");
    $stmt->bind_param("sis", $user_id, $related_id, $type);
    $stmt->execute();
    $stmt->close();
}

function check_admin() {
    if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
        header("HTTP/1.1 401 Unauthorized");
        send_json_response(false, 'Unauthorized: Admin access required.');
    }
}

function normalize_time_value($time, $default) {
    if (!$time || !preg_match('/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
        return $default;
    }
    return strlen($time) === 5 ? $time . ':00' : $time;
}

function sanitize_location_setting_input($request_body) {
    $global_enabled = !empty($request_body['global_enabled']) ? 1 : 0;
    $office_latitude = isset($request_body['office_latitude']) ? floatval($request_body['office_latitude']) : null;
    $office_longitude = isset($request_body['office_longitude']) ? floatval($request_body['office_longitude']) : null;
    $office_radius_meters = isset($request_body['office_radius_meters']) ? intval($request_body['office_radius_meters']) : 150;

    if ($global_enabled === 1) {
        if ($office_latitude === null || $office_longitude === null) {
            send_json_response(false, 'Office latitude and longitude are required when global location attendance is enabled.');
        }
        if ($office_latitude < -90 || $office_latitude > 90 || $office_longitude < -180 || $office_longitude > 180) {
            send_json_response(false, 'Invalid office coordinates.');
        }
    }

    if ($office_radius_meters < 20 || $office_radius_meters > 5000) {
        send_json_response(false, 'Office radius must be between 20 and 5000 meters.');
    }

    return [
        'global_enabled' => $global_enabled,
        'office_latitude' => $office_latitude,
        'office_longitude' => $office_longitude,
        'office_radius_meters' => $office_radius_meters,
        'half_day_first_half_cutoff_ist' => normalize_time_value($request_body['half_day_first_half_cutoff_ist'] ?? null, '14:00:00'),
        'half_day_second_half_cutoff_ist' => normalize_time_value($request_body['half_day_second_half_cutoff_ist'] ?? null, '18:30:00')
    ];
}

$action = $_GET['action'] ?? '';
$request_body = json_decode(file_get_contents('php://input'), true);

switch ($action) {
    case 'check_connection':
        send_json_response(true, 'Database connection successful.');
        break;

    case 'login':
        $user_id = $request_body['user_id'] ?? '';
        $password = $request_body['password'] ?? '';
        if (empty($user_id) || empty($password)) {
            send_json_response(false, 'User ID and password are required.');
        }
        $stmt = $db->prepare("SELECT user_id, full_name, password FROM users WHERE user_id = ? AND is_admin = 1");
        if (!$stmt) send_json_response(false, "SQL Error: " . $db->error);
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $stmt->bind_result($db_user_id, $db_full_name, $db_password_hash);
        $stmt->fetch();
        $stmt->close();
        if ($db_user_id && password_verify($password, $db_password_hash)) {
            // Regenerate session ID and set variables for 12-hour persistence
            session_regenerate_id(true);
            $_SESSION['user_id'] = $db_user_id;
            $_SESSION['full_name'] = $db_full_name;
            $_SESSION['is_admin'] = true;
            send_json_response(true, 'Admin login successful.');
        } else {
            send_json_response(false, 'Invalid credentials or not an admin.');
        }
        break;

    case 'logout':
        session_unset();
        session_destroy();
        send_json_response(true, 'Logout successful.');
        break;

    case 'get_user_list':
        check_admin();
        $stmt = $db->prepare("SELECT user_id, full_name FROM users ORDER BY full_name");
        if (!$stmt) send_json_response(false, "SQL Error (get_user_list): " . $db->error);
        $stmt->execute();
        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // --- EXCLUSION LOGIC ---
        $filtered_users = array_filter($users, function($user) {
            return strcasecmp($user['full_name'], EXCLUDED_USER_FULL_NAME) !== 0;
        });
        
        send_json_response(true, 'User list fetched.', array_values($filtered_users));
        break;
        
    case 'get_all_users_details':
        check_admin();
        $query = "SELECT u.user_id, u.full_name, u.is_admin, p.tracked_since, p.monthly_leave_allowance, p.initial_leaves, p.bonus_leaves FROM users u LEFT JOIN profiles p ON u.user_id = p.user_id ORDER BY u.full_name ASC";
        $result = $db->query($query);
        if (!$result) send_json_response(false, "SQL Error (get_all_users_details): " . $db->error);
        $users = $result->fetch_all(MYSQLI_ASSOC);

        // --- EXCLUSION LOGIC ---
        $filtered_users = array_filter($users, function($user) {
            return strcasecmp($user['full_name'], EXCLUDED_USER_FULL_NAME) !== 0;
        });

        send_json_response(true, "All user details fetched.", array_values($filtered_users));
        break;

    case 'get_location_settings':
        check_admin();
        $stmt = $db->prepare("SELECT id, global_enabled, office_latitude, office_longitude, office_radius_meters, half_day_first_half_cutoff_ist, half_day_second_half_cutoff_ist, updated_by, updated_at FROM location_attendance_settings WHERE id = 1 LIMIT 1");
        if (!$stmt) send_json_response(false, "SQL Error (get_location_settings): " . $db->error);
        $stmt->execute();
        $result = $stmt->get_result();
        $settings = $result->fetch_assoc();
        $stmt->close();

        if (!$settings) {
            $settings = [
                'id' => 1,
                'global_enabled' => 0,
                'office_latitude' => null,
                'office_longitude' => null,
                'office_radius_meters' => 150,
                'half_day_first_half_cutoff_ist' => '14:00:00',
                'half_day_second_half_cutoff_ist' => '18:30:00',
                'updated_by' => null,
                'updated_at' => null
            ];
        }
        send_json_response(true, 'Location settings fetched.', $settings);
        break;

    case 'save_location_settings':
        check_admin();
        $clean = sanitize_location_setting_input($request_body ?? []);
        $updated_by = $_SESSION['user_id'] ?? 'system';

        $stmt = $db->prepare("INSERT INTO location_attendance_settings (id, global_enabled, office_latitude, office_longitude, office_radius_meters, half_day_first_half_cutoff_ist, half_day_second_half_cutoff_ist, updated_by) VALUES (1, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE global_enabled=VALUES(global_enabled), office_latitude=VALUES(office_latitude), office_longitude=VALUES(office_longitude), office_radius_meters=VALUES(office_radius_meters), half_day_first_half_cutoff_ist=VALUES(half_day_first_half_cutoff_ist), half_day_second_half_cutoff_ist=VALUES(half_day_second_half_cutoff_ist), updated_by=VALUES(updated_by)");
        if (!$stmt) send_json_response(false, "SQL Error (save_location_settings): " . $db->error);
        $stmt->bind_param(
            "iddisss",
            $clean['global_enabled'],
            $clean['office_latitude'],
            $clean['office_longitude'],
            $clean['office_radius_meters'],
            $clean['half_day_first_half_cutoff_ist'],
            $clean['half_day_second_half_cutoff_ist'],
            $updated_by
        );

        if (!$stmt->execute()) {
            send_json_response(false, 'Failed to save location settings: ' . $stmt->error);
        }
        $stmt->close();
        send_json_response(true, 'Location settings saved successfully.');
        break;

    case 'set_user_location_attendance':
        check_admin();
        $target_user_id = $request_body['user_id'] ?? '';
        $location_attendance_enabled = !empty($request_body['location_attendance_enabled']) ? 1 : 0;
        $background_tracking_required = !empty($request_body['background_tracking_required']) ? 1 : 0;
        if (empty($target_user_id)) {
            send_json_response(false, 'user_id is required.');
        }

        $stmt_user = $db->prepare("SELECT full_name FROM users WHERE user_id = ? LIMIT 1");
        if (!$stmt_user) send_json_response(false, "SQL Error (set_user_location_attendance/check): " . $db->error);
        $stmt_user->bind_param("s", $target_user_id);
        $stmt_user->execute();
        $stmt_user->bind_result($target_full_name);
        $found = $stmt_user->fetch();
        $stmt_user->close();
        if (!$found) {
            send_json_response(false, 'User not found.');
        }
        if (strcasecmp($target_full_name, EXCLUDED_USER_FULL_NAME) === 0) {
            send_json_response(false, 'Cannot update location attendance for this user due to exclusion rules.');
        }

        $updated_by = $_SESSION['user_id'] ?? 'system';
        $stmt = $db->prepare("INSERT INTO user_location_prefs (user_id, location_attendance_enabled, background_tracking_required, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE location_attendance_enabled=VALUES(location_attendance_enabled), background_tracking_required=VALUES(background_tracking_required), updated_by=VALUES(updated_by)");
        if (!$stmt) send_json_response(false, "SQL Error (set_user_location_attendance): " . $db->error);
        $stmt->bind_param("siis", $target_user_id, $location_attendance_enabled, $background_tracking_required, $updated_by);
        if (!$stmt->execute()) {
            send_json_response(false, 'Failed to update user location preference: ' . $stmt->error);
        }
        $stmt->close();
        send_json_response(true, 'User location attendance preference updated successfully.');
        break;

    case 'get_user_location_attendance_list':
        check_admin();
        $query = "SELECT u.user_id, u.full_name, COALESCE(ulp.location_attendance_enabled, 0) AS location_attendance_enabled, COALESCE(ulp.background_tracking_required, 1) AS background_tracking_required, ulp.updated_by, ulp.updated_at FROM users u LEFT JOIN user_location_prefs ulp ON u.user_id = ulp.user_id ORDER BY u.full_name ASC";
        $result = $db->query($query);
        if (!$result) send_json_response(false, "SQL Error (get_user_location_attendance_list): " . $db->error);
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $filtered = array_filter($rows, function($row) {
            return strcasecmp($row['full_name'], EXCLUDED_USER_FULL_NAME) !== 0;
        });
        send_json_response(true, 'User location attendance list fetched.', array_values($filtered));
        break;

    case 'add_user':
        check_admin();
        $user_id = $request_body['user_id'] ?? '';
        $password = $request_body['password'] ?? '';
        $full_name = $request_body['full_name'] ?? '';
        $is_admin = $request_body['is_admin'] ?? 0;
        $tracked_since = $request_body['tracked_since'] ? date('Y-m-d', strtotime($request_body['tracked_since'])) : date('Y-m-d');
        $monthly_allowance = $request_body['monthly_leave_allowance'] ?? 2;

        if (empty($user_id) || empty($password) || empty($full_name)) { send_json_response(false, 'User ID, Password, and Full Name are required.'); }
        
        // Prevent adding a user with the excluded name
        if (strcasecmp($full_name, EXCLUDED_USER_FULL_NAME) === 0) {
             send_json_response(false, 'Cannot create user with this name due to exclusion rules.');
        }

        $stmt_check = $db->prepare("SELECT user_id FROM users WHERE user_id = ?");
        if (!$stmt_check) send_json_response(false, "SQL Error (add_user/check): " . $db->error);
        $stmt_check->bind_param("s", $user_id);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) { send_json_response(false, 'This User ID already exists.'); }
        $stmt_check->close();
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $db->begin_transaction();
        try {
            $stmt_user = $db->prepare("INSERT INTO users (user_id, password, full_name, is_admin) VALUES (?, ?, ?, ?)");
            if (!$stmt_user) throw new Exception("SQL Error (add_user/user): " . $db->error);
            $stmt_user->bind_param("sssi", $user_id, $hashed_password, $full_name, $is_admin);
            $stmt_user->execute();
            $stmt_user->close();

            $stmt_profile = $db->prepare("INSERT INTO profiles (user_id, tracked_since, monthly_leave_allowance, initial_leaves, bonus_leaves) VALUES (?, ?, ?, '[]', '[]')");
            if (!$stmt_profile) throw new Exception("SQL Error (add_user/profile): " . $db->error);
            $stmt_profile->bind_param("ssd", $user_id, $tracked_since, $monthly_allowance);
            $stmt_profile->execute();
            $stmt_profile->close();
            
            $db->commit();
            send_json_response(true, 'New employee created successfully.');
        } catch (Exception $e) {
            $db->rollback();
            send_json_response(false, 'Failed to create new employee: ' . $e->getMessage());
        }
        break;
        
    case 'update_user':
        check_admin();
        $user_id = $request_body['user_id'] ?? '';
        $full_name = $request_body['full_name'] ?? '';
        $new_password = $request_body['password'] ?? '';
        $is_admin = $request_body['is_admin'] ?? 0;
        $tracked_since = $request_body['tracked_since'] ? date('Y-m-d', strtotime($request_body['tracked_since'])) : date('Y-m-d');
        $monthly_allowance = $request_body['monthly_leave_allowance'] ?? 2;

        if (empty($user_id) || empty($full_name)) { send_json_response(false, 'User ID and Full Name are required.'); }
        
        $db->begin_transaction();
        try {
            if (!empty($new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt_user = $db->prepare("UPDATE users SET full_name = ?, password = ?, is_admin = ? WHERE user_id = ?");
                if (!$stmt_user) throw new Exception("SQL Error (update_user/user/pass): " . $db->error);
                $stmt_user->bind_param("ssis", $full_name, $hashed_password, $is_admin, $user_id);
            } else {
                $stmt_user = $db->prepare("UPDATE users SET full_name = ?, is_admin = ? WHERE user_id = ?");
                if (!$stmt_user) throw new Exception("SQL Error (update_user/user/no-pass): " . $db->error);
                $stmt_user->bind_param("sis", $full_name, $is_admin, $user_id);
            }
            $stmt_user->execute();
            $stmt_user->close();

            $stmt_profile = $db->prepare("INSERT INTO profiles (user_id, tracked_since, monthly_leave_allowance) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE tracked_since=VALUES(tracked_since), monthly_leave_allowance=VALUES(monthly_leave_allowance)");
            if (!$stmt_profile) throw new Exception("SQL Error (update_user/profile): " . $db->error);
            $stmt_profile->bind_param("ssd", $user_id, $tracked_since, $monthly_allowance);
            $stmt_profile->execute();
            $stmt_profile->close();

            $db->commit();
            send_json_response(true, 'Employee details updated successfully.');
        } catch (Exception $e) {
            $db->rollback();
            send_json_response(false, 'Failed to update employee details: ' . $e->getMessage());
        }
        break;

    case 'adjust_leave_balance':
        check_admin();
        $user_id = $request_body['user_id'] ?? null;
        $adjustment_value = $request_body['value'] ?? 0;
        $reason = $request_body['reason'] ?? null;
        
        if (!$user_id || empty($reason) || !is_numeric($adjustment_value)) {
            send_json_response(false, 'User ID, a numeric adjustment value, and a mandatory reason are required.');
        }

        $stmt = $db->prepare("SELECT bonus_leaves, full_name FROM profiles p JOIN users u ON p.user_id = u.user_id WHERE p.user_id = ?");
        if (!$stmt) send_json_response(false, "SQL Error (adjust_leave_balance/fetch): " . $db->error);
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $stmt->bind_result($bonus_leaves_json, $full_name);
        $stmt->fetch();
        $stmt->close();

        // --- EXCLUSION CHECK ---
        if (strcasecmp($full_name, EXCLUDED_USER_FULL_NAME) === 0) {
            send_json_response(false, 'Cannot adjust leave for this user due to exclusion rules.');
        }
        
        $bonus_leaves = $bonus_leaves_json ? json_decode($bonus_leaves_json, true) : [];
        if (json_last_error() !== JSON_ERROR_NONE) { $bonus_leaves = []; }
        
        // Add the new adjustment entry with mandatory reason
        $bonus_leaves[] = [ 
            "count" => floatval($adjustment_value), 
            "note" => $reason . " (Adjusted by admin on " . date('Y-m-d H:i:s') . ")" 
        ];
        $new_bonus_leaves_json = json_encode($bonus_leaves);

        $db->begin_transaction();
        try {
             $stmt_update = $db->prepare("UPDATE profiles SET bonus_leaves = ? WHERE user_id = ?");
            if (!$stmt_update) throw new Exception("SQL Error (adjust_leave_balance/update): " . $db->error);
            $stmt_update->bind_param("ss", $new_bonus_leaves_json, $user_id);
            $stmt_update->execute();
            $stmt_update->close();
            
            // Log notification for the user
            log_user_notification($db, $user_id, 'adjustment');
            
            $db->commit();
            send_json_response(true, 'Leave balance adjusted successfully. Reason recorded.');
        } catch (Exception $e) {
            $db->rollback();
            send_json_response(false, 'Failed to adjust leave balance: ' . $e->getMessage());
        }
        break;

    case 'get_admin_data':
        check_admin();
        $profiles = [];
        $attendance = [];

        // Fetch users to apply exclusion logic
        $user_query = $db->query("SELECT user_id, full_name FROM users");
        if (!$user_query) send_json_response(false, "SQL Error (get_admin_data/users): " . $db->error);
        $all_users = $user_query->fetch_all(MYSQLI_ASSOC);
        $allowed_user_ids = array_column(array_filter($all_users, function($user) {
            return strcasecmp($user['full_name'], EXCLUDED_USER_FULL_NAME) !== 0;
        }), 'user_id');
        
        $user_id_placeholders = implode(',', array_fill(0, count($allowed_user_ids), '?'));

        if (!empty($allowed_user_ids)) {
             // Fetch Profiles for allowed users
            $stmt_profiles = $db->prepare("SELECT user_id, tracked_since, monthly_leave_allowance, initial_leaves, bonus_leaves FROM profiles WHERE user_id IN ($user_id_placeholders)");
            if (!$stmt_profiles) send_json_response(false, "SQL Error (get_admin_data/profiles): " . $db->error);
            $stmt_profiles->bind_param(str_repeat('s', count($allowed_user_ids)), ...$allowed_user_ids);
            $stmt_profiles->execute();
            $result = $stmt_profiles->get_result();
            while($row = $result->fetch_assoc()) { $profiles[$row['user_id']] = $row; }
            $stmt_profiles->close();
            
            // Fetch Attendance for allowed users
            $stmt_attendance = $db->prepare("SELECT id, user_id, attendance_date, login_time, logout_time, status, breaks FROM attendance WHERE user_id IN ($user_id_placeholders) ORDER BY attendance_date ASC");
            if (!$stmt_attendance) send_json_response(false, "SQL Error (get_admin_data/attendance): " . $db->error);
            $stmt_attendance->bind_param(str_repeat('s', count($allowed_user_ids)), ...$allowed_user_ids);
            $stmt_attendance->execute();
            $result = $stmt_attendance->get_result();
            while($row = $result->fetch_assoc()) {
                if (!isset($attendance[$row['user_id']])) $attendance[$row['user_id']] = [];
                $attendance[$row['user_id']][$row['attendance_date']] = $row;
            }
            $stmt_attendance->close();
        }

        send_json_response(true, 'Admin data fetched', [ 'profiles' => $profiles, 'attendance' => $attendance, 'current_admin' => ['user_id' => $_SESSION['user_id'], 'full_name' => $_SESSION['full_name']] ]);
        break;
        
    case 'update_attendance':
        check_admin();
        $user_id = $request_body['user_id'] ?? '';
        
        // Fetch user name for exclusion check
        $stmt_name = $db->prepare("SELECT full_name FROM users WHERE user_id = ?");
        if (!$stmt_name) send_json_response(false, "SQL Error (update_attendance/name): " . $db->error);
        $stmt_name->bind_param("s", $user_id);
        $stmt_name->execute();
        $stmt_name->bind_result($full_name);
        $stmt_name->fetch();
        $stmt_name->close();
        
        // --- EXCLUSION CHECK (Safety layer) ---
        if (strcasecmp($full_name, EXCLUDED_USER_FULL_NAME) === 0) {
            send_json_response(false, 'Cannot modify attendance for this user due to exclusion rules.');
        }

        $date = $request_body['date'] ?? '';
        $status = $request_body['status'] ?? '';
        $login_time_str = $request_body['login_time'] ?? '';   // e.g., "09:30"
        $logout_time_str = $request_body['logout_time'] ?? ''; // e.g., "18:30"

        if (empty($user_id) || empty($date) || empty($status)) {
            send_json_response(false, 'User ID, date, and status are required.');
        }
        
        $login_datetime = NULL;
        $logout_datetime = NULL;
        $breaks_json = NULL; // Default to NULL

        // Logic to clear login/logout/breaks if status is non-working
        $status_lower = strtolower($status);
        if ($status_lower === 'present') {
            // Only format times if status is 'Present'
            $login_datetime = !empty($login_time_str) ? $date . ' ' . $login_time_str . ':00' : NULL;
            $logout_datetime = !empty($logout_time_str) ? $date . ' ' . $logout_time_str . ':00' : NULL;
            
            // If login time is set, initialize breaks to an empty array string '[]', otherwise clear them.
            $breaks_json = $login_datetime ? '[]' : NULL;

        } else if (strpos($status_lower, 'leave') !== false || strpos($status_lower, 'half day') !== false || $status_lower === 'absent') {
            // Status is leave/half day/absent: clear all time-related fields
            $login_datetime = NULL;
            $logout_datetime = NULL;
            $breaks_json = NULL; 
        }

        // Build query
        $stmt = $db->prepare("
            INSERT INTO attendance (user_id, attendance_date, status, login_time, logout_time, breaks) 
            VALUES (?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                login_time = VALUES(login_time), 
                logout_time = VALUES(logout_time),
                breaks = VALUES(breaks)
        ");
        
        if (!$stmt) send_json_response(false, "SQL Error (update_attendance/insert): " . $db->error);
        
        $stmt->bind_param("ssssss", $user_id, $date, $status, $login_datetime, $logout_datetime, $breaks_json);
        
        if ($stmt->execute()) {
            send_json_response(true, 'Attendance updated successfully.');
        } else {
            send_json_response(false, 'Failed to update attendance: ' . $stmt->error);
        }
        $stmt->close();
        break;

    case 'get_all_leave_requests':
        check_admin();
        
        // Fetch all requests
        $stmt = $db->prepare("SELECT lr.id, lr.user_id, lr.leave_type, lr.start_date, lr.end_date, lr.reason, lr.status, lr.admin_reason, lr.created_at, u.full_name FROM leave_requests lr JOIN users u ON lr.user_id = u.user_id ORDER BY lr.status = 'pending' DESC, lr.created_at DESC");
        if (!$stmt) send_json_response(false, "SQL Error (get_all_leave_requests/fetch): " . $db->error);
        $stmt->execute();
        $result = $stmt->get_result();
        $requests = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // --- EXCLUSION LOGIC ---
        $filtered_requests = array_filter($requests, function($request) {
            return strcasecmp($request['full_name'], EXCLUDED_USER_FULL_NAME) !== 0;
        });

        send_json_response(true, 'Leave requests fetched successfully.', array_values($filtered_requests));
        break;

    case 'update_leave_status':
        check_admin();
        $request_id = $request_body['request_id'] ?? null;
        $new_status = $request_body['status'] ?? null;
        $admin_reason = $request_body['reason'] ?? null;
        if (!$request_id || !in_array($new_status, ['approved', 'rejected'])) { send_json_response(false, 'Request ID and a valid status are required.'); }
        if ($new_status === 'rejected' && empty($admin_reason)) { send_json_response(false, 'A reason is mandatory for rejecting a request.'); }
        
        $db->begin_transaction();
        try {
            // Get user_id and name before updating the request
            // FIX: Ambiguous column resolved by using lr.user_id
            $stmt_get = $db->prepare("SELECT lr.user_id, u.full_name, start_date, end_date, leave_type FROM leave_requests lr JOIN users u ON lr.user_id = u.user_id WHERE lr.id = ?");
            if (!$stmt_get) throw new Exception("SQL Error (update_leave_status/get): " . $db->error);
            $stmt_get->bind_param("i", $request_id);
            $stmt_get->execute();
            $stmt_get->bind_result($req_user_id, $full_name, $req_start_date, $req_end_date, $req_leave_type);
            $request_found = $stmt_get->fetch();
            $stmt_get->close();

            if (!$request_found) {
                 send_json_response(false, 'Leave request not found or already processed.');
            }
            
            // --- EXCLUSION CHECK ---
            if (strcasecmp($full_name, EXCLUDED_USER_FULL_NAME) === 0) {
                send_json_response(false, 'Cannot process request for this user due to exclusion rules.');
            }

            $stmt1 = $db->prepare("UPDATE leave_requests SET status = ?, admin_reason = ? WHERE id = ?");
            if (!$stmt1) throw new Exception("SQL Error (update_leave_status/update_req): " . $db->error);
            $stmt1->bind_param("ssi", $new_status, $admin_reason, $request_id);
            $stmt1->execute();
            $stmt1->close();
            
            if ($new_status === 'approved') {
                if ($req_user_id) {
                    $period = new DatePeriod( new DateTime($req_start_date), new DateInterval('P1D'), (new DateTime($req_end_date))->modify('+1 day') );
                    // SQL to clear all time data on leave approval
                    $stmt3 = $db->prepare("
                        INSERT INTO attendance (user_id, attendance_date, status, login_time, logout_time, breaks) 
                        VALUES (?, ?, ?, NULL, NULL, NULL) 
                        ON DUPLICATE KEY UPDATE 
                            status = VALUES(status), 
                            login_time = NULL, 
                            logout_time = NULL,
                            breaks = NULL
                    ");
                    // CRITICAL FIX: Add database error check here.
                    if (!$stmt3) throw new Exception("SQL Error (update_leave_status/insert_att): " . $db->error);

                    foreach ($period as $date) {
                        $date_str = $date->format('Y-m-d');
                        $stmt3->bind_param("sss", $req_user_id, $date_str, $req_leave_type);
                        $stmt3->execute();
                    }
                    $stmt3->close();
                }
            }
            
            // Log notification for the user
            if ($req_user_id) {
                $log_type = ($new_status === 'rejected') ? 'rejection' : 'approval';
                log_user_notification($db, $req_user_id, $log_type, $request_id);
            }

            $db->commit();
            send_json_response(true, 'Leave request ' . $new_status . ' successfully.');
        } catch (Exception $e) {
            $db->rollback();
            // Provide the detailed SQL error if available
            $error_message = $e->getMessage();
            if (strpos($error_message, "SQL Error") !== false) {
                 error_log("Failed to update leave status (SQL): " . $error_message);
            }
            send_json_response(false, 'Failed to update leave status: ' . $error_message);
        }
        break;

    case 'partially_approve_leave':
    case 'edit_leave_decision':
        check_admin();
        $request_id = $request_body['request_id'] ?? null;
        $admin_reason = $request_body['reason'] ?? null;
        $new_days = $request_body['days'] ?? [];
        if (!$request_id || empty($admin_reason)) { send_json_response(false, 'Request ID and a reason are mandatory.'); }

        $db->begin_transaction();
        try {
            // FIX: Ambiguous column resolved by using lr.user_id
            $stmt_get = $db->prepare("SELECT lr.user_id, u.full_name, start_date, end_date FROM leave_requests lr JOIN users u ON lr.user_id = u.user_id WHERE lr.id = ?");
            if (!$stmt_get) throw new Exception("SQL Error (partial/get): " . $db->error);
            $stmt_get->bind_param("i", $request_id);
            $stmt_get->execute();
            $stmt_get->bind_result($user_id, $full_name, $original_start, $original_end);
            $request_found = $stmt_get->fetch();
            $stmt_get->close();
            
            if (!$request_found) {
                 send_json_response(false, 'Original leave request not found or already processed.');
            }
            
            // --- EXCLUSION CHECK ---
            if (strcasecmp($full_name, EXCLUDED_USER_FULL_NAME) === 0) {
                send_json_response(false, 'Cannot process request for this user due to exclusion rules.');
            }

            // Clear existing attendance records for the dates in the original request range
            $stmt_delete = $db->prepare("DELETE FROM attendance WHERE user_id = ? AND attendance_date BETWEEN ? AND ? AND (LOWER(status) LIKE '%leave%' OR LOWER(status) LIKE '%half day%')");
            if (!$stmt_delete) throw new Exception("SQL Error (partial/delete): " . $db->error);
            $stmt_delete->bind_param("sss", $user_id, $original_start, $original_end);
            $stmt_delete->execute();
            $stmt_delete->close();
            
            $approved_days_count = 0;
            if (!empty($new_days)) {
                // SQL to clear all time data on partial approval/edit
                $stmt_insert = $db->prepare("
                    INSERT INTO attendance (user_id, attendance_date, status, login_time, logout_time, breaks) 
                    VALUES (?, ?, ?, NULL, NULL, NULL) 
                    ON DUPLICATE KEY UPDATE 
                        status = VALUES(status), 
                        login_time = NULL, 
                        logout_time = NULL,
                        breaks = NULL
                ");
                // CRITICAL FIX: Add database error check here.
                if (!$stmt_insert) throw new Exception("SQL Error (partial/insert): " . $db->error);

                foreach ($new_days as $day) {
                    $stmt_insert->bind_param("sss", $user_id, $day['date'], $day['type']);
                    $stmt_insert->execute();
                    $approved_days_count++;
                }
                $stmt_insert->close();
            }
            
            $new_status = ($approved_days_count > 0) ? 'partially_approved' : 'rejected';
            
            $stmt_update = $db->prepare("UPDATE leave_requests SET status = ?, admin_reason = ? WHERE id = ?");
            if (!$stmt_update) throw new Exception("SQL Error (partial/update_req): " . $db->error);
            $stmt_update->bind_param("ssi", $new_status, $admin_reason, $request_id);
            $stmt_update->execute();
            $stmt_update->close();
            
            // Log notification for the user
            $log_type = ($new_status === 'rejected') ? 'rejection' : 'partial_approval';
            log_user_notification($db, $user_id, $log_type, $request_id);

            $db->commit();
            send_json_response(true, 'Leave decision has been processed successfully.');
        } catch (Exception $e) {
            $db->rollback();
            $error_message = $e->getMessage();
            if (strpos($error_message, "SQL Error") !== false) {
                 error_log("Failed to process leave decision (SQL): " . $error_message);
            }
            send_json_response(false, 'Failed to process leave decision: ' . $error_message);
        }
        break;

    case 'get_leave_request_details_admin':
        check_admin();
        $request_id = $request_body['request_id'] ?? null;
        if (!$request_id) { send_json_response(false, "Request ID is required."); }
        
        // Fetch user_id and name for exclusion check
        // FIX: Ambiguous column resolved by using lr.user_id
        $stmt = $db->prepare("SELECT lr.user_id, u.full_name, start_date, end_date FROM leave_requests lr JOIN users u ON lr.user_id = u.user_id WHERE lr.id = ?");
        if (!$stmt) send_json_response(false, "SQL Error (get_details/fetch): " . $db->error);
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $stmt->bind_result($user_id, $full_name, $start_date, $end_date);
        $request_found = $stmt->fetch();
        $stmt->close();
        if (!$request_found) { send_json_response(false, "Leave request not found."); }
        
        // --- EXCLUSION CHECK ---
        if (strcasecmp($full_name, EXCLUDED_USER_FULL_NAME) === 0) {
            send_json_response(false, 'Cannot view details for this user due to exclusion rules.');
        }
        
        $period = new DatePeriod(new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day'));
        $all_dates = [];
        foreach ($period as $date) {
            $all_dates[$date->format('Y-m-d')] = ['date' => $date->format('Y-m-d'), 'status' => 'Not Approved'];
        }

        $stmt2 = $db->prepare("SELECT attendance_date, status FROM attendance WHERE user_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date ASC");
        if (!$stmt2) send_json_response(false, "SQL Error (get_details/fetch_att): " . $db->error);
        $stmt2->bind_param("sss", $user_id, $start_date, $end_date);
        $stmt2->execute();
        $result = $stmt2->get_result();
        while($row = $result->fetch_assoc()) {
            if (array_key_exists($row['attendance_date'], $all_dates) && (stripos($row['status'], 'leave') !== false || stripos($row['status'], 'half day') !== false) ) {
                $all_dates[$row['attendance_date']]['status'] = $row['status'];
            }
        }
        $stmt2->close();
        send_json_response(true, 'Details fetched successfully.', array_values($all_dates));
        break;

    default:
        send_json_response(false, 'Invalid admin action requested.');
        break;
}

// Ensure the buffer is cleared if execution reaches the end without calling send_json_response
if (ob_get_length() > 0) {
    ob_end_clean();
    // This is a fail-safe, but a script should ideally always call send_json_response
    // or return a proper HTTP error before this point.
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Internal server error: Script completed without proper response.', 'data' => null]);
}

$db->close();
?>
