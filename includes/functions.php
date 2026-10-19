<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

final class AppException extends RuntimeException
{
}

function h(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect_to(string $page, array $params = []): never
{
    $query = http_build_query(['page' => $page, ...$params]);
    header('Location: index.php?' . $query, true, 303);
    exit;
}

function set_flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        throw new AppException('Your form expired. Please try again.');
    }
}

function current_user(?PDO $pdo = null): ?array
{
    static $cached = false;
    static $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;
    $id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        return null;
    }
    $pdo ??= database();
    $statement = $pdo->prepare(
        'SELECT id, full_name, email, phone, role, created_at
         FROM users WHERE id = :id AND is_active = 1'
    );
    $statement->execute(['id' => $id]);
    $user = $statement->fetch() ?: null;
    if (!$user) {
        unset($_SESSION['user_id']);
    }
    return $user;
}

function require_user(PDO $pdo, array $roles = []): array
{
    $user = current_user($pdo);
    if (!$user) {
        set_flash('Please sign in to continue.', 'error');
        redirect_to('login');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        set_flash('You are not authorized to view that page.', 'error');
        redirect_to($user['role']);
    }
    return $user;
}

function activity(PDO $pdo, ?int $actorId, string $action, string $details = ''): void
{
    $statement = $pdo->prepare(
        'INSERT INTO activity_logs (actor_id, action, details) VALUES (:actor_id, :action, :details)'
    );
    $statement->execute([
        'actor_id' => $actorId,
        'action' => mb_substr($action, 0, 60),
        'details' => mb_substr($details, 0, 500),
    ]);
}

function post_text(string $key, int $maxLength, bool $required = true): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        throw new AppException('Invalid form data.');
    }
    $value = trim($value);
    if ($required && $value === '') {
        throw new AppException('Please complete all required fields.');
    }
    if (mb_strlen($value) > $maxLength) {
        throw new AppException('One or more fields are longer than allowed.');
    }
    return $value;
}

function validate_email(string $email): string
{
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
        throw new AppException('Enter a valid email address.');
    }
    return $email;
}

function save_uploaded_image(?string $currentPath = null): ?string
{
    $file = $_FILES['business_image'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $currentPath;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new AppException('The image could not be uploaded. Check the file size and try again.');
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new AppException('The uploaded image is invalid.');
    }
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new AppException('Images must be 3 MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new AppException('Choose a valid JPG, PNG or WebP image.');
    }

    $directory = dirname(__DIR__) . '/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new AppException('The upload folder is not available.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new AppException('The image could not be saved.');
    }
    return 'uploads/' . $filename;
}

function remove_uploaded_image(?string $path): void
{
    if (!$path || !preg_match('/^uploads\/[a-f0-9]{32}\.(jpg|png|webp)$/', $path)) {
        return;
    }
    $file = dirname(__DIR__) . '/' . $path;
    if (is_file($file) && !unlink($file)) {
        error_log('Unable to remove advertisement image: ' . $file);
    }
}

function image_url(?string $path): string
{
    if ($path && (str_starts_with($path, 'assets/') || preg_match('/^uploads\/[a-f0-9]{32}\.(jpg|png|webp)$/', $path))) {
        return $path;
    }
    return 'assets/ice-cubes.jpg';
}

function money(mixed $amount): string
{
    return '₦' . number_format((float)$amount, 0);
}

function date_label(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $timestamp = date_create_immutable($date, new DateTimeZone('UTC'));
    return $timestamp
        ? $timestamp->setTimezone(new DateTimeZone('Africa/Lagos'))->format('j M Y, g:i a')
        : '—';
}

function find_ads(PDO $pdo, string $search = '', bool $activeOnly = true, ?int $producerId = null, ?int $adId = null): array
{
    $conditions = [];
    $params = [];
    if ($activeOnly) {
        $conditions[] = "a.status = 'active' AND pr.status = 'active' AND u.is_active = 1 AND prod.status = 'available'";
    }
    if ($producerId !== null) {
        $conditions[] = 'a.producer_id = :producer_id';
        $params['producer_id'] = $producerId;
    }
    if ($adId !== null) {
        $conditions[] = 'a.id = :advertisement_id';
        $params['advertisement_id'] = $adId;
    }
    if ($search !== '') {
        $conditions[] = '(a.title LIKE :search_title OR a.description LIKE :search_description
            OR prod.name LIKE :search_product OR prod.description LIKE :search_product_description
            OR pr.business_name LIKE :search_business OR u.full_name LIKE :search_name
            OR a.location LIKE :search_location OR pr.location LIKE :search_profile_location)';
        $pattern = '%' . $search . '%';
        foreach (['search_title', 'search_description', 'search_product', 'search_product_description',
                  'search_business', 'search_name', 'search_location', 'search_profile_location'] as $key) {
            $params[$key] = $pattern;
        }
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $statement = $pdo->prepare(
        "SELECT a.id, a.title, a.description, a.image_path, a.phone, a.email,
                a.location, a.operating_hours, a.status, a.view_count, a.created_at,
                a.producer_id, a.product_id, prod.name AS product_name,
                prod.description AS product_description, prod.ice_block_size,
                prod.price, prod.quantity, prod.status AS product_status,
                pr.business_name, pr.address, pr.location AS business_location,
                pr.description AS business_description, pr.operating_hours AS business_hours,
                u.full_name AS producer_name, u.email AS producer_email
         FROM advertisements a
         JOIN products prod ON prod.id = a.product_id
         JOIN producers pr ON pr.id = a.producer_id
         JOIN users u ON u.id = pr.user_id
         $where
         ORDER BY a.created_at DESC, a.id DESC"
    );
    $statement->execute($params);
    return $statement->fetchAll();
}

function find_ad(PDO $pdo, int $id, bool $activeOnly = true): ?array
{
    return find_ads($pdo, '', $activeOnly, null, $id)[0] ?? null;
}
