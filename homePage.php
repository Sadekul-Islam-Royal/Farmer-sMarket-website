<?php
/**
 * Repository: farmers-market
 * File: homePage.php
 * Module: Public Landing Page, Live Marketplace & Notice Preview, About, Services & Login
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

// 1. Handle Login Form Submission with password_verify()
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $user_role = $_POST['user_role'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($user_role) || empty($username) || empty($password)) {
        die("Please fill in all fields.");
    }

    if ($user_role === "farmer") {
        $query = "SELECT * FROM farmers WHERE phone = ?";
    } elseif ($user_role === "mill_owner") {
        $query = "SELECT * FROM mill_owners WHERE mill_id = ?";
    } elseif ($user_role === "admin") {
        $query = "SELECT * FROM admins WHERE email = ?";
    } else {
        die("Invalid role selected.");
    }

    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user_role;
            $_SESSION['username'] = $username;

            if ($user_role === "farmer") {
                header("Location: farmerProfile.php");
            } elseif ($user_role === "mill_owner") {
                header("Location: MillProfile.php");
            } elseif ($user_role === "admin") {
                header("Location: adminProfile.php");
            }
            exit();
        } else {
            echo "<script>alert('Invalid password.'); window.history.back();</script>";
        }
    } else {
        echo "<script>alert('User not found.'); window.history.back();</script>";
    }

    $stmt->close();
}

// 2. Fetch Recent Government Notices from `official_documents`
$notices_result = $conn->query("SELECT * FROM official_documents ORDER BY created_at DESC LIMIT 8");

// 3. Fetch Recent Mill Crop Purchase Posts from `mill_post` joined with `mill_owners`
$posts_query = "
    SELECT mp.*,
           COALESCE(NULLIF(mo.mill_name, ''), mp.mill_name) AS display_mill_name,
           COALESCE(
               NULLIF(TRIM(CONCAT_WS(', ', NULLIF(mo.village, ''), NULLIF(mo.upazila, ''), NULLIF(mo.district, ''))), ''),
               mp.address
           ) AS display_address
    FROM mill_post mp
    LEFT JOIN mill_owners mo ON mp.mill_id = mo.mill_id OR mp.mill_id = CAST(mo.id AS CHAR)
    ORDER BY mp.created_at DESC
    LIMIT 8
";
$posts_result = $conn->query($posts_query);

$conn->close();
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(__t('site_name')); ?></title>
    <link rel="stylesheet" href="homePage.css">
</head>

<body>

    <header>
        <nav class="navbar">
            <div class="navbar-container">
                <a href="homePage.php" class="navbar-logo" onclick="switchPageView('home-view', event)"><?php echo htmlspecialchars(__t('site_name')); ?></a>
                <button class="navbar-toggle" aria-label="Toggle Menu">
                    <span class="bar"></span>
                    <span class="bar"></span>
                    <span class="bar"></span>
                </button>
                <ul class="navbar-menu">
                    <li><a href="#home" id="nav-home" class="active-nav" onclick="switchPageView('home-view', event)"><?php echo htmlspecialchars(__t('nav_home')); ?></a></li>
                    <li><a href="#about" id="nav-about" onclick="switchPageView('about-view', event)"><?php echo htmlspecialchars(__t('nav_about')); ?></a></li>
                    <li><a href="#services" id="nav-services" onclick="switchPageView('services-view', event)"><?php echo htmlspecialchars(__t('nav_services')); ?></a></li>
                    <li><a href="#login" id="nav-login"><?php echo htmlspecialchars(__t('nav_login')); ?></a></li>
                    <li class="lang-switcher">
                        <a href="<?php echo lang_url('bn'); ?>" class="<?php echo $current_lang === 'bn' ? 'active-lang' : ''; ?>">বাংলা</a>
                        <a href="<?php echo lang_url('en'); ?>" class="<?php echo $current_lang === 'en' ? 'active-lang' : ''; ?>">English</a>
                    </li>
                </ul>
            </div>
        </nav>
    </header>

    <main class="page-wrapper">

        <!-- 1. HOME VIEW -->
        <section id="home-view" class="page-section active-page">

            <div class="hero-card">
                <div class="hero-badge"><?php echo htmlspecialchars(__t('hero_badge')); ?></div>
                <h1 class="hero-title"><?php echo htmlspecialchars(__t('hero_title')); ?></h1>
                <p class="hero-description"><?php echo htmlspecialchars(__t('hero_desc')); ?></p>
                <div class="hero-actions">
                    <button type="button" class="action-btn primary-btn" onclick="openLoginForm()"><?php echo htmlspecialchars(__t('login_btn')); ?></button>
                    <a href="register.php" class="action-btn secondary-btn"><?php echo htmlspecialchars(__t('register_btn')); ?></a>
                </div>
            </div>

            <!-- Government Notice Board -->
            <div class="content-card">
                <div class="section-header">
                    <h2><?php echo htmlspecialchars(__t('notice_board')); ?></h2>
                    <span class="section-tag"><?php echo $current_lang === 'bn' ? 'সাম্প্রতিক সরকারি সার্কুলার ও মূল্যতালিকা' : 'Recent Official Circulars & Price Lists'; ?></span>
                </div>

                <?php if ($notices_result && $notices_result->num_rows > 0): ?>
                    <div class="cards-carousel">
                        <?php while ($doc = $notices_result->fetch_assoc()): ?>
                            <?php
                                $loc_parts = array_filter([trim($doc['upazila'] ?? ''), trim($doc['district'] ?? '')]);
                                $location_str = !empty($loc_parts) ? implode(', ', $loc_parts) : ($current_lang === 'bn' ? 'জাতীয় / আঞ্চলিক' : 'National / Regional');
                                $doc_date = !empty($doc['created_at']) ? date('d M Y', strtotime($doc['created_at'])) : ($current_lang === 'bn' ? 'সাম্প্রতিক' : 'Recent');
                                $file_type = strtoupper($doc['file_type'] ?? 'DOC');
                            ?>
                            <div class="info-card notice-card">
                                <div class="card-body">
                                    <div class="card-top-badge">
                                        <span class="badge-pill badge-green"><?php echo $current_lang === 'bn' ? 'সরকারি নোটিশ' : 'Official Notice'; ?></span>
                                        <span class="badge-format"><?php echo htmlspecialchars($file_type); ?></span>
                                    </div>
                                    <h3 class="card-title"><?php echo htmlspecialchars($doc['title'] ?? 'Government Circular'); ?></h3>
                                    <p class="card-detail"><strong><?php echo $current_lang === 'bn' ? 'প্রকাশক:' : 'Issued By:'; ?></strong> <?php echo htmlspecialchars($doc['uploader_name'] ?? 'Authorized Officer'); ?></p>
                                    <?php if (!empty($doc['uploader_designation'])): ?>
                                        <p class="card-detail"><strong><?php echo $current_lang === 'bn' ? 'পদবি:' : 'Designation:'; ?></strong> <?php echo htmlspecialchars($doc['uploader_designation']); ?></p>
                                    <?php endif; ?>
                                    <p class="card-detail"><strong><?php echo $current_lang === 'bn' ? 'এলাকা:' : 'Area:'; ?></strong> <?php echo htmlspecialchars($location_str); ?></p>
                                </div>

                                <div class="card-footer">
                                    <div class="card-meta">
                                        <span><?php echo $current_lang === 'bn' ? 'প্রকাশের তারিখ:' : 'Published:'; ?></span>
                                        <span><?php echo htmlspecialchars($doc_date); ?></span>
                                    </div>
                                    <button type="button" class="card-show-more-btn" onclick="openLoginForm()">
                                        <?php echo htmlspecialchars(__t('show_more')); ?>
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p class="empty-state-text"><?php echo $current_lang === 'bn' ? 'এই মুহূর্তে কোনো সাম্প্রতিক সরকারি নোটিশ নেই।' : 'No recent government notices available at the moment.'; ?></p>
                <?php endif; ?>
            </div>

            <!-- Mill Crop Purchase Posts -->
            <div class="content-card">
                <div class="section-header">
                    <h2><?php echo htmlspecialchars(__t('mill_posts')); ?></h2>
                    <span class="section-tag"><?php echo $current_lang === 'bn' ? 'যাচাইকৃত মিলের সরাসরি ক্রয় প্রস্তাব' : 'Live Procurement Offers from Verified Mills'; ?></span>
                </div>

                <?php if ($posts_result && $posts_result->num_rows > 0): ?>
                    <div class="cards-carousel">
                        <?php while ($post = $posts_result->fetch_assoc()): ?>
                            <?php
                                $posted_date = !empty($post['created_at']) ? date('d M Y', strtotime($post['created_at'])) : ($current_lang === 'bn' ? 'সাম্প্রতিক' : 'Recent');
                            ?>
                            <div class="info-card mill-card">
                                <div class="card-body">
                                    <div class="card-top-badge">
                                        <span class="badge-pill badge-blue"><?php echo $current_lang === 'bn' ? 'ফসলের চাহিদা' : 'Crop Demand'; ?></span>
                                        <span class="badge-format"><?php echo htmlspecialchars($post['crop_type'] ?? 'Grain'); ?></span>
                                    </div>
                                    <h3 class="card-title"><?php echo htmlspecialchars($post['crop_name'] ?? 'Crop Purchase'); ?></h3>
                                    <p class="card-detail"><strong><?php echo $current_lang === 'bn' ? 'মূল্য:' : 'Price:'; ?></strong> <?php echo htmlspecialchars($post['price'] ?? '0'); ?> <?php echo $current_lang === 'bn' ? 'টাকা /' : 'Taka /'; ?> <?php echo htmlspecialchars($post['unit'] ?? 'Unit'); ?></p>
                                    <p class="card-detail"><strong><?php echo $current_lang === 'bn' ? 'মিল:' : 'Mill:'; ?></strong> <?php echo htmlspecialchars($post['display_mill_name'] ?? 'Registered Mill'); ?></p>
                                    <p class="card-detail"><strong><?php echo $current_lang === 'bn' ? 'অবস্থান:' : 'Location:'; ?></strong> <?php echo htmlspecialchars($post['display_address'] ?? 'Local Upazila'); ?></p>
                                </div>

                                <div class="card-footer">
                                    <div class="card-meta">
                                        <span><?php echo $current_lang === 'bn' ? 'পোস্টের তারিখ:' : 'Posted:'; ?></span>
                                        <span><?php echo htmlspecialchars($posted_date); ?></span>
                                    </div>
                                    <button type="button" class="card-show-more-btn" onclick="openLoginForm()">
                                        <?php echo htmlspecialchars(__t('show_more')); ?>
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p class="empty-state-text"><?php echo $current_lang === 'bn' ? 'এই মুহূর্তে কোনো সক্রিয় মিল ক্রয় পোস্ট নেই।' : 'No active mill purchase posts available right now.'; ?></p>
                <?php endif; ?>
            </div>

            <!-- Short About Preview -->
            <div class="content-card about-preview-card">
                <div class="section-header">
                    <h2><?php echo $current_lang === 'bn' ? 'কৃষক বাজার সম্পর্কে' : "About Farmer's Market"; ?></h2>
                    <span class="section-tag"><?php echo $current_lang === 'bn' ? 'লক্ষ্য ও কর্মপদ্ধতি' : 'Platform Mission & Workflow'; ?></span>
                </div>
                <p class="about-preview-text">
                    <?php echo $current_lang === 'bn'
                        ? 'কৃষক বাজার এমন একটি সমন্বিত ডিজিটাল কৃষি ব্যবস্থা, যা কৃষক, মিল মালিক এবং সরকারি প্রশাসনিক কর্মকর্তাদের মধ্যে ন্যায্য মূল্য ও স্বচ্ছতা নিশ্চিত করতে তৈরি করা হয়েছে।'
                        : "Farmer's Market is an integrated digital agricultural ecosystem designed to eliminate unfair middleman exploitation and bring complete price transparency between growers, mill owners, and government authorities."; ?>
                </p>
                <div class="about-preview-highlights">
                    <div class="mini-highlight">
                        <strong><?php echo $current_lang === 'bn' ? 'কৃষকদের জন্য:' : 'For Farmers:'; ?></strong>
                        <?php echo $current_lang === 'bn' ? 'কাছের মিলের দর দেখুন, তুলনা করুন, সরাসরি কথা বলুন এবং অভিযোগ জানান।' : 'View nearby mill rates, compare prices, chat directly, and report unfair pricing.'; ?>
                    </div>
                    <div class="mini-highlight">
                        <strong><?php echo $current_lang === 'bn' ? 'মিল মালিকদের জন্য:' : 'For Mill Owners:'; ?></strong>
                        <?php echo $current_lang === 'bn' ? 'ফসল ক্রয়ের চাহিদা প্রকাশ করুন, আর্দ্রতার মান নির্ধারণ করুন এবং সরাসরি সংগ্রহ করুন।' : 'Publish crop purchase demands, specify moisture standards, and source directly.'; ?>
                    </div>
                    <div class="mini-highlight">
                        <strong><?php echo $current_lang === 'bn' ? 'অ্যাডমিনদের জন্য:' : 'For Admins:'; ?></strong>
                        <?php echo $current_lang === 'bn' ? 'সরকারি মূল্যতালিকা প্রকাশ করুন, উপজেলা নির্দেশনা দিন এবং অভিযোগ নিষ্পত্তি করুন।' : 'Publish official price lists, issue upazila directives, and resolve complaints.'; ?>
                    </div>
                </div>
                <div class="about-preview-footer">
                    <button type="button" class="action-btn primary-btn" onclick="switchPageView('about-view', event)">
                        <?php echo htmlspecialchars(__t('show_more')); ?>
                    </button>
                </div>
            </div>

        </section>

        <!-- 2. ABOUT VIEW -->
        <section id="about-view" class="page-section">
            <div class="content-card">
                <div class="section-header">
                    <h2><?php echo $current_lang === 'bn' ? 'কৃষক বাজার পোর্টাল সম্পর্কে বিস্তারিত' : "About Farmer's Market Portal"; ?></h2>
                    <button type="button" class="back-home-btn" onclick="switchPageView('home-view', event)"><?php echo $current_lang === 'bn' ? '← হোমে ফিরে যান' : '&larr; Back to Home'; ?></button>
                </div>
                <div class="about-intro-box">
                    <h3><?php echo $current_lang === 'bn' ? 'এই প্ল্যাটফর্ম কী কাজ করে' : 'What This Platform Does'; ?></h3>
                    <p>
                        <?php echo $current_lang === 'bn'
                            ? 'কৃষক বাজার কৃষক, মিল মালিক এবং সরকারি কৃষি ও প্রশাসনিক কর্মকর্তাদের একটি অভিন্ন ডিজিটাল প্ল্যাটফর্মে যুক্ত করে। এর মাধ্যমে ফসলের ক্রয়-বিক্রয় দর, সরকারি নোটিশ, সরাসরি বার্তা আদান-প্রদান এবং অভিযোগ ব্যবস্থাপনা সহজ ও স্বচ্ছ হয়।'
                            : "Farmer's Market connects farmers, mill owners, and government officers on a unified digital portal to bring transparency to crop trading, official circulars, direct messaging, and grievance resolution."; ?>
                    </p>
                </div>
            </div>
        </section>

        <!-- 3. SERVICES VIEW -->
        <section id="services-view" class="page-section">
            <div class="content-card">
                <div class="section-header">
                    <h2><?php echo $current_lang === 'bn' ? 'আমাদের সেবাসমূহ' : 'Our Platform Services'; ?></h2>
                    <button type="button" class="back-home-btn" onclick="switchPageView('home-view', event)"><?php echo $current_lang === 'bn' ? '← হোমে ফিরে যান' : '&larr; Back to Home'; ?></button>
                </div>
                <div class="services-grid">
                    <div class="service-box">
                        <div class="service-icon">01</div>
                        <h3><?php echo $current_lang === 'bn' ? 'সরাসরি ফসল ক্রয় বোর্ড' : 'Live Crop Procurement Board'; ?></h3>
                        <p><?php echo $current_lang === 'bn' ? 'যাচাইকৃত মিল মালিকরা দর, আর্দ্রতা ও শর্তসহ ফসল ক্রয়ের পোস্ট প্রকাশ করেন।' : 'Verified mill owners publish real-time crop purchase offers with transparent rates and moisture terms.'; ?></p>
                    </div>
                    <div class="service-box">
                        <div class="service-icon">02</div>
                        <h3><?php echo $current_lang === 'bn' ? 'উপজেলা ভিত্তিক ফিল্টারিং' : 'Smart Upazila Proximity Filtering'; ?></h3>
                        <p><?php echo $current_lang === 'bn' ? 'কৃষকের নিজ উপজেলা ও জেলার মিলগুলোকে অগ্রাধিকার দিয়ে তালিকা দেখানো হয়।' : 'Automatically prioritizes buyers in the farmer’s own Upazila and District.'; ?></p>
                    </div>
                    <div class="service-box">
                        <div class="service-icon">03</div>
                        <h3><?php echo $current_lang === 'bn' ? 'সরকারি নোটিশ ও অভিযোগ কেন্দ্র' : 'Official Notices & Complaint Portal'; ?></h3>
                        <p><?php echo $current_lang === 'bn' ? 'সরকারি সার্কুলার দেখা এবং সংশ্লিষ্ট কর্মকর্তার কাছে অভিযোগ জমা ও ট্র্যাক করার সুবিধা।' : 'Access official price circulars and submit or track formal complaints.'; ?></p>
                    </div>
                </div>
            </div>
        </section>

    </main>

    <!-- LOGIN POPUP MODAL -->
    <div class="modal-backdrop" id="loginBackdrop" style="display: none;" onclick="closeLoginOnBackdrop(event)">
        <div class="wrapper" id="loginWrapper">
            <span class="close" onclick="closeLoginForm()">
                <img src="close.ico" alt="close Icon">
            </span>
            <div class="form-box">
                <h1><?php echo htmlspecialchars(__t('login_heading')); ?></h1>

                <form action="homePage.php" method="POST" id="loginForm">

                    <div class="role-selection">
                        <label class="role-option">
                            <input type="radio" name="user_role" value="farmer" checked onclick="updateInputType('farmer')"> <?php echo htmlspecialchars(__t('role_farmer')); ?>
                        </label>
                        <label class="role-option">
                            <input type="radio" name="user_role" value="mill_owner" onclick="updateInputType('mill_owner')"> <?php echo htmlspecialchars(__t('role_mill_owner')); ?>
                        </label>
                        <label class="role-option">
                            <input type="radio" name="user_role" value="admin" onclick="updateInputType('admin')"> <?php echo htmlspecialchars(__t('role_admin')); ?>
                        </label>
                    </div>

                    <div class="input-box">
                        <input type="tel" id="username" name="username" placeholder="<?php echo $current_lang === 'bn' ? 'মোবাইল নম্বর' : 'Phone Number'; ?>" required autocomplete="off">
                        <img src="user.ico" alt="User Icon">
                    </div>

                    <div class="input-box">
                        <input type="password" id="password" name="password" placeholder="<?php echo htmlspecialchars(__t('password_ph')); ?>" required>
                        <img src="padlock.ico" alt="Lock Icon">
                    </div>

                    <div class="remember-forget">
                        <label id="remember-label">
                            <input type="checkbox" id="remember-checkbox" onchange="changeLabelColor()"> <?php echo htmlspecialchars(__t('remember_me')); ?>
                        </label>
                        <a href="forgotPassword.php"><?php echo htmlspecialchars(__t('forgot_password')); ?></a>
                    </div>

                    <button type="submit" class="btn"><?php echo htmlspecialchars(__t('login_heading')); ?></button>

                    <div class="register-link">
                        <p><?php echo htmlspecialchars(__t('no_account')); ?> <a href="register.php"><?php echo htmlspecialchars(__t('register_link')); ?></a></p>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <script>
        const currentLang = <?php echo json_encode($current_lang); ?>;
        const navbarToggle = document.querySelector('.navbar-toggle');
        const navbarmenu = document.querySelector('.navbar-menu');

        navbarToggle.addEventListener('click', () => {
            navbarToggle.classList.toggle('active');
            navbarmenu.classList.toggle('active');
        });

        function changeLabelColor() {
            const checkbox = document.getElementById('remember-checkbox');
            const label = document.getElementById('remember-label');
            label.style.color = checkbox.checked ? '#0000FF' : '#000';
        }

        const loginBackdrop = document.getElementById('loginBackdrop');
        const loginForm = document.getElementById('loginForm');
        const loginNavBtn = document.getElementById('nav-login');

        function openLoginForm() {
            loginBackdrop.style.display = 'flex';
            navbarToggle.classList.remove('active');
            navbarmenu.classList.remove('active');
        }

        loginNavBtn.addEventListener('click', (e) => {
            e.preventDefault();
            openLoginForm();
        });

        function closeLoginForm() {
            loginBackdrop.style.display = 'none';
            loginForm.reset();
            updateInputType('farmer');
            document.getElementById('remember-label').style.color = '#000';
        }

        function closeLoginOnBackdrop(event) {
            if (event.target === loginBackdrop) {
                closeLoginForm();
            }
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && loginBackdrop.style.display === 'flex') {
                closeLoginForm();
            }
        });

        document.querySelector('.register-link a').addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = this.getAttribute('href');
        });

        if (new URLSearchParams(window.location.search).get('login') === 'true') {
            openLoginForm();
        }

        function updateInputType(role) {
            const usernameInput = document.getElementById('username');

            if (role === 'farmer') {
                usernameInput.type = 'tel';
                usernameInput.placeholder = currentLang === 'bn' ? 'মোবাইল নম্বর' : 'Phone Number';
            } else if (role === 'mill_owner') {
                usernameInput.type = 'text';
                usernameInput.placeholder = currentLang === 'bn' ? 'মিল আইডি / রেজিস্ট্রেশন নম্বর' : 'Mill ID / Registration Number';
            } else if (role === 'admin') {
                usernameInput.type = 'email';
                usernameInput.placeholder = currentLang === 'bn' ? 'ইমেইল ঠিকানা' : 'Email Address';
            }
        }

        function switchPageView(sectionId, event) {
            if (event) {
                event.preventDefault();
            }

            document.querySelectorAll('.page-section').forEach(sec => {
                sec.classList.remove('active-page');
            });

            document.querySelectorAll('.navbar-menu li a').forEach(link => {
                link.classList.remove('active-nav');
            });

            const targetSection = document.getElementById(sectionId);
            if (targetSection) {
                targetSection.classList.add('active-page');
            }

            if (sectionId === 'home-view') {
                document.getElementById('nav-home').classList.add('active-nav');
            } else if (sectionId === 'about-view') {
                document.getElementById('nav-about').classList.add('active-nav');
            } else if (sectionId === 'services-view') {
                document.getElementById('nav-services').classList.add('active-nav');
            }

            navbarToggle.classList.remove('active');
            navbarmenu.classList.remove('active');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>

</body>

</html>

