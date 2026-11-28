<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// URL থেকে ?lang=bn বা ?lang=en আসলে session-এ সেভ করে clean URL-এ redirect করুন
if (isset($_GET['lang']) && in_array($_GET['lang'], ['bn', 'en'], true)) {
    $_SESSION['lang'] = $_GET['lang'];

    $query = $_GET;
    unset($query['lang']);
    $path = strtok($_SERVER['REQUEST_URI'] ?? 'homePage.php', '?');
    $cleanUrl = $path . (!empty($query) ? '?' . http_build_query($query) : '');

    header('Location: ' . $cleanUrl);
    exit;
}

$current_lang = $_SESSION['lang'] ?? 'en';

$translations = [
    'en' => [
        'site_name'         => "Farmer's Market",
        'nav_home'          => 'Home',
        'nav_about'         => 'About',
        'nav_services'      => 'Services',
        'nav_login'         => 'Login',
        'hero_badge'        => 'Official Digital Agricultural Marketplace',
        'hero_title'        => "Farmer's Market",
        'hero_desc'         => 'A transparent digital bridge connecting local farmers, verified mill owners, and government agricultural officers.',
        'login_btn'         => 'Login to Dashboard',
        'register_btn'      => 'Create New Account',
        'notice_board'      => 'Government Notice Board',
        'mill_posts'        => 'Mill Crop Purchase Posts',
        'show_more'         => 'Show More',
        'login_heading'     => 'Login',
        'role_farmer'       => 'Farmer',
        'role_mill_owner'   => 'Mill Owner',
        'role_admin'        => 'Admin',
        'password_ph'       => 'Password',
        'remember_me'       => 'Remember me',
        'forgot_password'   => 'Forgot password?',
        'no_account'        => "Don't have an account?",
        'register_link'     => 'Register',
    ],
    'bn' => [
        'site_name'         => 'কৃষক বাজার',
        'nav_home'          => 'হোম',
        'nav_about'         => 'সম্পর্কে',
        'nav_services'      => 'সেবাসমূহ',
        'nav_login'         => 'লগইন',
        'hero_badge'        => 'সরকারি ডিজিটাল কৃষি বাজার প্ল্যাটফর্ম',
        'hero_title'        => 'কৃষক বাজার',
        'hero_desc'         => 'স্থানীয় কৃষক, যাচাইকৃত মিল মালিক এবং সরকারি কৃষি কর্মকর্তাদের সংযুক্ত করার একটি স্বচ্ছ ডিজিটাল প্ল্যাটফর্ম।',
        'login_btn'         => 'ড্যাশবোর্ডে লগইন করুন',
        'register_btn'      => 'নতুন অ্যাকাউন্ট তৈরি করুন',
        'notice_board'      => 'সরকারি নোটিশ বোর্ড',
        'mill_posts'        => 'মিলের ফসল ক্রয় পোস্ট',
        'show_more'         => 'আরও দেখুন',
        'login_heading'     => 'লগইন',
        'role_farmer'       => 'কৃষক',
        'role_mill_owner'   => 'মিল মালিক',
        'role_admin'        => 'অ্যাডমিন',
        'password_ph'       => 'পাসওয়ার্ড',
        'remember_me'       => 'মনে রাখুন',
        'forgot_password'   => 'পাসওয়ার্ড ভুলে গেছেন?',
        'no_account'        => 'অ্যাকাউন্ট নেই?',
        'register_link'     => 'রেজিস্ট্রেশন করুন',
    ],
];

function __t(string $key): string {
    global $translations, $current_lang;
    return $translations[$current_lang][$key] ?? $translations['en'][$key] ?? $key;
}

function lang_url(string $lang): string {
    $query = $_GET;
    $query['lang'] = $lang;
    $path = strtok($_SERVER['REQUEST_URI'] ?? 'homePage.php', '?');
    return htmlspecialchars($path . '?' . http_build_query($query), ENT_QUOTES, 'UTF-8');
}

function render_lang_toggle(string $extraClass = ''): void {
    global $current_lang;
    $targetLang = ($current_lang === 'en') ? 'bn' : 'en';
    $buttonText = ($current_lang === 'en') ? 'বাংলা' : 'English';
    $href = lang_url($targetLang);
    $classes = trim('lang-switch-btn ' . $extraClass);

    echo '<a href="' . $href . '" class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '" data-lang="' . $targetLang . '">'
        . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8')
        . '</a>';
}
