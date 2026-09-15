<?php
chdir('/var/www/html/ospos');
require 'vendor/autoload.php';
require 'app/Config/Paths.php';
$paths = new Config\Paths();
require BASEPATH . 'bootstrap.php';
$app = new CodeIgniter\CodeIgniter($paths);
$app->initialize();

$employee = model('Employee');

$person_data = [
    'first_name' => 'Test', 'last_name' => 'Employee', 'gender' => 0,
    'email' => 'test.employee@example.com', 'phone_number' => '', 'address_1' => '',
    'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => ''
];
$employee_data = [
    'username' => 'testemployee',
    'password' => password_hash('testpassword', PASSWORD_DEFAULT),
    'hash_version' => 2,
    'language_code' => 'english',
    'language' => 'english'
];
$grants_data = [
    ['permission_id' => 'items', 'menu_group' => 'home']
];

try {
    $ok = $employee->save_employee($person_data, $employee_data, $grants_data, -1);
    echo $ok ? "SAVE OK, person_id=" . $employee_data['person_id'] . "\n" : "SAVE FAILED\n";
} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}