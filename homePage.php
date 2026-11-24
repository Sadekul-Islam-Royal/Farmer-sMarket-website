<?php
/**
 * Repository: farmers-market-portal
 * File: homePage.php
 * Module: Public Landing Page, Live Marketplace & Notice Preview, About, Services & Login
 * Description: Displays recent government notices and mill crop purchase posts as cards
 *              from the database, triggers login popup on "Show More" click, provides
 *              detailed step-by-step About (7+ sentences per point) & Services views,
 *              and preserves existing login logic.
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

// 1. Handle Login Form Submission (Preserved Exactly as Original)
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
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farmer's Market | Direct Agricultural Trading &amp; Government Monitoring Portal</title>
    <link rel="stylesheet" href="homePage.css">
</head>

<body>

    <!-- Top Navigation Bar (Preserved Structure) -->
    <header>
        <nav class="navbar">
            <div class="navbar-container">
                <a href="homePage.php" class="navbar-logo" onclick="switchPageView('home-view', event)">Farmer's Market</a>
                <button class="navbar-toggle" aria-label="Toggle Menu">
                    <span class="bar"></span>
                    <span class="bar"></span>
                    <span class="bar"></span>
                </button>
                <ul class="navbar-menu">
                    <li><a href="#home" id="nav-home" class="active-nav" onclick="switchPageView('home-view', event)">Home</a></li>
                    <li><a href="#about" id="nav-about" onclick="switchPageView('about-view', event)">About</a></li>
                    <li><a href="#services" id="nav-services" onclick="switchPageView('services-view', event)">Services</a></li>
                    <li><a href="#login" id="nav-login">Login</a></li>
                </ul>
            </div>
        </nav>
    </header>

    <!-- Main Page Container -->
    <main class="page-wrapper">

        <!-- =========================================================
             1. HOME VIEW
        ========================================================== -->
        <section id="home-view" class="page-section active-page">

            <!-- Hero Banner with Large Title & Portal Overview -->
            <div class="hero-card">
                <div class="hero-badge">Official Digital Agricultural Marketplace</div>
                <h1 class="hero-title">Farmer's Market</h1>
                <p class="hero-description">
                    A transparent digital bridge connecting local farmers, verified mill owners, and government agricultural officers. Compare fair market prices, view official upazila price circulars, publish crop purchase demands, and ensure syndicate-free agricultural trade across districts.
                </p>
                <div class="hero-actions">
                    <button type="button" class="action-btn primary-btn" onclick="openLoginForm()">Login to Dashboard</button>
                    <a href="register.php" class="action-btn secondary-btn">Create New Account</a>
                </div>
            </div>

            <!-- Government Notice Board Section (Cards from Server) -->
            <div class="content-card">
                <div class="section-header">
                    <h2>Government Notice Board</h2>
                    <span class="section-tag">Recent Official Circulars &amp; Price Lists</span>
                </div>

                <?php if ($notices_result && $notices_result->num_rows > 0): ?>
                    <div class="cards-carousel">
                        <?php while ($doc = $notices_result->fetch_assoc()): ?>
                            <?php
                                $loc_parts = array_filter([trim($doc['upazila'] ?? ''), trim($doc['district'] ?? '')]);
                                $location_str = !empty($loc_parts) ? implode(', ', $loc_parts) : 'National / Regional';
                                $doc_date = !empty($doc['created_at']) ? date('d M Y', strtotime($doc['created_at'])) : 'Recent';
                                $file_type = strtoupper($doc['file_type'] ?? 'DOC');
                            ?>
                            <div class="info-card notice-card">
                                <div class="card-body">
                                    <div class="card-top-badge">
                                        <span class="badge-pill badge-green">Official Notice</span>
                                        <span class="badge-format"><?php echo htmlspecialchars($file_type); ?></span>
                                    </div>
                                    <h3 class="card-title"><?php echo htmlspecialchars($doc['title'] ?? 'Government Circular'); ?></h3>
                                    <p class="card-detail"><strong>Issued By:</strong> <?php echo htmlspecialchars($doc['uploader_name'] ?? 'Authorized Officer'); ?></p>
                                    <?php if (!empty($doc['uploader_designation'])): ?>
                                        <p class="card-detail"><strong>Designation:</strong> <?php echo htmlspecialchars($doc['uploader_designation']); ?></p>
                                    <?php endif; ?>
                                    <p class="card-detail"><strong>Area:</strong> <?php echo htmlspecialchars($location_str); ?></p>
                                </div>

                                <div class="card-footer">
                                    <div class="card-meta">
                                        <span>Published:</span>
                                        <span><?php echo htmlspecialchars($doc_date); ?></span>
                                    </div>
                                    <button type="button" class="card-show-more-btn" onclick="openLoginForm()">
                                        Show More
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p class="empty-state-text">No recent government notices available at the moment.</p>
                <?php endif; ?>
            </div>

            <!-- Mill Crop Purchase Posts Section (Cards from Server) -->
            <div class="content-card">
                <div class="section-header">
                    <h2>Mill Crop Purchase Posts</h2>
                    <span class="section-tag">Live Procurement Offers from Verified Mills</span>
                </div>

                <?php if ($posts_result && $posts_result->num_rows > 0): ?>
                    <div class="cards-carousel">
                        <?php while ($post = $posts_result->fetch_assoc()): ?>
                            <?php
                                $posted_date = !empty($post['created_at']) ? date('d M Y', strtotime($post['created_at'])) : 'Recent';
                            ?>
                            <div class="info-card mill-card">
                                <div class="card-body">
                                    <div class="card-top-badge">
                                        <span class="badge-pill badge-blue">Crop Demand</span>
                                        <span class="badge-format"><?php echo htmlspecialchars($post['crop_type'] ?? 'Grain'); ?></span>
                                    </div>
                                    <h3 class="card-title"><?php echo htmlspecialchars($post['crop_name'] ?? 'Crop Purchase'); ?></h3>
                                    <p class="card-detail"><strong>Price:</strong> <?php echo htmlspecialchars($post['price'] ?? '0'); ?> Taka / <?php echo htmlspecialchars($post['unit'] ?? 'Unit'); ?></p>
                                    <p class="card-detail"><strong>Mill:</strong> <?php echo htmlspecialchars($post['display_mill_name'] ?? 'Registered Mill'); ?></p>
                                    <p class="card-detail"><strong>Location:</strong> <?php echo htmlspecialchars($post['display_address'] ?? 'Local Upazila'); ?></p>
                                </div>

                                <div class="card-footer">
                                    <div class="card-meta">
                                        <span>Posted:</span>
                                        <span><?php echo htmlspecialchars($posted_date); ?></span>
                                    </div>
                                    <button type="button" class="card-show-more-btn" onclick="openLoginForm()">
                                        Show More
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p class="empty-state-text">No active mill purchase posts available right now.</p>
                <?php endif; ?>
            </div>

            <!-- Short About Preview Section on Home Page -->
            <div class="content-card about-preview-card">
                <div class="section-header">
                    <h2>About Farmer's Market</h2>
                    <span class="section-tag">Platform Mission &amp; Workflow</span>
                </div>
                <p class="about-preview-text">
                    <strong>Farmer's Market</strong> is an integrated digital agricultural ecosystem designed to eliminate unfair middleman exploitation and bring complete price transparency between growers, rice/grain mill owners, and government administrative authorities. Through real-time crop purchase listings, upazila-based proximity matching, direct messaging, and official regulatory oversight, every stakeholder operates in a secure and accountable environment.
                </p>
                <div class="about-preview-highlights">
                    <div class="mini-highlight">
                        <strong>For Farmers:</strong> View nearby mill rates, compare prices, chat directly, and report unfair pricing.
                    </div>
                    <div class="mini-highlight">
                        <strong>For Mill Owners:</strong> Publish crop purchase demands, specify moisture standards, and source directly.
                    </div>
                    <div class="mini-highlight">
                        <strong>For Admins:</strong> Publish official price lists, issue upazila directives, and resolve complaints.
                    </div>
                </div>
                <div class="about-preview-footer">
                    <button type="button" class="action-btn primary-btn" onclick="switchPageView('about-view', event)">
                        Show More
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
                    <h2>About Farmer's Market Portal</h2>
                    <button type="button" class="back-home-btn" onclick="switchPageView('home-view', event)">&larr; Back to Home</button>
                </div>

                <div class="about-intro-box">
                    <h3>What This Platform Does</h3>
                    <p>
                        <strong>Farmer's Market</strong> is a unified web-based agricultural governance and trading portal connecting three core pillars of the agricultural supply chain: <strong>Farmers</strong>, <strong>Mill Owners</strong>, and <strong>Government Administrative Officers (Admins)</strong>. The platform digitizes crop procurement announcements so that every purchase offer from verified mills is publicly visible with exact rates and terms. It enforces upazila-level market supervision by linking local farmers and millers under their designated administrative jurisdiction. Authorized government officers publish official price circulars and local directives directly onto the portal to prevent price manipulation. Farmers and mill owners can communicate directly through a built-in messaging system without relying on unauthorized middlemen or brokers. Additionally, the platform includes a structured grievance and complaint tracking mechanism addressed to local agricultural and administrative authorities. Together, these integrated modules create a transparent, accountable, and fair digital marketplace for agricultural trade.
                    </p>
                </div>

                <!-- Step-by-Step Workflow of Each Role (Each step has 7+ detailed sentences) -->
                <div class="about-block">
                    <h3 class="block-heading">How the Portal Works: Step-by-Step Role Workflow</h3>

                    <div class="steps-container">
                        <div class="step-card">
                            <div class="step-number">Step 1</div>
                            <div class="step-content">
                                <h4>Role-Based Account Registration &amp; Secure Authentication</h4>
                                <p>
                                    Every user begins their journey on the Farmer's Market portal by registering under their specific operational role in the agricultural supply chain. Farmers register using their personal details, National Identity (NID) number, mobile phone number, and complete residential address including village, upazila, and district. Mill owners create their accounts by providing their official Mill ID, mill name, owner name, contact credentials, preferred crop types, and mill location details. Government administrative officers register with their official employee ID, administrative designation, assigned upazila and district, and official government email address. During login, users select their designated role radio button so the system dynamically adapts the credential field to accept a phone number for farmers, a Mill ID for mill owners, or an email address for admins. Once the credentials are verified against the database, the portal establishes a secure session and automatically redirects the user to their dedicated role-specific dashboard. This strict role-based separation ensures data privacy, clear accountability, and tailored tools for every participant.
                                </p>
                            </div>
                        </div>

                        <div class="step-card">
                            <div class="step-number">Step 2</div>
                            <div class="step-content">
                                <h4>Admin Operations: Price Circulars, Upazila Directives &amp; Supervision</h4>
                                <p>
                                    Upazila and District Administrative Officers serve as the regulatory backbone of the platform through the Admin Dashboard. From the overview panel, an admin immediately monitors real-time statistics for their assigned upazila, including total registered farmers, active mill owners, issued directives, and unresolved complaints. Admins upload universal official notices and government crop price lists in PDF, JPG, or PNG formats so that farmers and millers across all regions stay informed of state-approved rates. Beyond universal notices, admins issue targeted local directives exclusively for farmers, mill owners, or both groups within their own upazila to enforce fair trading regulations. They also inspect all active mill crop purchase posts within their jurisdiction to verify that offered prices align with official government guidelines. Through the directory section, admins can search and review complete records of registered farmers and mills in their upazila whenever field verification is needed. Finally, admins maintain direct communication with mill owners and resolve formal complaints submitted by local stakeholders.
                                </p>
                            </div>
                        </div>

                        <div class="step-card">
                            <div class="step-number">Step 3</div>
                            <div class="step-content">
                                <h4>Mill Owner Operations: Publishing Crop Purchase Demands</h4>
                                <p>
                                    Verified mill owners utilize the Mill Owner Dashboard to streamline their raw crop procurement directly from local producers. Using the built-in crop purchase post form, a mill owner specifies the exact crop name, such as BRRI Dhan 28, Wheat, or Maize, along with the broader crop category. They clearly declare their offered purchase price in Taka alongside the measurement unit, such as Maund (40 Kg), Kg, Quintal, or Ton, so there is zero ambiguity in valuation. Mill owners also define the maximum acceptable crop moisture percentage and outline specific delivery schedules, quality expectations, and payment terms in the description field. Once published, these purchase posts appear immediately on the mill owner's management board, the admin's oversight panel, and the farmer's live marketplace feed. Mill owners can search through their active posts, inspect full post details in a popup modal, or delete outdated offers once their procurement targets are met. In addition, mill owners check official government notices, read local administrative directives, and chat directly with interested farmers and upazila admins.
                                </p>
                            </div>
                        </div>

                        <div class="step-card">
                            <div class="step-number">Step 4</div>
                            <div class="step-content">
                                <h4>Farmer Operations: Proximity-Ranked Discovery &amp; Price Comparison</h4>
                                <p>
                                    Farmers access the Farmer Dashboard to discover verified crop buyers and make well-informed selling decisions without leaving their villages. When a farmer opens their home feed, the portal automatically prioritizes mill purchase posts using an intelligent location-ranking algorithm based on the farmer's registered profile. Posts from mills operating within the farmer's own upazila are displayed first, followed by mills located within the same district, and finally offers from neighboring regions. Farmers can further refine their search by filtering posts by district, upazila, or crop category such as Paddy, Wheat, Corn, or Others, as well as searching by keywords. Clicking the Show More button on any post card opens a detailed popup window displaying moisture requirements, delivery terms, mill owner contact information, and exact mill address. Alongside mill offers, farmers browse and filter the Official Notices and Price Lists table to compare private mill rates against government-declared benchmark prices. Farmers also receive urgent local directives in their notification tab and keep their personal profile and address details up to date for accurate local matching.
                                </p>
                            </div>
                        </div>

                        <div class="step-card">
                            <div class="step-number">Step 5</div>
                            <div class="step-content">
                                <h4>Direct Messaging &amp; Formal Complaint Resolution</h4>
                                <p>
                                    The final stage of the portal's workflow empowers stakeholders to finalize trade agreements directly and resolve market disputes transparently. Whenever a farmer finds a favorable crop purchase post, they can click the Message button inside the post details modal to open a direct conversation with that specific mill owner. Through the real-time chat interface, farmers and mill owners discuss available harvest quantities, moisture readiness, transportation logistics, and delivery dates without middleman interference. Similarly, mill owners and upazila admins use the integrated messaging system to clarify regulatory instructions or coordinate procurement drives. If a farmer or mill owner experiences syndicate price suppression, unfair weight deductions, or breach of payment terms, they can open the Complaint section. Users submit structured complaints addressed directly to the Upazila Agriculture Officer, Upazila Commerce Officer, Upazila Nirbahi Officer (UNO), or District Food Controller. Each submitted complaint is recorded with a timestamp and tracked live from Pending to Resolved status once the designated officer takes administrative action.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step-by-Step Benefits Section (Each benefit has 7+ detailed sentences) -->
                <div class="about-block">
                    <h3 class="block-heading">Key Benefits of Using Farmer's Market</h3>

                    <div class="benefits-grid">
                        <div class="benefit-step">
                            <span class="benefit-num">01</span>
                            <h4>Elimination of Middleman Exploitation</h4>
                            <p>
                                Traditional agricultural supply chains often force smallholder farmers to sell their harvest through multiple layers of local brokers and intermediaries. These middlemen frequently withhold real market rates and capture a substantial share of the profit that rightfully belongs to the producer. Farmer's Market removes these unnecessary layers by connecting growers directly with verified mill owners on a single digital platform. Farmers can view exact mill procurement prices firsthand without relying on secondhand verbal quotes from local agents. Direct communication allows producers to negotiate delivery schedules and quantities straight with the mill's decision-makers. As a result, farmers retain a significantly higher profit margin on every maund or ton of crop they sell. Meanwhile, mill owners also benefit by procuring raw crops at fair, predictable rates without paying broker commissions.
                            </p>
                        </div>

                        <div class="benefit-step">
                            <span class="benefit-num">02</span>
                            <h4>Transparent &amp; Verified Government Pricing</h4>
                            <p>
                                A major challenge in rural crop marketing is the lack of timely access to official government price circulars and seasonal procurement announcements. Farmer's Market solves this information gap through its dedicated Universal Notice Board managed directly by authorized administrative officers. Whenever the government announces updated paddy, rice, wheat, or maize price lists, officials upload the verified PDF or image documents onto the portal. Both farmers and mill owners can search and filter these circulars by district, upazila, or title at any time from their dashboards. Having official price benchmarks readily visible empowers farmers to recognize fair offers and reject undervalued bids immediately. It also encourages healthy price competition among registered mills within the same locality. Ultimately, open access to verified circulars establishes a culture of trust and price fairness across the entire agricultural community.
                            </p>
                        </div>

                        <div class="benefit-step">
                            <span class="benefit-num">03</span>
                            <h4>Reduced Transportation Costs via Local Matching</h4>
                            <p>
                                Transporting harvested crops over long distances is expensive, time-consuming, and increases the risk of post-harvest weight loss or spoilage. To minimize these logistical burdens, Farmer's Market incorporates smart proximity-based ranking directly into the farmer's marketplace view. The system automatically matches the farmer's registered upazila and district against the locations of active crop-buying mills. Purchase posts from mills located inside the farmer's own upazila are prioritized at the very top of the feed with a Nearby badge. This local-first visibility helps farmers identify trusted buyers within a short distance of their farms before considering distant markets. Selling to nearby mills dramatically lowers fuel and vehicle rental expenses for rural producers. At the same time, local mills secure fresh, locally grown harvests faster and with lower supply-chain delays.
                            </p>
                        </div>

                        <div class="benefit-step">
                            <span class="benefit-num">04</span>
                            <h4>Clear Quality &amp; Moisture Standards</h4>
                            <p>
                                Disputes at the mill gate frequently arise when farmers arrive with harvested grain only to face unexpected price cuts due to moisture or quality discrepancies. Farmer's Market prevents such misunderstandings by requiring mill owners to state their exact quality and moisture criteria upfront in every post. Each crop purchase card clearly displays the crop variety, unit price, maximum acceptable moisture percentage, and specific delivery or payment terms. Farmers can review these technical requirements carefully before harvesting, drying, or transporting their produce to the mill. Knowing the exact moisture threshold in advance allows farmers to properly dry their grain and qualify for the full advertised price. This transparency protects farmers from arbitrary deductions upon arrival at the procurement center. Consequently, both buyers and sellers experience smoother, faster, and more predictable transactions at the point of delivery.
                            </p>
                        </div>

                        <div class="benefit-step">
                            <span class="benefit-num">05</span>
                            <h4>Direct Digital Communication Record</h4>
                            <p>
                                Coordinating agricultural sales through unrecorded phone calls or informal verbal messages often leads to miscommunication over pricing and delivery dates. Farmer's Market provides an integrated, role-based direct messaging system built right into every user dashboard. Farmers can initiate conversations directly from a mill's purchase post, automatically linking the discussion to the verified mill owner's account. All messages are stored chronologically with exact timestamps, creating a clear digital record of every inquiry and agreement. Mill owners can organize their inbox effortlessly using built-in filters to separate conversations with farmers from official messages with upazila admins. This structured communication channel ensures that important trade details are never lost or forgotten during peak harvest seasons. Furthermore, having a documented message history promotes honest conduct and mutual accountability between buyers and sellers.
                            </p>
                        </div>

                        <div class="benefit-step">
                            <span class="benefit-num">06</span>
                            <h4>Accountable Administrative Oversight</h4>
                            <p>
                                Sustainable market reform requires active supervision by local government authorities who can enforce regulations and protect vulnerable stakeholders. Farmer's Market equips upazila and district admins with comprehensive monitoring tools tailored to their exact administrative boundaries. Admins can review registered farmer and mill directories, monitor local crop purchase rates, and broadcast urgent upazila-wide directives whenever market intervention is needed. Most importantly, the portal's formal complaint module gives farmers and mill owners a direct line to report unfair practices without fear or bureaucratic delay. Users can address complaints specifically to the Upazila Agriculture Officer, Commerce Officer, UNO, or District Food Controller based on the nature of the issue. Every complaint remains visible in both the sender's history and the admin's action queue until it is officially resolved. This closed-loop accountability deters syndicate manipulation and ensures swift, transparent administrative justice.
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
                    <h2>Our Platform Services</h2>
                    <button type="button" class="back-home-btn" onclick="switchPageView('home-view', event)">&larr; Back to Home</button>
                </div>

                <p class="services-intro">
                    Farmer's Market provides end-to-end digital tools tailored for agricultural producers, processing mills, and government monitoring bodies.
                </p>

                <div class="services-grid">
                    <div class="service-box">
                        <div class="service-icon">01</div>
                        <h3>Live Crop Procurement Board</h3>
                        <p>Verified mill owners publish real-time crop purchase offers with transparent unit rates, moisture requirements, and delivery terms for Paddy, Wheat, Maize, and other crops.</p>
                    </div>

                    <div class="service-box">
                        <div class="service-icon">02</div>
                        <h3>Smart Upazila Proximity Filtering</h3>
                        <p>Location-aware matching automatically ranks buyers in the farmer's own Upazila and District first, complete with multi-parameter search by crop type and administrative area.</p>
                    </div>

                    <div class="service-box">
                        <div class="service-icon">03</div>
                        <h3>Official Government Notice Repository</h3>
                        <p>Centralized digital notice board where authorized officials publish downloadable PDF and image price circulars searchable by district, upazila, and title.</p>
                    </div>

                    <div class="service-box">
                        <div class="service-icon">04</div>
                        <h3>Targeted Local Directives &amp; Alerts</h3>
                        <p>Upazila admins broadcast urgent regulatory orders and policy notifications directly to local farmers, mill owners, or both groups simultaneously.</p>
                    </div>

                    <div class="service-box">
                        <div class="service-icon">05</div>
                        <h3>Direct Role-to-Role Messaging</h3>
                        <p>Integrated real-time chat system enabling direct negotiation between farmers and mill owners, as well as official coordination between mill owners and admins.</p>
                    </div>

                    <div class="service-box">
                        <div class="service-icon">06</div>
                        <h3>Digital Grievance &amp; Complaint Portal</h3>
                        <p>Structured complaint submission routed directly to Upazila Agriculture Officers, UNOs, Commerce Officers, or District Food Controllers with live resolution tracking.</p>
                    </div>
                </div>
            </div>
        </section>

    </main>

    <!-- =========================================================
         LOGIN POPUP MODAL (Preserved Exactly as Original)
    ========================================================== -->
    <div class="modal-backdrop" id="loginBackdrop" style="display: none;" onclick="closeLoginOnBackdrop(event)">
        <div class="wrapper" id="loginWrapper">
            <span class="close" onclick="closeLoginForm()">
                <img src="close.ico" alt="close Icon">
            </span>
            <div class="form-box">
                <h1>Login</h1>

                <form action="homePage.php" method="POST" id="loginForm">

                    <!-- Role Selection -->
                    <div class="role-selection">
                        <label class="role-option">
                            <input type="radio" name="user_role" value="farmer" checked onclick="updateInputType('farmer')"> Farmer
                        </label>
                        <label class="role-option">
                            <input type="radio" name="user_role" value="mill_owner" onclick="updateInputType('mill_owner')"> Mill Owner
                        </label>
                        <label class="role-option">
                            <input type="radio" name="user_role" value="admin" onclick="updateInputType('admin')"> Admin
                        </label>
                    </div>

                    <!-- Username Input Box -->
                    <div class="input-box">
                        <input type="tel" id="username" name="username" placeholder="Phone Number" required autocomplete="off">
                        <img src="user.ico" alt="User Icon">
                    </div>

                    <!-- Password Input Box -->
                    <div class="input-box">
                        <input type="password" id="password" name="password" placeholder="Password" required>
                        <img src="padlock.ico" alt="Lock Icon">
                    </div>

                    <div class="remember-forget">
                        <label id="remember-label">
                            <input type="checkbox" id="remember-checkbox" onchange="changeLabelColor()"> Remember me
                        </label>
                        <a href="forgotPassword.php">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn">Login</button>

                    <div class="register-link">
                        <p>Don't have an account? <a href="register.php">Register</a></p>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <script>
        const navbarToggle = document.querySelector('.navbar-toggle');
        const navbarmenu = document.querySelector('.navbar-menu');

        navbarToggle.addEventListener('click', () => {
            navbarToggle.classList.toggle('active');
            navbarmenu.classList.toggle('active');
        });

        function changeLabelColor() {
            const checkbox = document.getElementById('remember-checkbox');
            const label = document.getElementById('remember-label');

            if (checkbox.checked) {
                label.style.color = '#0000FF';
            } else {
                label.style.color = '#000';
            }
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
                usernameInput.placeholder = 'Phone Number';
            } else if (role === 'mill_owner') {
                usernameInput.type = 'text';
                usernameInput.placeholder = 'Mill ID / Registration Number';
            } else if (role === 'admin') {
                usernameInput.type = 'email';
                usernameInput.placeholder = 'Email Address';
            }
        }

        // Switch between Home, About, and Services Sections
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