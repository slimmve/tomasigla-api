<?php
// ============================================================
// TomaSIGLA API — index.php (Supabase / PostgreSQL version)
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ]);
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $err['message'],
            'file'    => basename($err['file']),
            'line'    => $err['line'],
        ]);
    }
});

require_once __DIR__ . "/db.php";

$action = $_GET['action'] ?? '';

// ============================================================
// HELPERS
// ============================================================

function dec($val) {
    $v = trim($val ?? '');
    return ($v === '' || $v === null) ? null : (float)$v;
}

function handleImage($value) {
    if (empty($value)) return '';

    if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
        return $value;
    }

    if (preg_match('/^data:(image\/\w+);base64,(.+)$/s', $value, $m)) {
        $mime      = $m[1];
        $data      = base64_decode($m[2]);
        $ext       = str_replace('image/', '', $mime);
        $ext       = ($ext === 'jpeg') ? 'jpg' : $ext;
        $filename  = uniqid('img_', true) . '.' . $ext;
        $uploadDir = __DIR__ . '/uploads/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        file_put_contents($uploadDir . $filename, $data);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . '/uploads/' . $filename;
    }

    return $value;
}

// ============================================================
// ROUTER
// ============================================================

switch ($action) {

    // ============================================================
    // AUTH
    // ============================================================

    case 'login':
        $email    = trim($_POST['email']    ?? '');
        $password =      $_POST['password'] ?? '';
        $type     =      $_POST['type']     ?? 'user';

        if (!$email || !$password) {
            echo json_encode(['success' => false, 'message' => 'Email and password required.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            exit;
        }

        if ($type === 'admin' && $user['role'] !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Access denied. Admins only.']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'user'    => [
                'id'    => $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ]
        ]);
        break;

    case 'register':
        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password =      $_POST['password'] ?? '';

        if (!$name || !$email || !$password) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit;
        }

        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check->execute([$email]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already registered.']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'user')");
        $stmt->execute([$name, $email, $hash]);

        echo json_encode(['success' => true, 'message' => 'Registered successfully.']);
        break;

    // ============================================================
    // TOURIST SPOTS
    // ============================================================

    case 'get_spots':
        $search    = '%' . ($_GET['search']   ?? '') . '%';
        $category  =        $_GET['category'] ?? '';
        $adminMode =       ($_GET['admin']    ?? '') === '1';

        $sql    = "SELECT * FROM tourist_spots WHERE (name ILIKE ? OR description ILIKE ? OR address ILIKE ?)";
        $params = [$search, $search, $search];

        if ($category) {
            $sql    .= " AND category = ?";
            $params[] = $category;
        }

        if (!$adminMode) {
            $sql .= " AND status = 'active'";
        }

        $sql .= " ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'add_spot':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Name is required.']);
            exit;
        }

        $img  = handleImage($_POST['image'] ?? '');
        $stmt = $pdo->prepare("
            INSERT INTO tourist_spots (name, category, description, address, latitude, longitude, image, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $name,
            trim($_POST['category']    ?? ''),
            trim($_POST['description'] ?? ''),
            trim($_POST['address']     ?? ''),
            dec($_POST['latitude']  ?? ''),
            dec($_POST['longitude'] ?? ''),
            $img,
            trim($_POST['status']      ?? 'active'),
        ]);
        $row = $stmt->fetch();
        echo json_encode(['success' => true, 'id' => $row['id']]);
        break;

    case 'update_spot':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Name is required.']);
            exit;
        }
        if (empty($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'ID is required.']);
            exit;
        }

        $img  = handleImage($_POST['image'] ?? '');
        $stmt = $pdo->prepare("
            UPDATE tourist_spots
            SET name=?, category=?, description=?, address=?, latitude=?, longitude=?, image=?, status=?
            WHERE id=?
        ");
        $stmt->execute([
            $name,
            trim($_POST['category']    ?? ''),
            trim($_POST['description'] ?? ''),
            trim($_POST['address']     ?? ''),
            dec($_POST['latitude']  ?? ''),
            dec($_POST['longitude'] ?? ''),
            $img,
            trim($_POST['status']      ?? 'active'),
            (int)$_POST['id'],
        ]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_spot':
        if (empty($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'ID is required.']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM tourist_spots WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
        echo json_encode(['success' => true]);
        break;

    // ============================================================
    // BUSINESSES
    // ============================================================

    case 'get_businesses':
        $search    = '%' . ($_GET['search']   ?? '') . '%';
        $category  =        $_GET['category'] ?? '';
        $adminMode =       ($_GET['admin']    ?? '') === '1';

        $sql    = "SELECT * FROM businesses WHERE (name ILIKE ? OR description ILIKE ? OR address ILIKE ?)";
        $params = [$search, $search, $search];

        if ($category) {
            $sql    .= " AND category = ?";
            $params[] = $category;
        }

        if (!$adminMode) {
            $sql .= " AND status = 'active'";
        }

        $sql .= " ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['images'] = !empty($row['images']) ? json_decode($row['images'], true) : [];
        }

        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    case 'add_business':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Name is required.']);
            exit;
        }

        $img       = handleImage($_POST['image'] ?? '');
        $extraImgs = $_POST['images'] ?? '[]';
        $imgsArr   = json_decode($extraImgs, true) ?? [];
        $imgsArr   = array_map('handleImage', $imgsArr);
        $imgsJson  = json_encode(array_values(array_filter($imgsArr)));

        $stmt = $pdo->prepare("
            INSERT INTO businesses (name, category, description, address, contact, latitude, longitude, image, images, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $name,
            trim($_POST['category']    ?? ''),
            trim($_POST['description'] ?? ''),
            trim($_POST['address']     ?? ''),
            trim($_POST['contact']     ?? ''),
            dec($_POST['latitude']  ?? ''),
            dec($_POST['longitude'] ?? ''),
            $img,
            $imgsJson,
            trim($_POST['status']      ?? 'active'),
        ]);
        $row = $stmt->fetch();
        echo json_encode(['success' => true, 'id' => $row['id']]);
        break;

    case 'update_business':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Name is required.']);
            exit;
        }
        if (empty($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'ID is required.']);
            exit;
        }

        $img       = handleImage($_POST['image'] ?? '');
        $extraImgs = $_POST['images'] ?? '[]';
        $imgsArr   = json_decode($extraImgs, true) ?? [];
        $imgsArr   = array_map('handleImage', $imgsArr);
        $imgsJson  = json_encode(array_values(array_filter($imgsArr)));

        $stmt = $pdo->prepare("
            UPDATE businesses
            SET name=?, category=?, description=?, address=?, contact=?, latitude=?, longitude=?, image=?, images=?, status=?
            WHERE id=?
        ");
        $stmt->execute([
            $name,
            trim($_POST['category']    ?? ''),
            trim($_POST['description'] ?? ''),
            trim($_POST['address']     ?? ''),
            trim($_POST['contact']     ?? ''),
            dec($_POST['latitude']  ?? ''),
            dec($_POST['longitude'] ?? ''),
            $img,
            $imgsJson,
            trim($_POST['status']      ?? 'active'),
            (int)$_POST['id'],
        ]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_business':
        if (empty($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'ID is required.']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM businesses WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
        echo json_encode(['success' => true]);
        break;

    // ============================================================
    // PRODUCTS
    // ============================================================

    case 'get_products':
        $search    = '%' . ($_GET['search']   ?? '') . '%';
        $category  =        $_GET['category'] ?? '';
        $adminMode =       ($_GET['admin']    ?? '') === '1';

        $sql    = "
            SELECT p.*, b.name AS business_name
            FROM products p
            LEFT JOIN businesses b ON p.business_id = b.id
            WHERE (p.name ILIKE ? OR p.description ILIKE ?)
        ";
        $params = [$search, $search];

        if ($category) {
            $sql    .= " AND p.category = ?";
            $params[] = $category;
        }

        if (!$adminMode) {
            $sql .= " AND p.status = 'active'";
        }

        $sql .= " ORDER BY p.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'add_product':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Name is required.']);
            exit;
        }

        $img   = handleImage($_POST['image'] ?? '');
        $bizId = trim($_POST['business_id'] ?? '');
        $stmt  = $pdo->prepare("
            INSERT INTO products (name, category, description, price, business_id, image, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $name,
            trim($_POST['category']    ?? ''),
            trim($_POST['description'] ?? ''),
            dec($_POST['price']        ?? '') ?? 0,
            $bizId !== '' ? (int)$bizId : null,
            $img,
            trim($_POST['status']      ?? 'active'),
        ]);
        $row = $stmt->fetch();
        echo json_encode(['success' => true, 'id' => $row['id']]);
        break;

    case 'update_product':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Name is required.']);
            exit;
        }
        if (empty($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'ID is required.']);
            exit;
        }

        $img   = handleImage($_POST['image'] ?? '');
        $bizId = trim($_POST['business_id'] ?? '');
        $stmt  = $pdo->prepare("
            UPDATE products
            SET name=?, category=?, description=?, price=?, business_id=?, image=?, status=?
            WHERE id=?
        ");
        $stmt->execute([
            $name,
            trim($_POST['category']    ?? ''),
            trim($_POST['description'] ?? ''),
            dec($_POST['price']        ?? '') ?? 0,
            $bizId !== '' ? (int)$bizId : null,
            $img,
            trim($_POST['status']      ?? 'active'),
            (int)$_POST['id'],
        ]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_product':
        if (empty($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'ID is required.']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
        echo json_encode(['success' => true]);
        break;

    // ============================================================
    // EVENTS
    // ============================================================

    case 'get_events':
        $search    = '%' . ($_GET['search'] ?? '') . '%';
        $type      =        $_GET['type']   ?? '';
        $adminMode =       ($_GET['admin']  ?? '') === '1';

        $sql    = "SELECT * FROM events WHERE (title ILIKE ? OR description ILIKE ? OR location ILIKE ?)";
        $params = [$search, $search, $search];

        if ($type) {
            $sql    .= " AND type = ?";
            $params[] = $type;
        }

        if (!$adminMode) {
            $sql .= " AND status = 'active'";
        }

        $sql .= " ORDER BY event_date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'add_event':
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            echo json_encode(['success' => false, 'message' => 'Title is required.']);
            exit;
        }

        $date = trim($_POST['event_date'] ?? '');
        $time = trim($_POST['event_time'] ?? '');
        $stmt = $pdo->prepare("
            INSERT INTO events (title, type, description, location, event_date, event_time, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $title,
            trim($_POST['type']        ?? ''),
            trim($_POST['description'] ?? ''),
            trim($_POST['location']    ?? ''),
            $date !== '' ? $date : null,
            $time !== '' ? $time : null,
            trim($_POST['status']      ?? 'active'),
        ]);
        $row = $stmt->fetch();
        echo json_encode(['success' => true, 'id' => $row['id']]);
        break;

    case 'update_event':
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            echo json_encode(['success' => false, 'message' => 'Title is required.']);
            exit;
        }
        if (empty($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'ID is required.']);
            exit;
        }

        $date = trim($_POST['event_date'] ?? '');
        $time = trim($_POST['event_time'] ?? '');
        $stmt = $pdo->prepare("
            UPDATE events
            SET title=?, type=?, description=?, location=?, event_date=?, event_time=?, status=?
            WHERE id=?
        ");
        $stmt->execute([
            $title,
            trim($_POST['type']        ?? ''),
            trim($_POST['description'] ?? ''),
            trim($_POST['location']    ?? ''),
            $date !== '' ? $date : null,
            $time !== '' ? $time : null,
            trim($_POST['status']      ?? 'active'),
            (int)$_POST['id'],
        ]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_event':
        if (empty($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'ID is required.']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
        echo json_encode(['success' => true]);
        break;

    // ============================================================
    // APP SETTINGS
    // ============================================================

    case 'get_settings':
        $stmt = $pdo->query("SELECT key, value FROM app_settings");
        $rows = $stmt->fetchAll();
        $data = [];
        foreach ($rows as $row) {
            $data[$row['key']] = $row['value'];
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'update_setting':
        $key   = trim($_POST['key']   ?? '');
        $value =      $_POST['value'] ?? '';

        if (!$key) {
            echo json_encode(['success' => false, 'message' => 'Key is required.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO app_settings (key, value) VALUES (?, ?)
            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$key, $value]);
        echo json_encode(['success' => true]);
        break;

    case 'update_settings_bulk':
        $raw   = $_POST['pairs'] ?? '{}';
        $pairs = json_decode($raw, true);

        if (!is_array($pairs)) {
            echo json_encode(['success' => false, 'message' => 'Invalid pairs JSON.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO app_settings (key, value) VALUES (?, ?)
            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = CURRENT_TIMESTAMP
        ");

        $pdo->beginTransaction();
        try {
            foreach ($pairs as $k => $v) {
                $stmt->execute([trim($k), $v]);
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'updated' => count($pairs)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ============================================================
    // CHATBOT
    // ============================================================

    case 'chatbot':
        $message = strtolower(trim($_POST['message'] ?? ''));

        $reply = "Sorry, I didn't understand that. Try asking about spots, events, or businesses in Sto. Tomas!";

        if (str_contains($message, 'spot') || str_contains($message, 'tourist')) {
            $stmt = $pdo->query("SELECT name FROM tourist_spots WHERE status='active' LIMIT 3");
            $list = implode(', ', array_column($stmt->fetchAll(), 'name'));
            $reply = $list
                ? "Here are some popular spots: $list. Check the map for directions!"
                : "No tourist spots are available right now.";
        } elseif (str_contains($message, 'event') || str_contains($message, 'festival')) {
            $stmt = $pdo->query("SELECT title FROM events WHERE status='active' ORDER BY event_date ASC LIMIT 3");
            $list = implode(', ', array_column($stmt->fetchAll(), 'title'));
            $reply = $list
                ? "Upcoming events: $list. Visit the Events tab for details!"
                : "No upcoming events right now.";
        } elseif (str_contains($message, 'business') || str_contains($message, 'shop') || str_contains($message, 'food')) {
            $stmt = $pdo->query("SELECT name FROM businesses WHERE status='active' LIMIT 3");
            $list = implode(', ', array_column($stmt->fetchAll(), 'name'));
            $reply = $list
                ? "Popular businesses: $list. Check the Business tab for more!"
                : "No businesses listed yet.";
        } elseif (str_contains($message, 'hello') || str_contains($message, 'hi') || str_contains($message, 'hey')) {
            $reply = "Hello! Welcome to TomaSIGLA — your guide to Sto. Tomas, Batangas. How can I help you?";
        }

        echo json_encode(['success' => true, 'reply' => $reply]);
        break;

    // ============================================================
    // DEFAULT
    // ============================================================

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
        break;
}
?>