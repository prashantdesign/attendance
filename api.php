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
                if ($stmt->execute()) send_json_response(true, "Clocked out successfully at " . date('h:i A'));
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