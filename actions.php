<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('home');
}

$pdo = null;
$fallbackPage = 'login';
$uploadedPath = null;

try {
    verify_csrf();
    $pdo = database();
    $action = post_text('action', 40);
    $user = current_user($pdo);

    switch ($action) {
        case 'register': {
            $fullName = post_text('full_name', 100);
            $businessName = post_text('business_name', 150, false);
            $email = validate_email(post_text('email', 254));
            $phone = post_text('phone', 30);
            $address = post_text('address', 2000, false);
            $location = post_text('location', 150, false);
            $description = post_text('business_description', 4000, false);
            $hours = post_text('operating_hours', 120, false);
            $password = post_text('password', 200);
            $confirmation = post_text('password_confirmation', 200);
            $role = post_text('role', 20);
            if (!in_array($role, ['customer', 'producer'], true)) {
                throw new AppException('Choose a customer or producer account.');
            }
            if (!preg_match('/^[+0-9() .-]{7,30}$/', $phone)) {
                throw new AppException('Enter a valid telephone number.');
            }
            if ($role === 'producer' && ($businessName === '' || $address === '' || $location === '')) {
                throw new AppException('Producers must provide a business name, address and location.');
            }
            if (mb_strlen($password) < 8) {
                throw new AppException('Password must contain at least 8 characters.');
            }
            if (!hash_equals($password, $confirmation)) {
                throw new AppException('The passwords do not match.');
            }
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare(
                    'INSERT INTO users (full_name, email, phone, password_hash, role)
                     VALUES (:full_name, :email, :phone, :password_hash, :role)'
                );
                $statement->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'phone' => $phone,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                ]);
                $userId = (int)$pdo->lastInsertId();
                if ($role === 'producer') {
                    $producer = $pdo->prepare(
                        'INSERT INTO producers (user_id, business_name, address, location, description, operating_hours)
                         VALUES (:user_id, :business_name, :address, :location, :description, :operating_hours)'
                    );
                    $producer->execute([
                        'user_id' => $userId,
                        'business_name' => $businessName,
                        'address' => $address,
                        'location' => $location,
                        'description' => $description,
                        'operating_hours' => $hours,
                    ]);
                }
                activity($pdo, $userId, 'account_registered', $role . ' account registered');
                $pdo->commit();
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                set_flash('Your account has been created.');
                redirect_to($role);
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        }

        case 'login': {
            $email = validate_email(post_text('email', 254));
            $password = post_text('password', 200);
            $statement = $pdo->prepare(
                'SELECT id, full_name, email, password_hash, role, is_active
                 FROM users WHERE email = :email'
            );
            $statement->execute(['email' => $email]);
            $account = $statement->fetch();
            if (!$account || !(bool)$account['is_active'] || !password_verify($password, $account['password_hash'])) {
                throw new AppException('Email or password is incorrect.');
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$account['id'];
            activity($pdo, (int)$account['id'], 'login', 'User signed in');
            set_flash('Welcome back, ' . $account['full_name'] . '.');
            redirect_to($account['role']);
        }

        case 'logout': {
            $user = $user ? require_user($pdo) : null;
            if ($user) {
                activity($pdo, (int)$user['id'], 'logout', 'User signed out');
            }
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]);
            }
            session_destroy();
            redirect_to('home');
        }

        case 'profile_update': {
            $fallbackPage = 'producer';
            $user = require_user($pdo, ['producer']);
            $fullName = post_text('full_name', 100);
            $phone = post_text('phone', 30);
            $email = validate_email(post_text('email', 254));
            $businessName = post_text('business_name', 150);
            $address = post_text('address', 2000);
            $location = post_text('location', 150);
            $description = post_text('business_description', 4000, false);
            $hours = post_text('operating_hours', 120, false);
            if (!preg_match('/^[+0-9() .-]{7,30}$/', $phone)) {
                throw new AppException('Enter a valid telephone number.');
            }
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare(
                    'UPDATE users SET full_name = :full_name, email = :email, phone = :phone WHERE id = :id'
                );
                $statement->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'phone' => $phone,
                    'id' => $user['id'],
                ]);
                $producerStatement = $pdo->prepare(
                    'UPDATE producers SET business_name = :business_name, address = :address,
                     location = :location, description = :description, operating_hours = :operating_hours
                     WHERE user_id = :user_id'
                );
                $producerStatement->execute([
                    'business_name' => $businessName,
                    'address' => $address,
                    'location' => $location,
                    'description' => $description,
                    'operating_hours' => $hours,
                    'user_id' => $user['id'],
                ]);
                $producerLookup = $pdo->prepare('SELECT id FROM producers WHERE user_id = :user_id');
                $producerLookup->execute(['user_id' => $user['id']]);
                $producerId = (int)$producerLookup->fetchColumn();
                $advertisements = $pdo->prepare(
                    'UPDATE advertisements SET phone = :phone, email = :email, location = :location,
                     operating_hours = :hours WHERE producer_id = :producer_id'
                );
                $advertisements->execute([
                    'phone' => $phone,
                    'email' => $email,
                    'location' => $location,
                    'hours' => $hours,
                    'producer_id' => $producerId,
                ]);
                activity($pdo, (int)$user['id'], 'profile_updated', 'Producer business profile updated');
                $pdo->commit();
                set_flash('Your profile has been updated.');
                redirect_to('producer');
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        }

        case 'password_change': {
            $fallbackPage = 'producer';
            $user = require_user($pdo, ['producer']);
            $currentPassword = post_text('current_password', 200);
            $newPassword = post_text('new_password', 200);
            $confirmation = post_text('password_confirmation', 200);
            $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
            $statement->execute(['id' => $user['id']]);
            $hash = $statement->fetchColumn();
            if (!is_string($hash) || !password_verify($currentPassword, $hash)) {
                throw new AppException('Your current password is incorrect.');
            }
            if (mb_strlen($newPassword) < 8) {
                throw new AppException('New password must contain at least 8 characters.');
            }
            if (!hash_equals($newPassword, $confirmation)) {
                throw new AppException('The new passwords do not match.');
            }
            $statement = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $statement->execute(['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $user['id']]);
            activity($pdo, (int)$user['id'], 'password_changed', 'Producer password changed');
            set_flash('Your password has been changed.');
            redirect_to('producer');
        }

        case 'advertisement_save': {
            $fallbackPage = 'producer';
            $user = require_user($pdo, ['producer']);
            $advertisementId = filter_var($_POST['advertisement_id'] ?? 0, FILTER_VALIDATE_INT);
            $advertisementId = $advertisementId === false ? 0 : (int)$advertisementId;
            $title = post_text('title', 150);
            $productName = post_text('product_name', 150);
            $productDescription = post_text('product_description', 4000);
            $iceBlockSize = post_text('ice_block_size', 100);
            $quantity = post_text('quantity', 100);
            $productStatus = post_text('product_status', 20, false) ?: 'available';
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $description = post_text('description', 4000);
            $location = post_text('location', 150);
            $phone = post_text('phone', 30);
            $email = validate_email(post_text('email', 254));
            $hours = post_text('operating_hours', 120, false);
            if ($price === false || $price <= 0 || $price > 9999999999.99) {
                throw new AppException('Enter a valid price greater than zero.');
            }
            if (!preg_match('/^[+0-9() .-]{7,30}$/', $phone)) {
                throw new AppException('Enter a valid telephone number.');
            }
            if (!in_array($productStatus, ['available', 'unavailable'], true)) {
                throw new AppException('Choose a valid product availability.');
            }
            $producerStatement = $pdo->prepare('SELECT id FROM producers WHERE user_id = :user_id AND status = "active"');
            $producerStatement->execute(['user_id' => $user['id']]);
            $producerId = (int)$producerStatement->fetchColumn();
            if ($producerId < 1) {
                throw new AppException('Your producer profile is not active.');
            }

            $existing = null;
            if ($advertisementId > 0) {
                $statement = $pdo->prepare(
                    'SELECT id, product_id, image_path, status FROM advertisements
                     WHERE id = :id AND producer_id = :producer_id'
                );
                $statement->execute(['id' => $advertisementId, 'producer_id' => $producerId]);
                $existing = $statement->fetch() ?: null;
                if (!$existing) {
                    throw new AppException('Advertisement not found.');
                }
            }

            $oldImage = $existing['image_path'] ?? null;
            $uploadedPath = save_uploaded_image($oldImage);
            if (!empty($_POST['remove_image']) && $existing && $uploadedPath === $oldImage) {
                $uploadedPath = null;
            }
            $status = $existing['status'] ?? 'pending';
            if ($existing && $status === 'active' && ($_POST['listing_status'] ?? '') === 'inactive') {
                $status = 'inactive';
            } elseif ($existing && $status === 'inactive' && ($_POST['listing_status'] ?? '') === 'active') {
                $status = 'pending';
            }

            $pdo->beginTransaction();
            try {
                if ($existing) {
                    $productStatement = $pdo->prepare(
                        'UPDATE products SET name = :name, description = :description, ice_block_size = :size,
                         price = :price, quantity = :quantity, status = :status
                         WHERE id = :id AND producer_id = :producer_id'
                    );
                    $productStatement->execute([
                        'name' => $productName,
                        'description' => $productDescription,
                        'size' => $iceBlockSize,
                        'price' => $price,
                        'quantity' => $quantity,
                        'status' => $productStatus,
                        'id' => $existing['product_id'],
                        'producer_id' => $producerId,
                    ]);
                    $statement = $pdo->prepare(
                        'UPDATE advertisements SET title = :title, description = :description, image_path = :image_path,
                         phone = :phone, email = :email, location = :location, operating_hours = :hours,
                         status = :status WHERE id = :id AND producer_id = :producer_id'
                    );
                    $statement->execute([
                        'title' => $title,
                        'description' => $description,
                        'image_path' => $uploadedPath,
                        'phone' => $phone,
                        'email' => $email,
                        'location' => $location,
                        'hours' => $hours,
                        'status' => $status,
                        'id' => $advertisementId,
                        'producer_id' => $producerId,
                    ]);
                    activity($pdo, (int)$user['id'], 'advertisement_updated', 'Advertisement #' . $advertisementId . ' updated');
                } else {
                    $productStatement = $pdo->prepare(
                        'INSERT INTO products (producer_id, name, description, ice_block_size, price, quantity, status)
                         VALUES (:producer_id, :name, :description, :size, :price, :quantity, :status)'
                    );
                    $productStatement->execute([
                        'producer_id' => $producerId,
                        'name' => $productName,
                        'description' => $productDescription,
                        'size' => $iceBlockSize,
                        'price' => $price,
                        'quantity' => $quantity,
                        'status' => $productStatus,
                    ]);
                    $productId = (int)$pdo->lastInsertId();
                    $statement = $pdo->prepare(
                        'INSERT INTO advertisements
                         (producer_id, product_id, title, description, image_path, phone, email, location, operating_hours)
                         VALUES (:producer_id, :product_id, :title, :description, :image_path, :phone, :email, :location, :hours)'
                    );
                    $statement->execute([
                        'producer_id' => $producerId,
                        'product_id' => $productId,
                        'title' => $title,
                        'description' => $description,
                        'image_path' => $uploadedPath,
                        'phone' => $phone,
                        'email' => $email,
                        'location' => $location,
                        'hours' => $hours,
                    ]);
                    $advertisementId = (int)$pdo->lastInsertId();
                    activity($pdo, (int)$user['id'], 'advertisement_created', 'Advertisement #' . $advertisementId . ' created; awaiting administrator approval');
                }
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($uploadedPath !== $oldImage) {
                    remove_uploaded_image($uploadedPath);
                }
                throw $error;
            }
            if ($oldImage !== $uploadedPath) {
                remove_uploaded_image($oldImage);
            }
            $uploadedPath = null;
            set_flash($existing ? 'Advertisement updated.' : 'Advertisement submitted for administrator approval.');
            redirect_to('producer');
        }

        case 'advertisement_delete': {
            $fallbackPage = 'producer';
            $user = require_user($pdo, ['producer']);
            $advertisementId = filter_var($_POST['advertisement_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$advertisementId || $advertisementId < 1) {
                throw new AppException('Choose a valid advertisement.');
            }
            $statement = $pdo->prepare('SELECT id, image_path FROM advertisements WHERE id = :id AND producer_id = (SELECT id FROM producers WHERE user_id = :user_id)');
            $statement->execute(['id' => $advertisementId, 'user_id' => $user['id']]);
            $ad = $statement->fetch();
            if (!$ad) {
                throw new AppException('Advertisement not found.');
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM advertisements WHERE id = :id')->execute(['id' => $advertisementId]);
                $pdo->prepare('DELETE FROM products WHERE id NOT IN (SELECT product_id FROM advertisements) AND producer_id = (SELECT id FROM producers WHERE user_id = :user_id)')
                    ->execute(['user_id' => $user['id']]);
                activity($pdo, (int)$user['id'], 'advertisement_deleted', 'Advertisement #' . $advertisementId . ' deleted');
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
            remove_uploaded_image($ad['image_path']);
            set_flash('Advertisement removed.');
            redirect_to('producer');
        }

        case 'enquiry_create': {
            $fallbackPage = 'customer';
            $user = require_user($pdo, ['customer']);
            $advertisementId = filter_var($_POST['advertisement_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$advertisementId || $advertisementId < 1) {
                throw new AppException('Choose a valid advertisement.');
            }
            $quantity = post_text('quantity', 100);
            $message = post_text('message', 1000, false);
            $statement = $pdo->prepare(
                "SELECT a.id, a.product_id, a.producer_id FROM advertisements a
                 JOIN products prod ON prod.id = a.product_id
                 JOIN producers pr ON pr.id = a.producer_id
                 JOIN users u ON u.id = pr.user_id
                 WHERE a.id = :id AND a.status = 'active' AND prod.status = 'available'
                   AND pr.status = 'active' AND u.is_active = 1"
            );
            $statement->execute(['id' => $advertisementId]);
            $ad = $statement->fetch();
            if (!$ad) {
                throw new AppException('This advertisement is no longer available.');
            }
            $insert = $pdo->prepare(
                'INSERT INTO enquiries (advertisement_id, product_id, producer_id, customer_id, quantity, message)
                 VALUES (:advertisement_id, :product_id, :producer_id, :customer_id, :quantity, :message)'
            );
            $insert->execute([
                'advertisement_id' => $ad['id'],
                'product_id' => $ad['product_id'],
                'producer_id' => $ad['producer_id'],
                'customer_id' => $user['id'],
                'quantity' => $quantity,
                'message' => $message,
            ]);
            activity($pdo, (int)$user['id'], 'enquiry_submitted', 'Enquiry for advertisement #' . $advertisementId);
            set_flash('Your enquiry has been sent to the producer.');
            redirect_to('customer');
        }

        case 'enquiry_respond': {
            $fallbackPage = 'producer';
            $user = require_user($pdo, ['producer']);
            $enquiryId = filter_var($_POST['enquiry_id'] ?? null, FILTER_VALIDATE_INT);
            $status = post_text('status', 20);
            $response = post_text('producer_response', 1000, false);
            if (!$enquiryId || !in_array($status, ['pending', 'responded', 'closed'], true)) {
                throw new AppException('Choose a valid enquiry status.');
            }
            $statement = $pdo->prepare(
                'UPDATE enquiries e JOIN producers p ON p.id = e.producer_id
                 SET e.status = :status, e.producer_response = :response
                 WHERE e.id = :id AND p.user_id = :user_id'
            );
            $statement->execute([
                'status' => $status,
                'response' => $response,
                'id' => $enquiryId,
                'user_id' => $user['id'],
            ]);
            if ($statement->rowCount() === 0) {
                $exists = $pdo->prepare(
                    'SELECT 1 FROM enquiries e JOIN producers p ON p.id = e.producer_id
                     WHERE e.id = :id AND p.user_id = :user_id'
                );
                $exists->execute(['id' => $enquiryId, 'user_id' => $user['id']]);
                if (!$exists->fetchColumn()) {
                    throw new AppException('Enquiry not found.');
                }
            }
            activity($pdo, (int)$user['id'], 'enquiry_updated', 'Enquiry #' . $enquiryId . ' updated');
            set_flash('Your response has been saved.');
            redirect_to('producer');
        }

        case 'admin_user_update': {
            $fallbackPage = 'admin';
            $user = require_user($pdo, ['admin']);
            $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$targetId || $targetId < 1) {
                throw new AppException('Choose a valid account.');
            }
            $fullName = post_text('full_name', 100);
            $email = validate_email(post_text('email', 254));
            $phone = post_text('phone', 30);
            if (!preg_match('/^[+0-9() .-]{7,30}$/', $phone)) {
                throw new AppException('Enter a valid telephone number.');
            }
            $statement = $pdo->prepare(
                'UPDATE users SET full_name = :full_name, email = :email, phone = :phone WHERE id = :id'
            );
            $statement->execute([
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'id' => $targetId,
            ]);
            if ($statement->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM users WHERE id = :id');
                $exists->execute(['id' => $targetId]);
                if (!$exists->fetchColumn()) {
                    throw new AppException('User not found.');
                }
            }
            activity($pdo, (int)$user['id'], 'user_updated', 'User #' . $targetId . ' profile updated');
            set_flash('User information updated.');
            redirect_to('admin');
        }

        case 'admin_user_status': {
            $fallbackPage = 'admin';
            $user = require_user($pdo, ['admin']);
            $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $active = ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
            if (!$targetId || $targetId === (int)$user['id']) {
                throw new AppException('Choose another account to manage.');
            }
            $statement = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = :id');
            $statement->execute(['id' => $targetId]);
            $target = $statement->fetch();
            if (!$target) {
                throw new AppException('User not found.');
            }
            if ($target['role'] === 'admin' && !$active) {
                $adminCount = (int)$pdo->query(
                    "SELECT COUNT(*) FROM users u JOIN administrators a ON a.user_id = u.id
                     WHERE u.role = 'admin' AND u.is_active = 1"
                )->fetchColumn();
                if ($adminCount <= 1) {
                    throw new AppException('The last active administrator cannot be suspended.');
                }
            }
            $pdo->prepare('UPDATE users SET is_active = :is_active WHERE id = :id')
                ->execute(['is_active' => $active, 'id' => $targetId]);
            if ($target['role'] === 'producer') {
                $pdo->prepare('UPDATE producers SET status = :status WHERE user_id = :user_id')
                    ->execute(['status' => $active ? 'active' : 'suspended', 'user_id' => $targetId]);
            }
            activity($pdo, (int)$user['id'], 'user_status_changed', 'User #' . $targetId . ' status changed');
            set_flash('User account status updated.');
            redirect_to('admin');
        }

        case 'admin_user_delete': {
            $fallbackPage = 'admin';
            $user = require_user($pdo, ['admin']);
            $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$targetId || $targetId === (int)$user['id']) {
                throw new AppException('Choose another account to delete.');
            }
            $statement = $pdo->prepare('SELECT id, is_active, role FROM users WHERE id = :id');
            $statement->execute(['id' => $targetId]);
            $target = $statement->fetch();
            if (!$target || (bool)$target['is_active']) {
                throw new AppException('Only inactive accounts can be deleted.');
            }
            if ($target['role'] === 'admin') {
                throw new AppException('Administrator accounts cannot be deleted here.');
            }
            $images = $pdo->prepare('SELECT image_path FROM advertisements WHERE producer_id = (SELECT id FROM producers WHERE user_id = :user_id)');
            $images->execute(['user_id' => $targetId]);
            $paths = $images->fetchAll(PDO::FETCH_COLUMN);
            activity($pdo, (int)$user['id'], 'user_deleted', 'Inactive user #' . $targetId . ' deleted');
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $targetId]);
            foreach ($paths as $path) {
                remove_uploaded_image($path);
            }
            set_flash('Inactive account and associated records removed.');
            redirect_to('admin');
        }

        case 'admin_ad_status': {
            $fallbackPage = 'admin';
            $user = require_user($pdo, ['admin']);
            $advertisementId = filter_var($_POST['advertisement_id'] ?? null, FILTER_VALIDATE_INT);
            $status = post_text('status', 20);
            if (!$advertisementId || !in_array($status, ['active', 'inactive', 'pending'], true)) {
                throw new AppException('Choose a valid advertisement status.');
            }
            $statement = $pdo->prepare('UPDATE advertisements SET status = :status WHERE id = :id');
            $statement->execute(['status' => $status, 'id' => $advertisementId]);
            if ($statement->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM advertisements WHERE id = :id');
                $exists->execute(['id' => $advertisementId]);
                if (!$exists->fetchColumn()) {
                    throw new AppException('Advertisement not found.');
                }
            }
            activity($pdo, (int)$user['id'], 'advertisement_moderated', 'Advertisement #' . $advertisementId . ' set to ' . $status);
            set_flash('Advertisement moderation status updated.');
            redirect_to('admin');
        }

        case 'admin_ad_update': {
            $fallbackPage = 'admin';
            $user = require_user($pdo, ['admin']);
            $advertisementId = filter_var($_POST['advertisement_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$advertisementId || $advertisementId < 1) {
                throw new AppException('Choose a valid advertisement.');
            }
            $title = post_text('title', 150);
            $productName = post_text('product_name', 150);
            $productDescription = post_text('product_description', 4000);
            $iceBlockSize = post_text('ice_block_size', 100);
            $quantity = post_text('quantity', 100);
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $description = post_text('description', 4000);
            $phone = post_text('phone', 30);
            $email = validate_email(post_text('email', 254));
            $location = post_text('location', 150);
            $hours = post_text('operating_hours', 120, false);
            $productStatus = post_text('product_status', 20);
            if ($price === false || $price <= 0 || $price > 9999999999.99
                || !in_array($productStatus, ['available', 'unavailable'], true)
                || !preg_match('/^[+0-9() .-]{7,30}$/', $phone)) {
                throw new AppException('Check the price, product availability and telephone number.');
            }
            $statement = $pdo->prepare('SELECT * FROM advertisements WHERE id = :id');
            $statement->execute(['id' => $advertisementId]);
            $ad = $statement->fetch();
            if (!$ad) {
                throw new AppException('Advertisement not found.');
            }
            $oldImage = $ad['image_path'];
            $uploadedPath = save_uploaded_image($oldImage);
            if (!empty($_POST['remove_image']) && $uploadedPath === $oldImage) {
                $uploadedPath = null;
            }
            $pdo->beginTransaction();
            try {
                $product = $pdo->prepare(
                    'UPDATE products SET name = :name, description = :description, ice_block_size = :size,
                     quantity = :quantity, price = :price, status = :status WHERE id = :id'
                );
                $product->execute([
                    'name' => $productName,
                    'description' => $productDescription,
                    'size' => $iceBlockSize,
                    'quantity' => $quantity,
                    'price' => $price,
                    'status' => $productStatus,
                    'id' => $ad['product_id'],
                ]);
                $updateAd = $pdo->prepare(
                    'UPDATE advertisements SET title = :title, description = :description, image_path = :image_path,
                     phone = :phone, email = :email, location = :location, operating_hours = :hours WHERE id = :id'
                );
                $updateAd->execute([
                    'title' => $title,
                    'description' => $description,
                    'image_path' => $uploadedPath,
                    'phone' => $phone,
                    'email' => $email,
                    'location' => $location,
                    'hours' => $hours,
                    'id' => $advertisementId,
                ]);
                activity($pdo, (int)$user['id'], 'advertisement_edited_by_admin', 'Advertisement #' . $advertisementId . ' edited');
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($uploadedPath !== $oldImage) {
                    remove_uploaded_image($uploadedPath);
                }
                throw $error;
            }
            if ($oldImage !== $uploadedPath) {
                remove_uploaded_image($oldImage);
            }
            $uploadedPath = null;
            set_flash('Advertisement information updated.');
            redirect_to('admin');
        }

        case 'admin_ad_delete': {
            $fallbackPage = 'admin';
            $user = require_user($pdo, ['admin']);
            $advertisementId = filter_var($_POST['advertisement_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$advertisementId) {
                throw new AppException('Choose a valid advertisement.');
            }
            $statement = $pdo->prepare('SELECT id, image_path FROM advertisements WHERE id = :id');
            $statement->execute(['id' => $advertisementId]);
            $ad = $statement->fetch();
            if (!$ad) {
                throw new AppException('Advertisement not found.');
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM advertisements WHERE id = :id')->execute(['id' => $advertisementId]);
                $pdo->prepare('DELETE FROM products WHERE id NOT IN (SELECT product_id FROM advertisements)')->execute();
                activity($pdo, (int)$user['id'], 'advertisement_deleted_by_admin', 'Advertisement #' . $advertisementId . ' deleted');
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
            remove_uploaded_image($ad['image_path']);
            set_flash('Advertisement deleted.');
            redirect_to('admin');
        }

        default:
            throw new AppException('That action is not available.');
    }
} catch (AppException $error) {
    set_flash($error->getMessage(), 'error');
    redirect_to($fallbackPage);
} catch (PDOException $error) {
    if ($error->getCode() === '23000' && ($error->errorInfo[1] ?? null) === 1062) {
        set_flash('An account already uses that email address.', 'error');
        redirect_to($fallbackPage);
    }
    error_log('IceBlock database action failed: ' . $error->getMessage());
    set_flash('The request could not be saved. Please check your input and try again.', 'error');
    redirect_to($fallbackPage);
} catch (Throwable $error) {
    if ($uploadedPath !== null) {
        remove_uploaded_image($uploadedPath);
    }
    error_log('IceBlock action failed: ' . $error->getMessage());
    set_flash('An unexpected error occurred. Please try again.', 'error');
    redirect_to($fallbackPage);
}
