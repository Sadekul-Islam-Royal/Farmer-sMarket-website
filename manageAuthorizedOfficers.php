<?php
/**
 * Repository: farmers-market-portal
 * File: manageAuthorizedOfficers.php
 * Module: Super Admin - Government Officer Registry & Verification List
 */
session_start();
require_once 'lang.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: homePage.php?login=true");
    exit();
}

$servername = "sql209.infinityfree.com";
$username   = "if0_43517157";
$password   = "Sadekul3055";
$dbname     = "if0_43517157_farmers_market";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$is_bn = ($current_lang === 'bn');

$current_user_id = intval($_SESSION['user_id']);
$current_role    = strtolower(trim($_SESSION['role'] ?? ''));

// Check super admin status safely
$is_super_admin = ($current_role === 'super_admin');

if (!$is_super_admin && $current_user_id > 0) {
    $chk_sa = $conn->query("SHOW COLUMNS FROM admins LIKE 'is_super_admin'");
    if ($chk_sa && $chk_sa->num_rows > 0) {
        $stmt_sa = $conn->prepare("SELECT is_super_admin FROM admins WHERE id = ? LIMIT 1");
        $stmt_sa->bind_param("i", $current_user_id);
        $stmt_sa->execute();
        $sa_row = $stmt_sa->get_result()->fetch_assoc();
        $stmt_sa->close();
        if ($sa_row && intval($sa_row['is_super_admin'] ?? 0) === 1) {
            $is_super_admin = true;
        }
    }
}

if (!$is_super_admin) {
    $conn->close();
    http_response_code(403);
    die($is_bn
        ? "অ্যাক্সেস নিষিদ্ধ: শুধুমাত্র সুপার অ্যাডমিন অনুমোদিত কর্মকর্তাদের রেজিস্ট্রি অ্যাক্সেস করতে পারবেন। <a href='adminPage.php'>ফিরে যান</a>"
        : "Access Denied: Only Super Admin can access Authorized Officers Registry. <a href='adminPage.php'>Go Back</a>");
}

if (!function_exists('ensureOfficerWhitelistSchema')) {
    function ensureOfficerWhitelistSchema(mysqli $conn): void
    {
        $conn->query("
            CREATE TABLE IF NOT EXISTS authorized_officers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                officer_code VARCHAR(60) NOT NULL UNIQUE,
                full_name VARCHAR(150) NOT NULL,
                designation VARCHAR(120) NOT NULL,
                district VARCHAR(100) NOT NULL,
                upazila VARCHAR(100) NOT NULL,
                is_registered TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('verifyOfficerRegistration')) {
    function verifyOfficerRegistration(mysqli $conn, string $officerCode, string $requestedDesignation): array
    {
        $code = trim($officerCode);
        $role = trim($requestedDesignation);

        if ($code === '' || $role === '') {
            return [
                'ok'     => false,
                'reason' => 'Officer ID and designation are required for registration.'
            ];
        }

        $stmt = $conn->prepare("SELECT id, full_name, designation, district, upazila, is_registered FROM authorized_officers WHERE officer_code = ? LIMIT 1");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$record) {
            return [
                'ok'     => false,
                'reason' => 'This Officer ID is not listed in the official government officer registry.'
            ];
        }

        if (strcasecmp(trim($record['designation']), $role) !== 0) {
            return [
                'ok'     => false,
                'reason' => 'Designation mismatch: this Officer ID is officially assigned as "' . $record['designation'] . '", not "' . $role . '".'
            ];
        }

        if (intval($record['is_registered'] ?? 0) === 1) {
            return [
                'ok'     => false,
                'reason' => 'This Officer ID has already completed admin registration.'
            ];
        }

        return [
            'ok'      => true,
            'officer' => $record
        ];
    }
}

ensureOfficerWhitelistSchema($conn);

$flash_msg  = "";
$flash_type = "alert-success";

// 1. Add New Authorized Government Officer
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_officer'])) {
    $officer_code = strtoupper(trim($_POST['officer_code'] ?? ''));
    $full_name    = trim($_POST['full_name'] ?? '');
    $designation  = trim($_POST['designation'] ?? '');
    $district     = trim($_POST['district'] ?? '');
    $upazila      = trim($_POST['upazila'] ?? '');

    if ($officer_code === '' || $full_name === '' || $designation === '' || $district === '' || $upazila === '') {
        $flash_msg  = $is_bn
            ? "সবগুলো তথ্য (অফিসার আইডি, পূর্ণ নাম, পদবি, জেলা, উপজেলা) পূরণ করা আবশ্যক।"
            : "All fields (Officer ID, Full Name, Designation, District, Upazila) are required.";
        $flash_type = "alert-error";
    } else {
        $chk = $conn->prepare("SELECT id FROM authorized_officers WHERE officer_code = ? LIMIT 1");
        $chk->bind_param("s", $officer_code);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($exists) {
            $flash_msg  = $is_bn
                ? "অফিসার আইডি '{$officer_code}' ইতিমধ্যে সরকারি রেজিস্ট্রিতে রয়েছে।"
                : "Officer ID '{$officer_code}' already exists in the official registry.";
            $flash_type = "alert-error";
        } else {
            $stmt = $conn->prepare("INSERT INTO authorized_officers (officer_code, full_name, designation, district, upazila, is_registered) VALUES (?, ?, ?, ?, ?, 0)");
            $stmt->bind_param("sssss", $officer_code, $full_name, $designation, $district, $upazila);
            if ($stmt->execute()) {
                $flash_msg = $is_bn
                    ? "অনুমোদিত কর্মকর্তা '{$full_name}' ({$officer_code}) সফলভাবে যুক্ত করা হয়েছে।"
                    : "Authorized officer '{$full_name}' ({$officer_code}) added successfully.";
            } else {
                $flash_msg  = ($is_bn ? "কর্মকর্তা যুক্ত করতে ব্যর্থ হয়েছে: " : "Failed to add officer: ") . $stmt->error;
                $flash_type = "alert-error";
            }
            $stmt->close();
        }
    }
}

// 2. Update Existing Authorized Officer Record
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_officer'])) {
    $edit_id      = intval($_POST['edit_id'] ?? 0);
    $officer_code = strtoupper(trim($_POST['officer_code'] ?? ''));
    $full_name    = trim($_POST['full_name'] ?? '');
    $designation  = trim($_POST['designation'] ?? '');
    $district     = trim($_POST['district'] ?? '');
    $upazila      = trim($_POST['upazila'] ?? '');

    if ($edit_id <= 0 || $officer_code === '' || $full_name === '' || $designation === '' || $district === '' || $upazila === '') {
        $flash_msg  = $is_bn
            ? "আপডেটের তথ্য সঠিক নয়। অনুগ্রহ করে সবগুলো ঘর পূরণ করুন।"
            : "Invalid update data. Please fill out all required fields.";
        $flash_type = "alert-error";
    } else {
        $chk = $conn->prepare("SELECT id FROM authorized_officers WHERE officer_code = ? AND id <> ? LIMIT 1");
        $chk->bind_param("si", $officer_code, $edit_id);
        $chk->execute();
        $dup = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($dup) {
            $flash_msg  = $is_bn
                ? "অন্য একজন কর্মকর্তা ইতিমধ্যে অফিসার আইডি '{$officer_code}' ব্যবহার করছেন।"
                : "Another officer already uses Officer ID '{$officer_code}'.";
            $flash_type = "alert-error";
        } else {
            $stmt = $conn->prepare("UPDATE authorized_officers SET officer_code = ?, full_name = ?, designation = ?, district = ?, upazila = ? WHERE id = ?");
            $stmt->bind_param("sssssi", $officer_code, $full_name, $designation, $district, $upazila, $edit_id);
            if ($stmt->execute()) {
                $flash_msg = $is_bn ? "কর্মকর্তার তথ্য সফলভাবে আপডেট করা হয়েছে।" : "Officer record updated successfully.";
            } else {
                $flash_msg  = ($is_bn ? "কর্মকর্তার তথ্য আপডেট করতে ব্যর্থ হয়েছে: " : "Failed to update officer: ") . $stmt->error;
                $flash_type = "alert-error";
            }
            $stmt->close();
        }
    }
}

// 3. Delete Authorized Officer Record
if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    if ($del_id > 0) {
        $stmt = $conn->prepare("DELETE FROM authorized_officers WHERE id = ?");
        $stmt->bind_param("i", $del_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $flash_msg = $is_bn ? "কর্মকর্তার তথ্য রেজিস্ট্রি থেকে মুছে ফেলা হয়েছে।" : "Officer record removed from the registry.";
        } else {
            $flash_msg  = $is_bn ? "কর্মকর্তার তথ্য মুছে ফেলা যায়নি।" : "Could not delete officer record.";
            $flash_type = "alert-error";
        }
        $stmt->close();
    }
    header("Location: manageAuthorizedOfficers.php");
    exit();
}

// 4. Filter & Search Registry List
$search_q    = trim($_GET['search_q'] ?? '');
$filter_role = trim($_GET['filter_role'] ?? '');
$filter_dist = trim($_GET['filter_dist'] ?? '');

$query  = "SELECT * FROM authorized_officers WHERE 1=1";
$params = [];
$types  = "";

if ($search_q !== '') {
    $query .= " AND (officer_code LIKE ? OR full_name LIKE ? OR upazila LIKE ?)";
    $like = "%" . $search_q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}
if ($filter_role !== '') {
    $query .= " AND designation LIKE ?";
    $params[] = "%" . $filter_role . "%";
    $types .= "s";
}
if ($filter_dist !== '') {
    $query .= " AND district = ?";
    $params[] = $filter_dist;
    $types .= "s";
}
$query .= " ORDER BY id DESC";

if (!empty($params)) {
    $stmt_list = $conn->prepare($query);
    $stmt_list->bind_param($types, ...$params);
    $stmt_list->execute();
    $officers_res = $stmt_list->get_result();
    $stmt_list->close();
} else {
    $officers_res = $conn->query($query);
}

$conn->close();

if (!function_exists('getDesignationBadgeClass')) {
    function getDesignationBadgeClass(string $designation): string
    {
        if (stripos($designation, 'UNO') !== false) {
            return 'badge-uno';
        }
        if (stripos($designation, 'Agriculture') !== false) {
            return 'badge-agri';
        }
        if (stripos($designation, 'Commerce') !== false) {
            return 'badge-commerce';
        }
        return 'badge-food';
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_bn ? 'অনুমোদিত সরকারি কর্মকর্তা | সুপার অ্যাডমিন' : 'Authorized Government Officers | Super Admin'; ?></title>
    <link rel="stylesheet" href="officerWhitelistStyle.css">
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

    <header class="top-bar">
        <div class="top-bar-inner">
            <div>
                <div class="top-bar-title"><?php echo $is_bn ? 'সরকারি কর্মকর্তা রেজিস্ট্রি ও যাচাইকরণ' : 'Government Officer Registry &amp; Verification'; ?></div>
                <div class="top-bar-sub"><?php echo $is_bn ? 'সুপার অ্যাডমিন নিয়ন্ত্রণ | শুধুমাত্র যাচাইকৃত অফিসার আইডি ও পদবি' : 'Super Admin Control | Verified Officer IDs &amp; Role Assignments Only'; ?></div>
            </div>
            <div class="action-group" style="flex-wrap:wrap;">
                <div class="lang-switch-group">
                    <a href="<?php echo lang_url('bn'); ?>" class="lang-switch-btn <?php echo $is_bn ? 'active' : ''; ?>">বাংলা</a>
                    <a href="<?php echo lang_url('en'); ?>" class="lang-switch-btn <?php echo !$is_bn ? 'active' : ''; ?>">English</a>
                </div>
                <a href="adminPage.php" class="btn btn-slate btn-sm"><?php echo $is_bn ? 'অ্যাডমিন ড্যাশবোর্ডে ফিরুন' : 'Back to Admin Dashboard'; ?></a>
            </div>
        </div>
    </header>

    <main class="page-container">

        <?php if ($flash_msg !== ''): ?>
            <div class="alert <?php echo htmlspecialchars($flash_type); ?>">
                <?php echo htmlspecialchars($flash_msg); ?>
            </div>
        <?php endif; ?>

        <section class="card">
            <div class="card-header">
                <h2 class="card-title"><?php echo $is_bn ? 'অনুমোদিত সরকারি কর্মকর্তা যুক্ত করুন' : 'Add Authorized Government Officer'; ?></h2>
            </div>

            <form method="POST" action="manageAuthorizedOfficers.php">
                <div class="form-grid-4">
                    <div class="form-group">
                        <label class="form-label"><?php echo $is_bn ? 'সরকারি অফিসার আইডি' : 'Official Officer ID'; ?></label>
                        <input type="text" name="officer_code" placeholder="<?php echo $is_bn ? 'যেমন: OFF-UNO-101' : 'e.g., OFF-UNO-101'; ?>" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo $is_bn ? 'কর্মকর্তার পূর্ণ নাম' : 'Officer Full Name'; ?></label>
                        <input type="text" name="full_name" placeholder="<?php echo $is_bn ? 'যেমন: মো. তারিকুল ইসলাম' : 'e.g., Md. Tariqul Islam'; ?>" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo $is_bn ? 'নির্ধারিত পদবি' : 'Designated Role'; ?></label>
                        <input type="text" name="designation" placeholder="<?php echo $is_bn ? 'যেমন: Upazila Agriculture Officer' : 'e.g., Upazila Agriculture Officer'; ?>" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo $is_bn ? 'জেলা' : 'District'; ?></label>
                        <select name="district" id="addDistrict" onchange="populateUpazilas('addDistrict', 'addUpazila')" class="form-control" required>
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
                        <select name="upazila" id="addUpazila" class="form-control" required>
                            <option value=""><?php echo $is_bn ? 'উপজেলা নির্বাচন করুন' : 'Select Upazila'; ?></option>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end;">
                        <button type="submit" name="add_officer" class="btn btn-emerald"><?php echo $is_bn ? 'সরকারি রেজিস্ট্রিতে যুক্ত করুন' : 'Add to Official Registry'; ?></button>
                    </div>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="card-header">
                <h2 class="card-title"><?php echo $is_bn ? 'অনুমোদিত কর্মকর্তাদের তালিকা' : 'Authorized Officers List'; ?></h2>

                <form method="GET" action="manageAuthorizedOfficers.php" class="filter-form">
                    <input type="text" name="search_q" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="<?php echo $is_bn ? 'আইডি, নাম, উপজেলা খুঁজুন...' : 'Search ID, name, upazila...'; ?>" class="form-control" style="width:185px;">

                    <input type="text" name="filter_role" value="<?php echo htmlspecialchars($filter_role); ?>" placeholder="<?php echo $is_bn ? 'পদবি ফিল্টার...' : 'Filter role...'; ?>" class="form-control" style="width:170px;">

                    <select name="filter_dist" class="form-control" style="width:145px;">
                        <option value=""><?php echo $is_bn ? 'সব জেলা' : 'All Districts'; ?></option>
                        <?php foreach (["Bogura","Chapainawabganj","Joypurhat","Naogaon","Natore","Pabna","Rajshahi","Sirajganj"] as $d): ?>
                            <option value="<?php echo $d; ?>" <?php if ($filter_dist === $d) echo 'selected'; ?>><?php echo $d; ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn btn-blue btn-sm"><?php echo $is_bn ? 'ফিল্টার' : 'Filter'; ?></button>
                    <?php if ($search_q !== '' || $filter_role !== '' || $filter_dist !== ''): ?>
                        <a href="manageAuthorizedOfficers.php" class="btn btn-slate btn-sm"><?php echo $is_bn ? 'রিসেট' : 'Reset'; ?></a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-wrap">
                <table class="officer-table">
                    <thead>
                        <tr>
                            <th><?php echo $is_bn ? 'অফিসার আইডি' : 'Officer ID'; ?></th>
                            <th><?php echo $is_bn ? 'কর্মকর্তার নাম' : 'Officer Name'; ?></th>
                            <th><?php echo $is_bn ? 'নির্ধারিত পদবি' : 'Assigned Role'; ?></th>
                            <th><?php echo $is_bn ? 'জেলা' : 'District'; ?></th>
                            <th><?php echo $is_bn ? 'উপজেলা' : 'Upazila'; ?></th>
                            <th><?php echo $is_bn ? 'নিবন্ধন অবস্থা' : 'Registration'; ?></th>
                            <th><?php echo $is_bn ? 'কার্যক্রম' : 'Actions'; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($officers_res && $officers_res->num_rows > 0): ?>
                            <?php while ($off = $officers_res->fetch_assoc()): ?>
                                <?php
                                    $is_reg = intval($off['is_registered'] ?? 0) === 1;
                                    $badge_cls = getDesignationBadgeClass($off['designation'] ?? '');
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($off['officer_code'] ?? ''); ?></strong></td>
                                    <td><?php echo htmlspecialchars($off['full_name'] ?? ''); ?></td>
                                    <td>
                                        <span class="badge <?php echo htmlspecialchars($badge_cls); ?>">
                                            <?php echo htmlspecialchars($off['designation'] ?? ''); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($off['district'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($off['upazila'] ?? ''); ?></td>
                                    <td>
                                        <?php if ($is_reg): ?>
                                            <span class="badge badge-status-reg"><?php echo $is_bn ? 'নিবন্ধিত' : 'Registered'; ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-status-unreg"><?php echo $is_bn ? 'নিবন্ধন অপেক্ষমাণ' : 'Pending Registration'; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <button
                                                type="button"
                                                class="btn btn-blue btn-sm"
                                                data-id="<?php echo (int)$off['id']; ?>"
                                                data-code="<?php echo htmlspecialchars($off['officer_code'] ?? '', ENT_QUOTES); ?>"
                                                data-name="<?php echo htmlspecialchars($off['full_name'] ?? '', ENT_QUOTES); ?>"
                                                data-designation="<?php echo htmlspecialchars($off['designation'] ?? '', ENT_QUOTES); ?>"
                                                data-district="<?php echo htmlspecialchars($off['district'] ?? '', ENT_QUOTES); ?>"
                                                data-upazila="<?php echo htmlspecialchars($off['upazila'] ?? '', ENT_QUOTES); ?>"
                                                onclick="openEditModal(this)"
                                            >
                                                <?php echo $is_bn ? 'সম্পাদনা' : 'Edit'; ?>
                                            </button>
                                            <a
                                                href="manageAuthorizedOfficers.php?delete_id=<?php echo (int)$off['id']; ?>"
                                                class="btn btn-danger btn-sm"
                                                onclick="return confirm('<?php echo $is_bn ? 'এই অনুমোদিত কর্মকর্তার তথ্য মুছে ফেলবেন?' : 'Delete this authorized officer record?'; ?>');"
                                            >
                                                <?php echo $is_bn ? 'মুছুন' : 'Delete'; ?>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align:center; color:#64748b;"><?php echo $is_bn ? 'কোনো অনুমোদিত কর্মকর্তার তথ্য পাওয়া যায়নি।' : 'No authorized officer records found.'; ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>

    <div id="editOfficerModal" class="modal-backdrop">
        <div class="modal-card">
            <div class="modal-head">
                <h3 class="modal-title"><?php echo $is_bn ? 'কর্মকর্তার তথ্য সম্পাদনা' : 'Edit Officer Record'; ?></h3>
                <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="manageAuthorizedOfficers.php">
                <input type="hidden" name="edit_id" id="editOfficerId">

                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'সরকারি অফিসার আইডি' : 'Official Officer ID'; ?></label>
                    <input type="text" name="officer_code" id="editOfficerCode" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'কর্মকর্তার পূর্ণ নাম' : 'Officer Full Name'; ?></label>
                    <input type="text" name="full_name" id="editOfficerName" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo $is_bn ? 'নির্ধারিত পদবি' : 'Designated Role'; ?></label>
                    <input type="text" name="designation" id="editOfficerDesignation" class="form-control" required>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo $is_bn ? 'জেলা' : 'District'; ?></label>
                        <select name="district" id="editDistrict" onchange="populateUpazilas('editDistrict', 'editUpazila', '')" class="form-control" required>
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

                    <div class="form-group">
                        <label class="form-label"><?php echo $is_bn ? 'উপজেলা' : 'Upazila'; ?></label>
                        <select name="upazila" id="editUpazila" class="form-control" required>
                            <option value=""><?php echo $is_bn ? 'উপজেলা নির্বাচন করুন' : 'Select Upazila'; ?></option>
                        </select>
                    </div>
                </div>

                <div class="action-group" style="justify-content:flex-end;">
                    <button type="button" class="btn btn-slate" onclick="closeEditModal()"><?php echo $is_bn ? 'বাতিল' : 'Cancel'; ?></button>
                    <button type="submit" name="update_officer" class="btn btn-emerald"><?php echo $is_bn ? 'পরিবর্তন সংরক্ষণ করুন' : 'Save Changes'; ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const isBnLang = <?php echo json_encode($is_bn); ?>;
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

        function populateUpazilas(districtSelectId, upazilaSelectId, selectedUpazila = "") {
            const distEl = document.getElementById(districtSelectId);
            const upzEl  = document.getElementById(upazilaSelectId);
            if (!distEl || !upzEl) return;

            const distVal = distEl.value;
            upzEl.innerHTML = `<option value="">${isBnLang ? 'উপজেলা নির্বাচন করুন' : 'Select Upazila'}</option>`;

            if (distVal && rajshahiUpazilas[distVal]) {
                rajshahiUpazilas[distVal].forEach(u => {
                    const opt = document.createElement("option");
                    opt.value = u;
                    opt.text = u;
                    if (selectedUpazila && selectedUpazila === u) {
                        opt.selected = true;
                    }
                    upzEl.appendChild(opt);
                });
            }
        }

        const editModal = document.getElementById("editOfficerModal");

        function openEditModal(btn) {
            if (!editModal || !btn) return;
            document.getElementById("editOfficerId").value = btn.getAttribute("data-id") || "";
            document.getElementById("editOfficerCode").value = btn.getAttribute("data-code") || "";
            document.getElementById("editOfficerName").value = btn.getAttribute("data-name") || "";
            document.getElementById("editOfficerDesignation").value = btn.getAttribute("data-designation") || "";

            const dist = btn.getAttribute("data-district") || "";
            const upz  = btn.getAttribute("data-upazila") || "";
            const distSelect = document.getElementById("editDistrict");
            distSelect.value = dist;
            populateUpazilas("editDistrict", "editUpazila", upz);

            editModal.style.display = "block";
        }

        function closeEditModal() {
            if (editModal) {
                editModal.style.display = "none";
            }
        }

        window.onclick = function(e) {
            if (e.target === editModal) {
                closeEditModal();
            }
        };

        document.addEventListener("keydown", function(e) {
            if (e.key === "Escape") {
                closeEditModal();
            }
        });
    </script>
</body>
</html>