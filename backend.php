<?php
define('DB_HOST',        'localhost');
define('DB_NAME',        'student_rental_hub');
define('DB_USER',        'root');
define('DB_PASS',        '');
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH','$2y$10$MNYmZAlRtuG1vPlUTJAiOeGAtWVOrgfK0lt.R8K17ECaQ9MHzhAj6');
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // keep errors hidden from JSON output
header('Content-Type: application/json');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
           out(false, [], 'DB Error: ' . $e->getMessage());
        }
    }
    return $pdo;
}

function out(bool $ok, array $data = [], string $msg = ''): void {
    echo json_encode(['success' => $ok, 'message' => $msg] + $data);
    exit;
}

function requireLogin(): array {
    if (empty($_SESSION['user'])) out(false, [], 'Not logged in');
    return $_SESSION['user'];
}

function generateTxnId(): string {
    return 'TXN' . strtoupper(substr(md5(uniqid('', true)), 0, 10));
}

function logActivity(int $userId, string $username, string $action, string $details = ''): void {
    try {
        $db = getDB();
        $ns = $db->prepare("SELECT full_name FROM profiles WHERE user_id=? LIMIT 1");
        $ns->execute([$userId]);
        $row = $ns->fetch();
        $displayName = ($row && !empty($row['full_name'])) ? $row['full_name'] : $username;
        $db->prepare("INSERT INTO activity_logs (user_id,username,action,details) VALUES (?,?,?,?)")
           ->execute([$userId, $displayName, $action, $details]);
    } catch (Exception $e) {}
}

$action = $_POST['action'] ?? '';

/* ---------- LOGIN ---------- */
if ($action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? 'student';

    if (!$username || !$password) out(false, [], 'Please enter username and password.');

    if ($role === 'admin') {
        if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD_HASH)) {
            $_SESSION['user'] = ['id'=>0,'username'=>'admin','role'=>'admin','profile_completed'=>true];
            out(true, ['user' => $_SESSION['user'], 'profile_completed' => true]);
        }
        out(false, [], 'Invalid admin credentials.');
    }

    $db   = getDB();
    $stmt = $db->prepare("SELECT id,username,password,role,profile_completed FROM users WHERE username=? AND role='student' LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user'] = [
            'id'                => (int)$user['id'],
            'username'          => $user['username'],
            'role'              => 'student',
            'profile_completed' => (bool)$user['profile_completed'],
        ];
        $nameCheck = $db->prepare("SELECT full_name FROM profiles WHERE user_id=? LIMIT 1");
        $nameCheck->execute([(int)$user['id']]);
        $nameRow = $nameCheck->fetch();
        $displayName = ($nameRow && !empty($nameRow['full_name'])) ? $nameRow['full_name'] : $user['username'];
        logActivity((int)$user['id'], $displayName, 'LOGIN', 'Student logged in');
        out(true, [
            'user'              => $_SESSION['user'],
            'profile_completed' => (bool)$user['profile_completed']
        ]);
    }
    out(false, [], 'Invalid username or password.');
}

/* ---------- LOGOUT ---------- */
if ($action === 'logout') {
    if (!empty($_SESSION['user'])) {
        $u = $_SESSION['user'];
        if ($u['role'] === 'student') logActivity($u['id'], $u['username'], 'LOGOUT', 'Logged out');
    }
    $_SESSION = [];
    session_destroy();
    out(true, [], 'Logged out');
}

/* ---------- GET PROFILE ---------- */
if ($action === 'getProfile') {
    $user = requireLogin();
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM profiles WHERE user_id=? LIMIT 1");
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();
    out(true, ['profile' => $profile ?: null]);
}

/* ---------- SAVE PROFILE ---------- */
if ($action === 'saveProfile') {
    $user      = requireLogin();
    $full_name = trim($_POST['full_name'] ?? '');
    $age       = (int)($_POST['age'] ?? 0);
    $college   = trim($_POST['college'] ?? '');
    $year      = trim($_POST['year_class'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $aadhaar_number    = trim($_POST['aadhaar_number']    ?? '');
    $college_id_number = trim($_POST['college_id_number'] ?? '');
    $mobile_number = trim($_POST['mobile_number'] ?? '');
    if (!$full_name || !$college) out(false, [], 'Full name and college are required.');

    // Validate Aadhaar
    if ($aadhaar_number && !preg_match('/^\d{12}$/', $aadhaar_number)) {
        out(false, [], 'Aadhaar number must be exactly 12 digits.');
    }

    // Handle file uploads
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $aadhaar_file    = null;
    $college_id_file = null;
    $allowedTypes    = ['image/jpeg','image/png','image/gif','application/pdf'];

    if (!empty($_FILES['aadhaar_file']['tmp_name'])) {
        $mime = mime_content_type($_FILES['aadhaar_file']['tmp_name']);
        if (!in_array($mime, $allowedTypes)) out(false, [], 'Aadhaar: Only image or PDF allowed.');
        $ext = pathinfo($_FILES['aadhaar_file']['name'], PATHINFO_EXTENSION);
        $filename = 'aadhaar_' . $user['id'] . '_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['aadhaar_file']['tmp_name'], $uploadDir . $filename);
        $aadhaar_file = 'uploads/' . $filename;
    }

    if (!empty($_FILES['college_id_file']['tmp_name'])) {
        $mime = mime_content_type($_FILES['college_id_file']['tmp_name']);
        if (!in_array($mime, $allowedTypes)) out(false, [], 'College ID: Only image or PDF allowed.');
        $ext = pathinfo($_FILES['college_id_file']['name'], PATHINFO_EXTENSION);
        $filename = 'collegeid_' . $user['id'] . '_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['college_id_file']['tmp_name'], $uploadDir . $filename);
        $college_id_file = 'uploads/' . $filename;
    }

    $db = getDB();

    // Build dynamic update to avoid overwriting existing files if no new upload
    $sql = "INSERT INTO profiles (user_id,full_name,age,college,year_class,address,aadhaar_number,college_id_number,mobile_number)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          full_name=VALUES(full_name), age=VALUES(age),
          college=VALUES(college), year_class=VALUES(year_class),
          address=VALUES(address), aadhaar_number=VALUES(aadhaar_number),
          college_id_number=VALUES(college_id_number),
          mobile_number=VALUES(mobile_number),
          updated_at=CURRENT_TIMESTAMP";

    $db->prepare($sql)->execute([
    $user['id'],$full_name,$age,$college,$year,$address,
    $aadhaar_number,$college_id_number,$mobile_number
]);
    // Update file paths only if new files uploaded
    if ($aadhaar_file) {
        $db->prepare("UPDATE profiles SET aadhaar_file=? WHERE user_id=?")
           ->execute([$aadhaar_file, $user['id']]);
    }
    if ($college_id_file) {
        $db->prepare("UPDATE profiles SET college_id_file=? WHERE user_id=?")
           ->execute([$college_id_file, $user['id']]);
    }

    $db->prepare("UPDATE users SET profile_completed=1 WHERE id=?")->execute([$user['id']]);
    $_SESSION['user']['profile_completed'] = true;

    logActivity($user['id'], $user['username'], 'PROFILE_UPDATE', "Name: $full_name | College: $college");
    out(true, [], 'Profile saved!');
}

/* ---------- STUDENT STATS ---------- */
if ($action === 'studentStats') {
    $user = requireLogin();
    $db   = getDB();

    $r1 = $db->prepare("SELECT COUNT(*) FROM rentals WHERE user_id=?");
    $r1->execute([$user['id']]);
    $rentals = (int)$r1->fetchColumn();

    $r2 = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM rentals WHERE user_id=?");
    $r2->execute([$user['id']]);
    $spend = (float)$r2->fetchColumn();

    $r3 = $db->prepare("SELECT COUNT(*) FROM ratings WHERE user_id=?");
    $r3->execute([$user['id']]);
    $ratings = (int)$r3->fetchColumn();

    out(true, ['rentals'=>$rentals, 'spend'=>$spend, 'ratings'=>$ratings]);
}

/* ---------- PROCESS RENTAL ---------- */
if ($action === 'processRental') {
    $user = requireLogin();
    $db   = getDB();

    $name    = trim($_POST['name']         ?? '');
    $address = trim($_POST['address']      ?? '');
    $mode    = trim($_POST['payment_mode'] ?? 'UPI');
    $total   = (float)($_POST['total_amount'] ?? 0);
    $items   = json_decode($_POST['items'] ?? '[]', true);

    if (!$name || !$address) out(false, [], 'Name and address are required.');
    if (empty($items))       out(false, [], 'No items in cart.');

    $itemNames = implode(', ', array_column($items, 'item_name'));
    $txn          = generateTxnId();
    $rentDate     = date('Y-m-d');
    $deliveryDate = date('Y-m-d', strtotime('+1 days')); // delivery next day
    $dueDate      = date('Y-m-d', strtotime('+7 days'));

    try {
        $db->prepare(
            "INSERT INTO rentals (user_id,student_name,address,items,total_amount,payment_mode,rent_date,delivery_date,due_date)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([$user['id'],$name,$address,$itemNames,$total,$mode,$rentDate,$deliveryDate,$dueDate]);

        $rentalId = (int)$db->lastInsertId();

        $db->prepare(
            "INSERT INTO payments (user_id,rental_id,transaction_id,amount,payment_mode,status)
             VALUES (?,?,?,?,?,'success')"
        )->execute([$user['id'],$rentalId,$txn,$total,$mode]);

        logActivity($user['id'], $user['username'], 'RENTAL',
            "Rented: $itemNames | ₹$total | $mode | Due: $dueDate | TXN: $txn");

        out(true, [
            'rental_id'      => $rentalId,
            'transaction_id' => $txn,
			'delivery_date'  => $deliveryDate,
            'due_date'       => $dueDate
        ]);
    } catch (Exception $e) {
        out(false, [], 'Rental failed: ' . $e->getMessage());
    }
}
/* ---------- MY RENTALS ---------- */
if ($action === 'myRentals') {
    $user = requireLogin();
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT items,total_amount,payment_mode,rent_date,delivery_date,due_date
         FROM rentals WHERE user_id=? ORDER BY id DESC"
    );
    $stmt->execute([$user['id']]);
    out(true, ['rentals' => $stmt->fetchAll()]);
}

/* ---------- ADMIN STATS ---------- */
if ($action === 'adminStats') {
    requireLogin();
    $db = getDB();
    $students = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
    $rentals  = (int)$db->query("SELECT COUNT(*) FROM rentals")->fetchColumn();
    $revenue  = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM rentals")->fetchColumn();
    $ratings  = (int)$db->query("SELECT COUNT(*) FROM ratings")->fetchColumn();
    out(true, compact('students','rentals','revenue','ratings'));
}

/* ---------- ACTIVITY LOG ---------- */
if ($action === 'activityLog') {
    requireLogin();
    $db   = getDB();
    $logs = $db->query("SELECT username,action,details,created_at FROM activity_logs ORDER BY id DESC LIMIT 100")->fetchAll();
    out(true, ['logs' => $logs]);
}

/* ---------- RENT TRACKING ---------- */
if ($action === 'rentTracking') {
    requireLogin();
    $db   = getDB();
    $stmt = $db->query(
        "SELECT student_name,items,total_amount,rent_date,due_date,
                DATEDIFF(due_date, CURDATE()) AS days_until_due
         FROM rentals ORDER BY due_date ASC"
    );
    out(true, ['rentals' => $stmt->fetchAll()]);
}
/* ---------- SUBMIT RATING ---------- */
if ($action === 'submitRating') {
    $user      = requireLogin();
    $rental_id = (int)($_POST['rental_id'] ?? 0);
    $rating    = (int)($_POST['rating']    ?? 0);
    $review    = trim($_POST['review']     ?? '');

    if ($rating < 1 || $rating > 5) out(false, [], 'Invalid rating.');

    $db = getDB();
    $check = $db->prepare("SELECT id FROM rentals WHERE id=? AND user_id=? LIMIT 1");
    $check->execute([$rental_id, $user['id']]);
    if (!$check->fetch()) out(false, [], 'Invalid rental.');

    // Get full name at time of rating
$nameStmt = $db->prepare("SELECT full_name FROM profiles WHERE user_id=? LIMIT 1");
$nameStmt->execute([$user['id']]);
$nameRow = $nameStmt->fetch();
$fullNameAtRating = ($nameRow && !empty($nameRow['full_name'])) 
                    ? $nameRow['full_name'] 
                    : $user['username'];

$db->prepare(
    "INSERT IGNORE INTO ratings (user_id,rental_id,rating,review,username,full_name) VALUES (?,?,?,?,?,?)"
)->execute([$user['id'],$rental_id,$rating,$review,$user['username'],$fullNameAtRating]);

    logActivity($user['id'], $user['username'], 'RATING', "Gave $rating star(s)".($review ? ": $review" : ''));
    out(true, [], 'Rating submitted!');
}

/* ---------- ALL RATINGS ---------- */
if ($action === 'allRatings') {
    requireLogin();
    $db   = getDB();
    $stmt = $db->query(
    "SELECT 
        COALESCE(NULLIF(TRIM(r.username),''), u.username) AS username,
        COALESCE(NULLIF(TRIM(r.full_name),''), NULLIF(TRIM(r.username),''), u.username) AS display_name,
        r.rating, r.review, r.created_at
     FROM ratings r
     LEFT JOIN users u ON r.user_id = u.id
     ORDER BY r.id DESC"
);
    out(true, ['ratings' => $stmt->fetchAll()]);
}
/* fallback */
/* ---------- STUDENT DOCUMENTS ---------- */
if ($action === 'studentDocuments') {
    requireLogin();
    $db   = getDB();
    $stmt = $db->query(
    "SELECT u.id, u.username,
            COALESCE(NULLIF(TRIM(p.full_name),''), u.username) AS full_name,
            p.college, p.year_class,
            p.mobile_number,
            p.aadhaar_number, p.aadhaar_file,
            p.college_id_number, p.college_id_file
     FROM users u
     LEFT JOIN profiles p ON p.user_id = u.id
     WHERE u.role = 'student'
     ORDER BY u.id ASC"
);
    out(true, ['students' => $stmt->fetchAll()]);
}
out(false, [], 'Unknown action');

?>

