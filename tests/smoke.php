<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

$checks = [
    'HTML text is escaped' => h('<script>"x"</script>') === '&lt;script&gt;&quot;x&quot;&lt;/script&gt;',
    'Email addresses are normalized' => validate_email(' USER@example.com ') === 'user@example.com',
    'Invalid image paths use the default image' => image_url('../config.php') === 'assets/ice-cubes.jpg',
    'Prices use naira formatting' => money(1500) === '₦1,500',
    'Database timestamps display in local time' => date_label('2025-01-02 12:00:00') === '2 Jan 2025, 1:00 pm',
    'CSRF token is emitted in the form' => str_contains(csrf_field(), h(csrf_token())),
];

try {
    validate_email('not-an-email');
    $checks['Invalid email is rejected'] = false;
} catch (AppException) {
    $checks['Invalid email is rejected'] = true;
}

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
}
if ($failed) {
    exit(1);
}
