<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/config.php';

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['database']['host'], $config['database']['port'], $config['database']['name']);
    $pdo = new PDO($dsn, $config['database']['user'], $config['database']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    echo "Connected to MySQL database [{$config['database']['name']}].\n";

    // Safe migration of columns on `posts` table
    $columns = $pdo->query("SHOW COLUMNS FROM posts")->fetchAll(PDO::FETCH_COLUMN);
    $desiredColumns = [
        'image_url' => "ALTER TABLE posts ADD COLUMN image_url VARCHAR(500) NULL AFTER featured_media_id",
        'image_alt' => "ALTER TABLE posts ADD COLUMN image_alt VARCHAR(255) NULL AFTER image_url",
        'image_caption' => "ALTER TABLE posts ADD COLUMN image_caption VARCHAR(255) NULL AFTER image_alt",
        'tags' => "ALTER TABLE posts ADD COLUMN tags VARCHAR(255) NULL AFTER author_id",
        'views' => "ALTER TABLE posts ADD COLUMN views INT UNSIGNED NOT NULL DEFAULT 0 AFTER tags",
        'is_featured' => "ALTER TABLE posts ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER views",
        'allow_comments' => "ALTER TABLE posts ADD COLUMN allow_comments TINYINT(1) NOT NULL DEFAULT 1 AFTER is_featured",
        'focus_keyword' => "ALTER TABLE posts ADD COLUMN focus_keyword VARCHAR(120) NULL AFTER seo_description",
        'og_title' => "ALTER TABLE posts ADD COLUMN og_title VARCHAR(255) NULL AFTER canonical_url",
        'og_description' => "ALTER TABLE posts ADD COLUMN og_description VARCHAR(320) NULL AFTER og_title",
        'robots_index' => "ALTER TABLE posts ADD COLUMN robots_index TINYINT(1) NOT NULL DEFAULT 1 AFTER og_image_id",
        'robots_follow' => "ALTER TABLE posts ADD COLUMN robots_follow TINYINT(1) NOT NULL DEFAULT 1 AFTER robots_index",
    ];

    foreach ($desiredColumns as $colName => $alterSql) {
        if (!in_array($colName, $columns, true)) {
            $pdo->exec($alterSql);
            echo "Added missing column '{$colName}' to posts table.\n";
        }
    }

    // Ensure public inquiry storage exists for website forms.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inquiries (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          type ENUM('general','conversation','visa','automation','advisory','newsletter') NOT NULL DEFAULT 'general',
          name VARCHAR(160) NULL,
          email VARCHAR(190) NOT NULL,
          company VARCHAR(190) NULL,
          phone VARCHAR(80) NULL,
          subject VARCHAR(190) NULL,
          message TEXT NULL,
          metadata JSON NULL,
          ip_hash BINARY(32) NULL,
          user_agent VARCHAR(500) NULL,
          status ENUM('new','contacted','closed','spam') NOT NULL DEFAULT 'new',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX inquiries_status_created_idx (status, created_at),
          INDEX inquiries_email_idx (email)
        ) ENGINE=InnoDB
    ");

    // 1. Roles & Permissions
    $pdo->exec("
        INSERT INTO roles (id, name, description) VALUES
            (1, 'Super Admin', 'Full system access'),
            (2, 'Admin', 'Content and settings access'),
            (3, 'Editor', 'Posts and pages access'),
            (4, 'Author', 'Own posts access'),
            (5, 'User', 'Profile only')
        ON DUPLICATE KEY UPDATE description = VALUES(description)
    ");

    // 2. Initial Admin User
    // SECURITY: No hardcoded password. A cryptographically random password is generated
    // at seed-time and printed ONCE. Copy it immediately — it is never stored in plaintext.
    $adminEmail = 'admin@apexbusinessksa.com';
    $checkUser = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $checkUser->execute([$adminEmail]);
    $userId = $checkUser->fetchColumn();
    if (!$userId) {
        $randomPassword = bin2hex(random_bytes(10)); // 20 hex chars, ~160-bit entropy
        $userStmt = $pdo->prepare("INSERT INTO users (role_id, email, password_hash, display_name, status) VALUES (1, ?, ?, ?, 'active')");
        $userStmt->execute([$adminEmail, password_hash($randomPassword, PASSWORD_BCRYPT, ['cost' => 12]), 'Apex Administrator']);
        $userId = (int) $pdo->lastInsertId();
        echo "\n================================================================\n";
        echo "  INITIAL ADMIN ACCOUNT CREATED — COPY CREDENTIALS IMMEDIATELY  \n";
        echo "================================================================\n";
        echo "  Email   : {$adminEmail}\n";
        echo "  Password: {$randomPassword}\n";
        echo "================================================================\n";
        echo "  This password is shown ONCE and is NOT stored anywhere.        \n";
        echo "  Change it immediately after first login via the CMS.           \n";
        echo "================================================================\n\n";
    } else {
        echo "Admin user already exists, skipping creation: {$adminEmail}\n";
    }

    // 3. Categories
    $categories = [
        ['name' => 'Market Entry', 'slug' => 'market-entry'],
        ['name' => 'Advisory', 'slug' => 'advisory'],
        ['name' => 'Learning', 'slug' => 'learning'],
        ['name' => 'Tax & Compliance', 'slug' => 'tax-compliance'],
        ['name' => 'AI & Automation', 'slug' => 'ai-automation']
    ];
    $catStmt = $pdo->prepare("INSERT INTO categories (name, slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)");
    foreach ($categories as $cat) {
        $catStmt->execute([$cat['name'], $cat['slug']]);
    }
    echo "Seeded " . count($categories) . " categories.\n";

    // 4. Initial Articles / Posts
    $initialPosts = [
        [
            'title' => 'Entering the Saudi Market: Investment Registration, Entity Setup & Operating Realities',
            'slug' => 'a-practical-guide-to-entering-the-saudi-market',
            'description' => 'A current executive guide to Saudi market entry, including MISA investment registration, entity formation, sector-specific approvals, tax setup, banking, and operating readiness.',
            'content' => '<p>Entering Saudi Arabia can create significant growth opportunities, but a durable setup depends on sequencing investment, corporate, tax, labor, banking, and sector requirements correctly. The updated Saudi Investment Law is especially important because it replaced the former foreign-investor licensing procedure with an <strong>investment registration mechanism</strong>.</p><h2>1. Confirm the activity and investment-registration path</h2><p>A foreign investor must register with the Ministry of Investment (MISA) before carrying out investment activities, subject to the Investment Law and its Executive Regulations. Some activities are restricted or governed by additional sector rules, so the first task is to classify the proposed activity and identify any competent-authority approvals.</p><ul><li><strong>Entity choice:</strong> Select a structure that matches ownership, liability, governance, contracting, and tax needs.</li><li><strong>Restricted or regulated activities:</strong> Identify prior approvals and sector-specific licensing before committing to a launch timeline.</li><li><strong>Regional Headquarters considerations:</strong> Multinational groups should evaluate RHQ rules and incentives separately from ordinary operating-company formation.</li></ul><blockquote>The right sequence is more important than moving every workstream at once.</blockquote><h2>2. Establish the company and statutory registrations</h2><p>After the relevant investment-registration step, the business can progress through company establishment and Commercial Registration procedures, then complete the statutory registrations that apply to its activity. These may include Chamber of Commerce, ZATCA, labor platforms such as Qiwa, immigration services such as Muqeem, GOSI, municipal requirements, and sector regulators.</p><h2>3. Prepare banking and beneficial-ownership documentation</h2><p>Bank onboarding is a separate KYC process. Expect requests for corporate documents, ownership and ultimate beneficial owner information, authorized signatory evidence, source-of-funds context, and other documents required by the bank and the specific structure.</p><h2>4. Build compliance into the operating model</h2><p>Formation is only the beginning. Maintain a compliance calendar covering tax, e-invoicing, labor, immigration, corporate records, licenses and permits, renewals, and management reporting. Requirements vary by activity and can change, so operating teams should verify current rules with the relevant authority.</p><p><strong>Source note:</strong> Reviewed against the Saudi Ministry of Investment\'s updated Investment Law and 2026 Investor Guide. This article is general information, not legal or tax advice.</p>',
            'status' => 'published',
            'image_url' => 'https://images.unsplash.com/photo-1580674684081-7617fbf3d745?auto=format&fit=crop&w=1400&q=85',
            'image_alt' => 'Modern commercial tower in King Abdullah Financial District, Riyadh',
            'image_caption' => 'Saudi market entry requires the right investment registration, entity structure, sector approvals, tax setup, and operating controls.',
            'tags' => 'Saudi Market Entry, MISA Registration, Business Setup, Commercial Registration, ZATCA, Riyadh, Investment Law',
            'views' => 1420,
            'is_featured' => 1,
            'allow_comments' => 1,
            'published_at' => '2026-08-15 10:00:00',
            'seo_title' => 'Saudi Market Entry: MISA Registration & Entity Setup Guide | Apex',
            'seo_description' => 'Current Saudi market-entry guide covering MISA investment registration, entity setup, sector approvals, tax, banking, labor, and operating readiness.',
            'focus_keyword' => 'Saudi market entry',
            'canonical_url' => 'https://apexbusinessksa.com/insights/a-practical-guide-to-entering-the-saudi-market',
            'og_title' => 'Entering the Saudi Market: Investment Registration, Entity Setup & Operating Realities',
            'og_description' => 'Current guidance on MISA investment registration, entity setup, sector approvals, tax, banking, and operating readiness.',
            'category_slug' => 'market-entry'
        ],
        [
            'title' => 'The Operating Discipline Behind Durable Growth: Frameworks for GCC Leadership Teams',
            'slug' => 'the-operating-discipline-behind-durable-growth',
            'description' => 'How executive leadership teams translate corporate strategy into disciplined weekly rhythms, transparent KPIs, and resilient regional operations in Saudi Arabia.',
            'content' => "<p>In high-velocity business environments like Saudi Arabia and the broader GCC, rapid expansion often exposes operational friction. Strategic vision is critical, but sustainable market value is built on the unglamorous foundation of disciplined, repeatable operational execution.</p><h2>The Core Elements of Operating Discipline</h2><p>High-performing leadership teams distinguish themselves by implementing structured operational rhythms that connect top-level corporate objectives with frontline execution:</p><ul><li><strong>Weekly Milestone Cadence:</strong> Replacing lengthy, unfocused status meetings with structured 30-minute operational standups focused exclusively on blockers, cross-department dependencies, and delivery velocity.</li><li><strong>Real-Time Financial &amp; Regulatory Visibility:</strong> Maintaining rolling 13-week cash forecasts, ZATCA tax calendars, and proactive Saudization metric tracking rather than reacting at fiscal quarter ends.</li><li><strong>Clear Single-Owner Accountability:</strong> Assigning unambiguous executive ownership for every government approval, commercial contract milestone, and technology deployment.</li></ul><blockquote>Execution is not an occasional burst of energy; it is the daily discipline of turning strategic ambition into reliable operating systems.</blockquote><h2>Navigating Growth While Maintaining Compliance</h2><p>As regulatory frameworks in the Kingdom continuously evolve—from electronic invoicing mandates to municipal compliance standards—executive teams must build adaptive operational reflexes. Integrating regular compliance audits into core business reviews ensures regulatory readiness without interrupting commercial momentum.</p><h2>Building for Long-Term Durability</h2><p>By establishing rigorous internal controls, clear reporting pipelines, and dedicated administrative support, growing enterprises build the resilience required to scale across Riyadh, Jeddah, and the wider Gulf region with sustained profitability.</p>",
            'status' => 'published',
            'image_url' => 'https://images.unsplash.com/photo-1556761175-b413da4baf72?auto=format&fit=crop&w=1200&q=85',
            'image_alt' => 'Executive strategy and operational planning meeting',
            'image_caption' => 'Operational cadence and cross-functional discipline driving sustainable business growth.',
            'tags' => 'Operations, Leadership, Growth, Corporate Governance, Strategy, Execution',
            'views' => 890,
            'is_featured' => 0,
            'allow_comments' => 1,
            'published_at' => '2026-08-10 09:00:00',
            'seo_title' => 'Operating Discipline for Durable Growth | Apex Business Consultancy',
            'seo_description' => 'How executive leadership teams implement operational discipline for sustainable business growth.',
            'focus_keyword' => 'operating discipline',
            'canonical_url' => 'https://apexbusinessksa.com/insights/the-operating-discipline-behind-durable-growth',
            'og_title' => 'The Operating Discipline Behind Durable Growth',
            'og_description' => 'Building long-term operational resilience in fast-growing economies.',
            'category_slug' => 'advisory'
        ],
        [
            'title' => 'GCC Unified Tourist Visa: What Is Confirmed, What Is Still Pending, and Why It Matters',
            'slug' => 'gcc-grand-tours-visa-unified-gulf-mobility-guide',
            'description' => 'An evidence-based status guide to the planned GCC unified tourist visa, separating confirmed regional policy from launch details that travelers and businesses should not assume are final.',
            'content' => '<p>The GCC unified tourist visa is an approved regional initiative intended to make multi-country tourism easier across Saudi Arabia, the United Arab Emirates, Bahrain, Qatar, Oman, and Kuwait. The important distinction is between <strong>approval of the initiative</strong> and <strong>full public activation of a GCC-wide visa service</strong>.</p><h2>What is confirmed</h2><ul><li>GCC authorities have continued work toward a unified tourist visa and a more integrated regional travel experience.</li><li>Technical, security, immigration, and operational coordination between member states is central to implementation.</li><li>The strategic objective is to make the GCC easier to experience as a multi-destination region.</li></ul><h2>What businesses and travelers should not assume</h2><p>Until competent authorities publish a live application service and final rules, do not treat unofficial claims about exact fees, universal eligibility, processing times, or fixed 30–90 day validity as guaranteed. Current entry requirements for each destination remain the operational baseline.</p><blockquote>Build travel plans around rules that are live today; treat the unified visa as a future efficiency until official activation is verifiable.</blockquote><h2>Why the initiative still matters for business</h2><p>A functioning multi-country visa could reduce planning friction for regional conferences, investor meetings, tourism itineraries, and executive travel. Companies operating across the Gulf can prepare now by standardizing traveler documentation, maintaining clear mobility policies, and monitoring official immigration updates rather than designing operations around unconfirmed launch assumptions.</p><p><strong>Editorial note:</strong> Status reviewed against GCC General Secretariat announcements. Entry rules can change quickly; confirm requirements with the relevant government authority before travel.</p>',
            'status' => 'published',
            'image_url' => 'https://images.unsplash.com/photo-1512453979798-5ea266f8880c?auto=format&fit=crop&w=1400&q=85',
            'image_alt' => 'Modern skyline of GCC capital connecting business and travel',
            'image_caption' => 'The GCC unified tourist visa is a regional integration initiative; travelers should continue using current country-specific entry rules until official activation is confirmed.',
            'tags' => 'GCC Unified Tourist Visa, GCC Travel, Saudi Arabia, UAE, Bahrain, Qatar, Oman, Kuwait, Regional Mobility',
            'views' => 1640,
            'is_featured' => 1,
            'allow_comments' => 1,
            'published_at' => '2026-08-20 10:00:00',
            'seo_title' => 'GCC Unified Tourist Visa Status & Business Guide | Apex',
            'seo_description' => 'Current-status guide to the GCC unified tourist visa: what is officially confirmed, what remains pending, and how businesses should prepare.',
            'focus_keyword' => 'GCC unified tourist visa',
            'canonical_url' => 'https://apexbusinessksa.com/insights/gcc-grand-tours-visa-unified-gulf-mobility-guide',
            'og_title' => 'GCC Unified Tourist Visa: What Is Confirmed and What Is Still Pending',
            'og_description' => 'An evidence-based status guide separating confirmed GCC policy from launch details that remain subject to official publication.',
            'category_slug' => 'market-entry'
        ],
        [
            'title' => 'GCC Unified Tourist Visa for Residents: Current Status and a Practical Readiness Checklist',
            'slug' => 'gcc-unified-visa-for-residents-requirements-guide',
            'description' => 'What GCC residents can prepare now—and which eligibility, validity, fee, and application details should be verified only when official unified-visa rules are published.',
            'content' => '<p>GCC residents are an important audience for regional mobility, but a professional travel plan should distinguish between existing country-specific resident visa policies and the planned GCC unified tourist visa. Final unified-visa eligibility rules should be taken only from the competent authorities when the service is formally activated.</p><h2>Do not rely on unofficial eligibility lists</h2><p>Claims about profession-based fast tracks, minimum residence validity, guaranteed approval windows, exact fees, or automatic multi-entry rights may circulate before final rules are published. Treat those details as provisional unless they appear on an official government or GCC channel.</p><h2>A readiness checklist that is useful today</h2><ul><li><strong>Passport:</strong> Keep sufficient validity for the destination and travel period.</li><li><strong>Residence status:</strong> Make sure your GCC residence permit is active and can be verified digitally where applicable.</li><li><strong>Identity and supporting documents:</strong> Keep clear copies of passport, residence card, employment or company information, accommodation, and itinerary documents.</li><li><strong>Insurance:</strong> Check the destination\'s current insurance requirements rather than assuming a future unified policy.</li><li><strong>Current entry rules:</strong> Verify each country separately until a GCC-wide service is officially live.</li></ul><h2>For corporate travel teams</h2><p>Maintain a country-by-country matrix for employee nationality, residency, destination, purpose of travel, and required permit. When the unified service becomes operational, that matrix can be updated without disrupting existing compliance workflows.</p><blockquote>Readiness is useful; guessing future visa rules is not. Verify the live rule at the point of application.</blockquote><p><strong>Editorial note:</strong> This article is informational and does not represent an immigration authority decision or guarantee of visa approval.</p>',
            'status' => 'published',
            'image_url' => 'https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=1200&q=85',
            'image_alt' => 'International business traveler preparing travel documents',
            'image_caption' => 'A practical readiness framework for GCC residents while final unified-visa rules remain subject to official publication.',
            'tags' => 'GCC Residents, Unified Tourist Visa, Expat Mobility, Travel Compliance, Business Travel, GCC',
            'views' => 1280,
            'is_featured' => 0,
            'allow_comments' => 1,
            'published_at' => '2026-08-18 11:30:00',
            'seo_title' => 'GCC Unified Tourist Visa for Residents: Current Status | Apex',
            'seo_description' => 'A practical readiness checklist for GCC residents, with clear separation between current entry rules and pending unified-tourist-visa details.',
            'focus_keyword' => 'GCC unified visa residents',
            'canonical_url' => 'https://apexbusinessksa.com/insights/gcc-unified-visa-for-residents-requirements-guide',
            'og_title' => 'GCC Unified Tourist Visa for Residents: Current Status & Readiness Checklist',
            'og_description' => 'A practical readiness checklist for GCC residents while final unified-visa rules remain subject to official publication.',
            'category_slug' => 'tax-compliance'
        ],
        [
            'title' => 'UAE–Bahrain Single Entry Point Pilot: What the First Phase Means for GCC Travel',
            'slug' => 'one-stop-border-entry-and-aviation-integration-gcc',
            'description' => 'What is actually live in the UAE–Bahrain Single Entry Point pilot, what the first phase changes, and why broader GCC expansion should not be assumed ahead of official rollout decisions.',
            'content' => '<p>On 17 February 2026, the United Arab Emirates and Bahrain launched the first phase of the GCC <strong>Single Entry Point for Air Travel</strong> project. The GCC General Secretariat described it as a pilot implementation under a resolution of GCC interior ministers.</p><h2>What the first phase means</h2><p>The project is designed to streamline selected travel procedures between the participating states by completing agreed checks through a coordinated process. That is materially different from claiming that all GCC airports, land borders, customs systems, or immigration processes have already become a single domestic-style travel zone.</p><h2>What is confirmed—and what is not</h2><ul><li><strong>Confirmed:</strong> the first-phase UAE–Bahrain air-travel pilot launched in February 2026.</li><li><strong>Confirmed:</strong> GCC authorities intend to study expansion based on the readiness of each member state.</li><li><strong>Not safe to assume:</strong> universal GCC rollout, fixed time-savings percentages, or a new unified aviation authority unless announced by the competent authorities.</li></ul><h2>Business implications</h2><p>For companies with teams moving frequently between Gulf markets, the pilot is a useful signal that travel processes may become more integrated over time. Travel policies should still be built around current border, visa, security, and customs rules for each route.</p><blockquote>Pilot programs are strategically important, but operating policies should change only when the relevant route and procedure are officially active.</blockquote><p><strong>Source note:</strong> Reviewed against the GCC General Secretariat announcement dated 18 February 2026.</p>',
            'status' => 'published',
            'image_url' => 'https://images.unsplash.com/photo-1530521954074-e64f6810b32d?auto=format&fit=crop&w=1200&q=85',
            'image_alt' => 'Modern international airport terminal with passenger jets',
            'image_caption' => 'The UAE–Bahrain Single Entry Point for Air Travel launched its first phase in February 2026 as a GCC pilot project.',
            'tags' => 'UAE, Bahrain, Single Entry Point, GCC Travel, Aviation, Corporate Mobility, Border Process',
            'views' => 950,
            'is_featured' => 0,
            'allow_comments' => 1,
            'published_at' => '2026-08-14 09:15:00',
            'seo_title' => 'UAE–Bahrain Single Entry Point Pilot Explained | Apex',
            'seo_description' => 'A factual guide to the February 2026 UAE–Bahrain Single Entry Point for Air Travel pilot and what it does—and does not—mean for GCC travel.',
            'focus_keyword' => 'UAE Bahrain single entry point',
            'canonical_url' => 'https://apexbusinessksa.com/insights/one-stop-border-entry-and-aviation-integration-gcc',
            'og_title' => 'UAE–Bahrain Single Entry Point Pilot: What the First Phase Means',
            'og_description' => 'A factual guide to the February 2026 UAE–Bahrain Single Entry Point for Air Travel pilot and its current scope.',
            'category_slug' => 'advisory'
        ],
        [
            'title' => 'GCC Tourism Integration: How Hospitality Operators Can Prepare for More Multi-Destination Travel',
            'slug' => 'gcc-tourism-and-hospitality-investment-surge-2026',
            'description' => 'A scenario-based operating guide for hospitality and tourism businesses preparing for deeper GCC travel integration—without treating unconfirmed visa forecasts as established facts.',
            'content' => '<p>Regional tourism integration could make multi-destination Gulf itineraries easier over time, creating opportunities for hotels, destination operators, event organizers, airlines, and mobility providers. The commercial opportunity is real, but investment decisions should not depend on unverified claims about a unified visa launch date, guaranteed visitor growth, or fixed revenue forecasts.</p><h2>Plan for scenarios, not headlines</h2><p>Hospitality operators can model three cases: current country-by-country entry rules, a phased regional integration scenario, and a mature multi-country travel environment. This keeps capital and operating decisions resilient if rollout timing changes.</p><h2>Practical moves that create value now</h2><ul><li><strong>Multi-city partnerships:</strong> Build referral relationships with hotels, tour operators, and transport providers in complementary GCC destinations.</li><li><strong>Flexible booking design:</strong> Make itinerary changes, split stays, and corporate group bookings easier to manage.</li><li><strong>Shared customer data discipline:</strong> Prepare consent, privacy, and CRM practices for cross-border partnerships.</li><li><strong>MICE readiness:</strong> Package accommodation, airport transfer, meeting space, and local support for regional business events.</li><li><strong>Demand monitoring:</strong> Track actual booking origin, length of stay, route mix, and conversion rather than relying on headline forecasts.</li></ul><h2>Investment lens</h2><p>Asset managers should stress-test occupancy, rate, seasonality, route capacity, and regulatory assumptions separately. A unified visa, if and when fully activated, should be treated as an upside case rather than the only justification for an investment.</p><blockquote>Good regional strategy captures integration upside without making the business dependent on a policy timeline it cannot control.</blockquote>',
            'status' => 'published',
            'image_url' => 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1200&q=85',
            'image_alt' => 'Luxury hotel and urban resort development in the Gulf',
            'image_caption' => 'Hospitality operators can prepare for deeper regional travel integration without relying on unconfirmed visa timelines or forecasts.',
            'tags' => 'Hospitality, Tourism, GCC, Saudi Arabia, UAE, Oman, Investment, Multi-Destination Travel',
            'views' => 870,
            'is_featured' => 0,
            'allow_comments' => 1,
            'published_at' => '2026-08-12 14:40:00',
            'seo_title' => 'GCC Tourism Integration: Hospitality Strategy Guide | Apex',
            'seo_description' => 'How hospitality operators and investors can prepare for multi-destination GCC travel using scenario planning and measurable operating data.',
            'focus_keyword' => 'GCC tourism integration',
            'canonical_url' => 'https://apexbusinessksa.com/insights/gcc-tourism-and-hospitality-investment-surge-2026',
            'og_title' => 'GCC Tourism Integration: A Hospitality Readiness Guide',
            'og_description' => 'How hospitality operators can prepare for deeper GCC travel integration using scenario planning and measurable operating data.',
            'category_slug' => 'advisory'
        ]
    ];

    $postStmt = $pdo->prepare("
        INSERT INTO posts (
            title, slug, description, content, status, image_url, image_alt, image_caption,
            author_id, tags, views, is_featured, allow_comments, published_at, seo_title,
            seo_description, focus_keyword, canonical_url, og_title, og_description, robots_index, robots_follow
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            content = VALUES(content),
            image_url = VALUES(image_url),
            image_alt = VALUES(image_alt),
            image_caption = VALUES(image_caption),
            tags = VALUES(tags),
            views = VALUES(views),
            is_featured = VALUES(is_featured),
            published_at = VALUES(published_at),
            seo_title = VALUES(seo_title),
            seo_description = VALUES(seo_description),
            focus_keyword = VALUES(focus_keyword)
    ");

    $catMapQuery = $pdo->query("SELECT id, slug FROM categories");
    $catMap = [];
    while ($row = $catMapQuery->fetch()) {
        $catMap[$row['slug']] = (int) $row['id'];
    }

    $linkCatStmt = $pdo->prepare("INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)");

    foreach ($initialPosts as $p) {
        $postStmt->execute([
            $p['title'],
            $p['slug'],
            $p['description'],
            $p['content'],
            $p['status'],
            $p['image_url'],
            $p['image_alt'],
            $p['image_caption'],
            $userId,
            $p['tags'],
            $p['views'],
            $p['is_featured'],
            $p['allow_comments'],
            $p['published_at'],
            $p['seo_title'],
            $p['seo_description'],
            $p['focus_keyword'],
            $p['canonical_url'],
            $p['og_title'],
            $p['og_description']
        ]);

        $postId = (int) $pdo->query("SELECT id FROM posts WHERE slug = " . $pdo->quote($p['slug']))->fetchColumn();
        if ($postId && isset($catMap[$p['category_slug']])) {
            $linkCatStmt->execute([$postId, $catMap[$p['category_slug']]]);
        }
    }
    echo "Seeded " . count($initialPosts) . " initial publications into MySQL posts table.\n";

    // 4.1 Pages
    $initialPages = [
        [
            'title' => 'Start a Conversation',
            'slug' => 'conversation',
            'content' => '<h2>Start a meaningful conversation</h2><p>Whether you are expanding into Saudi Arabia, evaluating an investment opportunity, or resolving operational hurdles, our Riyadh team provides grounded, practical guidance.</p>',
            'status' => 'published',
            'seo_title' => 'Start a Conversation | Apex Business Consultancy',
            'seo_description' => 'Initiate an exploratory consultation with Apex Business Consultancy in Riyadh. Discuss your market entry, licensing, operational growth, or AI automation goals.',
            'canonical_url' => 'https://apexbusinessksa.com/conversation'
        ],
        [
            'title' => 'Visa Assistance Services',
            'slug' => 'visa-assistance',
            'content' => '<h2>Visa Assistance Services</h2><p>Navigate international visa and residency requirements with dedicated case coordination, document verification, and appointment scheduling support.</p>',
            'status' => 'published',
            'seo_title' => 'Visa Assistance Services | Business, Work & Travel | Apex Business Consultancy',
            'seo_description' => 'Dedicated visa and immigration assistance for investors, professionals, executives, and families moving to Saudi Arabia, GCC, and key international destinations.',
            'canonical_url' => 'https://apexbusinessksa.com/visa-assistance'
        ],
        [
            'title' => 'AI Workflow Automation',
            'slug' => 'automation',
            'content' => '<h2>Automate Your Business Operations</h2><p>Eliminate repetitive manual tasks, automate customer communication, and connect your business systems into high-velocity intelligent workflows.</p>',
            'status' => 'published',
            'seo_title' => 'AI Workflow Automation | Enterprise Solutions | Apex Business Consultancy',
            'seo_description' => 'Transform repetitive business tasks into automated workflows. AI-driven customer communications, CRM integrations, and operational pipelines tailored for GCC businesses.',
            'canonical_url' => 'https://apexbusinessksa.com/automation'
        ],
        [
            'title' => 'Schedule an Advisory Session',
            'slug' => 'advisory-session',
            'content' => '<h2>Schedule an Advisory Session</h2><p>Book a private, highly structured strategic consultation with our senior advisors in Riyadh to evaluate your market expansion, entity structuring, and operational roadmap.</p>',
            'status' => 'published',
            'seo_title' => 'Schedule an Advisory Session | Executive Guidance | Apex Business Consultancy',
            'seo_description' => 'Book a dedicated one-on-one strategic advisory session with our senior consultants in Riyadh. Get immediate clarity on market entry, corporate structuring, and regulatory milestones.',
            'canonical_url' => 'https://apexbusinessksa.com/advisory-session'
        ],
        [
            'title' => 'Meet Our Advisors',
            'slug' => 'advisors',
            'content' => '<h2>Meet with Our Senior Advisors</h2><p>Our multidisciplinary advisory team combines deep Saudi regulatory mastery, corporate structuring experience, cross-border mobility expertise, and operational discipline.</p>',
            'status' => 'published',
            'seo_title' => 'Meet Our Advisors | Senior Leadership & Consultants | Apex Business Consultancy',
            'seo_description' => 'Meet the senior business consultants, regulatory specialists, and operations advisors at Apex Business Consultancy in King Abdullah Financial District, Riyadh.',
            'canonical_url' => 'https://apexbusinessksa.com/advisors'
        ],
        [
            'title' => 'About Apex Business Consultancy',
            'slug' => 'about',
            'content' => '<h2>Better context for meaningful moves</h2><p>Apex Business Consultancy helps founders, investors, and enterprise leadership teams turn Saudi market complexity into a practical, highly compliant path forward.</p>',
            'status' => 'published',
            'seo_title' => 'About Apex Business Consultancy | Local Context & Strategic Execution in Riyadh',
            'seo_description' => 'Learn how Apex Business Consultancy supports founders, corporate investors, and leadership teams navigating market entry and operational growth in Saudi Arabia.',
            'canonical_url' => 'https://apexbusinessksa.com/about'
        ],
        [
            'title' => 'Services',
            'slug' => 'services',
            'content' => '<h2>Practical support for the moves that matter</h2><p>From entering Saudi Arabia to improving the way your business operates, Apex brings local context, professional support, and practical technology into one working relationship.</p>',
            'status' => 'published',
            'seo_title' => 'Services | Market Entry, PRO, Visas, Tax & AI Automation | Apex Business Consultancy',
            'seo_description' => 'Explore Apex Business Consultancy services: MISA investment registration, investment advisory, PRO/GRO support, visa assistance, tax return guidance, audit review, trading education, and AI workflow automation.',
            'canonical_url' => 'https://apexbusinessksa.com/services'
        ],
        [
            'title' => 'Contact Apex Business Consultancy',
            'slug' => 'contact',
            'content' => '<h2>Bring us your key priorities</h2><p>Whether you are establishing a new entity in Saudi Arabia, evaluating an investment opportunity, or resolving operational hurdles, our Riyadh team is ready to assist.</p>',
            'status' => 'published',
            'seo_title' => 'Contact Apex Business Consultancy | Riyadh, Saudi Arabia',
            'seo_description' => 'Get in touch with Apex Business Consultancy in King Abdullah Financial District, Riyadh. Start a conversation regarding market entry, licensing, tax, or AI automation.',
            'canonical_url' => 'https://apexbusinessksa.com/contact'
        ]
    ];

    $pageStmt = $pdo->prepare("
        INSERT INTO pages (title, slug, content, status, seo_title, seo_description, canonical_url, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            content = VALUES(content),
            status = VALUES(status),
            seo_title = VALUES(seo_title),
            seo_description = VALUES(seo_description),
            canonical_url = VALUES(canonical_url)
    ");

    foreach ($initialPages as $pg) {
        $pageStmt->execute([
            $pg['title'],
            $pg['slug'],
            $pg['content'],
            $pg['status'],
            $pg['seo_title'],
            $pg['seo_description'],
            $pg['canonical_url'],
            $userId
        ]);
    }
    echo "Seeded " . count($initialPages) . " core pages into MySQL pages table.\n";

    // 4.2 Initial Site Settings
    $initialSettings = [
        ['setting_key' => 'site_name', 'setting_value' => 'Apex Business Consultancy', 'value_type' => 'string'],
        ['setting_key' => 'tagline', 'setting_value' => 'Strategic Clarity for Business Growth in Saudi Arabia', 'value_type' => 'string'],
        ['setting_key' => 'favicon', 'setting_value' => 'favicon.svg', 'value_type' => 'string'],
        ['setting_key' => 'primary_color', 'setting_value' => '#07152f', 'value_type' => 'string'],
        ['setting_key' => 'accent_color', 'setting_value' => '#58c748', 'value_type' => 'string'],
        ['setting_key' => 'support_email', 'setting_value' => 'hello@apexbusinessksa.com', 'value_type' => 'string'],
        ['setting_key' => 'support_phone', 'setting_value' => '+966 11 555 0142', 'value_type' => 'string'],
        ['setting_key' => 'footer_copy', 'setting_value' => 'Clarity for ambitious businesses entering and growing in Saudi Arabia.', 'value_type' => 'string']
    ];

    $settingStmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value, value_type, updated_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            value_type = VALUES(value_type),
            updated_by = VALUES(updated_by)
    ");

    foreach ($initialSettings as $s) {
        $settingStmt->execute([
            $s['setting_key'],
            $s['setting_value'],
            $s['value_type'],
            $userId
        ]);
    }
    echo "Seeded " . count($initialSettings) . " site settings into MySQL settings table.\n";

    // 5. Activity Logs
    $initialLogs = [
        ['action' => 'System initialized and verified', 'entity_type' => 'system', 'entity_id' => null, 'status' => 'success', 'metadata' => ['version' => '2.4.0', 'environment' => 'development']],
        ['action' => 'Editorial categories configured', 'entity_type' => 'category', 'entity_id' => null, 'status' => 'success', 'metadata' => ['count' => count($categories)]],
        ['action' => 'Published article: A practical guide to entering the Saudi market', 'entity_type' => 'article', 'entity_id' => 1, 'status' => 'success', 'metadata' => ['slug' => 'a-practical-guide-to-entering-the-saudi-market']],
        ['action' => 'Published article: The operating discipline behind durable growth', 'entity_type' => 'article', 'entity_id' => 2, 'status' => 'success', 'metadata' => ['slug' => 'the-operating-discipline-behind-durable-growth']],
        ['action' => 'Published article: Build a process before a position', 'entity_type' => 'article', 'entity_id' => 3, 'status' => 'success', 'metadata' => ['slug' => 'build-a-process-before-a-position']],
        ['action' => 'Published article: What your first 90 days in Saudi Arabia should make visible', 'entity_type' => 'article', 'entity_id' => 4, 'status' => 'success', 'metadata' => ['slug' => 'what-your-first-90-days-should-make-visible']]
    ];

    $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, entity_type, entity_id, status, metadata, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, INET6_ATON('127.0.0.1'), NOW() - INTERVAL ? MINUTE)");
    $minOffset = 60;
    foreach ($initialLogs as $log) {
        $logStmt->execute([
            $userId,
            $log['action'],
            $log['entity_type'],
            $log['entity_id'],
            $log['status'],
            json_encode($log['metadata']),
            $minOffset
        ]);
        $minOffset -= 10;
    }
    echo "Seeded " . count($initialLogs) . " initial activity logs.\n";

    echo "Database migration & seeding completed successfully!\n";
} catch (Exception $e) {
    echo "Seed Error: " . $e->getMessage() . "\n";
    exit(1);
}
