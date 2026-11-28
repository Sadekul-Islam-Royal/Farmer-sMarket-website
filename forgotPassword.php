<?php
/**
 * Repository: farmers-market-portal
 * File: forgotPassword.php
 * Module: Password Recovery with 5-Digit Verification Code (Email/SMS) & 89s Expiry
 * Description: Verifies role-specific credentials, generates a 5-digit OTP valid for
 *              89 seconds, sends via Email if available or SMS to Phone if no Email exists,
 *              informs the user of the delivery method, supports Resend after expiry,
 *              and updates the password upon code verification.
 */
session_start();
require_once 'lang.php';

$host = "sql209.infinityfree.com";
$db_user = "if0_43517157";
$db_password = "Sadekul3055";
$db_name = "if0_43517157_farmers_market";

$conn = new mysqli($host, $db_user, $db_password, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$is_bn = ($current_lang === 'bn');

/**
 * Helper: Send 5-digit verification code via Email (if email exists) or SMS (if no email)
 */
function dispatchVerificationCode(string $email, string $phone, string $code, string $userName, bool $isBn = false): array
{
    $cleanEmail = trim($email);
    $cleanPhone = trim($phone);

    if ($cleanEmail !== '' && filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
        // Send via Email
        $subject = "Farmer's Market - Password Reset Verification Code";
        $body    = "Hello " . $userName . ",\n\n"
                 . "Your 5-digit password reset verification code is: " . $code . "\n"
                 . "This code will expire in 89 seconds.\n\n"
                 . "Regards,\nFarmer's Market Portal";
        $headers = "From: no-reply@farmersmarket.gov.bd\r\nContent-Type: text/plain; charset=UTF-8\r\n";

        @mail($cleanEmail, $subject, $body, $headers);

        return [
            'channel' => 'Email',
            'target'  => $cleanEmail,
            'notice'  => $isBn
                ? $cleanEmail . " ইমেইলে ৫ সংখ্যার ভেরিফিকেশন কোড পাঠানো হয়েছে।"
                : "A 5-digit verification code has been sent via Email to " . $cleanEmail . "."
        ];
    } else {
        // Send via SMS (Integrate your local SMS Gateway API URL/Key here if needed)
        $smsText = "Farmer's Market: Your 5-digit verification code is " . $code . ". Valid for 89 seconds.";
        // Example SMS Gateway hook:
        // @file_get_contents("https://sms-gateway.example.com/api/send?to=" . urlencode($cleanPhone) . "&msg=" . urlencode($smsText));

        return [
            'channel' => 'SMS',
            'target'  => $cleanPhone,
            'notice'  => $isBn
                ? "এই অ্যাকাউন্টে কোনো ইমেইল যুক্ত নেই। আপনার ফোন নম্বর (" . $cleanPhone . ")-এ SMS-এর মাধ্যমে ৫ সংখ্যার ভেরিফিকেশন কোড পাঠানো হয়েছে।"
                : "No email address is linked to this account. A 5-digit verification code has been sent via SMS to your phone number (" . $cleanPhone . ")."
        ];
    }
}

$step = "verify"; // 'verify' | 'otp' | 'reset' | 'done'
$selected_role = $_POST['user_role'] ?? 'farmer';
$error_msg = "";
$success_msg = "";

// STEP 1: Verify Account Credentials & Send 5-Digit Code
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verify_account'])) {
    $selected_role = trim($_POST['user_role'] ?? 'farmer');
    $login_username = trim($_POST['login_username'] ?? '');
    $unique_identifier = trim($_POST['unique_identifier'] ?? '');

    if ($login_username === '' || $unique_identifier === '') {
        $error_msg = $is_bn ? "অনুগ্রহ করে প্রয়োজনীয় উভয় ঘর পূরণ করুন।" : "Please fill in both required fields.";
    } else {
        $found_user = null;

        if ($selected_role === "farmer") {
            // Farmers table has phone & nid (no email column)
            $stmt = $conn->prepare("SELECT id, name, phone FROM farmers WHERE phone = ? AND nid = ? LIMIT 1");
            $stmt->bind_param("ss", $login_username, $unique_identifier);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $found_user = [
                    'id'    => intval($row['id']),
                    'role'  => 'farmer',
                    'name'  => $row['name'] ?? 'Farmer',
                    'email' => '', // Farmer has no email -> will send via SMS
                    'phone' => $row['phone'] ?? ''
                ];
            } else {
                $error_msg = $is_bn ? "এই ফোন নম্বর ও এনআইডি নম্বরের সাথে কোনো কৃষক অ্যাকাউন্ট মেলেনি।" : "No Farmer account matched this Phone Number and NID Number.";
            }
        } elseif ($selected_role === "mill_owner") {
            // Mill Owners table has mill_id, email, and phone
            $stmt = $conn->prepare("SELECT id, name, mill_name, email, phone FROM mill_owners WHERE mill_id = ? AND (email = ? OR phone = ?) LIMIT 1");
            $stmt->bind_param("sss", $login_username, $unique_identifier, $unique_identifier);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $found_user = [
                    'id'    => intval($row['id']),
                    'role'  => 'mill_owner',
                    'name'  => ($row['mill_name'] ?? 'Mill') . " (" . ($row['name'] ?? 'Owner') . ")",
                    'email' => $row['email'] ?? '',
                    'phone' => $row['phone'] ?? ''
                ];
            } else {
                $error_msg = $is_bn ? "এই মিল আইডি ও ইমেইল/ফোন নম্বরের সাথে কোনো মিল মালিক অ্যাকাউন্ট মেলেনি।" : "No Mill Owner account matched this Mill ID and Email/Phone.";
            }
        } elseif ($selected_role === "admin") {
            // Admins table has email, employee_id, and phone
            $stmt = $conn->prepare("SELECT id, name, email, phone FROM admins WHERE email = ? AND employee_id = ? LIMIT 1");
            $stmt->bind_param("ss", $login_username, $unique_identifier);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $found_user = [
                    'id'    => intval($row['id']),
                    'role'  => 'admin',
                    'name'  => $row['name'] ?? 'Admin',
                    'email' => $row['email'] ?? '',
                    'phone' => $row['phone'] ?? ''
                ];
            } else {
                $error_msg = $is_bn ? "এই ইমেইল ঠিকানা ও এমপ্লয়ি আইডির সাথে কোনো অ্যাডমিন অ্যাকাউন্ট মেলেনি।" : "No Admin account matched this Email Address and Employee ID.";
            }
        } else {
            $error_msg = $is_bn ? "ভুল ব্যবহারকারীর ধরন নির্বাচন করা হয়েছে।" : "Invalid role selected.";
        }

        if ($found_user !== null) {
            $otp_code = (string)random_int(10000, 99999);
            $expires_at = time() + 89;

            $dispatch = dispatchVerificationCode($found_user['email'], $found_user['phone'], $otp_code, $found_user['name'], $is_bn);

            $_SESSION['pwd_reset'] = [
                'user_id'         => $found_user['id'],
                'role'            => $found_user['role'],
                'name'            => $found_user['name'],
                'email'           => $found_user['email'],
                'phone'           => $found_user['phone'],
                'code'            => $otp_code,
                'expires_at'      => $expires_at,
                'delivery_notice' => $dispatch['notice'],
                'channel'         => $dispatch['channel'],
                'otp_verified'    => false
            ];

            $step = "otp";
        }
    }
}

// STEP 1B: Resend 5-Digit Code (Reset 89-second timer)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['resend_code'])) {
    if (!isset($_SESSION['pwd_reset'])) {
        $error_msg = $is_bn ? "সেশনের মেয়াদ শেষ হয়েছে। অনুগ্রহ করে আবার অ্যাকাউন্ট যাচাই করুন।" : "Session expired. Please verify your account details again.";
        $step = "verify";
    } else {
        $resetData = $_SESSION['pwd_reset'];
        $new_otp_code = (string)random_int(10000, 99999);
        $new_expires_at = time() + 89;

        $dispatch = dispatchVerificationCode($resetData['email'], $resetData['phone'], $new_otp_code, $resetData['name'], $is_bn);

        $_SESSION['pwd_reset']['code'] = $new_otp_code;
        $_SESSION['pwd_reset']['expires_at'] = $new_expires_at;
        $_SESSION['pwd_reset']['delivery_notice'] = $dispatch['notice'];
        $_SESSION['pwd_reset']['channel'] = $dispatch['channel'];
        $_SESSION['pwd_reset']['otp_verified'] = false;

        $success_msg = $is_bn
            ? $dispatch['channel'] . "-এর মাধ্যমে নতুন ৫ সংখ্যার ভেরিফিকেশন কোড পুনরায় পাঠানো হয়েছে।"
            : "A new 5-digit verification code has been resent via " . $dispatch['channel'] . ".";
        $step = "otp";
    }
}

// STEP 2: Verify Submitted 5-Digit Code within 89 Seconds
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verify_otp'])) {
    if (!isset($_SESSION['pwd_reset'])) {
        $error_msg = $is_bn ? "সেশনের মেয়াদ শেষ হয়েছে। অনুগ্রহ করে আবার অ্যাকাউন্ট যাচাই করুন।" : "Session expired. Please verify your account details again.";
        $step = "verify";
    } else {
        $resetData = $_SESSION['pwd_reset'];
        $entered_code = trim($_POST['verification_code'] ?? '');
        $step = "otp";

        if (time() > intval($resetData['expires_at'])) {
            $error_msg = $is_bn ? "ভেরিফিকেশন কোডের মেয়াদ শেষ হয়ে গেছে (৮৯ সেকেন্ড অতিক্রান্ত)। অনুগ্রহ করে কোড পুনরায় পাঠান।" : "Verification code has expired (89 seconds limit exceeded). Please click Resend Code.";
        } elseif ($entered_code === '' || !preg_match('/^\d{5}$/', $entered_code)) {
            $error_msg = $is_bn ? "অনুগ্রহ করে বৈধ ৫ সংখ্যার ভেরিফিকেশন কোড লিখুন।" : "Please enter a valid 5-digit numeric verification code.";
        } elseif ($entered_code !== $resetData['code']) {
            $error_msg = $is_bn ? "ভেরিফিকেশন কোডটি সঠিক নয়। অনুগ্রহ করে আবার চেষ্টা করুন।" : "Incorrect verification code. Please check and try again.";
        } else {
            $_SESSION['pwd_reset']['otp_verified'] = true;
            $step = "reset";
        }
    }
}

// STEP 3: Update New Password after OTP Verification
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['reset_password'])) {
    if (!isset($_SESSION['pwd_reset']) || empty($_SESSION['pwd_reset']['otp_verified'])) {
        $error_msg = $is_bn ? "অনুগ্রহ করে প্রথমে ভেরিফিকেশন সম্পন্ন করুন।" : "Please complete verification first.";
        $step = "verify";
    } else {
        $resetData = $_SESSION['pwd_reset'];
        $verified_role = $resetData['role'];
        $verified_user_id = intval($resetData['user_id']);
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $step = "reset";

        if ($new_password === '' || $confirm_password === '') {
            $error_msg = $is_bn ? "অনুগ্রহ করে নতুন পাসওয়ার্ড লিখুন এবং নিশ্চিত করুন।" : "Please enter and confirm your new password.";
        } elseif (strlen($new_password) < 4) {
            $error_msg = $is_bn ? "নতুন পাসওয়ার্ড কমপক্ষে ৪ অক্ষরের হতে হবে।" : "New password must be at least 4 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error_msg = $is_bn ? "নতুন পাসওয়ার্ড ও নিশ্চিত পাসওয়ার্ড মেলেনি।" : "New password and confirm password do not match.";
        } else {
            if ($verified_role === "farmer") {
                $stmt = $conn->prepare("UPDATE farmers SET password = ? WHERE id = ?");
            } elseif ($verified_role === "mill_owner") {
                $stmt = $conn->prepare("UPDATE mill_owners SET password = ? WHERE id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
            }

            $stmt->bind_param("si", $new_password, $verified_user_id);
            if ($stmt->execute()) {
                unset($_SESSION['pwd_reset']);
                $success_msg = $is_bn ? "পাসওয়ার্ড সফলভাবে আপডেট হয়েছে! এখন আপনি নতুন পাসওয়ার্ড দিয়ে লগইন করতে পারবেন।" : "Password updated successfully! You can now log in with your new password.";
                $step = "done";
            } else {
                $error_msg = ($is_bn ? "পাসওয়ার্ড আপডেট করতে ব্যর্থ হয়েছে: " : "Failed to update password: ") . $stmt->error;
            }
            $stmt->close();
        }
    }
}

$conn->close();

// Calculate remaining seconds for OTP countdown
$remaining_seconds = 0;
$delivery_notice = "";
$verified_account_name = "";
$demo_otp_code = "";

if (isset($_SESSION['pwd_reset'])) {
    $remaining_seconds = max(0, intval($_SESSION['pwd_reset']['expires_at']) - time());
    $delivery_notice = $_SESSION['pwd_reset']['delivery_notice'] ?? '';
    $verified_account_name = $_SESSION['pwd_reset']['name'] ?? '';
    $demo_otp_code = $_SESSION['pwd_reset']['code'] ?? '';
}
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_bn ? 'পাসওয়ার্ড পুনরুদ্ধার | কৃষক বাজার' : "Forgot Password | Farmer's Market"; ?></title>
    <link rel="stylesheet" href="forgotPassword.css">
    <link rel="stylesheet" href="lang-toggle.css">
</head>

<body>

    <!-- Top Navigation Bar -->
    <header>
        <nav class="navbar">
            <div class="navbar-container">
                <a href="homePage.php" class="navbar-logo"><?php echo htmlspecialchars(__t('site_name')); ?></a>
                <ul class="navbar-menu" style="display:flex; align-items:center; gap:14px;">
                    <li>
                        <div class="lang-switch-group">
                           <?php render_lang_toggle(); ?>
                        </div>
                    </li>
                    <li><a href="homePage.php"><?php echo $is_bn ? 'হোম' : 'Home'; ?></a></li>
                    <li><a href="homePage.php?login=true"><?php echo $is_bn ? 'লগইন' : 'Login'; ?></a></li>
                </ul>
            </div>
        </nav>
    </header>

    <!-- Main Center Card -->
    <main class="auth-container">
        <div class="wrapper">
            <a href="homePage.php?login=true" class="close" title="<?php echo $is_bn ? 'লগইনে ফিরুন' : 'Back to Login'; ?>">
                <img src="close.ico" alt="Close">
            </a>

            <div class="form-box">
                <h1><?php echo $is_bn ? 'পাসওয়ার্ড রিসেট করুন' : 'Reset Password'; ?></h1>

                <?php if ($step === "verify"): ?>
                    <p class="subtitle">
                        <?php echo $is_bn
                            ? 'আপনার ধরন নির্বাচন করুন এবং ৫ সংখ্যার ভেরিফিকেশন কোড পেতে লগইন ইউজারনেম ও ইউনিক আইডি দিন।'
                            : 'Select your role and enter your login username along with your unique ID to receive a 5-digit verification code.'; ?>
                    </p>
                <?php elseif ($step === "otp"): ?>
                    <p class="subtitle">
                        <?php echo $is_bn ? 'অ্যাকাউন্ট:' : 'Account:'; ?> <strong><?php echo htmlspecialchars($verified_account_name); ?></strong>
                    </p>
                <?php elseif ($step === "reset"): ?>
                    <p class="subtitle">
                        <?php echo $is_bn
                            ? '<strong>' . htmlspecialchars($verified_account_name) . '</strong>-এর ভেরিফিকেশন সম্পন্ন হয়েছে। নিচে নতুন পাসওয়ার্ড তৈরি করুন।'
                            : 'Verification complete for <strong>' . htmlspecialchars($verified_account_name) . '</strong>. Create a new password below.'; ?>
                    </p>
                <?php else: ?>
                    <p class="subtitle">
                        <?php echo $is_bn ? 'আপনার অ্যাকাউন্টের পাসওয়ার্ড সফলভাবে রিসেট করা হয়েছে।' : 'Your account password has been reset successfully.'; ?>
                    </p>
                <?php endif; ?>

                <?php if ($error_msg !== ''): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
                <?php endif; ?>

                <?php if ($success_msg !== ''): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
                <?php endif; ?>

                <!-- =========================================================
                     STEP 1: VERIFY USERNAME & UNIQUE ID
                ========================================================== -->
                <?php if ($step === "verify"): ?>
                    <form action="forgotPassword.php" method="POST" id="verifyForm">
                        <div class="role-selection">
                            <label class="role-option">
                                <input type="radio" name="user_role" value="farmer" <?php if ($selected_role === 'farmer') echo 'checked'; ?> onclick="updateFields('farmer')"> <?php echo $is_bn ? 'কৃষক' : 'Farmer'; ?>
                            </label>
                            <label class="role-option">
                                <input type="radio" name="user_role" value="mill_owner" <?php if ($selected_role === 'mill_owner') echo 'checked'; ?> onclick="updateFields('mill_owner')"> <?php echo $is_bn ? 'মিল মালিক' : 'Mill Owner'; ?>
                            </label>
                            <label class="role-option">
                                <input type="radio" name="user_role" value="admin" <?php if ($selected_role === 'admin') echo 'checked'; ?> onclick="updateFields('admin')"> <?php echo $is_bn ? 'অ্যাডমিন' : 'Admin'; ?>
                            </label>
                        </div>

                        <!-- Input Box 1: Login Username -->
                        <div class="field-group">
                            <label class="field-label" id="usernameLabel" for="login_username"><?php echo $is_bn ? 'লগইন ইউজারনেম (ফোন নম্বর)' : 'Login Username (Phone Number)'; ?></label>
                            <div class="input-box">
                                <input
                                    type="tel"
                                    id="login_username"
                                    name="login_username"
                                    value="<?php echo htmlspecialchars($_POST['login_username'] ?? ''); ?>"
                                    placeholder="<?php echo $is_bn ? 'ফোন নম্বর লিখুন' : 'Enter Phone Number'; ?>"
                                    required
                                    autocomplete="off"
                                >
                                <img src="user.ico" alt="User Icon">
                            </div>
                        </div>

                        <!-- Input Box 2: Unique Identifier -->
                        <div class="field-group">
                            <label class="field-label" id="uniqueLabel" for="unique_identifier"><?php echo $is_bn ? 'ইউনিক আইডি (এনআইডি নম্বর)' : 'Unique ID (NID Number)'; ?></label>
                            <div class="input-box">
                                <input
                                    type="text"
                                    id="unique_identifier"
                                    name="unique_identifier"
                                    value="<?php echo htmlspecialchars($_POST['unique_identifier'] ?? ''); ?>"
                                    placeholder="<?php echo $is_bn ? 'এনআইডি নম্বর লিখুন' : 'Enter NID Number'; ?>"
                                    required
                                    autocomplete="off"
                                >
                                <img src="padlock.ico" alt="ID Icon">
                            </div>
                        </div>

                        <button type="submit" name="verify_account" class="btn"><?php echo $is_bn ? 'ভেরিফিকেশন কোড পাঠান' : 'Send Verification Code'; ?></button>

                        <div class="bottom-links">
                            <a href="homePage.php?login=true">&larr; <?php echo $is_bn ? 'লগইনে ফিরুন' : 'Back to Login'; ?></a>
                            <a href="register.php"><?php echo $is_bn ? 'অ্যাকাউন্ট তৈরি করুন' : 'Create Account'; ?></a>
                        </div>
                    </form>

                <!-- =========================================================
                     STEP 2: ENTER 5-DIGIT CODE (89 SECONDS EXPIRY + RESEND)
                ========================================================== -->
                <?php elseif ($step === "otp"): ?>
                    <?php if ($delivery_notice !== ''): ?>
                        <div class="delivery-banner">
                            <?php echo htmlspecialchars($delivery_notice); ?>
                            <!-- Local testing helper hint (remove in live production if external mail/SMS is active) -->
                            <div class="local-test-hint"><?php echo $is_bn ? 'টেস্ট কোড (লোকালহোস্ট):' : 'Test Code (Localhost):'; ?> <strong><?php echo htmlspecialchars($demo_otp_code); ?></strong></div>
                        </div>
                    <?php endif; ?>

                    <div class="timer-box" id="timerBox">
                        <?php echo $is_bn ? 'কোডের মেয়াদ শেষ হবে:' : 'Code expires in:'; ?> <span id="countdownTimer"><?php echo (int)$remaining_seconds; ?></span> <?php echo $is_bn ? 'সেকেন্ডে' : 'seconds'; ?>
                    </div>

                    <form action="forgotPassword.php" method="POST" id="otpForm">
                        <div class="field-group">
                            <label class="field-label" for="verification_code"><?php echo $is_bn ? '৫ সংখ্যার ভেরিফিকেশন কোড লিখুন' : 'Enter 5-Digit Verification Code'; ?></label>
                            <div class="input-box">
                                <input
                                    type="text"
                                    id="verification_code"
                                    name="verification_code"
                                    maxlength="5"
                                    pattern="\d{5}"
                                    inputmode="numeric"
                                    placeholder="<?php echo $is_bn ? 'যেমন: ৪৮২৯১' : 'e.g. 48291'; ?>"
                                    class="otp-input"
                                    required
                                    autocomplete="off"
                                    <?php if ($remaining_seconds <= 0) echo 'disabled'; ?>
                                >
                                <img src="padlock.ico" alt="Code Icon">
                            </div>
                        </div>

                        <button
                            type="submit"
                            name="verify_otp"
                            id="verifyOtpBtn"
                            class="btn"
                            <?php if ($remaining_seconds <= 0) echo 'disabled'; ?>
                        >
                            <?php echo $is_bn ? 'কোড যাচাই করুন' : 'Verify Code'; ?>
                        </button>
                    </form>

                    <!-- Resend Verification Code Form (Enabled when 89s timer expires) -->
                    <form action="forgotPassword.php" method="POST" class="resend-form">
                        <button
                            type="submit"
                            name="resend_code"
                            id="resendCodeBtn"
                            class="btn resend-btn"
                            <?php if ($remaining_seconds > 0) echo 'disabled'; ?>
                        >
                            <?php echo $is_bn ? 'ভেরিফিকেশন কোড পুনরায় পাঠান' : 'Resend Verification Code'; ?>
                        </button>
                    </form>

                    <div class="bottom-links">
                        <a href="forgotPassword.php">&larr; <?php echo $is_bn ? 'অ্যাকাউন্ট পরিবর্তন করুন' : 'Change Account'; ?></a>
                        <a href="homePage.php?login=true"><?php echo $is_bn ? 'লগইনে ফিরুন' : 'Back to Login'; ?></a>
                    </div>

                <!-- =========================================================
                     STEP 3: SET NEW PASSWORD
                ========================================================== -->
                <?php elseif ($step === "reset"): ?>
                    <form action="forgotPassword.php" method="POST">
                        <div class="field-group">
                            <label class="field-label" for="new_password"><?php echo $is_bn ? 'নতুন পাসওয়ার্ড' : 'New Password'; ?></label>
                            <div class="input-box">
                                <input type="password" id="new_password" name="new_password" placeholder="<?php echo $is_bn ? 'নতুন পাসওয়ার্ড লিখুন' : 'Enter New Password'; ?>" required>
                                <img src="padlock.ico" alt="Lock Icon">
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="confirm_password"><?php echo $is_bn ? 'নতুন পাসওয়ার্ড নিশ্চিত করুন' : 'Confirm New Password'; ?></label>
                            <div class="input-box">
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="<?php echo $is_bn ? 'পুনরায় নতুন পাসওয়ার্ড লিখুন' : 'Re-enter New Password'; ?>" required>
                                <img src="padlock.ico" alt="Lock Icon">
                            </div>
                        </div>

                        <button type="submit" name="reset_password" class="btn"><?php echo $is_bn ? 'পাসওয়ার্ড আপডেট করুন' : 'Update Password'; ?></button>
                    </form>

                <!-- =========================================================
                     STEP 4: PASSWORD RESET COMPLETE
                ========================================================== -->
                <?php else: ?>
                    <div style="margin-top: 18px;">
                        <a href="homePage.php?login=true" class="btn"><?php echo $is_bn ? 'লগইনে এগিয়ে যান' : 'Proceed to Login'; ?></a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        const isBnLang = <?php echo json_encode($is_bn); ?>;

        function updateFields(role) {
            const usernameLabel = document.getElementById('usernameLabel');
            const usernameInput = document.getElementById('login_username');
            const uniqueLabel = document.getElementById('uniqueLabel');
            const uniqueInput = document.getElementById('unique_identifier');

            if (!usernameInput || !uniqueInput) return;

            if (role === 'farmer') {
                usernameLabel.textContent = isBnLang ? 'লগইন ইউজারনেম (ফোন নম্বর)' : 'Login Username (Phone Number)';
                usernameInput.type = 'tel';
                usernameInput.placeholder = isBnLang ? 'ফোন নম্বর লিখুন' : 'Enter Phone Number';

                uniqueLabel.textContent = isBnLang ? 'ইউনিক আইডি (এনআইডি নম্বর)' : 'Unique ID (NID Number)';
                uniqueInput.type = 'text';
                uniqueInput.placeholder = isBnLang ? 'এনআইডি নম্বর লিখুন' : 'Enter NID Number';
            } else if (role === 'mill_owner') {
                usernameLabel.textContent = isBnLang ? 'লগইন ইউজারনেম (মিল আইডি)' : 'Login Username (Mill ID)';
                usernameInput.type = 'text';
                usernameInput.placeholder = isBnLang ? 'মিল আইডি / রেজিস্ট্রেশন নম্বর লিখুন' : 'Enter Mill ID / Registration Number';

                uniqueLabel.textContent = isBnLang ? 'ইউনিক যাচাইকরণ (নিবন্ধিত ইমেইল বা ফোন)' : 'Unique Verification (Registered Email or Phone)';
                uniqueInput.type = 'text';
                uniqueInput.placeholder = isBnLang ? 'নিবন্ধিত ইমেইল বা ফোন নম্বর লিখুন' : 'Enter Registered Email or Phone Number';
            } else if (role === 'admin') {
                usernameLabel.textContent = isBnLang ? 'লগইন ইউজারনেম (ইমেইল ঠিকানা)' : 'Login Username (Email Address)';
                usernameInput.type = 'email';
                usernameInput.placeholder = isBnLang ? 'অফিসিয়াল ইমেইল ঠিকানা লিখুন' : 'Enter Official Email Address';

                uniqueLabel.textContent = isBnLang ? 'ইউনিক আইডি (এমপ্লয়ি আইডি)' : 'Unique ID (Employee ID)';
                uniqueInput.type = 'text';
                uniqueInput.placeholder = isBnLang ? 'এমপ্লয়ি আইডি লিখুন' : 'Enter Employee ID';
            }
        }

        // 89-Second Countdown Timer for 5-Digit Verification Code
        let secondsLeft = <?php echo (int)$remaining_seconds; ?>;
        const timerDisplay = document.getElementById('countdownTimer');
        const timerBox = document.getElementById('timerBox');
        const otpInput = document.getElementById('verification_code');
        const verifyOtpBtn = document.getElementById('verifyOtpBtn');
        const resendCodeBtn = document.getElementById('resendCodeBtn');

        function handleExpiryState() {
            if (timerBox) {
                timerBox.classList.add('expired');
                timerBox.innerHTML = isBnLang
                    ? 'ভেরিফিকেশন কোডের মেয়াদ শেষ! অনুগ্রহ করে <strong>ভেরিফিকেশন কোড পুনরায় পাঠান</strong> বাটনে ক্লিক করুন।'
                    : 'Verification code expired! Please click <strong>Resend Verification Code</strong>.';
            }
            if (otpInput) otpInput.disabled = true;
            if (verifyOtpBtn) verifyOtpBtn.disabled = true;
            if (resendCodeBtn) resendCodeBtn.disabled = false;
        }

        if (timerDisplay) {
            if (secondsLeft <= 0) {
                handleExpiryState();
            } else {
                const countdownInterval = setInterval(() => {
                    secondsLeft--;
                    if (secondsLeft > 0) {
                        timerDisplay.textContent = secondsLeft;
                    } else {
                        clearInterval(countdownInterval);
                        handleExpiryState();
                    }
                }, 1000);
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const checkedRole = document.querySelector('input[name="user_role"]:checked');
            if (checkedRole) {
                updateFields(checkedRole.value);
            }
        });
    </script>

</body>

</html>
