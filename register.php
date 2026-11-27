<?php
session_start();
require_once 'lang.php';

$is_bn = ($current_lang === 'bn');

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

function normalizePhoneDigits(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (substr($digits, 0, 3) === '880') {
        $digits = '0' . substr($digits, 3);
    }
    return $digits;
}

function ensureAdminRegistrySchema(mysqli $conn): void
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

    $colDesig = $conn->query("SHOW COLUMNS FROM admins LIKE 'designation'");
    if ($colDesig && $colDesig->num_rows === 0) {
        $conn->query("ALTER TABLE admins ADD COLUMN designation VARCHAR(120) DEFAULT NULL");
    }

    $colEmp = $conn->query("SHOW COLUMNS FROM admins LIKE 'employee_id'");
    if ($colEmp && $colEmp->num_rows === 0) {
        $conn->query("ALTER TABLE admins ADD COLUMN employee_id VARCHAR(60) DEFAULT NULL");
    }
}

function verifyAdminAgainstRegistry(
    mysqli $conn,
    string $name,
    string $email,
    string $employeeId,
    string $designation,
    string $rawAddr,
    bool $isBn = false
): array {
    $cleanName  = strtolower(trim($name));
    $cleanEmail = strtolower(trim($email));
    $cleanCode  = strtoupper(trim($employeeId));
    $cleanRole  = trim($designation);

    if ($cleanName === '' || $cleanEmail === '' || $cleanCode === '' || $cleanRole === '') {
        return [
            'valid'   => false,
            'message' => $isBn
                ? 'নাম, ইমেইল, এমপ্লয়ি আইডি এবং দাপ্তরিক পদবি আবশ্যক।'
                : 'Name, Email, Employee ID, and Official Designation are required.'
        ];
    }

    $stmt = $conn->prepare("SELECT id, officer_code, full_name, designation, district, upazila, is_registered FROM authorized_officers WHERE officer_code = ? LIMIT 1");
    if (!$stmt) {
        return [
            'valid'   => false,
            'message' => $isBn
                ? 'এই মুহূর্তে অফিসার রেজিস্ট্রি যাচাই করা যাচ্ছে না।'
                : 'Unable to verify officer registry right now.'
        ];
    }
    $stmt->bind_param("s", $cleanCode);
    $stmt->execute();
    $regOfficer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$regOfficer) {
        return [
            'valid'   => false,
            'message' => $isBn
                ? 'এই এমপ্লয়ি / অফিসার আইডি সরকারি রেজিস্ট্রিতে পাওয়া যায়নি।'
                : 'This Employee / Officer ID is not found in the official government registry.'
        ];
    }

    if (intval($regOfficer['is_registered'] ?? 0) === 1) {
        return [
            'valid'   => false,
            'message' => $isBn
                ? 'এই অফিসার আইডি দিয়ে ইতিমধ্যে নিবন্ধন সম্পন্ন হয়েছে।'
                : 'This Officer ID has already been registered.'
        ];
    }

    $officialDesig = trim($regOfficer['designation'] ?? '');
    if (strcasecmp($officialDesig, $cleanRole) !== 0) {
        return [
            'valid'   => false,
            'message' => $isBn
                ? 'পদবি মিলছে না: এই অফিসার আইডি "' . $officialDesig . '" হিসেবে নিবন্ধিত, "' . $cleanRole . '" হিসেবে নয়।'
                : 'Designation mismatch: this Officer ID is registered as "' . $officialDesig . '", not "' . $cleanRole . '".'
        ];
    }

    $officialName = strtolower(trim($regOfficer['full_name'] ?? ''));
    if ($officialName !== '' && $officialName !== $cleanName) {
        return [
            'valid'   => false,
            'message' => $isBn
                ? 'কর্মকর্তার নাম সরকারি রেজিস্ট্রির তথ্যের সাথে মিলছে না।'
                : 'Officer name does not match the official registry record.'
        ];
    }

    $addrParts  = parseAddressParts($rawAddr);
    $upazilaIn  = strtolower(trim($addrParts['upazila'] !== '' ? $addrParts['upazila'] : $addrParts['village']));
    $districtIn = strtolower(trim($addrParts['district'] !== '' ? $addrParts['district'] : $rawAddr));
    $regUpz     = strtolower(trim($regOfficer['upazila'] ?? ''));
    $regDist    = strtolower(trim($regOfficer['district'] ?? ''));

    if ($upazilaIn !== '' && $regUpz !== '' && $upazilaIn !== $regUpz) {
        return [
            'valid'   => false,
            'message' => $isBn
                ? 'উপজেলা এই অফিসার আইডির নির্ধারিত কর্মস্থলের সাথে মিলছে না।'
                : 'Upazila does not match the official assignment for this Officer ID.'
        ];
    }

    if ($districtIn !== '' && $regDist !== '' && $districtIn !== $regDist) {
        return [
            'valid'   => false,
            'message' => $isBn
                ? 'জেলা এই অফিসার আইডির নির্ধারিত কর্মস্থলের সাথে মিলছে না।'
                : 'District does not match the official assignment for this Officer ID.'
        ];
    }

    $stmtDup = $conn->prepare("SELECT id FROM admins WHERE email = ? OR employee_id = ? LIMIT 1");
    if ($stmtDup) {
        $stmtDup->bind_param("ss", $cleanEmail, $cleanCode);
        $stmtDup->execute();
        $dupAdmin = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();

        if ($dupAdmin) {
            return [
                'valid'   => false,
                'message' => $isBn
                    ? 'এই ইমেইল বা এমপ্লয়ি আইডি দিয়ে একটি অ্যাডমিন অ্যাকাউন্ট ইতিমধ্যে বিদ্যমান।'
                    : 'An admin account with this Email or Employee ID already exists.'
            ];
        }
    }

    return [
        'valid'         => true,
        'registry_data' => $regOfficer
    ];
}

function verifyMillAgainstRegistry(mysqli $conn, string $name, string $nid, string $phone, string $millId, string $rawAddr, bool $isBn = false): array
{
    $addrParts = parseAddressParts($rawAddr);
    $village   = strtolower(trim($addrParts['village']));
    $upazila   = strtolower(trim($addrParts['upazila']));
    $district  = strtolower(trim($addrParts['district']));
    $normPhone = normalizePhoneDigits($phone);
    $cleanId   = trim($millId);
    $cleanNid  = trim($nid);
    $cleanName = strtolower(trim($name));

    $incorrectMsg = $isBn
        ? 'প্রদত্ত তথ্য সঠিক নয়। অনুগ্রহ করে সঠিক তথ্য প্রদান করুন।'
        : 'The provided information is incorrect. Please enter the correct information.';

    if ($cleanId === '' || $cleanNid === '' || $normPhone === '' || $cleanName === '' || $upazila === '' || $district === '') {
        return [
            'valid'   => false,
            'message' => $incorrectMsg
        ];
    }

    $stmt = $conn->prepare("SELECT * FROM register_mill WHERE mill_ID = ? LIMIT 1");
    if (!$stmt) {
        return [
            'valid'   => false,
            'message' => $incorrectMsg
        ];
    }
    $stmt->bind_param("s", $cleanId);
    $stmt->execute();
    $regMill = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$regMill) {
        return [
            'valid'   => false,
            'message' => $incorrectMsg
        ];
    }

    $regStatus   = strtolower(trim($regMill['stsats'] ?? 'active'));
    $regOwner    = strtolower(trim($regMill['mill_owner'] ?? ''));
    $regNid      = trim($regMill['nid'] ?? '');
    $regPhone    = normalizePhoneDigits($regMill['phone'] ?? '');
    $regVillage  = strtolower(trim($regMill['village'] ?? ''));
    $regUpazila  = strtolower(trim($regMill['upazila'] ?? ''));
    $regDistrict = strtolower(trim($regMill['district'] ?? ''));

    $villageMatches = ($regVillage === '' || $village === '' || $regVillage === $village);

    if (
        $regStatus !== 'active' ||
        $regOwner !== $cleanName ||
        $regNid !== $cleanNid ||
        $regPhone !== $normPhone ||
        !$villageMatches ||
        $regUpazila !== $upazila ||
        $regDistrict !== $district
    ) {
        return [
            'valid'   => false,
            'message' => $incorrectMsg
        ];
    }

    $stmtDup = $conn->prepare("SELECT id FROM mill_owners WHERE mill_id = ? OR nid = ? OR phone = ? LIMIT 1");
    if ($stmtDup) {
        $stmtDup->bind_param("sss", $cleanId, $cleanNid, $normPhone);
        $stmtDup->execute();
        $existing = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();

        if ($existing) {
            return [
                'valid'   => false,
                'message' => $isBn
                    ? 'একজন মিল মালিক একাধিক মিল অ্যাকাউন্ট নিবন্ধন করতে পারবেন না।'
                    : 'A mill owner cannot register more than one mill account.'
            ];
        }
    }

    return [
        'valid'         => true,
        'registry_data' => $regMill
    ];
}

// AJAX verification endpoint when Mill Owner clicks "Next"
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'verify_mill_registry') {
    header('Content-Type: application/json; charset=UTF-8');

    $conn = @new mysqli("sql209.infinityfree.com", "if0_43517157", "Sadekul3055", "if0_43517157_farmers_market");
    if ($conn->connect_error) {
        echo json_encode(['valid' => false, 'message' => $is_bn ? 'ডাটাবেস সংযোগ ব্যর্থ হয়েছে।' : 'Database connection failed.']);
        exit();
    }

    if (!isset($_SESSION['mill_verify_attempts'])) {
        $_SESSION['mill_verify_attempts'] = 0;
    }

    $name    = trim($_POST['millOwnerName'] ?? '');
    $nid     = trim($_POST['millNID'] ?? '');
    $phone   = trim($_POST['millPhone'] ?? '');
    $millId  = trim($_POST['millID'] ?? '');
    $rawAddr = trim($_POST['millAddress'] ?? '');

    $check = verifyMillAgainstRegistry($conn, $name, $nid, $phone, $millId, $rawAddr, $is_bn);
    $conn->close();

    if ($check['valid']) {
        $_SESSION['mill_verify_attempts'] = 0;
        echo json_encode([
            'valid'    => true,
            'attempts' => 0,
            'redirect' => ''
        ]);
        exit();
    }

    $_SESSION['mill_verify_attempts']++;
    $attempts = (int)$_SESSION['mill_verify_attempts'];
    $redirect = '';

    if ($attempts >= 3) {
        $_SESSION['mill_verify_attempts'] = 0;
        $redirect = 'homePage.php';
    }

    echo json_encode([
        'valid'    => false,
        'message'  => $check['message'],
        'attempts' => $attempts,
        'redirect' => $redirect
    ]);
    exit();
}

// AJAX verification endpoint when Admin clicks "Next"
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'verify_admin_registry') {
    header('Content-Type: application/json; charset=UTF-8');

    $conn = @new mysqli("sql209.infinityfree.com", "if0_43517157", "Sadekul3055", "if0_43517157_farmers_market");
    if ($conn->connect_error) {
        echo json_encode(['valid' => false, 'message' => $is_bn ? 'ডাটাবেস সংযোগ ব্যর্থ হয়েছে।' : 'Database connection failed.']);
        exit();
    }

    ensureAdminRegistrySchema($conn);

    $name        = trim($_POST['adminName'] ?? '');
    $email       = trim($_POST['adminIdEmail'] ?? '');
    $employeeId  = trim($_POST['adminIdEmployee'] ?? '');
    $designation = trim($_POST['adminDesignation'] ?? '');
    $rawAddr     = trim($_POST['adminAddress'] ?? '');

    $check = verifyAdminAgainstRegistry($conn, $name, $email, $employeeId, $designation, $rawAddr, $is_bn);
    $conn->close();

    echo json_encode([
        'valid'   => $check['valid'],
        'message' => $check['message'] ?? ''
    ]);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli("sql209.infinityfree.com", "if0_43517157", "Sadekul3055", "if0_43517157_farmers_market");
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    ensureAdminRegistrySchema($conn);

    $role = $_POST['role_type'] ?? '';
    $rawPassword = $_POST['password'] ?? '';

    if (strlen($rawPassword) < 8) {
        $passLenErr = json_encode($is_bn ? 'পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।' : 'Password must be at least 8 characters long.');
        echo "<script>alert($passLenErr); window.history.back();</script>";
        $conn->close();
        exit();
    }

    $password = password_hash($rawPassword, PASSWORD_DEFAULT);

    $stmt = null;
    $redirect = 'homePage.php';
    $msg = $is_bn ? 'নিবন্ধন সফল হয়েছে!' : 'Registration Successful!';
    $registeredOfficerCode = '';

    if ($role === 'farmer') {
        $name       = trim($_POST['farmerName'] ?? '');
        $nid        = trim($_POST['farmerNID'] ?? '');
        $phone      = trim($_POST['farmerPhone'] ?? '');
        $rawAddr    = trim($_POST['farmerAddress'] ?? '');
        $addrParts  = parseAddressParts($rawAddr);
        $village    = $addrParts['village'];
        $upazila    = $addrParts['upazila'];
        $district   = $addrParts['district'];

        $stmt = $conn->prepare("INSERT INTO farmers (name, nid, phone, village, upazila, district, password) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $name, $nid, $phone, $village, $upazila, $district, $password);

        $redirect = 'farmerProfile.php';
        $msg = $is_bn ? 'কৃষক নিবন্ধন সফল হয়েছে!' : 'Farmer Registration Successful!';
    } elseif ($role === 'mill_owner') {
        $name       = trim($_POST['millOwnerName'] ?? '');
        $nid        = trim($_POST['millNID'] ?? '');
        $phone      = normalizePhoneDigits(trim($_POST['millPhone'] ?? ''));
        $mill_id    = trim($_POST['millID'] ?? '');
        $rawAddr    = trim($_POST['millAddress'] ?? '');
        $crop_types = trim($_POST['cropTypeString'] ?? '');

        $check = verifyMillAgainstRegistry($conn, $name, $nid, $phone, $mill_id, $rawAddr, $is_bn);
        if (!$check['valid']) {
            $safeErr = json_encode($check['message']);
            echo "<script>alert($safeErr); window.location.href='register.php';</script>";
            $conn->close();
            exit();
        }

        $regMill   = $check['registry_data'];
        $mill_name = trim($regMill['mill_name'] ?? '');
        $village   = trim($regMill['village'] ?? '');
        $upazila   = trim($regMill['upazila'] ?? '');
        $district  = trim($regMill['district'] ?? '');

        $stmt = $conn->prepare("INSERT INTO mill_owners (name, mill_name, nid, phone, mill_id, village, upazila, district, crop_types, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssss", $name, $mill_name, $nid, $phone, $mill_id, $village, $upazila, $district, $crop_types, $password);

        $redirect = 'MillProfile.php';
        $msg = $is_bn ? 'মিল মালিক নিবন্ধন সফল হয়েছে!' : 'Mill Owner Registration Successful!';
    } elseif ($role === 'admin') {
        $name        = trim($_POST['adminName'] ?? '');
        $phone       = trim($_POST['adminPhone'] ?? '');
        $email       = trim($_POST['adminIdEmail'] ?? '');
        $employee_id = strtoupper(trim($_POST['adminIdEmployee'] ?? ''));
        $designation = trim($_POST['adminDesignation'] ?? '');
        $rawAddr     = trim($_POST['adminAddress'] ?? '');

        $check = verifyAdminAgainstRegistry($conn, $name, $email, $employee_id, $designation, $rawAddr, $is_bn);
        if (!$check['valid']) {
            $safeErr = json_encode($check['message']);
            echo "<script>alert($safeErr); window.location.href='register.php';</script>";
            $conn->close();
            exit();
        }

        $regOfficer            = $check['registry_data'];
        $officialDesignation   = trim($regOfficer['designation'] ?? $designation);
        $upazila               = trim($regOfficer['upazila'] ?? '');
        $district              = trim($regOfficer['district'] ?? '');
        $registeredOfficerCode = trim($regOfficer['officer_code'] ?? $employee_id);

        $stmt = $conn->prepare("INSERT INTO admins (name, phone, email, employee_id, designation, upazila, district, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssss", $name, $phone, $email, $registeredOfficerCode, $officialDesignation, $upazila, $district, $password);

        $redirect = 'adminPage.php';
        $msg = $is_bn ? 'অ্যাডমিন নিবন্ধন সফল হয়েছে!' : 'Admin Registration Successful!';
    }

    if ($stmt) {
        if ($stmt->execute()) {
            $_SESSION['user_id'] = $stmt->insert_id;
            $_SESSION['role'] = $role;

            if ($role === 'admin' && $registeredOfficerCode !== '') {
                $stmtMark = $conn->prepare("UPDATE authorized_officers SET is_registered = 1 WHERE officer_code = ?");
                if ($stmtMark) {
                    $stmtMark->bind_param("s", $registeredOfficerCode);
                    $stmtMark->execute();
                    $stmtMark->close();
                }
            }

            $safeMsg = json_encode($msg);
            $safeUrl = json_encode($redirect);
            echo "<script>alert($safeMsg); window.location.href=$safeUrl;</script>";
        } else {
            echo "Error: " . htmlspecialchars($stmt->error);
        }
        $stmt->close();
    }

    $conn->close();
    exit();
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(__t('site_name')); ?> - <?php echo $is_bn ? 'নিবন্ধন' : 'Registration'; ?></title>
    <link rel="stylesheet" href="registerStyle.css">
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

    <header class="reg-header">
        <div class="navbar-container">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                <h1 class="site-title" style="margin:0;"><?php echo htmlspecialchars(__t('site_name')); ?></h1>
                <div class="lang-switch-group">
                    <a href="<?php echo lang_url('bn'); ?>" class="lang-switch-btn <?php echo $is_bn ? 'active' : ''; ?>">বাংলা</a>
                    <a href="<?php echo lang_url('en'); ?>" class="lang-switch-btn <?php echo !$is_bn ? 'active' : ''; ?>">English</a>
                </div>
            </div>
            <p class="site-description">
                <?php echo $is_bn
                    ? 'কৃষক, মিল মালিক এবং ক্রেতাদের মধ্যে সরাসরি সংযোগ স্থাপন ও সহজে কৃষিপণ্য কেনাবেচার জন্য একটি নির্ভরযোগ্য ডিজিটাল প্ল্যাটফর্ম। আজই আপনার অ্যাকাউন্ট তৈরি করুন এবং আমাদের সেবা ব্যবহার করুন।'
                    : 'A trusted digital platform for establishing direct connections between farmers, mill owners, and buyers to easily trade agricultural products. Create your account and access our services today.'; ?>
            </p>
        </div>
    </header>

    <div class="container">

        <div id="roleSelectView" class="role-selection-view">
            <h2><?php echo $is_bn ? 'নিবন্ধন' : 'Registration'; ?></h2>
            <p style="margin-bottom: 20px;"><?php echo $is_bn ? 'আপনার অ্যাকাউন্টের ধরন নির্বাচন করুন:' : 'Select your account type:'; ?></p>

            <button type="button" class="role-btn" onclick="showForm('farmer')"><?php echo $is_bn ? 'কৃষক' : 'Farmer'; ?></button>
            <button type="button" class="role-btn" onclick="showForm('mill_owner')"><?php echo $is_bn ? 'মিল মালিক' : 'Mill Owner'; ?></button>
            <button type="button" class="role-btn" onclick="showForm('admin')"><?php echo $is_bn ? 'অ্যাডমিন' : 'Admin'; ?></button>

            <div class="login-link">
                <?php echo $is_bn ? 'ইতিমধ্যে অ্যাকাউন্ট আছে?' : 'Already have an account?'; ?> <a href="homePage.php?login=true"><?php echo $is_bn ? 'লগইন' : 'Login'; ?></a>
            </div>
        </div>

        <form id="farmerForm" class="hidden" onsubmit="goToStep2(event, 'farmer')">
            <h2><?php echo $is_bn ? 'কৃষক নিবন্ধন' : 'Farmer Registration'; ?></h2>
            <div class="form-group">
                <label><?php echo $is_bn ? 'নাম:' : 'Name:'; ?></label>
                <input type="text" id="farmerName" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'এনআইডি কার্ড নম্বর:' : 'NID Card Number:'; ?></label>
                <input type="text" id="farmerNID" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'ফোন নম্বর:' : 'Phone Number:'; ?></label>
                <input type="tel" id="farmerPhone" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'ঠিকানা (গ্রাম, উপজেলা, জেলা):' : 'Address (Village, Upazila, District):'; ?></label>
                <input type="text" id="farmerAddress" placeholder="e.g., Shyampur, Sherpur, Bogura" required>
            </div>
            <button type="submit"><?php echo $is_bn ? 'পরবর্তী' : 'Next'; ?></button>
            <button type="button" class="back-btn" onclick="showRoleSelection()"><?php echo $is_bn ? 'ফিরে যান' : 'Back'; ?></button>
        </form>

        <form id="millForm" class="hidden" onsubmit="goToStep2(event, 'mill_owner')">
            <h2><?php echo $is_bn ? 'মিল মালিক নিবন্ধন' : 'Mill Owner Registration'; ?></h2>
            <div class="form-group">
                <label><?php echo $is_bn ? 'নাম:' : 'Name:'; ?></label>
                <input type="text" id="millOwnerName" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'এনআইডি নম্বর:' : 'NID Number:'; ?></label>
                <input type="text" id="millNID" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'ফোন নম্বর:' : 'Phone Number:'; ?></label>
                <input type="tel" id="millPhone" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'মিল আইডি নম্বর:' : 'Mill ID Number:'; ?></label>
                <input type="text" id="millID" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'ঠিকানা (গ্রাম, উপজেলা, জেলা):' : 'Address (Village, Upazila, District):'; ?></label>
                <input type="text" id="millAddress" placeholder="e.g., Shyampur, Sherpur, Bogura" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'ফসলের ধরন:' : 'Type of crops:'; ?></label>
                <input type="checkbox" name="cropType" value="Rice"> <?php echo $is_bn ? 'রাইস মিল' : 'Rice Mill'; ?> <br>
                <input type="checkbox" name="cropType" value="Wheat"> <?php echo $is_bn ? 'গম মিল' : 'Wheat Mill'; ?> <br>
                <input type="checkbox" name="cropType" value="Corn"> <?php echo $is_bn ? 'ভুট্টা মিল' : 'Corn Mill'; ?> <br>
                <input type="checkbox" name="cropType" value="Lentils"> <?php echo $is_bn ? 'ডাল ও অন্যান্য মিল' : 'Lentils &amp; Others Mill'; ?>
            </div>
            <small id="millVerifyError" style="color: #ff6b6b; display: none; font-weight: bold; margin-bottom: 10px;"></small>
            <button type="submit" id="millNextBtn"><?php echo $is_bn ? 'পরবর্তী' : 'Next'; ?></button>
            <button type="button" class="back-btn" onclick="showRoleSelection()"><?php echo $is_bn ? 'ফিরে যান' : 'Back'; ?></button>
        </form>

        <form id="adminForm" class="hidden" onsubmit="goToStep2(event, 'admin')">
            <h2><?php echo $is_bn ? 'অ্যাডমিন নিবন্ধন' : 'Admin Registration'; ?></h2>
            <div class="form-group">
                <label><?php echo $is_bn ? 'নাম:' : 'Name:'; ?></label>
                <input type="text" id="adminName" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'ফোন নম্বর:' : 'Phone Number:'; ?></label>
                <input type="tel" id="adminPhone" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'ইমেইল আইডি:' : 'Email ID:'; ?></label>
                <input type="email" id="adminIdEmail" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'এমপ্লয়ি / অফিসার আইডি:' : 'Employee / Officer ID:'; ?></label>
                <input type="text" id="adminIdEmployee" placeholder="e.g., OFF-AGRI-102" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'দাপ্তরিক পদবি:' : 'Official Designation:'; ?></label>
                <input type="text" id="adminDesignation" placeholder="e.g., Upazila Agriculture Officer" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'ঠিকানা (উপজেলা, জেলা):' : 'Address (Upazila, District):'; ?></label>
                <input type="text" id="adminAddress" placeholder="e.g., Sherpur, Bogura" required>
            </div>
            <small id="adminVerifyError" style="color: #ff6b6b; display: none; font-weight: bold; margin-bottom: 10px;"></small>
            <button type="submit" id="adminNextBtn"><?php echo $is_bn ? 'পরবর্তী' : 'Next'; ?></button>
            <button type="button" class="back-btn" onclick="showRoleSelection()"><?php echo $is_bn ? 'ফিরে যান' : 'Back'; ?></button>
        </form>

        <form id="step2" class="hidden" action="" method="POST" onsubmit="return validatePassword(event)">
            <h2><?php echo $is_bn ? 'পাসওয়ার্ড নির্ধারণ করুন' : 'Set Password'; ?></h2>
            <input type="hidden" name="role_type" id="h_role_type">

            <input type="hidden" name="farmerName" id="h_farmerName">
            <input type="hidden" name="farmerNID" id="h_farmerNID">
            <input type="hidden" name="farmerPhone" id="h_farmerPhone">
            <input type="hidden" name="farmerAddress" id="h_farmerAddress">

            <input type="hidden" name="millOwnerName" id="h_millOwnerName">
            <input type="hidden" name="millNID" id="h_millNID">
            <input type="hidden" name="millPhone" id="h_millPhone">
            <input type="hidden" name="millID" id="h_millID">
            <input type="hidden" name="millAddress" id="h_millAddress">
            <input type="hidden" name="cropTypeString" id="h_cropTypeString">

            <input type="hidden" name="adminName" id="h_adminName">
            <input type="hidden" name="adminPhone" id="h_adminPhone">
            <input type="hidden" name="adminIdEmail" id="h_adminIdEmail">
            <input type="hidden" name="adminIdEmployee" id="h_adminIdEmployee">
            <input type="hidden" name="adminDesignation" id="h_adminDesignation">
            <input type="hidden" name="adminAddress" id="h_adminAddress">

            <div class="form-group">
                <label id="usernameLabel"><?php echo $is_bn ? 'ইউজারনেম:' : 'Username:'; ?></label>
                <input type="text" id="regUser" readonly style="background: #eee; color: #333;">
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'পাসওয়ার্ড:' : 'Password:'; ?></label>
                <input type="password" id="password" name="password" minlength="8" required>
            </div>
            <div class="form-group">
                <label><?php echo $is_bn ? 'পাসওয়ার্ড নিশ্চিত করুন:' : 'Confirm Password:'; ?></label>
                <input type="password" id="confirmPassword" minlength="8" required>
                <small id="errorMsg" style="color: #ff6b6b; display: none; font-weight: bold; margin-top: 5px;"></small>
            </div>
            <button type="submit"><?php echo $is_bn ? 'জমা দিন' : 'Submit'; ?></button>
            <button type="button" class="back-btn" onclick="goToStep1()"><?php echo $is_bn ? 'ফিরে যান' : 'Back'; ?></button>
        </form>
    </div>

    <script>
        const isBnLang = <?php echo json_encode($is_bn); ?>;
        let activeRole = '';
        let millFailedAttempts = 0;

        function showForm(role) {
            activeRole = role;
            document.getElementById('roleSelectView').classList.add('hidden');

            if (role === 'farmer') {
                document.getElementById('farmerForm').classList.remove('hidden');
            } else if (role === 'mill_owner') {
                const errBox = document.getElementById('millVerifyError');
                if (errBox) errBox.style.display = 'none';
                document.getElementById('millForm').classList.remove('hidden');
            } else if (role === 'admin') {
                const adminErr = document.getElementById('adminVerifyError');
                if (adminErr) adminErr.style.display = 'none';
                document.getElementById('adminForm').classList.remove('hidden');
            }
        }

        function showRoleSelection() {
            document.getElementById('farmerForm').classList.add('hidden');
            document.getElementById('millForm').classList.add('hidden');
            document.getElementById('adminForm').classList.add('hidden');
            document.getElementById('step2').classList.add('hidden');
            document.getElementById('roleSelectView').classList.remove('hidden');
        }

        async function goToStep2(event, role) {
            event.preventDefault();
            document.getElementById('h_role_type').value = role;
            const regUser = document.getElementById('regUser');

            if (role === 'farmer') {
                document.getElementById('h_farmerName').value = document.getElementById('farmerName').value.trim();
                document.getElementById('h_farmerNID').value = document.getElementById('farmerNID').value.trim();
                document.getElementById('h_farmerPhone').value = document.getElementById('farmerPhone').value.trim();
                document.getElementById('h_farmerAddress').value = document.getElementById('farmerAddress').value.trim();

                regUser.value = document.getElementById('farmerPhone').value.trim();
                document.getElementById('usernameLabel').innerText = isBnLang ? "ইউজারনেম (ফোন নম্বর):" : "Username (Phone Number):";
                document.getElementById('farmerForm').classList.add('hidden');
                document.getElementById('step2').classList.remove('hidden');
            } else if (role === 'mill_owner') {
                const ownerName = document.getElementById('millOwnerName').value.trim();
                const ownerNid  = document.getElementById('millNID').value.trim();
                const phone     = document.getElementById('millPhone').value.trim();
                const millId    = document.getElementById('millID').value.trim();
                const address   = document.getElementById('millAddress').value.trim();
                const errBox    = document.getElementById('millVerifyError');
                const nextBtn   = document.getElementById('millNextBtn');

                nextBtn.disabled = true;
                errBox.style.display = 'none';

                const formData = new FormData();
                formData.append('action', 'verify_mill_registry');
                formData.append('millOwnerName', ownerName);
                formData.append('millNID', ownerNid);
                formData.append('millPhone', phone);
                formData.append('millID', millId);
                formData.append('millAddress', address);

                try {
                    const response = await fetch('register.php', {
                        method: 'POST',
                        body: formData,
                        cache: 'no-store'
                    });
                    const result = await response.json();

                    if (!result.valid) {
                        millFailedAttempts = Math.max(
                            millFailedAttempts + 1,
                            result.attempts ? Number(result.attempts) : 0
                        );
                        const messageText = result.message || (isBnLang
                            ? 'প্রদত্ত তথ্য সঠিক নয়। অনুগ্রহ করে সঠিক তথ্য প্রদান করুন।'
                            : 'The provided information is incorrect. Please enter the correct information.');

                        errBox.innerText = messageText;
                        errBox.style.display = 'block';
                        alert(messageText);

                        if (millFailedAttempts >= 3 || result.redirect) {
                            window.location.replace(result.redirect || 'homePage.php');
                            return;
                        }
                        nextBtn.disabled = false;
                        return;
                    }

                    millFailedAttempts = 0;
                    errBox.style.display = 'none';
                    nextBtn.disabled = false;

                    document.getElementById('h_millOwnerName').value = ownerName;
                    document.getElementById('h_millNID').value = ownerNid;
                    document.getElementById('h_millPhone').value = phone;
                    document.getElementById('h_millID').value = millId;
                    document.getElementById('h_millAddress').value = address;

                    const checkboxes = document.querySelectorAll('input[name="cropType"]:checked');
                    document.getElementById('h_cropTypeString').value = Array.from(checkboxes).map(cb => cb.value).join(', ');

                    regUser.value = millId;
                    document.getElementById('usernameLabel').innerText = isBnLang ? "ইউজারনেম (মিল আইডি):" : "Username (Mill ID):";
                    document.getElementById('millForm').classList.add('hidden');
                    document.getElementById('step2').classList.remove('hidden');
                } catch (err) {
                    millFailedAttempts += 1;
                    nextBtn.disabled = false;
                    const fallbackMsg = isBnLang
                        ? 'প্রদত্ত তথ্য সঠিক নয়। অনুগ্রহ করে সঠিক তথ্য প্রদান করুন।'
                        : 'The provided information is incorrect. Please enter the correct information.';
                    errBox.innerText = fallbackMsg;
                    errBox.style.display = 'block';
                    alert(fallbackMsg);

                    if (millFailedAttempts >= 3) {
                        window.location.replace('homePage.php');
                        return;
                    }
                }
            } else if (role === 'admin') {
                const adminName        = document.getElementById('adminName').value.trim();
                const adminPhone       = document.getElementById('adminPhone').value.trim();
                const adminEmail       = document.getElementById('adminIdEmail').value.trim();
                const adminEmployeeId  = document.getElementById('adminIdEmployee').value.trim();
                const adminDesignation = document.getElementById('adminDesignation').value.trim();
                const adminAddress     = document.getElementById('adminAddress').value.trim();
                const adminErrBox      = document.getElementById('adminVerifyError');
                const adminNextBtn     = document.getElementById('adminNextBtn');

                adminNextBtn.disabled = true;
                adminErrBox.style.display = 'none';

                const formData = new FormData();
                formData.append('action', 'verify_admin_registry');
                formData.append('adminName', adminName);
                formData.append('adminIdEmail', adminEmail);
                formData.append('adminIdEmployee', adminEmployeeId);
                formData.append('adminDesignation', adminDesignation);
                formData.append('adminAddress', adminAddress);

                try {
                    const response = await fetch('register.php', {
                        method: 'POST',
                        body: formData,
                        cache: 'no-store'
                    });
                    const result = await response.json();

                    if (!result.valid) {
                        const errText = result.message || (isBnLang ? 'কর্মকর্তা যাচাইকরণ ব্যর্থ হয়েছে।' : 'Officer verification failed.');
                        adminErrBox.innerText = errText;
                        adminErrBox.style.display = 'block';
                        alert(errText);
                        adminNextBtn.disabled = false;
                        return;
                    }

                    adminErrBox.style.display = 'none';
                    adminNextBtn.disabled = false;

                    document.getElementById('h_adminName').value = adminName;
                    document.getElementById('h_adminPhone').value = adminPhone;
                    document.getElementById('h_adminIdEmail').value = adminEmail;
                    document.getElementById('h_adminIdEmployee').value = adminEmployeeId;
                    document.getElementById('h_adminDesignation').value = adminDesignation;
                    document.getElementById('h_adminAddress').value = adminAddress;

                    regUser.value = adminEmail;
                    document.getElementById('usernameLabel').innerText = isBnLang ? "ইউজারনেম (ইমেইল আইডি):" : "Username (Email ID):";
                    document.getElementById('adminForm').classList.add('hidden');
                    document.getElementById('step2').classList.remove('hidden');
                } catch (err) {
                    adminNextBtn.disabled = false;
                    const errText = isBnLang ? 'কর্মকর্তার তথ্য যাচাই করা যায়নি। অনুগ্রহ করে আবার চেষ্টা করুন।' : 'Could not verify officer record. Please try again.';
                    adminErrBox.innerText = errText;
                    adminErrBox.style.display = 'block';
                    alert(errText);
                }
            }
        }

        function goToStep1() {
            document.getElementById('password').value = '';
            document.getElementById('confirmPassword').value = '';
            document.getElementById('errorMsg').style.display = 'none';

            document.getElementById('step2').classList.add('hidden');
            showForm(activeRole);
        }

        function validatePassword(event) {
            const pass = document.getElementById('password').value;
            const confirmPass = document.getElementById('confirmPassword').value;
            const errorMsg = document.getElementById('errorMsg');

            if (pass.length < 8) {
                errorMsg.innerText = isBnLang ? 'পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে!' : 'Password must be at least 8 characters long!';
                errorMsg.style.display = 'block';
                event.preventDefault();
                return false;
            }

            if (pass !== confirmPass) {
                errorMsg.innerText = isBnLang ? 'পাসওয়ার্ড দুটি মেলেনি!' : 'Passwords do not match!';
                errorMsg.style.display = 'block';
                event.preventDefault();
                return false;
            }

            errorMsg.style.display = 'none';
            return true;
        }
    </script>
</body>
</html>
