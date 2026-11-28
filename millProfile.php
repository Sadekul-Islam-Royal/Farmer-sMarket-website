<?php
/**
 * Repository: farmers-market-portal
 * File: MillProfile.php
 * Module: Mill Owner Dashboard & Marketplace Management
 * Description: Handles mill owner profile updates (including profile photo) using
 *              village, upazila, and district columns, enforces temporary account
 *              suspension restrictions, displays suspension/reactivation notifications,
 *              role-based profile links, and manages crop purchase posts, search &
 *              filtering, popups, messaging, and complaints.
 */
session_start();
require_once 'lang.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'mill_owner') {
    header("Location: homePage.php?login=true");
    exit();
}

function parseAddressParts(string $rawAddress): array
{
    $parts = array_map('trim', explode(',', $rawAddress));
    $parts = array_values(array_filter($parts, static function ($val) {
        return $val !== '';
    }));

    $village  = '';
    $upazila  = '';
    $district = '';

    if (count($parts) >= 3) {
        $village  = $parts[0];
        $upazila  = $parts[1];
        $district = $parts[2];
    } elseif (count($parts) === 2) {
        $upazila  = $parts[0];
        $district = $parts[1];
    } elseif (count($parts) === 1) {
        $district = $parts[0];
    }

    return [
        'village'  => $village,
        'upazila'  => $upazila,
        'district' => $district,
    ];
}

function formatLocationColumns(array $row): string
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

function ensureMillOwnerSchema(mysqli $conn): void
{
    $col_chk = $conn->query("SHOW COLUMNS FROM mill_owners LIKE 'profile_photo'");
    if ($col_chk && $col_chk->num_rows === 0) {
        $conn->query("ALTER TABLE mill_owners ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL");
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

ensureMillOwnerSchema($conn);

$mill_db_id = intval($_SESSION['user_id']);
$flash_msg  = "";
$flash_type = "alert-success";

// 1. Fetch Mill Owner Data
$stmt = $conn->prepare("SELECT * FROM mill_owners WHERE id = ?");
$stmt->bind_param("i", $mill_db_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: homePage.php?login=true");
    exit();
}

$mill_village  = trim($user['village'] ?? '');
$mill_upazila  = trim($user['upazila'] ?? '');
$mill_district = trim($user['district'] ?? '');
$mill_display_address = formatLocationColumns($user);
$mill_code = trim($user['mill_id'] ?? $user['mill_ID'] ?? '');

// Check temporary suspension status and auto-expire if end date has passed
$today_date = date('Y-m-d');
$account_status = trim($user['account_status'] ?? 'active');
$suspended_until = trim($user['suspended_until'] ?? '');
$active_complaint_no = trim($user['active_complaint_no'] ?? '');
$suspension_reason = trim($user['suspension_reason'] ?? '');

if ($account_status === 'suspended' && $suspended_until !== '' && $suspended_until < $today_date) {
    $stmt_exp = $conn->prepare("UPDATE mill_owners SET account_status = 'active', suspended_until = NULL, active_complaint_no = NULL, suspension_reason = NULL WHERE id = ?");
    $stmt_exp->bind_param("i", $mill_db_id);
    $stmt_exp->execute();
    $stmt_exp->close();

    if ($mill_code !== '') {
        $stmt_s_exp = $conn->prepare("UPDATE mill_suspensions SET status = 'expired' WHERE mill_ID = ? AND status = 'active' AND suspended_until < ?");
        $stmt_s_exp->bind_param("ss", $mill_code, $today_date);
        $stmt_s_exp->execute();
        $stmt_s_exp->close();
    }

    $account_status = 'active';
    $suspended_until = '';
    $active_complaint_no = '';
    $suspension_reason = '';
}

$is_suspended = ($account_status === 'suspended' && ($suspended_until === '' || $suspended_until >= $today_date));

// 2. Handle Mill Owner Profile Update (Including Profile Photo Upload)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $name       = trim($_POST['name'] ?? '');
    $mill_name  = trim($_POST['mill_name'] ?? '');
    $mill_id    = trim($_POST['mill_id'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $crop_types = trim($_POST['crop_types'] ?? '');
    $rawAddr    = trim($_POST['address'] ?? '');
    $addrParts  = parseAddressParts($rawAddr);
    $village    = $addrParts['village'];
    $upazila    = $addrParts['upazila'];
    $district   = $addrParts['district'];
    $photo_path = trim($user['profile_photo'] ?? '');

    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $img_tmp      = $_FILES['profile_photo']['tmp_name'];
        $img_name     = basename($_FILES['profile_photo']['name']);
        $img_ext      = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));
        $allowed_imgs = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($img_ext, $allowed_imgs, true)) {
            $photo_dir = "uploads/mill_photos/";
            if (!is_dir($photo_dir)) {
                mkdir($photo_dir, 0777, true);
            }
            $new_photo_file = "mill_" . $mill_db_id . "_" . time() . "." . $img_ext;
            $photo_dest     = $photo_dir . $new_photo_file;

            if (move_uploaded_file($img_tmp, $photo_dest)) {
                $photo_path = $photo_dest;
            }
        } else {
            $flash_msg  = "Invalid image format. Only JPG, JPEG, PNG, or WEBP allowed.";
            $flash_type = "alert-error";
        }
    }

    if ($flash_type !== "alert-error") {
        $stmt = $conn->prepare("UPDATE mill_owners SET name=?, mill_name=?, mill_id=?, phone=?, email=?, crop_types=?, village=?, upazila=?, district=?, profile_photo=? WHERE id=?");
        $stmt->bind_param("ssssssssssi", $name, $mill_name, $mill_id, $phone, $email, $crop_types, $village, $upazila, $district, $photo_path, $mill_db_id);

        if ($stmt->execute()) {
            $flash_msg = "Mill profile updated successfully!";
            $user['name']          = $name;
            $user['mill_name']     = $mill_name;
            $user['mill_id']       = $mill_id;
            $user['phone']         = $phone;
            $user['email']         = $email;
            $user['crop_types']    = $crop_types;
            $user['village']       = $village;
            $user['upazila']       = $upazila;
            $user['district']      = $district;
            $user['profile_photo'] = $photo_path;

            $mill_village  = $village;
            $mill_upazila  = $upazila;
            $mill_district = $district;
            $mill_display_address = formatLocationColumns($user);
            $mill_code     = $mill_id;
        } else {
            $flash_msg  = "Error updating profile: " . $stmt->error;
            $flash_type = "alert-error";
        }
        $stmt->close();
    }
}

// 3. Handle Create New Crop Purchase Post (Blocked while suspended)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_post'])) {
    if ($is_suspended) {
        $flash_msg  = "Action blocked: Your mill account is temporarily suspended" . ($suspended_until !== '' ? " until {$suspended_until}" : "") . ". You cannot create new crop purchase posts during the suspension period.";
        $flash_type = "alert-error";
    } else {
        $crop_name     = trim($_POST['crop_name'] ?? '');
        $crop_type     = trim($_POST['crop_type'] ?? '');
        $price         = trim($_POST['price'] ?? '');
        $unit          = trim($_POST['unit'] ?? 'Kg');
        $crop_moisture = trim($_POST['crop_moisture'] ?? '');
        $description   = trim($_POST['description'] ?? '');

        $post_mill_ref = $mill_code !== '' ? $mill_code : (string)$mill_db_id;
        $owner_name    = $user['name'] ?? '';
        $m_name        = $user['mill_name'] ?? '';
        $m_phone       = $user['phone'] ?? '';
        $m_address     = $mill_display_address;

        $stmt = $conn->prepare("INSERT INTO mill_post (mill_id, mill_name, owner_name, phone, address, crop_name, crop_type, price, unit, crop_moisture, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssssss", $post_mill_ref, $m_name, $owner_name, $m_phone, $m_address, $crop_name, $crop_type, $price, $unit, $crop_moisture, $description);

        if ($stmt->execute()) {
            $flash_msg = "Crop purchase post published successfully!";
        } else {
            $flash_msg  = "Failed to publish post: " . $stmt->error;
            $flash_type = "alert-error";
        }
        $stmt->close();
    }
}

// 4. Handle Delete Own Crop Purchase Post
if (isset($_GET['delete_post_id'])) {
    $del_id = intval($_GET['delete_post_id']);
    if ($del_id > 0) {
        $db_id_str = (string)$mill_db_id;
        $stmt = $conn->prepare("DELETE FROM mill_post WHERE id = ? AND (mill_id = ? OR mill_id = ?)");
        $stmt->bind_param("iss", $del_id, $mill_code, $db_id_str);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $flash_msg = "Purchase post deleted successfully.";
        }
        $stmt->close();
    }
    header("Location: MillProfile.php?section=home-sec");
    exit();
}

// 5. Handle Send Direct Message (Farmer messaging blocked while suspended; Admin messaging allowed)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_message'])) {
    $receiver_id   = intval($_POST['receiver_id'] ?? 0);
    $receiver_type = trim($_POST['receiver_type'] ?? 'farmer');
    $message       = trim($_POST['message'] ?? '');

    if (!in_array($receiver_type, ['farmer', 'admin'], true)) {
        $receiver_type = 'farmer';
    }

    if ($is_suspended && $receiver_type === 'farmer') {
        $flash_msg  = "Action blocked: Your mill account is temporarily suspended" . ($suspended_until !== '' ? " until {$suspended_until}" : "") . ". Messaging with farmers is disabled, though you can still contact admins.";
        $flash_type = "alert-error";
    } elseif ($receiver_id > 0 && $message !== '') {
        $stmt = $conn->prepare("INSERT INTO direct_messages (sender_type, sender_id, receiver_type, receiver_id, message) VALUES ('mill_owner', ?, ?, ?, ?)");
        $stmt->bind_param("isis", $mill_db_id, $receiver_type, $receiver_id, $message);
        $stmt->execute();
        $stmt->close();

        header("Location: MillProfile.php?section=messages-sec&chat_type=" . urlencode($receiver_type) . "&chat_with=" . $receiver_id);
        exit();
    }
}

// 6. Handle Submit Complaint to Admin
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_complaint'])) {
    $subject        = trim($_POST['subject'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $comp_district  = trim($_POST['comp_district'] ?? '');
    $comp_upazila   = trim($_POST['comp_upazila'] ?? '');
    $target_officer = trim($_POST['target_officer'] ?? '');

    $full_subject = $subject . " [To: " . $target_officer . "]";
    $sender_name  = ($user['mill_name'] ?? '') . " (" . ($user['name'] ?? '') . ")";
    $sender_phone = $user['phone'] ?? '';

    $stmt = $conn->prepare("INSERT INTO complaints (sender_id, sender_name, sender_role, sender_phone, district, upazila, subject, description, status) VALUES (?, ?, 'mill_owner', ?, ?, ?, ?, ?, 'Pending')");
    $stmt->bind_param("issssss", $mill_db_id, $sender_name, $sender_phone, $comp_district, $comp_upazila, $full_subject, $description);

    if ($stmt->execute()) {
        $flash_msg = "Complaint submitted successfully to " . $target_officer . "!";
    } else {
        $flash_msg  = "Failed to submit complaint: " . $stmt->error;
        $flash_type = "alert-error";
    }
    $stmt->close();
}

// 7. Fetch Mill's Own Crop Purchase Posts
$post_search = trim($_GET['post_search'] ?? '');
$db_id_str = (string)$mill_db_id;
if ($post_search !== '') {
    $ps_like = "%" . $post_search . "%";
    $stmt_posts = $conn->prepare("SELECT * FROM mill_post WHERE (mill_id = ? OR mill_id = ?) AND (crop_name LIKE ? OR crop_type LIKE ? OR address LIKE ?) ORDER BY created_at DESC");
    $stmt_posts->bind_param("sssss", $mill_code, $db_id_str, $ps_like, $ps_like, $ps_like);
} else {
    $stmt_posts = $conn->prepare("SELECT * FROM mill_post WHERE mill_id = ? OR mill_id = ? ORDER BY created_at DESC");
    $stmt_posts->bind_param("ss", $mill_code, $db_id_str);
}
$stmt_posts->execute();
$my_posts_result = $stmt_posts->get_result();
$stmt_posts->close();
$total_my_posts = $my_posts_result ? $my_posts_result->num_rows : 0;

// 8. Fetch Official Notices with District, Upazila & Search Filter
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

// 9. Fetch only Farmers & Admins with active conversations with this Mill Owner, ordered by most recent message
$recent_conversations = [];

$stmt_conv_f = $conn->prepare("
    SELECT f.id, f.name, f.phone, f.village, f.upazila, f.district, MAX(dm.created_at) AS last_msg_time
    FROM farmers f
    INNER JOIN direct_messages dm
        ON (
            (dm.sender_type = 'mill_owner' AND dm.sender_id = ? AND dm.receiver_type = 'farmer' AND dm.receiver_id = f.id)
            OR
            (dm.sender_type = 'farmer' AND dm.sender_id = f.id AND dm.receiver_type = 'mill_owner' AND dm.receiver_id = ?)
        )
    GROUP BY f.id, f.name, f.phone, f.village, f.upazila, f.district
");
$stmt_conv_f->bind_param("ii", $mill_db_id, $mill_db_id);
$stmt_conv_f->execute();
$conv_farmers_res = $stmt_conv_f->get_result();
while ($cf = $conv_farmers_res->fetch_assoc()) {
    $f_addr = formatLocationColumns($cf);
    $recent_conversations[] = [
        'role'          => 'farmer',
        'id'            => intval($cf['id']),
        'name'          => $cf['name'] ?? 'Farmer',
        'sub'           => trim(($cf['phone'] ?? '') . ($f_addr !== '' ? ' | ' . $f_addr : ''), ' |'),
        'last_msg_time' => $cf['last_msg_time'] ?? '',
    ];
}
$stmt_conv_f->close();

$stmt_conv_a = $conn->prepare("
    SELECT a.id, a.name, a.designation, a.upazila, a.district, MAX(dm.created_at) AS last_msg_time
    FROM admins a
    INNER JOIN direct_messages dm
        ON (
            (dm.sender_type = 'mill_owner' AND dm.sender_id = ? AND dm.receiver_type = 'admin' AND dm.receiver_id = a.id)
            OR
            (dm.sender_type = 'admin' AND dm.sender_id = a.id AND dm.receiver_type = 'mill_owner' AND dm.receiver_id = ?)
        )
    GROUP BY a.id, a.name, a.designation, a.upazila, a.district
");
$stmt_conv_a->bind_param("ii", $mill_db_id, $mill_db_id);
$stmt_conv_a->execute();
$conv_admins_res = $stmt_conv_a->get_result();
while ($ca = $conv_admins_res->fetch_assoc()) {
    $a_loc = trim(($ca['upazila'] ?? '') . ', ' . ($ca['district'] ?? ''), ', ');
    $a_sub = trim(($ca['designation'] ?? '') . ($a_loc !== '' ? ' - ' . $a_loc : ''), ' -');
    $recent_conversations[] = [
        'role'          => 'admin',
        'id'            => intval($ca['id']),
        'name'          => $ca['name'] ?? 'Admin',
        'sub'           => $a_sub,
        'last_msg_time' => $ca['last_msg_time'] ?? '',
    ];
}
$stmt_conv_a->close();

usort($recent_conversations, static function (array $a, array $b): int {
    $tA = strtotime($a['last_msg_time'] ?: '1970-01-01 00:00:00');
    $tB = strtotime($b['last_msg_time'] ?: '1970-01-01 00:00:00');
    if ($tA === $tB) {
        return $b['id'] <=> $a['id'];
    }
    return $tB <=> $tA;
});

$raw_chat_with = trim($_GET['chat_with'] ?? '');
$raw_chat_type = trim($_GET['chat_type'] ?? '');
$active_chat_id = 0;
$active_chat_type = '';
$active_partner_info = null;
$active_chat_messages = null;

if ($raw_chat_with !== '' && ctype_digit($raw_chat_with)) {
    $candidate_id = intval($raw_chat_with);

    if ($raw_chat_type === 'admin') {
        $stmt_a = $conn->prepare("SELECT id, name, designation, upazila, district FROM admins WHERE id = ? LIMIT 1");
        $stmt_a->bind_param("i", $candidate_id);
        $stmt_a->execute();
        $adm = $stmt_a->get_result()->fetch_assoc();
        $stmt_a->close();
        if ($adm) {
            $active_chat_id = intval($adm['id']);
            $active_chat_type = 'admin';
            $active_partner_info = [
                'name' => $adm['name'] ?? 'Admin',
                'sub'  => trim(($adm['designation'] ?? '') . ' | ' . ($adm['upazila'] ?? '') . ', ' . ($adm['district'] ?? ''), ' |, ')
            ];
        }
    } elseif ($raw_chat_type === 'farmer') {
        $stmt_f = $conn->prepare("SELECT id, name, phone, village, upazila, district FROM farmers WHERE id = ? LIMIT 1");
        $stmt_f->bind_param("i", $candidate_id);
        $stmt_f->execute();
        $frm = $stmt_f->get_result()->fetch_assoc();
        $stmt_f->close();
        if ($frm) {
            $active_chat_id = intval($frm['id']);
            $active_chat_type = 'farmer';
            $active_partner_info = [
                'name' => $frm['name'] ?? 'Farmer',
                'sub'  => trim(($frm['phone'] ?? '') . ' | ' . formatLocationColumns($frm), ' |')
            ];
        }
    } else {
        $stmt_f = $conn->prepare("SELECT id, name, phone, village, upazila, district FROM farmers WHERE id = ? LIMIT 1");
        $stmt_f->bind_param("i", $candidate_id);
        $stmt_f->execute();
        $frm = $stmt_f->get_result()->fetch_assoc();
        $stmt_f->close();

        if ($frm) {
            $active_chat_id = intval($frm['id']);
            $active_chat_type = 'farmer';
            $active_partner_info = [
                'name' => $frm['name'] ?? 'Farmer',
                'sub'  => trim(($frm['phone'] ?? '') . ' | ' . formatLocationColumns($frm), ' |')
            ];
        } else {
            $stmt_a = $conn->prepare("SELECT id, name, designation, upazila, district FROM admins WHERE id = ? LIMIT 1");
            $stmt_a->bind_param("i", $candidate_id);
            $stmt_a->execute();
            $adm = $stmt_a->get_result()->fetch_assoc();
            $stmt_a->close();
            if ($adm) {
                $active_chat_id = intval($adm['id']);
                $active_chat_type = 'admin';
                $active_partner_info = [
                    'name' => $adm['name'] ?? 'Admin',
                    'sub'  => trim(($adm['designation'] ?? '') . ' | ' . ($adm['upazila'] ?? '') . ', ' . ($adm['district'] ?? ''), ' |, ')
                ];
            }
        }
    }

    if ($active_chat_id > 0 && $active_chat_type !== '') {
        $stmt_msg = $conn->prepare("
            SELECT * FROM direct_messages
            WHERE (sender_type = 'mill_owner' AND sender_id = ? AND receiver_type = ? AND receiver_id = ?)
               OR (sender_type = ? AND sender_id = ? AND receiver_type = 'mill_owner' AND receiver_id = ?)
            ORDER BY created_at ASC, id ASC
        ");
        $stmt_msg->bind_param("isisii", $mill_db_id, $active_chat_type, $active_chat_id, $active_chat_type, $active_chat_id, $mill_db_id);
        $stmt_msg->execute();
        $active_chat_messages = $stmt_msg->get_result();
        $stmt_msg->close();
    }
}

// 10. Mill Notifications / Directives from Admins + Account Action Notifications
$stmt_notif = $conn->prepare("SELECT * FROM admin_orders WHERE target_audience IN ('mill_owner', 'both') ORDER BY created_at DESC");
$stmt_notif->execute();
$notif_res = $stmt_notif->get_result();
$stmt_notif->close();

$stmt_acc_notif = $conn->prepare("SELECT * FROM mill_notifications WHERE mill_owner_id = ? OR mill_ID = ? ORDER BY created_at DESC");
$stmt_acc_notif->bind_param("is", $mill_db_id, $mill_code);
$stmt_acc_notif->execute();
$account_notif_res = $stmt_acc_notif->get_result();
$stmt_acc_notif->close();

// 11. Mill's Submitted Complaints History
$stmt_mc = $conn->prepare("SELECT * FROM complaints WHERE sender_id = ? AND sender_role = 'mill_owner' ORDER BY created_at DESC");
$stmt_mc->bind_param("i", $mill_db_id);
$stmt_mc->execute();
$my_complaints = $stmt_mc->get_result();
$stmt_mc->close();

$conn->close();

$default_section = 'home-sec';
if (isset($_GET['section'])) {
    $default_section = $_GET['section'];
} elseif ($notice_district !== '' || $notice_upazila !== '' || $notice_search !== '' || $post_search !== '' || isset($_POST['create_post'])) {
    $default_section = 'home-sec';
} elseif ($raw_chat_with !== '' || isset($_POST['send_message'])) {
    $default_section = 'messages-sec';
} elseif (isset($_POST['submit_complaint'])) {
    $default_section = 'complaint-sec';
} elseif (isset($_POST['update_profile'])) {
    $default_section = 'profile-sec';
}

$mill_photo   = trim($user['profile_photo'] ?? '');
$mill_initial = strtoupper(substr(trim($user['mill_name'] ?? ($user['name'] ?? 'M')), 0, 1));
$is_bn = ($current_lang === 'bn');
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_bn ? 'মিল মালিক ড্যাশবোর্ড | কৃষক বাজার' : "Mill Owner Dashboard | Farmer's Market"; ?></title>
    <link rel="stylesheet" href="MillProfileStyle.css">
   <link rel="stylesheet" href="lang-toggle.css">
</head>
<body>

    <header class="top-navbar">
        <div class="nav-container">
            <div class="brand-area">
                <div>
                    <div class="brand-title"><?php echo htmlspecialchars($user['mill_name'] ?? __t('site_name')); ?></div>
                    <div class="brand-subtitle">
                        <?php echo $is_bn ? 'মিল মালিক ড্যাশবোর্ড | স্বাগতম,' : 'Mill Owner Dashboard | Welcome,'; ?>
                        <?php echo htmlspecialchars($user['name'] ?? 'Mill Owner'); ?>
                    </div>
                </div>
            </div>
            <div class="nav-user-area" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <div class="lang-switch-group">
                    <?php render_lang_toggle(); ?>
                </div>
                <?php if ($mill_photo !== '' && file_exists($mill_photo)): ?>
                    <img src="<?php echo htmlspecialchars($mill_photo); ?>" alt="Mill Photo" style="width:34px; height:34px; border-radius:50%; object-fit:cover; border:1px solid #cbd5e1;">
                <?php endif; ?>
                <span><?php echo $is_bn ? 'মিল আইডি:' : 'Mill ID:'; ?> <strong><?php echo htmlspecialchars($mill_code); ?></strong></span>
            </div>
        </div>
    </header>

    <div class="main-wrapper">

        <aside class="sidebar">
            <a onclick="switchSection('home-sec', this)" id="nav-home-sec" class="active-link">
                <span><?php echo $is_bn ? 'হোম ও পোস্ট' : 'Home &amp; Posts'; ?></span>
            </a>
            <a onclick="switchSection('messages-sec', this)" id="nav-messages-sec">
                <span><?php echo $is_bn ? 'বার্তা' : 'Messages'; ?></span>
            </a>
            <a onclick="switchSection('notification-sec', this)" id="nav-notification-sec">
                <span><?php echo $is_bn ? 'নোটিফিকেশন' : 'Notification'; ?></span>
            </a>
            <a onclick="switchSection('complaint-sec', this)" id="nav-complaint-sec">
                <span><?php echo $is_bn ? 'অভিযোগ' : 'Complaint'; ?></span>
            </a>
            <a onclick="switchSection('profile-sec', this)" id="nav-profile-sec">
                <span><?php echo $is_bn ? 'মিল প্রোফাইল' : 'Mill Profile'; ?></span>
            </a>
            <a href="logout.php" class="logout-btn"><?php echo $is_bn ? 'লগআউট' : 'Logout'; ?></a>
        </aside>

        <main class="main-content">

            <?php if ($is_suspended): ?>
                <div class="alert alert-error">
                    <strong><?php echo $is_bn ? 'অ্যাকাউন্ট সাময়িক স্থগিত' : 'Account Temporarily Suspended'; ?><?php echo $suspended_until !== '' ? ($is_bn ? ' (' . htmlspecialchars(date('d M Y', strtotime($suspended_until))) . ' পর্যন্ত)' : ' Until ' . htmlspecialchars(date('d M Y', strtotime($suspended_until)))) : ''; ?>:</strong>
                    <?php if ($active_complaint_no !== ''): ?>
                        <?php echo $is_bn ? 'অভিযোগ #' : 'Complaint #'; ?><?php echo htmlspecialchars($active_complaint_no); ?> —
                    <?php endif; ?>
                    <?php echo htmlspecialchars($suspension_reason !== '' ? $suspension_reason : ($is_bn ? 'নতুন ফসল ক্রয় পোস্ট তৈরি এবং কৃষকদের সাথে মেসেজ পাঠানো সাময়িক বন্ধ রয়েছে। আপনি অ্যাডমিনদের সাথে যোগাযোগ করতে পারবেন।' : 'Creating new crop purchase posts and messaging farmers are temporarily disabled. You can still communicate with admins.')); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($flash_msg)): ?>
                <div class="alert <?php echo htmlspecialchars($flash_type); ?>"><?php echo htmlspecialchars($flash_msg); ?></div>
            <?php endif; ?>

            <div id="home-sec" class="tab-section active-section">

                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'নতুন ফসল ক্রয় পোস্ট তৈরি করুন' : 'Create New Crop Purchase Post'; ?></span>
                    </h2>
                    <?php if ($is_suspended): ?>
                        <p style="font-size: 13px; color: #991b1b;">
                            <?php echo $is_bn
                                ? 'স্থগিতাদেশ চলাকালীন নতুন ফসল ক্রয় পোস্ট তৈরি করা বন্ধ রয়েছে' . ($suspended_until !== '' ? ' (' . htmlspecialchars(date('d M Y', strtotime($suspended_until))) . ' পর্যন্ত)' : '') . '।'
                                : 'Creating new crop purchase posts is disabled until ' . htmlspecialchars($suspended_until !== '' ? date('d M Y', strtotime($suspended_until)) : 'the suspension is lifted') . '.'; ?>
                        </p>
                    <?php else: ?>
                        <form action="MillProfile.php?section=home-sec" method="POST">
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'ফসলের নাম' : 'Crop Name'; ?></label>
                                    <input type="text" name="crop_name" placeholder="<?php echo $is_bn ? 'যেমন: ব্রি ধান ২৮ / গম / ভুট্টা' : 'e.g., BRRI Dhan 28 / Wheat / Maize'; ?>" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'ফসলের ধরন' : 'Crop Type'; ?></label>
                                    <input type="text" name="crop_type" placeholder="<?php echo $is_bn ? 'যেমন: ধান / শস্য' : 'e.g., Paddy / Grain'; ?>" class="form-control" required>
                                </div>
                            </div>
                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'ক্রয় মূল্য (টাকা)' : 'Purchase Price (Taka)'; ?></label>
                                    <input type="text" name="price" placeholder="<?php echo $is_bn ? 'যেমন: ১২৫০' : 'e.g., 1250'; ?>" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'একক' : 'Unit'; ?></label>
                                    <select name="unit" class="form-control">
                                        <option value="Maund (40 Kg)"><?php echo $is_bn ? 'মণ (৪০ কেজি)' : 'Maund (40 Kg)'; ?></option>
                                        <option value="Kg"><?php echo $is_bn ? 'কেজি' : 'Kg'; ?></option>
                                        <option value="Quintal"><?php echo $is_bn ? 'কুইন্টাল' : 'Quintal'; ?></option>
                                        <option value="Ton"><?php echo $is_bn ? 'টন' : 'Ton'; ?></option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo $is_bn ? 'আর্দ্রতার শর্ত' : 'Moisture Requirement'; ?></label>
                                    <input type="text" name="crop_moisture" placeholder="<?php echo $is_bn ? 'যেমন: সর্বোচ্চ ১৪%' : 'e.g., Max 14%'; ?>" class="form-control" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><?php echo $is_bn ? 'ক্রয়ের বিস্তারিত ও শর্তাবলি' : 'Purchase Details &amp; Terms'; ?></label>
                                <textarea name="description" rows="2" placeholder="<?php echo $is_bn ? 'ডেলিভারির সময়, পেমেন্ট শর্ত বা মান সংক্রান্ত তথ্য লিখুন...' : 'Specify delivery time, payment terms, or quality notes...'; ?>" class="form-control" required></textarea>
                            </div>
                            <button type="submit" name="create_post" class="btn btn-emerald"><?php echo $is_bn ? 'ক্রয় পোস্ট প্রকাশ করুন' : 'Publish Purchase Post'; ?></button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'আমার ফসল ক্রয় পোস্টসমূহ' : 'My Crop Purchase Posts'; ?> (<?php echo (int)$total_my_posts; ?>)</span>

                        <form method="GET" action="MillProfile.php" class="filter-bar">
                            <input type="hidden" name="section" value="home-sec">
                            <input type="text" name="post_search" value="<?php echo htmlspecialchars($post_search); ?>" placeholder="<?php echo $is_bn ? 'আপনার পোস্ট খুঁজুন...' : 'Search your posts...'; ?>" class="form-control" style="width: 185px; padding: 6px 10px; font-size: 13px;">
                            <button type="submit" class="btn btn-emerald btn-sm"><?php echo $is_bn ? 'খুঁজুন' : 'Search'; ?></button>
                            <?php if ($post_search !== ''): ?>
                                <a href="MillProfile.php?section=home-sec" class="btn btn-sm" style="background:#64748b; color:#fff;"><?php echo $is_bn ? 'রিসেট' : 'Reset'; ?></a>
                            <?php endif; ?>
                        </form>
                    </h2>

                    <?php if ($my_posts_result && $my_posts_result->num_rows > 0): ?>
                        <div class="posts-grid">
                            <?php while ($row = $my_posts_result->fetch_assoc()): ?>
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
                                        <p><strong><?php echo $is_bn ? 'মিল:' : 'Mill:'; ?></strong> <?php echo htmlspecialchars($row['mill_name'] ?? ($user['mill_name'] ?? '')); ?></p>
                                        <p><strong><?php echo $is_bn ? 'মালিক:' : 'Owner:'; ?></strong> <?php echo htmlspecialchars($row['owner_name'] ?? ($user['name'] ?? '')); ?></p>
                                        <p><strong><?php echo $is_bn ? 'ফোন:' : 'Phone:'; ?></strong> <?php echo htmlspecialchars($row['phone'] ?? ($user['phone'] ?? '')); ?></p>
                                        <p><strong><?php echo $is_bn ? 'ঠিকানা:' : 'Address:'; ?></strong> <?php echo htmlspecialchars($mill_display_address); ?></p>
                                    </div>

                                    <div class="post-footer-area" style="margin-top: 10px;">
                                        <div class="post-meta" style="margin-bottom: 8px;">
                                            <span><?php echo $is_bn ? 'পোস্ট:' : 'Posted:'; ?></span>
                                            <span><?php echo htmlspecialchars($posted_date); ?></span>
                                        </div>

                                        <div style="display:flex; gap:6px;">
                                            <button
                                                type="button"
                                                class="btn btn-secondary toggle-post-btn"
                                                id="btn-<?php echo (int)$row['id']; ?>"
                                                onclick="openPostPopup(<?php echo (int)$row['id']; ?>)"
                                                style="flex:1; background:#64748b; padding: 5px 10px; font-size: 11.5px;"
                                            >
                                                <?php echo htmlspecialchars(__t('show_more')); ?>
                                            </button>
                                            <a
                                                href="MillProfile.php?delete_post_id=<?php echo (int)$row['id']; ?>"
                                                class="btn btn-danger"
                                                onclick="return confirm('<?php echo $is_bn ? 'এই ক্রয় পোস্টটি মুছে ফেলবেন?' : 'Delete this purchase post?'; ?>');"
                                                style="padding: 5px 10px; font-size: 11.5px;"
                                            >
                                                <?php echo $is_bn ? 'মুছুন' : 'Delete'; ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p style="font-size: 14px; color: #64748b;"><?php echo $is_bn ? 'কোনো ফসল ক্রয় পোস্ট পাওয়া যায়নি।' : 'No crop purchase posts found.'; ?></p>
                    <?php endif; ?>
                </div>

                <div class="card-box">
                    <h2 class="section-title">
                        <span><?php echo htmlspecialchars(__t('notice_board')); ?></span>

                        <form method="GET" action="MillProfile.php" class="filter-bar">
                            <input type="hidden" name="section" value="home-sec">

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
                                <a href="MillProfile.php?section=home-sec" class="btn btn-sm" style="background:#64748b; color:#fff;"><?php echo $is_bn ? 'রিসেট' : 'Reset'; ?></a>
                            <?php endif; ?>
                        </form>
                    </h2>

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th><?php echo $is_bn ? 'তারিখ' : 'Date'; ?></th>
                                    <th><?php echo $is_bn ? 'নোটিশের শিরোনাম' : 'Notice Title'; ?></th>
                                    <th><?php echo $is_bn ? 'প্রকাশক' : 'Uploader'; ?></th>
                                    <th><?php echo $is_bn ? 'এলাকা' : 'Location'; ?></th>
                                    <th><?php echo $is_bn ? 'কার্যক্রম' : 'Action'; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($documents_result && $documents_result->num_rows > 0): ?>
                                    <?php while ($doc = $documents_result->fetch_assoc()): ?>
                                        <?php
                                            $doc_loc = implode(', ', array_filter([$doc['upazila'] ?? '', $doc['district'] ?? '']));
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
                                                <br><small style="color:#059669;"><?php echo htmlspecialchars($doc['uploader_designation'] ?? ''); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($doc_loc); ?></td>
                                            <td>
                                                <button
                                                    type="button"
                                                    class="btn btn-emerald btn-sm"
                                                    data-title="<?php echo htmlspecialchars($doc['title'] ?? '', ENT_QUOTES); ?>"
                                                    data-uploader="<?php echo htmlspecialchars($doc['uploader_name'] ?? '', ENT_QUOTES); ?>"
                                                    data-designation="<?php echo htmlspecialchars($doc['uploader_designation'] ?? '', ENT_QUOTES); ?>"
                                                    data-location="<?php echo htmlspecialchars($doc_loc, ENT_QUOTES); ?>"
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
                                    <tr><td colspan="5" style="text-align: center;"><?php echo $is_bn ? 'কোনো নোটিশ পাওয়া যায়নি।' : 'No notices available.'; ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <section id="messages-sec" class="card-box tab-section">
                <h2 class="section-title">
                    <span><?php echo $is_bn ? 'সাম্প্রতিক কথোপকথন' : 'Recent Conversations'; ?></span>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="btn btn-sm btn-emerald" id="filterAllBtn" onclick="filterChatContacts('all')"><?php echo $is_bn ? 'সব' : 'All'; ?></button>
                        <button type="button" class="btn btn-sm btn-blue" id="filterFarmersBtn" onclick="filterChatContacts('farmer')"><?php echo $is_bn ? 'কৃষক' : 'Farmers'; ?></button>
                        <button type="button" class="btn btn-sm" style="background:#7c3aed; color:#fff;" id="filterAdminsBtn" onclick="filterChatContacts('admin')"><?php echo $is_bn ? 'অ্যাডমিন' : 'Admins'; ?></button>
                    </div>
                </h2>

                <div class="chat-container">
                    <div class="chat-sidebar-list" id="chatSidebarList">
                        <?php if (!empty($recent_conversations)): ?>
                            <?php foreach ($recent_conversations as $conv_item): ?>
                                <?php
                                    $item_role = $conv_item['role'];
                                    $item_id   = intval($conv_item['id']);
                                    $is_active = ($active_chat_type === $item_role && $active_chat_id === $item_id);
                                ?>
                                <a
                                    href="MillProfile.php?section=messages-sec&chat_type=<?php echo urlencode($item_role); ?>&chat_with=<?php echo $item_id; ?>"
                                    class="chat-user-item chat-contact-item <?php echo $is_active ? 'active' : ''; ?>"
                                    data-role="<?php echo htmlspecialchars($item_role); ?>"
                                    style="display:block; text-decoration:none; color:inherit;"
                                >
                                    <?php if ($item_role === 'admin'): ?>
                                        <span class="status-badge" style="background:#ede9fe; color:#5b21b6; margin-bottom:3px;"><?php echo $is_bn ? 'অ্যাডমিন' : 'ADMIN'; ?></span><br>
                                    <?php else: ?>
                                        <span class="status-badge status-resolved" style="margin-bottom:3px;"><?php echo $is_bn ? 'কৃষক' : 'FARMER'; ?></span><br>
                                    <?php endif; ?>
                                    <strong><?php echo htmlspecialchars($conv_item['name']); ?></strong><br>
                                    <small style="color:#64748b;"><?php echo htmlspecialchars($conv_item['sub']); ?></small>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding:12px; font-size:13px; color:#64748b;"><?php echo $is_bn ? 'এখনও কোনো কথোপকথন শুরু হয়নি।' : 'No active conversations yet.'; ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="chat-window-area">
                        <?php if ($active_chat_id > 0 && $active_partner_info): ?>
                            <div style="padding: 10px 15px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size:14px; font-weight:600; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <?php echo $is_bn ? 'কথোপকথন' : 'Chat with'; ?> (<?php echo strtoupper(htmlspecialchars($active_chat_type)); ?>):
                                    <?php echo htmlspecialchars($active_partner_info['name']); ?>
                                    <?php if (!empty($active_partner_info['sub'])): ?>
                                        <span style="font-size:12px; color:#64748b; font-weight:400;"> — <?php echo htmlspecialchars($active_partner_info['sub']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <a href="viewProfile.php?type=<?php echo urlencode($active_chat_type); ?>&id=<?php echo (int)$active_chat_id; ?>" class="btn btn-blue btn-sm"><?php echo $is_bn ? 'প্রোফাইল দেখুন' : 'View Profile'; ?></a>
                            </div>

                            <div class="chat-messages-box" id="chatMessagesBox">
                                <?php if ($active_chat_messages && $active_chat_messages->num_rows > 0): ?>
                                    <?php while ($msg = $active_chat_messages->fetch_assoc()): ?>
                                        <?php if (($msg['sender_type'] ?? '') === 'mill_owner'): ?>
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
                                    <div style="text-align:center; color:#94a3b8; margin-top: auto; margin-bottom: auto; font-size:13px;"><?php echo $is_bn ? 'কোনো বার্তা নেই। নিচে বার্তা লিখুন!' : 'No messages yet. Start conversation below!'; ?></div>
                                <?php endif; ?>
                            </div>

                            <?php if ($is_suspended && $active_chat_type === 'farmer'): ?>
                                <div style="padding: 12px 15px; background: #fef2f2; border-top: 1px solid #fecaca; color: #991b1b; font-size: 13px;">
                                    <?php echo $is_bn ? 'আপনার অ্যাকাউন্ট স্থগিত থাকায় কৃষকদের সাথে মেসেজ পাঠানো সাময়িক বন্ধ। তবে আপনি অ্যাডমিনদের সাথে যোগাযোগ করতে পারবেন।' : 'Messaging with farmers is temporarily disabled while your account is suspended. You can still message admins.'; ?>
                                </div>
                            <?php else: ?>
                                <form action="MillProfile.php?section=messages-sec&chat_type=<?php echo urlencode($active_chat_type); ?>&chat_with=<?php echo (int)$active_chat_id; ?>" method="POST" class="chat-input-form">
                                    <input type="hidden" name="receiver_id" value="<?php echo (int)$active_chat_id; ?>">
                                    <input type="hidden" name="receiver_type" value="<?php echo htmlspecialchars($active_chat_type); ?>">
                                    <input type="text" name="message" placeholder="<?php echo $is_bn ? 'বার্তা লিখুন...' : 'Type a message...'; ?>" class="form-control" autocomplete="off" required style="flex:1;">
                                    <button type="submit" name="send_message" class="btn btn-emerald"><?php echo $is_bn ? 'পাঠান' : 'Send'; ?></button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="display:flex; align-items:center; justify-content:center; height:100%; color:#94a3b8; font-size:14px; text-align:center; padding:16px;">
                                <?php echo $is_bn ? 'কথোপকথন শুরু করতে বাম পাশ থেকে একটি কথোপকথন নির্বাচন করুন।' : 'Select a conversation from the left sidebar to start chatting.'; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section id="notification-sec" class="card-box tab-section">
                <h2 class="section-title">
                    <span><?php echo $is_bn ? 'অ্যাকাউন্ট অবস্থা নোটিফিকেশন' : 'Account Status Notifications'; ?></span>
                </h2>
                <div class="table-responsive" style="margin-bottom: 24px;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?php echo $is_bn ? 'তারিখ ও সময়' : 'Date &amp; Time'; ?></th>
                                <th><?php echo $is_bn ? 'অবস্থা' : 'Status'; ?></th>
                                <th><?php echo $is_bn ? 'অভিযোগ নং' : 'Complaint No.'; ?></th>
                                <th><?php echo $is_bn ? 'শিরোনাম ও বিস্তারিত' : 'Title &amp; Details'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($account_notif_res && $account_notif_res->num_rows > 0): ?>
                                <?php while ($an = $account_notif_res->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo !empty($an['created_at']) ? date('d M Y, h:i A', strtotime($an['created_at'])) : ''; ?></td>
                                        <td>
                                            <span class="status-badge <?php echo (($an['event_type'] ?? '') === 'SUSPENDED') ? 'status-suspended' : 'status-resolved'; ?>">
                                                <?php echo htmlspecialchars($an['event_type'] ?? ''); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($an['complaint_no'] ?: '-'); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($an['title'] ?? ''); ?></strong><br>
                                            <small><?php echo htmlspecialchars($an['message'] ?? ''); ?></small>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center;"><?php echo $is_bn ? 'কোনো অ্যাকাউন্ট নোটিফিকেশন পাওয়া যায়নি।' : 'No account action notifications found.'; ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <h2 class="section-title">
                    <span><?php echo $is_bn ? 'সিস্টেম নোটিফিকেশন ও নির্দেশনা' : 'System Notifications &amp; Directives'; ?></span>
                </h2>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?php echo $is_bn ? 'তারিখ' : 'Date'; ?></th>
                                <th><?php echo $is_bn ? 'শিরোনাম' : 'Title'; ?></th>
                                <th><?php echo $is_bn ? 'বিস্তারিত' : 'Details'; ?></th>
                                <th><?php echo $is_bn ? 'কার্যক্রম' : 'Action'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($notif_res && $notif_res->num_rows > 0): ?>
                                <?php while ($notif = $notif_res->fetch_assoc()): ?>
                                    <?php $notif_date = !empty($notif['created_at']) ? date('d M Y', strtotime($notif['created_at'])) : ''; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($notif_date); ?></td>
                                        <td><strong><?php echo htmlspecialchars($notif['title'] ?? ''); ?></strong></td>
                                        <td><?php echo htmlspecialchars($notif['details'] ?? ''); ?></td>
                                        <td>
                                            <?php if (!empty($notif['file_path'])): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-emerald btn-sm"
                                                    data-title="<?php echo htmlspecialchars($notif['title'] ?? 'Directive File', ENT_QUOTES); ?>"
                                                    data-meta="<?php echo htmlspecialchars('Date: ' . $notif_date, ENT_QUOTES); ?>"
                                                    data-file-path="<?php echo htmlspecialchars($notif['file_path'], ENT_QUOTES); ?>"
                                                    data-file-type="<?php echo htmlspecialchars(strtolower($notif['file_type'] ?? pathinfo($notif['file_path'], PATHINFO_EXTENSION)), ENT_QUOTES); ?>"
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
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center;"><?php echo $is_bn ? 'কোনো নোটিফিকেশন পাওয়া যায়নি।' : 'No notifications found.'; ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="complaint-sec" class="card-box tab-section">
                <h2 class="section-title">
                    <span><?php echo $is_bn ? 'অভিযোগ জমা ও অবস্থা যাচাই' : 'Submit &amp; Track Complaints'; ?></span>
                </h2>

                <form action="MillProfile.php?section=complaint-sec" method="POST" style="margin-bottom: 24px;">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label"><?php echo $is_bn ? 'যে কর্মকর্তার কাছে অভিযোগ পাঠাবেন' : 'Send Complaint To Officer'; ?></label>
                            <select name="target_officer" class="form-control" required>
                                <option value=""><?php echo $is_bn ? 'কর্মকর্তা নির্বাচন করুন' : 'Select Officer'; ?></option>
                                <option value="Upazila Agriculture Officer"><?php echo $is_bn ? 'উপজেলা কৃষি কর্মকর্তা' : 'Upazila Agriculture Officer'; ?></option>
                                <option value="Upazila Commerce Officer"><?php echo $is_bn ? 'উপজেলা বাণিজ্য কর্মকর্তা' : 'Upazila Commerce Officer'; ?></option>
                                <option value="Upazila Nirbahi Officer (UNO)"><?php echo $is_bn ? 'উপজেলা নির্বাহী অফিসার (ইউএনও)' : 'Upazila Nirbahi Officer (UNO)'; ?></option>
                                <option value="District Food Controller"><?php echo $is_bn ? 'জেলা খাদ্য নিয়ন্ত্রক' : 'District Food Controller'; ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?php echo $is_bn ? 'জেলা' : 'District'; ?></label>
                            <select name="comp_district" id="compDistrictSelect" onchange="populateCompUpazilas()" class="form-control" required>
                                <option value=""><?php echo $is_bn ? 'জেলা নির্বাচন করুন' : 'Select District'; ?></option>
                                <option value="Bogura">Bogura</option>
                                <option value="Chapainawabganj">Chapainawabganj</option>
                                <option value="Joypurhat">Joypurhat</option>
                                <option value="Naogaon">Naogaon</option>
                                <option value="Natore">Natore</option>
                                <option value="Pabna">Pabna</option>
                                <option value="Rajshahi">Rajshahi</option>
                                <option value="Sirajganj">Sirajganj</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label"><?php echo $is_bn ? 'উপজেলা' : 'Upazila'; ?></label>
                            <select name="comp_upazila" id="compUpazilaSelect" class="form-control" required>
                                <option value=""><?php echo $is_bn ? 'উপজেলা নির্বাচন করুন' : 'Select Upazila'; ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?php echo $is_bn ? 'অভিযোগের বিষয়' : 'Complaint Subject'; ?></label>
                            <input type="text" name="subject" placeholder="<?php echo $is_bn ? 'যেমন: সরবরাহ / পরিবহন / বাজার সমস্যা' : 'e.g., Supply / Transport / Market Issue'; ?>" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo $is_bn ? 'বিস্তারিত বিবরণ' : 'Description'; ?></label>
                        <textarea name="description" rows="3" placeholder="<?php echo $is_bn ? 'আপনার সমস্যা বিস্তারিত লিখুন...' : 'Describe your issue in detail...'; ?>" class="form-control" required></textarea>
                    </div>
                    <button type="submit" name="submit_complaint" class="btn btn-blue"><?php echo $is_bn ? 'অভিযোগ জমা দিন' : 'Submit Complaint'; ?></button>
                </form>

                <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 12px; color: #1e293b;"><?php echo $is_bn ? 'আমার অভিযোগের তালিকা' : 'My Complaint History'; ?></h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><?php echo $is_bn ? 'তারিখ' : 'Date'; ?></th>
                                <th><?php echo $is_bn ? 'বিষয় ও বিবরণ' : 'Subject &amp; Details'; ?></th>
                                <th><?php echo $is_bn ? 'অবস্থা' : 'Status'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($my_complaints && $my_complaints->num_rows > 0): ?>
                                <?php while ($mc = $my_complaints->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo !empty($mc['created_at']) ? date('d M Y', strtotime($mc['created_at'])) : ''; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($mc['subject'] ?? ''); ?></strong><br>
                                            <small><?php echo htmlspecialchars($mc['description'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <?php if (($mc['status'] ?? '') === 'Pending'): ?>
                                                <span class="status-badge status-pending"><?php echo $is_bn ? 'অমীমাংসিত' : 'Pending'; ?></span>
                                            <?php else: ?>
                                                <span class="status-badge status-resolved"><?php echo $is_bn ? 'সমাধানকৃত' : 'Resolved'; ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="text-align: center;"><?php echo $is_bn ? 'আপনি কোনো অভিযোগ জমা দেননি।' : 'You have not submitted any complaints.'; ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div id="profile-sec" class="card-box tab-section">
                <h2 class="section-title">
                    <span><?php echo $is_bn ? 'মিল মালিক প্রোফাইল তথ্য' : 'Mill Owner Profile Information'; ?></span>
                    <a href="viewProfile.php?type=mill_owner&id=<?php echo (int)$mill_db_id; ?>" class="btn btn-blue btn-sm"><?php echo $is_bn ? 'পাবলিক প্রিভিউ' : 'Public / Role Preview'; ?></a>
                </h2>

                <?php if ($user): ?>
                    <div style="display:flex; align-items:center; gap:18px; padding-bottom:16px; margin-bottom:16px; border-bottom:1px solid #e2e8f0;">
                        <?php if ($mill_photo !== '' && file_exists($mill_photo)): ?>
                            <img src="<?php echo htmlspecialchars($mill_photo); ?>" alt="Mill Profile Picture" style="width:86px; height:86px; border-radius:50%; object-fit:cover; border:2px solid #059669;">
                        <?php else: ?>
                            <div style="width:86px; height:86px; border-radius:50%; background:#d1fae5; color:#065f46; display:flex; align-items:center; justify-content:center; font-size:30px; font-weight:700; border:2px solid #059669;">
                                <?php echo htmlspecialchars($mill_initial); ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h3 style="margin:0 0 4px 0; font-size:18px; color:#0f172a;"><?php echo htmlspecialchars($user['mill_name'] ?? ''); ?></h3>
                            <div style="font-size:13px; color:#059669; font-weight:600;"><?php echo $is_bn ? 'মালিক:' : 'Owner:'; ?> <?php echo htmlspecialchars($user['name'] ?? ''); ?></div>
                            <div style="font-size:12px; color:#64748b; margin-top:2px;"><?php echo htmlspecialchars($mill_display_address); ?></div>
                        </div>
                    </div>

                    <div class="profile-row">
                        <span class="profile-label"><?php echo $is_bn ? 'মিল আইডি:' : 'Mill ID:'; ?></span>
                        <span><?php echo htmlspecialchars($mill_code); ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-label"><?php echo $is_bn ? 'অ্যাকাউন্টের অবস্থা:' : 'Account Status:'; ?></span>
                        <span>
                            <?php if ($is_suspended): ?>
                                <strong style="color:#b91c1c;"><?php echo $is_bn ? 'সাময়িক স্থগিত' : 'Temporarily Suspended'; ?><?php echo $suspended_until !== '' ? ($is_bn ? ' (' . htmlspecialchars($suspended_until) . ' পর্যন্ত)' : ' (Until ' . htmlspecialchars($suspended_until) . ')') : ''; ?></strong>
                            <?php else: ?>
                                <strong style="color:#047857;"><?php echo $is_bn ? 'সক্রিয়' : 'Active'; ?></strong>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-label"><?php echo $is_bn ? 'মিলের নাম:' : 'Mill Name:'; ?></span>
                        <span><?php echo htmlspecialchars($user['mill_name'] ?? ''); ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-label"><?php echo $is_bn ? 'মালিকের পূর্ণ নাম:' : 'Owner Full Name:'; ?></span>
                        <span><?php echo htmlspecialchars($user['name'] ?? ''); ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-label"><?php echo $is_bn ? 'এনআইডি নম্বর:' : 'NID Number:'; ?></span>
                        <span><?php echo htmlspecialchars($user['nid'] ?? ''); ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-label"><?php echo $is_bn ? 'মোবাইল নম্বর:' : 'Phone Number:'; ?></span>
                        <span><?php echo htmlspecialchars($user['phone'] ?? ''); ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-label"><?php echo $is_bn ? 'ইমেইল ঠিকানা:' : 'Email Address:'; ?></span>
                        <span><?php echo htmlspecialchars($user['email'] ?? ''); ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-label"><?php echo $is_bn ? 'ফসলের ধরন:' : 'Crop Types:'; ?></span>
                        <span><?php echo htmlspecialchars($user['crop_types'] ?? ''); ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-label"><?php echo $is_bn ? 'ঠিকানা:' : 'Address:'; ?></span>
                        <span><?php echo htmlspecialchars($mill_display_address); ?></span>
                    </div>
                <?php endif; ?>

                <button type="button" id="openUpdateModalBtn" class="btn btn-emerald" style="margin-top: 18px;"><?php echo $is_bn ? 'প্রোফাইল ও ছবি আপডেট করুন' : 'Update Profile &amp; Photo'; ?></button>
            </div>

        </main>
    </div>

    <div id="postDetailsModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="postModalTitle">
        <div class="modal-box post-popup-box">
            <div class="modal-header">
                <h3 id="postModalTitle"><?php echo $is_bn ? 'ফসল ক্রয় পোস্টের বিস্তারিত' : 'Crop Purchase Post Details'; ?></h3>
                <span id="closePostModalBtn" class="close-modal" title="Close">&times;</span>
            </div>
            <div id="postModalBody" class="post-popup-body"></div>
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
                <h3><?php echo $is_bn ? 'মিল মালিক প্রোফাইল আপডেট' : 'Update Mill Owner Profile'; ?></h3>
                <span id="closeUpdateModalBtn" class="close-modal">&times;</span>
            </div>
            <form action="MillProfile.php?section=profile-sec" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'প্রোফাইল ছবি (JPG, PNG, WEBP)' : 'Profile Picture (JPG, PNG, WEBP)'; ?></label>
                    <input type="file" name="profile_photo" class="form-control" accept=".jpg, .jpeg, .png, .webp">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'মিল আইডি' : 'Mill ID'; ?></label>
                    <input type="text" name="mill_id" value="<?php echo htmlspecialchars($mill_code); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'মিলের নাম' : 'Mill Name'; ?></label>
                    <input type="text" name="mill_name" value="<?php echo htmlspecialchars($user['mill_name'] ?? ''); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'মালিকের পূর্ণ নাম' : 'Owner Full Name'; ?></label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'মোবাইল নম্বর' : 'Phone Number'; ?></label>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'ইমেইল ঠিকানা' : 'Email Address'; ?></label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'ফসলের ধরন' : 'Crop Types'; ?></label>
                    <input type="text" name="crop_types" value="<?php echo htmlspecialchars($user['crop_types'] ?? ''); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'ঠিকানা (গ্রাম, উপজেলা, জেলা)' : 'Address (Village, Upazila, District)'; ?></label>
                    <input type="text" name="address" value="<?php echo htmlspecialchars($mill_display_address); ?>" placeholder="e.g., Shyampur, Sherpur, Bogura" class="form-control" required>
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

        function populateCompUpazilas() {
            const districtSelect = document.getElementById("compDistrictSelect");
            const upazilaSelect = document.getElementById("compUpazilaSelect");
            const selectedDistrict = districtSelect.value;

            upazilaSelect.innerHTML = '<option value="">Select Upazila</option>';

            if (selectedDistrict && rajshahiUpazilas[selectedDistrict]) {
                rajshahiUpazilas[selectedDistrict].forEach(upazila => {
                    const option = document.createElement("option");
                    option.value = upazila;
                    option.text = upazila;
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

            if (sectionId === 'messages-sec') {
                scrollChatToBottom();
            }
        }

        function filterChatContacts(roleFilter) {
            document.querySelectorAll('.chat-contact-item').forEach(item => {
                const role = item.getAttribute('data-role');
                if (roleFilter === 'all' || role === roleFilter) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
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
        const closePostModalBtn = document.getElementById('closePostModalBtn');

        function openPostPopup(postId) {
            const detailsDiv = document.getElementById('details-' + postId);
            const cardEl = detailsDiv ? detailsDiv.closest('.post-card') : null;
            const headingEl = cardEl ? cardEl.querySelector('.post-basic-info h4') : null;

            if (!detailsDiv || !postDetailsModal || !postModalBody) return;

            if (postModalTitle) {
                postModalTitle.textContent = headingEl ? headingEl.textContent : 'Crop Purchase Post Details';
            }
            postModalBody.innerHTML = detailsDiv.innerHTML;
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
            const location = btnEl.getAttribute('data-location') || '';
            const date = btnEl.getAttribute('data-date') || '';
            const filePath = btnEl.getAttribute('data-file-path') || '';
            const fileType = (btnEl.getAttribute('data-file-type') || '').toLowerCase();

            if (noticeModalTitle) noticeModalTitle.textContent = title;
            if (noticeModalMeta) {
                const uploaderText = designation ? `${uploader} (${designation})` : uploader;
                noticeModalMeta.innerHTML =
                    `<strong>Uploader:</strong> ${escapeHtml(uploaderText)}` +
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
            populateCompUpazilas();
            switchSection(<?php echo json_encode($default_section); ?>, null);
            scrollChatToBottom();
        });
    </script>

</body>
</html>
