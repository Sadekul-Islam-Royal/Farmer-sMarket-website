# Repository Architecture & Complete Technical Documentation: Farmer's Market Portal

## 1. Executive Summary & System Overview

The **Farmer's Market Portal** (`farmers-market-portal`) is a comprehensive, role-based web application designed to digitize, regulate, and streamline agricultural commodity trading across local administrative jurisdictions in Bangladesh. Built using a server-side PHP architecture backed by a MySQL relational database and enhanced with vanilla JavaScript, the platform connects three primary stakeholders: agricultural producers (**Farmers**), processing enterprises (**Mill Owners**), and local government officials (**Admins**, including Upazila Nirbahi Officers, Industry & Commerce Officers, Agriculture Officers, and Super Admins). The system eliminates predatory middleman exploitation by providing a transparent, public marketplace where verified mill owners broadcast real-time crop procurement offers alongside state-mandated benchmark pricing circulars. Every transaction, regulatory directive, and user interaction is governed by strict administrative boundaries mapped to Bangladesh's regional hierarchy of districts and upazilas. Furthermore, the portal incorporates built-in bilingual localization supporting both English (`en`) and Bengali (`bn`) across all operational dashboards, ensuring accessibility for rural farmers as well as administrative staff. Through integrated real-time direct messaging, automated account suspension enforcement, official registry whitelisting, and closed-loop complaint resolution, the platform establishes an accountable digital ecosystem for agricultural governance.

---

## 2. Repository Directory & File Structure

The repository follows a modular, flat-root PHP architecture where each file corresponds to a dedicated functional module, role dashboard, authentication flow, or localization utility.

* **`homePage.php`**: Serves as the public-facing landing page, live marketplace preview, informational portal, and primary authentication entry point. It queries recent government notices and live mill crop purchase posts for public display, renders detailed multi-section "About" and "Services" views, and houses the modal login system for all three user roles.


* **`register.php`**: Implements a two-step, role-specific onboarding workflow with real-time asynchronous backend verification. It validates Farmers via personal and National Identity (NID) records, verifies Mill Owners against the official `register_mill` table with a three-attempt lockout safeguard, and authenticates prospective Admins against the government `authorized_officers` whitelist before hashing passwords with `PASSWORD_DEFAULT`.


* **`forgotPassword.php`**: Provides a four-stage password recovery pipeline utilizing a cryptographically random 5-digit One-Time Password (OTP) constrained by an 89-second expiration window. It dynamically routes verification codes via email for Admins and Mill Owners or via SMS gateway hooks for Farmers without linked email addresses.


* **`farmerProfile.php`**: Delivers the complete operational dashboard for registered Farmers. It features an SQL-level proximity ranking algorithm that prioritizes mill purchase offers within the farmer's home upazila and district, provides multi-parameter crop and location filtering, manages profile photo uploads, enables direct messaging with mill owners, and tracks formal grievances.


* **`MillProfile.php`**: Powers the procurement and compliance workspace for verified Mill Owners. It enables millers to publish, search, and delete crop purchase demands with explicit moisture and unit pricing criteria, enforces automated posting and farmer-messaging restrictions during active administrative suspensions, and separates inbox threads between farmers and government officers.


* **`adminPage.php`**: Acts as the central administrative command center for Upazila and District officers. It resolves hierarchical officer privileges dynamically from official designations, manages physical mill registries (`register_mill`), executes and lifts temporary mill account suspensions, processes officer transfer activation requests, publishes universal price circulars, issues targeted upazila directives, and resolves stakeholder complaints.


* **`manageAuthorizedOfficers.php`**: Provides a dedicated Super Admin interface for maintaining the government officer whitelist (`authorized_officers`). Super Admins use this module to register official officer codes, assign administrative designations and district/upazila jurisdictions, monitor registration completion flags, and update or revoke officer authorizations.


* **`lang.php`**: Implements session-persisted bilingual internationalization (`en` and `bn`) across the platform. It exposes the `__t()` translation lookup helper and the `lang_url()` query-preserving URL generator so users can toggle languages seamlessly without losing active dashboard tabs or filter states.


* **Associated Stylesheets & Auxiliary Endpoints**: The repository links modular CSS files (`homePage.css`, `registerStyle.css`, `forgotPassword.css`, `farmerProfileSytle.css`, `MillProfileStyle.css`, `adminProfileStyle.css`, `officerWhitelistStyle.css`) alongside `viewProfile.php` for cross-role public profile inspection and `logout.php` for session termination.



---

## 3. Relational Database Schema & Data Architecture

The application relies on a MySQL database (`if0_43517157_farmers_market`) utilizing the `InnoDB` storage engine and `utf8mb4` character encoding to support full Unicode text, including Bengali script. Several modules execute idempotent schema verification routines (`ensureAdminSchemaUpdates`, `ensureFarmerSchema`, `ensureMillOwnerSchema`, `ensureAdminRegistrySchema`, `ensureOfficerWhitelistSchema`) on initialization to guarantee required columns and tables exist.

| Table Name | Primary Key | Key Columns & Attributes | Functional Purpose |
| --- | --- | --- | --- |
| **`farmers`** | `id` (INT) | `name`, `nid`, `phone`, `village`, `upazila`, `district`, `password`, `profile_photo` | Stores registered agricultural producer profiles, NID credentials, normalized residential locations, and avatar paths.

 |
| **`mill_owners`** | `id` (INT) | `mill_id` / `mill_ID`, `mill_name`, `name`, `nid`, `phone`, `email`, `village`, `upazila`, `district`, `crop_types`, `password`, `profile_photo`, `account_status`, `suspended_until`, `active_complaint_no`, `suspension_reason` | Maintains active mill enterprise accounts, supported grain categories, login credentials, and real-time disciplinary suspension states.

 |
| **`admins`** | `id` (INT) | `name`, `phone`, `email`, `employee_id`, `designation`, `upazila`, `district`, `password`, `profile_photo`, `account_status`, `status_reason`, `status_updated_at`, `is_super_admin` | Stores verified government officials, hierarchical designations, assigned jurisdictions, and administrative lifecycle statuses.

 |
| **`authorized_officers`** | `id` (INT) | `officer_code` (UNIQUE), `full_name`, `designation`, `district`, `upazila`, `is_registered`, `created_at`, `updated_at` | Serves as the Super Admin whitelist controlling which government officials are permitted to register admin accounts.

 |
| **`register_mill`** | `id` (INT) | `mill_ID`, `mill_name`, `mill_owner`, `nid`, `phone`, `village`, `upazila`, `district`, `stsats` (`active`/`closed`), `date`, `registered_by` | Acts as the official upazila-level registry of licensed mills managed by Industry & Commerce Officers and queried during mill registration.

 |
| **`mill_post`** | `id` (INT) | `mill_id`, `mill_name`, `owner_name`, `phone`, `address`, `crop_name`, `crop_type`, `price`, `unit`, `crop_moisture`, `description`, `created_at` | Stores live crop procurement offers published by mill owners, including unit pricing, moisture thresholds, and delivery terms.

 |
| **`official_documents`** | `id` (INT) | `admin_id`, `uploader_name`, `uploader_designation`, `uploader_email`, `upazila`, `district`, `title`, `file_path`, `file_type`, `created_at` | Houses universal government notices and official crop price circulars uploaded as PDF or image files.

 |
| **`admin_orders`** | `id` (INT) | `admin_id`, `district`, `upazila`, `target_audience` (`farmer`/`mill_owner`/`both`), `title`, `details`, `file_path`, `file_type`, `created_at` | Stores jurisdiction-specific administrative directives targeted at local farmers, mill owners, or both audiences.

 |
| **`direct_messages`** | `id` (INT) | `sender_type`, `sender_id`, `receiver_type`, `receiver_id`, `message`, `created_at` | Persists chronological one-to-one chat logs between farmers and mill owners as well as mill owners and admins.

 |
| **`complaints`** | `id` (INT) | `sender_id`, `sender_name`, `sender_role`, `sender_phone`, `district`, `upazila`, `subject`, `description`, `status` (`Pending`/`Resolved`), `created_at` | Tracks formal grievances submitted by farmers and mill owners to designated upazila or district officials.

 |
| **`mill_suspensions`** | `id` (INT) | `mill_ID`, `mill_owner_id`, `upazila`, `district`, `complaint_no`, `reason`, `suspended_until`, `status` (`active`/`expired`/`lifted`), `imposed_by_id`, `imposed_by_name`, `imposed_by_designation`, `lifted_by_id`, `lifted_by_name`, `lifted_at`, `created_at` | Audits disciplinary suspensions imposed on mill accounts, tracking expiration dates and officer lifting priority.

 |
| **`mill_notifications`** | `id` (INT) | `mill_ID`, `mill_owner_id`, `event_type` (`SUSPENDED`/`REACTIVATED`), `complaint_no`, `title`, `message`, `created_at` | Delivers automated system alerts to mill owners whenever their account status changes due to suspension or reactivation.

 |
| **`admin_requests`** | `id` (INT) | `requester_admin_id`, `requester_name`, `requester_designation`, `request_target` (`uno`/`super_admin`), `request_type`, `target_upazila`, `target_district`, `requested_status`, `reason`, `status` (`pending`/`approved`/`rejected`), `reviewed_by_id`, `reviewed_by_name`, `created_at`, `reviewed_at` | Manages inter-upazila transfer activation requests and administrative status petitions routed to UNOs or Super Admins.

 |

---

## 4. Authentication, Whitelist Verification & Password Recovery

### Role-Based Login & Session Architecture

Authentication is initiated from `homePage.php` via an interactive modal dialog that dynamically adjusts its input validation and placeholder text based on the selected `user_role` radio button. Farmers authenticate using their registered mobile `phone` number, Mill Owners authenticate using their unique `mill_id`, and Admins authenticate using their official `email` address. Upon successful credential verification, the server initializes `$_SESSION['user_id']`, `$_SESSION['role']`, and `$_SESSION['username']`, routing each user to their respective workspace (`farmerProfile.php`, `MillProfile.php`, or `adminProfile.php` / `adminPage.php`).

### Two-Step Registration & Asynchronous Whitelist Verification (`register.php`)

The registration module enforces strict data integrity before allowing any user to reach the password creation step (`#step2`):

1. **Address Parsing & Phone Normalization**: The helper `parseAddressParts()` decomposes comma-separated address strings into discrete `village`, `upazila`, and `district` tokens. Concurrently, `normalizePhoneDigits()` strips non-numeric characters and converts international `+880` prefixes into standard `01...` local formats for consistent database matching.


2. **Farmer Onboarding**: Farmers submit their full name, NID card number, phone number, and village/upazila/district address, advancing directly to password creation where the phone number is locked as their read-only login username.


3. **Mill Owner Registry Verification & Three-Attempt Lockout**: When a Mill Owner clicks "Next", JavaScript sends an asynchronous `fetch()` POST request (`action=verify_mill_registry`) to `register.php`. The server queries `register_mill` by `mill_ID` and verifies that the mill's official status (`stsats`) is `active` and that the owner's name, NID, normalized phone number, village, upazila, and district match the government record. It also checks `mill_owners` to prevent duplicate accounts for the same mill, NID, or phone. Failed verification increments `$_SESSION['mill_verify_attempts']`; upon three consecutive failed attempts, the session counter resets and the browser is immediately redirected to `homePage.php` to deter brute-force enumeration.


4. **Admin Whitelist Verification**: Prospective Admins trigger an AJAX verification call (`action=verify_admin_registry`) that checks their `officer_code` against `authorized_officers`. Registration is permitted only if the officer code exists, `is_registered` is `0`, and the submitted `designation`, `full_name`, `upazila`, and `district` match the official whitelist entry. Upon final form submission, `authorized_officers.is_registered` is atomically updated to `1`.



### Multi-Channel 5-Digit OTP Password Recovery (`forgotPassword.php`)

The password reset workflow operates as a four-step state machine (`verify` → `otp` → `reset` → `done`) backed by `$_SESSION['pwd_reset']`:

1. **Identity Lookup**: Users select their role and provide two matching identifiers: Phone + NID for Farmers, Mill ID + Email/Phone for Mill Owners, or Email + Employee ID for Admins.


2. **Dynamic Channel Dispatch**: Once matched, `random_int(10000, 99999)` generates a 5-digit OTP valid for exactly 89 seconds (`time() + 89`). If the account has a valid email address (Admins and registered Mill Owners), `dispatchVerificationCode()` sends the OTP via PHP's `mail()` function. If no email is linked (such as Farmer accounts), the system routes the code via an SMS gateway dispatch hook and notifies the user of the phone number used.


3. **Real-Time Countdown & Resend**: A client-side JavaScript interval decrements the remaining seconds live on screen. When the 89-second window expires, the OTP input and "Verify Code" button are automatically disabled while the "Resend Verification Code" button becomes active to issue a fresh 5-digit code and reset the timer.


4. **Credential Update**: After validating the 5-digit code within the time limit, the user sets and confirms a new password, which updates the corresponding role table and clears the recovery session.



---

## 5. Role-Based Operational Modules & Business Logic

### Administrative Hierarchy & Governance (`adminPage.php` & `manageAuthorizedOfficers.php`)

The administrative module implements granular role-based access control derived dynamically from the officer's official designation via `resolveOfficerRoleFromDesignation()`:

* **Role Classification**: Designations containing keywords such as `super`, `dc`, `deputy commissioner`, `collector`, `divisional`, `site admin`, or `district agriculture` resolve to `super_admin`. Designations matching `industry`, `commerce`, `shilpa`, `banijjo`, or `banijya` resolve to `industry_commerce`. Designations containing `uno`, `upazila nirbahi`, or `nirbahi` resolve to `uno`, while agricultural terms resolve to `agriculture`.


* **Account Lifecycle Enforcement**: On every page load, `adminPage.php` verifies the logged-in officer's `account_status`. Accounts marked `retired` or `pending` are immediately logged out and redirected with an explanatory error message. Officers marked `suspended` or `on_leave` retain read-only access to their profile and request forms, but are blocked from issuing directives or suspending/reactivating mills. Officers marked `transferred` are prompted to submit an activation petition to their new Upazila UNO or Super Admin.


* **Officer Whitelist & Transfer Management**: Super Admins manage the master registry of government officials in `manageAuthorizedOfficers.php` (supporting create, edit, delete, and multi-filter search by ID, role, and district). Within `adminPage.php`, Super Admins can reassign any officer's upazila/district posting and account status (scoped to their own district if `has_district_scope` is active) and approve or reject `super_admin` petitions. UNOs can approve `transfer_activation` requests strictly for officers moving into their own upazila and manage the status (`active`, `transferred`, `retired`, `on_leave`, `suspended`) of subordinate upazila officers, while being restricted from altering fellow UNOs or Super Admins.


* **Physical Mill Registry CRUD**: Active Upazila Industry & Commerce Officers (`$is_industry_officer`) possess exclusive write permissions to insert, update (`active`/`closed`), and delete records in `register_mill` within their assigned upazila and district. All administrators can query any mill nationwide by `mill_ID` using the lookup tool.


* **Mill Disciplinary Suspensions & Officer Priority**: Any active admin can temporarily suspend a mill owner's account in their jurisdiction by recording a `complaint_no`, `reason`, and future `suspended_until` date in `mill_suspensions`. This updates `mill_owners.account_status` to `suspended` and dispatches a `SUSPENDED` alert via `pushMillNotification()`. To prevent conflicting administrative overrides, lifting a suspension early is restricted by priority rules: only the imposing officer (`imposed_by_id`) can reactivate the mill unless that officer's own account status is no longer `active` (e.g., transferred, retired, on leave, or suspended). Additionally, `syncExpiredSuspensions()` automatically expires past-due suspensions on page load and restores the mill owner's `active` status if no other active suspensions remain.



### Farmer Marketplace & Proximity Discovery (`farmerProfile.php`)

The Farmer Dashboard is engineered to maximize local price transparency and minimize logistics overhead:

* **SQL Proximity-Ranking Engine**: When querying `mill_post` joined with `mill_owners`, the query computes a dynamic `proximity_rank` column using a SQL `CASE` expression. Posts matching the farmer's registered `upazila` receive rank `1` (*Nearby (Upazila)*), posts matching the farmer's `district` receive rank `2` (*Same District*), and all other regional offers receive rank `3` (*Other Area*). Results are sorted by `proximity_rank ASC, mp.created_at DESC` so local buyers always appear first.


* **Multi-Faceted Filtering & Crop Categorization**: Farmers can filter offers simultaneously by `filter_district`, `filter_upazila` (dynamically populated for all 8 Rajshahi Division districts via JavaScript), `filter_crop` (`Paddy`, `Wheat`, `Corn`, `Others` mapped via SQL `LIKE` / `NOT LIKE` clauses), and free-text `search_query` across crop names, mill names, and administrative locations.


* **Interactive Modals & Direct Action Links**: Clicking "Show More" on any crop card launches `#postDetailsModal`, displaying moisture specifications, full terms, mill owner contact details, a direct link to `viewProfile.php`, and a one-click "Message" button that opens a direct chat thread with that mill owner.



### Mill Owner Procurement & Compliance (`MillProfile.php`)

The Mill Owner Dashboard balances commercial procurement tools with regulatory compliance:

* **Self-Healing Suspension Checks**: Upon loading `MillProfile.php`, the system compares `suspended_until` against the current date. If a suspension period has elapsed, the script automatically resets `mill_owners.account_status` to `active` and marks the corresponding `mill_suspensions` record as `expired`.


* **Restricted-State Enforcement**: While `$is_suspended` is true, a prominent warning banner displays the suspension end date, complaint number, and reason. The crop purchase post creation form is replaced with a restriction notice, and backend `POST` handlers block both `create_post` and `send_message` requests targeting `farmer` recipients. Crucially, suspended mill owners retain the ability to message `admin` accounts so they can submit clarifications or compliance proof to resolve their suspension.


* **Segmented Inbox & Unified Notifications**: The messaging interface aggregates active conversations with both farmers and admins, sorting contacts by most recent message timestamp (`last_msg_time DESC`) and providing client-side role filter buttons (`All`, `Farmers`, `Admins`). The notification tab separates disciplinary `Account Status Notifications` (`mill_notifications`) from general `System Notifications & Directives` (`admin_orders`).



---

## 6. Cross-Cutting Features: Communications, Grievances & Internationalization

### Direct Messaging & Closed-Loop Complaint Tracking

Across `adminPage.php`, `farmerProfile.php`, and `MillProfile.php`, direct messaging operates on a unified `direct_messages` schema using polymorphic `(sender_type, sender_id)` and `(receiver_type, receiver_id)` pairs. Chat windows automatically scroll to the latest message on load and distinguish outgoing bubbles from incoming messages with timestamps. For formal regulatory intervention, Farmers and Mill Owners submit grievances via the Complaint tab by selecting a target authority (`Upazila Agriculture Officer`, `Upazila Commerce Officer`, `Upazila Nirbahi Officer (UNO)`, or `District Food Controller`), specifying the district and upazila, and describing the issue. The system appends `[To: <Target Officer>]` to the subject line and routes the record to the corresponding local admin's complaint queue, where the admin can mark it `Resolved` with a single confirmation action.

### Bilingual Internationalization & Document Previews

Every authenticated workspace includes `lang.php` and renders a language switcher (`বাংলা` / `English`) in the top navigation bar. Clicking either button invokes `lang_url()`, which preserves all existing `$_GET` parameters (such as active tab `section`, active chat partner `chat_with`, or search filters) while updating `$_SESSION['lang']`. All headings, form labels, status badges, table headers, and confirmation dialogs dynamically render in the active language. Finally, universal notices (`official_documents`) and local directive attachments (`admin_orders`) are previewed inline using JavaScript modal viewers (`renderFilePreviewHtml`) that embed PDF documents via `<embed type="application/pdf">` and render image files (`JPG`, `PNG`, `WEBP`) directly inside the browser without requiring external downloads.

---

## 7. Security Considerations & Architectural Recommendations

* **Credential & Configuration Management**: Database credentials are currently hardcoded across individual PHP scripts; centralizing connection logic into a single `db_connect.php` configuration file backed by environment variables is recommended for maintainability and security.


* **Password Hashing Consistency**: While `register.php` hashes new passwords using `password_hash($rawPassword, PASSWORD_DEFAULT)`, `homePage.php` and `forgotPassword.php` currently compare and store plaintext passwords (`$password === $user['password']` and `UPDATE ... SET password = ?`). Updating `homePage.php` to use `password_verify()` and `forgotPassword.php` to store `password_hash()` digests will unify cryptographic security across the entire authentication lifecycle.


* **Input & Output Sanitization**: The codebase consistently uses `mysqli` prepared statements (`prepare()` / `bind_param()`) for parameterized queries and `htmlspecialchars()` with `ENT_QUOTES` and JavaScript `escapeHtml()` helpers to prevent SQL injection and Cross-Site Scripting (XSS). Adding CSRF tokens to state-changing `POST` forms and `GET` action links (`resolve_complaint_id`, `delete_post_id`, `delete_id`) will further harden administrative and user endpoints.
