<?php
session_start(); 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli("localhost", "root", "", "farmers_market");
    if ($conn->connect_error) { 
        die("Connection failed: " . $conn->connect_error); 
    }

    $role = $_POST['role_type'] ?? '';
    $password = isset($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : '';

    $stmt = null;

    if ($role === 'farmer') {
        $name = $_POST['farmerName'] ?? '';
        $nid = $_POST['farmerNID'] ?? '';
        $phone = $_POST['farmerPhone'] ?? '';
        $address = $_POST['farmerAddress'] ?? '';

        $stmt = $conn->prepare("INSERT INTO farmers (name, nid, phone, address, password) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $name, $nid, $phone, $address, $password);
        
        $redirect = 'farmerProfile.php';
        $msg = 'Farmer Registration Successful!';
    } 
    elseif ($role === 'mill_owner') {
        $name = $_POST['millOwnerName'] ?? '';
        $phone = $_POST['millPhone'] ?? '';
        $mill_id = $_POST['millID'] ?? '';
        $address = $_POST['millAddress'] ?? '';
        $crop_types = $_POST['cropTypeString'] ?? '';

        $stmt = $conn->prepare("INSERT INTO mill_owners (name, phone, mill_id, address, crop_types, password) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $name, $phone, $mill_id, $address, $crop_types, $password);
        
        $redirect = 'millProfile.php';
        $msg = 'Mill Owner Registration Successful!';
    } 
    elseif ($role === 'admin') {
        $name = $_POST['adminName'] ?? '';
        $phone = $_POST['adminPhone'] ?? '';
        $email = $_POST['adminIdEmail'] ?? '';
        $employee_id = $_POST['adminIdEmployee'] ?? '';
        $address = $_POST['adminAddress'] ?? '';

        $stmt = $conn->prepare("INSERT INTO admins (name, phone, email, employee_id, address, password) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $name, $phone, $email, $employee_id, $address, $password);
        
        $redirect = 'adminProfile.php';
        $msg = 'Admin Registration Successful!';
    }

    if ($stmt) {
        if ($stmt->execute()) {
            
            $_SESSION['user_id'] = $stmt->insert_id; 
            $_SESSION['role'] = $role;

            echo "<script>alert('$msg'); window.location.href='$redirect';</script>";
        } else {
            echo "Error: " . $stmt->error;
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
        
        <!-- Step 0: Role Selection View -->
        <div id="roleSelectView" class="role-selection-view">
            <h2>Registration</h2>
            <p style="margin-bottom: 20px;">Select your account type:</p>
            
            <button class="role-btn" onclick="showForm('farmer')">Farmer</button>
            <button class="role-btn" onclick="showForm('mill_owner')">Mill Owner</button>
            <button class="role-btn" onclick="showForm('admin')">Admin</button>

            <div class="login-link">
                Already have an account? <a href="homePage.php?login=true">Login</a>
            </div>
        </div>

        <!-- Farmer Registration Form (Step 1) -->
        <form id="farmerForm" class="hidden" onsubmit="goToStep2(event, 'farmer')">
            <h2>Farmer Registration</h2>
            <div class="form-group"><label>Name:</label><input type="text" id="farmerName" required></div>
            <div class="form-group"><label>NID Card Number:</label><input type="text" id="farmerNID" required></div>
            <div class="form-group"><label>Phone Number:</label><input type="tel" id="farmerPhone" required></div>
            <div class="form-group"><label>Address:</label><input type="text" id="farmerAddress" required></div>
            <button type="submit">Next</button>
            <button type="button" class="back-btn" onclick="showRoleSelection()">Back</button>
        </form>

        <!-- Mill Owner Registration Form (Step 1) -->
        <form id="millForm" class="hidden" onsubmit="goToStep2(event, 'mill_owner')">
            <h2>Mill Owner Registration</h2>
            <div class="form-group"><label>Name:</label><input type="text" id="millOwnerName" required></div>
            <div class="form-group"><label>Phone Number:</label><input type="tel" id="millPhone" required></div>
            <div class="form-group"><label>Mill ID Number:</label><input type="text" id="millID" required></div>
            <div class="form-group"><label>Address:</label><input type="text" id="millAddress" required></div>
            <div class="form-group">
                <label>Type of crops:</label>
                <input type="checkbox" name="cropType" value="Rice"> Rice Mill <br>
                <input type="checkbox" name="cropType" value="Wheat"> Wheat Mill <br>
                <input type="checkbox" name="cropType" value="Corn"> Corn Mill <br>
                <input type="checkbox" name="cropType" value="Lentils"> Lentils & Others Mill
            </div>
            <button type="submit">Next</button>
            <button type="button" class="back-btn" onclick="showRoleSelection()">Back</button>
        </form>

        <!-- Admin Registration Form (Step 1) -->
        <form id="adminForm" class="hidden" onsubmit="goToStep2(event, 'admin')">
            <h2>Admin Registration</h2>
            <div class="form-group"><label>Name:</label><input type="text" id="adminName" required></div>
            <div class="form-group"><label>Phone Number:</label><input type="tel" id="adminPhone" required></div>
            <div class="form-group"><label>Email ID:</label><input type="email" id="adminIdEmail" required></div>
            <div class="form-group"><label>Employee ID:</label><input type="text" id="adminIdEmployee" required></div>
            <div class="form-group"><label>Address (District):</label><input type="text" id="adminAddress" required></div>
            <button type="submit">Next</button>
            <button type="button" class="back-btn" onclick="showRoleSelection()">Back</button>
        </form>

        <!-- Step 2: Password & Universal Backend Submission -->
        <form id="step2" class="hidden" action="" method="POST" onsubmit="return validatePassword(event)">
            <h2>Set Password</h2>
            <input type="hidden" name="role_type" id="h_role_type">
            
            <!-- Farmer Hidden Fields -->
            <input type="hidden" name="farmerName" id="h_farmerName">
            <input type="hidden" name="farmerNID" id="h_farmerNID">
            <input type="hidden" name="farmerPhone" id="h_farmerPhone">
            <input type="hidden" name="farmerAddress" id="h_farmerAddress">
            
            <!-- Mill Owner Hidden Fields -->
            <input type="hidden" name="millOwnerName" id="h_millOwnerName">
            <input type="hidden" name="millPhone" id="h_millPhone">
            <input type="hidden" name="millID" id="h_millID">
            <input type="hidden" name="millAddress" id="h_millAddress">
            <input type="hidden" name="cropTypeString" id="h_cropTypeString">
            
            <!-- Admin Hidden Fields -->
            <input type="hidden" name="adminName" id="h_adminName">
            <input type="hidden" name="adminPhone" id="h_adminPhone">
            <input type="hidden" name="adminIdEmail" id="h_adminIdEmail">
            <input type="hidden" name="adminIdEmployee" id="h_adminIdEmployee">
            <input type="hidden" name="adminAddress" id="h_adminAddress">

            <div class="form-group">
                <label id="usernameLabel">Username:</label>
                <input type="text" id="regUser" readonly style="background: #eee; color: #333;">
            </div>
            <div class="form-group"><label>Password:</label><input type="password" id="password" name="password" required></div>
            <div class="form-group">
                <label>Confirm Password:</label><input type="password" id="confirmPassword" required>
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
                document.getElementById('h_farmerName').value = document.getElementById('farmerName').value;
                document.getElementById('h_farmerNID').value = document.getElementById('farmerNID').value;
                document.getElementById('h_farmerPhone').value = document.getElementById('farmerPhone').value;
                document.getElementById('h_farmerAddress').value = document.getElementById('farmerAddress').value;
                
                regUser.value = document.getElementById('farmerPhone').value;
                document.getElementById('usernameLabel').innerText = "Username (Phone Number):";
                document.getElementById('farmerForm').classList.add('hidden');
            } 
            else if (role === 'mill_owner') {
                document.getElementById('h_millOwnerName').value = document.getElementById('millOwnerName').value;
                document.getElementById('h_millPhone').value = document.getElementById('millPhone').value;
                document.getElementById('h_millID').value = document.getElementById('millID').value;
                document.getElementById('h_millAddress').value = document.getElementById('millAddress').value;
                
                let checkboxes = document.querySelectorAll('input[name="cropType"]:checked');
                document.getElementById('h_cropTypeString').value = Array.from(checkboxes).map(cb => cb.value).join(', ');

                regUser.value = document.getElementById('millID').value;
                document.getElementById('usernameLabel').innerText = "Username (Mill ID):";
                document.getElementById('millForm').classList.add('hidden');
            } 
            else if (role === 'admin') {
                document.getElementById('h_adminName').value = document.getElementById('adminName').value;
                document.getElementById('h_adminPhone').value = document.getElementById('adminPhone').value;
                document.getElementById('h_adminIdEmail').value = document.getElementById('adminIdEmail').value;
                document.getElementById('h_adminIdEmployee').value = document.getElementById('adminIdEmployee').value;
                document.getElementById('adminAddress').value = document.getElementById('h_adminAddress').value; // fix assignment reference if needed

                regUser.value = document.getElementById('adminIdEmail').value;
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
            let pass = document.getElementById('password').value;
            let confirmPass = document.getElementById('confirmPassword').value;
            let errorMsg = document.getElementById('errorMsg');

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