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
    <link rel="stylesheet" href="lang-toggle.css">
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
                        <?php render_lang_toggle(); ?></li>
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

        <!-- =========================================================
             2. ABOUT VIEW (Comprehensive Step-by-Step Guide - 7+ Sentences per Point)
        ========================================================== -->
        <section id="about-view" class="page-section">
            <div class="content-card">
                <div class="section-header">
                    <h2><?php echo $current_lang === 'bn' ? 'কৃষক বাজার পোর্টাল সম্পর্কে বিস্তারিত' : "About Farmer's Market Portal"; ?></h2>
                    <button type="button" class="back-home-btn" onclick="switchPageView('home-view', event)"><?php echo $current_lang === 'bn' ? '← হোমে ফিরে যান' : '&larr; Back to Home'; ?></button>
                </div>

                <div class="about-intro-box">
                    <h3><?php echo $current_lang === 'bn' ? 'এই প্ল্যাটফর্ম কী কাজ করে' : 'What This Platform Does'; ?></h3>
                    <p>
                        <?php if ($current_lang === 'bn'): ?>
                            <strong>কৃষক বাজার</strong> হলো একটি সমন্বিত ওয়েব-ভিত্তিক কৃষি ব্যবস্থাপনা ও বাণিজ্য পোর্টাল, যা কৃষি সরবরাহ শৃঙ্খলের তিনটি মূল স্তম্ভ—<strong>কৃষক</strong>, <strong>মিল মালিক</strong> এবং <strong>সরকারি প্রশাসনিক কর্মকর্তা (অ্যাডমিন)</strong>-কে একত্রে যুক্ত করে। এই প্ল্যাটফর্ম ফসল সংগ্রহের ঘোষণাগুলো ডিজিটাল রূপে উপস্থাপন করে, যাতে যাচাইকৃত মিলগুলোর প্রতিটি ক্রয় প্রস্তাব নির্দিষ্ট দর ও শর্তসহ সবার সামনে স্পষ্ট থাকে। এটি স্থানীয় কৃষক ও মিল মালিকদের নির্ধারিত প্রশাসনিক এখতিয়ারের আওতায় এনে উপজেলা পর্যায়ের বাজার তদারকি জোরদার করে। অনুমোদিত সরকারি কর্মকর্তারা সরাসরি পোর্টালে সরকারি মূল্য সার্কুলার ও স্থানীয় নির্দেশনা প্রকাশ করেন, যা বাজারের দর কারসাজি রোধে সহায়তা করে। কৃষক ও মিল মালিকরা অননুমোদিত মধ্যস্বত্বভোগী বা দালালের ওপর নির্ভর না করেই বিল্ট-ইন মেসেজিং ব্যবস্থার মাধ্যমে সরাসরি যোগাযোগ করতে পারেন। পাশাপাশি, প্ল্যাটফর্মটিতে স্থানীয় কৃষি ও প্রশাসনিক কর্তৃপক্ষের কাছে অভিযোগ জমা দেওয়া ও তা ট্র্যাক করার একটি সুশৃঙ্খল ব্যবস্থা রয়েছে। সব মিলিয়ে, এসব সমন্বিত মডিউল কৃষি বাণিজ্যের জন্য একটি স্বচ্ছ, জবাবদিহিমূলক এবং ন্যায্য ডিজিটাল বাজার গড়ে তোলে।
                        <?php else: ?>
                            <strong>Farmer's Market</strong> is a unified web-based agricultural governance and trading portal connecting three core pillars of the agricultural supply chain: <strong>Farmers</strong>, <strong>Mill Owners</strong>, and <strong>Government Administrative Officers (Admins)</strong>. The platform digitizes crop procurement announcements so that every purchase offer from verified mills is publicly visible with exact rates and terms. It enforces upazila-level market supervision by linking local farmers and millers under their designated administrative jurisdiction. Authorized government officers publish official price circulars and local directives directly onto the portal to prevent price manipulation. Farmers and mill owners can communicate directly through a built-in messaging system without relying on unauthorized middlemen or brokers. Additionally, the platform includes a structured grievance and complaint tracking mechanism addressed to local agricultural and administrative authorities. Together, these integrated modules create a transparent, accountable, and fair digital marketplace for agricultural trade.
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Step-by-Step Workflow of Each Role (Each step has 7+ detailed sentences) -->
                <div class="about-block">
                    <h3 class="block-heading"><?php echo $current_lang === 'bn' ? 'পোর্টাল কীভাবে কাজ করে: ধাপে ধাপে ভূমিকাভিত্তিক কর্মপ্রবাহ' : 'How the Portal Works: Step-by-Step Role Workflow'; ?></h3>

                    <div class="steps-container">
                        <div class="step-card">
                            <div class="step-number"><?php echo $current_lang === 'bn' ? 'ধাপ ১' : 'Step 1'; ?></div>
                            <div class="step-content">
                                <h4><?php echo $current_lang === 'bn' ? 'ভূমিকাভিত্তিক অ্যাকাউন্ট নিবন্ধন ও নিরাপদ প্রমাণীকরণ' : 'Role-Based Account Registration &amp; Secure Authentication'; ?></h4>
                                <p>
                                    <?php if ($current_lang === 'bn'): ?>
                                        প্রতিটি ব্যবহারকারী কৃষি সরবরাহ শৃঙ্খলে নিজস্ব নির্ধারিত ভূমিকায় নিবন্ধনের মাধ্যমে কৃষক বাজার পোর্টালে যাত্রা শুরু করেন। কৃষকরা তাদের ব্যক্তিগত তথ্য, জাতীয় পরিচয়পত্র (এনআইডি) নম্বর, মোবাইল ফোন নম্বর এবং গ্রাম, উপজেলা ও জেলাসহ পূর্ণাঙ্গ স্থায়ী ঠিকানা দিয়ে নিবন্ধন করেন। মিল মালিকরা তাদের নির্ধারিত মিল আইডি, মিলের নাম, মালিকের নাম, যোগাযোগের তথ্য, পছন্দের ফসলের ধরন এবং মিলের অবস্থানের বিস্তারিত তথ্য দিয়ে অ্যাকাউন্ট তৈরি করেন। সরকারি প্রশাসনিক কর্মকর্তারা তাদের প্রাতিষ্ঠানিক কর্মচারী আইডি, প্রশাসনিক পদবি, দায়িত্বপ্রাপ্ত উপজেলা ও জেলা এবং সরকারি ইমেইল ঠিকানা ব্যবহার করে নিবন্ধন করেন। লগইনের সময় ব্যবহারকারীরা তাদের নির্ধারিত ভূমিকা নির্বাচন করলে সিস্টেম স্বয়ংক্রিয়ভাবে তথ্য যাচাইয়ের ক্ষেত্রটি পরিবর্তন করে কৃষকের জন্য মোবাইল নম্বর, মিল মালিকের জন্য মিল আইডি এবং অ্যাডমিনের জন্য ইমেইল ঠিকানা গ্রহণ করে। তথ্যগুলো ডাটাবেসের সাথে যাচাই হওয়ার পর পোর্টাল একটি নিরাপদ সেশন তৈরি করে এবং ব্যবহারকারীকে সরাসরি তার ভূমিকাভিত্তিক ড্যাশবোর্ডে নিয়ে যায়। ভূমিকাভিত্তিক এই কঠোর পৃথকীকরণ তথ্য সুরক্ষা, স্পষ্ট জবাবদিহিতা এবং প্রতিটি অংশগ্রহণকারীর জন্য উপযোগী টুলস নিশ্চিত করে।
                                    <?php else: ?>
                                        Every user begins their journey on the Farmer's Market portal by registering under their specific operational role in the agricultural supply chain. Farmers register using their personal details, National Identity (NID) number, mobile phone number, and complete residential address including village, upazila, and district. Mill owners create their accounts by providing their official Mill ID, mill name, owner name, contact credentials, preferred crop types, and mill location details. Government administrative officers register with their official employee ID, administrative designation, assigned upazila and district, and official government email address. During login, users select their designated role radio button so the system dynamically adapts the credential field to accept a phone number for farmers, a Mill ID for mill owners, or an email address for admins. Once the credentials are verified against the database, the portal establishes a secure session and automatically redirects the user to their dedicated role-specific dashboard. This strict role-based separation ensures data privacy, clear accountability, and tailored tools for every participant.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <div class="step-card">
                            <div class="step-number"><?php echo $current_lang === 'bn' ? 'ধাপ ২' : 'Step 2'; ?></div>
                            <div class="step-content">
                                <h4><?php echo $current_lang === 'bn' ? 'অ্যাডমিন কার্যক্রম: মূল্য সার্কুলার, উপজেলা নির্দেশনা ও তদারকি' : 'Admin Operations: Price Circulars, Upazila Directives &amp; Supervision'; ?></h4>
                                <p>
                                    <?php if ($current_lang === 'bn'): ?>
                                        উপজেলা ও জেলা প্রশাসনিক কর্মকর্তারা অ্যাডমিন ড্যাশবোর্ডের মাধ্যমে প্ল্যাটফর্মের নিয়ন্ত্রণমূলক ভিত্তি হিসেবে কাজ করেন। ওভারভিউ প্যানেল থেকেই একজন অ্যাডমিন তাদের নির্ধারিত উপজেলার রিয়েল-টাইম পরিসংখ্যান পর্যবেক্ষণ করতে পারেন, যার মধ্যে মোট নিবন্ধিত কৃষক, সক্রিয় মিল মালিক, জারিকৃত নির্দেশনা এবং অমীমাংসিত অভিযোগ অন্তর্ভুক্ত থাকে। অ্যাডমিনরা PDF, JPG অথবা PNG ফরম্যাটে সর্বজনীন সরকারি নোটিশ এবং সরকারি ফসলের মূল্যতালিকা আপলোড করেন, যাতে সব অঞ্চলের কৃষক ও মিলার রাষ্ট্রীয় অনুমোদিত দর সম্পর্কে অবগত থাকেন। সর্বজনীন নোটিশের পাশাপাশি অ্যাডমিনরা নিজ উপজেলার শুধুমাত্র কৃষক, মিল মালিক অথবা উভয় পক্ষের জন্য লক্ষ্যভিত্তিক স্থানীয় নির্দেশনা জারি করেন, যাতে সুষ্ঠু বাণিজ্য বিধি কার্যকর থাকে। তারা তাদের আওতাধীন এলাকার সক্রিয় মিলগুলোর ফসল ক্রয়ের পোস্টগুলোও যাচাই করেন, যেন প্রস্তাবিত দর সরকারি নির্দেশিকার সাথে সামঞ্জস্যপূর্ণ থাকে। ডিরেক্টরি অংশের মাধ্যমে অ্যাডমিনরা প্রয়োজন অনুযায়ী মাঠ পর্যায়ে যাচাইয়ের জন্য নিজ উপজেলার নিবন্ধিত কৃষক ও মিলগুলোর পূর্ণাঙ্গ রেকর্ড অনুসন্ধান ও পর্যালোচনা করতে পারেন। সবশেষে, অ্যাডমিনরা মিল মালিকদের সাথে সরাসরি যোগাযোগ বজায় রাখেন এবং স্থানীয় অংশীদারদের জমা দেওয়া আনুষ্ঠানিক অভিযোগগুলো নিষ্পত্তি করেন।
                                    <?php else: ?>
                                        Upazila and District Administrative Officers serve as the regulatory backbone of the platform through the Admin Dashboard. From the overview panel, an admin immediately monitors real-time statistics for their assigned upazila, including total registered farmers, active mill owners, issued directives, and unresolved complaints. Admins upload universal official notices and government crop price lists in PDF, JPG, or PNG formats so that farmers and millers across all regions stay informed of state-approved rates. Beyond universal notices, admins issue targeted local directives exclusively for farmers, mill owners, or both groups within their own upazila to enforce fair trading regulations. They also inspect all active mill crop purchase posts within their jurisdiction to verify that offered prices align with official government guidelines. Through the directory section, admins can search and review complete records of registered farmers and mills in their upazila whenever field verification is needed. Finally, admins maintain direct communication with mill owners and resolve formal complaints submitted by local stakeholders.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <div class="step-card">
                            <div class="step-number"><?php echo $current_lang === 'bn' ? 'ধাপ ৩' : 'Step 3'; ?></div>
                            <div class="step-content">
                                <h4><?php echo $current_lang === 'bn' ? 'মিল মালিক কার্যক্রম: ফসল ক্রয়ের চাহিদা প্রকাশ' : 'Mill Owner Operations: Publishing Crop Purchase Demands'; ?></h4>
                                <p>
                                    <?php if ($current_lang === 'bn'): ?>
                                        যাচাইকৃত মিল মালিকরা স্থানীয় উৎপাদকদের কাছ থেকে সরাসরি কাঁচা ফসল সংগ্রহ সহজ করতে মিল মালিক ড্যাশবোর্ড ব্যবহার করেন। বিল্ট-ইন ফসল ক্রয় পোস্ট ফর্ম ব্যবহার করে একজন মিল মালিক ফসলের সুনির্দিষ্ট নাম—যেমন ব্রি ধান ২৮, গম বা ভুট্টা—এবং বিস্তৃত ফসলের ধরন উল্লেখ করেন। মূল্যায়নে কোনো অস্পষ্টতা না রাখতে তারা পরিমাপের একক—যেমন মণ (৪০ কেজি), কেজি, কুইন্টাল বা টন—সহ প্রস্তাবিত ক্রয়মূল্য টাকায় স্পষ্টভাবে ঘোষণা করেন। মিল মালিকরা ফসলের সর্বোচ্চ গ্রহণযোগ্য আর্দ্রতার শতকরা হারও নির্ধারণ করেন এবং বিবরণ অংশে নির্দিষ্ট সরবরাহ সময়সূচি, মানের প্রত্যাশা ও পরিশোধের শর্তাবলি তুলে ধরেন। প্রকাশিত হওয়ার পর এই ক্রয় পোস্টগুলো তাৎক্ষণিকভাবে মিল মালিকের ব্যবস্থাপনা বোর্ড, অ্যাডমিনের তদারকি প্যানেল এবং কৃষকের লাইভ মার্কেটপ্লেস ফিডে দৃশ্যমান হয়। মিল মালিকরা তাদের সক্রিয় পোস্টগুলো অনুসন্ধান করতে পারেন, পপআপ মোডালে পূর্ণাঙ্গ বিবরণ দেখতে পারেন অথবা সংগ্রহের লক্ষ্য পূরণ হলে পুরোনো প্রস্তাব মুছে ফেলতে পারেন। এছাড়াও, মিল মালিকরা সরকারি নোটিশ ও স্থানীয় প্রশাসনিক নির্দেশনা দেখেন এবং আগ্রহী কৃষক ও উপজেলা অ্যাডমিনদের সাথে সরাসরি চ্যাট করেন।
                                    <?php else: ?>
                                        Verified mill owners utilize the Mill Owner Dashboard to streamline their raw crop procurement directly from local producers. Using the built-in crop purchase post form, a mill owner specifies the exact crop name, such as BRRI Dhan 28, Wheat, or Maize, along with the broader crop category. They clearly declare their offered purchase price in Taka alongside the measurement unit, such as Maund (40 Kg), Kg, Quintal, or Ton, so there is zero ambiguity in valuation. Mill owners also define the maximum acceptable crop moisture percentage and outline specific delivery schedules, quality expectations, and payment terms in the description field. Once published, these purchase posts appear immediately on the mill owner's management board, the admin's oversight panel, and the farmer's live marketplace feed. Mill owners can search through their active posts, inspect full post details in a popup modal, or delete outdated offers once their procurement targets are met. In addition, mill owners check official government notices, read local administrative directives, and chat directly with interested farmers and upazila admins.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <div class="step-card">
                            <div class="step-number"><?php echo $current_lang === 'bn' ? 'ধাপ ৪' : 'Step 4'; ?></div>
                            <div class="step-content">
                                <h4><?php echo $current_lang === 'bn' ? 'কৃষক কার্যক্রম: নৈকট্যভিত্তিক অনুসন্ধান ও মূল্য তুলনা' : 'Farmer Operations: Proximity-Ranked Discovery &amp; Price Comparison'; ?></h4>
                                <p>
                                    <?php if ($current_lang === 'bn'): ?>
                                        কৃষকরা গ্রাম ছাড়াই যাচাইকৃত ফসল ক্রেতা খুঁজে পেতে এবং সঠিক বিক্রয় সিদ্ধান্ত নিতে কৃষক ড্যাশবোর্ডে প্রবেশ করেন। কোনো কৃষক তাদের হোম ফিড খুললে পোর্টালটি কৃষকের নিবন্ধিত প্রোফাইলের ওপর ভিত্তি করে একটি বুদ্ধিমান অবস্থান-ক্রম অ্যালগরিদম ব্যবহার করে মিলের ক্রয় পোস্টগুলো স্বয়ংক্রিয়ভাবে সাজায়। কৃষকের নিজ উপজেলার ভেতরে পরিচালিত মিলগুলোর পোস্ট সবার আগে দেখানো হয়, এরপর একই জেলার মিল এবং সবশেষে পার্শ্ববর্তী এলাকার প্রস্তাবগুলো প্রদর্শিত হয়। কৃষকরা জেলা, উপজেলা অথবা ধান, গম, ভুট্টা বা অন্যান্য ফসলের ধরন অনুযায়ী পোস্ট ফিল্টার করার পাশাপাশি কীওয়ার্ড দিয়েও অনুসন্ধান করতে পারেন। যেকোনো পোস্ট কার্ডের ‘আরও দেখুন’ বাটনে ক্লিক করলে একটি বিস্তারিত পপআপ উইন্ডো খোলে, যেখানে আর্দ্রতার শর্ত, সরবরাহের নিয়ম, মিল মালিকের যোগাযোগের তথ্য এবং মিলের সঠিক ঠিকানা দেখা যায়। মিলের প্রস্তাবের পাশাপাশি কৃষকরা সরকারি নোটিশ ও মূল্যতালিকা সারণি ব্রাউজ ও ফিল্টার করে বেসরকারি মিলের দরের সাথে সরকারি নির্ধারিত মানদণ্ড দর তুলনা করতে পারেন। কৃষকরা তাদের নোটিফিকেশন ট্যাবে জরুরি স্থানীয় নির্দেশনাও পান এবং সঠিক স্থানীয় মিলের সাথে যুক্ত হতে ব্যক্তিগত প্রোফাইল ও ঠিকানা হালনাগাদ রাখেন।
                                    <?php else: ?>
                                        Farmers access the Farmer Dashboard to discover verified crop buyers and make well-informed selling decisions without leaving their villages. When a farmer opens their home feed, the portal automatically prioritizes mill purchase posts using an intelligent location-ranking algorithm based on the farmer's registered profile. Posts from mills operating within the farmer's own upazila are displayed first, followed by mills located within the same district, and finally offers from neighboring regions. Farmers can further refine their search by filtering posts by district, upazila, or crop category such as Paddy, Wheat, Corn, or Others, as well as searching by keywords. Clicking the Show More button on any post card opens a detailed popup window displaying moisture requirements, delivery terms, mill owner contact information, and exact mill address. Alongside mill offers, farmers browse and filter the Official Notices and Price Lists table to compare private mill rates against government-declared benchmark prices. Farmers also receive urgent local directives in their notification tab and keep their personal profile and address details up to date for accurate local matching.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <div class="step-card">
                            <div class="step-number"><?php echo $current_lang === 'bn' ? 'ধাপ ৫' : 'Step 5'; ?></div>
                            <div class="step-content">
                                <h4><?php echo $current_lang === 'bn' ? 'সরাসরি বার্তা আদান-প্রদান ও আনুষ্ঠানিক অভিযোগ নিষ্পত্তি' : 'Direct Messaging &amp; Formal Complaint Resolution'; ?></h4>
                                <p>
                                    <?php if ($current_lang === 'bn'): ?>
                                        পোর্টালের কর্মপ্রবাহের শেষ ধাপ অংশীদারদের সরাসরি বাণিজ্য চুক্তি চূড়ান্ত করতে এবং স্বচ্ছভাবে বাজার বিরোধ নিষ্পত্তিতে সহায়তা করে। যখন কোনো কৃষক পছন্দের ফসল ক্রয়ের পোস্ট পান, তখন পোস্টের বিস্তারিত মোডালের ভেতরে থাকা মেসেজ বাটনে ক্লিক করে সংশ্লিষ্ট মিল মালিকের সাথে সরাসরি কথোপকথন শুরু করতে পারেন। রিয়েল-টাইম চ্যাট ইন্টারফেসের মাধ্যমে কৃষক ও মিল মালিকরা মধ্যস্বত্বভোগীর হস্তক্ষেপ ছাড়াই ফসলের পরিমাণ, আর্দ্রতার প্রস্তুতি, পরিবহন ব্যবস্থা এবং সরবরাহের তারিখ নিয়ে আলোচনা করেন। একইভাবে, মিল মালিক ও উপজেলা অ্যাডমিনরা নিয়ন্ত্রণমূলক নির্দেশনা স্পষ্ট করতে বা সংগ্রহ অভিযান সমন্বয় করতে সমন্বিত মেসেজিং ব্যবস্থা ব্যবহার করেন। কোনো কৃষক বা মিল মালিক সিন্ডিকেটের মাধ্যমে দর কমানো, ওজনে অন্যায্য কর্তন বা অর্থ পরিশোধের শর্ত ভঙ্গের শিকার হলে অভিযোগ অংশে যেতে পারেন। ব্যবহারকারীরা উপজেলা কৃষি কর্মকর্তা, উপজেলা বাণিজ্য কর্মকর্তা, উপজেলা নির্বাহী অফিসার (ইউএনও) অথবা জেলা খাদ্য নিয়ন্ত্রকের বরাবর সুনির্দিষ্ট অভিযোগ জমা দেন। জমা দেওয়া প্রতিটি অভিযোগ সময়সহ সংরক্ষিত হয় এবং দায়িত্বপ্রাপ্ত কর্মকর্তা প্রশাসনিক ব্যবস্থা নিলে ‘অমীমাংসিত’ থেকে ‘নিষ্পন্ন’ অবস্থায় সরাসরি ট্র্যাক করা যায়।
                                    <?php else: ?>
                                        The final stage of the portal's workflow empowers stakeholders to finalize trade agreements directly and resolve market disputes transparently. Whenever a farmer finds a favorable crop purchase post, they can click the Message button inside the post details modal to open a direct conversation with that specific mill owner. Through the real-time chat interface, farmers and mill owners discuss available harvest quantities, moisture readiness, transportation logistics, and delivery dates without middleman interference. Similarly, mill owners and upazila admins use the integrated messaging system to clarify regulatory instructions or coordinate procurement drives. If a farmer or mill owner experiences syndicate price suppression, unfair weight deductions, or breach of payment terms, they can open the Complaint section. Users submit structured complaints addressed directly to the Upazila Agriculture Officer, Upazila Commerce Officer, Upazila Nirbahi Officer (UNO), or District Food Controller. Each submitted complaint is recorded with a timestamp and tracked live from Pending to Resolved status once the designated officer takes administrative action.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step-by-Step Benefits Section (Each benefit has 7+ detailed sentences) -->
                <div class="about-block">
                    <h3 class="block-heading"><?php echo $current_lang === 'bn' ? 'কৃষক বাজার ব্যবহারের প্রধান সুবিধাসমূহ' : "Key Benefits of Using Farmer's Market"; ?></h3>

                    <div class="benefits-grid">
                        <div class="benefit-step">
                            <span class="benefit-num">01</span>
                            <h4><?php echo $current_lang === 'bn' ? 'মধ্যস্বত্বভোগীর শোষণ বিলোপ' : 'Elimination of Middleman Exploitation'; ?></h4>
                            <p>
                                <?php if ($current_lang === 'bn'): ?>
                                    প্রচলিত কৃষি সরবরাহ ব্যবস্থায় ক্ষুদ্র কৃষকদের প্রায়ই একাধিক স্তরের স্থানীয় দালাল ও মধ্যস্বত্বভোগীর মাধ্যমে ফসল বিক্রি করতে বাধ্য করা হয়। এসব মধ্যস্বত্বভোগী প্রায়শই প্রকৃত বাজার দর গোপন রাখে এবং উৎপাদকের প্রাপ্য মুনাফার একটি বড় অংশ নিজেদের দখলে নেয়। কৃষক বাজার একটি একক ডিজিটাল প্ল্যাটফর্মে চাষিদের সরাসরি যাচাইকৃত মিল মালিকদের সাথে যুক্ত করে এই অপ্রয়োজনীয় স্তরগুলো দূর করে। কৃষকরা স্থানীয় এজেন্টদের পরোক্ষ মৌখিক দরের ওপর নির্ভর না করেই মিলের প্রকৃত ক্রয়মূল্য সরাসরি দেখতে পারেন। সরাসরি যোগাযোগের ফলে উৎপাদকরা মিলের সিদ্ধান্ত গ্রহণকারীদের সাথেই সরবরাহের সময়সূচি ও পরিমাণ নিয়ে দরকষাকষি করতে পারেন। এর ফলে কৃষকরা বিক্রি করা প্রতি মণ বা টন ফসলে উল্লেখযোগ্যভাবে বেশি মুনাফা ধরে রাখতে পারেন। একই সাথে, দালালদের কমিশন না দিয়েই ন্যায্য ও পূর্বাভাসযোগ্য দরে কাঁচা ফসল সংগ্রহ করে মিল মালিকরাও উপকৃত হন।
                                <?php else: ?>
                                    Traditional agricultural supply chains often force smallholder farmers to sell their harvest through multiple layers of local brokers and intermediaries. These middlemen frequently withhold real market rates and capture a substantial share of the profit that rightfully belongs to the producer. Farmer's Market removes these unnecessary layers by connecting growers directly with verified mill owners on a single digital platform. Farmers can view exact mill procurement prices firsthand without relying on secondhand verbal quotes from local agents. Direct communication allows producers to negotiate delivery schedules and quantities straight with the mill's decision-makers. As a result, farmers retain a significantly higher profit margin on every maund or ton of crop they sell. Meanwhile, mill owners also benefit by procuring raw crops at fair, predictable rates without paying broker commissions.
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="benefit-step">
                            <span class="benefit-num">02</span>
                            <h4><?php echo $current_lang === 'bn' ? 'স্বচ্ছ ও যাচাইকৃত সরকারি মূল্য নির্ধারণ' : 'Transparent &amp; Verified Government Pricing'; ?></h4>
                            <p>
                                <?php if ($current_lang === 'bn'): ?>
                                    গ্রামীণ ফসল বিপণনে একটি বড় চ্যালেঞ্জ হলো সরকারি মূল্য সার্কুলার ও মৌসুমি সংগ্রহ ঘোষণার সময়মতো নাগাল না পাওয়া। অনুমোদিত প্রশাসনিক কর্মকর্তাদের সরাসরি পরিচালনায় নিবেদিত সর্বজনীন নোটিশ বোর্ডের মাধ্যমে কৃষক বাজার এই তথ্যগত ঘাটতি পূরণ করে। সরকার যখনই ধান, চাল, গম বা ভুট্টার হালনাগাদ মূল্যতালিকা ঘোষণা করে, কর্মকর্তারা যাচাইকৃত PDF বা ছবির নথি পোর্টালে আপলোড করেন। কৃষক ও মিল মালিক উভয়ই যেকোনো সময় নিজ ড্যাশবোর্ড থেকে জেলা, উপজেলা বা শিরোনাম অনুযায়ী এসব সার্কুলার অনুসন্ধান ও ফিল্টার করতে পারেন। সরকারি মানদণ্ড দর সহজে দৃশ্যমান থাকায় কৃষকরা ন্যায্য প্রস্তাব চিনতে পারেন এবং কম দরের প্রস্তাব তাৎক্ষণিকভাবে প্রত্যাখ্যান করতে পারেন। এটি একই এলাকার নিবন্ধিত মিলগুলোর মধ্যে স্বাস্থ্যকর মূল্য প্রতিযোগিতাও উৎসাহিত করে। পরিশেষে, যাচাইকৃত সার্কুলারে উন্মুক্ত প্রবেশাধিকার পুরো কৃষি সমাজে আস্থা ও দরের ন্যায্যতার সংস্কৃতি গড়ে তোলে।
                                <?php else: ?>
                                    A major challenge in rural crop marketing is the lack of timely access to official government price circulars and seasonal procurement announcements. Farmer's Market solves this information gap through its dedicated Universal Notice Board managed directly by authorized administrative officers. Whenever the government announces updated paddy, rice, wheat, or maize price lists, officials upload the verified PDF or image documents onto the portal. Both farmers and mill owners can search and filter these circulars by district, upazila, or title at any time from their dashboards. Having official price benchmarks readily visible empowers farmers to recognize fair offers and reject undervalued bids immediately. It also encourages healthy price competition among registered mills within the same locality. Ultimately, open access to verified circulars establishes a culture of trust and price fairness across the entire agricultural community.
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="benefit-step">
                            <span class="benefit-num">03</span>
                            <h4><?php echo $current_lang === 'bn' ? 'স্থানীয় মিলের সাথে যুক্ত হয়ে পরিবহন খরচ হ্রাস' : 'Reduced Transportation Costs via Local Matching'; ?></h4>
                            <p>
                                <?php if ($current_lang === 'bn'): ?>
                                    সংগৃহীত ফসল দীর্ঘ দূরত্বে পরিবহন করা ব্যয়বহুল, সময়সাপেক্ষ এবং এতে ফসল কাটার পর ওজন কমে যাওয়া বা নষ্ট হওয়ার ঝুঁকি বাড়ে। এই লজিস্টিক চাপ কমাতে কৃষক বাজার কৃষকের মার্কেটপ্লেস ভিউতে স্মার্ট নৈকট্যভিত্তিক ক্রম যুক্ত করেছে। সিস্টেমটি সক্রিয় ফসল ক্রেতা মিলগুলোর অবস্থানের সাথে কৃষকের নিবন্ধিত উপজেলা ও জেলা স্বয়ংক্রিয়ভাবে মিলিয়ে নেয়। কৃষকের নিজ উপজেলার ভেতরে অবস্থিত মিলগুলোর ক্রয় পোস্ট ‘কাছের মিল’ ব্যাজসহ ফিডের একদম শীর্ষে অগ্রাধিকার পায়। এই স্থানীয়-অগ্রাধিকার দৃশ্যমানতা কৃষকদের দূরের বাজার বিবেচনার আগেই খামারের কাছাকাছি নির্ভরযোগ্য ক্রেতা খুঁজে পেতে সহায়তা করে। কাছের মিলে বিক্রি করলে গ্রামীণ উৎপাদকদের জ্বালানি ও যানবাহন ভাড়া খরচ নাটকীয়ভাবে কমে যায়। একই সাথে, স্থানীয় মিলগুলো দ্রুততর সময়ে এবং সরবরাহ শৃঙ্খলের কম বিলম্বে তাজা ও স্থানীয়ভাবে উৎপাদিত ফসল সংগ্রহ করতে পারে।
                                <?php else: ?>
                                    Transporting harvested crops over long distances is expensive, time-consuming, and increases the risk of post-harvest weight loss or spoilage. To minimize these logistical burdens, Farmer's Market incorporates smart proximity-based ranking directly into the farmer's marketplace view. The system automatically matches the farmer's registered upazila and district against the locations of active crop-buying mills. Purchase posts from mills located inside the farmer's own upazila are prioritized at the very top of the feed with a Nearby badge. This local-first visibility helps farmers identify trusted buyers within a short distance of their farms before considering distant markets. Selling to nearby mills dramatically lowers fuel and vehicle rental expenses for rural producers. At the same time, local mills secure fresh, locally grown harvests faster and with lower supply-chain delays.
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="benefit-step">
                            <span class="benefit-num">04</span>
                            <h4><?php echo $current_lang === 'bn' ? 'স্পষ্ট মান ও আর্দ্রতার মানদণ্ড' : 'Clear Quality &amp; Moisture Standards'; ?></h4>
                            <p>
                                <?php if ($current_lang === 'bn'): ?>
                                    মিল গেটে প্রায়ই এমন বিরোধ দেখা দেয় যখন কৃষকরা ফসল নিয়ে যাওয়ার পর আর্দ্রতা বা মানের অসংগতির কারণে অপ্রত্যাশিত দরপতনের মুখে পড়েন। প্রতিটি পোস্টে মিল মালিকদের সুনির্দিষ্ট মান ও আর্দ্রতার শর্ত আগেভাগেই উল্লেখ করতে বাধ্য করে কৃষক বাজার এই ভুল বোঝাবুঝি প্রতিরোধ করে। প্রতিটি ফসল ক্রয় কার্ডে ফসলের জাত, একক মূল্য, সর্বোচ্চ গ্রহণযোগ্য আর্দ্রতার শতকরা হার এবং নির্দিষ্ট সরবরাহ বা অর্থ পরিশোধের শর্ত স্পষ্টভাবে দেখানো হয়। ফসল কাটা, শুকানো বা মিলে পরিবহনের আগেই কৃষকরা এই কারিগরি শর্তগুলো সতর্কতার সাথে পর্যালোচনা করতে পারেন। আর্দ্রতার সঠিক সীমা আগে থেকেই জানা থাকায় কৃষকরা ফসল যথাযথভাবে শুকিয়ে বিজ্ঞাপিত পূর্ণ দর পাওয়ার যোগ্য হতে পারেন। এই স্বচ্ছতা সংগ্রহ কেন্দ্রে পৌঁছালে মনগড়া কর্তন থেকে কৃষকদের সুরক্ষা দেয়। ফলে সরবরাহের পর্যায়ে ক্রেতা ও বিক্রেতা উভয়ই আরও মসৃণ, দ্রুত ও পূর্বাভাসযোগ্য লেনদেন সম্পন্ন করতে পারেন।
                                <?php else: ?>
                                    Disputes at the mill gate frequently arise when farmers arrive with harvested grain only to face unexpected price cuts due to moisture or quality discrepancies. Farmer's Market prevents such misunderstandings by requiring mill owners to state their exact quality and moisture criteria upfront in every post. Each crop purchase card clearly displays the crop variety, unit price, maximum acceptable moisture percentage, and specific delivery or payment terms. Farmers can review these technical requirements carefully before harvesting, drying, or transporting their produce to the mill. Knowing the exact moisture threshold in advance allows farmers to properly dry their grain and qualify for the full advertised price. This transparency protects farmers from arbitrary deductions upon arrival at the procurement center. Consequently, both buyers and sellers experience smoother, faster, and more predictable transactions at the point of delivery.
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="benefit-step">
                            <span class="benefit-num">05</span>
                            <h4><?php echo $current_lang === 'bn' ? 'সরাসরি ডিজিটাল যোগাযোগের সংরক্ষিত রেকর্ড' : 'Direct Digital Communication Record'; ?></h4>
                            <p>
                                <?php if ($current_lang === 'bn'): ?>
                                    অসংরক্ষিত ফোনালাপ বা অনানুষ্ঠানিক মৌখিক বার্তার মাধ্যমে ফসল বিক্রির সমন্বয় করলে প্রায়ই দর ও সরবরাহের তারিখ নিয়ে বিভ্রান্তি তৈরি হয়। কৃষক বাজার প্রতিটি ব্যবহারকারীর ড্যাশবোর্ডের ভেতরেই একটি ভূমিকাভিত্তিক সরাসরি মেসেজিং ব্যবস্থা যুক্ত করেছে। কৃষকরা মিলের ক্রয় পোস্ট থেকেই সরাসরি কথোপকথন শুরু করতে পারেন, যা স্বয়ংক্রিয়ভাবে যাচাইকৃত মিল মালিকের অ্যাকাউন্টের সাথে যুক্ত হয়। সব বার্তা সঠিক সময়সহ কালানুক্রমিকভাবে সংরক্ষিত হয়, ফলে প্রতিটি অনুসন্ধান ও সমঝোতার একটি স্পষ্ট ডিজিটাল রেকর্ড তৈরি হয়। মিল মালিকরা বিল্ট-ইন ফিল্টার ব্যবহার করে কৃষকদের সাথে কথোপকথন এবং উপজেলা অ্যাডমিনদের সাথে দাপ্তরিক বার্তা সহজেই আলাদা রাখতে পারেন। এই সুশৃঙ্খল যোগাযোগ মাধ্যম নিশ্চিত করে যে ভরা মৌসুমেও গুরুত্বপূর্ণ বাণিজ্যের তথ্য হারিয়ে বা ভুলে যাওয়া না হয়। অধিকন্তু, নথিভুক্ত বার্তার ইতিহাস ক্রেতা ও বিক্রেতার মধ্যে সৎ আচরণ ও পারস্পরিক জবাবদিহিতা বাড়ায়।
                                <?php else: ?>
                                    Coordinating agricultural sales through unrecorded phone calls or informal verbal messages often leads to miscommunication over pricing and delivery dates. Farmer's Market provides an integrated, role-based direct messaging system built right into every user dashboard. Farmers can initiate conversations directly from a mill's purchase post, automatically linking the discussion to the verified mill owner's account. All messages are stored chronologically with exact timestamps, creating a clear digital record of every inquiry and agreement. Mill owners can organize their inbox effortlessly using built-in filters to separate conversations with farmers from official messages with upazila admins. This structured communication channel ensures that important trade details are never lost or forgotten during peak harvest seasons. Furthermore, having a documented message history promotes honest conduct and mutual accountability between buyers and sellers.
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="benefit-step">
                            <span class="benefit-num">06</span>
                            <h4><?php echo $current_lang === 'bn' ? 'জবাবদিহিমূলক প্রশাসনিক তদারকি' : 'Accountable Administrative Oversight'; ?></h4>
                            <p>
                                <?php if ($current_lang === 'bn'): ?>
                                    টেকসই বাজার সংস্কারের জন্য স্থানীয় সরকারি কর্তৃপক্ষের সক্রিয় তদারকি প্রয়োজন, যারা বিধি কার্যকর করতে এবং ঝুঁকিপূর্ণ অংশীদারদের সুরক্ষা দিতে পারেন। কৃষক বাজার উপজেলা ও জেলা অ্যাডমিনদের তাদের নির্ধারিত প্রশাসনিক সীমানা অনুযায়ী পূর্ণাঙ্গ মনিটরিং টুলস সরবরাহ করে। অ্যাডমিনরা নিবন্ধিত কৃষক ও মিলের ডিরেক্টরি পর্যালোচনা করতে পারেন, স্থানীয় ফসল ক্রয়ের দর তদারকি করতে পারেন এবং বাজার হস্তক্ষেপ প্রয়োজন হলে জরুরি উপজেলাব্যাপী নির্দেশনা প্রচার করতে পারেন। সবচেয়ে গুরুত্বপূর্ণ হলো, পোর্টালের আনুষ্ঠানিক অভিযোগ মডিউল কৃষক ও মিল মালিকদের কোনো ভয় বা আমলাতান্ত্রিক বিলম্ব ছাড়াই অন্যায্য চর্চা জানানোর সরাসরি সুযোগ দেয়। সমস্যার ধরন অনুযায়ী ব্যবহারকারীরা সরাসরি উপজেলা কৃষি কর্মকর্তা, বাণিজ্য কর্মকর্তা, ইউএনও বা জেলা খাদ্য নিয়ন্ত্রকের কাছে অভিযোগ করতে পারেন। আনুষ্ঠানিকভাবে নিষ্পত্তি না হওয়া পর্যন্ত প্রতিটি অভিযোগ প্রেরকের ইতিহাস এবং অ্যাডমিনের অ্যাকশন কিউ উভয় জায়গাতেই দৃশ্যমান থাকে। এই সমন্বিত জবাবদিহিতা সিন্ডিকেটের কারসাজি নিরুৎসাহিত করে এবং দ্রুত ও স্বচ্ছ প্রশাসনিক ন্যায়বিচার নিশ্চিত করে।
                                <?php else: ?>
                                    Sustainable market reform requires active supervision by local government authorities who can enforce regulations and protect vulnerable stakeholders. Farmer's Market equips upazila and district admins with comprehensive monitoring tools tailored to their exact administrative boundaries. Admins can review registered farmer and mill directories, monitor local crop purchase rates, and broadcast urgent upazila-wide directives whenever market intervention is needed. Most importantly, the portal's formal complaint module gives farmers and mill owners a direct line to report unfair practices without fear or bureaucratic delay. Users can address complaints specifically to the Upazila Agriculture Officer, Commerce Officer, UNO, or District Food Controller based on the nature of the issue. Every complaint remains visible in both the sender's history and the admin's action queue until it is officially resolved. This closed-loop accountability deters syndicate manipulation and ensures swift, transparent administrative justice.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- =========================================================
             3. SERVICES VIEW
        ========================================================== -->
        <section id="services-view" class="page-section">
            <div class="content-card">
                <div class="section-header">
                    <h2><?php echo $current_lang === 'bn' ? 'আমাদের প্ল্যাটফর্ম সেবাসমূহ' : 'Our Platform Services'; ?></h2>
                    <button type="button" class="back-home-btn" onclick="switchPageView('home-view', event)"><?php echo $current_lang === 'bn' ? '← হোমে ফিরে যান' : '&larr; Back to Home'; ?></button>
                </div>

                <p class="services-intro">
                    <?php echo $current_lang === 'bn'
                        ? "কৃষক বাজার কৃষি উৎপাদক, প্রক্রিয়াজাতকরণ মিল এবং সরকারি তদারকি সংস্থার জন্য উপযোগী সমন্বিত ডিজিটাল টুলস প্রদান করে।"
                        : "Farmer's Market provides end-to-end digital tools tailored for agricultural producers, processing mills, and government monitoring bodies."; ?>
                </p>

                <div class="services-grid">
                    <div class="service-box">
                        <div class="service-icon">01</div>
                        <h3><?php echo $current_lang === 'bn' ? 'সরাসরি ফসল সংগ্রহ বোর্ড' : 'Live Crop Procurement Board'; ?></h3>
                        <p><?php echo $current_lang === 'bn' ? 'যাচাইকৃত মিল মালিকরা ধান, গম, ভুট্টা ও অন্যান্য ফসলের জন্য স্বচ্ছ একক দর, আর্দ্রতার শর্ত এবং সরবরাহের নিয়মসহ রিয়েল-টাইম ক্রয় প্রস্তাব প্রকাশ করেন।' : 'Verified mill owners publish real-time crop purchase offers with transparent unit rates, moisture requirements, and delivery terms for Paddy, Wheat, Maize, and other crops.'; ?></p>
                    </div>

                    <div class="service-box">
                        <div class="service-icon">02</div>
                        <h3><?php echo $current_lang === 'bn' ? 'স্মার্ট উপজেলা নৈকট্য ফিল্টারিং' : 'Smart Upazila Proximity Filtering'; ?></h3>
                        <p><?php echo $current_lang === 'bn' ? 'অবস্থানভিত্তিক ম্যাচিং স্বয়ংক্রিয়ভাবে কৃষকের নিজ উপজেলা ও জেলার ক্রেতাদের আগে দেখায়, সাথে ফসলের ধরন ও প্রশাসনিক এলাকা অনুযায়ী বহু-মানদণ্ডে অনুসন্ধানের সুবিধা থাকে।' : 'Location-aware matching automatically ranks buyers in the farmer\'s own Upazila and District first, complete with multi-parameter search by crop type and administrative area.'; ?></p>
                    </div>

                    <div class="service-box">
                        <div class="service-icon">03</div>
                        <h3><?php echo $current_lang === 'bn' ? 'দাপ্তরিক সরকারি নোটিশ ভান্ডার' : 'Official Government Notice Repository'; ?></h3>
                        <p><?php echo $current_lang === 'bn' ? 'কেন্দ্রীভূত ডিজিটাল নোটিশ বোর্ড, যেখানে অনুমোদিত কর্মকর্তারা জেলা, উপজেলা ও শিরোনাম অনুযায়ী অনুসন্ধানযোগ্য PDF ও ছবির মূল্য সার্কুলার প্রকাশ করেন।' : 'Centralized digital notice board where authorized officials publish downloadable PDF and image price circulars searchable by district, upazila, and title.'; ?></p>
                    </div>

                    <div class="service-box">
                        <div class="service-icon">04</div>
                        <h3><?php echo $current_lang === 'bn' ? 'লক্ষ্যভিত্তিক স্থানীয় নির্দেশনা ও সতর্কতা' : 'Targeted Local Directives &amp; Alerts'; ?></h3>
                        <p><?php echo $current_lang === 'bn' ? 'উপজেলা অ্যাডমিনরা জরুরি নিয়ন্ত্রণমূলক আদেশ ও নীতিগত বিজ্ঞপ্তি সরাসরি স্থানীয় কৃষক, মিল মালিক অথবা উভয় পক্ষকে একসাথে পাঠাতে পারেন।' : 'Upazila admins broadcast urgent regulatory orders and policy notifications directly to local farmers, mill owners, or both groups simultaneously.'; ?></p>
                    </div>

                    <div class="service-box">
                        <div class="service-icon">05</div>
                        <h3><?php echo $current_lang === 'bn' ? 'সরাসরি ভূমিকাভিত্তিক মেসেজিং' : 'Direct Role-to-Role Messaging'; ?></h3>
                        <p><?php echo $current_lang === 'bn' ? 'সমন্বিত রিয়েল-টাইম চ্যাট ব্যবস্থা, যা কৃষক ও মিল মালিকদের সরাসরি দরকষাকষির পাশাপাশি মিল মালিক ও অ্যাডমিনদের দাপ্তরিক সমন্বয় সহজ করে।' : 'Integrated real-time chat system enabling direct negotiation between farmers and mill owners, as well as official coordination between mill owners and admins.'; ?></p>
                    </div>

                    <div class="service-box">
                        <div class="service-icon">06</div>
                        <h3><?php echo $current_lang === 'bn' ? 'ডিজিটাল অভিযোগ ও প্রতিকার পোর্টাল' : 'Digital Grievance &amp; Complaint Portal'; ?></h3>
                        <p><?php echo $current_lang === 'bn' ? 'উপজেলা কৃষি কর্মকর্তা, ইউএনও, বাণিজ্য কর্মকর্তা বা জেলা খাদ্য নিয়ন্ত্রকের কাছে সরাসরি পাঠানো সুনির্দিষ্ট অভিযোগ জমা ও তা নিষ্পত্তির লাইভ ট্র্যাকিং সুবিধা।' : 'Structured complaint submission routed directly to Upazila Agriculture Officers, UNOs, Commerce Officers, or District Food Controllers with live resolution tracking.'; ?></p>
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
