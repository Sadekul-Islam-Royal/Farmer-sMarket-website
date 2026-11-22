<?php

session_start();

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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli("localhost", "root", "", "farmers_market");
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $role = $_POST['role_type'] ?? '';
    $password = isset($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : '';

    $stmt = null;
    $redirect = 'homePage.php';
    $msg = 'Registration Successful!';

    if ($role === 'farmer') {
        $name       = trim($_POST['farmerName'] ?? '');
        $nid        = trim($_POST['farmerNID'] ?? '');
        $phone      = trim($_POST['farmerPhone'] ?? '');
        $rawAddr    = trim($_POST['farmerAddress'] ?? '');
        $addrParts  = parseAddressParts($rawAddr);
        $village    =$addrParts['village'];
        $upazila    =$addrParts['upazila'];
        $district   =$addrParts['district'];

        $stmt =$conn->prepare("INSERT INTO farmers (name, nid, phone, village, upazila, district, password) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $name, $nid,$phone, $village,$upazila, $district,$password);

        $redirect = 'farmerProfile.php';$msg = 'Farmer Registration Successful!';
    } elseif ($role === 'mill_owner') {
        $name       = trim($_POST['millOwnerName'] ?? '');
        $phone      = trim($_POST['millPhone'] ?? '');
        $mill_id    = trim($_POST['millID'] ?? '');
        $rawAddr    = trim($_POST['millAddress'] ?? '');
        $addrParts  = parseAddressParts($rawAddr);
        $village    =$addrParts['village'];
        $upazila    =$addrParts['upazila'];
        $district   =$addrParts['district'];
        $crop_types = trim($_POST['cropTypeString'] ?? '');

        $stmt =$conn->prepare("INSERT INTO mill_owners (name, phone, mill_id, village, upazila, district, crop_types, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssss", $name,$phone, $mill_id,$village, $upazila,$district, $crop_types,$password);

        $redirect = 'MillProfile.php';$msg = 'Mill Owner Registration Successful!';
    } elseif ($role === 'admin') {
        $name        = trim($_POST['adminName'] ?? '');
        $phone       = trim($_POST['adminPhone'] ?? '');
        $email       = trim($_POST['adminIdEmail'] ?? '');
        $employee_id = trim($_POST['adminIdEmployee'] ?? '');
        $rawAddr     = trim($_POST['adminAddress'] ?? '');
        $addrParts   = parseAddressParts($rawAddr);
        $upazila     =$addrParts['upazila'] !== '' ? $addrParts['upazila'] :$addrParts['village'];
        $district    =$addrParts['district'] !== '' ? $addrParts['district'] :$rawAddr;

        $stmt =$conn->prepare("INSERT INTO admins (name, phone, email, employee_id, upazila, district, password) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $name, $phone,$email, $employee_id,$upazila, $district,$password);

        $redirect = 'adminProfile.php';$msg = 'Admin Registration Successful!';
    }

    if ($stmt) {
        if ($stmt->execute()) {
            $_SESSION['user_id'] =$stmt->insert_id;
            $_SESSION['role'] =$role;

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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farmer's Market - Registration</title>
    <link rel="stylesheet" href="registerStyle.css">
</head>
<body>

    <header class="reg-header">
        <div class="navbar-container">
            <h1 class="site-title">Farmer's Market</h1>
            <p class="site-description">
                A trusted digital platform for establishing direct connections between farmers, mill owners, and buyers to easily trade agricultural products. Create your account and access our services today.
            </p>
        </div>
    </header>

    <div class="container">

        <div id="roleSelectView" class="role-selection-view">
            <h2>Registration</h2>
            <p style="margin-bottom: 20px;">Select your account type:</p>

            <button type="button" class="role-btn" onclick="showForm('farmer')">Farmer</button>
            <button type="button" class="role-btn" onclick="showForm('mill_owner')">Mill Owner</button>
            <button type="button" class="role-btn" onclick="showForm('admin')">Admin</button>

            <div class="login-link">
                Already have an account? <a href="homePage.php?login=true">Login</a>
            </div>
        </div>

        <form id="farmerForm" class="hidden" onsubmit="goToStep2(event, 'farmer')">
            <h2>Farmer Registration</h2>
            <div class="form-group">
                <label>Name:</label>
                <input type="text" id="farmerName" required>
            </div>
            <div class="form-group">
                <label>NID Card Number:</label>
                <input type="text" id="farmerNID" required>
            </div>
            <div class="form-group">
                <label>Phone Number:</label>
                <input type="tel" id="farmerPhone" required>
            </div>
            <div class="form-group">
                <label>Address (Village, Upazila, District):</label>
                <input type="text" id="farmerAddress" placeholder="e.g., Shyampur, Sherpur, Bogura" required>
            </div>
            <button type="submit">Next</button>
            <button type="button" class="back-btn" onclick="showRoleSelection()">Back</button>
        </form>

        <form id="millForm" class="hidden" onsubmit="goToStep2(event, 'mill_owner')">
            <h2>Mill Owner Registration</h2>
            <div class="form-group">
                <label>Name:</label>
                <input type="text" id="millOwnerName" required>
            </div>
            <div class="form-group">
                <label>Phone Number:</label>
                <input type="tel" id="millPhone" required>
            </div>
            <div class="form-group">
                <label>Mill ID Number:</label>
                <input type="text" id="millID" required>
            </div>
            <div class="form-group">
                <label>Address (Village, Upazila, District):</label>
                <input type="text" id="millAddress" placeholder="e.g., Shyampur, Sherpur, Bogura" required>
            </div>
            <div class="form-group">
                <label>Type of crops:</label>
                <input type="checkbox" name="cropType" value="Rice"> Rice Mill <br>
                <input type="checkbox" name="cropType" value="Wheat"> Wheat Mill <br>
                <input type="checkbox" name="cropType" value="Corn"> Corn Mill <br>
                <input type="checkbox" name="cropType" value="Lentils"> Lentils &amp; Others Mill
            </div>
            <button type="submit">Next</button>
            <button type="button" class="back-btn" onclick="showRoleSelection()">Back</button>
        </form>

        <form id="adminForm" class="hidden" onsubmit="goToStep2(event, 'admin')">
            <h2>Admin Registration</h2>
            <div class="form-group">
                <label>Name:</label>
                <input type="text" id="adminName" required>
            </div>
            <div class="form-group">
                <label>Phone Number:</label>
                <input type="tel" id="adminPhone" required>
            </div>
            <div class="form-group">
                <label>Email ID:</label>
                <input type="email" id="adminIdEmail" required>
            </div>
            <div class="form-group">
                <label>Employee ID:</label>
                <input type="text" id="adminIdEmployee" required>
            </div>
            <div class="form-group">
                <label>Address (Upazila, District):</label>
                <input type="text" id="adminAddress" placeholder="e.g., Sherpur, Bogura" required>
            </div>
            <button type="submit">Next</button>
            <button type="button" class="back-btn" onclick="showRoleSelection()">Back</button>
        </form>

        <form id="step2" class="hidden" action="" method="POST" onsubmit="return validatePassword(event)">
            <h2>Set Password</h2>
            <input type="hidden" name="role_type" id="h_role_type">

            <input type="hidden" name="farmerName" id="h_farmerName">
            <input type="hidden" name="farmerNID" id="h_farmerNID">
            <input type="hidden" name="farmerPhone" id="h_farmerPhone">
            <input type="hidden" name="farmerAddress" id="h_farmerAddress">

            <input type="hidden" name="millOwnerName" id="h_millOwnerName">
            <input type="hidden" name="millPhone" id="h_millPhone">
            <input type="hidden" name="millID" id="h_millID">
            <input type="hidden" name="millAddress" id="h_millAddress">
            <input type="hidden" name="cropTypeString" id="h_cropTypeString">

            <input type="hidden" name="adminName" id="h_adminName">
            <input type="hidden" name="adminPhone" id="h_adminPhone">
            <input type="hidden" name="adminIdEmail" id="h_adminIdEmail">
            <input type="hidden" name="adminIdEmployee" id="h_adminIdEmployee">
            <input type="hidden" name="adminAddress" id="h_adminAddress">

            <div class="form-group">
                <label id="usernameLabel">Username:</label>
                <input type="text" id="regUser" readonly style="background: #eee; color: #333;">
            </div>
            <div class="form-group">
                <label>Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label>Confirm Password:</label>
                <input type="password" id="confirmPassword" required>
                <small id="errorMsg" style="color: #ff6b6b; display: none; font-weight: bold; margin-top: 5px;">Passwords do not match!</small>
            </div>
            <button type="submit">Submit</button>
            <button type="button" class="back-btn" onclick="goToStep1()">Back</button>
        </form>
    </div>

    <script>
        let activeRole = '';

        function showForm(role) {
            activeRole = role;
            document.getElementById('roleSelectView').classList.add('hidden');

            if (role === 'farmer') {
                document.getElementById('farmerForm').classList.remove('hidden');
            } else if (role === 'mill_owner') {
                document.getElementById('millForm').classList.remove('hidden');
            } else if (role === 'admin') {
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

        function goToStep2(event, role) {
            event.preventDefault();
            document.getElementById('h_role_type').value = role;
            const regUser = document.getElementById('regUser');

            if (role === 'farmer') {
                document.getElementById('h_farmerName').value = document.getElementById('farmerName').value.trim();
                document.getElementById('h_farmerNID').value = document.getElementById('farmerNID').value.trim();
                document.getElementById('h_farmerPhone').value = document.getElementById('farmerPhone').value.trim();
                document.getElementById('h_farmerAddress').value = document.getElementById('farmerAddress').value.trim();

                regUser.value = document.getElementById('farmerPhone').value.trim();
                document.getElementById('usernameLabel').innerText = "Username (Phone Number):";
                document.getElementById('farmerForm').classList.add('hidden');
            } else if (role === 'mill_owner') {
                document.getElementById('h_millOwnerName').value = document.getElementById('millOwnerName').value.trim();
                document.getElementById('h_millPhone').value = document.getElementById('millPhone').value.trim();
                document.getElementById('h_millID').value = document.getElementById('millID').value.trim();
                document.getElementById('h_millAddress').value = document.getElementById('millAddress').value.trim();

                const checkboxes = document.querySelectorAll('input[name="cropType"]:checked');
                document.getElementById('h_cropTypeString').value = Array.from(checkboxes).map(cb => cb.value).join(', ');

                regUser.value = document.getElementById('millID').value.trim();
                document.getElementById('usernameLabel').innerText = "Username (Mill ID):";
                document.getElementById('millForm').classList.add('hidden');
            } else if (role === 'admin') {
                document.getElementById('h_adminName').value = document.getElementById('adminName').value.trim();
                document.getElementById('h_adminPhone').value = document.getElementById('adminPhone').value.trim();
                document.getElementById('h_adminIdEmail').value = document.getElementById('adminIdEmail').value.trim();
                document.getElementById('h_adminIdEmployee').value = document.getElementById('adminIdEmployee').value.trim();
                document.getElementById('h_adminAddress').value = document.getElementById('adminAddress').value.trim();

                regUser.value = document.getElementById('adminIdEmail').value.trim();
                document.getElementById('usernameLabel').innerText = "Username (Email ID):";
                document.getElementById('adminForm').classList.add('hidden');
            }

            document.getElementById('step2').classList.remove('hidden');
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

            if (pass !== confirmPass) {
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
