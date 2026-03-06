<?php
// This file handles the 'Approve' and 'Reject' actions from the email.

// Start output buffering to capture potential PHP errors/warnings
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

require 'db_connect.php'; // Get the database connection

// --- NEW FUNCTION: Log a notification for the user ---
function log_user_notification($db, $user_id, $type, $related_id = NULL) {
    // This function is included here for robustness in case the admin processed the leave via email.
    $stmt = $db->prepare("INSERT INTO leave_notifications (user_id, related_id, type) VALUES (?, ?, ?)");
    $stmt->bind_param("sis", $user_id, $related_id, $type);
    $stmt->execute();
    $stmt->close();
}
// --- END NEW FUNCTION ---

// Get parameters from the URL
$action = $_GET['action'] ?? null;
$request_id = $_GET['id'] ?? null;
$token = $_GET['token'] ?? null;

// Simple HTML for response
function show_message($title, $message, $is_error = false) {
    // Clear the buffer before outputting HTML response
    if (ob_get_length() > 0) {
        ob_clean();
    }
    $color = $is_error ? '#dc3545' : '#28a745';
    echo "
    <!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>{$title}</title>
    <style>
        body { font-family: Arial, sans-serif; display: grid; place-items: center; min-height: 90vh; background-color: #f4f4f4; }
        .container { background-color: #fff; border: 1px solid #ddd; border-top: 5px solid {$color}; border-radius: 8px; padding: 2rem 3rem; box-shadow: 0 4px 12px rgba(0,0,0,0.05); text-align: center; }
        h1 { color: {$color}; margin-top: 0; }
        p { font-size: 1.1rem; color: #333; }
        .button { background-color: #4f46e5; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; display: inline-block; margin-top: 15px; }
    </style>
    </head><body>
    <div class='container'>
        <h1>{$title}</h1>
        <p>{$message}</p>
        <a href='#' class='button' onclick='window.close();'>Close Window</a>
    </div>
    </body></html>
    ";
    // End output buffering and flush output
    ob_end_flush();
    exit;
}

// 1. Validate input
if (!$action || !$request_id || !$token) {
    show_message('Action Failed', 'Invalid link. Missing parameters.', true);
}
if (!in_array($action, ['approve', 'reject'])) {
    show_message('Action Failed', 'Invalid action specified.', true);
}

// 2. Find the request using the token
// The token is critical for security and ensuring the request hasn't been processed.
$stmt_find = $db->prepare("SELECT user_id, start_date, end_date, leave_type FROM leave_requests WHERE id = ? AND admin_token = ? AND status = 'pending'");
if (!$stmt_find) {
    show_message('Database Error', 'Could not prepare statement.', true);
}
$stmt_find->bind_param("is", $request_id, $token);
$stmt_find->execute();
$stmt_find->bind_result($user_id, $start_date, $end_date, $leave_type);
$request = $stmt_find->fetch();
$stmt_find->close();

if (!$request) {
    // If request fails, check if it was processed already (token invalid/NULL).
    show_message('Action Failed', 'This link is invalid or the request has already been processed.', true);
}

// 3. Process the action
$db->begin_transaction();
try {
    $new_status = ($action === 'approve') ? 'approved' : 'rejected';
    $admin_reason = "Processed via email link by Admin on " . date('Y-m-d H:i:s');

    // Update the leave_requests table and, critically, set token to NULL so it can't be used again
    $stmt_update = $db->prepare("UPDATE leave_requests SET status = ?, admin_reason = ?, admin_token = NULL WHERE id = ?");
    $stmt_update->bind_param("ssi", $new_status, $admin_reason, $request_id);
    $stmt_update->execute();
    $stmt_update->close();

    // If approved, update the attendance table
    if ($action === 'approve') {
        $period = new DatePeriod( new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day') );
        // FIX: Ensure login_time, logout_time, and breaks are explicitly cleared/NULL when approving leave.
        $stmt_att = $db->prepare("
            INSERT INTO attendance (user_id, attendance_date, status, login_time, logout_time, breaks) 
            VALUES (?, ?, ?, NULL, NULL, NULL) 
            ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                login_time = NULL, 
                logout_time = NULL,
                breaks = NULL
        ");
        if (!$stmt_att) throw new Exception("Failed to prepare attendance update statement in handle_leave_action.php");
        
        foreach ($period as $date) {
            $date_str = $date->format('Y-m-d');
            $stmt_att->bind_param("sss", $user_id, $date_str, $leave_type);
            $stmt_att->execute();
        }
        $stmt_att->close();
    }
    
    // Log notification for the user
    if ($user_id) {
        $log_type = ($action === 'reject') ? 'rejection' : 'approval';
        log_user_notification($db, $user_id, $log_type, $request_id);
    }
    
    $db->commit();
    
    // 4. Show success message (Human readable titles/messages)
    $success_title = ($action === 'approve') ? 'Leave Request Approved' : 'Leave Request Rejected';
    $message_detail = ($action === 'approve') ? 'approved' : 'rejected';
    show_message($success_title, "The leave request (ID: {$request_id}) has been successfully {$message_detail}. You may now close this window.", false);

} catch (Exception $e) {
    $db->rollback();
    show_message('Action Failed', 'An error occurred while processing the request: ' . $e->getMessage(), true);
}
// End buffering (already handled by show_message, but safety)
if (ob_get_length() > 0) {
    ob_end_clean();
}

$db->close();
?>