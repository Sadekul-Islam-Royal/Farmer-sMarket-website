<?php
/**
 * Repository: farmers-market-portal
 * File: forgotPassword.php
 * Module: Password Recovery with 5-Digit Verification Code (Email/SMS) & 89s Expiry
 * Description: Verifies role-specific credentials, generates a 5-digit OTP valid for
 *              89 seconds, sends via Email if available or SMS to Phone if no Email exists,
 *              informs the user of the delivery method, supports Resend after expiry,
 *              and updates the hashed password upon code verification.
 */
session_start();

$host = "localhost";
$db_user = "root";
$db_password = "";
$db_name = "farmers_market";

$conn = new mysqli($host, $db_user, $db_password, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/**
 * Helper: Send 5-digit verification code via Email (if email exists) or SMS (if no email)
 */
function dispatchVerificationCode(string $email, string $phone, string $code, string $userName): array
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
            'notice'  => "A 5-digit verification code has been sent via Email to " . $cleanEmail . "."
        ];
    } else {
        // Send via SMS (Integrate your local SMS Gateway API URL/Key here if needed)
        $smsText = "Farmer's Market: Your 5-digit verification code is " . $code . ". Valid for 89 seconds.";
        // Example SMS Gateway hook:
        // @file_get_contents("https://sms-gateway.example.com/api/send?to=" . urlencode($cleanPhone) . "&msg=" . urlencode($smsText));

        return [
            'channel' => 'SMS',
            'target'  => $cleanPhone,
            'notice'  => "No email address is linked to this account. A 5-digit verification code has been sent via SMS to your phone number (" . $cleanPhone . ")."
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
        $error_msg = "Please fill in both required fields.";
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
                $error_msg = "No Farmer account matched this Phone Number and NID Number.";
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
                $error_msg = "No Mill Owner account matched this Mill ID and Email/Phone.";
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
                $error_msg = "No Admin account matched this Email Address and Employee ID.";
            }
        } else {
            $error_msg = "Invalid role selected.";
        }

        if ($found_user !== null) {
            $otp_code = (string)random_int(10000, 99999);
            $expires_at = time() + 89;

            $dispatch = dispatchVerificationCode($found_user['email'], $found_user['phone'], $otp_code, $found_user['name']);

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
        $error_msg = "Session expired. Please verify your account details again.";
        $step = "verify";
    } else {
        $resetData = $_SESSION['pwd_reset'];
        $new_otp_code = (string)random_int(10000, 99999);
        $new_expires_at = time() + 89;

        $dispatch = dispatchVerificationCode($resetData['email'], $resetData['phone'], $new_otp_code, $resetData['name']);

        $_SESSION['pwd_reset']['code'] = $new_otp_code;
        $_SESSION['pwd_reset']['expires_at'] = $new_expires_at;
        $_SESSION['pwd_reset']['delivery_notice'] = $dispatch['notice'];
        $_SESSION['pwd_reset']['channel'] = $dispatch['channel'];
        $_SESSION['pwd_reset']['otp_verified'] = false;

        $success_msg = "A new 5-digit verification code has been resent via " . $dispatch['channel'] . ".";
        $step = "otp";
    }
}

// STEP 2: Verify Submitted 5-Digit Code within 89 Seconds
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verify_otp'])) {
    if (!isset($_SESSION['pwd_reset'])) {
        $error_msg = "Session expired. Please verify your account details again.";
        $step = "verify";
    } else {
        $resetData = $_SESSION['pwd_reset'];
        $entered_code = trim($_POST['verification_code'] ?? '');
        $step = "otp";

        if (time() > intval($resetData['expires_at'])) {
            $error_msg = "Verification code has expired (89 seconds limit exceeded). Please click Resend Code.";
        } elseif ($entered_code === '' || !preg_match('/^\d{5}$/', $entered_code)) {
            $error_msg = "Please enter a valid 5-digit numeric verification code.";
        } elseif ($entered_code !== $resetData['code']) {
            $error_msg = "Incorrect verification code. Please check and try again.";
        } else {
            $_SESSION['pwd_reset']['otp_verified'] = true;
            $step = "reset";
        }
    }
}

// STEP 3: Update New Password after OTP Verification
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['reset_password'])) {
    if (!isset($_SESSION['pwd_reset']) || empty($_SESSION['pwd_reset']['otp_verified'])) {
        $error_msg = "Please complete verification first.";
        $step = "verify";
    } else {
        $resetData = $_SESSION['pwd_reset'];
        $verified_role = $resetData['role'];
        $verified_user_id = intval($resetData['user_id']);
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $step = "reset";

        if ($new_password === '' || $confirm_password === '') {
            $error_msg = "Please enter and confirm your new password.";
        } elseif (strlen($new_password) < 8) {
            $error_msg = "New password must be at least 8 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error_msg = "New password and confirm password do not match.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            if ($verified_role === "farmer") {
                $stmt = $conn->prepare("UPDATE farmers SET password = ? WHERE id = ?");
            } elseif ($verified_role === "mill_owner") {
                $stmt = $conn->prepare("UPDATE mill_owners SET password = ? WHERE id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
            }

            $stmt->bind_param("si", $hashed_password, $verified_user_id);
            if ($stmt->execute()) {
                unset($_SESSION['pwd_reset']);
                $success_msg = "Password updated successfully! You can now log in with your new password.";
                $step = "done";
            } else {
                $error_msg = "Failed to update password: " . $stmt->error;
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

if (isset($_SESSION['pwd_reset'])) {
    $remaining_seconds = max(0, intval($_SESSION['pwd_reset']['expires_at']) - time());
    $delivery_notice = $_SESSION['pwd_reset']['delivery_notice'] ?? '';
    $verified_account_name = $_SESSION['pwd_reset']['name'] ?? '';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Farmer's Market</title>
    <link rel="stylesheet" href="forgotPassword.css">
</head>

<body>

    <!-- Top Navigation Bar -->
    <header>
        <nav class="navbar">
            <div class="navbar-container">
                <a href="homePage.php" class="navbar-logo">Farmer's Market</a>
                <ul class="navbar-menu">
                    <li><a href="homePage.php">Home</a></li>
                    <li><a href="homePage.php?login=true">Login</a></li>
                </ul>
            </div>
        </nav>
    </header>

    <!-- Main Center Card -->
    <main class="auth-container">
        <div class="wrapper">
            <a href="homePage.php?login=true" class="close" title="Back to Login">
                <img src="close.ico" alt="Close">
            </a>

            <div class="form-box">
                <h1>Reset Password</h1>

                <?php if ($step === "verify"): ?>
                    <p class="subtitle">
                        Select your role and enter your login username along with your unique ID to receive a 5-digit verification code.
                    </p>
                <?php elseif ($step === "otp"): ?>
                    <p class="subtitle">
                        Account: <strong><?php echo htmlspecialchars($verified_account_name); ?></strong>
                    </p>
                <?php elseif ($step === "reset"): ?>
                    <p class="subtitle">
                        Verification complete for <strong><?php echo htmlspecialchars($verified_account_name); ?></strong>. Create a new password (minimum 8 characters) below.
                    </p>
                <?php else: ?>
                    <p class="subtitle">
                        Your account password has been reset successfully.
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
                                <input type="radio" name="user_role" value="farmer" <?php if ($selected_role === 'farmer') echo 'checked'; ?> onclick="updateFields('farmer')"> Farmer
                            </label>
                            <label class="role-option">
                                <input type="radio" name="user_role" value="mill_owner" <?php if ($selected_role === 'mill_owner') echo 'checked'; ?> onclick="updateFields('mill_owner')"> Mill Owner
                            </label>
                            <label class="role-option">
                                <input type="radio" name="user_role" value="admin" <?php if ($selected_role === 'admin') echo 'checked'; ?> onclick="updateFields('admin')"> Admin
                            </label>
                        </div>

                        <!-- Input Box 1: Login Username -->
                        <div class="field-group">
                            <label class="field-label" id="usernameLabel" for="login_username">Login Username (Phone Number)</label>
                            <div class="input-box">
                                <input
                                    type="tel"
                                    id="login_username"
                                    name="login_username"
                                    value="<?php echo htmlspecialchars($_POST['login_username'] ?? ''); ?>"
                                    placeholder="Enter Phone Number"
                                    required
                                    autocomplete="off"
                                >
                                <img src="user.ico" alt="User Icon">
                            </div>
                        </div>

                        <!-- Input Box 2: Unique Identifier -->
                        <div class="field-group">
                            <label class="field-label" id="uniqueLabel" for="unique_identifier">Unique ID (NID Number)</label>
                            <div class="input-box">
                                <input
                                    type="text"
                                    id="unique_identifier"
                                    name="unique_identifier"
                                    value="<?php echo htmlspecialchars($_POST['unique_identifier'] ?? ''); ?>"
                                    placeholder="Enter NID Number"
                                    required
                                    autocomplete="off"
                                >
                                <img src="padlock.ico" alt="ID Icon">
                            </div>
                        </div>

                        <button type="submit" name="verify_account" class="btn">Send Verification Code</button>

                        <div class="bottom-links">
                            <a href="homePage.php?login=true">&larr; Back to Login</a>
                            <a href="register.php">Create Account</a>
                        </div>
                    </form>

                <!-- =========================================================
                     STEP 2: ENTER 5-DIGIT CODE (89 SECONDS EXPIRY + RESEND)
                ========================================================== -->
                <?php elseif ($step === "otp"): ?>
                    <?php if ($delivery_notice !== ''): ?>
                        <div class="delivery-banner">
                            <?php echo htmlspecialchars($delivery_notice); ?>
                        </div>
                    <?php endif; ?>

                    <div class="timer-box" id="timerBox">
                        Code expires in: <span id="countdownTimer"><?php echo (int)$remaining_seconds; ?></span> seconds
                    </div>

                    <form action="forgotPassword.php" method="POST" id="otpForm">
                        <div class="field-group">
                            <label class="field-label" for="verification_code">Enter 5-Digit Verification Code</label>
                            <div class="input-box">
                                <input
                                    type="text"
                                    id="verification_code"
                                    name="verification_code"
                                    maxlength="5"
                                    pattern="\d{5}"
                                    inputmode="numeric"
                                    placeholder="Enter 5-digit code"
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
                            Verify Code
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
                            Resend Verification Code
                        </button>
                    </form>

                    <div class="bottom-links">
                        <a href="forgotPassword.php">&larr; Change Account</a>
                        <a href="homePage.php?login=true">Back to Login</a>
                    </div>

                <!-- =========================================================
                     STEP 3: SET NEW PASSWORD
                ========================================================== -->
                <?php elseif ($step === "reset"): ?>
                    <form action="forgotPassword.php" method="POST">
                        <div class="field-group">
                            <label class="field-label" for="new_password">New Password (min. 8 characters)</label>
                            <div class="input-box">
                                <input
                                    type="password"
                                    id="new_password"
                                    name="new_password"
                                    minlength="8"
                                    placeholder="Enter New Password (at least 8 chars)"
                                    required
                                >
                                <img src="padlock.ico" alt="Lock Icon">
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="confirm_password">Confirm New Password</label>
                            <div class="input-box">
                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    minlength="8"
                                    placeholder="Re-enter New Password"
                                    required
                                >
                                <img src="padlock.ico" alt="Lock Icon">
                            </div>
                        </div>

                        <button type="submit" name="reset_password" class="btn">Update Password</button>
                    </form>

                <!-- =========================================================
                     STEP 4: PASSWORD RESET COMPLETE
                ========================================================== -->
                <?php else: ?>
                    <div style="margin-top: 18px;">
                        <a href="homePage.php?login=true" class="btn">Proceed to Login</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        function updateFields(role) {
            const usernameLabel = document.getElementById('usernameLabel');
            const usernameInput = document.getElementById('login_username');
            const uniqueLabel = document.getElementById('uniqueLabel');
            const uniqueInput = document.getElementById('unique_identifier');

            if (!usernameInput || !uniqueInput) return;

            if (role === 'farmer') {
                usernameLabel.textContent = 'Login Username (Phone Number)';
                usernameInput.type = 'tel';
                usernameInput.placeholder = 'Enter Phone Number';

                uniqueLabel.textContent = 'Unique ID (NID Number)';
                uniqueInput.type = 'text';
                uniqueInput.placeholder = 'Enter NID Number';
            } else if (role === 'mill_owner') {
                usernameLabel.textContent = 'Login Username (Mill ID)';
                usernameInput.type = 'text';
                usernameInput.placeholder = 'Enter Mill ID / Registration Number';

                uniqueLabel.textContent = 'Unique Verification (Registered Email or Phone)';
                uniqueInput.type = 'text';
                uniqueInput.placeholder = 'Enter Registered Email or Phone Number';
            } else if (role === 'admin') {
                usernameLabel.textContent = 'Login Username (Email Address)';
                usernameInput.type = 'email';
                usernameInput.placeholder = 'Enter Official Email Address';

                uniqueLabel.textContent = 'Unique ID (Employee ID)';
                uniqueInput.type = 'text';
                uniqueInput.placeholder = 'Enter Employee ID';
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
                timerBox.innerHTML = 'Verification code expired! Please click <strong>Resend Verification Code</strong>.';
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
