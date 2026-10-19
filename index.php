<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

try {
    $pdo = database();
} catch (Throwable $error) {
    error_log('IceBlock database connection failed: ' . $error->getMessage());
    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Database setup required — IceBlock Connect</title><link rel="stylesheet" href="style.css"></head>
    <body><main class="app-area"><section class="panel"><p class="section-kicker">Setup required</p><h1>Connect the MySQL database</h1><p>IceBlock Connect could not reach MySQL. Start MySQL, import <code>schema.sql</code>, then run <code>php seed.php</code> from this folder.</p><p>For connection settings, set <code>ICEBLOCK_DB_HOST</code>, <code>ICEBLOCK_DB_PORT</code>, <code>ICEBLOCK_DB_NAME</code>, <code>ICEBLOCK_DB_USER</code>, and <code>ICEBLOCK_DB_PASSWORD</code>.</p></section></main></body>
    </html>
    <?php
    exit;
}

$page = $_GET['page'] ?? 'home';
$allowedPages = ['home', 'marketplace', 'advertisement', 'login', 'register', 'producer', 'customer', 'admin'];
if (!is_string($page) || !in_array($page, $allowedPages, true)) {
    $page = 'home';
}
$user = current_user($pdo);
$flash = take_flash();
$search = $_GET['search'] ?? '';
$search = is_string($search) ? mb_substr(trim($search), 0, 120) : '';
$advertisement = null;
$profile = null;
$producerId = null;
$producerAds = [];
$producerEnquiries = [];
$customerEnquiries = [];
$allUsers = [];
$allAds = [];
$adminActivity = [];
$adminEditingUser = null;
$adminEditingAd = null;

if ($page === 'producer') {
    $user = require_user($pdo, ['producer']);
    $statement = $pdo->prepare(
        'SELECT p.*, u.full_name, u.email, u.phone, u.created_at AS registration_date
         FROM producers p JOIN users u ON u.id = p.user_id WHERE p.user_id = :user_id'
    );
    $statement->execute(['user_id' => $user['id']]);
    $profile = $statement->fetch();
    if (!$profile) {
        http_response_code(500);
        exit('Producer profile is missing. Contact the system administrator.');
    }
    $producerId = (int)$profile['id'];
    $producerAds = find_ads($pdo, '', false, $producerId);
    $statement = $pdo->prepare(
        'SELECT e.id, e.quantity, e.message, e.producer_response, e.status, e.created_at,
                u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
                a.title, prod.name AS product_name
         FROM enquiries e
         JOIN users u ON u.id = e.customer_id
         JOIN advertisements a ON a.id = e.advertisement_id
         JOIN products prod ON prod.id = e.product_id
         WHERE e.producer_id = :producer_id ORDER BY e.created_at DESC, e.id DESC'
    );
    $statement->execute(['producer_id' => $producerId]);
    $producerEnquiries = $statement->fetchAll();
} elseif ($page === 'customer') {
    $user = require_user($pdo, ['customer']);
    $statement = $pdo->prepare(
        'SELECT e.id, e.quantity, e.message, e.producer_response, e.status, e.created_at,
                a.title, a.phone, a.email AS business_email, a.location,
                prod.name AS product_name, pr.business_name
         FROM enquiries e
         JOIN advertisements a ON a.id = e.advertisement_id
         JOIN products prod ON prod.id = e.product_id
         JOIN producers pr ON pr.id = e.producer_id
         WHERE e.customer_id = :customer_id ORDER BY e.created_at DESC, e.id DESC'
    );
    $statement->execute(['customer_id' => $user['id']]);
    $customerEnquiries = $statement->fetchAll();
} elseif ($page === 'admin') {
    $user = require_user($pdo, ['admin']);
    $allUsers = $pdo->query(
        'SELECT id, full_name, email, phone, role, is_active, created_at FROM users ORDER BY created_at DESC, id DESC'
    )->fetchAll();
    $allAds = find_ads($pdo, '', false);
    $editingUserId = filter_var($_GET['edit_user'] ?? null, FILTER_VALIDATE_INT);
    if ($editingUserId) {
        foreach ($allUsers as $account) {
            if ((int)$account['id'] === (int)$editingUserId) {
                $adminEditingUser = $account;
                break;
            }
        }
    }
    $editingAdminAdId = filter_var($_GET['edit_ad'] ?? null, FILTER_VALIDATE_INT);
    $adminEditingAd = $editingAdminAdId
        ? (find_ads($pdo, '', false, null, (int)$editingAdminAdId)[0] ?? null)
        : null;
    $adminActivity = $pdo->query(
        'SELECT l.action, l.details, l.created_at, u.full_name AS actor_name, u.email AS actor_email
         FROM activity_logs l LEFT JOIN users u ON u.id = l.actor_id
         ORDER BY l.created_at DESC, l.id DESC LIMIT 80'
    )->fetchAll();
} elseif ($page === 'advertisement') {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        http_response_code(404);
    } else {
        $advertisement = find_ad($pdo, (int)$id, true);
        if ($advertisement) {
            $pdo->prepare('UPDATE advertisements SET view_count = view_count + 1 WHERE id = :id')
                ->execute(['id' => $advertisement['id']]);
            $advertisement['view_count'] = (int)$advertisement['view_count'] + 1;
        } else {
            http_response_code(404);
        }
    }
}

function render_ad_card(array $ad, ?array $user): string
{
    $url = 'index.php?page=advertisement&id=' . (int)$ad['id'];
    $contact = '<div class="ad-meta"><span>⌖ ' . h($ad['location']) . '</span><span>▦ ' . h($ad['quantity']) . '</span></div>';
    return '<article class="ad-card">
      <a class="ad-picture ad-picture-link" href="' . h($url) . '"><img class="ice-photo" src="' . h(image_url($ad['image_path'])) . '" alt="' . h($ad['title']) . '" loading="lazy"></a>
      <div class="ad-content">
        <span class="tag">' . h(ucfirst($ad['status'])) . ' · ' . h(ucfirst($ad['product_status'])) . '</span>
        <h3><a class="plain-link" href="' . h($url) . '">' . h($ad['title']) . '</a></h3>
        <p class="ad-description">' . h($ad['description']) . '</p>
        ' . $contact . '
        <div class="ad-bottom"><span class="price">' . h(money($ad['price'])) . '<small>per package</small></span><a class="btn btn-lime" href="' . h($url) . '">View details <span aria-hidden="true">→</span></a></div>
        <p>Supplied by <strong>' . h($ad['business_name']) . '</strong></p>
      </div>
    </article>';
}

$navPage = $page === 'advertisement' ? 'marketplace' : $page;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5f7f2">
  <meta name="description" content="Find local ice block producers, compare current offers and contact suppliers on IceBlock Connect.">
  <title>IceBlock Connect — Ice block producer directory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <header class="topbar">
    <a class="brand" href="index.php" aria-label="IceBlock Connect home">
      <span class="brand-mark" aria-hidden="true">ice<span>.</span></span>
      <span class="brand-copy"><strong>IceBlock</strong><small>CONNECT</small></span>
    </a>
    <nav class="main-nav" aria-label="Main navigation">
      <a class="nav-link <?= $navPage === 'home' ? 'active' : '' ?>" href="index.php">Discover</a>
      <a class="nav-link <?= $navPage === 'marketplace' ? 'active' : '' ?>" href="index.php?page=marketplace">Marketplace</a>
    </nav>
    <div class="header-actions">
      <span class="availability"><i></i> Local marketplace</span>
      <?php if ($user): ?>
        <a class="btn btn-dark header-login" href="index.php?page=<?= h($user['role']) ?>"><?= h($user['full_name']) ?> · <?= h(ucfirst($user['role'])) ?></a>
        <form class="inline-form" method="post" action="actions.php">
          <?= csrf_field() ?><input type="hidden" name="action" value="logout">
          <button class="btn secondary header-account">Log out</button>
        </form>
      <?php else: ?>
        <a class="btn btn-dark header-login" href="index.php?page=login">Sign in <span aria-hidden="true">↗</span></a>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($flash): ?>
    <div class="feedback"><div class="alert <?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="status"><?= h($flash['message']) ?></div></div>
  <?php endif; ?>

  <main class="app-area">
    <?php if ($page === 'home'): ?>
      <?php
        $featured = array_slice(find_ads($pdo), 0, 3);
        $featuredLocations = array_values(array_unique(array_filter(array_map(
            static fn(array $ad): string => trim((string)$ad['location']),
            $featured
        ))));
        $counts = $pdo->query(
            "SELECT (SELECT COUNT(*) FROM advertisements a
                       JOIN products prod ON prod.id = a.product_id
                       JOIN producers pr ON pr.id = a.producer_id
                       JOIN users u ON u.id = pr.user_id
                       WHERE a.status = 'active' AND prod.status = 'available'
                         AND pr.status = 'active' AND u.is_active = 1) AS ads,
                    (SELECT COUNT(*) FROM users WHERE is_active = 1) AS users,
                    (SELECT COUNT(*) FROM enquiries) AS enquiries"
        )->fetch();
      ?>
      <section class="hero">
        <div class="hero-copy">
          <span class="eyebrow">Your neighborhood ice market</span>
          <h1>Ice nearby.<br><em>Price upfront.</em></h1>
          <p>Find ice for your shop, home or event. Compare listed prices and quantities, then contact the seller to confirm today's availability.</p>
          <div class="hero-actions">
            <a class="btn btn-lime" href="index.php?page=marketplace">Explore marketplace <span aria-hidden="true">→</span></a>
            <a class="btn btn-outline" href="index.php?page=<?= $user && $user['role'] === 'producer' ? 'producer' : 'register' ?>">I'm a producer</a>
          </div>
        </div>
        <div class="hero-visual">
          <img class="hero-photo" src="assets/ice-cubes.jpg" alt="Close-up photograph of clear ice cubes">
          <div class="float-label"><strong><?= (int)$counts['ads'] ?> active offers</strong>From registered producers</div>
        </div>
      </section>
      <div class="demo-note"><span aria-hidden="true">ⓘ</span><span><strong>Buying locally is simple.</strong> Check the location, compare the listed offer and call the producer before setting out.</span></div>
      <form class="search-row home-search" method="get" action="index.php">
        <input type="hidden" name="page" value="marketplace">
        <span class="search-icon" aria-hidden="true">⌕</span>
        <input name="search" placeholder="Search producers, products or location" aria-label="Search local producers">
        <button class="btn btn-dark">Search <span aria-hidden="true">→</span></button>
      </form>
      <nav class="smart-bar" aria-label="Browse available areas">
        <span class="smart-label">Browse by area</span>
        <div class="chip-row">
          <a class="chip" href="index.php?page=marketplace">All suppliers</a>
          <?php foreach ($featuredLocations as $location): ?>
            <a class="chip" href="index.php?page=marketplace&amp;search=<?= rawurlencode($location) ?>"><?= h($location) ?></a>
          <?php endforeach; ?>
        </div>
      </nav>
      <div class="stats">
        <div class="stat"><span class="stat-icon" aria-hidden="true">✳</span><div><strong><?= (int)$counts['ads'] ?></strong><span>Active advertisements</span></div></div>
        <div class="stat"><span class="stat-icon" aria-hidden="true">⌖</span><div><strong><?= (int)$counts['users'] ?></strong><span>Registered accounts</span></div></div>
        <div class="stat"><span class="stat-icon" aria-hidden="true">↗</span><div><strong><?= (int)$counts['enquiries'] ?></strong><span>Customer enquiries</span></div></div>
      </div>
      <div class="section-head"><div><p class="section-kicker">Local suppliers</p><h2>Featured offers</h2><p>Explore current advertisements from registered producers.</p></div><a class="btn secondary" href="index.php?page=marketplace">View all offers <span aria-hidden="true">→</span></a></div>
      <div class="ad-grid"><?= $featured ? implode('', array_map(fn($ad) => render_ad_card($ad, $user), $featured)) : '<div class="empty"><strong>No active advertisements</strong>Check back soon for local offers.</div>' ?></div>
      <div class="section-head"><div><p class="section-kicker">How it works</p><h2>From discovery to contact</h2></div></div>
      <div class="how-grid">
        <article class="how-card"><span>01 / DISCOVER</span><h3>Search local businesses</h3><p>Find producers by business name, product or location.</p></article>
        <article class="how-card"><span>02 / COMPARE</span><h3>Review current information</h3><p>Check product, price, size, hours and contact details.</p></article>
        <article class="how-card"><span>03 / CONNECT</span><h3>Contact the producer</h3><p>Send an enquiry or contact the business directly.</p></article>
      </div>
      <div class="trust-strip">
        <div class="trust-item"><strong>See the offer first</strong><span>Check the listed price, product and quantity before you call.</span></div>
        <div class="trust-item"><strong>Shop closer to home</strong><span>Browse ice suppliers by the location shown on each offer.</span></div>
        <div class="trust-item"><strong>Deal directly with sellers</strong><span>Confirm today's stock, pickup or delivery with the producer.</span></div>
      </div>

    <?php elseif ($page === 'marketplace'): ?>
      <?php $results = find_ads($pdo, $search); ?>
      <div class="section-head"><div><p class="section-kicker">The marketplace</p><h2>Find an ice block producer</h2><p>Search by business name, producer, product or location.</p></div></div>
      <form class="search-row" method="get" action="index.php">
        <input type="hidden" name="page" value="marketplace">
        <span class="search-icon" aria-hidden="true">⌕</span>
        <input name="search" value="<?= h($search) ?>" placeholder="Business, product or location" aria-label="Search advertisements">
        <button class="btn btn-dark">Search <span aria-hidden="true">→</span></button>
      </form>
      <div class="section-head results-head"><div><h2>Search results <span class="result-count"><?= count($results) ?></span></h2></div></div>
      <div class="ad-grid"><?= $results ? implode('', array_map(fn($ad) => render_ad_card($ad, $user), $results)) : '<div class="empty"><strong>No matching producers found</strong>Try another business name, product or location.</div>' ?></div>

    <?php elseif ($page === 'advertisement'): ?>
      <?php if (!$advertisement): ?>
        <section class="panel"><h1>Advertisement not found</h1><p>This offer is no longer active or does not exist.</p><a class="btn btn-dark" href="index.php?page=marketplace">Back to marketplace</a></section>
      <?php else: ?>
        <div class="section-head"><div><p class="section-kicker">Producer advertisement</p><h1><?= h($advertisement['title']) ?></h1><p>Published <?= h(date_label($advertisement['created_at'])) ?> · <?= (int)$advertisement['view_count'] ?> views</p></div><a class="btn secondary" href="index.php?page=marketplace">Back to marketplace</a></div>
        <section class="detail-layout">
          <div class="detail-image"><img src="<?= h(image_url($advertisement['image_path'])) ?>" alt="<?= h($advertisement['title']) ?>"></div>
          <div class="panel detail-panel">
            <span class="tag"><?= h(ucfirst($advertisement['product_status'])) ?> product</span>
            <h2><?= h($advertisement['business_name']) ?></h2>
            <p><?= h($advertisement['description']) ?></p>
            <div class="detail-list">
              <div><span>Product</span><strong><?= h($advertisement['product_name']) ?></strong></div>
              <div><span>Product details</span><strong><?= h($advertisement['product_description']) ?></strong></div>
              <div><span>Ice block size</span><strong><?= h($advertisement['ice_block_size']) ?></strong></div>
              <div><span>Price</span><strong><?= h(money($advertisement['price'])) ?></strong></div>
              <div><span>Available quantity</span><strong><?= h($advertisement['quantity']) ?></strong></div>
              <div><span>Producer</span><strong><?= h($advertisement['producer_name']) ?></strong></div>
              <div><span>Location / address</span><strong><?= h($advertisement['location']) ?> · <?= h($advertisement['address']) ?></strong></div>
              <div><span>Operating hours</span><strong><?= h($advertisement['operating_hours'] ?: $advertisement['business_hours']) ?></strong></div>
              <div><span>Phone</span><strong><a href="tel:<?= h($advertisement['phone']) ?>"><?= h($advertisement['phone']) ?></a></strong></div>
              <div><span>Email</span><strong><a href="mailto:<?= h($advertisement['email']) ?>"><?= h($advertisement['email']) ?></a></strong></div>
            </div>
          </div>
        </section>
        <?php if ($user && $user['role'] === 'customer'): ?>
          <section class="panel"><h2>Send a producer enquiry</h2><p>Include the quantity you need and the producer will respond in your customer dashboard.</p>
            <form class="form-grid" method="post" action="actions.php">
              <?= csrf_field() ?><input type="hidden" name="action" value="enquiry_create"><input type="hidden" name="advertisement_id" value="<?= (int)$advertisement['id'] ?>">
              <div class="field"><label for="enquiry-quantity">Quantity needed</label><input id="enquiry-quantity" name="quantity" maxlength="100" required placeholder="e.g. 20 blocks"></div>
              <div class="field"><label for="enquiry-message">Message (optional)</label><input id="enquiry-message" name="message" maxlength="1000" placeholder="Ask the producer a question"></div>
              <div class="field full"><button class="btn btn-dark">Send enquiry</button></div>
            </form>
          </section>
        <?php elseif (!$user): ?>
          <div class="demo-note"><span aria-hidden="true">ⓘ</span><span><a href="index.php?page=login">Sign in as a customer</a> to submit an enquiry, or contact the producer directly using the details above.</span></div>
        <?php endif; ?>
      <?php endif; ?>

    <?php elseif ($page === 'login' || $page === 'register'): ?>
      <div class="section-head"><div><p class="section-kicker">Welcome to IceBlock Connect</p><h1>Join the local ice marketplace</h1><p>Create a customer or producer account.</p></div></div>
      <div class="auth-layout">
        <section class="panel"><p class="section-kicker">Already registered?</p><h2>Sign in</h2>
          <form class="form-grid" method="post" action="actions.php">
            <?= csrf_field() ?><input type="hidden" name="action" value="login">
            <div class="field full"><label for="login-email">Email address</label><input id="login-email" name="email" type="email" maxlength="254" required autocomplete="username"></div>
            <div class="field full"><label for="login-password">Password</label><input id="login-password" name="password" type="password" required autocomplete="current-password"></div>
            <div class="field full"><button class="btn btn-dark">Sign in <span aria-hidden="true">→</span></button></div>
          </form>
          <p class="note">Use your registered email address and password.</p>
        </section>
        <section class="panel"><p class="section-kicker">New around here?</p><h2>Create an account</h2>
          <form class="form-grid" method="post" action="actions.php">
            <?= csrf_field() ?><input type="hidden" name="action" value="register">
            <div class="field"><label for="reg-name">Full name</label><input id="reg-name" name="full_name" maxlength="100" required autocomplete="name"></div>
            <div class="field"><label for="reg-phone">Telephone number</label><input id="reg-phone" name="phone" type="tel" maxlength="30" required autocomplete="tel"></div>
            <div class="field full"><label for="reg-email">Email address</label><input id="reg-email" name="email" type="email" maxlength="254" required autocomplete="email"></div>
            <div class="field"><label for="reg-password">Password (at least 8 characters)</label><input id="reg-password" name="password" type="password" minlength="8" required autocomplete="new-password"></div>
            <div class="field"><label for="reg-password-confirm">Confirm password</label><input id="reg-password-confirm" name="password_confirmation" type="password" minlength="8" required autocomplete="new-password"></div>
            <div class="field"><label for="reg-role">Account type</label><select id="reg-role" name="role"><option value="customer">Customer</option><option value="producer">Ice block producer</option></select></div>
            <div class="field"><label for="reg-business">Business name (producers)</label><input id="reg-business" name="business_name" maxlength="150"></div>
            <div class="field"><label for="reg-address">Business address (producers)</label><input id="reg-address" name="address" maxlength="2000"></div>
            <div class="field"><label for="reg-location">Business location (producers)</label><input id="reg-location" name="location" maxlength="150" placeholder="e.g. Kaduna"></div>
            <div class="field"><label for="reg-hours">Operating hours</label><input id="reg-hours" name="operating_hours" maxlength="120" placeholder="e.g. Mon–Sat, 8am–6pm"></div>
            <div class="field full"><label for="reg-description">Business description (producers)</label><textarea id="reg-description" name="business_description" maxlength="4000"></textarea></div>
            <div class="field full"><button class="btn btn-lime">Create account <span aria-hidden="true">→</span></button></div>
          </form>
          <p class="note">Producer fields are required when registering a producer account.</p>
        </section>
      </div>

    <?php elseif ($page === 'producer'): ?>
      <?php
        $editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
        $editingAd = $editId ? (find_ads($pdo, '', false, $producerId, (int)$editId)[0] ?? null) : null;
        $statsStatement = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'active') AS active,
                    SUM(status = 'inactive') AS inactive,
                    SUM(view_count) AS views
             FROM advertisements WHERE producer_id = :producer_id"
        );
        $statsStatement->execute(['producer_id' => $producerId]);
        $producerStats = $statsStatement->fetch();
        $adForm = $editingAd ?: [
            'id' => 0,
            'title' => '',
            'product_name' => '',
            'product_description' => '',
            'ice_block_size' => '',
            'price' => '',
            'quantity' => '',
            'product_status' => 'available',
            'description' => '',
            'phone' => $profile['phone'],
            'email' => $profile['email'],
            'location' => $profile['location'],
            'operating_hours' => $profile['operating_hours'],
            'status' => 'pending',
            'image_path' => null,
        ];
      ?>
      <div class="section-head"><div><p class="section-kicker">Producer workspace</p><h1>Producer dashboard</h1><p>Manage your business profile and advertisements.</p></div><a class="btn secondary" href="index.php?page=marketplace">View marketplace</a></div>
      <div class="stats">
        <div class="stat"><span class="stat-icon">✳</span><div><strong><?= (int)$producerStats['total'] ?></strong><span>Total advertisements</span></div></div>
        <div class="stat"><span class="stat-icon">✓</span><div><strong><?= (int)$producerStats['active'] ?></strong><span>Active advertisements</span></div></div>
        <div class="stat"><span class="stat-icon">◷</span><div><strong><?= (int)$producerStats['inactive'] ?></strong><span>Inactive advertisements</span></div></div>
        <div class="stat"><span class="stat-icon">↗</span><div><strong><?= (int)$producerStats['views'] ?></strong><span>Advertisement views</span></div></div>
      </div>
      <section class="panel"><h2>Business profile</h2><p>Registered <?= h(date_label($profile['registration_date'])) ?> · update your public business contact information.</p>
        <form class="form-grid" method="post" action="actions.php">
          <?= csrf_field() ?><input type="hidden" name="action" value="profile_update">
          <div class="field"><label for="profile-name">Full name</label><input id="profile-name" name="full_name" maxlength="100" required value="<?= h($profile['full_name']) ?>"></div>
          <div class="field"><label for="profile-business">Business name</label><input id="profile-business" name="business_name" maxlength="150" required value="<?= h($profile['business_name']) ?>"></div>
          <div class="field"><label for="profile-email">Email address</label><input id="profile-email" name="email" type="email" maxlength="254" required value="<?= h($profile['email']) ?>"></div>
          <div class="field"><label for="profile-phone">Telephone number</label><input id="profile-phone" name="phone" type="tel" maxlength="30" required value="<?= h($profile['phone']) ?>"></div>
          <div class="field"><label for="profile-location">Business location</label><input id="profile-location" name="location" maxlength="150" required value="<?= h($profile['location']) ?>"></div>
          <div class="field"><label for="profile-hours">Operating hours</label><input id="profile-hours" name="operating_hours" maxlength="120" value="<?= h($profile['operating_hours']) ?>"></div>
          <div class="field full"><label for="profile-address">Business address</label><input id="profile-address" name="address" maxlength="2000" required value="<?= h($profile['address']) ?>"></div>
          <div class="field full"><label for="profile-description">Business description</label><textarea id="profile-description" name="business_description" maxlength="4000"><?= h($profile['description']) ?></textarea></div>
          <div class="field full"><button class="btn btn-dark">Save business profile</button></div>
        </form>
      </section>
      <section class="panel"><h2>Change password</h2><form class="form-grid" method="post" action="actions.php">
        <?= csrf_field() ?><input type="hidden" name="action" value="password_change">
        <div class="field"><label for="current-password">Current password</label><input id="current-password" name="current_password" type="password" required autocomplete="current-password"></div>
        <div class="field"><label for="new-password">New password</label><input id="new-password" name="new_password" type="password" minlength="8" required autocomplete="new-password"></div>
        <div class="field"><label for="confirm-password">Confirm new password</label><input id="confirm-password" name="password_confirmation" type="password" minlength="8" required autocomplete="new-password"></div>
        <div class="field"><button class="btn secondary">Update password</button></div>
      </form></section>
      <section class="panel"><h2><?= $editingAd ? 'Edit advertisement' : 'Create an advertisement' ?></h2>
        <p>New advertisements are reviewed by an administrator before appearing in public search results.</p>
        <form class="form-grid" method="post" action="actions.php" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="action" value="advertisement_save"><input type="hidden" name="advertisement_id" value="<?= (int)$adForm['id'] ?>">
          <div class="field"><label for="ad-title">Advertisement title</label><input id="ad-title" name="title" maxlength="150" required value="<?= h($adForm['title']) ?>"></div>
          <div class="field"><label for="ad-product-name">Product name</label><input id="ad-product-name" name="product_name" maxlength="150" required value="<?= h($adForm['product_name']) ?>"></div>
          <div class="field"><label for="ad-price">Price (naira)</label><input id="ad-price" name="price" type="number" min="1" step="0.01" required value="<?= h($adForm['price']) ?>"></div>
          <div class="field"><label for="ad-quantity">Available quantity</label><input id="ad-quantity" name="quantity" maxlength="100" required value="<?= h($adForm['quantity']) ?>" placeholder="e.g. 50 blocks"></div>
          <div class="field"><label for="ad-size">Ice block size</label><input id="ad-size" name="ice_block_size" maxlength="100" required value="<?= h($adForm['ice_block_size']) ?>" placeholder="e.g. Small / 5 kg"></div>
          <div class="field"><label for="ad-product-status">Product availability</label><select id="ad-product-status" name="product_status"><option value="available" <?= $adForm['product_status'] === 'available' ? 'selected' : '' ?>>Available</option><option value="unavailable" <?= $adForm['product_status'] === 'unavailable' ? 'selected' : '' ?>>Unavailable</option></select></div>
          <div class="field"><label for="ad-location">Business location</label><input id="ad-location" name="location" maxlength="150" required value="<?= h($adForm['location']) ?>"></div>
          <div class="field"><label for="ad-phone">Telephone number</label><input id="ad-phone" name="phone" type="tel" maxlength="30" required value="<?= h($adForm['phone']) ?>"></div>
          <div class="field"><label for="ad-email">Contact email</label><input id="ad-email" name="email" type="email" maxlength="254" required value="<?= h($adForm['email']) ?>"></div>
          <div class="field"><label for="ad-hours">Operating hours</label><input id="ad-hours" name="operating_hours" maxlength="120" value="<?= h($adForm['operating_hours']) ?>"></div>
          <?php if ($editingAd && $editingAd['status'] !== 'pending'): ?>
            <div class="field"><label for="ad-status">Advertisement status</label><select id="ad-status" name="listing_status"><option value="active" <?= $editingAd['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $editingAd['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
          <?php elseif ($editingAd): ?>
            <div class="field"><label>Review status</label><p class="status-label">Pending administrator approval</p></div>
          <?php endif; ?>
          <div class="field full"><label for="ad-product-description">Product description</label><textarea id="ad-product-description" name="product_description" maxlength="4000" required><?= h($adForm['product_description']) ?></textarea></div>
          <div class="field full"><label for="ad-description">Advertisement description</label><textarea id="ad-description" name="description" maxlength="4000" required><?= h($adForm['description']) ?></textarea></div>
          <div class="field full"><label for="business-image">Business image (JPG, PNG or WebP; up to 3 MB)</label><input id="business-image" name="business_image" type="file" accept="image/jpeg,image/png,image/webp"></div>
          <?php if ($editingAd && $editingAd['image_path']): ?>
            <div class="field full image-current"><img src="<?= h(image_url($editingAd['image_path'])) ?>" alt="Current business image"><label><input type="checkbox" name="remove_image" value="1"> Remove current image</label></div>
          <?php endif; ?>
          <div class="field full actions"><button class="btn btn-lime"><?= $editingAd ? 'Save changes' : 'Submit for review' ?></button><?php if ($editingAd): ?><a class="btn secondary" href="index.php?page=producer">Cancel</a><?php endif; ?></div>
        </form>
      </section>
      <section class="panel"><h2>Your advertisements</h2>
        <?php if (!$producerAds): ?><div class="empty"><strong>No advertisements yet</strong>Create your first listing above.</div><?php else: ?>
          <div class="table-wrap"><table><thead><tr><th>Offer</th><th>Price / quantity</th><th>Status</th><th>Views</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($producerAds as $ad): ?>
              <tr><td><?= h($ad['title']) ?><br><span class="note"><?= h($ad['location']) ?></span></td><td><?= h(money($ad['price'])) ?> · <?= h($ad['quantity']) ?></td><td><?= h(ucfirst($ad['status'])) ?></td><td><?= (int)$ad['view_count'] ?></td><td class="table-actions"><a class="btn secondary small" href="index.php?page=producer&amp;edit=<?= (int)$ad['id'] ?>">Edit</a> <form class="inline-form" method="post" action="actions.php" data-confirm="Delete this advertisement? Its enquiries will also be removed."><?= csrf_field() ?><input type="hidden" name="action" value="advertisement_delete"><input type="hidden" name="advertisement_id" value="<?= (int)$ad['id'] ?>"><button class="btn danger small">Delete</button></form></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </section>
      <section class="panel"><h2>Customer enquiries</h2>
        <?php if (!$producerEnquiries): ?><div class="empty"><strong>No enquiries yet</strong>Customer enquiries about your offers will appear here.</div><?php else: ?>
          <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Advertisement</th><th>Quantity / message</th><th>Status</th><th>Respond</th></tr></thead><tbody>
            <?php foreach ($producerEnquiries as $enquiry): ?>
              <tr><td><?= h($enquiry['customer_name']) ?><br><a href="mailto:<?= h($enquiry['customer_email']) ?>"><?= h($enquiry['customer_email']) ?></a><br><a href="tel:<?= h($enquiry['customer_phone']) ?>"><?= h($enquiry['customer_phone']) ?></a></td><td><?= h($enquiry['title']) ?></td><td><?= h($enquiry['quantity']) ?><br><?= h($enquiry['message'] ?: '—') ?></td><td><?= h(ucfirst($enquiry['status'])) ?><br><?= h($enquiry['producer_response']) ?></td>
                <td><form method="post" action="actions.php" class="request-update-form"><?= csrf_field() ?><input type="hidden" name="action" value="enquiry_respond"><input type="hidden" name="enquiry_id" value="<?= (int)$enquiry['id'] ?>"><select name="status" aria-label="Enquiry status"><option value="pending" <?= $enquiry['status'] === 'pending' ? 'selected' : '' ?>>Pending</option><option value="responded" <?= $enquiry['status'] === 'responded' ? 'selected' : '' ?>>Responded</option><option value="closed" <?= $enquiry['status'] === 'closed' ? 'selected' : '' ?>>Closed</option></select><textarea name="producer_response" maxlength="1000" placeholder="Write a response"><?= h($enquiry['producer_response']) ?></textarea><button class="btn secondary small">Save response</button></form></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </section>

    <?php elseif ($page === 'customer'): ?>
      <div class="section-head"><div><p class="section-kicker">Customer workspace</p><h1>Your enquiries</h1><p>Track producer responses and contact the business directly.</p></div><a class="btn btn-dark" href="index.php?page=marketplace">Browse advertisements</a></div>
      <section class="panel"><h2>Enquiry history</h2>
        <?php if (!$customerEnquiries): ?><div class="empty"><strong>No enquiries yet</strong>Open an advertisement and send a question to its producer.</div><?php else: ?>
          <div class="table-wrap"><table><thead><tr><th>Business / advertisement</th><th>Quantity / message</th><th>Date</th><th>Status / response</th><th>Contact</th></tr></thead><tbody>
            <?php foreach ($customerEnquiries as $enquiry): ?>
              <tr><td><?= h($enquiry['business_name']) ?><br><?= h($enquiry['title']) ?></td><td><?= h($enquiry['quantity']) ?><br><?= h($enquiry['message'] ?: '—') ?></td><td><?= h(date_label($enquiry['created_at'])) ?></td><td><?= h(ucfirst($enquiry['status'])) ?><br><?= h($enquiry['producer_response'] ?: 'No response yet.') ?></td><td><a href="tel:<?= h($enquiry['phone']) ?>"><?= h($enquiry['phone']) ?></a><br><a href="mailto:<?= h($enquiry['business_email']) ?>">Email producer</a></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </section>

    <?php elseif ($page === 'admin'): ?>
      <?php
        $adminCounts = $pdo->query(
            "SELECT (SELECT COUNT(*) FROM users) AS users,
                    (SELECT COUNT(*) FROM advertisements) AS ads,
                    (SELECT COUNT(*) FROM enquiries) AS enquiries,
                    (SELECT COUNT(*) FROM advertisements WHERE status = 'pending') AS pending"
        )->fetch();
      ?>
      <div class="section-head"><div><p class="section-kicker">System oversight</p><h1>Administrator dashboard</h1><p>Manage users and advertisements and review platform activity.</p></div></div>
      <div class="stats">
        <div class="stat"><span class="stat-icon">⌖</span><div><strong><?= (int)$adminCounts['users'] ?></strong><span>Registered users</span></div></div>
        <div class="stat"><span class="stat-icon">✳</span><div><strong><?= (int)$adminCounts['ads'] ?></strong><span>Advertisements</span></div></div>
        <div class="stat"><span class="stat-icon">◷</span><div><strong><?= (int)$adminCounts['pending'] ?></strong><span>Awaiting approval</span></div></div>
        <div class="stat"><span class="stat-icon">↗</span><div><strong><?= (int)$adminCounts['enquiries'] ?></strong><span>Customer enquiries</span></div></div>
      </div>
      <?php if ($adminEditingUser): ?>
        <section class="panel"><h2>Edit user information</h2><form class="form-grid" method="post" action="actions.php">
          <?= csrf_field() ?><input type="hidden" name="action" value="admin_user_update"><input type="hidden" name="user_id" value="<?= (int)$adminEditingUser['id'] ?>">
          <div class="field"><label for="user-full-name">Full name</label><input id="user-full-name" name="full_name" maxlength="100" required value="<?= h($adminEditingUser['full_name']) ?>"></div>
          <div class="field"><label for="user-email">Email address</label><input id="user-email" name="email" type="email" maxlength="254" required value="<?= h($adminEditingUser['email']) ?>"></div>
          <div class="field"><label for="user-phone">Telephone number</label><input id="user-phone" name="phone" type="tel" maxlength="30" required value="<?= h($adminEditingUser['phone']) ?>"></div>
          <div class="field full actions"><button class="btn btn-dark">Save user information</button><a class="btn secondary" href="index.php?page=admin">Cancel</a></div>
        </form></section>
      <?php endif; ?>
      <?php if ($adminEditingAd): ?>
        <section class="panel"><h2>Edit advertisement</h2><form class="form-grid" method="post" action="actions.php" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="action" value="admin_ad_update"><input type="hidden" name="advertisement_id" value="<?= (int)$adminEditingAd['id'] ?>">
          <div class="field"><label for="admin-ad-title">Advertisement title</label><input id="admin-ad-title" name="title" maxlength="150" required value="<?= h($adminEditingAd['title']) ?>"></div>
          <div class="field"><label for="admin-product-name">Product name</label><input id="admin-product-name" name="product_name" maxlength="150" required value="<?= h($adminEditingAd['product_name']) ?>"></div>
          <div class="field"><label for="admin-price">Price (naira)</label><input id="admin-price" name="price" type="number" min="1" step="0.01" required value="<?= h($adminEditingAd['price']) ?>"></div>
          <div class="field"><label for="admin-quantity">Available quantity</label><input id="admin-quantity" name="quantity" maxlength="100" required value="<?= h($adminEditingAd['quantity']) ?>"></div>
          <div class="field"><label for="admin-size">Ice block size</label><input id="admin-size" name="ice_block_size" maxlength="100" required value="<?= h($adminEditingAd['ice_block_size']) ?>"></div>
          <div class="field"><label for="admin-availability">Product availability</label><select id="admin-availability" name="product_status"><option value="available" <?= $adminEditingAd['product_status'] === 'available' ? 'selected' : '' ?>>Available</option><option value="unavailable" <?= $adminEditingAd['product_status'] === 'unavailable' ? 'selected' : '' ?>>Unavailable</option></select></div>
          <div class="field"><label for="admin-location">Business location</label><input id="admin-location" name="location" maxlength="150" required value="<?= h($adminEditingAd['location']) ?>"></div>
          <div class="field"><label for="admin-phone">Telephone number</label><input id="admin-phone" name="phone" type="tel" maxlength="30" required value="<?= h($adminEditingAd['phone']) ?>"></div>
          <div class="field"><label for="admin-email">Contact email</label><input id="admin-email" name="email" type="email" maxlength="254" required value="<?= h($adminEditingAd['email']) ?>"></div>
          <div class="field"><label for="admin-hours">Operating hours</label><input id="admin-hours" name="operating_hours" maxlength="120" value="<?= h($adminEditingAd['operating_hours']) ?>"></div>
          <div class="field full"><label for="admin-product-description">Product description</label><textarea id="admin-product-description" name="product_description" maxlength="4000" required><?= h($adminEditingAd['product_description']) ?></textarea></div>
          <div class="field full"><label for="admin-ad-description">Advertisement description</label><textarea id="admin-ad-description" name="description" maxlength="4000" required><?= h($adminEditingAd['description']) ?></textarea></div>
          <div class="field full"><label for="admin-business-image">Replace business image (JPG, PNG or WebP; up to 3 MB)</label><input id="admin-business-image" name="business_image" type="file" accept="image/jpeg,image/png,image/webp"></div>
          <?php if ($adminEditingAd['image_path']): ?><div class="field full image-current"><img src="<?= h(image_url($adminEditingAd['image_path'])) ?>" alt="Current advertisement image"><label><input type="checkbox" name="remove_image" value="1"> Remove current image</label></div><?php endif; ?>
          <div class="field full actions"><button class="btn btn-dark">Save advertisement</button><a class="btn secondary" href="index.php?page=admin">Cancel</a></div>
        </form></section>
      <?php endif; ?>
      <section class="panel"><h2>Registered users</h2>
        <div class="table-wrap"><table><thead><tr><th>Name / email</th><th>Phone</th><th>Role</th><th>Registered</th><th>Account</th><th>Actions</th></tr></thead><tbody>
          <?php foreach ($allUsers as $account): ?>
            <tr><td><?= h($account['full_name']) ?><br><a href="mailto:<?= h($account['email']) ?>"><?= h($account['email']) ?></a></td><td><?= h($account['phone']) ?></td><td><?= h(ucfirst($account['role'])) ?></td><td><?= h(date_label($account['created_at'])) ?></td><td><?= $account['is_active'] ? 'Active' : 'Suspended' ?></td><td>
              <a class="btn secondary small" href="index.php?page=admin&amp;edit_user=<?= (int)$account['id'] ?>">Edit</a>
              <?php if ((int)$account['id'] !== (int)$user['id'] && $account['role'] !== 'admin'): ?>
                <form class="inline-form" method="post" action="actions.php"><?= csrf_field() ?><input type="hidden" name="action" value="admin_user_status"><input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>"><input type="hidden" name="is_active" value="<?= $account['is_active'] ? '0' : '1' ?>"><button class="btn <?= $account['is_active'] ? 'danger' : 'secondary' ?> small"><?= $account['is_active'] ? 'Suspend' : 'Reactivate' ?></button></form>
                <?php if (!$account['is_active']): ?><form class="inline-form" method="post" action="actions.php" data-confirm="Permanently delete this user and their records?"><?= csrf_field() ?><input type="hidden" name="action" value="admin_user_delete"><input type="hidden" name="user_id" value="<?= (int)$account['id'] ?>"><button class="btn danger small">Delete</button></form><?php endif; ?>
              <?php else: ?>Protected account<?php endif; ?>
            </td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </section>
      <section class="panel"><h2>Advertisement moderation</h2>
        <?php if (!$allAds): ?><div class="empty"><strong>No advertisements found</strong></div><?php else: ?>
          <div class="table-wrap"><table><thead><tr><th>Offer</th><th>Producer</th><th>Price / location</th><th>Views</th><th>Status / moderation</th></tr></thead><tbody>
            <?php foreach ($allAds as $ad): ?>
              <tr><td><?= h($ad['title']) ?><br><span class="note"><?= h($ad['product_name']) ?></span></td><td><?= h($ad['business_name']) ?><br><?= h($ad['producer_name']) ?></td><td><?= h(money($ad['price'])) ?><br><?= h($ad['location']) ?></td><td><?= (int)$ad['view_count'] ?></td><td><a class="btn secondary small" href="index.php?page=admin&amp;edit_ad=<?= (int)$ad['id'] ?>">Edit details</a><form class="inline-form" method="post" action="actions.php"><?= csrf_field() ?><input type="hidden" name="action" value="admin_ad_status"><input type="hidden" name="advertisement_id" value="<?= (int)$ad['id'] ?>"><select name="status" aria-label="Advertisement status"><option value="pending" <?= $ad['status'] === 'pending' ? 'selected' : '' ?>>Pending</option><option value="active" <?= $ad['status'] === 'active' ? 'selected' : '' ?>>Active / approve</option><option value="inactive" <?= $ad['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select><button class="btn secondary small">Save status</button></form><form method="post" action="actions.php" data-confirm="Permanently remove this advertisement?"><?= csrf_field() ?><input type="hidden" name="action" value="admin_ad_delete"><input type="hidden" name="advertisement_id" value="<?= (int)$ad['id'] ?>"><button class="btn danger small">Delete</button></form></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </section>
      <section class="panel"><h2>Recent system activity</h2>
        <?php if (!$adminActivity): ?><div class="empty"><strong>No system activity recorded yet.</strong></div><?php else: ?>
          <div class="table-wrap"><table><thead><tr><th>Time</th><th>User</th><th>Activity</th><th>Details</th></tr></thead><tbody>
            <?php foreach ($adminActivity as $entry): ?><tr><td><?= h(date_label($entry['created_at'])) ?></td><td><?= h($entry['actor_name'] ?: 'Deleted account') ?><br><?= h($entry['actor_email'] ?: '') ?></td><td><?= h(str_replace('_', ' ', ucfirst($entry['action']))) ?></td><td><?= h($entry['details']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>

  <footer class="site-footer">
    <a class="brand footer-brand" href="index.php"><span class="brand-mark" aria-hidden="true">ice<span>.</span></span><span class="brand-copy"><strong>IceBlock</strong><small>CONNECT</small></span></a>
    <p>Helping local ice businesses find their next customer.</p>
    <span class="footer-note">Payment and delivery are arranged directly with each producer.</span>
  </footer>
  <script src="app.js" defer></script>
</body>
</html>
