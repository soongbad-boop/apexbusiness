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
    $adminEmail = 'admin@apexbusiness.sa';
    $checkUser = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $checkUser->execute([$adminEmail]);
    $userId = $checkUser->fetchColumn();
    if (!$userId) {
        $userStmt = $pdo->prepare("INSERT INTO users (role_id, email, password_hash, display_name, status) VALUES (1, ?, ?, ?, 'active')");
        $userStmt->execute([$adminEmail, password_hash('Admin@Apex2026', PASSWORD_DEFAULT), 'Apex Administrator']);
        $userId = (int) $pdo->lastInsertId();
        echo "Created initial admin user: {$adminEmail}\n";
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
            'title' => 'A Practical Guide to Entering the Saudi Market: Licensing, Legal Structures & Operational Realities',
            'slug' => 'a-practical-guide-to-entering-the-saudi-market',
            'description' => 'The foundational decisions shape every move that follows. Here is an executive analysis of licensing paths, entity structures, banking requirements, and local operating realities in Saudi Arabia.',
            'content' => "<p>Entering Saudi Arabia represents one of the most compelling expansion opportunities in the global economy today. However, transforming strategic ambition into an active, compliant corporate entity requires navigating a precise sequence of legal, fiscal, and administrative milestones. The objective is not to execute every process simultaneously, but to establish the structural dependencies in the correct order.</p><h2>1. Defining the Commercial Model &amp; Licensing Framework</h2><p>Before initiating corporate formation, founders and multinational executives must evaluate their target sector against the Ministry of Investment (MISA) licensing classifications. Key considerations include:</p><ul><li><strong>100% Foreign-Owned LLCs:</strong> Enabled under modern foreign investment regulations for services, technology, manufacturing, and trading.</li><li><strong>Regional Headquarters (RHQ) Program:</strong> Tailored for multinational corporations overseeing Middle East and North Africa operations, unlocking substantial long-term tax and visa incentives.</li><li><strong>Branch of a Foreign Entity:</strong> A direct extension of an international parent company suitable for specific engineering, contracting, or consulting engagements.</li></ul><blockquote>Careful upfront licensing alignment prevents costly amendments and administrative delays during banking, municipal registration, and recruitment phases.</blockquote><h2>2. Commercial Registration (CR) &amp; Regulatory Registrations</h2><p>Once MISA issues the investment license, the enterprise proceeds to the Ministry of Commerce for its Articles of Association (AoA) and Commercial Registration (CR). This unlocks subsequent statutory enrollments with:</p><ol><li><strong>Chamber of Commerce:</strong> Official business membership in the relevant provincial chamber.</li><li><strong>ZATCA (Zakat, Tax and Customs Authority):</strong> Corporate income tax registration, VAT registration, and Phase 2 E-Invoicing integration.</li><li><strong>Qiwa &amp; Muqeem Portals:</strong> Labor Ministry platforms governing employment contracts, Saudization (Nitaqat) quotas, and work visas.</li><li><strong>GOSI (General Organization for Social Insurance):</strong> Social security registration for domestic and international staff.</li></ol><h2>3. Corporate Banking &amp; Capitalization</h2><p>Opening a corporate bank account in Saudi Arabia has become significantly more streamlined with digital verification. International founders must provide notarized parent-company resolutions, ultimate beneficial ownership (UBO) disclosures, and authenticated MISA licenses to ensure swift KYC clearance.</p><h2>4. Building a Resilient Local Operating Cadence</h2><p>Establishing an entity is merely the first milestone. Sustainable market leadership requires ongoing PRO/GRO support, diligent tax filing, and proactive compliance monitoring. Apex Advisory partners with leadership teams from initial feasibility through day-to-day operations to ensure long-term commercial success.</p>",
            'status' => 'published',
            'image_url' => 'https://images.unsplash.com/photo-1580674684081-7617fbf3d745?auto=format&fit=crop&w=1400&q=85',
            'image_alt' => 'Modern commercial tower in King Abdullah Financial District, Riyadh',
            'image_caption' => 'Strategic market entry and corporate structuring in Riyadh, Saudi Arabia.',
            'tags' => 'Market Entry, MISA, Business Setup, Commercial Registration, ZATCA, Riyadh, Vision 2030',
            'views' => 1420,
            'is_featured' => 1,
            'allow_comments' => 1,
            'published_at' => '2026-08-15 10:00:00',
            'seo_title' => 'Guide to Entering the Saudi Market | Apex Business Consultancy',
            'seo_description' => 'Practical guide to establishing your business in Saudi Arabia with MISA licensing, legal structures, and local operating context.',
            'focus_keyword' => 'Saudi market entry',
            'canonical_url' => 'https://apexbusiness.sa/insights/a-practical-guide-to-entering-the-saudi-market',
            'og_title' => 'A Practical Guide to Entering the Saudi Market',
            'og_description' => 'Key insights for founders and investors expanding into Saudi Arabia.',
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
            'canonical_url' => 'https://apexbusiness.sa/insights/the-operating-discipline-behind-durable-growth',
            'og_title' => 'The Operating Discipline Behind Durable Growth',
            'og_description' => 'Building long-term operational resilience in fast-growing economies.',
            'category_slug' => 'advisory'
        ],
        [
            'title' => 'GCC Grand Tours Visa: How the Unified Schengen-Style Gulf Visa Reshapes Regional Business & Tourism',
            'slug' => 'gcc-grand-tours-visa-unified-gulf-mobility-guide',
            'description' => 'A comprehensive breakdown of the GCC Grand Tours Unified Visa initiative covering Saudi Arabia, UAE, Bahrain, Qatar, Oman, and Kuwait, and how seamless cross-border mobility transforms regional investment.',
            'content' => "<p>The announcement of the GCC Grand Tours Visa—widely termed the GCC Unified Tourist Visa—marks the most significant shift in regional mobility across the Arabian Peninsula since the inception of the Gulf Cooperation Council. Designed to function similarly to Europe's Schengen Zone, this single visa permits travelers to visit all six member nations: <strong>Saudi Arabia, the United Arab Emirates, Bahrain, Qatar, Oman, and Kuwait</strong>.</p><h2>The Strategic Objective Behind Unified Mobility</h2><p>Approved collaboratively by the GCC Interior and Tourism Ministries, the unified visa aims to consolidate the Gulf region into a cohesive international tourism and business hub. Rather than navigating six separate immigration protocols, international investors, consultants, and visitors will now be able to execute multi-country itineraries under a single digital approval.</p><blockquote>Regional integration is not just about tourism numbers; it is about creating a unified economic corridor where talent, capital, and commercial enterprise move without friction.</blockquote><h2>Key Advantages for International Investors &amp; Business Executives</h2><ul><li><strong>Frictionless Multi-Hub Operations:</strong> Enterprise teams can seamlessly conduct client meetings in Riyadh, attend conferences in Dubai, and visit manufacturing sites in Bahrain without multiple visa applications.</li><li><strong>Cost and Administrative Efficiency:</strong> Eliminating repeated individual visa fees, consular submissions, and redundant processing times.</li><li><strong>Extended Validity &amp; Digital Simplicity:</strong> The electronic visa application portal streamlines approval into minutes, offering extended multi-entry validity spanning 30 to 90 days.</li></ul><h2>What This Means for Saudi Market Entrants</h2><p>For organizations establishing a presence under Saudi Arabia's Regional Headquarters (RHQ) program or MISA licensing, the unified visa allows seamless deployment of senior executives and regional specialists across the entire GCC. The initiative solidifies Saudi Arabia as the central anchor of Gulf commerce.</p>",
            'status' => 'published',
            'image_url' => 'https://images.unsplash.com/photo-1512453979798-5ea266f8880c?auto=format&fit=crop&w=1400&q=85',
            'image_alt' => 'Modern skyline of GCC capital connecting business and travel',
            'image_caption' => 'The Unified GCC Grand Tours Visa creates unprecedented cross-border mobility across all six Gulf states.',
            'tags' => 'GCC Visa, Unified Tourism, Saudi Arabia, UAE, Regional Mobility, Business Expansion, MISA',
            'views' => 1640,
            'is_featured' => 1,
            'allow_comments' => 1,
            'published_at' => '2026-08-20 10:00:00',
            'seo_title' => 'Unified GCC Grand Tours Visa Guide 2026 | Apex Business Consultancy',
            'seo_description' => 'Learn how the GCC Grand Tours Unified Visa enables seamless travel across Saudi Arabia, UAE, Bahrain, Qatar, Oman, and Kuwait.',
            'focus_keyword' => 'GCC Grand Tours Visa',
            'canonical_url' => 'https://apexbusiness.sa/insights/gcc-grand-tours-visa-unified-gulf-mobility-guide',
            'og_title' => 'GCC Grand Tours Visa: How the Unified Gulf Visa Reshapes Regional Business',
            'og_description' => 'Comprehensive guide to the unified GCC visa across Saudi Arabia, UAE, Bahrain, Qatar, Oman, and Kuwait.',
            'category_slug' => 'market-entry'
        ],
        [
            'title' => 'GCC Unified Visa for Residents: Step-by-Step Eligibility, Requirements & Compliance Checklist',
            'slug' => 'gcc-unified-visa-for-residents-requirements-guide',
            'description' => 'Everything expatriate professionals, executive directors, and corporate residents need to know about qualifying for the GCC Grand Tours Visa.',
            'content' => "<p>One of the most anticipated dimensions of the GCC Grand Tours Visa is its specialized provision for expatriate residents living across the Gulf. With millions of international professionals residing in Saudi Arabia, the UAE, Qatar, and neighboring states, the unified resident framework provides unprecedented mobility for corporate executives and their families.</p><h2>Core Eligibility Criteria for Expatriate Residents</h2><p>To successfully apply through the forthcoming unified electronic portal, applicants must ensure their primary residency and documentation meet standardized GCC thresholds:</p><ul><li><strong>Valid GCC Residency (Iqama / Emirates ID):</strong> Must hold an active residency status with a minimum of 3 to 6 months remaining validity.</li><li><strong>Approved Professional Classifications:</strong> Priority digital fast-tracking is designated for accredited professions, corporate directors, engineers, legal counsel, and business owners.</li><li><strong>Valid International Passport:</strong> Passport with at least 6 months remaining validity and sufficient blank endorsement pages.</li><li><strong>Comprehensive Travel Insurance:</strong> Proof of regional medical and emergency repatriation insurance covering all participating GCC territories.</li></ul><blockquote>Ensuring documentation alignment before departure avoids border delays and guarantees seamless multi-port transit across GCC checkpoints.</blockquote><h2>Step-by-Step Application Protocol</h2><ol><li><strong>Digital Portal Submission:</strong> Complete the standardized online questionnaire and declare either a single-nation or multi-nation itinerary.</li><li><strong>Document Upload:</strong> Upload biometric photo scans, residence permit proof, and flight/accommodation itineraries.</li><li><strong>Electronic Fee Payment:</strong> Secure credit card processing with instant confirmation receipts.</li><li><strong>Digital Visa Delivery:</strong> Receive the unified electronic visa with scannable QR verification delivered directly via email.</li></ol>",
            'status' => 'published',
            'image_url' => 'https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=1200&q=85',
            'image_alt' => 'International business traveler preparing travel documents',
            'image_caption' => 'Streamlined digital submission requirements for expatriate residents across the Gulf.',
            'tags' => 'GCC Residents, Visa Requirements, Expat Mobility, Travel Compliance, Business Travel, Iqama',
            'views' => 1280,
            'is_featured' => 0,
            'allow_comments' => 1,
            'published_at' => '2026-08-18 11:30:00',
            'seo_title' => 'GCC Unified Visa for Residents Requirements | Apex Advisory',
            'seo_description' => 'Complete eligibility, required documents, and step-by-step application checklist for GCC expatriate residents.',
            'focus_keyword' => 'GCC visa for residents',
            'canonical_url' => 'https://apexbusiness.sa/insights/gcc-unified-visa-for-residents-requirements-guide',
            'og_title' => 'GCC Unified Visa for Residents: Step-by-Step Requirements & Checklist',
            'og_description' => 'Complete compliance and document requirements for GCC expatriate residents.',
            'category_slug' => 'tax-compliance'
        ],
        [
            'title' => 'One-Stop Border Entry & Aviation Integration: How the UAE & Bahrain Pilot Previews Seamless GCC Travel',
            'slug' => 'one-stop-border-entry-and-aviation-integration-gcc',
            'description' => 'Analyzing the landmark UAE-Bahrain single-window immigration clearance and the launch of the new unified GCC Aviation Authority.',
            'content' => "<p>Cross-border corporate travel in the Gulf is undergoing a structural transformation. With the recent approval and active rollout of the One-Stop travel system between the United Arab Emirates and the Kingdom of Bahrain, paired with the establishment of the unified GCC Aviation Authority, the region is pioneering a truly integrated travel ecosystem.</p><h2>How the One-Stop Border System Operates</h2><p>Traditionally, travelers traversing Gulf borders were required to clear passport exit controls in the departing nation followed by separate entry immigration checkpoints in the destination country. Under the new bilateral protocol, travelers are screened only once at the point of origin, with shared biometric and security databases authorizing instant entry.</p><blockquote>Single-point clearance reduces airport and causeway transit bottlenecks by over 60%, delivering immense productivity gains for executive teams.</blockquote><h2>Key Commercial &amp; Operational Implications</h2><ul><li><strong>Accelerated Corporate Commutes:</strong> Daily business commuting between Manama, Dubai, Abu Dhabi, and Riyadh becomes as seamless as domestic travel.</li><li><strong>Unified Airspace Management:</strong> The new GCC Aviation Authority harmonizes flight paths, reducing air transit delays and increasing direct short-haul frequencies between secondary business cities.</li><li><strong>Supply Chain &amp; Cargo Velocity:</strong> Synchronized customs protocols expedite air cargo and specialized equipment movement across commercial hubs.</li></ul><p>As Saudi Arabia prepares to integrate its King Fahd Causeway and international air terminals into this single-window architecture, cross-border commercial execution will reach unprecedented speed.</p>",
            'status' => 'published',
            'image_url' => 'https://images.unsplash.com/photo-1530521954074-e64f6810b32d?auto=format&fit=crop&w=1200&q=85',
            'image_alt' => 'Modern international airport terminal with passenger jets',
            'image_caption' => 'Unified aviation authorities and bilateral one-stop border clearance protocols in action.',
            'tags' => 'Aviation, Border Entry, Corporate Travel, UAE, Bahrain, Logistics, Supply Chain',
            'views' => 950,
            'is_featured' => 0,
            'allow_comments' => 1,
            'published_at' => '2026-08-14 09:15:00',
            'seo_title' => 'One-Stop Border Entry & Aviation Integration in GCC | Apex Advisory',
            'seo_description' => 'Discover how the UAE-Bahrain one-stop border pilot and GCC Aviation Authority streamline regional corporate travel.',
            'focus_keyword' => 'one stop border entry gcc',
            'canonical_url' => 'https://apexbusiness.sa/insights/one-stop-border-entry-and-aviation-integration-gcc',
            'og_title' => 'One-Stop Border Entry & Aviation Integration Across the GCC',
            'og_description' => 'Strategic operational advantages of unified border clearance and regional aviation integration.',
            'category_slug' => 'advisory'
        ],
        [
            'title' => 'Hospitality & Cross-Border Investment Surge Across Saudi Arabia, Oman, and the UAE',
            'slug' => 'gcc-tourism-and-hospitality-investment-surge-2026',
            'description' => 'How unified GCC tourist visas are driving private equity and institutional capital into hospitality, MICE facilities, and urban infrastructure.',
            'content' => "<p>The introduction of unified regional visas is creating a profound multiplier effect across the GCC hospitality and commercial real estate sectors. With international visitors now able to combine cultural heritage tours in AlUla, financial conferences in Riyadh, urban leisure in Dubai, and eco-resorts in Oman’s Musandam into single excursions, occupancy rates and hotel pipeline investments are reaching historic highs.</p><h2>The Economic Ripple Effect in Figures</h2><p>Regional tourism ministries project that the unified visa framework will contribute over $25 billion in incremental tourism receipts across the Gulf by 2030, with average visitor stays lengthening from 3.2 days to over 8.5 days.</p><ul><li><strong>Oman’s Hotel &amp; Eco-Tourism Boom:</strong> Leveraging proximity to the UAE and Saudi Arabia to capture multi-destination eco-tourists and wellness travelers.</li><li><strong>Saudi Mega-Projects:</strong> Direct flight and ground transit connectivity linking Red Sea Global, Diriyah Gate, and NEOM with established Gulf tourism corridors.</li><li><strong>MICE Industry Convergence:</strong> Major international exhibitions and summits can now draw cross-GCC delegates with zero visa friction.</li></ul><blockquote>Investors are no longer evaluating single-country hospitality yields; they are underwriting multi-hub regional assets that capture circulating Gulf traveler traffic.</blockquote><h2>Strategic Recommendations for Hospitality Operators</h2><p>Hospitality groups, asset managers, and commercial operators must tailor their packages to cross-border travelers—offering multi-city booking perks, integrated loyalty recognition, and seamless airport transfer partnerships across the GCC.</p>",
            'status' => 'published',
            'image_url' => 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1200&q=85',
            'image_alt' => 'Luxury hotel and urban resort development in the Gulf',
            'image_caption' => 'Expanding luxury hospitality and commercial real estate across the GCC.',
            'tags' => 'Hospitality, Real Estate, Investment, Tourism, Oman, UAE, Saudi Vision 2030',
            'views' => 870,
            'is_featured' => 0,
            'allow_comments' => 1,
            'published_at' => '2026-08-12 14:40:00',
            'seo_title' => 'GCC Hospitality & Cross-Border Investment Surge | Apex Advisory',
            'seo_description' => 'Explore how unified regional visas drive hospitality, private equity, and real estate investments across Saudi Arabia, Oman, and the UAE.',
            'focus_keyword' => 'GCC hospitality investment',
            'canonical_url' => 'https://apexbusiness.sa/insights/gcc-tourism-and-hospitality-investment-surge-2026',
            'og_title' => 'Hospitality & Cross-Border Investment Surge Across Saudi Arabia, Oman, and the UAE',
            'og_description' => 'Macroeconomic and real estate implications of GCC unified travel policies.',
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
            'canonical_url' => 'https://apexbusiness.sa/conversation'
        ],
        [
            'title' => 'Visa Assistance Services',
            'slug' => 'visa-assistance',
            'content' => '<h2>Visa Assistance Services</h2><p>Navigate international visa and residency requirements with dedicated case coordination, document verification, and appointment scheduling support.</p>',
            'status' => 'published',
            'seo_title' => 'Visa Assistance Services | Business, Work & Travel | Apex Business Consultancy',
            'seo_description' => 'Dedicated visa and immigration assistance for investors, professionals, executives, and families moving to Saudi Arabia, GCC, and key international destinations.',
            'canonical_url' => 'https://apexbusiness.sa/visa-assistance'
        ],
        [
            'title' => 'AI Workflow Automation',
            'slug' => 'automation',
            'content' => '<h2>Automate Your Business Operations</h2><p>Eliminate repetitive manual tasks, automate customer communication, and connect your business systems into high-velocity intelligent workflows.</p>',
            'status' => 'published',
            'seo_title' => 'AI Workflow Automation | Enterprise Solutions | Apex Business Consultancy',
            'seo_description' => 'Transform repetitive business tasks into automated workflows. AI-driven customer communications, CRM integrations, and operational pipelines tailored for GCC businesses.',
            'canonical_url' => 'https://apexbusiness.sa/automation'
        ],
        [
            'title' => 'Schedule an Advisory Session',
            'slug' => 'advisory-session',
            'content' => '<h2>Schedule an Advisory Session</h2><p>Book a private, highly structured strategic consultation with our senior advisors in Riyadh to evaluate your market expansion, entity structuring, and operational roadmap.</p>',
            'status' => 'published',
            'seo_title' => 'Schedule an Advisory Session | Executive Guidance | Apex Business Consultancy',
            'seo_description' => 'Book a dedicated one-on-one strategic advisory session with our senior consultants in Riyadh. Get immediate clarity on market entry, corporate structuring, and regulatory milestones.',
            'canonical_url' => 'https://apexbusiness.sa/advisory-session'
        ],
        [
            'title' => 'Meet Our Advisors',
            'slug' => 'advisors',
            'content' => '<h2>Meet with Our Senior Advisors</h2><p>Our multidisciplinary advisory team combines deep Saudi regulatory mastery, corporate structuring experience, cross-border mobility expertise, and operational discipline.</p>',
            'status' => 'published',
            'seo_title' => 'Meet Our Advisors | Senior Leadership & Consultants | Apex Business Consultancy',
            'seo_description' => 'Meet the senior business consultants, regulatory specialists, and operations advisors at Apex Business Consultancy in King Abdullah Financial District, Riyadh.',
            'canonical_url' => 'https://apexbusiness.sa/advisors'
        ],
        [
            'title' => 'About Apex Business Consultancy',
            'slug' => 'about',
            'content' => '<h2>Better context for meaningful moves</h2><p>Apex Business Consultancy helps founders, investors, and enterprise leadership teams turn Saudi market complexity into a practical, highly compliant path forward.</p>',
            'status' => 'published',
            'seo_title' => 'About Apex Business Consultancy | Local Context & Strategic Execution in Riyadh',
            'seo_description' => 'Learn how Apex Business Consultancy supports founders, corporate investors, and leadership teams navigating market entry and operational growth in Saudi Arabia.',
            'canonical_url' => 'https://apexbusiness.sa/about'
        ],
        [
            'title' => 'Services',
            'slug' => 'services',
            'content' => '<h2>Practical support for the moves that matter</h2><p>From entering Saudi Arabia to improving the way your business operates, Apex brings local context, professional support, and practical technology into one working relationship.</p>',
            'status' => 'published',
            'seo_title' => 'Services | Market Entry, PRO, Visas, Tax & AI Automation | Apex Business Consultancy',
            'seo_description' => 'Explore Apex Business Consultancy services: MISA licensing, investment advisory, PRO/GRO support, visa assistance, tax return guidance, audit review, trading education, and AI workflow automation.',
            'canonical_url' => 'https://apexbusiness.sa/services'
        ],
        [
            'title' => 'Contact Apex Business Consultancy',
            'slug' => 'contact',
            'content' => '<h2>Bring us your key priorities</h2><p>Whether you are establishing a new entity in Saudi Arabia, evaluating an investment opportunity, or resolving operational hurdles, our Riyadh team is ready to assist.</p>',
            'status' => 'published',
            'seo_title' => 'Contact Apex Business Consultancy | Riyadh, Saudi Arabia',
            'seo_description' => 'Get in touch with Apex Business Consultancy in King Abdullah Financial District, Riyadh. Start a conversation regarding market entry, licensing, tax, or AI automation.',
            'canonical_url' => 'https://apexbusiness.sa/contact'
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
        ['setting_key' => 'support_email', 'setting_value' => 'hello@apexbusiness.sa', 'value_type' => 'string'],
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
