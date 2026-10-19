<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $pdo = database();
    $pdo->beginTransaction();

    $accounts = [
        [
            'name' => 'Demo Producer',
            'email' => 'producer@example.com',
            'phone' => '+234 800 000 0000',
            'password' => getenv('ICEBLOCK_DEMO_PRODUCER_PASSWORD') ?: 'IceProducer123!',
            'role' => 'producer',
        ],
        [
            'name' => 'Demo Customer',
            'email' => 'customer@example.com',
            'phone' => '+234 800 000 0001',
            'password' => getenv('ICEBLOCK_DEMO_CUSTOMER_PASSWORD') ?: 'IceCustomer123!',
            'role' => 'customer',
        ],
        [
            'name' => 'Demo Administrator',
            'email' => 'admin@example.com',
            'phone' => '+234 800 000 0002',
            'password' => getenv('ICEBLOCK_DEMO_ADMIN_PASSWORD') ?: 'IceAdmin123!',
            'role' => 'admin',
        ],
    ];

    $findUser = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $insertUser = $pdo->prepare(
        'INSERT INTO users (full_name, email, phone, password_hash, role)
         VALUES (:full_name, :email, :phone, :password_hash, :role)'
    );
    $userIds = [];
    foreach ($accounts as $account) {
        $findUser->execute(['email' => $account['email']]);
        $id = $findUser->fetchColumn();
        if (!$id) {
            $insertUser->execute([
                'full_name' => $account['name'],
                'email' => $account['email'],
                'phone' => $account['phone'],
                'password_hash' => password_hash($account['password'], PASSWORD_DEFAULT),
                'role' => $account['role'],
            ]);
            $id = $pdo->lastInsertId();
        }
        $userIds[$account['role']] = (int)$id;
    }

    $producerIdQuery = $pdo->prepare('SELECT id FROM producers WHERE user_id = :user_id');
    $producerIdQuery->execute(['user_id' => $userIds['producer']]);
    $producerId = $producerIdQuery->fetchColumn();
    if (!$producerId) {
        $statement = $pdo->prepare(
            'INSERT INTO producers (user_id, business_name, address, location, description, operating_hours)
             VALUES (:user_id, :business_name, :address, :location, :description, :hours)'
        );
        $statement->execute([
            'user_id' => $userIds['producer'],
            'business_name' => 'Demo Ice Supply',
            'address' => 'Kaduna, Nigeria',
            'location' => 'Kaduna',
            'description' => 'Reliable local ice block supply for shops, homes and events.',
            'hours' => 'Daily, 8:00 am – 6:00 pm',
        ]);
        $producerId = $pdo->lastInsertId();
    }

    $pdo->prepare('INSERT IGNORE INTO administrators (user_id) VALUES (:user_id)')
        ->execute(['user_id' => $userIds['admin']]);

    $advertisementCount = (int)$pdo->query('SELECT COUNT(*) FROM advertisements')->fetchColumn();
    if ($advertisementCount === 0) {
        $samples = [
            ['Standard Ice Blocks — Kaduna', 'Standard Ice Blocks', 'Packaged ice blocks for shops, small businesses and everyday use.', 'Small blocks', 1500, '10 blocks', 'Kaduna'],
            ['Bulk Ice Supply — Zaria', 'Bulk Ice Supply', 'Bulk supply for retailers and event organisers.', 'Large blocks', 12000, '100 blocks', 'Zaria'],
            ['Event Ice Package — Kafanchan', 'Event Ice Package', 'A convenient ice block package for parties and small events.', 'Medium blocks', 5000, '30 blocks', 'Kafanchan'],
        ];
        $productInsert = $pdo->prepare(
            "INSERT INTO products (producer_id, name, description, ice_block_size, price, quantity, status)
             VALUES (:producer_id, :name, :description, :size, :price, :quantity, 'available')"
        );
        $adInsert = $pdo->prepare(
            "INSERT INTO advertisements
             (producer_id, product_id, title, description, image_path, phone, email, location, operating_hours, status)
             VALUES (:producer_id, :product_id, :title, :description, 'assets/ice-cubes.jpg', :phone, :email, :location, :hours, 'active')"
        );
        foreach ($samples as [$title, $name, $description, $size, $price, $quantity, $location]) {
            $productInsert->execute([
                'producer_id' => $producerId,
                'name' => $name,
                'description' => $description,
                'size' => $size,
                'price' => $price,
                'quantity' => $quantity,
            ]);
            $adInsert->execute([
                'producer_id' => $producerId,
                'product_id' => $pdo->lastInsertId(),
                'title' => $title,
                'description' => $description,
                'phone' => '+234 800 000 0000',
                'email' => 'producer@example.com',
                'location' => $location,
                'hours' => 'Daily, 8:00 am – 6:00 pm',
            ]);
        }
    }

    $pdo->commit();
    echo "Sample data is ready.\n";
    echo "Producer: producer@example.com / " . ($accounts[0]['password']) . "\n";
    echo "Customer: customer@example.com / " . ($accounts[1]['password']) . "\n";
    echo "Administrator: admin@example.com / " . ($accounts[2]['password']) . "\n";
    echo "Change the public demonstration passwords before any shared deployment.\n";
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
