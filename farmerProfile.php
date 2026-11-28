<?php
/**
 * Repository: farmers-market-portal
 * File: farmerProfile.php
 * Module: Farmer Dashboard & Marketplace View
 * Description: Handles farmer profile updates (including profile photo) using
 *              village, upazila, and district columns (without a deleted address
 *              column), prioritizes nearby mill posts, adds role-based profile links,
 *              and supports search & filtering, popups, messaging, and complaints.
 */
session_start();
require_once 'lang.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'farmer') {
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

function ensureFarmerSchema(mysqli $conn): void
{
    $col_chk = $conn->query("SHOW COLUMNS FROM farmers LIKE 'profile_photo'");
    if ($col_chk && $col_chk->num_rows === 0) {
        $conn->query("ALTER TABLE farmers ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL");
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

ensureFarmerSchema($conn);

$farmer_id  = intval($_SESSION['user_id']);
$flash_msg  = "";
$flash_type = "alert-success";

// 1. Fetch Farmer Data
$stmt = $conn->prepare("SELECT * FROM farmers WHERE id = ?");
$stmt->bind_param("i", $farmer_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: homePage.php?login=true");
    exit();
}

$farmer_village  = trim($user['village'] ?? '');
$farmer_upazila  = trim($user['upazila'] ?? '');
$farmer_district = trim($user['district'] ?? '');
$farmer_display_address = formatLocationColumns($user);

// 2. Handle Farmer Profile Update (Including Profile Photo Upload)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $name       = trim($_POST['name'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $nid        = trim($_POST['nid'] ?? '');
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
            $photo_dir = "uploads/farmer_photos/";
            if (!is_dir($photo_dir)) {
                mkdir($photo_dir, 0777, true);
            }
            $new_photo_file = "farmer_" . $farmer_id . "_" . time() . "." . $img_ext;
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
        $stmt = $conn->prepare("UPDATE farmers SET name=?, phone=?, nid=?, village=?, upazila=?, district=?, profile_photo=? WHERE id=?");
        $stmt->bind_param("sssssssi", $name, $phone, $nid, $village, $upazila, $district, $photo_path, $farmer_id);

        if ($stmt->execute()) {
            $flash_msg = "Farmer profile updated successfully!";
            $user['name']          = $name;
            $user['phone']         = $phone;
            $user['nid']           = $nid;
            $user['village']       = $village;
            $user['upazila']       = $upazila;
            $user['district']      = $district;
            $user['profile_photo'] = $photo_path;

            $farmer_village  = $village;
            $farmer_upazila  = $upazila;
            $farmer_district = $district;
            $farmer_display_address = formatLocationColumns($user);
        } else {
            $flash_msg  = "Error updating profile: " . $stmt->error;
            $flash_type = "alert-error";
        }
        $stmt->close();
    }
}

// 3. Handle Send Direct Chat Message
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_message'])) {
    $receiver_id   = intval($_POST['receiver_id'] ?? 0);
    $receiver_type = trim($_POST['receiver_type'] ?? 'mill_owner');
    $message       = trim($_POST['message'] ?? '');

    if ($receiver_id > 0 && $message !== '') {
        $stmt = $conn->prepare("INSERT INTO direct_messages (sender_type, sender_id, receiver_type, receiver_id, message) VALUES ('farmer', ?, ?, ?, ?)");
        $stmt->bind_param("isis", $farmer_id, $receiver_type, $receiver_id, $message);
        $stmt->execute();
        $stmt->close();
        header("Location: farmerProfile.php?section=messages-sec&chat_with=" . $receiver_id);
        exit();
    }
}

// 4. Handle Submit Complaint
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_complaint'])) {
    $subject        = trim($_POST['subject'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $comp_district  = trim($_POST['comp_district'] ?? '');
    $comp_upazila   = trim($_POST['comp_upazila'] ?? '');
    $target_officer = trim($_POST['target_officer'] ?? '');

    $full_subject = $subject . " [To: " . $target_officer . "]";
    $sender_name  = $user['name'] ?? '';
    $sender_phone = $user['phone'] ?? '';

    $stmt = $conn->prepare("INSERT INTO complaints (sender_id, sender_name, sender_role, sender_phone, district, upazila, subject, description, status) VALUES (?, ?, 'farmer', ?, ?, ?, ?, ?, 'Pending')");
    $stmt->bind_param("issssss", $farmer_id, $sender_name, $sender_phone, $comp_district, $comp_upazila, $full_subject, $description);
    if ($stmt->execute()) {
        $flash_msg = "Complaint submitted successfully to " . $target_officer . "!";
    } else {
        $flash_msg  = "Failed to submit complaint: " . $stmt->error;
        $flash_type = "alert-error";
    }
    $stmt->close();
}

// 5. Fetch Official Notices with District, Upazila & Search Filter
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

// 6. Mill Purchase Posts with Proximity Priority (Same Upazila -> Same District -> Others) + Search & Filter
$filter_district = trim($_GET['filter_district'] ?? '');
$filter_upazila  = trim($_GET['filter_upazila'] ?? '');
$filter_crop     = trim($_GET['filter_crop'] ?? '');
$search_query    = trim($_GET['search_query'] ?? '');

$u_prio_like = "%" . $farmer_upazila . "%";
$d_prio_like = "%" . $farmer_district . "%";

$posts_query = "
    SELECT mp.*,
           COALESCE(NULLIF(mo.mill_name, ''), mp.mill_name) AS display_mill_name,
           COALESCE(NULLIF(mo.name, ''), mp.owner_name) AS display_owner_name,
           COALESCE(NULLIF(mo.phone, ''), mp.phone) AS display_phone,
           COALESCE(
               NULLIF(TRIM(CONCAT_WS(', ', NULLIF(mo.village, ''), NULLIF(mo.upazila, ''), NULLIF(mo.district, ''))), ''),
               mp.address
           ) AS display_address,
           COALESCE(mo.id, mp.mill_id) AS resolved_mill_id,
           CASE
               WHEN (? <> '' AND (mo.upazila LIKE ? OR mp.address LIKE ?)) THEN 1
               WHEN (? <> '' AND (mo.district LIKE ? OR mp.address LIKE ?)) THEN 2
               ELSE 3
           END AS proximity_rank
    FROM mill_post mp
    LEFT JOIN mill_owners mo ON mp.mill_id = mo.mill_id OR mp.mill_id = CAST(mo.id AS CHAR)
    WHERE 1=1
";

$params = [
    $farmer_upazila, $u_prio_like, $u_prio_like,
    $farmer_district, $d_prio_like, $d_prio_like
];
$types = "ssssss";

if ($filter_district !== '') {
    $posts_query .= " AND (mo.district LIKE ? OR mp.address LIKE ?)";
    $d_like = "%" . $filter_district . "%";
    $params[] = $d_like;
    $params[] = $d_like;
    $types .= "ss";
}

if ($filter_upazila !== '') {
    $posts_query .= " AND (mo.upazila LIKE ? OR mp.address LIKE ?)";
    $u_like = "%" . $filter_upazila . "%";
    $params[] = $u_like;
    $params[] = $u_like;
    $types .= "ss";
}

if ($filter_crop !== '') {
    if ($filter_crop === 'Paddy') {
        $posts_query .= " AND (mp.crop_name LIKE '%Paddy%' OR mp.crop_name LIKE '%Rice%' OR mp.crop_type LIKE '%Paddy%' OR mp.crop_type LIKE '%Rice%')";
    } elseif ($filter_crop === 'Wheat') {
        $posts_query .= " AND (mp.crop_name LIKE '%Wheat%' OR mp.crop_type LIKE '%Wheat%')";
    } elseif ($filter_crop === 'Corn') {
        $posts_query .= " AND (mp.crop_name LIKE '%Corn%' OR mp.crop_name LIKE '%Maize%' OR mp.crop_name LIKE '%Cron%' OR mp.crop_type LIKE '%Corn%' OR mp.crop_type LIKE '%Maize%')";
    } elseif ($filter_crop === 'Others') {
        $posts_query .= " AND (
            mp.crop_name NOT LIKE '%Paddy%' AND mp.crop_name NOT LIKE '%Rice%'
            AND mp.crop_name NOT LIKE '%Wheat%' AND mp.crop_name NOT LIKE '%Corn%'
            AND mp.crop_name NOT LIKE '%Maize%' AND mp.crop_name NOT LIKE '%Cron%'
            AND mp.crop_type NOT LIKE '%Paddy%' AND mp.crop_type NOT LIKE '%Rice%'
            AND mp.crop_type NOT LIKE '%Wheat%' AND mp.crop_type NOT LIKE '%Corn%'
            AND mp.crop_type NOT LIKE '%Maize%'
        )";
    }
}

if ($search_query !== '') {
    $posts_query .= " AND (mp.crop_name LIKE ? OR mp.crop_type LIKE ? OR mp.address LIKE ? OR mo.mill_name LIKE ? OR mo.village LIKE ? OR mo.upazila LIKE ? OR mo.district LIKE ?)";
    $sq_like = "%" . $search_query . "%";
    $params[] = $sq_like;
    $params[] = $sq_like;
    $params[] = $sq_like;
    $params[] = $sq_like;
    $params[] = $sq_like;
    $params[] = $sq_like;
    $params[] = $sq_like;
    $types .= "sssssss";
}

$posts_query .= " ORDER BY proximity_rank ASC, mp.created_at DESC";

$stmt_posts = $conn->prepare($posts_query);
$stmt_posts->bind_param($types, ...$params);
$stmt_posts->execute();
$posts_result = $stmt_posts->get_result();
$stmt_posts->close();

// Fetch only mill owners who have an active conversation with this farmer, ordered by most recent message
$stmt_chat_mills = $conn->prepare("
    SELECT mo.id, mo.mill_id, mo.mill_name, mo.name, mo.village, mo.upazila, mo.district, MAX(dm.created_at) AS last_msg_time
    FROM mill_owners mo
    INNER JOIN direct_messages dm
        ON (
            (dm.sender_type = 'farmer' AND dm.sender_id = ? AND dm.receiver_type = 'mill_owner' AND dm.receiver_id = mo.id)
            OR
            (dm.sender_type = 'mill_owner' AND dm.sender_id = mo.id AND dm.receiver_type = 'farmer' AND dm.receiver_id = ?)
        )
    GROUP BY mo.id, mo.mill_id, mo.mill_name, mo.name, mo.village, mo.upazila, mo.district
    ORDER BY last_msg_time DESC, mo.id DESC
");
$stmt_chat_mills->bind_param("ii", $farmer_id, $farmer_id);
$stmt_chat_mills->execute();
$mills_chat_list = $stmt_chat_mills->get_result();
$stmt_chat_mills->close();

// Active Chat
$raw_chat_with = trim($_GET['chat_with'] ?? '');
$active_chat_messages = null;
$active_chat_info = null;
$actual_mill_db_id = 0;

if ($raw_chat_with !== '') {
    $num_id = ctype_digit($raw_chat_with) ? intval($raw_chat_with) : 0;
    $stmt_mi = $conn->prepare("SELECT * FROM mill_owners WHERE id = ? OR mill_id = ? LIMIT 1");
    $stmt_mi->bind_param("is", $num_id, $raw_chat_with);
    $stmt_mi->execute();
    $active_chat_info = $stmt_mi->get_result()->fetch_assoc();
    $stmt_mi->close();

    if ($active_chat_info) {
        $actual_mill_db_id = intval($active_chat_info['id']);
        $stmt_msg = $conn->prepare("SELECT * FROM direct_messages WHERE (sender_type='farmer' AND sender_id=? AND receiver_type='mill_owner' AND receiver_id=?) OR (sender_type='mill_owner' AND sender_id=? AND receiver_type='farmer' AND receiver_id=?) ORDER BY created_at ASC, id ASC");
        $stmt_msg->bind_param("iiii", $farmer_id, $actual_mill_db_id, $actual_mill_db_id, $farmer_id);
        $stmt_msg->execute();
        $active_chat_messages = $stmt_msg->get_result();
        $stmt_msg->close();
    }
}

// Farmer's Complaints History
$stmt_fc = $conn->prepare("SELECT * FROM complaints WHERE sender_id = ? AND sender_role = 'farmer' ORDER BY created_at DESC");
$stmt_fc->bind_param("i", $farmer_id);
$stmt_fc->execute();
$my_complaints = $stmt_fc->get_result();
$stmt_fc->close();

// Farmer Notifications / Directives
$stmt_notif = $conn->prepare("SELECT * FROM admin_orders WHERE target_audience IN ('farmer', 'both') ORDER BY created_at DESC");
$stmt_notif->execute();
$notif_res = $stmt_notif->get_result();
$stmt_notif->close();

$conn->close();

$default_section = 'home-sec';
if (isset($_GET['section'])) {
    $default_section = $_GET['section'];
} elseif (
    $filter_district !== '' || $filter_upazila !== '' || $filter_crop !== '' || $search_query !== '' ||
    $notice_district !== '' || $notice_upazila !== '' || $notice_search !== ''
) {
    $default_section = 'home-sec';
} elseif ($raw_chat_with !== '') {
    $default_section = 'messages-sec';
} elseif (isset($_POST['submit_complaint'])) {
    $default_section = 'complaint-sec';
} elseif (isset($_POST['update_profile'])) {
    $default_section = 'profile-sec';
}

$farmer_photo   = trim($user['profile_photo'] ?? '');
$farmer_initial = strtoupper(substr(trim($user['name'] ?? 'F'), 0, 1));
$is_bn = ($current_lang === 'bn');
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_bn ? 'কৃষক ড্যাশবোর্ড | কৃষক বাজার' : "Farmer Dashboard | Farmer's Market"; ?></title>
    <link rel="stylesheet" href="farmerProfileSytle.css">
   <link rel="stylesheet" href="lang-toggle.css">
</head>
<body>

    <header class="top-navbar">
        <div class="nav-container">
            <div class="brand-area">
                <div>
                    <div class="brand-title"><?php echo htmlspecialchars(__t('site_name')); ?></div>
                    <div class="brand-subtitle">
                        <?php echo $is_bn ? 'কৃষক ড্যাশবোর্ড | স্বাগতম,' : 'Farmer Dashboard | Welcome,'; ?>
                        <?php echo htmlspecialchars($user['name'] ?? 'Farmer'); ?>
                    </div>
                </div>
            </div>
            <div class="nav-user-area" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <div class="lang-switch-group">
                   <?php render_lang_toggle(); ?>
                </div>
                <?php if ($farmer_photo !== '' && file_exists($farmer_photo)): ?>
                    <img src="<?php echo htmlspecialchars($farmer_photo); ?>" alt="Farmer Photo" style="width:34px; height:34px; border-radius:50%; object-fit:cover; border:1px solid #cbd5e1;">
                <?php endif; ?>
                <span><?php echo $is_bn ? 'ফোন:' : 'Phone:'; ?> <strong><?php echo htmlspecialchars($user['phone'] ?? ''); ?></strong></span>
            </div>
        </div>
    </header>

    <div class="main-wrapper">

        <aside class="sidebar">
            <a onclick="switchSection('home-sec', this)" id="nav-home-sec" class="active-link">
                <span><?php echo $is_bn ? 'হোম' : 'Home'; ?></span>
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
                <span><?php echo $is_bn ? 'আমার প্রোফাইল' : 'My Profile'; ?></span>
            </a>
            <a href="logout.php" class="logout-btn"><?php echo $is_bn ? 'লগআউট' : 'Logout'; ?></a>
        </aside>

        <main class="main-content">

            <?php if (!empty($flash_msg)): ?>
                <div class="alert <?php echo htmlspecialchars($flash_type); ?>"><?php echo htmlspecialchars($flash_msg); ?></div>
            <?php endif; ?>

            <div id="home-sec" class="tab-section active-section">
                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'কৃষক বাজার পোর্টাল পরিচিতি' : "About Farmer's Market Portal"; ?></span>
                    </h2>
                    <p style="font-size: 14px; color: #475569; line-height: 1.6;">
                        <?php echo $is_bn
                            ? 'কৃষক বাজার প্ল্যাটফর্মে আপনাকে স্বাগতম। আপনার উপজেলা ও জেলার মিলগুলোর ফসল ক্রয় পোস্টগুলো অগ্রাধিকার ভিত্তিতে আগে দেখানো হয়, যাতে আপনি সহজেই কাছের ক্রেতা খুঁজে পান, দর যাচাই করতে পারেন, সরকারি নোটিশ দেখতে পারেন এবং মিল মালিকদের সাথে সরাসরি যোগাযোগ করতে পারেন।'
                            : "Welcome to the Farmer's Market platform. Posts from mills in your Upazila and District are prioritized first so you can easily find nearby buyers, compare prices, check official notices, and connect directly with mill owners."; ?>
                    </p>
                </div>

                <div class="card-box" style="margin-bottom: 24px;">
                    <h2 class="section-title">
                        <span><?php echo $is_bn ? 'মিল মালিকদের ফসল ক্রয় পোস্ট' : "Mill Owners' Crop Purchase Posts"; ?></span>

                        <form method="GET" action="farmerProfile.php" class="filter-bar">
                            <input type="hidden" name="section" value="home-sec">

                            <input type="text" name="search_query" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="<?php echo $is_bn ? 'ফসল, মিল, উপজেলা, জেলা খুঁজুন...' : 'Search crop, mill, upazila, district...'; ?>" class="form-control" style="width: 195px; padding: 6px 10px; font-size: 13px;">

                            <select name="filter_district" id="districtSelect" onchange="populateUpazilas()" class="form-control" style="width: 150px; padding: 6px 10px; font-size: 13px;">
                                <option value=""><?php echo $is_bn ? 'জেলা নির্বাচন করুন' : 'Select District'; ?></option>
                                <option value="Bogura" <?php if ($filter_district === 'Bogura') echo 'selected'; ?>>Bogura</option>
                                <option value="Chapainawabganj" <?php if ($filter_district === 'Chapainawabganj') echo 'selected'; ?>>Chapainawabganj</option>
                                <option value="Joypurhat" <?php if ($filter_district === 'Joypurhat') echo 'selected'; ?>>Joypurhat</option>
                                <option value="Naogaon" <?php if ($filter_district === 'Naogaon') echo 'selected'; ?>>Naogaon</option>
                                <option value="Natore" <?php if ($filter_district === 'Natore') echo 'selected'; ?>>Natore</option>
                                <option value="Pabna" <?php if ($filter_district === 'Pabna') echo 'selected'; ?>>Pabna</option>
                                <option value="Rajshahi" <?php if ($filter_district === 'Rajshahi') echo 'selected'; ?>>Rajshahi</option>
                                <option value="Sirajganj" <?php if ($filter_district === 'Sirajganj') echo 'selected'; ?>>Sirajganj</option>
                            </select>

                            <select name="filter_upazila" id="upazilaSelect" class="form-control" style="width: 150px; padding: 6px 10px; font-size: 13px;">
                                <option value=""><?php echo $is_bn ? 'উপজেলা নির্বাচন করুন' : 'Select Upazila'; ?></option>
                            </select>

                            <select name="filter_crop" class="form-control" style="width: 140px; padding: 6px 10px; font-size: 13px;">
                                <option value=""><?php echo $is_bn ? 'ফসল নির্বাচন করুন' : 'Select Crop'; ?></option>
                                <option value="Paddy" <?php if ($filter_crop === 'Paddy') echo 'selected'; ?>><?php echo $is_bn ? 'ধান / চাল' : 'Paddy / Rice'; ?></option>
                                <option value="Wheat" <?php if ($filter_crop === 'Wheat') echo 'selected'; ?>><?php echo $is_bn ? 'গম' : 'Wheat'; ?></option>
                                <option value="Corn" <?php if ($filter_crop === 'Corn') echo 'selected'; ?>><?php echo $is_bn ? 'ভুট্টা' : 'Corn / Maize'; ?></option>
                                <option value="Others" <?php if ($filter_crop === 'Others') echo 'selected'; ?>><?php echo $is_bn ? 'অন্যান্য' : 'Others'; ?></option>
                            </select>

                            <button type="submit" class="btn btn-emerald btn-sm"><?php echo $is_bn ? 'খুঁজুন ও ফিল্টার' : 'Search &amp; Filter'; ?></button>
                            <?php if ($filter_district !== '' || $filter_upazila !== '' || $filter_crop !== '' || $search_query !== ''): ?>
                                <a href="farmerProfile.php?section=home-sec" class="btn btn-sm" style="background:#64748b; color:#fff;"><?php echo $is_bn ? 'রিসেট' : 'Reset'; ?></a>
                            <?php endif; ?>
                        </form>
                    </h2>

                    <?php if ($posts_result && $posts_result->num_rows > 0): ?>
                        <div class="posts-grid">
                            <?php while ($row = $posts_result->fetch_assoc()): ?>
                                <?php
                                    $posted_date = !empty($row['created_at']) ? date('d M Y', strtotime($row['created_at'])) : '';
                                    $rank = intval($row['proximity_rank'] ?? 3);
                                    if ($is_bn) {
                                        $proximity_label = $rank === 1 ? 'নিকটবর্তী (উপজেলা)' : ($rank === 2 ? 'একই জেলা' : 'অন্যান্য এলাকা');
                                    } else {
                                        $proximity_label = $rank === 1 ? 'Nearby (Upazila)' : ($rank === 2 ? 'Same District' : 'Other Area');
                                    }
                                ?>
                                <div class="post-card compact-post">
                                    <div class="post-basic-info">
                                        <h4><?php echo htmlspecialchars($row['crop_name'] ?? ''); ?></h4>
                                        <p><strong><?php echo $is_bn ? 'ধরন:' : 'Type:'; ?></strong> <?php echo htmlspecialchars($row['crop_type'] ?? ''); ?></p>
                                        <p><strong><?php echo $is_bn ? 'দর:' : 'Price:'; ?></strong> <?php echo htmlspecialchars($row['price'] ?? ''); ?> <?php echo $is_bn ? 'টাকা /' : 'Taka /'; ?> <?php echo htmlspecialchars($row['unit'] ?? ''); ?></p>
                                        <p><strong><?php echo $is_bn ? 'এলাকা:' : 'Area:'; ?></strong> <?php echo htmlspecialchars($row['display_address'] ?? ''); ?></p>
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
                                            <span><?php echo $is_bn ? 'পোস্ট:' : 'Posted:'; ?> <?php echo htmlspecialchars($posted_date); ?></span>
                                            <span><?php echo htmlspecialchars($proximity_label); ?></span>
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
                        <p style="font-size: 14px; color: #64748b;"><?php echo $is_bn ? 'আপনার খোঁজ বা ফিল্টার অনুযায়ী কোনো সক্রিয় ক্রয় পোস্ট পাওয়া যায়নি।' : 'No active purchase posts available matching your search or filter.'; ?></p>
                    <?php endif; ?>
                </div>

                <div class="card-box">
                    <h2 class="section-title">
                        <span><?php echo htmlspecialchars(__t('notice_board')); ?></span>

                        <form method="GET" action="farmerProfile.php" class="filter-bar">
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
                                <a href="farmerProfile.php?section=home-sec" class="btn btn-sm" style="background:#64748b; color:#fff;"><?php echo $is_bn ? 'রিসেট' : 'Reset'; ?></a>
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
                    <span><?php echo $is_bn ? 'মিল মালিকদের সাথে সাম্প্রতিক কথোপকথন' : 'Recent Conversations with Mill Owners'; ?></span>
                </h2>
                <div class="chat-container">
                    <div class="chat-sidebar-list">
                        <?php if ($mills_chat_list && $mills_chat_list->num_rows > 0): ?>
                            <?php while ($m_item = $mills_chat_list->fetch_assoc()): ?>
                                <?php
                                    $is_active_mill = ($actual_mill_db_id > 0 && $actual_mill_db_id === intval($m_item['id']));
                                    $m_addr = formatLocationColumns($m_item);
                                ?>
                                <a href="farmerProfile.php?section=messages-sec&chat_with=<?php echo (int)$m_item['id']; ?>" class="chat-user-item <?php echo $is_active_mill ? 'active' : ''; ?>" style="display:block; text-decoration:none; color:inherit;">
                                    <strong><?php echo htmlspecialchars($m_item['mill_name'] ?? ''); ?></strong><br>
                                    <small style="color:#64748b;"><?php echo $is_bn ? 'মালিক:' : 'Owner:'; ?> <?php echo htmlspecialchars($m_item['name'] ?? ''); ?><?php echo $m_addr !== '' ? ' | ' . htmlspecialchars($m_addr) : ''; ?></small>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="padding:12px; font-size:13px; color:#64748b;"><?php echo $is_bn ? 'এখনও কোনো কথোপকথন শুরু হয়নি।' : 'No active conversations yet.'; ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="chat-window-area">
                        <?php if ($actual_mill_db_id > 0 && $active_chat_info): ?>
                            <div style="padding: 10px 15px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size:14px; font-weight:600; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <?php echo $is_bn ? 'কথোপকথন:' : 'Chat with:'; ?> <?php echo htmlspecialchars($active_chat_info['mill_name'] ?? ''); ?> (<?php echo htmlspecialchars($active_chat_info['name'] ?? ''); ?>)
                                </div>
                                <a href="viewProfile.php?type=mill_owner&id=<?php echo (int)$actual_mill_db_id; ?>" class="btn btn-blue btn-sm"><?php echo $is_bn ? 'মিল প্রোফাইল দেখুন' : 'View Mill Profile'; ?></a>
                            </div>
                            <div class="chat-messages-box" id="chatMessagesBox">
                                <?php if ($active_chat_messages && $active_chat_messages->num_rows > 0): ?>
                                    <?php while ($msg = $active_chat_messages->fetch_assoc()): ?>
                                        <?php if (($msg['sender_type'] ?? '') === 'farmer'): ?>
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
                            <form action="farmerProfile.php?section=messages-sec&chat_with=<?php echo (int)$actual_mill_db_id; ?>" method="POST" class="chat-input-form">
                                <input type="hidden" name="receiver_id" value="<?php echo (int)$actual_mill_db_id; ?>">
                                <input type="hidden" name="receiver_type" value="mill_owner">
                                <input type="text" name="message" placeholder="<?php echo $is_bn ? 'বার্তা লিখুন...' : 'Type a message...'; ?>" class="form-control" autocomplete="off" required style="flex:1;">
                                <button type="submit" name="send_message" class="btn btn-emerald"><?php echo $is_bn ? 'পাঠান' : 'Send'; ?></button>
                            </form>
                        <?php else: ?>
                            <div style="display:flex; align-items:center; justify-content:center; height:100%; color:#94a3b8; font-size:14px;">
                                <?php echo $is_bn ? 'কথোপকথন শুরু করতে বাম পাশ থেকে একটি কথোপকথন নির্বাচন করুন।' : 'Select a conversation from the sidebar to chat.'; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section id="notification-sec" class="card-box tab-section">
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

                <form action="farmerProfile.php?section=complaint-sec" method="POST" style="margin-bottom: 24px;">
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
                            <input type="text" name="subject" placeholder="<?php echo $is_bn ? 'যেমন: ন্যায্য মূল্য সমস্যা' : 'e.g., Fair Pricing Issue'; ?>" class="form-control" required>
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
                    <span><?php echo $is_bn ? 'কৃষক প্রোফাইল তথ্য' : 'Farmer Profile Information'; ?></span>
                    <a href="viewProfile.php?type=farmer&id=<?php echo (int)$farmer_id; ?>" class="btn btn-blue btn-sm"><?php echo $is_bn ? 'পাবলিক প্রিভিউ' : 'Public / Role Preview'; ?></a>
                </h2>

                <?php if ($user): ?>
                    <div style="display:flex; align-items:center; gap:18px; padding-bottom:16px; margin-bottom:16px; border-bottom:1px solid #e2e8f0;">
                        <?php if ($farmer_photo !== '' && file_exists($farmer_photo)): ?>
                            <img src="<?php echo htmlspecialchars($farmer_photo); ?>" alt="Farmer Profile Picture" style="width:86px; height:86px; border-radius:50%; object-fit:cover; border:2px solid #059669;">
                        <?php else: ?>
                            <div style="width:86px; height:86px; border-radius:50%; background:#d1fae5; color:#065f46; display:flex; align-items:center; justify-content:center; font-size:30px; font-weight:700; border:2px solid #059669;">
                                <?php echo htmlspecialchars($farmer_initial); ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h3 style="margin:0 0 4px 0; font-size:18px; color:#0f172a;"><?php echo htmlspecialchars($user['name'] ?? ''); ?></h3>
                            <div style="font-size:13px; color:#059669; font-weight:600;"><?php echo $is_bn ? 'কৃষক আইডি:' : 'Farmer ID:'; ?> #<?php echo htmlspecialchars($user['id'] ?? ''); ?></div>
                            <div style="font-size:12px; color:#64748b; margin-top:2px;"><?php echo htmlspecialchars($farmer_display_address); ?></div>
                        </div>
                    </div>

                    <div class="profile-row">
                        <span class="profile-label"><?php echo $is_bn ? 'কৃষক আইডি:' : 'Farmer ID:'; ?></span>
                        <span>#<?php echo htmlspecialchars($user['id'] ?? ''); ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-label"><?php echo $is_bn ? 'পূর্ণ নাম:' : 'Full Name:'; ?></span>
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
                        <span class="profile-label"><?php echo $is_bn ? 'ঠিকানা:' : 'Address:'; ?></span>
                        <span><?php echo htmlspecialchars($farmer_display_address); ?></span>
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
            <div id="postModalFooter" class="post-popup-footer" style="display:flex; gap:8px;">
                <a id="postModalProfileBtn" href="#" class="btn btn-emerald btn-sm" style="flex:1; text-align:center;"><?php echo $is_bn ? 'মিল প্রোফাইল দেখুন' : 'View Mill Profile'; ?></a>
                <a id="postModalMessageBtn" href="#" class="btn btn-blue btn-sm" style="flex:1; text-align:center;"><?php echo $is_bn ? 'বার্তা পাঠান' : 'Message'; ?></a>
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
                <h3><?php echo $is_bn ? 'কৃষক প্রোফাইল আপডেট' : 'Update Farmer Profile'; ?></h3>
                <span id="closeUpdateModalBtn" class="close-modal">&times;</span>
            </div>
            <form action="farmerProfile.php?section=profile-sec" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'প্রোফাইল ছবি (JPG, PNG, WEBP)' : 'Profile Picture (JPG, PNG, WEBP)'; ?></label>
                    <input type="file" name="profile_photo" class="form-control" accept=".jpg, .jpeg, .png, .webp">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'পূর্ণ নাম' : 'Full Name'; ?></label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'এনআইডি নম্বর' : 'NID Number'; ?></label>
                    <input type="text" name="nid" value="<?php echo htmlspecialchars($user['nid'] ?? ''); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'মোবাইল নম্বর' : 'Phone Number'; ?></label>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'ঠিকানা (গ্রাম, উপজেলা, জেলা)' : 'Address (Village, Upazila, District)'; ?></label>
                    <input type="text" name="address" value="<?php echo htmlspecialchars($farmer_display_address); ?>" placeholder="e.g., Shyampur, Sherpur, Bogura" class="form-control" required>
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

        const preselectedUpazila = <?php echo json_encode($filter_upazila); ?>;
        const preselectedNoticeUpazila = <?php echo json_encode($notice_upazila); ?>;

        function populateUpazilas() {
            const districtSelect = document.getElementById("districtSelect");
            const upazilaSelect = document.getElementById("upazilaSelect");
            const selectedDistrict = districtSelect.value;

            upazilaSelect.innerHTML = '<option value="">Select Upazila</option>';

            if (selectedDistrict && rajshahiUpazilas[selectedDistrict]) {
                rajshahiUpazilas[selectedDistrict].forEach(upazila => {
                    const option = document.createElement("option");
                    option.value = upazila;
                    option.text = upazila;
                    if (upazila === preselectedUpazila) {
                        option.selected = true;
                    }
                    upazilaSelect.appendChild(option);
                });
            }
        }

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
        const postModalProfileBtn = document.getElementById('postModalProfileBtn');
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

            if (postModalProfileBtn) {
                if (millId) {
                    postModalProfileBtn.href = 'viewProfile.php?type=mill_owner&id=' + encodeURIComponent(millId);
                    postModalProfileBtn.style.display = 'inline-flex';
                } else {
                    postModalProfileBtn.style.display = 'none';
                }
            }

            if (postModalMessageBtn) {
                if (millId) {
                    postModalMessageBtn.href = 'farmerProfile.php?section=messages-sec&chat_with=' + encodeURIComponent(millId);
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
            populateUpazilas();
            populateNoticeUpazilas();
            populateCompUpazilas();
            switchSection(<?php echo json_encode($default_section); ?>, null);
            scrollChatToBottom();
        });
    </script>

</body>
</html>
