<?php
/**
 * Repository: farmers-market
 * File: adminPage.php
 * Module: Upazila & District Administration, Super Admin Approval,
 *         Profile Picture Management, Transfer Activation, Mill Registry & Actions
 */
session_start();
require_once 'lang.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: homePage.php?login=true");
    exit();
}

function formatLocationParts(array $row): string
{
    $parts = array_filter([
        trim($row['village'] ?? ''),
        trim($row['upazila'] ?? ''),
        trim($row['district'] ?? ''),
    ], static function ($v) {
        return $v !== '';
    });

    return implode(', ', $parts);
}

function resolveOfficerRoleFromDesignation(string $designation): string
{
    $d = strtolower(trim($designation));

    if (
        strpos($d, 'super') !== false ||
        strpos($d, 'dc') !== false ||
        strpos($d, 'deputy commissioner') !== false ||
        strpos($d, 'collector') !== false ||
        strpos($d, 'divisional') !== false ||
        strpos($d, 'site admin') !== false ||
        strpos($d, 'district agriculture') !== false
    ) {
        return 'super_admin';
    }

    if (
        strpos($d, 'industry') !== false ||
        strpos($d, 'commerce') !== false ||
        strpos($d, 'shilpa') !== false ||
        strpos($d, 'banijjo') !== false ||
        strpos($d, 'banijya') !== false
    ) {
        return 'industry_commerce';
    }

    if (
        strpos($d, 'uno') !== false ||
        strpos($d, 'upazila nirbahi') !== false ||
        strpos($d, 'nirbahi') !== false
    ) {
        return 'uno';
    }

    if (
        strpos($d, 'agri') !== false ||
        strpos($d, 'krishi') !== false ||
        strpos($d, 'agriculture') !== false
    ) {
        return 'agriculture';
    }

    return 'other_admin';
}

function ensureAdminSchemaUpdates(mysqli $conn): void
{
    $col_chk = $conn->query("SHOW COLUMNS FROM admins LIKE 'profile_photo'");
    if ($col_chk && $col_chk->num_rows === 0) {
        $conn->query("ALTER TABLE admins ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL");
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS admin_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            requester_admin_id INT NOT NULL,
            requester_name VARCHAR(150) NOT NULL,
            requester_designation VARCHAR(150) NOT NULL,
            request_target ENUM('uno', 'super_admin') NOT NULL,
            request_type VARCHAR(60) NOT NULL DEFAULT 'transfer_activation',
            target_upazila VARCHAR(120) NOT NULL DEFAULT '',
            target_district VARCHAR(120) NOT NULL DEFAULT '',
            requested_status VARCHAR(40) NOT NULL DEFAULT 'active',
            reason TEXT,
            status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            reviewed_by_id INT DEFAULT NULL,
            reviewed_by_name VARCHAR(150) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            reviewed_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function pushMillNotification(mysqli $conn, string $millId, ?int $ownerId, string $eventType, string $complaintNo, string $title, string $message): void
{
    $stmt = $conn->prepare("
        INSERT INTO mill_notifications (mill_ID, mill_owner_id, event_type, complaint_no, title, message)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param("sissss", $millId, $ownerId, $eventType, $complaintNo, $title, $message);
        $stmt->execute();
        $stmt->close();
    }
}

function syncExpiredSuspensions(mysqli $conn, string $upazilaLike, string $districtLike): void
{
    $today = date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT id, mill_ID, mill_owner_id, complaint_no, suspended_until
        FROM mill_suspensions
        WHERE upazila LIKE ? AND district LIKE ? AND status = 'active' AND suspended_until < ?
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("sss", $upazilaLike, $districtLike, $today);
    $stmt->execute();
    $rows = $stmt->get_result();
    $stmt->close();

    if ($rows && $rows->num_rows > 0) {
        while ($exp = $rows->fetch_assoc()) {
            $sid   = intval($exp['id']);
            $mCode = $exp['mill_ID'];

            $stmt_u = $conn->prepare("UPDATE mill_suspensions SET status = 'expired' WHERE id = ?");
            if ($stmt_u) {
                $stmt_u->bind_param("i", $sid);
                $stmt_u->execute();
                $stmt_u->close();
            }

            $stmt_cnt = $conn->prepare("SELECT COUNT(*) AS cnt FROM mill_suspensions WHERE mill_ID = ? AND status = 'active'");
            $active_cnt = 0;
            if ($stmt_cnt) {
                $stmt_cnt->bind_param("s", $mCode);
                $stmt_cnt->execute();
                $active_cnt = intval($stmt_cnt->get_result()->fetch_assoc()['cnt'] ?? 0);
                $stmt_cnt->close();
            }

            if ($active_cnt === 0) {
                $stmt_mo = $conn->prepare("UPDATE mill_owners SET account_status = 'active', suspended_until = NULL, active_complaint_no = NULL, suspension_reason = NULL WHERE mill_ID = ?");
                if ($stmt_mo) {
                    $stmt_mo->bind_param("s", $mCode);
                    $stmt_mo->execute();
                    $stmt_mo->close();
                }

                $owner_id = !empty($exp['mill_owner_id']) ? intval($exp['mill_owner_id']) : null;
                $title    = "Mill Account Reactivated";
                $msg      = "Your mill account ({$mCode}) has been reactivated automatically after the suspension period ending on {$exp['suspended_until']} expired.";
                pushMillNotification($conn, $mCode, $owner_id, 'REACTIVATED', $exp['complaint_no'] ?? '', $title, $msg);
            }
        }
    }
}

$servername = "sql209.infinityfree.com";
$username   = "if0_43517157";
$password   = "Sadekul3055";
$dbname     = "if0_43517157_farmers_market";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

ensureAdminSchemaUpdates($conn);

$admin_id   = intval($_SESSION['user_id']);
$flash_msg  = "";
$flash_type = "alert-success";

// 1. Fetch Admin Data & Verify Account Status
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: homePage.php?login=true");
    exit();
}

$current_acc_status = strtolower(trim($user['account_status'] ?? 'active'));
if ($current_acc_status === 'retired') {
    $reason_txt = trim($user['status_reason'] ?? '');
    session_destroy();
    $msg_param = urlencode("Your admin account is retired" . ($reason_txt !== '' ? ": {$reason_txt}" : "."));
    header("Location: homePage.php?login=true&error=" . $msg_param);
    exit();
}

if ($current_acc_status === 'pending') {
    session_destroy();
    $msg_param = urlencode("Your administrative account is awaiting Super Admin verification and activation.");
    header("Location: homePage.php?login=true&error=" . $msg_param);
    exit();
}

$admin_upazila       = trim($user['upazila'] ?? '');
$admin_district      = trim($user['district'] ?? '');
$admin_designation   = trim($user['designation'] ?? '');
$officer_role        = resolveOfficerRoleFromDesignation($admin_designation);

$is_super_admin      = ($officer_role === 'super_admin' || intval($user['is_super_admin'] ?? 0) === 1);
$is_uno              = ($officer_role === 'uno');
$is_industry_officer = ($officer_role === 'industry_commerce');

$can_issue_orders    = ($current_acc_status === 'active');
$can_manage_mills    = ($current_acc_status === 'active');
$has_district_scope  = ($admin_district !== '');

$upazila_like  = "%" . $admin_upazila . "%";
$district_like = "%" . $admin_district . "%";

syncExpiredSuspensions($conn, $upazila_like, $district_like);

// 2A. Officer submits Activation Request to New Upazila UNO (upon transfer)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['request_uno_activation'])) {
    $req_upazila  = trim($_POST['req_upazila'] ?? '');
    $req_district = trim($_POST['req_district'] ?? '');
    $req_reason   = trim($_POST['req_reason'] ?? '');

    if ($req_upazila === '' || $req_district === '') {
        $flash_msg  = "Please specify both Target Upazila and District to request UNO activation.";
        $flash_type = "alert-error";
    } else {
        $r_name  = $user['name'] ?? 'Admin';
        $r_desig = $admin_designation !== '' ? $admin_designation : 'Officer';

        $stmt_ar = $conn->prepare("
            INSERT INTO admin_requests
            (requester_admin_id, requester_name, requester_designation, request_target, request_type, target_upazila, target_district, requested_status, reason, status)
            VALUES (?, ?, ?, 'uno', 'transfer_activation', ?, ?, 'active', ?, 'pending')
        ");
        $stmt_ar->bind_param("isssss", $admin_id, $r_name, $r_desig, $req_upazila, $req_district, $req_reason);
        if ($stmt_ar->execute()) {
            $flash_msg = "Activation request submitted to the UNO of {$req_upazila}, {$req_district}.";
        } else {
            $flash_msg  = "Failed to submit activation request: " . $stmt_ar->error;
            $flash_type = "alert-error";
        }
        $stmt_ar->close();
    }
}

// 2B. Any Admin (including UNO) submits Request to Super Admin
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['request_super_admin'])) {
    $req_type     = trim($_POST['req_type'] ?? 'transfer_request');
    $req_upazila  = trim($_POST['sa_upazila'] ?? $admin_upazila);
    $req_district = trim($_POST['sa_district'] ?? $admin_district);
    $req_reason   = trim($_POST['sa_reason'] ?? '');

    if ($req_reason === '') {
        $flash_msg  = "Please provide details for your request to the Super Admin.";
        $flash_type = "alert-error";
    } else {
        $r_name  = $user['name'] ?? 'Admin';
        $r_desig = $admin_designation !== '' ? $admin_designation : 'Officer';

        $stmt_sa = $conn->prepare("
            INSERT INTO admin_requests
            (requester_admin_id, requester_name, requester_designation, request_target, request_type, target_upazila, target_district, requested_status, reason, status)
            VALUES (?, ?, ?, 'super_admin', ?, ?, ?, 'active', ?, 'pending')
        ");
        $stmt_sa->bind_param("issssss", $admin_id, $r_name, $r_desig, $req_type, $req_upazila, $req_district, $req_reason);
        if ($stmt_sa->execute()) {
            $flash_msg = "Request sent to the Super Admin.";
        } else {
            $flash_msg  = "Failed to send request to Super Admin: " . $stmt_sa->error;
            $flash_type = "alert-error";
        }
        $stmt_sa->close();
    }
}

// 2C. UNO clicks "Activate" on a Transfer Activation Request for their Upazila
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['uno_activate_request'])) {
    if (!$is_uno || $current_acc_status !== 'active') {
        $flash_msg  = "Access denied: Only an active UNO can activate officers for their Upazila.";
        $flash_type = "alert-error";
    } else {
        $req_id = intval($_POST['request_id'] ?? 0);
        if ($req_id > 0) {
            $stmt_get_r = $conn->prepare("
                SELECT * FROM admin_requests
                WHERE id = ? AND request_target = 'uno' AND status = 'pending'
                LIMIT 1
            ");
            $stmt_get_r->bind_param("i", $req_id);
            $stmt_get_r->execute();
            $req_row = $stmt_get_r->get_result()->fetch_assoc();
            $stmt_get_r->close();

            if (!$req_row) {
                $flash_msg  = "Pending activation request not found.";
                $flash_type = "alert-error";
            } elseif (
                strcasecmp(trim($req_row['target_upazila'] ?? ''), $admin_upazila) !== 0 ||
                strcasecmp(trim($req_row['target_district'] ?? ''), $admin_district) !== 0
            ) {
                $flash_msg  = "Access denied: You can only activate requests for your own Upazila ({$admin_upazila}, {$admin_district}).";
                $flash_type = "alert-error";
            } else {
                $target_off_id = intval($req_row['requester_admin_id']);
                $stmt_chk_off  = $conn->prepare("SELECT id, designation FROM admins WHERE id = ? LIMIT 1");
                $stmt_chk_off->bind_param("i", $target_off_id);
                $stmt_chk_off->execute();
                $target_off = $stmt_chk_off->get_result()->fetch_assoc();
                $stmt_chk_off->close();

                if (!$target_off) {
                    $flash_msg  = "Officer account not found.";
                    $flash_type = "alert-error";
                } elseif (in_array(resolveOfficerRoleFromDesignation($target_off['designation'] ?? ''), ['uno', 'super_admin'], true)) {
                    $flash_msg  = "Only Super Admin can transfer or activate a UNO or Super Admin.";
                    $flash_type = "alert-error";
                } else {
                    $note = "Activated by UNO {$user['name']} for {$admin_upazila}, {$admin_district}";
                    $stmt_act = $conn->prepare("
                        UPDATE admins
                        SET upazila = ?, district = ?, account_status = 'active', status_reason = ?, status_updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt_act->bind_param("sssi", $admin_upazila, $admin_district, $note, $target_off_id);
                    $stmt_act->execute();
                    $stmt_act->close();

                    $reviewer_name = $user['name'] ?? 'UNO';
                    $stmt_upd_r = $conn->prepare("
                        UPDATE admin_requests
                        SET status = 'approved', reviewed_by_id = ?, reviewed_by_name = ?, reviewed_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt_upd_r->bind_param("isi", $admin_id, $reviewer_name, $req_id);
                    $stmt_upd_r->execute();
                    $stmt_upd_r->close();

                    $flash_msg = "Officer '{$req_row['requester_name']}' is now activated in {$admin_upazila}, {$admin_district} and visible in your officer list.";
                }
            }
        }
    }
}

// 2D. UNO updates status/reason of an officer strictly inside their own Upazila
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['uno_update_officer_status'])) {
    if (!$is_uno || $current_acc_status !== 'active') {
        $flash_msg  = "Access denied: Only an active UNO can update officer status in their own Upazila.";
        $flash_type = "alert-error";
    } else {
        $target_admin_id = intval($_POST['target_admin_id'] ?? 0);
        $new_status      = strtolower(trim($_POST['account_status'] ?? 'active'));
        $status_reason   = trim($_POST['status_reason'] ?? '');
        $allowed_stat    = ['active', 'transferred', 'retired', 'on_leave', 'suspended'];

        if (!in_array($new_status, $allowed_stat, true)) {
            $new_status = 'active';
        }

        if ($target_admin_id > 0 && $target_admin_id !== $admin_id) {
            $stmt_t = $conn->prepare("SELECT id, designation, upazila, district FROM admins WHERE id = ? LIMIT 1");
            $stmt_t->bind_param("i", $target_admin_id);
            $stmt_t->execute();
            $target_row = $stmt_t->get_result()->fetch_assoc();
            $stmt_t->close();

            if (!$target_row) {
                $flash_msg  = "Officer not found.";
                $flash_type = "alert-error";
            } elseif (
                strcasecmp(trim($target_row['upazila'] ?? ''), $admin_upazila) !== 0 ||
                strcasecmp(trim($target_row['district'] ?? ''), $admin_district) !== 0
            ) {
                $flash_msg  = "Access denied: You can only manage officers in your own Upazila ({$admin_upazila}).";
                $flash_type = "alert-error";
            } elseif (in_array(resolveOfficerRoleFromDesignation($target_row['designation'] ?? ''), ['uno', 'super_admin'], true)) {
                $flash_msg  = "Access denied: Only Super Admin can take action on a UNO or Super Admin.";
                $flash_type = "alert-error";
            } else {
                $stmt_os = $conn->prepare("UPDATE admins SET account_status=?, status_reason=?, status_updated_at=NOW() WHERE id=?");
                $stmt_os->bind_param("ssi", $new_status, $status_reason, $target_admin_id);
                if ($stmt_os->execute()) {
                    $flash_msg = "Officer status updated to '" . strtoupper(str_replace('_', ' ', $new_status)) . "' in {$admin_upazila}.";
                } else {
                    $flash_msg  = "Unable to update officer status.";
                    $flash_type = "alert-error";
                }
                $stmt_os->close();
            }
        }
    }
}

// 2E. Super Admin manages any Admin/UNO posting & status (within District if district-level Super Admin)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['super_admin_update_officer'])) {
    if (!$is_super_admin || $current_acc_status !== 'active') {
        $flash_msg  = "Access denied: Only an active Super Admin can perform this action.";
        $flash_type = "alert-error";
    } else {
        $target_admin_id = intval($_POST['target_admin_id'] ?? 0);
        $new_status      = strtolower(trim($_POST['account_status'] ?? 'active'));
        $status_reason   = trim($_POST['status_reason'] ?? '');
        $new_upazila     = trim($_POST['target_upazila'] ?? '');
        $new_district    = trim($_POST['target_district'] ?? ($has_district_scope ? $admin_district : ''));
        $allowed_stat    = ['active', 'pending', 'transferred', 'retired', 'on_leave', 'suspended'];

        if (!in_array($new_status, $allowed_stat, true)) {
            $new_status = 'active';
        }

        if ($has_district_scope && strcasecmp($new_district, $admin_district) !== 0) {
            $flash_msg  = "As a district-level Super Admin, your authority is limited to {$admin_district} district.";
            $flash_type = "alert-error";
        } elseif ($target_admin_id > 0) {
            $stmt_t = $conn->prepare("SELECT id, upazila, district FROM admins WHERE id = ? LIMIT 1");
            $stmt_t->bind_param("i", $target_admin_id);
            $stmt_t->execute();
            $target_row = $stmt_t->get_result()->fetch_assoc();
            $stmt_t->close();

            if (!$target_row) {
                $flash_msg  = "Target officer not found.";
                $flash_type = "alert-error";
            } elseif ($has_district_scope && strcasecmp(trim($target_row['district'] ?? ''), $admin_district) !== 0) {
                $flash_msg  = "Access denied: This officer belongs to another district outside {$admin_district}.";
                $flash_type = "alert-error";
            } else {
                $final_upazila  = ($new_upazila !== '') ? $new_upazila : trim($target_row['upazila'] ?? '');
                $final_district = $has_district_scope ? $admin_district : (($new_district !== '') ? $new_district : trim($target_row['district'] ?? ''));

                $stmt_sa_u = $conn->prepare("UPDATE admins SET upazila=?, district=?, account_status=?, status_reason=?, status_updated_at=NOW() WHERE id=?");
                $stmt_sa_u->bind_param("ssssi", $final_upazila, $final_district, $new_status, $status_reason, $target_admin_id);
                if ($stmt_sa_u->execute()) {
                    $flash_msg = "Officer updated: {$final_upazila}, {$final_district} -> " . strtoupper(str_replace('_', ' ', $new_status)) . ".";
                } else {
                    $flash_msg  = "Failed to update officer.";
                    $flash_type = "alert-error";
                }
                $stmt_sa_u->close();
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['super_admin_review_request'])) {
    if (!$is_super_admin || $current_acc_status !== 'active') {
        $flash_msg  = "Access denied: Only an active Super Admin can review these requests.";
        $flash_type = "alert-error";
    } else {
        $req_id   = intval($_POST['request_id'] ?? 0);
        $decision = strtolower(trim($_POST['decision'] ?? 'approved'));
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            $decision = 'rejected';
        }

        if ($req_id > 0) {
            $stmt_gr = $conn->prepare("SELECT * FROM admin_requests WHERE id = ? AND request_target = 'super_admin' AND status = 'pending' LIMIT 1");
            $stmt_gr->bind_param("i", $req_id);
            $stmt_gr->execute();
            $s_req = $stmt_gr->get_result()->fetch_assoc();
            $stmt_gr->close();

            if (!$s_req) {
                $flash_msg  = "Pending request not found.";
                $flash_type = "alert-error";
            } elseif ($has_district_scope && strcasecmp(trim($s_req['target_district'] ?? ''), $admin_district) !== 0) {
                $flash_msg  = "Access denied: This request is outside {$admin_district} district.";
                $flash_type = "alert-error";
            } else {
                $reviewer_name = $user['name'] ?? 'Super Admin';
                $stmt_ur = $conn->prepare("UPDATE admin_requests SET status=?, reviewed_by_id=?, reviewed_by_name=?, reviewed_at=NOW() WHERE id=?");
                $stmt_ur->bind_param("sisi", $decision, $admin_id, $reviewer_name, $req_id);
                $stmt_ur->execute();
                $stmt_ur->close();

                if ($decision === 'approved') {
                    $t_off_id = intval($s_req['requester_admin_id']);
                    $t_upz    = trim($s_req['target_upazila'] ?? '');
                    $t_dst    = trim($s_req['target_district'] ?? '');
                    $note     = "Approved by Super Admin ({$reviewer_name}): " . ($s_req['reason'] ?? '');

                    if ($t_upz !== '' && $t_dst !== '') {
                        $stmt_apply = $conn->prepare("UPDATE admins SET upazila=?, district=?, account_status='active', status_reason=?, status_updated_at=NOW() WHERE id=?");
                        $stmt_apply->bind_param("sssi", $t_upz, $t_dst, $note, $t_off_id);
                        $stmt_apply->execute();
                        $stmt_apply->close();
                    }
                }
                $flash_msg = "Request marked as " . strtoupper($decision) . ".";
            }
        }
    }
}

// 3. Handle Admin Profile Update (Including Profile Picture Upload; Upazila & District locked from self-edit)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $name        = trim($_POST['name'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $employee_id = trim($_POST['employee_id'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $photo_path  = trim($user['profile_photo'] ?? '');

    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $img_tmp  = $_FILES['profile_photo']['tmp_name'];
        $img_name = basename($_FILES['profile_photo']['name']);
        $img_ext  = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));
        $allowed_imgs = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($img_ext, $allowed_imgs, true)) {
            $photo_dir = "uploads/admin_photos/";
            if (!is_dir($photo_dir)) {
                mkdir($photo_dir, 0777, true);
            }
            $new_photo_file = "admin_" . $admin_id . "_" . time() . "." . $img_ext;
            $photo_dest = $photo_dir . $new_photo_file;

            if (move_uploaded_file($img_tmp, $photo_dest)) {
                $photo_path = $photo_dest;
            }
        } else {
            $flash_msg  = "Invalid image format. Only JPG, JPEG, PNG, or WEBP allowed.";
            $flash_type = "alert-error";
        }
    }

    if ($flash_type !== "alert-error") {
        $stmt = $conn->prepare("UPDATE admins SET name=?, phone=?, email=?, employee_id=?, designation=?, profile_photo=? WHERE id=?");
        $stmt->bind_param("ssssssi", $name, $phone, $email, $employee_id, $designation, $photo_path, $admin_id);

        if ($stmt->execute()) {
            $flash_msg = "Admin profile updated successfully.";
            $user['name']          = $name;
            $user['phone']         = $phone;
            $user['email']         = $email;
            $user['employee_id']   = $employee_id;
            $user['designation']   = $designation;
            $user['profile_photo'] = $photo_path;

            $admin_designation   = trim($designation);
            $officer_role        = resolveOfficerRoleFromDesignation($admin_designation);
            $is_super_admin      = ($officer_role === 'super_admin' || intval($user['is_super_admin'] ?? 0) === 1);
            $is_uno              = ($officer_role === 'uno');
            $is_industry_officer = ($officer_role === 'industry_commerce');
        } else {
            $flash_msg  = "Error updating profile: " . $stmt->error;
            $flash_type = "alert-error";
        }
        $stmt->close();
    }
}

// 4. Handle Universal Upload Notice
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['upload_notice'])) {
    $title = trim($_POST['notice_title'] ?? '');

    if (isset($_FILES['notice_file']) && $_FILES['notice_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['notice_file']['tmp_name'];
        $file_name = basename($_FILES['notice_file']['name']);
        $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        if (in_array($file_ext, $allowed, true)) {
            $upload_dir = "uploads/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $safe_name     = preg_replace('/[^A-Za-z0-9._-]/', '_', $file_name);
            $new_file_name = uniqid() . '_' . $safe_name;
            $destination   = $upload_dir . $new_file_name;

            if (move_uploaded_file($file_tmp, $destination)) {
                $uploader_name  = $user['name'] ?? '';
                $uploader_desig = $user['designation'] ?? '';
                $uploader_email = $user['email'] ?? '';
                $upz            = $user['upazila'] ?? '';
                $dst            = $user['district'] ?? '';

                $stmt = $conn->prepare("INSERT INTO official_documents (admin_id, uploader_name, uploader_designation, uploader_email, upazila, district, title, file_path, file_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issssssss", $admin_id, $uploader_name, $uploader_desig, $uploader_email, $upz, $dst, $title, $destination, $file_ext);
                if ($stmt->execute()) {
                    $flash_msg = "Universal notice uploaded successfully.";
                } else {
                    $flash_msg  = "Database error: " . $stmt->error;
                    $flash_type = "alert-error";
                }
                $stmt->close();
            } else {
                $flash_msg  = "Failed to move uploaded file.";
                $flash_type = "alert-error";
            }
        } else {
            $flash_msg  = "Invalid file type. Only PDF, JPG, JPEG, PNG allowed.";
            $flash_type = "alert-error";
        }
    } else {
        $flash_msg  = "Please select a valid notice file.";
        $flash_type = "alert-error";
    }
}

// 5. Handle Issue Local Directive (Blocked if suspended or on_leave)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['issue_order'])) {
    if (!$can_issue_orders) {
        $flash_msg  = "Action blocked: Because your account status is '" . strtoupper(str_replace('_', ' ', $current_acc_status)) . "', you cannot issue directives to farmers or mill owners.";
        $flash_type = "alert-error";
    } else {
        $target_audience = trim($_POST['target_audience'] ?? '');
        $title           = trim($_POST['order_title'] ?? '');
        $details         = trim($_POST['order_details'] ?? '');
        $file_path       = null;
        $file_ext        = null;

        if (isset($_FILES['order_file']) && $_FILES['order_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp  = $_FILES['order_file']['tmp_name'];
            $file_name = basename($_FILES['order_file']['name']);
            $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            if (in_array($file_ext, $allowed, true)) {
                $upload_dir = "uploads/";
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $safe_name   = preg_replace('/[^A-Za-z0-9._-]/', '_', $file_name);
                $destination = $upload_dir . uniqid() . '_' . $safe_name;
                if (move_uploaded_file($file_tmp, $destination)) {
                    $file_path = $destination;
                }
            }
        }

        $stmt = $conn->prepare("INSERT INTO admin_orders (admin_id, district, upazila, target_audience, title, details, file_path, file_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssss", $admin_id, $admin_district, $admin_upazila, $target_audience, $title, $details, $file_path, $file_ext);
        if ($stmt->execute()) {
            $flash_msg = "Local directive issued successfully for your Upazila.";
        } else {
            $flash_msg  = "Failed to issue directive: " . $stmt->error;
            $flash_type = "alert-error";
        }
        $stmt->close();
    }
}

// 6. Handle Send Direct Chat Message
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_message'])) {
    $receiver_id   = intval($_POST['receiver_id'] ?? 0);
    $receiver_type = trim($_POST['receiver_type'] ?? 'mill_owner');
    $message       = trim($_POST['message'] ?? '');

    if ($receiver_id > 0 && $message !== '') {
        $stmt = $conn->prepare("INSERT INTO direct_messages (sender_type, sender_id, receiver_type, receiver_id, message) VALUES ('admin', ?, ?, ?, ?)");
        $stmt->bind_param("isis", $admin_id, $receiver_type, $receiver_id, $message);
        $stmt->execute();
        $stmt->close();
        header("Location: adminPage.php?section=chat-section&chat_with=" . $receiver_id);
        exit();
    }
}

// 7. Handle Complaint Resolution
if (isset($_GET['resolve_complaint_id'])) {
    $comp_id = intval($_GET['resolve_complaint_id']);
    if ($comp_id > 0) {
        $stmt = $conn->prepare("UPDATE complaints SET status = 'Resolved' WHERE id = ?");
        $stmt->bind_param("i", $comp_id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: adminPage.php?section=complaints-sec&msg=resolved");
    exit();
}

if (isset($_GET['msg']) && $_GET['msg'] === 'resolved') {
    $flash_msg = "Complaint marked as Resolved.";
}

// 8A. Insert Mill into `register_mill`
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_register_mill'])) {
    if (!$is_industry_officer || !$can_manage_mills) {
        $flash_msg  = "Access denied: Only an active Upazila Industry & Commerce Officer can add mills to register_mill.";
        $flash_type = "alert-error";
    } elseif ($admin_upazila === '' || $admin_district === '') {
        $flash_msg  = "Your admin profile must have an Upazila and District assigned.";
        $flash_type = "alert-error";
    } else {
        $mill_id    = strtoupper(trim($_POST['mill_ID'] ?? ''));
        $mill_name  = trim($_POST['mill_name'] ?? '');
        $mill_owner = trim($_POST['mill_owner'] ?? '');
        $nid        = trim($_POST['nid'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $village    = trim($_POST['village'] ?? '');
        $stsats     = in_array(($_POST['stsats'] ?? 'active'), ['active', 'closed'], true) ? $_POST['stsats'] : 'active';
        $reg_date   = trim($_POST['date'] ?? date('Y-m-d'));

        if ($mill_id === '' || $mill_name === '' || $mill_owner === '' || $nid === '' || $phone === '') {
            $flash_msg  = "Mill ID, Mill Name, Mill Owner, NID, and Phone are required.";
            $flash_type = "alert-error";
        } else {
            $stmt_ins = $conn->prepare("
                INSERT INTO register_mill
                (mill_ID, mill_name, mill_owner, nid, phone, village, upazila, district, stsats, date, registered_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_ins->bind_param(
                "ssssssssssi",
                $mill_id,
                $mill_name,
                $mill_owner,
                $nid,
                $phone,
                $village,
                $admin_upazila,
                $admin_district,
                $stsats,
                $reg_date,
                $admin_id
            );

            if ($stmt_ins->execute()) {
                $flash_msg = "Mill '{$mill_name}' ({$mill_id}) registered for {$admin_upazila}, {$admin_district}.";
            } else {
                $flash_msg  = "Failed to register mill: " . $stmt_ins->error;
                $flash_type = "alert-error";
            }
            $stmt_ins->close();
        }
    }
}

// 8B. Update Mill in `register_mill`
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_register_mill'])) {
    if (!$is_industry_officer || !$can_manage_mills) {
        $flash_msg  = "Access denied: Only an active Upazila Industry & Commerce Officer can update mills.";
        $flash_type = "alert-error";
    } else {
        $row_id     = intval($_POST['row_id'] ?? 0);
        $mill_name  = trim($_POST['mill_name'] ?? '');
        $mill_owner = trim($_POST['mill_owner'] ?? '');
        $nid        = trim($_POST['nid'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $village    = trim($_POST['village'] ?? '');
        $stsats     = in_array(($_POST['stsats'] ?? 'active'), ['active', 'closed'], true) ? $_POST['stsats'] : 'active';
        $reg_date   = trim($_POST['date'] ?? date('Y-m-d'));

        if ($row_id > 0 && $mill_name !== '' && $mill_owner !== '' && $nid !== '' && $phone !== '') {
            $stmt_upd = $conn->prepare("
                UPDATE register_mill
                SET mill_name=?, mill_owner=?, nid=?, phone=?, village=?, stsats=?, date=?
                WHERE id=? AND upazila=? AND district=?
            ");
            $stmt_upd->bind_param(
                "sssssssiss",
                $mill_name,
                $mill_owner,
                $nid,
                $phone,
                $village,
                $stsats,
                $reg_date,
                $row_id,
                $admin_upazila,
                $admin_district
            );
            if ($stmt_upd->execute() && $stmt_upd->affected_rows > 0) {
                $flash_msg = "Mill record updated successfully.";
            } else {
                $flash_msg  = "Update failed or record belongs to another Upazila.";
                $flash_type = "alert-error";
            }
            $stmt_upd->close();
        }
    }
}

// 8C. Delete Mill from `register_mill`
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_register_mill'])) {
    if (!$is_industry_officer || !$can_manage_mills) {
        $flash_msg  = "Access denied: Only an active Upazila Industry & Commerce Officer can delete mills.";
        $flash_type = "alert-error";
    } else {
        $row_id = intval($_POST['row_id'] ?? 0);
        if ($row_id > 0) {
            $stmt_del = $conn->prepare("DELETE FROM register_mill WHERE id=? AND upazila=? AND district=?");
            $stmt_del->bind_param("iss", $row_id, $admin_upazila, $admin_district);
            if ($stmt_del->execute() && $stmt_del->affected_rows > 0) {
                $flash_msg = "Mill record deleted from register_mill.";
            } else {
                $flash_msg  = "Delete failed or record belongs to another Upazila.";
                $flash_type = "alert-error";
            }
            $stmt_del->close();
        }
    }
}

// 8D. Temporarily Suspend Mill Account
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['suspend_mill_account'])) {
    if (!$can_manage_mills) {
        $flash_msg  = "Action blocked: Because your account status is '" . strtoupper(str_replace('_', ' ', $current_acc_status)) . "', you cannot suspend mill accounts.";
        $flash_type = "alert-error";
    } else {
        $target_mill_id  = strtoupper(trim($_POST['mill_ID'] ?? ''));
        $complaint_no    = trim($_POST['complaint_no'] ?? '');
        $reason          = trim($_POST['reason'] ?? '');
        $suspended_until = trim($_POST['suspended_until'] ?? '');
        $today           = date('Y-m-d');

        if ($target_mill_id === '' || $complaint_no === '' || $reason === '' || $suspended_until === '') {
            $flash_msg  = "Mill ID, Complaint Number, Punishment Reason, and Suspension End Date are required.";
            $flash_type = "alert-error";
        } elseif ($suspended_until < $today) {
            $flash_msg  = "Suspension end date cannot be in the past.";
            $flash_type = "alert-error";
        } else {
            $stmt_m = $conn->prepare("
                SELECT id, mill_ID, mill_name, name, phone, upazila, district
                FROM mill_owners
                WHERE mill_ID = ? AND upazila LIKE ? AND district LIKE ?
                LIMIT 1
            ");
            $stmt_m->bind_param("sss", $target_mill_id, $upazila_like, $district_like);
            $stmt_m->execute();
            $mo = $stmt_m->get_result()->fetch_assoc();
            $stmt_m->close();

            if (!$mo) {
                $flash_msg  = "No registered mill account found for Mill ID '{$target_mill_id}' in {$admin_upazila}.";
                $flash_type = "alert-error";
            } else {
                $owner_id    = intval($mo['id']);
                $actor_name  = $user['name'] ?? 'Admin';
                $actor_desig = $admin_designation !== '' ? $admin_designation : 'Upazila Admin';

                $stmt_s = $conn->prepare("
                    INSERT INTO mill_suspensions
                    (mill_ID, mill_owner_id, upazila, district, complaint_no, reason, suspended_until, status, imposed_by_id, imposed_by_name, imposed_by_designation)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)
                ");
                $stmt_s->bind_param(
                    "sisssssiss",
                    $target_mill_id,
                    $owner_id,
                    $admin_upazila,
                    $admin_district,
                    $complaint_no,
                    $reason,
                    $suspended_until,
                    $admin_id,
                    $actor_name,
                    $actor_desig
                );

                if ($stmt_s->execute()) {
                    $stmt_u = $conn->prepare("
                        UPDATE mill_owners
                        SET account_status = 'suspended', suspended_until = ?, active_complaint_no = ?, suspension_reason = ?
                        WHERE id = ?
                    ");
                    $stmt_u->bind_param("sssi", $suspended_until, $complaint_no, $reason, $owner_id);
                    $stmt_u->execute();
                    $stmt_u->close();

                    $notif_title = "Mill Account Temporarily Suspended";
                    $notif_body  = "Complaint #{$complaint_no}: Your account ({$target_mill_id}) is suspended until {$suspended_until}. Reason: {$reason}. Posting and farmer messaging are disabled; admin messaging remains available.";
                    pushMillNotification($conn, $target_mill_id, $owner_id, 'SUSPENDED', $complaint_no, $notif_title, $notif_body);

                    $flash_msg = "Mill account '{$target_mill_id}' suspended until {$suspended_until} and notification sent.";
                } else {
                    $flash_msg  = "Failed to suspend mill account: " . $stmt_s->error;
                    $flash_type = "alert-error";
                }
                $stmt_s->close();
            }
        }
    }
}

// 8E. Reactivate Suspended Mill Account
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reactivate_mill_account'])) {
    if (!$can_manage_mills) {
        $flash_msg  = "Action blocked: Because your account status is '" . strtoupper(str_replace('_', ' ', $current_acc_status)) . "', you cannot reactivate mill accounts.";
        $flash_type = "alert-error";
    } else {
        $susp_id = intval($_POST['suspension_id'] ?? 0);
        if ($susp_id > 0) {
            $stmt_r = $conn->prepare("
                SELECT ms.*, imp.account_status AS imposing_officer_status
                FROM mill_suspensions ms
                LEFT JOIN admins imp ON ms.imposed_by_id = imp.id
                WHERE ms.id = ? AND ms.upazila LIKE ? AND ms.district LIKE ? AND ms.status = 'active'
                LIMIT 1
            ");
            $stmt_r->bind_param("iss", $susp_id, $upazila_like, $district_like);
            $stmt_r->execute();
            $s_row = $stmt_r->get_result()->fetch_assoc();
            $stmt_r->close();

            if (!$s_row) {
                $flash_msg  = "Active suspension record not found.";
                $flash_type = "alert-error";
            } else {
                $is_imposer          = (intval($s_row['imposed_by_id']) === $admin_id);
                $imposer_status      = strtolower(trim($s_row['imposing_officer_status'] ?? 'active'));
                $imposer_unavailable = ($imposer_status !== 'active');

                if (!$is_imposer && !$imposer_unavailable) {
                    $flash_msg  = "Priority restriction: {$s_row['imposed_by_name']} ({$s_row['imposed_by_designation']}) is active and has priority to reactivate this mill account unless transferred, retired, on leave, or suspended.";
                    $flash_type = "alert-error";
                } else {
                    $actor_name = $user['name'] ?? 'Admin';
                    $stmt_l = $conn->prepare("UPDATE mill_suspensions SET status = 'lifted', lifted_by_id = ?, lifted_by_name = ?, lifted_at = NOW() WHERE id = ?");
                    $stmt_l->bind_param("isi", $admin_id, $actor_name, $susp_id);
                    $stmt_l->execute();
                    $stmt_l->close();

                    $mCode = $s_row['mill_ID'];
                    $stmt_rem = $conn->prepare("SELECT COUNT(*) AS cnt FROM mill_suspensions WHERE mill_ID = ? AND status = 'active'");
                    $stmt_rem->bind_param("s", $mCode);
                    $stmt_rem->execute();
                    $rem_cnt = intval($stmt_rem->get_result()->fetch_assoc()['cnt'] ?? 0);
                    $stmt_rem->close();

                    if ($rem_cnt === 0) {
                        $stmt_a = $conn->prepare("UPDATE mill_owners SET account_status = 'active', suspended_until = NULL, active_complaint_no = NULL, suspension_reason = NULL WHERE mill_ID = ?");
                        $stmt_a->bind_param("s", $mCode);
                        $stmt_a->execute();
                        $stmt_a->close();
                    }

                    $owner_id    = !empty($s_row['mill_owner_id']) ? intval($s_row['mill_owner_id']) : null;
                    $notif_title = "Mill Account Reactivated";
                    $notif_body  = "Your mill account ({$mCode}) has been reactivated by {$actor_name}. You can now create posts and message farmers again.";
                    pushMillNotification($conn, $mCode, $owner_id, 'REACTIVATED', $s_row['complaint_no'] ?? '', $notif_title, $notif_body);

                    $flash_msg = "Mill account '{$mCode}' reactivated and notification sent to the mill owner.";
                }
            }
        }
    }
}

// 9. Fetch Filter & Search Parameters for Universal Notice Board
$notice_district = trim($_GET['notice_district'] ?? '');
$notice_upazila  = trim($_GET['notice_upazila'] ?? '');
$notice_search   = trim($_GET['notice_search'] ?? '');

$notice_query = "SELECT * FROM official_documents WHERE 1=1";
$n_params = [];
$n_types = "";

if ($notice_district !== '') {
    $notice_query .= " AND district LIKE ?";
    $n_params[] = "%" . $notice_district . "%";
    $n_types .= "s";
}
if ($notice_upazila !== '') {
    $notice_query .= " AND upazila LIKE ?";
    $n_params[] = "%" . $notice_upazila . "%";
    $n_types .= "s";
}
if ($notice_search !== '') {
    $notice_query .= " AND (title LIKE ? OR uploader_name LIKE ? OR upazila LIKE ? OR district LIKE ?)";
    $ns_like = "%" . $notice_search . "%";
    $n_params[] = $ns_like;
    $n_params[] = $ns_like;
    $n_params[] = $ns_like;
    $n_params[] = $ns_like;
    $n_types .= "ssss";
}
$notice_query .= " ORDER BY created_at DESC";

if (!empty($n_params)) {
    $stmt_docs = $conn->prepare($notice_query);
    $stmt_docs->bind_param($n_types, ...$n_params);
    $stmt_docs->execute();
    $documents_result = $stmt_docs->get_result();
    $stmt_docs->close();
} else {
    $documents_result = $conn->query($notice_query);
}

// 10. Fetch Upazila / District Data for Display
$dir_search = trim($_GET['dir_search'] ?? '');
$dir_like   = "%" . $dir_search . "%";

if ($dir_search !== '') {
    $stmt_f = $conn->prepare("SELECT id, name, nid, phone, village, upazila, district FROM farmers WHERE upazila LIKE ? AND district LIKE ? AND (name LIKE ? OR phone LIKE ? OR nid LIKE ? OR village LIKE ? OR upazila LIKE ? OR district LIKE ?) ORDER BY id DESC");
    $stmt_f->bind_param("ssssssss", $upazila_like, $district_like, $dir_like, $dir_like, $dir_like, $dir_like, $dir_like, $dir_like);
} else {
    $stmt_f = $conn->prepare("SELECT id, name, nid, phone, village, upazila, district FROM farmers WHERE upazila LIKE ? AND district LIKE ? ORDER BY id DESC");
    $stmt_f->bind_param("ss", $upazila_like, $district_like);
}
$stmt_f->execute();
$farmers_result = $stmt_f->get_result();
$total_farmers = $farmers_result ? $farmers_result->num_rows : 0;
$stmt_f->close();

if ($dir_search !== '') {
    $stmt_m = $conn->prepare("SELECT id, mill_ID, account_status, suspended_until, name, mill_name, phone, email, village, upazila, district FROM mill_owners WHERE upazila LIKE ? AND district LIKE ? AND (mill_name LIKE ? OR name LIKE ? OR mill_ID LIKE ? OR village LIKE ? OR upazila LIKE ? OR district LIKE ?) ORDER BY id DESC");
    $stmt_m->bind_param("ssssssss", $upazila_like, $district_like, $dir_like, $dir_like, $dir_like, $dir_like, $dir_like, $dir_like);
} else {
    $stmt_m = $conn->prepare("SELECT id, mill_ID, account_status, suspended_until, name, mill_name, phone, email, village, upazila, district FROM mill_owners WHERE upazila LIKE ? AND district LIKE ? ORDER BY id DESC");
    $stmt_m->bind_param("ss", $upazila_like, $district_like);
}
$stmt_m->execute();
$mills_result = $stmt_m->get_result();
$total_mills = $mills_result ? $mills_result->num_rows : 0;
$stmt_m->close();

$officers_result = null;
if ($is_super_admin) {
    if ($has_district_scope) {
        $stmt_off = $conn->prepare("SELECT id, name, employee_id, designation, upazila, district, account_status, status_reason, profile_photo FROM admins WHERE id <> ? AND district LIKE ? ORDER BY upazila ASC, name ASC");
        $stmt_off->bind_param("is", $admin_id, $district_like);
    } else {
        $stmt_off = $conn->prepare("SELECT id, name, employee_id, designation, upazila, district, account_status, status_reason, profile_photo FROM admins WHERE id <> ? ORDER BY district ASC, upazila ASC, name ASC");
        $stmt_off->bind_param("i", $admin_id);
    }
    $stmt_off->execute();
    $officers_result = $stmt_off->get_result();
    $stmt_off->close();
} elseif ($is_uno) {
    $stmt_off = $conn->prepare("SELECT id, name, employee_id, designation, upazila, district, account_status, status_reason, profile_photo FROM admins WHERE id <> ? AND upazila LIKE ? AND district LIKE ? ORDER BY name ASC");
    $stmt_off->bind_param("iss", $admin_id, $upazila_like, $district_like);
    $stmt_off->execute();
    $officers_result = $stmt_off->get_result();
    $stmt_off->close();
}

$uno_pending_requests = null;
$uno_pending_count = 0;
if ($is_uno) {
    $stmt_ur = $conn->prepare("
        SELECT ar.*, a.employee_id, a.phone, a.upazila AS current_upazila, a.district AS current_district, a.account_status AS current_status
        FROM admin_requests ar
        INNER JOIN admins a ON ar.requester_admin_id = a.id
        WHERE ar.request_target = 'uno'
          AND ar.status = 'pending'
          AND ar.target_upazila LIKE ?
          AND ar.target_district LIKE ?
        ORDER BY ar.created_at DESC
    ");
    $stmt_ur->bind_param("ss", $upazila_like, $district_like);
    $stmt_ur->execute();
    $uno_pending_requests = $stmt_ur->get_result();
    $uno_pending_count = $uno_pending_requests ? $uno_pending_requests->num_rows : 0;
    $stmt_ur->close();
}

$sa_pending_requests = null;
$sa_pending_count = 0;
if ($is_super_admin) {
    if ($has_district_scope) {
        $stmt_sr = $conn->prepare("
            SELECT ar.*, a.employee_id, a.phone, a.upazila AS current_upazila, a.district AS current_district
            FROM admin_requests ar
            INNER JOIN admins a ON ar.requester_admin_id = a.id
            WHERE ar.request_target = 'super_admin'
              AND ar.status = 'pending'
              AND ar.target_district LIKE ?
            ORDER BY ar.created_at DESC
        ");
        $stmt_sr->bind_param("s", $district_like);
    } else {
        $stmt_sr = $conn->prepare("
            SELECT ar.*, a.employee_id, a.phone, a.upazila AS current_upazila, a.district AS current_district
            FROM admin_requests ar
            INNER JOIN admins a ON ar.requester_admin_id = a.id
            WHERE ar.request_target = 'super_admin'
              AND ar.status = 'pending'
            ORDER BY ar.created_at DESC
        ");
    }
    $stmt_sr->execute();
    $sa_pending_requests = $stmt_sr->get_result();
    $sa_pending_count = $sa_pending_requests ? $sa_pending_requests->num_rows : 0;
    $stmt_sr->close();
}

$stmt_my_req = $conn->prepare("SELECT * FROM admin_requests WHERE requester_admin_id = ? ORDER BY created_at DESC");
$stmt_my_req->bind_param("i", $admin_id);
$stmt_my_req->execute();
$my_requests_result = $stmt_my_req->get_result();
$stmt_my_req->close();

$mill_id_lookup = strtoupper(trim($_GET['mill_id_lookup'] ?? ''));
$lookup_mill_row = null;
if ($mill_id_lookup !== '') {
    $stmt_lk = $conn->prepare("SELECT * FROM register_mill WHERE mill_ID = ? LIMIT 1");
    $stmt_lk->bind_param("s", $mill_id_lookup);
    $stmt_lk->execute();
    $lookup_mill_row = $stmt_lk->get_result()->fetch_assoc();
    $stmt_lk->close();
}

$stmt_rm = $conn->prepare("SELECT * FROM register_mill WHERE upazila LIKE ? AND district LIKE ? ORDER BY date DESC, id DESC");
$stmt_rm->bind_param("ss", $upazila_like, $district_like);
$stmt_rm->execute();
$register_mill_result = $stmt_rm->get_result();
$total_register_mill = $register_mill_result ? $register_mill_result->num_rows : 0;
$stmt_rm->close();

$stmt_susp = $conn->prepare("
    SELECT ms.*, mo.mill_name, mo.name AS owner_name, imp.account_status AS imposing_officer_status
    FROM mill_suspensions ms
    LEFT JOIN mill_owners mo ON ms.mill_ID = mo.mill_ID
    LEFT JOIN admins imp ON ms.imposed_by_id = imp.id
    WHERE ms.upazila LIKE ? AND ms.district LIKE ? AND ms.status = 'active'
    ORDER BY ms.created_at DESC
");
$stmt_susp->bind_param("ss", $upazila_like, $district_like);
$stmt_susp->execute();
$active_suspensions_result = $stmt_susp->get_result();
$active_suspensions_count = $active_suspensions_result ? $active_suspensions_result->num_rows : 0;
$stmt_susp->close();

$stmt_notifs = $conn->prepare("SELECT * FROM mill_notifications ORDER BY created_at DESC LIMIT 20");
$stmt_notifs->execute();
$mill_notifications_result = $stmt_notifs->get_result();
$stmt_notifs->close();

$stmt_chat_mills = $conn->prepare("
    SELECT mo.id, mo.mill_ID, mo.mill_name, mo.name, MAX(dm.created_at) AS last_msg_time
    FROM mill_owners mo
    INNER JOIN direct_messages dm
        ON (
            (dm.sender_type = 'admin' AND dm.sender_id = ? AND dm.receiver_type = 'mill_owner' AND dm.receiver_id = mo.id)
            OR
            (dm.sender_type = 'mill_owner' AND dm.sender_id = mo.id AND dm.receiver_type = 'admin' AND dm.receiver_id = ?)
        )
    GROUP BY mo.id, mo.mill_ID, mo.mill_name, mo.name
    ORDER BY last_msg_time DESC, mo.id DESC
");
$stmt_chat_mills->bind_param("ii", $admin_id, $admin_id);
$stmt_chat_mills->execute();
$mills_chat_list = $stmt_chat_mills->get_result();
$stmt_chat_mills->close();

$stmt_c = $conn->prepare("SELECT * FROM complaints WHERE district LIKE ? AND (upazila LIKE ? OR upazila IS NULL OR upazila = '') ORDER BY created_at DESC");
$stmt_c->bind_param("ss", $district_like, $upazila_like);
$stmt_c->execute();
$complaints_result = $stmt_c->get_result();
$stmt_c->close();

$stmt_pc = $conn->prepare("SELECT COUNT(*) AS cnt FROM complaints WHERE district LIKE ? AND (upazila LIKE ? OR upazila IS NULL OR upazila = '') AND status = 'Pending'");
$stmt_pc->bind_param("ss", $district_like, $upazila_like);
$stmt_pc->execute();
$pending_complaints = $stmt_pc->get_result()->fetch_assoc()['cnt'] ?? 0;
$stmt_pc->close();

$stmt_o = $conn->prepare("SELECT * FROM admin_orders WHERE admin_id = ? ORDER BY created_at DESC");
$stmt_o->bind_param("i", $admin_id);
$stmt_o->execute();
$orders_result = $stmt_o->get_result();
$total_orders = $orders_result ? $orders_result->num_rows : 0;
$stmt_o->close();

$stmt_my_notices = $conn->prepare("SELECT * FROM official_documents WHERE admin_id = ? ORDER BY created_at DESC");
$stmt_my_notices->bind_param("i", $admin_id);
$stmt_my_notices->execute();
$my_notices_result = $stmt_my_notices->get_result();
$total_my_notices = $my_notices_result ? $my_notices_result->num_rows : 0;
$stmt_my_notices->close();

$stmt_posts = $conn->prepare("
    SELECT mp.*,
           COALESCE(NULLIF(mo.mill_name, ''), mp.mill_name) AS display_mill_name,
           COALESCE(NULLIF(mo.name, ''), mp.owner_name) AS display_owner_name,
           COALESCE(NULLIF(mo.phone, ''), mp.phone) AS display_phone,
           COALESCE(
               NULLIF(TRIM(CONCAT_WS(', ', NULLIF(mo.village, ''), NULLIF(mo.upazila, ''), NULLIF(mo.district, ''))), ''),
               mp.address
           ) AS display_address,
           COALESCE(mo.id, mp.mill_id) AS resolved_mill_id
    FROM mill_post mp
    LEFT JOIN mill_owners mo ON mp.mill_id = mo.mill_ID OR mp.mill_id = CAST(mo.id AS CHAR)
    WHERE (mo.upazila LIKE ? AND mo.district LIKE ?) OR (mp.address LIKE ? AND mp.address LIKE ?)
    ORDER BY mp.created_at DESC
");
$stmt_posts->bind_param("ssss", $upazila_like, $district_like, $upazila_like, $district_like);
$stmt_posts->execute();
$posts_result = $stmt_posts->get_result();
$stmt_posts->close();
$total_posts = $posts_result ? $posts_result->num_rows : 0;

$raw_chat_with = trim($_GET['chat_with'] ?? '');
$active_chat_messages = null;
$active_mill_info = null;
$actual_mill_db_id = 0;

if ($raw_chat_with !== '') {
    $num_id = ctype_digit($raw_chat_with) ? intval($raw_chat_with) : 0;
    $stmt_mi = $conn->prepare("SELECT * FROM mill_owners WHERE id = ? OR mill_ID = ? LIMIT 1");
    $stmt_mi->bind_param("is", $num_id, $raw_chat_with);
    $stmt_mi->execute();
    $active_mill_info = $stmt_mi->get_result()->fetch_assoc();
    $stmt_mi->close();

    if ($active_mill_info) {
        $actual_mill_db_id = intval($active_mill_info['id']);
        $stmt_msg = $conn->prepare("SELECT * FROM direct_messages WHERE (sender_type='admin' AND sender_id=? AND receiver_type='mill_owner' AND receiver_id=?) OR (sender_type='mill_owner' AND sender_id=? AND receiver_type='admin' AND receiver_id=?) ORDER BY created_at ASC, id ASC");
        $stmt_msg->bind_param("iiii", $admin_id, $actual_mill_db_id, $actual_mill_db_id, $admin_id);
        $stmt_msg->execute();
        $active_chat_messages = $stmt_msg->get_result();
        $stmt_msg->close();
    }
}

$conn->close();

$default_section = 'dashboard-home';
if (isset($_GET['section'])) {
    $default_section = $_GET['section'];
} elseif ($mill_id_lookup !== '') {
    $default_section = 'mill-registry-sec';
} elseif ($notice_district !== '' || $notice_upazila !== '' || $notice_search !== '') {
    $default_section = 'dashboard-home';
} elseif ($dir_search !== '') {
    $default_section = 'district-directory';
} elseif ($raw_chat_with !== '') {
    $default_section = 'chat-section';
} elseif (
    isset($_POST['add_register_mill']) ||
    isset($_POST['update_register_mill']) ||
    isset($_POST['delete_register_mill']) ||
    isset($_POST['suspend_mill_account']) ||
    isset($_POST['reactivate_mill_account']) ||
    isset($_POST['uno_update_officer_status']) ||
    isset($_POST['uno_activate_request']) ||
    isset($_POST['super_admin_update_officer']) ||
    isset($_POST['super_admin_review_request'])
) {
    $default_section = 'mill-registry-sec';
} elseif (isset($_POST['upload_notice']) || isset($_POST['issue_order'])) {
    $default_section = 'directives-sec';
} elseif (isset($_POST['update_profile']) || isset($_POST['request_uno_activation']) || isset($_POST['request_super_admin'])) {
    $default_section = 'profileView';
}

$admin_photo = trim($user['profile_photo'] ?? '');
$admin_initial = strtoupper(substr(trim($user['name'] ?? 'A'), 0, 1));
$is_bn = ($current_lang === 'bn');
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_bn ? 'অ্যাডমিন প্যানেল | কৃষক বাজার' : "Admin Page | Farmer's Market"; ?></title>
    <link rel="stylesheet" href="adminProfileStyle.css">
    <style>
        .lang-switch-group { display: inline-flex; gap: 6px; align-items: center; }
        .lang-switch-btn {
            padding: 5px 11px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #0f172a;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }
        .lang-switch-btn.active {
            background: #059669;
            border-color: #059669;
            color: #ffffff;
        }
    </style>
</head>
<body>

   <header class="top-navbar">
        <div class="nav-container">
            <div class="brand-area">
                <div>
                    <div class="brand-title"><?php echo htmlspecialchars(__t('site_name')); ?></div>
                    <div class="brand-subtitle">
                        <?php echo htmlspecialchars($admin_designation); ?>
                        <?php if ($admin_upazila !== '' || $admin_district !== ''): ?>
                            | <?php echo $is_bn ? 'উপজেলা:' : 'Upazila:'; ?> <?php echo htmlspecialchars($admin_upazila); ?>, <?php echo $is_bn ? 'জেলা:' : 'District:'; ?> <?php echo htmlspecialchars($admin_district); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="nav-user-area" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <div class="lang-switch-group">
                    <a href="<?php echo lang_url('bn'); ?>" class="lang-switch-btn <?php echo $is_bn ? 'active' : ''; ?>">বাংলা</a>
                    <a href="<?php echo lang_url('en'); ?>" class="lang-switch-btn <?php echo !$is_bn ? 'active' : ''; ?>">English</a>
                </div>
                <?php if ($admin_photo !== '' && file_exists($admin_photo)): ?>
                    <img src="<?php echo htmlspecialchars($admin_photo); ?>" alt="Admin Photo" style="width:34px; height:34px; border-radius:50%; object-fit:cover; border:1px solid #cbd5e1;">
                <?php endif; ?>
                <span><?php echo $is_bn ? 'স্বাগতম,' : 'Welcome,'; ?> <strong><?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?></strong></span>
            </div>
        </div>
    </header>

    <div class="main-wrapper">

        <aside class="sidebar">
            <a onclick="switchSection('dashboard-home', this)" id="nav-dashboard-home" class="active-link">
                <span><?php echo $is_bn ? 'ড্যাশবোর্ড' : 'Dashboard'; ?></span>
            </a>
            <a onclick="switchSection('mill-registry-sec', this)" id="nav-mill-registry-sec">
                <span><?php echo $is_bn ? 'মিল রেজিস্ট্রি ও ব্যবস্থা' : 'Mill Registry & Actions'; ?></span>
                <?php $pending_badge = (int)$active_suspensions_count + (int)$uno_pending_count + (int)$sa_pending_count; ?>
                <?php if ($pending_badge > 0): ?>
                    <span class="badge-count"><?php echo $pending_badge; ?></span>
                <?php endif; ?>
            </a>
            <a onclick="switchSection('directives-sec', this)" id="nav-directives-sec">
                <span><?php echo $is_bn ? 'নির্দেশনা ও নোটিশ' : 'Directive'; ?></span>
            </a>
            <a onclick="switchSection('complaints-sec', this)" id="nav-complaints-sec">
                <span><?php echo $is_bn ? 'অভিযোগ' : 'Complaint'; ?></span>
                <span class="badge-count"><?php echo (int)$pending_complaints; ?></span>
            </a>
            <a onclick="switchSection('chat-section', this)" id="nav-chat-section">
                <span><?php echo $is_bn ? 'বার্তা' : 'Messages'; ?></span>
            </a>
            <a onclick="switchSection('district-directory', this)" id="nav-district-directory">
                <span><?php echo $is_bn ? 'কৃষক ও মিল মালিক' : 'Farmer & Mills'; ?></span>
            </a>
            <?php if ($is_super_admin): ?>
                <a href="manageAuthorizedOfficers.php" class="btn btn-emerald btn-sm"><?php echo $is_bn ? 'অনুমোদিত কর্মকর্তা তালিকা' : 'Authorized Officers Registry'; ?></a>
            <?php endif; ?>
            <a onclick="switchSection('profileView', this)" id="nav-profileView">
                <span><?php echo $is_bn ? 'অ্যাডমিন প্রোফাইল' : 'Admin Profile'; ?></span>
            </a>
            <a href="logout.php" class="logout-btn"><?php echo $is_bn ? 'লগআউট' : 'Logout'; ?></a>
        </aside>

        <main class="main-content">

            <?php if (in_array($current_acc_status, ['suspended', 'on_leave'], true)): ?>
                <div class="alert alert-error">
                    <strong><?php echo $is_bn ? 'অ্যাকাউন্টের অবস্থা:' : 'Account Status:'; ?> <?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', $current_acc_status))); ?></strong>
                    <?php if (!empty($user['status_reason'])): ?> — <?php echo htmlspecialchars($user['status_reason']); ?><?php endif; ?>.
                    <?php echo $is_bn ? 'আপনি প্রোফাইল দেখতে ও আবেদন পাঠাতে পারবেন, তবে নির্দেশনা দেওয়া বা মিল স্থগিত/সচল করা সাময়িক বন্ধ।' : 'You can view your ID/profile and submit requests, but issuing directives or suspending/reactivating mill accounts is disabled.'; ?>
                </div>
            <?php elseif ($current_acc_status === 'transferred'): ?>
                <div class="alert alert-error">
                    <strong><?php echo $is_bn ? 'অ্যাকাউন্টের অবস্থা: বদলিকৃত (TRANSFERRED)।' : 'Account Status: TRANSFERRED.'; ?></strong>
                    <?php echo $is_bn ? 'নতুন উপজেলার ইউএনও (UNO) অথবা সুপার অ্যাডমিনের কাছে সক্রিয় করার আবেদন পাঠান।' : 'Please submit an activation request from your Admin Profile to the UNO of your new Upazila (or to the Super Admin).'; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($flash_msg)): ?>
                <div class="alert <?php echo htmlspecialchars($flash_type); ?>"><?php echo htmlspecialchars($flash_msg); ?></div>
            <?php endif; ?>

            <!-- 1. DASHBOARD TAB -->
            <div id="dashboard-home" class="tab-section active-section">

                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'উপজেলা সারসংক্ষেপ:' : 'Upazila Overview:'; ?> <?php echo htmlspecialchars($admin_upazila); ?></span>
                    </h2>
                    <p style="font-size: 13px; color: #64748b; margin-bottom: 16px;">
                        <?php echo $is_bn ? 'আপনার নির্ধারিত উপজেলা ও জেলার সার্বিক পরিসংখ্যান।' : 'Summary statistics for your assigned Upazila and District.'; ?>
                    </p>

                    <div class="stats-grid">
                        <div class="stat-card" style="cursor: pointer;" onclick="switchSection('district-directory', document.getElementById('nav-district-directory'))">
                            <div class="stat-label"><?php echo $is_bn ? 'উপজেলার কৃষক' : 'Upazila Farmers'; ?></div>
                            <div class="stat-value"><?php echo (int)$total_farmers; ?> <?php echo $is_bn ? 'জন নিবন্ধিত' : 'Registered'; ?></div>
                        </div>
                        <div class="stat-card blue" style="cursor: pointer;" onclick="switchSection('district-directory', document.getElementById('nav-district-directory'))">
                            <div class="stat-label"><?php echo $is_bn ? 'উপজেলার মিল মালিক' : 'Upazila Mill Owners'; ?></div>
                            <div class="stat-value"><?php echo (int)$total_mills; ?> <?php echo $is_bn ? 'টি মিল' : 'Mills'; ?></div>
                        </div>
                        <div class="stat-card purple" style="cursor: pointer;" onclick="switchSection('directives-sec', document.getElementById('nav-directives-sec'))">
                            <div class="stat-label"><?php echo $is_bn ? 'জারিকৃত নির্দেশনা' : 'Issued Directives'; ?></div>
                            <div class="stat-value"><?php echo (int)$total_orders; ?> <?php echo $is_bn ? 'টি আদেশ' : 'Orders'; ?></div>
                        </div>
                        <div class="stat-card amber" style="cursor: pointer;" onclick="switchSection('complaints-sec', document.getElementById('nav-complaints-sec'))">
                            <div class="stat-label"><?php echo $is_bn ? 'অমীমাংসিত অভিযোগ' : 'Pending Complaints'; ?></div>
                            <div class="stat-value"><?php echo (int)$pending_complaints; ?> <?php echo $is_bn ? 'টি বাকি' : 'Unresolved'; ?></div>
                        </div>
                    </div>
                </div>

                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'উপজেলার মিল ফসল ক্রয় পোস্ট' : 'Upazila Mill Purchase Posts'; ?> (<?php echo (int)$total_posts; ?>)</span>
                    </h2>

                    <?php if ($posts_result && $posts_result->num_rows > 0): ?>
                        <div class="posts-grid">
                            <?php while ($row = $posts_result->fetch_assoc()): ?>
                                <?php $posted_date = !empty($row['created_at']) ? date('d M Y', strtotime($row['created_at'])) : ''; ?>
                                <div class="post-card compact-post">
                                    <div class="post-basic-info">
                                        <h4><?php echo htmlspecialchars($row['crop_name'] ?? ''); ?></h4>
                                        <p><strong><?php echo $is_bn ? 'ধরন:' : 'Type:'; ?></strong> <?php echo htmlspecialchars($row['crop_type'] ?? ''); ?></p>
                                        <p><strong><?php echo $is_bn ? 'দর:' : 'Price:'; ?></strong> <?php echo htmlspecialchars($row['price'] ?? ''); ?> <?php echo $is_bn ? 'টাকা /' : 'Taka /'; ?> <?php echo htmlspecialchars($row['unit'] ?? ''); ?></p>
                                    </div>

                                    <div class="post-expandable-details" id="details-<?php echo (int)$row['id']; ?>" style="display: none;">
                                        <p><strong><?php echo $is_bn ? 'ফসল:' : 'Crop:'; ?></strong> <?php echo htmlspecialchars($row['crop_name'] ?? ''); ?></p>
                                        <p><strong><?php echo $is_bn ? 'ধরন:' : 'Type:'; ?></strong> <?php echo htmlspecialchars($row['crop_type'] ?? ''); ?></p>
                                        <p><strong><?php echo $is_bn ? 'দর:' : 'Price:'; ?></strong> <?php echo htmlspecialchars($row['price'] ?? ''); ?> <?php echo $is_bn ? 'টাকা /' : 'Taka /'; ?> <?php echo htmlspecialchars($row['unit'] ?? ''); ?></p>
                                        <p><strong><?php echo $is_bn ? 'আর্দ্রতা:' : 'Moisture:'; ?></strong> <?php echo htmlspecialchars($row['crop_moisture'] ?? ''); ?></p>
                                        <p><strong><?php echo $is_bn ? 'বিবরণ:' : 'Description:'; ?></strong> <?php echo nl2br(htmlspecialchars($row['description'] ?? '')); ?></p>
                                        <hr style="border:0; border-top:1px solid #e2e8f0; margin:10px 0;">
                                        <p><strong><?php echo $is_bn ? 'মিল:' : 'Mill:'; ?></strong> <?php echo htmlspecialchars($row['display_mill_name'] ?? ''); ?></p>
                                        <p><strong><?php echo $is_bn ? 'মালিক:' : 'Owner:'; ?></strong> <?php echo htmlspecialchars($row['display_owner_name'] ?? ''); ?></p>
                                        <p><strong><?php echo $is_bn ? 'ফোন:' : 'Phone:'; ?></strong> <?php echo htmlspecialchars($row['display_phone'] ?? ''); ?></p>
                                        <p><strong><?php echo $is_bn ? 'ঠিকানা:' : 'Address:'; ?></strong> <?php echo htmlspecialchars($row['display_address'] ?? ''); ?></p>
                                    </div>

                                    <div class="post-footer-area" style="margin-top: 10px;">
                                        <div class="post-meta" style="margin-bottom: 8px;">
                                            <span><?php echo $is_bn ? 'পোস্ট:' : 'Posted:'; ?></span>
                                            <span><?php echo htmlspecialchars($posted_date); ?></span>
                                        </div>

                                        <button
                                            type="button"
                                            class="btn btn-secondary toggle-post-btn"
                                            id="btn-<?php echo (int)$row['id']; ?>"
                                            data-mill-id="<?php echo htmlspecialchars($row['resolved_mill_id'] ?? '', ENT_QUOTES); ?>"
                                            onclick="openPostPopup(<?php echo (int)$row['id']; ?>, this)"
                                            style="width:100%; background:#64748b; padding: 5px 10px; font-size: 11.5px;"
                                        >
                                            <?php echo htmlspecialchars(__t('show_more')); ?>
                                        </button>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p style="font-size: 14px; color: #64748b;"><?php echo $is_bn ? 'এই উপজেলায় কোনো ফসল ক্রয় পোস্ট নেই।' : 'No crop purchase posts available for this upazila.'; ?></p>
                    <?php endif; ?>
                </div>

                <div class="card-box">
                    <h2 class="section-title">
                        <span><?php echo htmlspecialchars(__t('notice_board')); ?></span>

                        <form method="GET" action="adminPage.php" class="filter-bar">
                            <input type="hidden" name="section" value="dashboard-home">

                            <input type="text" name="notice_search" value="<?php echo htmlspecialchars($notice_search); ?>" placeholder="<?php echo $is_bn ? 'নোটিশ খুঁজুন...' : 'Search notice...'; ?>" class="form-control" style="width: 155px; padding: 6px 10px; font-size: 13px;">

                            <select name="notice_district" id="noticeDistrictSelect" onchange="populateNoticeUpazilas()" class="form-control" style="width: 150px; padding: 6px 10px; font-size: 13px;">
                                <option value=""><?php echo $is_bn ? 'জেলা নির্বাচন করুন' : 'Select District'; ?></option>
                                <option value="Bogura" <?php if ($notice_district === 'Bogura') echo 'selected'; ?>>Bogura</option>
                                <option value="Chapainawabganj" <?php if ($notice_district === 'Chapainawabganj') echo 'selected'; ?>>Chapainawabganj</option>
                                <option value="Joypurhat" <?php if ($notice_district === 'Joypurhat') echo 'selected'; ?>>Joypurhat</option>
                                <option value="Naogaon" <?php if ($notice_district === 'Naogaon') echo 'selected'; ?>>Naogaon</option>
                                <option value="Natore" <?php if ($notice_district === 'Natore') echo 'selected'; ?>>Natore</option>
                                <option value="Pabna" <?php if ($notice_district === 'Pabna') echo 'selected'; ?>>Pabna</option>
                                <option value="Rajshahi" <?php if ($notice_district === 'Rajshahi') echo 'selected'; ?>>Rajshahi</option>
                                <option value="Sirajganj" <?php if ($notice_district === 'Sirajganj') echo 'selected'; ?>>Sirajganj</option>
                            </select>

                            <select name="notice_upazila" id="noticeUpazilaSelect" class="form-control" style="width: 150px; padding: 6px 10px; font-size: 13px;">
                                <option value=""><?php echo $is_bn ? 'উপজেলা নির্বাচন করুন' : 'Select Upazila'; ?></option>
                            </select>

                            <button type="submit" class="btn btn-emerald btn-sm"><?php echo $is_bn ? 'ফিল্টার' : 'Filter'; ?></button>
                            <?php if ($notice_district !== '' || $notice_upazila !== '' || $notice_search !== ''): ?>
                                <a href="adminPage.php?section=dashboard-home" class="btn btn-sm" style="background:#64748b; color:#fff;"><?php echo $is_bn ? 'রিসেট' : 'Reset'; ?></a>
                            <?php endif; ?>
                        </form>
                    </h2>

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th><?php echo $is_bn ? 'তারিখ' : 'Date'; ?></th>
                                    <th><?php echo $is_bn ? 'নোটিশের শিরোনাম' : 'Notice Title'; ?></th>
                                    <th><?php echo $is_bn ? 'প্রকাশক ও পদবি' : 'Uploader Name & Designation'; ?></th>
                                    <th><?php echo $is_bn ? 'ইমেইল' : 'Email ID'; ?></th>
                                    <th><?php echo $is_bn ? 'উপজেলা ও জেলা' : 'Upazila & District'; ?></th>
                                    <th><?php echo $is_bn ? 'কার্যক্রম' : 'Action'; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($documents_result && $documents_result->num_rows > 0): ?>
                                    <?php while ($doc = $documents_result->fetch_assoc()): ?>
                                        <?php
                                            $loc = array_filter([$doc['upazila'] ?? '', $doc['district'] ?? '']);
                                            $loc_str = implode(', ', $loc);
                                            $doc_date = !empty($doc['created_at']) ? date('d M Y', strtotime($doc['created_at'])) : '';
                                            $uploader_admin_id = intval($doc['admin_id'] ?? 0);
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($doc_date); ?></td>
                                            <td><strong><?php echo htmlspecialchars($doc['title'] ?? ''); ?></strong></td>
                                            <td>
                                                <?php if ($uploader_admin_id > 0): ?>
                                                    <a href="viewProfile.php?type=admin&id=<?php echo $uploader_admin_id; ?>" style="color:#059669; font-weight:600; text-decoration:none;">
                                                        <?php echo htmlspecialchars($doc['uploader_name'] ?? ''); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($doc['uploader_name'] ?? ''); ?>
                                                <?php endif; ?>
                                                <?php if (!empty($doc['uploader_designation'])): ?>
                                                    <br><small style="color:#059669; font-weight:600;"><?php echo htmlspecialchars($doc['uploader_designation']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($doc['uploader_email'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($loc_str); ?></td>
                                            <td>
                                                <button
                                                    type="button"
                                                    class="btn btn-emerald btn-sm"
                                                    data-title="<?php echo htmlspecialchars($doc['title'] ?? '', ENT_QUOTES); ?>"
                                                    data-uploader="<?php echo htmlspecialchars($doc['uploader_name'] ?? '', ENT_QUOTES); ?>"
                                                    data-designation="<?php echo htmlspecialchars($doc['uploader_designation'] ?? '', ENT_QUOTES); ?>"
                                                    data-email="<?php echo htmlspecialchars($doc['uploader_email'] ?? '', ENT_QUOTES); ?>"
                                                    data-location="<?php echo htmlspecialchars($loc_str, ENT_QUOTES); ?>"
                                                    data-date="<?php echo htmlspecialchars($doc_date, ENT_QUOTES); ?>"
                                                    data-file-path="<?php echo htmlspecialchars($doc['file_path'] ?? '', ENT_QUOTES); ?>"
                                                    data-file-type="<?php echo htmlspecialchars(strtolower($doc['file_type'] ?? ''), ENT_QUOTES); ?>"
                                                    onclick="openNoticePopupFromBtn(this)"
                                                >
                                                    <?php echo $is_bn ? 'দেখুন' : 'View'; ?> <?php echo htmlspecialchars(strtoupper($doc['file_type'] ?? 'File')); ?>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" style="text-align: center;"><?php echo $is_bn ? 'কোনো নোটিশ পাওয়া যায়নি।' : 'No notices found.'; ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- 1B. MILL REGISTRY, ACTIONS & OFFICER CONTROL TAB -->
            <section id="mill-registry-sec" class="tab-section">

                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'অফিসিয়াল মিল রেজিস্ট্রি ও ব্যবস্থা' : 'Official Mill Registry & Account Actions'; ?> (<?php echo htmlspecialchars($admin_upazila); ?>)</span>
                        <span class="status-badge role-tag"><?php echo htmlspecialchars($admin_designation); ?></span>
                    </h2>
                    <p style="font-size: 13px; color: #475569;">
                        <?php if ($is_super_admin): ?>
                            <?php echo $is_bn
                                ? ($has_district_scope ? "সুপার অ্যাডমিন: আপনার কার্যक्षेत्र {$admin_district} জেলা পর্যন্ত সীমাবদ্ধ।" : "সুপার অ্যাডমিন: সাইট-ব্যাপী প্রশাসনিক নিয়ন্ত্রণ।")
                                : ($has_district_scope ? "Super Admin: Your authority is scoped to {$admin_district} district." : "Site-wide administrative authority."); ?>
                        <?php elseif ($is_uno): ?>
                            <?php echo $is_bn
                                ? "ইউএনও ({$admin_upazila}): আপনি শুধুমাত্র নিজ উপজেলার ({$admin_upazila}) কর্মকর্তাদের অবস্থা পরিচালনা, বদলি সক্রিয়করণ এবং সুপার অ্যাডমিনের কাছে আবেদন পাঠাতে পারবেন।"
                                : "UNO ({$admin_upazila}): You can only manage officers within your own Upazila ({$admin_upazila}), activate officers requesting transfer to {$admin_upazila}, and submit requests to the Super Admin."; ?>
                        <?php else: ?>
                            <?php echo $is_bn
                                ? "আপনি <code>register_mill</code>-এ মিল আইডি খুঁজতে, নিজ উপজেলার মিল রেকর্ড দেখতে এবং সক্রিয় থাকলে মিল সাময়িক স্থগিত/সচল করতে পারবেন।"
                                : "You can search Mill IDs in <code>register_mill</code>, view your Upazila's mill records, and manage mill suspensions when active."; ?>
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ($is_industry_officer && $can_manage_mills): ?>
                    <div class="card-box" style="margin-bottom: 24px;">
                        <h2 class="section-title">
                            <span><?php echo $is_bn ? 'নতুন মিল নিবন্ধন করুন' : 'Register New Mill'; ?> (<?php echo htmlspecialchars($admin_upazila); ?>, <?php echo htmlspecialchars($admin_district); ?>)</span>
                        </h2>
                        <form action="adminPage.php?section=mill-registry-sec" method="POST">
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'মিল আইডি' : 'Mill ID'; ?></label>
                                    <input type="text" name="mill_ID" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'মিলের নাম' : 'Mill Name'; ?></label>
                                    <input type="text" name="mill_name" class="form-control" required>
                                </div>
                            </div>
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'মিল মালিকের নাম' : 'Mill Owner Name'; ?></label>
                                    <input type="text" name="mill_owner" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'মিল মালিকের এনআইডি নম্বর' : 'Mill Owner NID Number'; ?></label>
                                    <input type="text" name="nid" class="form-control" required>
                                </div>
                            </div>
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'মিল মালিকের মোবাইল নম্বর' : 'Mill Owner Phone Number'; ?></label>
                                    <input type="text" name="phone" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'গ্রাম / ঠিকানা' : 'Village / Address'; ?></label>
                                    <input type="text" name="village" class="form-control">
                                </div>
                            </div>
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'মিলের অবস্থা' : 'Mill Status'; ?></label>
                                    <select name="stsats" class="form-control" required>
                                        <option value="active"><?php echo $is_bn ? 'সক্রিয় (Active)' : 'Active'; ?></option>
                                        <option value="closed"><?php echo $is_bn ? 'বন্ধ (Closed)' : 'Closed'; ?></option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'নিবন্ধনের তারিখ' : 'Official Registration Date'; ?></label>
                                    <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" class="form-control" required>
                                </div>
                            </div>
                            <button type="submit" name="add_register_mill" class="btn btn-emerald"><?php echo $is_bn ? 'মিল রেকর্ড সংরক্ষণ করুন' : 'Save Mill Record'; ?></button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Search Mill by Mill ID Number -->
                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'মিল আইডি দিয়ে মিল খুঁজুন' : 'Search Mill by Mill ID Number'; ?></span>
                        <form method="GET" action="adminPage.php" class="filter-bar">
                            <input type="hidden" name="section" value="mill-registry-sec">
                            <input type="text" name="mill_id_lookup" value="<?php echo htmlspecialchars($mill_id_lookup); ?>" placeholder="<?php echo $is_bn ? 'মিল আইডি লিখুন...' : 'Enter Mill ID...'; ?>" class="form-control" style="width: 190px; padding: 6px 10px; font-size: 13px;">
                            <button type="submit" class="btn btn-blue btn-sm"><?php echo $is_bn ? 'খুঁজুন' : 'Search'; ?></button>
                            <?php if ($mill_id_lookup !== ''): ?>
                                <a href="adminPage.php?section=mill-registry-sec" class="btn btn-sm" style="background:#64748b; color:#fff;"><?php echo $is_bn ? 'রিসেট' : 'Reset'; ?></a>
                            <?php endif; ?>
                        </form>
                    </h2>

                    <?php if ($mill_id_lookup !== ''): ?>
                        <?php if ($lookup_mill_row): ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo $is_bn ? 'মিল আইডি' : 'Mill ID'; ?></th>
                                            <th><?php echo $is_bn ? 'মিলের নাম' : 'Mill Name'; ?></th>
                                            <th><?php echo $is_bn ? 'মিল মালিক' : 'Mill Owner'; ?></th>
                                            <th><?php echo $is_bn ? 'এনআইডি ও ফোন' : 'NID & Phone'; ?></th>
                                            <th><?php echo $is_bn ? 'গ্রাম, উপজেলা, জেলা' : 'Village, Upazila, District'; ?></th>
                                            <th><?php echo $is_bn ? 'অবস্থা' : 'Status'; ?></th>
                                            <th><?php echo $is_bn ? 'নিবন্ধনের তারিখ' : 'Registration Date'; ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($lookup_mill_row['mill_ID'] ?? ''); ?></strong></td>
                                            <td><?php echo htmlspecialchars($lookup_mill_row['mill_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($lookup_mill_row['mill_owner'] ?? ''); ?></td>
                                            <td>
                                                NID: <?php echo htmlspecialchars($lookup_mill_row['nid'] ?? ''); ?><br>
                                                Phone: <?php echo htmlspecialchars($lookup_mill_row['phone'] ?? ''); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(formatLocationParts($lookup_mill_row)); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo (($lookup_mill_row['stsats'] ?? 'active') === 'active') ? 'status-resolved' : 'status-suspended'; ?>">
                                                    <?php echo htmlspecialchars(ucfirst($lookup_mill_row['stsats'] ?? 'active')); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($lookup_mill_row['date'] ?? ''); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="font-size: 13px; color: #991b1b;">
                                <?php echo $is_bn
                                    ? "মিল আইডি '" . htmlspecialchars($mill_id_lookup) . "'-এর জন্য কোনো রেকর্ড পাওয়া যায়নি।"
                                    : "No record found in <code>register_mill</code> for Mill ID '" . htmlspecialchars($mill_id_lookup) . "'."; ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Upazila `register_mill` Table -->
                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'নিবন্ধিত মিলসমূহ' : 'Registered Mills in'; ?> <?php echo htmlspecialchars($admin_upazila); ?> (<?php echo (int)$total_register_mill; ?>)</span>
                    </h2>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th><?php echo $is_bn ? 'মিল আইডি' : 'Mill ID'; ?></th>
                                    <th><?php echo $is_bn ? 'মিল ও মালিক' : 'Mill Name & Owner'; ?></th>
                                    <th><?php echo $is_bn ? 'এনআইডি ও ফোন' : 'NID & Phone'; ?></th>
                                    <th><?php echo $is_bn ? 'অবস্থান' : 'Village / Location'; ?></th>
                                    <th><?php echo $is_bn ? 'অবস্থা' : 'Status'; ?></th>
                                    <th><?php echo $is_bn ? 'তারিখ' : 'Date'; ?></th>
                                    <?php if ($is_industry_officer && $can_manage_mills): ?>
                                        <th><?php echo $is_bn ? 'ব্যবস্থাপনা' : 'Manage'; ?></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($register_mill_result && $register_mill_result->num_rows > 0): ?>
                                    <?php while ($rm = $register_mill_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($rm['mill_ID'] ?? ''); ?></strong></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($rm['mill_name'] ?? ''); ?></strong><br>
                                                <small><?php echo $is_bn ? 'মালিক:' : 'Owner:'; ?> <?php echo htmlspecialchars($rm['mill_owner'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <small>NID: <?php echo htmlspecialchars($rm['nid'] ?? ''); ?><br>
                                                Phone: <?php echo htmlspecialchars($rm['phone'] ?? ''); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars(formatLocationParts($rm)); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo (($rm['stsats'] ?? 'active') === 'active') ? 'status-resolved' : 'status-suspended'; ?>">
                                                    <?php echo htmlspecialchars(ucfirst($rm['stsats'] ?? 'active')); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars(!empty($rm['date']) ? date('d M Y', strtotime($rm['date'])) : ''); ?></td>
                                            <?php if ($is_industry_officer && $can_manage_mills): ?>
                                                <td>
                                                    <form method="POST" action="adminPage.php?section=mill-registry-sec" style="margin-bottom: 6px;">
                                                        <input type="hidden" name="row_id" value="<?php echo (int)$rm['id']; ?>">
                                                        <input type="hidden" name="mill_name" value="<?php echo htmlspecialchars($rm['mill_name'] ?? '', ENT_QUOTES); ?>">
                                                        <input type="hidden" name="mill_owner" value="<?php echo htmlspecialchars($rm['mill_owner'] ?? '', ENT_QUOTES); ?>">
                                                        <input type="hidden" name="nid" value="<?php echo htmlspecialchars($rm['nid'] ?? '', ENT_QUOTES); ?>">
                                                        <input type="hidden" name="phone" value="<?php echo htmlspecialchars($rm['phone'] ?? '', ENT_QUOTES); ?>">
                                                        <input type="hidden" name="village" value="<?php echo htmlspecialchars($rm['village'] ?? '', ENT_QUOTES); ?>">
                                                        <input type="hidden" name="date" value="<?php echo htmlspecialchars($rm['date'] ?? date('Y-m-d'), ENT_QUOTES); ?>">
                                                        <select name="stsats" class="form-control" style="padding: 3px 6px; font-size: 12px; margin-bottom: 4px;">
                                                            <option value="active" <?php if (($rm['stsats'] ?? '') === 'active') echo 'selected'; ?>>Active</option>
                                                            <option value="closed" <?php if (($rm['stsats'] ?? '') === 'closed') echo 'selected'; ?>>Closed</option>
                                                        </select>
                                                        <button type="submit" name="update_register_mill" class="btn btn-blue btn-sm"><?php echo $is_bn ? 'আপডেট' : 'Update'; ?></button>
                                                    </form>
                                                    <form method="POST" action="adminPage.php?section=mill-registry-sec" style="display:inline;">
                                                        <input type="hidden" name="row_id" value="<?php echo (int)$rm['id']; ?>">
                                                        <button type="submit" name="delete_register_mill" class="btn btn-danger btn-sm" onclick="return confirm('<?php echo $is_bn ? 'এই মিল রেকর্ডটি মুছে ফেলবেন?' : 'Delete this mill record?'; ?>');"><?php echo $is_bn ? 'মুছুন' : 'Delete'; ?></button>
                                                    </form>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="<?php echo ($is_industry_officer && $can_manage_mills) ? '7' : '6'; ?>" style="text-align:center;"><?php echo $is_bn ? 'এই উপজেলায় কোনো নিবন্ধিত মিল নেই।' : 'No mills found in register_mill for ' . htmlspecialchars($admin_upazila) . '.'; ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Temporarily Suspend Mill Account Form -->
                <?php if ($can_manage_mills): ?>
                    <div class="card-box" style="margin-bottom: 24px;">
                        <h2 class="section-title">
                            <span><?php echo $is_bn ? 'মিল অ্যাকাউন্ট সাময়িক স্থগিত করুন' : 'Temporarily Suspend Mill Account'; ?> (<?php echo htmlspecialchars($admin_upazila); ?>)</span>
                        </h2>
                        <form action="adminPage.php?section=mill-registry-sec" method="POST">
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'মিল আইডি' : 'Mill ID'; ?></label>
                                    <input type="text" name="mill_ID" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'অভিযোগ নম্বর' : 'Complaint Number'; ?></label>
                                    <input type="text" name="complaint_no" class="form-control" required>
                                </div>
                            </div>
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'যে তারিখ পর্যন্ত স্থগিত' : 'Suspended Until Date'; ?></label>
                                    <input type="date" name="suspended_until" min="<?php echo date('Y-m-d'); ?>" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'স্থগিতের কারণ' : 'Punishment Reason'; ?></label>
                                    <input type="text" name="reason" class="form-control" required>
                                </div>
                            </div>
                            <button type="submit" name="suspend_mill_account" class="btn btn-danger"><?php echo $is_bn ? 'মিল স্থগিত ও নোটিফিকেশন পাঠান' : 'Suspend Mill Account & Notify'; ?></button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Active Suspended Mill Accounts & Reactivation -->
                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'স্থগিতকৃত মিল অ্যাকাউন্টসমূহ' : 'Suspended Mill Accounts'; ?> (<?php echo (int)$active_suspensions_count; ?>)</span>
                    </h2>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th><?php echo $is_bn ? 'মিল আইডি ও নাম' : 'Mill ID & Name'; ?></th>
                                    <th><?php echo $is_bn ? 'অভিযোগ নং ও কারণ' : 'Complaint No. & Reason'; ?></th>
                                    <th><?php echo $is_bn ? 'স্থগিতের মেয়াদ' : 'Suspended Until'; ?></th>
                                    <th><?php echo $is_bn ? 'আদেশ প্রদানকারী' : 'Imposed By'; ?></th>
                                    <th><?php echo $is_bn ? 'কার্যক্রম' : 'Action'; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($active_suspensions_result && $active_suspensions_result->num_rows > 0): ?>
                                    <?php while ($sp = $active_suspensions_result->fetch_assoc()): ?>
                                        <?php
                                            $is_imposer          = (intval($sp['imposed_by_id'] ?? 0) === $admin_id);
                                            $imposer_status      = strtolower(trim($sp['imposing_officer_status'] ?? 'active'));
                                            $imposer_unavailable = ($imposer_status !== 'active');
                                            $can_lift            = ($can_manage_mills && ($is_imposer || $imposer_unavailable));
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($sp['mill_ID'] ?? ''); ?></strong><br>
                                                <small><?php echo htmlspecialchars($sp['mill_name'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <strong><?php echo $is_bn ? 'অভিযোগ:' : 'Complaint:'; ?> <?php echo htmlspecialchars($sp['complaint_no'] ?? ''); ?></strong><br>
                                                <small><?php echo htmlspecialchars($sp['reason'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <span class="status-badge status-suspended">
                                                    <?php echo $is_bn ? 'পর্যন্ত' : 'Until'; ?> <?php echo htmlspecialchars(!empty($sp['suspended_until']) ? date('d M Y', strtotime($sp['suspended_until'])) : ''); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($sp['imposed_by_name'] ?? ''); ?><br>
                                                <small><?php echo htmlspecialchars($sp['imposed_by_designation'] ?? ''); ?></small>
                                                <?php if ($imposer_unavailable): ?>
                                                    <br><span class="status-badge status-pending">Status: <?php echo htmlspecialchars(strtoupper($imposer_status)); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($can_lift): ?>
                                                    <form method="POST" action="adminPage.php?section=mill-registry-sec" style="display:inline;">
                                                        <input type="hidden" name="suspension_id" value="<?php echo (int)$sp['id']; ?>">
                                                        <button type="submit" name="reactivate_mill_account" class="btn btn-emerald btn-sm" onclick="return confirm('<?php echo $is_bn ? 'এই মিল অ্যাকাউন্টটি পুনরায় সচল করবেন?' : 'Reactivate this mill account and notify the owner?'; ?>');"><?php echo $is_bn ? 'সচল করুন' : 'Reactivate'; ?></button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="status-badge status-pending"><?php echo $is_bn ? 'অগ্রাধিকার:' : 'Priority:'; ?> <?php echo htmlspecialchars($sp['imposed_by_name'] ?? 'Imposing Officer'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align:center;"><?php echo $is_bn ? 'এই উপজেলায় কোনো স্থগিতকৃত মিল নেই।' : 'No suspended mill accounts in ' . htmlspecialchars($admin_upazila) . '.'; ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- UNO Transfer Activation Requests -->
                <?php if ($is_uno && $current_acc_status === 'active'): ?>
                    <div class="card-box" style="margin-bottom: 24px;">
                        <h2 class="section-title">
                            <span><?php echo $is_bn ? 'বদলি সক্রিয়করণের আবেদন' : 'Transfer Activation Requests for'; ?> <?php echo htmlspecialchars($admin_upazila); ?> (<?php echo (int)$uno_pending_count; ?>)</span>
                        </h2>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th><?php echo $is_bn ? 'কর্মকর্তার নাম ও আইডি' : 'Officer Name & ID'; ?></th>
                                        <th><?php echo $is_bn ? 'পদবি' : 'Designation'; ?></th>
                                        <th><?php echo $is_bn ? 'পূর্বের কর্মস্থল ও অবস্থা' : 'Previous Posting & Status'; ?></th>
                                        <th><?php echo $is_bn ? 'আবেদনের মন্তব্য' : 'Application Note'; ?></th>
                                        <th><?php echo $is_bn ? 'কার্যক্রম' : 'Action'; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($uno_pending_requests && $uno_pending_requests->num_rows > 0): ?>
                                        <?php while ($ur = $uno_pending_requests->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <a href="viewProfile.php?type=admin&id=<?php echo (int)$ur['requester_admin_id']; ?>" style="color:#059669; font-weight:600; text-decoration:none;">
                                                        <?php echo htmlspecialchars($ur['requester_name'] ?? ''); ?>
                                                    </a><br>
                                                    <small>ID: <?php echo htmlspecialchars($ur['employee_id'] ?? ''); ?> | <?php echo htmlspecialchars($ur['phone'] ?? ''); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($ur['requester_designation'] ?? ''); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars(trim(($ur['current_upazila'] ?? '') . ', ' . ($ur['current_district'] ?? ''), ', ')); ?><br>
                                                    <span class="status-badge status-pending"><?php echo htmlspecialchars(strtoupper($ur['current_status'] ?? 'transferred')); ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($ur['reason'] ?? ''); ?></td>
                                                <td>
                                                    <form method="POST" action="adminPage.php?section=mill-registry-sec" style="display:inline;">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$ur['id']; ?>">
                                                        <button type="submit" name="uno_activate_request" class="btn btn-emerald btn-sm" onclick="return confirm('Activate this officer for <?php echo htmlspecialchars($admin_upazila, ENT_QUOTES); ?>?');"><?php echo $is_bn ? 'সক্রিয় করুন' : 'Activate'; ?></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" style="text-align:center;"><?php echo $is_bn ? 'কোনো পেন্ডিং আবেদন নেই।' : 'No pending transfer activation requests for ' . htmlspecialchars($admin_upazila) . '.'; ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Super Admin Pending Requests Queue -->
                <?php if ($is_super_admin && $current_acc_status === 'active'): ?>
                    <div class="card-box" style="margin-bottom: 24px;">
                        <h2 class="section-title">
                            <span><?php echo $is_bn ? 'সুপার অ্যাডমিনের কাছে আবেদনসমূহ' : 'Requests to Super Admin'; ?> (<?php echo (int)$sa_pending_count; ?>)</span>
                        </h2>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th><?php echo $is_bn ? 'আবেদনকারী ও পদবি' : 'Requester & Designation'; ?></th>
                                        <th><?php echo $is_bn ? 'লক্ষ্য উপজেলা ও জেলা' : 'Target Upazila & District'; ?></th>
                                        <th><?php echo $is_bn ? 'ধরন ও কারণ' : 'Request Type & Reason'; ?></th>
                                        <th><?php echo $is_bn ? 'কার্যক্রম' : 'Action'; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($sa_pending_requests && $sa_pending_requests->num_rows > 0): ?>
                                        <?php while ($sr = $sa_pending_requests->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <a href="viewProfile.php?type=admin&id=<?php echo (int)$sr['requester_admin_id']; ?>" style="color:#059669; font-weight:600; text-decoration:none;">
                                                        <?php echo htmlspecialchars($sr['requester_name'] ?? ''); ?>
                                                    </a><br>
                                                    <small><?php echo htmlspecialchars($sr['requester_designation'] ?? ''); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars(trim(($sr['target_upazila'] ?? '') . ', ' . ($sr['target_district'] ?? ''), ', ')); ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', $sr['request_type'] ?? 'request'))); ?></strong><br>
                                                    <small><?php echo htmlspecialchars($sr['reason'] ?? ''); ?></small>
                                                </td>
                                                <td>
                                                    <form method="POST" action="adminPage.php?section=mill-registry-sec" style="display:flex; gap:6px;">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$sr['id']; ?>">
                                                        <button type="submit" name="super_admin_review_request" value="approved" onclick="this.form.decision.value='approved';" class="btn btn-emerald btn-sm"><?php echo $is_bn ? 'অনুমোদন' : 'Approve'; ?></button>
                                                        <button type="submit" name="super_admin_review_request" value="rejected" onclick="this.form.decision.value='rejected';" class="btn btn-danger btn-sm"><?php echo $is_bn ? 'বাতিল' : 'Reject'; ?></button>
                                                        <input type="hidden" name="decision" value="approved">
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" style="text-align:center;"><?php echo $is_bn ? 'কোনো পেন্ডিং আবেদন নেই।' : 'No pending requests for Super Admin.'; ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Officer Status & Posting Control -->
                <?php if (($is_uno || $is_super_admin) && $current_acc_status === 'active'): ?>
                    <div class="card-box" style="margin-bottom: 24px;">
                        <h2 class="section-title">
                            <span>
                                <?php if ($is_super_admin): ?>
                                    <?php echo $is_bn ? 'সুপার অ্যাডমিন: কর্মকর্তার পদায়ন ও অবস্থা নিয়ন্ত্রণ' : 'Super Admin: Officer Posting & Activity Status Control'; ?>
                                <?php else: ?>
                                    <?php echo $is_bn ? 'ইউএনও কর্মকর্তার অবস্থা নিয়ন্ত্রণ (শুধু উপজেলা:' : 'UNO Officer Status Control (Only Upazila:'; ?> <?php echo htmlspecialchars($admin_upazila); ?>)
                                <?php endif; ?>
                            </span>
                        </h2>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th><?php echo $is_bn ? 'কর্মকর্তার নাম ও আইডি' : 'Officer Name & ID'; ?></th>
                                        <th><?php echo $is_bn ? 'পদবি ও কর্মস্থল' : 'Designation & Posting'; ?></th>
                                        <th><?php echo $is_bn ? 'বর্তমান অবস্থা ও মন্তব্য' : 'Current Status & Note'; ?></th>
                                        <th><?php echo $is_bn ? 'আপডেট' : 'Update'; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($officers_result && $officers_result->num_rows > 0): ?>
                                        <?php while ($off = $officers_result->fetch_assoc()): ?>
                                            <?php
                                                $off_st = strtolower(trim($off['account_status'] ?? 'active'));
                                                $off_role = resolveOfficerRoleFromDesignation($off['designation'] ?? '');
                                            ?>
                                            <tr>
                                                <td>
                                                    <a href="viewProfile.php?type=admin&id=<?php echo (int)$off['id']; ?>" style="color:#059669; font-weight:600; text-decoration:none;">
                                                        <?php echo htmlspecialchars($off['name'] ?? ''); ?>
                                                    </a><br>
                                                    <small>ID: <?php echo htmlspecialchars($off['employee_id'] ?? ''); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($off['designation'] ?? ''); ?><br>
                                                    <small><?php echo htmlspecialchars(trim(($off['upazila'] ?? '') . ', ' . ($off['district'] ?? ''), ', ')); ?></small>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo ($off_st === 'active') ? 'status-resolved' : 'status-suspended'; ?>">
                                                        <?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', $off_st))); ?>
                                                    </span>
                                                    <?php if (!empty($off['status_reason'])): ?>
                                                        <br><small><?php echo htmlspecialchars($off['status_reason']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($is_super_admin): ?>
                                                        <form method="POST" action="adminPage.php?section=mill-registry-sec" style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
                                                            <input type="hidden" name="target_admin_id" value="<?php echo (int)$off['id']; ?>">
                                                            <input type="text" name="target_upazila" value="<?php echo htmlspecialchars($off['upazila'] ?? '', ENT_QUOTES); ?>" placeholder="Upazila" class="form-control" style="width:115px; padding:4px 8px; font-size:12px;" required>
                                                            <?php if ($has_district_scope): ?>
                                                                <input type="hidden" name="target_district" value="<?php echo htmlspecialchars($admin_district, ENT_QUOTES); ?>">
                                                            <?php else: ?>
                                                                <input type="text" name="target_district" value="<?php echo htmlspecialchars($off['district'] ?? '', ENT_QUOTES); ?>" placeholder="District" class="form-control" style="width:115px; padding:4px 8px; font-size:12px;" required>
                                                            <?php endif; ?>
                                                            <select name="account_status" class="form-control" style="width:125px; padding:4px 8px; font-size:12px;">
                                                                <option value="active" <?php if ($off_st === 'active') echo 'selected'; ?>>Active</option>
                                                                <option value="pending" <?php if ($off_st === 'pending') echo 'selected'; ?>>Pending</option>
                                                                <option value="transferred" <?php if ($off_st === 'transferred') echo 'selected'; ?>>Transferred</option>
                                                                <option value="retired" <?php if ($off_st === 'retired') echo 'selected'; ?>>Retired</option>
                                                                <option value="on_leave" <?php if ($off_st === 'on_leave') echo 'selected'; ?>>On Leave</option>
                                                                <option value="suspended" <?php if ($off_st === 'suspended') echo 'selected'; ?>>Suspended</option>
                                                            </select>
                                                            <input type="text" name="status_reason" value="<?php echo htmlspecialchars($off['status_reason'] ?? '', ENT_QUOTES); ?>" placeholder="Reason / order..." class="form-control" style="width:145px; padding:4px 8px; font-size:12px;">
                                                            <button type="submit" name="super_admin_update_officer" class="btn btn-blue btn-sm"><?php echo $is_bn ? 'সংরক্ষণ' : 'Save'; ?></button>
                                                        </form>
                                                    <?php elseif ($is_uno && !in_array($off_role, ['uno', 'super_admin'], true)): ?>
                                                        <form method="POST" action="adminPage.php?section=mill-registry-sec" style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
                                                            <input type="hidden" name="target_admin_id" value="<?php echo (int)$off['id']; ?>">
                                                            <select name="account_status" class="form-control" style="width:130px; padding:4px 8px; font-size:12px;">
                                                                <option value="active" <?php if ($off_st === 'active') echo 'selected'; ?>>Active</option>
                                                                <option value="transferred" <?php if ($off_st === 'transferred') echo 'selected'; ?>>Transferred</option>
                                                                <option value="retired" <?php if ($off_st === 'retired') echo 'selected'; ?>>Retired</option>
                                                                <option value="on_leave" <?php if ($off_st === 'on_leave') echo 'selected'; ?>>On Leave</option>
                                                                <option value="suspended" <?php if ($off_st === 'suspended') echo 'selected'; ?>>Suspended</option>
                                                            </select>
                                                            <input type="text" name="status_reason" value="<?php echo htmlspecialchars($off['status_reason'] ?? '', ENT_QUOTES); ?>" placeholder="Reason / order..." class="form-control" style="width:155px; padding:4px 8px; font-size:12px;">
                                                            <button type="submit" name="uno_update_officer_status" class="btn btn-blue btn-sm"><?php echo $is_bn ? 'সংরক্ষণ' : 'Save'; ?></button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="status-badge status-pending"><?php echo $is_bn ? 'সুপার অ্যাডমিন নিয়ন্ত্রিত' : 'Managed by Super Admin'; ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" style="text-align:center;"><?php echo $is_bn ? 'আপনার আওতায় কোনো কর্মকর্তা পাওয়া যায়নি।' : 'No officers found in your scope.'; ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Mill Action Notifications Log -->
                <div class="card-box">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'মিল স্থগিত ও পুনরায় সচলকরণ নোটিফিকেশন লগ' : 'Mill Suspension & Reactivation Notifications'; ?></span>
                    </h2>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th><?php echo $is_bn ? 'তারিখ ও সময়' : 'Date & Time'; ?></th>
                                    <th><?php echo $is_bn ? 'মিল আইডি' : 'Mill ID'; ?></th>
                                    <th><?php echo $is_bn ? 'ঘটনা' : 'Event'; ?></th>
                                    <th><?php echo $is_bn ? 'অভিযোগ নং' : 'Complaint No.'; ?></th>
                                    <th><?php echo $is_bn ? 'নোটিফিকেশন বার্তা' : 'Notification Message'; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($mill_notifications_result && $mill_notifications_result->num_rows > 0): ?>
                                    <?php while ($nf = $mill_notifications_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo !empty($nf['created_at']) ? date('d M Y, h:i A', strtotime($nf['created_at'])) : ''; ?></td>
                                            <td><strong><?php echo htmlspecialchars($nf['mill_ID'] ?? ''); ?></strong></td>
                                            <td>
                                                <span class="status-badge <?php echo ($nf['event_type'] === 'SUSPENDED') ? 'status-suspended' : 'status-resolved'; ?>">
                                                    <?php echo htmlspecialchars($nf['event_type'] ?? ''); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($nf['complaint_no'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($nf['message'] ?? ''); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align:center;"><?php echo $is_bn ? 'এখনও কোনো মিল নোটিফিকেশন পাঠানো হয়নি।' : 'No mill notifications sent yet.'; ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </section>

            <!-- 2. DIRECTIVE TAB -->
            <section id="directives-sec" class="tab-section">
                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'নোটিশ / মূল্যতালিকা আপলোড করুন (সকলের জন্য)' : 'Upload Notice / Price List (For Everyone)'; ?></span>
                    </h2>
                    <form action="adminPage.php?section=directives-sec" method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label class="form-label"><?php echo $is_bn ? 'নোটিশের শিরোনাম / বিবরণ' : 'Notice Title / Description'; ?></label>
                            <input type="text" name="notice_title" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?php echo $is_bn ? 'ফাইল নির্বাচন করুন (PDF, JPG, PNG)' : 'Select File (PDF, JPG, PNG)'; ?></label>
                            <input type="file" name="notice_file" class="form-control" accept=".pdf, .jpg, .jpeg, .png" required>
                        </div>
                        <button type="submit" name="upload_notice" class="btn btn-emerald"><?php echo $is_bn ? 'সর্বজনীন নোটিশ আপলোড করুন' : 'Upload Universal Notice'; ?></button>
                    </form>
                </div>

                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'স্থানীয় নির্দেশনা / আদেশ জারি করুন (শুধু আপনার উপজেলার জন্য)' : 'Issue Local Directive / Order (Only for Your Upazila)'; ?></span>
                    </h2>
                    <?php if (!$can_issue_orders): ?>
                        <p style="font-size: 13px; color: #991b1b;">
                            <?php echo $is_bn ? 'আপনার অ্যাকাউন্টের অবস্থা সক্রিয় না থাকায় নির্দেশনা জারি করা বন্ধ রয়েছে।' : 'Issuing directives to farmers or mill owners is disabled while your account status is not active.'; ?>
                        </p>
                    <?php else: ?>
                        <form action="adminPage.php?section=directives-sec" method="POST" enctype="multipart/form-data">
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'লক্ষ্য প্রাপক (আপনার উপজেলায়)' : 'Target Audience (In Your Upazila)'; ?></label>
                                    <select name="target_audience" class="form-control" required>
                                        <option value="mill_owner"><?php echo $is_bn ? 'মিল মালিকগণ' : 'Mill Owners'; ?></option>
                                        <option value="farmer"><?php echo $is_bn ? 'কৃষকগণ' : 'Farmers'; ?></option>
                                        <option value="both"><?php echo $is_bn ? 'কৃষক ও মিল মালিক উভয়ই' : 'Both Farmers & Mill Owners'; ?></option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'ফাইল সংযুক্তি (ঐচ্ছিক)' : 'Attach File (Optional)'; ?></label>
                                    <input type="file" name="order_file" class="form-control" accept=".pdf, .jpg, .jpeg, .png">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><?php echo $is_bn ? 'নির্দেশনার বিষয় / শিরোনাম' : 'Directive Subject / Title'; ?></label>
                                <input type="text" name="order_title" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><?php echo $is_bn ? 'নির্দেশনার বিস্তারিত' : 'Directive Details'; ?></label>
                                <textarea name="order_details" rows="3" class="form-control" required></textarea>
                            </div>
                            <button type="submit" name="issue_order" class="btn btn-blue"><?php echo $is_bn ? 'স্থানীয় নির্দেশনা জারি করুন' : 'Issue Local Directive'; ?></button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'আমার জারিকৃত স্থানীয় নির্দেশনার তালিকা' : 'My Issued Local Directives Record'; ?> (<?php echo (int)$total_orders; ?>)</span>
                    </h2>
                    <?php if ($orders_result && $orders_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th><?php echo $is_bn ? 'তারিখ' : 'Date'; ?></th>
                                        <th><?php echo $is_bn ? 'লক্ষ্য প্রাপক' : 'Target Audience'; ?></th>
                                        <th><?php echo $is_bn ? 'শিরোনাম ও বিবরণ' : 'Title & Details'; ?></th>
                                        <th><?php echo $is_bn ? 'সংযুক্ত ফাইল' : 'Attachment'; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($ord = $orders_result->fetch_assoc()): ?>
                                        <?php $ord_date = !empty($ord['created_at']) ? date('d M Y', strtotime($ord['created_at'])) : ''; ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($ord_date); ?></td>
                                            <td><span class="status-badge role-tag"><?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', $ord['target_audience'] ?? ''))); ?></span></td>
                                            <td><strong><?php echo htmlspecialchars($ord['title'] ?? ''); ?></strong><br><small><?php echo htmlspecialchars($ord['details'] ?? ''); ?></small></td>
                                            <td>
                                                <?php if (!empty($ord['file_path'])): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-emerald btn-sm"
                                                        data-title="<?php echo htmlspecialchars($ord['title'] ?? 'Directive Attachment', ENT_QUOTES); ?>"
                                                        data-meta="<?php echo htmlspecialchars('Date: ' . $ord_date . ' | Target: ' . strtoupper(str_replace('_', ' ', $ord['target_audience'] ?? '')), ENT_QUOTES); ?>"
                                                        data-file-path="<?php echo htmlspecialchars($ord['file_path'], ENT_QUOTES); ?>"
                                                        data-file-type="<?php echo htmlspecialchars(strtolower($ord['file_type'] ?? pathinfo($ord['file_path'], PATHINFO_EXTENSION)), ENT_QUOTES); ?>"
                                                        onclick="openDirectiveFilePopup(this)"
                                                    >
                                                        <?php echo $is_bn ? 'ফাইল দেখুন' : 'View File'; ?>
                                                    </button>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8; font-size: 11px;"><?php echo $is_bn ? 'নেই' : 'None'; ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="font-size: 14px; color: #64748b;"><?php echo $is_bn ? 'আপনি এখনও কোনো স্থানীয় নির্দেশনা জারি করেননি।' : 'No local directives issued by you yet.'; ?></p>
                    <?php endif; ?>
                </div>

                <div class="card-box">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'আমার আপলোডকৃত সর্বজনীন নোটিশের তালিকা' : 'My Uploaded Universal Notices Record'; ?> (<?php echo (int)$total_my_notices; ?>)</span>
                    </h2>
                    <?php if ($my_notices_result && $my_notices_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th><?php echo $is_bn ? 'তারিখ' : 'Date'; ?></th>
                                        <th><?php echo $is_bn ? 'নোটিশের শিরোনাম' : 'Notice Title'; ?></th>
                                        <th><?php echo $is_bn ? 'এলাকা' : 'Location'; ?></th>
                                        <th><?php echo $is_bn ? 'কার্যক্রম' : 'Action'; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($mnot = $my_notices_result->fetch_assoc()): ?>
                                        <?php
                                            $m_loc = implode(', ', array_filter([$mnot['upazila'] ?? '', $mnot['district'] ?? '']));
                                            $m_date = !empty($mnot['created_at']) ? date('d M Y', strtotime($mnot['created_at'])) : '';
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($m_date); ?></td>
                                            <td><strong><?php echo htmlspecialchars($mnot['title'] ?? ''); ?></strong></td>
                                            <td><?php echo htmlspecialchars($m_loc); ?></td>
                                            <td>
                                                <button
                                                    type="button"
                                                    class="btn btn-emerald btn-sm"
                                                    data-title="<?php echo htmlspecialchars($mnot['title'] ?? '', ENT_QUOTES); ?>"
                                                    data-uploader="<?php echo htmlspecialchars($mnot['uploader_name'] ?? '', ENT_QUOTES); ?>"
                                                    data-designation="<?php echo htmlspecialchars($mnot['uploader_designation'] ?? '', ENT_QUOTES); ?>"
                                                    data-email="<?php echo htmlspecialchars($mnot['uploader_email'] ?? '', ENT_QUOTES); ?>"
                                                    data-location="<?php echo htmlspecialchars($m_loc, ENT_QUOTES); ?>"
                                                    data-date="<?php echo htmlspecialchars($m_date, ENT_QUOTES); ?>"
                                                    data-file-path="<?php echo htmlspecialchars($mnot['file_path'] ?? '', ENT_QUOTES); ?>"
                                                    data-file-type="<?php echo htmlspecialchars(strtolower($mnot['file_type'] ?? ''), ENT_QUOTES); ?>"
                                                    onclick="openNoticePopupFromBtn(this)"
                                                >
                                                    <?php echo $is_bn ? 'দেখুন' : 'View'; ?> <?php echo htmlspecialchars(strtoupper($mnot['file_type'] ?? 'File')); ?>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="font-size: 14px; color: #64748b;"><?php echo $is_bn ? 'আপনি এখনও কোনো সর্বজনীন নোটিশ আপলোড করেননি।' : 'No universal notices uploaded by you yet.'; ?></p>
                    <?php endif; ?>
                </div>
            </section>

            <!-- 3. COMPLAINTS TAB -->
            <section id="complaints-sec" class="tab-section">
                <h2 class="section-title">
                    <span><?php echo $is_bn ? 'আপনার দপ্তরে আসা অভিযোগসমূহ' : 'Complaints Addressed to Your Office'; ?> <?php echo $admin_upazila !== '' ? '(' . htmlspecialchars($admin_upazila) . ')' : ''; ?></span>
                </h2>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?php echo $is_bn ? 'তারিখ' : 'Date'; ?></th>
                                <th><?php echo $is_bn ? 'প্রেরক' : 'Sender'; ?></th>
                                <th><?php echo $is_bn ? 'ভূমিকা' : 'Role'; ?></th>
                                <th><?php echo $is_bn ? 'ফোন' : 'Phone'; ?></th>
                                <th><?php echo $is_bn ? 'বিষয় ও বিবরণ' : 'Subject & Details'; ?></th>
                                <th><?php echo $is_bn ? 'অবস্থা' : 'Status'; ?></th>
                                <th><?php echo $is_bn ? 'কার্যক্রম' : 'Action'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($complaints_result && $complaints_result->num_rows > 0): ?>
                                <?php while ($comp = $complaints_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo !empty($comp['created_at']) ? date('d M Y', strtotime($comp['created_at'])) : ''; ?></td>
                                        <td><strong><?php echo htmlspecialchars($comp['sender_name'] ?? ''); ?></strong></td>
                                        <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $comp['sender_role'] ?? ''))); ?></td>
                                        <td><?php echo htmlspecialchars($comp['sender_phone'] ?? ''); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($comp['subject'] ?? ''); ?></strong><br>
                                            <small><?php echo htmlspecialchars($comp['description'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <?php if (($comp['status'] ?? '') === 'Pending'): ?>
                                                <span class="status-badge status-pending"><?php echo $is_bn ? 'অমীমাংসিত' : 'Pending'; ?></span>
                                            <?php else: ?>
                                                <span class="status-badge status-resolved"><?php echo $is_bn ? 'সমাধানকৃত' : 'Resolved'; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (($comp['status'] ?? '') === 'Pending'): ?>
                                                <a href="adminPage.php?resolve_complaint_id=<?php echo (int)$comp['id']; ?>" class="btn btn-emerald btn-sm" onclick="return confirm('<?php echo $is_bn ? 'অভিযোগটি সমাধানকৃত হিসেবে চিহ্নিত করবেন?' : 'Mark as resolved?'; ?>');"><?php echo $is_bn ? 'সমাধান করুন' : 'Resolve'; ?></a>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 12px;"><?php echo $is_bn ? 'সম্পন্ন' : 'Completed'; ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align: center;"><?php echo $is_bn ? 'আপনার দপ্তরে কোনো অভিযোগ নেই।' : 'No complaints recorded for your office.'; ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- 4. MESSAGES TAB -->
            <section id="chat-section" class="card-box tab-section">
                <h2 class="section-title">
                    <span><?php echo $is_bn ? 'মিল মালিকদের সাথে সাম্প্রতিক কথোপকথন' : 'Recent Conversations with Mill Owners'; ?></span>
                </h2>
                <div class="chat-container">
                    <div class="chat-sidebar-list">
                        <?php if ($mills_chat_list && $mills_chat_list->num_rows > 0): ?>
                            <?php while ($m_item = $mills_chat_list->fetch_assoc()): ?>
                                <?php $is_active_mill = ($actual_mill_db_id > 0 && $actual_mill_db_id === intval($m_item['id'])); ?>
                                <a href="adminPage.php?section=chat-section&chat_with=<?php echo (int)$m_item['id']; ?>" class="chat-user-item <?php echo $is_active_mill ? 'active' : ''; ?>" style="display:block; text-decoration:none; color:inherit;">
                                    <strong><?php echo htmlspecialchars($m_item['mill_name'] ?? ''); ?></strong><br>
                                    <small style="color:#64748b;"><?php echo $is_bn ? 'মালিক:' : 'Owner:'; ?> <?php echo htmlspecialchars($m_item['name'] ?? ''); ?></small>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="padding:12px; font-size:13px; color:#64748b;"><?php echo $is_bn ? 'এখনও কোনো কথোপকথন শুরু হয়নি।' : 'No active conversations yet.'; ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="chat-window-area">
                        <?php if ($actual_mill_db_id > 0 && $active_mill_info): ?>
                            <div style="padding: 10px 15px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size:14px; font-weight:600; display:flex; justify-content:space-between; align-items:center;">
                                <span><?php echo $is_bn ? 'কথোপকথন:' : 'Chat with:'; ?> <?php echo htmlspecialchars($active_mill_info['mill_name'] ?? ''); ?> (<?php echo htmlspecialchars($active_mill_info['name'] ?? ''); ?>)</span>
                                <a href="viewProfile.php?type=mill_owner&id=<?php echo (int)$actual_mill_db_id; ?>" class="btn btn-blue btn-sm"><?php echo $is_bn ? 'প্রোফাইল দেখুন' : 'View Profile'; ?></a>
                            </div>
                            <div class="chat-messages-box" id="chatMessagesBox">
                                <?php if ($active_chat_messages && $active_chat_messages->num_rows > 0): ?>
                                    <?php while ($msg = $active_chat_messages->fetch_assoc()): ?>
                                        <?php if (($msg['sender_type'] ?? '') === 'admin'): ?>
                                            <div class="chat-bubble outgoing">
                                                <?php echo htmlspecialchars($msg['message'] ?? ''); ?>
                                                <div style="font-size:10px; color:#d1fae5; text-align:right; margin-top:2px;"><?php echo !empty($msg['created_at']) ? date('h:i A', strtotime($msg['created_at'])) : ''; ?></div>
                                            </div>
                                        <?php else: ?>
                                            <div class="chat-bubble incoming">
                                                <?php echo htmlspecialchars($msg['message'] ?? ''); ?>
                                                <div style="font-size:10px; color:#94a3b8; text-align:right; margin-top:2px;"><?php echo !empty($msg['created_at']) ? date('h:i A', strtotime($msg['created_at'])) : ''; ?></div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div style="text-align:center; color:#94a3b8; margin-top: auto; margin-bottom: auto; font-size:13px;"><?php echo $is_bn ? 'কোনো বার্তা নেই। নিচে বার্তা লিখুন!' : 'No messages yet. Send a message below!'; ?></div>
                                <?php endif; ?>
                            </div>
                            <form action="adminPage.php?section=chat-section&chat_with=<?php echo (int)$actual_mill_db_id; ?>" method="POST" class="chat-input-form">
                                <input type="hidden" name="receiver_id" value="<?php echo (int)$actual_mill_db_id; ?>">
                                <input type="hidden" name="receiver_type" value="mill_owner">
                                <input type="text" name="message" placeholder="<?php echo $is_bn ? 'বার্তা লিখুন...' : 'Type a message...'; ?>" class="form-control" autocomplete="off" required style="flex:1;">
                                <button type="submit" name="send_message" class="btn btn-emerald"><?php echo $is_bn ? 'পাঠান' : 'Send'; ?></button>
                            </form>
                        <?php else: ?>
                            <div style="display:flex; align-items:center; justify-content:center; height:100%; color:#94a3b8; font-size:14px;">
                                <?php echo $is_bn ? 'কথোপকথন শুরু করতে বাম পাশ থেকে একটি মিল নির্বাচন করুন।' : 'Select a conversation from the left sidebar to start chatting.'; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- 5. FARMER & MILLS TAB -->
            <section id="district-directory" class="card-box tab-section">
                <h2 class="section-title">
                    <span><?php echo $is_bn ? 'নিবন্ধিত কৃষক ও মিল মালিক তালিকা' : 'Registered Farmers & Mill Owners in Upazila:'; ?> <?php echo htmlspecialchars($admin_upazila); ?></span>

                    <form method="GET" action="adminPage.php" class="filter-bar">
                        <input type="hidden" name="section" value="district-directory">
                        <input type="text" name="dir_search" value="<?php echo htmlspecialchars($dir_search); ?>" placeholder="<?php echo $is_bn ? 'কৃষক বা মিল খুঁজুন...' : 'Search farmer or mill...'; ?>" class="form-control" style="width: 195px; padding: 6px 10px; font-size: 13px;">
                        <button type="submit" class="btn btn-emerald btn-sm"><?php echo $is_bn ? 'খুঁজুন' : 'Search'; ?></button>
                        <?php if ($dir_search !== ''): ?>
                            <a href="adminPage.php?section=district-directory" class="btn btn-sm" style="background:#64748b; color:#fff;"><?php echo $is_bn ? 'রিসেট' : 'Reset'; ?></a>
                        <?php endif; ?>
                    </form>
                </h2>

                <h3 style="font-size: 14px; margin-bottom: 8px; color: #065f46;"><?php echo $is_bn ? 'নিবন্ধিত কৃষকগণ' : 'Registered Farmers'; ?> (<?php echo (int)$total_farmers; ?>)</h3>
                <div class="table-responsive" style="margin-bottom: 22px;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?php echo $is_bn ? 'আইডি' : 'ID'; ?></th>
                                <th><?php echo $is_bn ? 'কৃষকের নাম' : 'Farmer Name'; ?></th>
                                <th><?php echo $is_bn ? 'এনআইডি নম্বর' : 'NID Number'; ?></th>
                                <th><?php echo $is_bn ? 'ফোন' : 'Phone'; ?></th>
                                <th><?php echo $is_bn ? 'ঠিকানা' : 'Address'; ?></th>
                                <th><?php echo $is_bn ? 'প্রোফাইল' : 'Profile'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($farmers_result && $farmers_result->num_rows > 0): ?>
                                <?php $f_count = 0; while ($f = $farmers_result->fetch_assoc()): $f_count++; ?>
                                    <tr class="farmer-row <?php echo ($f_count > 5) ? 'hidden-row' : ''; ?>">
                                        <td>#<?php echo (int)$f['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($f['name'] ?? ''); ?></strong></td>
                                        <td><?php echo htmlspecialchars($f['nid'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($f['phone'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars(formatLocationParts($f)); ?></td>
                                        <td>
                                            <a href="viewProfile.php?type=farmer&id=<?php echo (int)$f['id']; ?>" class="btn btn-emerald btn-sm"><?php echo $is_bn ? 'প্রোফাইল দেখুন' : 'View Profile'; ?></a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="text-align:center;"><?php echo $is_bn ? 'এই উপজেলায় কোনো কৃষক পাওয়া যায়নি।' : 'No farmers found in this upazila.'; ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php if ($total_farmers > 5): ?>
                        <div class="view-more-btn-container">
                            <button type="button" id="toggleFarmersBtn" class="btn btn-emerald btn-sm" onclick="toggleData('farmer-row', 'toggleFarmersBtn')"><?php echo htmlspecialchars(__t('show_more')); ?></button>
                        </div>
                    <?php endif; ?>
                </div>

                <h3 style="font-size: 14px; margin-bottom: 8px; color: #1d4ed8;"><?php echo $is_bn ? 'নিবন্ধিত মিল মালিকগণ' : 'Registered Mill Owners'; ?> (<?php echo (int)$total_mills; ?>)</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?php echo $is_bn ? 'মিল আইডি' : 'Mill ID'; ?></th>
                                <th><?php echo $is_bn ? 'মিলের নাম' : 'Mill Name'; ?></th>
                                <th><?php echo $is_bn ? 'মালিকের নাম' : 'Owner Name'; ?></th>
                                <th><?php echo $is_bn ? 'অ্যাকাউন্টের অবস্থা' : 'Account Status'; ?></th>
                                <th><?php echo $is_bn ? 'ফোন' : 'Phone'; ?></th>
                                <th><?php echo $is_bn ? 'মিলের ঠিকানা' : 'Mill Address'; ?></th>
                                <th><?php echo $is_bn ? 'প্রোফাইল' : 'Profile'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($mills_result && $mills_result->num_rows > 0): ?>
                                <?php $m_count = 0; while ($m = $mills_result->fetch_assoc()): $m_count++; ?>
                                    <?php $acc_stat = $m['account_status'] ?? 'active'; ?>
                                    <tr class="mill-row <?php echo ($m_count > 5) ? 'hidden-row' : ''; ?>">
                                        <td><strong><?php echo htmlspecialchars($m['mill_ID'] ?? ''); ?></strong></td>
                                        <td><?php echo htmlspecialchars($m['mill_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($m['name'] ?? ''); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo ($acc_stat === 'suspended') ? 'status-suspended' : 'status-resolved'; ?>">
                                                <?php echo htmlspecialchars(ucfirst($acc_stat)); ?>
                                            </span>
                                            <?php if ($acc_stat === 'suspended' && !empty($m['suspended_until'])): ?>
                                                <br><small><?php echo htmlspecialchars($m['suspended_until']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($m['phone'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars(formatLocationParts($m)); ?></td>
                                        <td>
                                            <a href="viewProfile.php?type=mill_owner&id=<?php echo (int)$m['id']; ?>" class="btn btn-blue btn-sm"><?php echo $is_bn ? 'প্রোফাইল দেখুন' : 'View Profile'; ?></a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align:center;"><?php echo $is_bn ? 'এই উপজেলায় কোনো মিল মালিক পাওয়া যায়নি।' : 'No mill owners found in this upazila.'; ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php if ($total_mills > 5): ?>
                        <div class="view-more-btn-container">
                            <button type="button" id="toggleMillsBtn" class="btn btn-emerald btn-sm" onclick="toggleData('mill-row', 'toggleMillsBtn')"><?php echo htmlspecialchars(__t('show_more')); ?></button>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- 6. ADMIN PROFILE & REQUESTS TAB -->
            <div id="profileView" class="tab-section">
                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'অ্যাডমিন প্রোফাইল তথ্য' : 'Admin Profile Information'; ?></span>
                        <a href="viewProfile.php?type=admin&id=<?php echo (int)$admin_id; ?>" class="btn btn-blue btn-sm"><?php echo $is_bn ? 'পাবলিক প্রিভিউ' : 'Public / Role Preview'; ?></a>
                    </h2>

                    <?php if ($user): ?>
                        <div style="display:flex; align-items:center; gap:18px; padding-bottom:16px; margin-bottom:16px; border-bottom:1px solid #e2e8f0;">
                            <?php if ($admin_photo !== '' && file_exists($admin_photo)): ?>
                                <img src="<?php echo htmlspecialchars($admin_photo); ?>" alt="Admin Profile Picture" style="width:86px; height:86px; border-radius:50%; object-fit:cover; border:2px solid #059669;">
                            <?php else: ?>
                                <div style="width:86px; height:86px; border-radius:50%; background:#d1fae5; color:#065f46; display:flex; align-items:center; justify-content:center; font-size:30px; font-weight:700; border:2px solid #059669;">
                                    <?php echo htmlspecialchars($admin_initial); ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h3 style="margin:0 0 4px 0; font-size:18px; color:#0f172a;"><?php echo htmlspecialchars($user['name'] ?? ''); ?></h3>
                                <div style="font-size:13px; color:#059669; font-weight:600;"><?php echo htmlspecialchars($user['designation'] ?? ''); ?></div>
                                <div style="font-size:12px; color:#64748b; margin-top:2px;">
                                    <?php echo htmlspecialchars(trim(($user['upazila'] ?? '') . ', ' . ($user['district'] ?? ''), ', ')); ?>
                                </div>
                            </div>
                        </div>

                        <div class="profile-row">
                            <span class="profile-label"><?php echo $is_bn ? 'কর্মচারী আইডি:' : 'Employee ID:'; ?></span>
                            <span><?php echo htmlspecialchars($user['employee_id'] ?? ''); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label"><?php echo $is_bn ? 'পূর্ণ নাম:' : 'Full Name:'; ?></span>
                            <span><?php echo htmlspecialchars($user['name'] ?? ''); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label"><?php echo $is_bn ? 'পদবি:' : 'Official Designation:'; ?></span>
                            <span><?php echo htmlspecialchars($user['designation'] ?? ''); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label"><?php echo $is_bn ? 'অ্যাকাউন্টের অবস্থা:' : 'Account Status:'; ?></span>
                            <span><?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', $current_acc_status))); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label"><?php echo $is_bn ? 'উপজেলা:' : 'Upazila:'; ?></span>
                            <span><?php echo htmlspecialchars($user['upazila'] ?? ''); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label"><?php echo $is_bn ? 'জেলা:' : 'District:'; ?></span>
                            <span><?php echo htmlspecialchars($user['district'] ?? ''); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label"><?php echo $is_bn ? 'মোবাইল নম্বর:' : 'Phone Number:'; ?></span>
                            <span><?php echo htmlspecialchars($user['phone'] ?? ''); ?></span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label"><?php echo $is_bn ? 'ইমেইল ঠিকানা:' : 'Email Address:'; ?></span>
                            <span><?php echo htmlspecialchars($user['email'] ?? ''); ?></span>
                        </div>
                    <?php endif; ?>

                    <button type="button" id="openUpdateModalBtn" class="btn btn-emerald" style="margin-top: 18px;"><?php echo $is_bn ? 'প্রোফাইল ও ছবি আপডেট করুন' : 'Update Profile & Photo'; ?></button>
                </div>

                <?php if (!$is_uno && !$is_super_admin): ?>
                    <div class="card-box" style="margin-bottom: 24px;">
                        <h2 class="section-title">
                            <span><?php echo $is_bn ? 'নতুন উপজেলার ইউএনও (UNO)-এর কাছে সক্রিয়করণের আবেদন' : 'Request Activation from New Upazila UNO (Transfer)'; ?></span>
                        </h2>
                        <form action="adminPage.php?section=profileView" method="POST">
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'নতুন উপজেলা' : 'New Upazila'; ?></label>
                                    <input type="text" name="req_upazila" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'নতুন জেলা' : 'New District'; ?></label>
                                    <input type="text" name="req_district" class="form-control" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><?php echo $is_bn ? 'বদলি আদেশ / মন্তব্য' : 'Transfer Order / Note'; ?></label>
                                <input type="text" name="req_reason" class="form-control" required>
                            </div>
                            <button type="submit" name="request_uno_activation" class="btn btn-blue"><?php echo $is_bn ? 'ইউএনও-এর কাছে আবেদন পাঠান' : 'Send Request to UNO'; ?></button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (!$is_super_admin): ?>
                    <div class="card-box" style="margin-bottom: 24px;">
                        <h2 class="section-title">
                            <span><?php echo $is_bn ? 'সুপার অ্যাডমিনের কাছে আবেদন পাঠান' : 'Submit Request to Super Admin'; ?></span>
                        </h2>
                        <form action="adminPage.php?section=profileView" method="POST">
                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'আবেদনের ধরন' : 'Request Type'; ?></label>
                                    <select name="req_type" class="form-control">
                                        <option value="transfer_request"><?php echo $is_bn ? 'বদলির আবেদন' : 'Transfer Request'; ?></option>
                                        <option value="leave_request"><?php echo $is_bn ? 'ছুটি / অবস্থার আবেদন' : 'Leave / Status Request'; ?></option>
                                        <option value="other_action"><?php echo $is_bn ? 'প্রশাসনিক ব্যবস্থা' : 'Administrative Action Request'; ?></option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'লক্ষ্য উপজেলা' : 'Target Upazila'; ?></label>
                                    <input type="text" name="sa_upazila" value="<?php echo htmlspecialchars($admin_upazila); ?>" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'লক্ষ্য জেলা' : 'Target District'; ?></label>
                                    <input type="text" name="sa_district" value="<?php echo htmlspecialchars($admin_district); ?>" class="form-control">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><?php echo $is_bn ? 'বিস্তারিত / কারণ' : 'Details / Reason'; ?></label>
                                <textarea name="sa_reason" rows="2" class="form-control" required></textarea>
                            </div>
                            <button type="submit" name="request_super_admin" class="btn btn-emerald"><?php echo $is_bn ? 'সুপার অ্যাডমিনের কাছে পাঠান' : 'Send Request to Super Admin'; ?></button>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="card-box">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'আমার প্রেরিত আবেদনসমূহ' : 'My Submitted Requests'; ?></span>
                    </h2>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th><?php echo $is_bn ? 'তারিখ' : 'Date'; ?></th>
                                    <th><?php echo $is_bn ? 'প্রাপক' : 'Sent To'; ?></th>
                                    <th><?php echo $is_bn ? 'লক্ষ্য এলাকা' : 'Target Location'; ?></th>
                                    <th><?php echo $is_bn ? 'বিস্তারিত' : 'Details'; ?></th>
                                    <th><?php echo $is_bn ? 'অবস্থা' : 'Status'; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($my_requests_result && $my_requests_result->num_rows > 0): ?>
                                    <?php while ($mr = $my_requests_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo !empty($mr['created_at']) ? date('d M Y', strtotime($mr['created_at'])) : ''; ?></td>
                                            <td><span class="status-badge role-tag"><?php echo strtoupper(htmlspecialchars($mr['request_target'] ?? '')); ?></span></td>
                                            <td><?php echo htmlspecialchars(trim(($mr['target_upazila'] ?? '') . ', ' . ($mr['target_district'] ?? ''), ', ')); ?></td>
                                            <td><?php echo htmlspecialchars($mr['reason'] ?? ''); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo (($mr['status'] ?? 'pending') === 'approved') ? 'status-resolved' : 'status-pending'; ?>">
                                                    <?php echo strtoupper(htmlspecialchars($mr['status'] ?? 'pending')); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="text-align:center;"><?php echo $is_bn ? 'এখনও কোনো আবেদন জমা দেওয়া হয়নি।' : 'No requests submitted yet.'; ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- Popup Modals -->
    <div id="postDetailsModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="postModalTitle">
        <div class="modal-box post-popup-box">
            <div class="modal-header">
                <h3 id="postModalTitle"><?php echo $is_bn ? 'ফসল ক্রয় পোস্টের বিস্তারিত' : 'Crop Purchase Post Details'; ?></h3>
                <span id="closePostModalBtn" class="close-modal" title="Close">&times;</span>
            </div>
            <div id="postModalBody" class="post-popup-body"></div>
            <div id="postModalFooter" class="post-popup-footer">
                <a id="postModalMessageBtn" href="#" class="btn btn-blue btn-sm" style="width:100%; text-align:center;"><?php echo $is_bn ? 'বার্তা পাঠান' : 'Message'; ?></a>
            </div>
        </div>
    </div>

    <div id="noticeDetailsModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="noticeModalTitle">
        <div class="modal-box notice-popup-box">
            <div class="modal-header">
                <h3 id="noticeModalTitle"><?php echo $is_bn ? 'সরকারি নোটিশ' : 'Official Notice'; ?></h3>
                <span id="closeNoticeModalBtn" class="close-modal" title="Close">&times;</span>
            </div>
            <div id="noticeModalMeta" class="notice-popup-meta"></div>
            <div id="noticeModalBody" class="notice-popup-body"></div>
        </div>
    </div>

    <div id="directiveFileModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="directiveFileModalTitle">
        <div class="modal-box notice-popup-box">
            <div class="modal-header">
                <h3 id="directiveFileModalTitle"><?php echo $is_bn ? 'নির্দেশনার ফাইল' : 'Directive File'; ?></h3>
                <span id="closeDirectiveFileModalBtn" class="close-modal" title="Close">&times;</span>
            </div>
            <div id="directiveFileModalMeta" class="notice-popup-meta"></div>
            <div id="directiveFileModalBody" class="notice-popup-body"></div>
        </div>
    </div>

    <div id="updateModal" class="modal">
        <div class="modal-box">
            <div class="modal-header">
                <h3><?php echo $is_bn ? 'অ্যাডমিন প্রোফাইল আপডেট' : 'Update Admin Profile'; ?></h3>
                <span id="closeUpdateModalBtn" class="close-modal">&times;</span>
            </div>
            <form action="adminPage.php?section=profileView" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'প্রোফাইল ছবি (JPG, PNG, WEBP)' : 'Profile Picture (JPG, PNG, WEBP)'; ?></label>
                    <input type="file" name="profile_photo" class="form-control" accept=".jpg, .jpeg, .png, .webp">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'কর্মচারী আইডি' : 'Employee ID'; ?></label>
                    <input type="text" name="employee_id" value="<?php echo htmlspecialchars($user['employee_id'] ?? ''); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'পূর্ণ নাম' : 'Full Name'; ?></label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'পদবি' : 'Official Designation'; ?></label>
                    <input type="text" name="designation" value="<?php echo htmlspecialchars($user['designation'] ?? ''); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'উপজেলা' : 'Upazila'; ?></label>
                    <input type="text" value="<?php echo htmlspecialchars($user['upazila'] ?? ''); ?>" class="form-control" readonly style="background:#f1f5f9;">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'জেলা' : 'District'; ?></label>
                    <input type="text" value="<?php echo htmlspecialchars($user['district'] ?? ''); ?>" class="form-control" readonly style="background:#f1f5f9;">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'মোবাইল নম্বর' : 'Phone Number'; ?></label>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'ইমেইল ঠিকানা' : 'Email Address'; ?></label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" class="form-control" required>
                </div>
                <button type="submit" name="update_profile" class="btn btn-emerald" style="width: 100%;"><?php echo $is_bn ? 'পরিবর্তন সংরক্ষণ করুন' : 'Save Changes'; ?></button>
            </form>
        </div>
    </div>

    <script>
        const rajshahiUpazilas = {
            "Bogura": ["Adamdighi", "Bogura Sadar", "Dhunat", "Dhupchanchia", "Gabtali", "Kahaloo", "Nandigram", "Sariakandi", "Shajahanpur", "Sherpur", "Shibganj", "Sonatala"],
            "Chapainawabganj": ["Bholahat", "Chapainawabganj Sadar", "Gomastapur", "Nachole", "Shibganj"],
            "Joypurhat": ["Akkelpur", "Joypurhat Sadar", "Kalai", "Khetlal", "Panchbibi"],
            "Naogaon": ["Atrai", "Badalgachhi", "Dhamoirhat", "Manda", "Mahadebpur", "Naogaon Sadar", "Niamatpur", "Patnitala", "Porsha", "Raninagar", "Sapahar"],
            "Natore": ["Bagatipara", "Baraigram", "Gurudaspur", "Lalpur", "Naldanga", "Natore Sadar", "Singra"],
            "Pabna": ["Atgharia", "Bera", "Bhangura", "Chatmohar", "Faridpur", "Ishwardi", "Pabna Sadar", "Santhia", "Sujanagar"],
            "Rajshahi": ["Bagha", "Bagmara", "Charghat", "Durgapur", "Godagari", "Mohanpur", "Paba", "Puthia", "Tanore"],
            "Sirajganj": ["Belkuchi", "Chauhali", "Kamarkhanda", "Kazipur", "Raiganj", "Shahjadpur", "Sirajganj Sadar", "Tarash", "Ullahpara"]
        };

        const preselectedNoticeUpazila = <?php echo json_encode($notice_upazila); ?>;

        function populateNoticeUpazilas() {
            const districtSelect = document.getElementById("noticeDistrictSelect");
            const upazilaSelect = document.getElementById("noticeUpazilaSelect");
            const selectedDistrict = districtSelect.value;

            upazilaSelect.innerHTML = '<option value="">Select Upazila</option>';

            if (selectedDistrict && rajshahiUpazilas[selectedDistrict]) {
                rajshahiUpazilas[selectedDistrict].forEach(upazila => {
                    const option = document.createElement("option");
                    option.value = upazila;
                    option.text = upazila;
                    if (upazila === preselectedNoticeUpazila) {
                        option.selected = true;
                    }
                    upazilaSelect.appendChild(option);
                });
            }
        }

        function scrollChatToBottom() {
            const chatBox = document.getElementById("chatMessagesBox");
            if (chatBox) {
                chatBox.scrollTop = chatBox.scrollHeight;
            }
        }

        function switchSection(sectionId, linkEl) {
            document.querySelectorAll('.tab-section').forEach(sec => {
                sec.classList.remove('active-section');
            });

            document.querySelectorAll('.sidebar a').forEach(a => {
                a.classList.remove('active-link');
            });

            const targetSection = document.getElementById(sectionId);
            if (targetSection) {
                targetSection.classList.add('active-section');
            }

            if (linkEl) {
                linkEl.classList.add('active-link');
            } else {
                const autoLink = document.getElementById('nav-' + sectionId);
                if (autoLink) autoLink.classList.add('active-link');
            }

            if (sectionId === 'chat-section') {
                scrollChatToBottom();
            }
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str ?? '';
            return div.innerHTML;
        }

        function renderFilePreviewHtml(filePath, fileType) {
            const cleanType = (fileType || '').toLowerCase();
            if (!filePath) {
                return '<p style="color:#64748b;">No file attachment available.</p>';
            }
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(cleanType)) {
                return `<img src="${escapeHtml(filePath)}" alt="Attachment Preview">`;
            }
            if (cleanType === 'pdf') {
                return `<embed src="${escapeHtml(filePath)}" type="application/pdf" width="100%" height="500px" />`;
            }
            return `<p><a href="${escapeHtml(filePath)}" target="_blank" class="btn btn-blue btn-sm">Open Attachment</a></p>`;
        }

        const postDetailsModal = document.getElementById('postDetailsModal');
        const postModalTitle = document.getElementById('postModalTitle');
        const postModalBody = document.getElementById('postModalBody');
        const postModalMessageBtn = document.getElementById('postModalMessageBtn');
        const closePostModalBtn = document.getElementById('closePostModalBtn');

        function openPostPopup(postId, btnEl) {
            const detailsDiv = document.getElementById('details-' + postId);
            const cardEl = detailsDiv ? detailsDiv.closest('.post-card') : null;
            const headingEl = cardEl ? cardEl.querySelector('.post-basic-info h4') : null;
            const millId = btnEl ? (btnEl.getAttribute('data-mill-id') || '') : '';

            if (!detailsDiv || !postDetailsModal || !postModalBody) return;

            if (postModalTitle) {
                postModalTitle.textContent = headingEl ? headingEl.textContent : 'Crop Purchase Post Details';
            }
            postModalBody.innerHTML = detailsDiv.innerHTML;

            if (postModalMessageBtn) {
                if (millId) {
                    postModalMessageBtn.href = 'adminPage.php?section=chat-section&chat_with=' + encodeURIComponent(millId);
                    postModalMessageBtn.style.display = 'inline-flex';
                } else {
                    postModalMessageBtn.style.display = 'none';
                }
            }

            postDetailsModal.style.display = 'block';
        }

        function closePostPopup() {
            if (postDetailsModal) postDetailsModal.style.display = 'none';
        }

        if (closePostModalBtn) closePostModalBtn.onclick = closePostPopup;

        const noticeDetailsModal = document.getElementById('noticeDetailsModal');
        const noticeModalTitle = document.getElementById('noticeModalTitle');
        const noticeModalMeta = document.getElementById('noticeModalMeta');
        const noticeModalBody = document.getElementById('noticeModalBody');
        const closeNoticeModalBtn = document.getElementById('closeNoticeModalBtn');

        function openNoticePopupFromBtn(btnEl) {
            if (!btnEl || !noticeDetailsModal || !noticeModalBody) return;

            const title = btnEl.getAttribute('data-title') || 'Official Notice';
            const uploader = btnEl.getAttribute('data-uploader') || '';
            const designation = btnEl.getAttribute('data-designation') || '';
            const email = btnEl.getAttribute('data-email') || '';
            const location = btnEl.getAttribute('data-location') || '';
            const date = btnEl.getAttribute('data-date') || '';
            const filePath = btnEl.getAttribute('data-file-path') || '';
            const fileType = (btnEl.getAttribute('data-file-type') || '').toLowerCase();

            if (noticeModalTitle) noticeModalTitle.textContent = title;
            if (noticeModalMeta) {
                const uploaderText = designation ? `${uploader} (${designation})` : uploader;
                noticeModalMeta.innerHTML =
                    `<strong>Uploader:</strong> ${escapeHtml(uploaderText)}` +
                    (email ? ` | <strong>Email:</strong> ${escapeHtml(email)}` : '') +
                    (location ? ` | <strong>Location:</strong> ${escapeHtml(location)}` : '') +
                    (date ? ` | <strong>Date:</strong> ${escapeHtml(date)}` : '');
            }
            noticeModalBody.innerHTML = renderFilePreviewHtml(filePath, fileType);
            noticeDetailsModal.style.display = 'block';
        }

        function closeNoticePopup() {
            if (noticeDetailsModal) noticeDetailsModal.style.display = 'none';
            if (noticeModalBody) noticeModalBody.innerHTML = '';
        }

        if (closeNoticeModalBtn) closeNoticeModalBtn.onclick = closeNoticePopup;

        const directiveFileModal = document.getElementById('directiveFileModal');
        const directiveFileModalTitle = document.getElementById('directiveFileModalTitle');
        const directiveFileModalMeta = document.getElementById('directiveFileModalMeta');
        const directiveFileModalBody = document.getElementById('directiveFileModalBody');
        const closeDirectiveFileModalBtn = document.getElementById('closeDirectiveFileModalBtn');

        function openDirectiveFilePopup(btnEl) {
            if (!btnEl || !directiveFileModal || !directiveFileModalBody) return;

            const title = btnEl.getAttribute('data-title') || 'Directive File';
            const meta = btnEl.getAttribute('data-meta') || '';
            const filePath = btnEl.getAttribute('data-file-path') || '';
            const fileType = (btnEl.getAttribute('data-file-type') || '').toLowerCase();

            if (directiveFileModalTitle) directiveFileModalTitle.textContent = title;
            if (directiveFileModalMeta) directiveFileModalMeta.innerHTML = escapeHtml(meta);
            directiveFileModalBody.innerHTML = renderFilePreviewHtml(filePath, fileType);
            directiveFileModal.style.display = 'block';
        }

        function closeDirectiveFilePopup() {
            if (directiveFileModal) directiveFileModal.style.display = 'none';
            if (directiveFileModalBody) directiveFileModalBody.innerHTML = '';
        }

        if (closeDirectiveFileModalBtn) closeDirectiveFileModalBtn.onclick = closeDirectiveFilePopup;

        function toggleData(rowClass, btnId) {
            const rows = document.querySelectorAll('.' + rowClass);
            const btn = document.getElementById(btnId);

            rows.forEach((row, index) => {
                if (index >= 5) {
                    row.classList.toggle('hidden-row');
                }
            });

            if (btn) {
                btn.innerText = (btn.innerText === "View More" || btn.innerText === "আরও দেখুন") ? "View Less" : "View More";
            }
        }

        const updateModal = document.getElementById('updateModal');
        const openBtn = document.getElementById('openUpdateModalBtn');
        const closeBtn = document.getElementById('closeUpdateModalBtn');

        if (openBtn && updateModal) openBtn.onclick = () => updateModal.style.display = 'block';
        if (closeBtn && updateModal) closeBtn.onclick = () => updateModal.style.display = 'none';

        window.onclick = (e) => {
            if (updateModal && e.target === updateModal) updateModal.style.display = 'none';
            if (postDetailsModal && e.target === postDetailsModal) closePostPopup();
            if (noticeDetailsModal && e.target === noticeDetailsModal) closeNoticePopup();
            if (directiveFileModal && e.target === directiveFileModal) closeDirectiveFilePopup();
        };

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closePostPopup();
                closeNoticePopup();
                closeDirectiveFilePopup();
                if (updateModal) updateModal.style.display = 'none';
            }
        });

        window.addEventListener('DOMContentLoaded', () => {
            populateNoticeUpazilas();
            switchSection(<?php echo json_encode($default_section); ?>, null);
            scrollChatToBottom();
        });
    </script>

</body>
</html>