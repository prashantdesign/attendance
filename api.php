<?php
// api.php - FINAL COMPLETE FILE

// Start output buffering to capture potential PHP errors/warnings
ob_start();

// --- START: PHPMailer Imports ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Assuming these PHPMailer files are available in the correct relative path
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
// --- END: PHPMailer Imports ---

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
    header('Access-Control-Max-Age: 86400');
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

// --- MODIFIED calculate_leave_balance Function (Kept logic for consistency) ---
function calculate_leave_balance($db, $user_id) {
    $stmt = $db->prepare("SELECT tracked_since, monthly_leave_allowance, initial_leaves, bonus_leaves FROM profiles WHERE user_id = ?");
    if (!$stmt) return 0;
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $stmt->bind_result($tracked_since, $monthly_allowance, $initial_leaves_json, $bonus_leaves_json);
    $profile_exists = $stmt->fetch();
    $stmt->close();
    if (!$profile_exists) return 0;

    // Calculate Initial/Carry-over Leaves
    $initial_leaves = 0;
    if (!empty($initial_leaves_json) && ($decoded = json_decode($initial_leaves_json, true))) {
        foreach ($decoded as $item) $initial_leaves += floatval($item['count'] ?? 0);
    }
    
    // Calculate Bonus/Admin-Adjusted Leaves (can be positive or negative)
    $bonus_leaves = 0;
    if (!empty($bonus_leaves_json) && ($decoded = json_decode($bonus_leaves_json, true))) {
        foreach ($decoded as $item) $bonus_leaves += floatval($item['count'] ?? 0);
    }

    $startOfTrackingMonth = new DateTime($tracked_since);
    $startOfCurrentMonth = new DateTime('first day of this month');
    $interval = $startOfTrackingMonth->diff($startOfCurrentMonth);
    // Count the starting month and all full months after it
    $months_passed = $interval->y * 12 + $interval->m + 1;
    
    // Calculate Earned Leaves 
    $earned_leaves = ($months_passed) * floatval($monthly_allowance ?? 2);

    // Calculate Used Leaves (Non-unpaid leaves)
    $stmt = $db->prepare("SELECT status FROM attendance WHERE user_id = ? AND (LOWER(status) LIKE '%leave%' OR LOWER(status) LIKE '%half day%') AND LOWER(status) NOT LIKE '%unpaid%'");
    if (!$stmt) return $initial_leaves + $bonus_leaves + $earned_leaves;
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $stmt->bind_result($status);
    $used_leaves = 0;
    while ($stmt->fetch()) {
        if (strpos(strtolower($status), 'half day') !== false) $used_leaves += 0.5;
        else $used_leaves += 1;
    }
    $stmt->close();
    
    // Total Leaves = (Initial + Bonus + Earned) - Used
    return ($initial_leaves + $bonus_leaves + $earned_leaves) - $used_leaves;
}
// --- END MODIFIED calculate_leave_balance Function ---

function get_current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function haversine_distance_meters($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000;
    $d_lat = deg2rad($lat2 - $lat1);
    $d_lon = deg2rad($lon2 - $lon1);

    $a = sin($d_lat / 2) * sin($d_lat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($d_lon / 2) * sin($d_lon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earth_radius * $c;
}

function get_location_policy_for_user($db, $user_id) {
    $policy = [
        'global_enabled' => 0,
        'office_latitude' => null,
        'office_longitude' => null,
        'office_radius_meters' => 150,
        'half_day_first_half_cutoff_ist' => '14:00:00',
        'half_day_second_half_cutoff_ist' => '18:30:00',
        'user_enabled' => 0,
        'background_tracking_required' => 1
    ];

    $stmt_settings = $db->prepare("SELECT global_enabled, office_latitude, office_longitude, office_radius_meters, half_day_first_half_cutoff_ist, half_day_second_half_cutoff_ist FROM location_attendance_settings WHERE id = 1 LIMIT 1");
    if ($stmt_settings) {
        $stmt_settings->execute();
        $result = $stmt_settings->get_result();
        if ($row = $result->fetch_assoc()) {
            $policy = array_merge($policy, $row);
        }
        $stmt_settings->close();
    }

    $stmt_user = $db->prepare("SELECT location_attendance_enabled, background_tracking_required FROM user_location_prefs WHERE user_id = ? LIMIT 1");
    if ($stmt_user) {
        $stmt_user->bind_param("s", $user_id);
        $stmt_user->execute();
        $result = $stmt_user->get_result();
        if ($row = $result->fetch_assoc()) {
            $policy['user_enabled'] = intval($row['location_attendance_enabled']);
            $policy['background_tracking_required'] = intval($row['background_tracking_required']);
        }
        $stmt_user->close();
    }

    $policy['global_enabled'] = intval($policy['global_enabled']);
    $policy['office_radius_meters'] = intval($policy['office_radius_meters']);
    return $policy;
}

function get_today_attendance_row($db, $user_id, $date) {
    $stmt = $db->prepare("SELECT id, status, login_time, logout_time, breaks FROM attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("ss", $user_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function parse_breaks_array($breaks_json) {
    if (!$breaks_json || $breaks_json === 'null') {
        return [];
    }
    $parsed = json_decode($breaks_json, true);
    return is_array($parsed) ? $parsed : [];
}

function is_break_open($breaks) {
    if (empty($breaks)) return false;
    $last_break = end($breaks);
    return !empty($last_break['startTime']) && empty($last_break['endTime']);
}

function apply_auto_half_day_status($db, $user_id, $attendance_date) {
    $row = get_today_attendance_row($db, $user_id, $attendance_date);
    if (!$row || empty($row['login_time']) || empty($row['logout_time'])) {
        return;
    }

    $status_now = strtolower(trim($row['status'] ?? 'present'));
    if ($status_now !== 'present' && strpos($status_now, 'half day') === false) {
        return;
    }

    $policy = get_location_policy_for_user($db, $user_id);
    $first_half_cutoff = $policy['half_day_first_half_cutoff_ist'] ?? '14:00:00';
    $second_half_cutoff = $policy['half_day_second_half_cutoff_ist'] ?? '18:30:00';

    $login = new DateTime($row['login_time']);
    $logout = new DateTime($row['logout_time']);

    $cutoff_first = new DateTime($attendance_date . ' ' . $first_half_cutoff);
    $cutoff_second = new DateTime($attendance_date . ' ' . $second_half_cutoff);

    $new_status = 'Present';
    if ($login >= $cutoff_second) {
        $new_status = 'Half Day - First Half';
    } elseif ($logout <= $cutoff_first) {
        $new_status = 'Half Day - Second Half';
    }

    if ($new_status !== $row['status']) {
        $stmt = $db->prepare("UPDATE attendance SET status = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $new_status, $row['id']);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function get_previous_inside_state($db, $user_id) {
    $stmt = $db->prepare("SELECT meta_json FROM location_events WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $stmt->bind_result($meta_json);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found || !$meta_json) return null;
    $meta = json_decode($meta_json, true);
    if (!is_array($meta) || !array_key_exists('inside_geofence', $meta)) return null;
    return !empty($meta['inside_geofence']);
}

// --- FULLY IMPLEMENTED send_leave_notification (MODIFIED TO ACCEPT BALANCE) ---
function send_leave_notification($db, $request_id, $user_id, $full_name, $start_date, $end_date, $type, $reason, $leave_balance) {
    // 1. Generate unique token for email link
    $token = bin2hex(random_bytes(32));

    // 2. Save token to the leave request
    $stmt_token = $db->prepare("UPDATE leave_requests SET admin_token = ? WHERE id = ?");
    $stmt_token->bind_param("si", $token, $request_id);
    $stmt_token->execute();
    $stmt_token->close();

    // --- SMTP & Recipient Settings (AS PROVIDED BY USER) ---
    $gmail_username = "devlopmentbypk@gmail.com";
    $gmail_app_password = "hvqwakskrtikpljj";
    $admin_email = "jayminpvyas@gmail.com";
    
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $gmail_username;
        $mail->Password = $gmail_app_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom($gmail_username, 'Attendance System');
        $mail->addAddress($admin_email, 'Admin');

        // Content
        $mail->isHTML(true);
        $mail->Subject = "New Leave Request from: " . $full_name;
        
        // --- Approval/Rejection Links ---
        // FIX: Dynamically construct base URL using the server's host name.
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'brown-okapi-217859.hostingersite.com'; // Use former Hostinger domain as fallback
        
        $base_url = $protocol . $host . '/'; 
        
        $approve_link = "{$base_url}handle_leave_action.php?action=approve&id={$request_id}&token={$token}";
        $reject_link = "{$base_url}handle_leave_action.php?action=reject&id={$request_id}&token={$token}";
        
        // Admin Dashboard link
        $admin_dashboard_link = "https://brown-okapi-217859.hostingersite.com/admin.html";
        // ---------------------------------

        $body = "
            <h2>New Leave Request Pending Approval</h2>
            <p><strong>Employee:</strong> {$full_name} ({$user_id})</p>
            <p style='color: #4f46e5; font-size: 1.1em;'><strong>Current Paid Leave Balance:</strong> {$leave_balance} Days</p>
            <p><strong>Type:</strong> {$type}</p>
            <p><strong>Dates:</strong> {$start_date} to {$end_date}</p>
            <p><strong>Reason:</strong> " . nl2br(htmlspecialchars($reason)) . "</p>
            <hr>
            <p><strong>Action required:</strong> (Clicking these links applies the action immediately)</p>
            <p>
                <a href='{$approve_link}' style='background-color: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-right: 15px; display: inline-block; margin-bottom: 10px;'>Approve Request (Full)</a>
                <a href='{$reject_link}' style='background-color: #ef4444; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-bottom: 10px;'>Reject Request (Full)</a>
            </p>
            
            <p style='margin-top: 15px; font-size: 0.9em; color: #6b7280;'>
                <strong style='color: #4f46e5;'>Note:</strong> For Partial Approval or to provide a specific rejection reason, please use the Admin Dashboard:
            </p>
             <p style='margin-bottom: 15px;'>
                 <a href='{$admin_dashboard_link}' style='background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Open Admin Dashboard (Leave Section)</a>
            </p>
            <p style='font-size: 0.8em; color: #9ca3af;'>URL: {$admin_dashboard_link}</p>
        ";
        $mail->Body = $body;

        $mail->send();
        return true;

    } catch (Exception $e) {
        // Log the detailed error but throw an exception for the API response
        error_log("Mailer Error ({$admin_email}): {$e->getMessage()}");
        // Throw a user-friendly error
        throw new Exception("Email failed to send. Please check your Gmail App Password and SMTP host/port. Mailer Error: {$mail->ErrorInfo}");
    }
}
// --- END FULLY IMPLEMENTED send_leave_notification ---


$action = $_GET['action'] ?? '';
$request_body = json_decode(file_get_contents('php://input'), true);

switch ($action) {
    case 'check_connection':
        send_json_response(true, 'Database connection successful.');
        break;

    case 'login':
        $provided_user_id = $request_body['user_id'] ?? '';
        $provided_password = $request_body['password'] ?? '';
        if (empty($provided_user_id) || empty($provided_password)) send_json_response(false, 'User ID and password are required.');

        $stmt = $db->prepare("SELECT user_id, full_name, password FROM users WHERE user_id = ?");
        if (!$stmt) send_json_response(false, "SQL Error: " . $db->error);
        $stmt->bind_param("s", $provided_user_id);
        $stmt->execute();
        $stmt->bind_result($db_user_id, $db_full_name, $db_password_hash);
        $stmt->fetch();
        $stmt->close();
        
        if ($db_user_id && password_verify($provided_password, $db_password_hash)) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $db_user_id;
            $_SESSION['full_name'] = $db_full_name;
            send_json_response(true, 'Login successful!', ['user_id' => $db_user_id, 'full_name' => $db_full_name]);
        } else {
            send_json_response(false, 'Invalid user ID or password.');
        }
        break;
        
    case 'logout':
        session_unset();
        session_destroy();
        send_json_response(true, 'Logout successful.');
        break;

    default:
        $user_id = get_current_user_id();
        if (!$user_id) {
            header("HTTP/1.1 401 Unauthorized");
            send_json_response(false, 'Authentication required. Please log in again.');
        }

        switch($action) {
            case 'get_all_data':
                $profile_stmt = $db->prepare("SELECT * FROM profiles WHERE user_id = ?");
                $profile = null;
                 if ($profile_stmt) {
                    $profile_stmt->bind_param("s", $user_id);
                    $profile_stmt->execute();
                    $result = $profile_stmt->get_result();
                    $profile = $result->fetch_assoc();
                    $profile_stmt->close();
                }

                $att_stmt = $db->prepare("SELECT * FROM attendance WHERE user_id = ? ORDER BY attendance_date ASC");
                $attendance = [];
                if ($att_stmt) {
                    $att_stmt->bind_param("s", $user_id);
                    $att_stmt->execute();
                    $result = $att_stmt->get_result();
                    while($row = $result->fetch_assoc()) {
                        $attendance[$row['attendance_date']] = $row;
                    }
                    $att_stmt->close();
                }

                $notif_stmt = $db->prepare("SELECT COUNT(id) FROM leave_notifications WHERE user_id = ? AND is_read = 0");
                $notif_count = 0;
                if ($notif_stmt) {
                    $notif_stmt->bind_param("s", $user_id);
                    $notif_stmt->execute();
                    $notif_stmt->bind_result($notif_count);
                    $notif_stmt->fetch();
                    $notif_stmt->close();
                }

                if ($profile) {
                    $profile['leave_balance'] = calculate_leave_balance($db, $user_id);
                }

                send_json_response(true, 'Data fetched successfully', [
                    'profile' => $profile, 
                    'attendance' => $attendance,
                    'current_user' => ['user_id' => $_SESSION['user_id'], 'full_name' => $_SESSION['full_name']],
                    'unread_notifications' => $notif_count > 0 
                ]);
                break;
            case 'clock_in':
                $today = date('Y-m-d');
                $now = date('Y-m-d H:i:s');
                $stmt = $db->prepare("INSERT INTO attendance (user_id, attendance_date, login_time, status) VALUES (?, ?, ?, 'Present') ON DUPLICATE KEY UPDATE login_time = ?, status = 'Present', logout_time = NULL, breaks = '[]'");
                if (!$stmt) send_json_response(false, "SQL Error: " . $db->error);
                $stmt->bind_param("ssss", $user_id, $today, $now, $now);
                if ($stmt->execute()) send_json_response(true, "Clocked in successfully at " . date('h:i A'));
                else send_json_response(false, "Error clocking in: " . $stmt->error);
                $stmt->close();
                break;
            case 'clock_out':
                $today_str = date('Y-m-d');
                $now = date('Y-m-d H:i:s');
                
                // 1. Close any open break before clocking out (data integrity fix)
                $stmt_break_check = $db->prepare("SELECT breaks FROM attendance WHERE user_id = ? AND attendance_date = ? AND login_time IS NOT NULL AND logout_time IS NULL");
                if (!$stmt_break_check) send_json_response(false, "SQL Error: " . $db->error);
                $stmt_break_check->bind_param("ss", $user_id, $today_str);
                $stmt_break_check->execute();
                $stmt_break_check->bind_result($breaks_json);
                $stmt_break_check->fetch();
                $stmt_break_check->close();

                $breaks = ($breaks_json && $breaks_json !== 'null') ? json_decode($breaks_json, true) : [];
                $last_break_key = count($breaks) > 0 ? key(array_slice($breaks, -1, 1, true)) : null;

                if ($last_break_key !== null && empty($breaks[$last_break_key]['endTime'])) {
                    $breaks[key($breaks)]['endTime'] = $now;
                    $new_breaks_json = json_encode($breaks);
                    $update_break_stmt = $db->prepare("UPDATE attendance SET breaks = ? WHERE user_id = ? AND attendance_date = ?");
                    if ($update_break_stmt) {
                        $update_break_stmt->bind_param("sss", $new_breaks_json, $user_id, $today_str);
                        $update_break_stmt->execute();
                        $update_break_stmt->close();
                    }
                }

                // 2. Perform clock out
                $stmt = $db->prepare("UPDATE attendance SET logout_time = ? WHERE user_id = ? AND attendance_date = ?");
                if (!$stmt) send_json_response(false, "SQL Error: " . $db->error);
                $stmt->bind_param("sss", $now, $user_id, $today_str);
                if ($stmt->execute()) {
                    apply_auto_half_day_status($db, $user_id, $today_str);
                    send_json_response(true, "Clocked out successfully at " . date('h:i A'));
                }
                else send_json_response(false, "Error clocking out: " . $stmt->error);
                $stmt->close();
                break;
             case 'break':
                $today = date('Y-m-d');
                $now = date('Y-m-d H:i:s');
                
                $stmt = $db->prepare("SELECT breaks FROM attendance WHERE user_id = ? AND attendance_date = ? AND login_time IS NOT NULL AND logout_time IS NULL");
                if (!$stmt) send_json_response(false, "SQL Error: " . $db->error);
                $stmt->bind_param("ss", $user_id, $today);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows === 0) {
                    $stmt->close();
                    send_json_response(false, "Action failed: No active clock-in record found for today.");
                }
                
                $stmt->bind_result($breaks_json);
                $stmt->fetch();
                $stmt->close();

                $breaks = ($breaks_json && $breaks_json !== 'null') ? json_decode($breaks_json, true) : [];
                $last_break = end($breaks);
                
                if ($last_break && empty($last_break['endTime'])) {
                    // End break
                    $breaks[key($breaks)]['endTime'] = $now;
                } else {
                    // Start new break
                    $breaks[] = ['startTime' => $now, 'endTime' => null];
                }
                
                $new_breaks_json = json_encode($breaks);
                $update_stmt = $db->prepare("UPDATE attendance SET breaks = ? WHERE user_id = ? AND attendance_date = ?");
                if (!$update_stmt) send_json_response(false, "SQL Error: " . $db->error);
                $update_stmt->bind_param("sss", $new_breaks_json, $user_id, $today);
                
                if ($update_stmt->execute()) {
                    send_json_response(true, "Break status updated.");
                } else {
                    send_json_response(false, "Error updating break status.");
                }
                $update_stmt->close();
                break;

            case 'get_location_policy':
                $policy = get_location_policy_for_user($db, $user_id);
                send_json_response(true, 'Location policy fetched.', $policy);
                break;

            case 'location_event':
                $latitude = isset($request_body['latitude']) ? floatval($request_body['latitude']) : null;
                $longitude = isset($request_body['longitude']) ? floatval($request_body['longitude']) : null;
                $accuracy_m = isset($request_body['accuracy_m']) ? floatval($request_body['accuracy_m']) : null;
                $device_time = $request_body['device_time'] ?? null;
                $is_background = !empty($request_body['is_background']);

                if ($latitude === null || $longitude === null || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                    send_json_response(false, 'Valid latitude and longitude are required.');
                }

                $policy = get_location_policy_for_user($db, $user_id);
                if (intval($policy['global_enabled']) !== 1 || intval($policy['user_enabled']) !== 1) {
                    send_json_response(true, 'Location attendance is disabled.', [
                        'tracking_enabled' => false,
                        'action_taken' => 'none'
                    ]);
                }

                if (!is_numeric($policy['office_latitude']) || !is_numeric($policy['office_longitude'])) {
                    send_json_response(false, 'Office geofence coordinates are not configured by admin.');
                }

                $distance = haversine_distance_meters(
                    floatval($latitude),
                    floatval($longitude),
                    floatval($policy['office_latitude']),
                    floatval($policy['office_longitude'])
                );
                $inside_geofence = $distance <= intval($policy['office_radius_meters']);

                $prev_inside = get_previous_inside_state($db, $user_id);
                $event_type = 'heartbeat';
                if ($prev_inside === null) {
                    $event_type = $inside_geofence ? 'enter_geofence' : 'exit_geofence';
                } elseif ($prev_inside === false && $inside_geofence === true) {
                    $event_type = 'enter_geofence';
                } elseif ($prev_inside === true && $inside_geofence === false) {
                    $event_type = 'exit_geofence';
                }

                $today = date('Y-m-d');
                $now = date('Y-m-d H:i:s');
                $action_taken = 'none';
                $attendance_row = get_today_attendance_row($db, $user_id, $today);

                if ($event_type === 'enter_geofence') {
                    if (!$attendance_row || empty($attendance_row['login_time']) || !empty($attendance_row['logout_time'])) {
                        $stmt_clock_in = $db->prepare("INSERT INTO attendance (user_id, attendance_date, login_time, status, breaks, logout_time) VALUES (?, ?, ?, 'Present', '[]', NULL) ON DUPLICATE KEY UPDATE login_time = VALUES(login_time), status = 'Present', breaks = '[]', logout_time = NULL");
                        if ($stmt_clock_in) {
                            $stmt_clock_in->bind_param("sss", $user_id, $today, $now);
                            $stmt_clock_in->execute();
                            $stmt_clock_in->close();
                            $action_taken = 'auto_clock_in';
                            $attendance_row = get_today_attendance_row($db, $user_id, $today);
                        }
                    } elseif (empty($attendance_row['logout_time'])) {
                        $breaks = parse_breaks_array($attendance_row['breaks'] ?? '[]');
                        if (is_break_open($breaks)) {
                            $breaks[key($breaks)]['endTime'] = $now;
                            $new_breaks_json = json_encode($breaks);
                            $stmt_break_end = $db->prepare("UPDATE attendance SET breaks = ? WHERE id = ?");
                            if ($stmt_break_end) {
                                $stmt_break_end->bind_param("si", $new_breaks_json, $attendance_row['id']);
                                $stmt_break_end->execute();
                                $stmt_break_end->close();
                                $action_taken = 'auto_break_end';
                            }
                        }
                    }
                }

                if ($event_type === 'exit_geofence' && $attendance_row && !empty($attendance_row['login_time']) && empty($attendance_row['logout_time'])) {
                    $breaks = parse_breaks_array($attendance_row['breaks'] ?? '[]');
                    if (!is_break_open($breaks)) {
                        $breaks[] = ['startTime' => $now, 'endTime' => null];
                        $new_breaks_json = json_encode($breaks);
                        $stmt_break_start = $db->prepare("UPDATE attendance SET breaks = ? WHERE id = ?");
                        if ($stmt_break_start) {
                            $stmt_break_start->bind_param("si", $new_breaks_json, $attendance_row['id']);
                            $stmt_break_start->execute();
                            $stmt_break_start->close();
                            $action_taken = 'auto_break_start';
                        }
                    }
                }

                $meta = [
                    'inside_geofence' => $inside_geofence,
                    'is_background' => $is_background,
                    'office_radius_meters' => intval($policy['office_radius_meters'])
                ];
                $meta_json = json_encode($meta);
                $stmt_event = $db->prepare("INSERT INTO location_events (user_id, event_type, latitude, longitude, accuracy_m, distance_from_office_m, device_time, server_time, action_taken, meta_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt_event) {
                    send_json_response(false, 'Failed to record location event: ' . $db->error);
                }
                $stmt_event->bind_param("ssdddsssss", $user_id, $event_type, $latitude, $longitude, $accuracy_m, $distance, $device_time, $now, $action_taken, $meta_json);
                if (!$stmt_event->execute()) {
                    send_json_response(false, 'Failed to record location event: ' . $stmt_event->error);
                }
                $stmt_event->close();

                send_json_response(true, 'Location event processed.', [
                    'tracking_enabled' => true,
                    'event_type' => $event_type,
                    'action_taken' => $action_taken,
                    'inside_geofence' => $inside_geofence,
                    'distance_from_office_m' => round($distance, 2)
                ]);
                break;
            
            case 'request_leave':
                $start_date = $request_body['start_date'] ?? null;
                $end_date = $request_body['end_date'] ?? null;
                $type = $request_body['type'] ?? null;
                $reason = $request_body['reason'] ?? '';
                if (!$start_date || !$end_date || !$type) send_json_response(false, "Start date, end date, and leave type are required.");
                
                // Get the current user's full name and leave balance for the email notification
                $full_name = $_SESSION['full_name'] ?? $user_id;
                $current_leave_balance = calculate_leave_balance($db, $user_id);

                $db->begin_transaction();
                try {
                    // 1. Insert leave request into DB (without token yet)
                    $stmt = $db->prepare("INSERT INTO leave_requests (user_id, start_date, end_date, leave_type, reason) VALUES (?, ?, ?, ?, ?)");
                    if (!$stmt) throw new Exception("SQL Error (request_leave/insert): " . $db->error);
                    $stmt->bind_param("sssss", $user_id, $start_date, $end_date, $type, $reason);
                    $stmt->execute();
                    $new_request_id = $db->insert_id;
                    $stmt->close();
                    
                    // 2. Send Email with Approval Links (which generates and saves the token)
                    // PASS THE CALCULATED BALANCE
                    send_leave_notification($db, $new_request_id, $user_id, $full_name, $start_date, $end_date, $type, $reason, number_format($current_leave_balance, 1));
                    
                    $db->commit();
                    send_json_response(true, "Leave request submitted successfully. Admin notified.");

                } catch (Exception $e) {
                    $db->rollback();
                    $error_message = $e->getMessage();
                    error_log("Leave Request Failed: " . $error_message);
                    send_json_response(false, "Leave request submission failed. Server Error: " . $error_message);
                }
                break;
            
            case 'get_my_leave_requests':
                $stmt = $db->prepare("SELECT id, start_date, end_date, leave_type, status, admin_reason FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC");
                if (!$stmt) send_json_response(false, "SQL Error: " . $db->error);
                $stmt->bind_param("s", $user_id);
                $stmt->execute();
                $stmt->bind_result($id, $start_date, $end_date, $leave_type, $status, $admin_reason);
                $requests = [];
                while($stmt->fetch()) {
                    $requests[] = ['id' => $id, 'start_date' => $start_date, 'end_date' => $end_date, 'leave_type' => $leave_type, 'status' => $status, 'admin_reason' => $admin_reason];
                }
                $stmt->close();
                send_json_response(true, 'Your leave requests fetched.', $requests);
                break;

            case 'delete_leave_request':
                $request_id = $request_body['request_id'] ?? null;
                if (!$request_id) send_json_response(false, 'Request ID is required.');
                // IMPORTANT: Only allow deletion of pending requests to prevent data inconsistency
                $stmt = $db->prepare("DELETE FROM leave_requests WHERE id = ? AND user_id = ? AND status = 'pending'");
                if (!$stmt) send_json_response(false, 'SQL Error: ' . $db->error);
                $stmt->bind_param("is", $request_id, $user_id);
                $stmt->execute();
                if ($stmt->affected_rows > 0) send_json_response(true, 'Leave request has been successfully deleted.');
                else send_json_response(false, 'Could not delete request. It may have already been processed or does not exist.');
                $stmt->close();
                break;
            
            case 'get_leave_request_details':
                $request_id = $request_body['request_id'] ?? null;
                if (!$request_id) {
                    send_json_response(false, 'Request ID is required.');
                }

                $stmt = $db->prepare("SELECT start_date, end_date FROM leave_requests WHERE id = ? AND user_id = ?");
                if (!$stmt) send_json_response(false, 'SQL Error: '. $db->error);
                $stmt->bind_param("is", $request_id, $user_id);
                $stmt->execute();
                $stmt->bind_result($start_date, $end_date);
                $request_found = $stmt->fetch();
                $stmt->close();

                if (!$request_found) {
                    send_json_response(false, 'Leave request not found or you do not have permission to view it.');
                }

                $period = new DatePeriod(new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day'));
                $all_dates = [];
                foreach ($period as $date) {
                    $all_dates[$date->format('Y-m-d')] = ['date' => $date->format('Y-m-d'), 'status' => 'Not Approved'];
                }

                $stmt2 = $db->prepare("SELECT attendance_date, status FROM attendance WHERE user_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date ASC");
                if (!$stmt2) send_json_response(false, 'SQL Error: '. $db->error);
                $stmt2->bind_param("sss", $user_id, $start_date, $end_date);
                $stmt2->execute();
                $stmt2->bind_result($att_date, $att_status);
                
                while ($stmt2->fetch()) {
                     if (array_key_exists($att_date, $all_dates) && (stripos($att_status, 'leave') !== false || stripos($att_status, 'half day') !== false)) {
                        $all_dates[$att_date]['status'] = $att_status;
                    }
                }
                $stmt2->close();

                send_json_response(true, 'Details fetched successfully.', array_values($all_dates));
                break;
            
            // NEW: Endpoint to clear unread notifications
            case 'clear_leave_notification':
                $stmt = $db->prepare("UPDATE leave_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
                if (!$stmt) send_json_response(false, 'SQL Error: '. $db->error);
                $stmt->bind_param("s", $user_id);
                $stmt->execute();
                $stmt->close();
                send_json_response(true, 'Notifications cleared.');
                break;
            
            default:
                send_json_response(false, 'Invalid action');
                break;
        }
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
