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

function dec($val)
{
    $v = trim($val ?? '');
    return ($v === '' || $v === null) ? null : (float)$v;
}

function handleImage($value)
{
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
        $isGuest   =       ($_GET['guest']    ?? '') === '1';

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
        if ($isGuest) $sql .= " LIMIT 3";

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
        $isGuest   =       ($_GET['guest']    ?? '') === '1';

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
        if ($isGuest) $sql .= " LIMIT 3";

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
        $isGuest   =       ($_GET['guest']    ?? '') === '1';

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
        if ($isGuest) $sql .= " LIMIT 3";

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
        $isGuest   =       ($_GET['guest']  ?? '') === '1';

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
        if ($isGuest) $sql .= " LIMIT 3";

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
        $reply   = "Sorry, I didn't understand that. Try asking about spots, products, events, or businesses in Sto. Tomas!";

        do {
            // ── GREETING ──────────────────────────────────────────
            if (str_contains($message, 'hello') || str_contains($message, 'hi') || str_contains($message, 'hey')) {
                $reply = "Hello! Welcome to TomaSIGLA — your guide to Sto. Tomas, Batangas. How can I help you?";
                break;

            // ── THANK YOU ─────────────────────────────────────────
            } elseif (str_contains($message, 'thank') || str_contains($message, 'salamat')) {
                $reply = "You're welcome! Feel free to ask if you need anything else about Sto. Tomas, Batangas!";
                break;

            // ── HELP ──────────────────────────────────────────────
            } elseif (str_contains($message, 'help') || str_contains($message, 'what can you do')) {
                $reply = "I can help you with:\n- Tourist Spots\n- Local Products\n- Businesses\n- Events\n\nYou can ask things like:\n- Give me spots in Sto. Tomas\n- Address of [place name]\n- Details about [business name]\n- Price of [product name]\n- Upcoming events";
                break;

            // ── ADDRESS OF SPECIFIC PLACE ──────────────────────────
            } elseif (
                str_contains($message, 'address') ||
                str_contains($message, 'where is') ||
                str_contains($message, 'location of') ||
                str_contains($message, 'located')
            ) {
                $found = null;

                $stmt = $pdo->query("SELECT name, address FROM tourist_spots WHERE status='active'");
                foreach ($stmt->fetchAll() as $row) {
                    if (str_contains($message, strtolower($row['name']))) {
                        $found = true;
                        $reply = "{$row['name']} is located at: {$row['address']}";
                        break;
                    }
                }

                if (!$found) {
                    $stmt = $pdo->query("SELECT name, address FROM businesses WHERE status='active'");
                    foreach ($stmt->fetchAll() as $row) {
                        if (str_contains($message, strtolower($row['name']))) {
                            $found = true;
                            $reply = "{$row['name']} is located at: {$row['address']}";
                            break;
                        }
                    }
                }

                if (!$found) {
                    $reply = "I couldn't find that place. Try asking 'give me spots' or 'give me businesses' first to see available names!";
                }
                break;

            // ── DETAILS / INFO ABOUT SPECIFIC PLACE ───────────────
            } elseif (
                str_contains($message, 'about') ||
                str_contains($message, 'info') ||
                str_contains($message, 'details') ||
                str_contains($message, 'tell me')
            ) {
                $found = null;

                $stmt = $pdo->query("SELECT name, description, address FROM tourist_spots WHERE status='active'");
                foreach ($stmt->fetchAll() as $row) {
                    if (str_contains($message, strtolower($row['name']))) {
                        $found = true;
                        $reply = "{$row['name']}\nAddress: {$row['address']}\n{$row['description']}";
                        break;
                    }
                }

                if (!$found) {
                    $stmt = $pdo->query("SELECT name, description, address, contact FROM businesses WHERE status='active'");
                    foreach ($stmt->fetchAll() as $row) {
                        if (str_contains($message, strtolower($row['name']))) {
                            $found = true;
                            $reply = "{$row['name']}\nAddress: {$row['address']}\nContact: {$row['contact']}\n{$row['description']}";
                            break;
                        }
                    }
                }

                if (!$found) {
                    $stmt = $pdo->query("SELECT p.name, p.description, p.price, b.name AS business_name FROM products p LEFT JOIN businesses b ON p.business_id = b.id WHERE p.status='active'");
                    foreach ($stmt->fetchAll() as $row) {
                        if (str_contains($message, strtolower($row['name']))) {
                            $found = true;
                            $bizInfo = $row['business_name'] ? "\nSold at: {$row['business_name']}" : '';
                            $reply = "{$row['name']}\nPrice: P{$row['price']}{$bizInfo}\n{$row['description']}";
                            break;
                        }
                    }
                }

                if (!$found) {
                    $stmt = $pdo->query("SELECT title, description, location, event_date, event_time FROM events WHERE status='active'");
                    foreach ($stmt->fetchAll() as $row) {
                        if (str_contains($message, strtolower($row['title']))) {
                            $found = true;
                            $reply = "{$row['title']}\nLocation: {$row['location']}\nDate: {$row['event_date']} {$row['event_time']}\n{$row['description']}";
                            break;
                        }
                    }
                }

                if (!$found) {
                    $reply = "I couldn't find details for that. Try asking 'give me spots', 'give me businesses', or 'give me products' to see available names!";
                }
                break;

            // ── CONTACT / PHONE ────────────────────────────────────
            } elseif (
                str_contains($message, 'contact') ||
                str_contains($message, 'phone') ||
                str_contains($message, 'number')
            ) {
                $found = null;
                $stmt  = $pdo->query("SELECT name, contact FROM businesses WHERE status='active'");
                foreach ($stmt->fetchAll() as $row) {
                    if (str_contains($message, strtolower($row['name']))) {
                        $found = true;
                        $reply = "Contact for {$row['name']}: {$row['contact']}";
                        break;
                    }
                }
                if (!$found) {
                    $stmt2 = $pdo->query("SELECT name FROM businesses WHERE status='active' LIMIT 5");
                    $list  = implode(', ', array_column($stmt2->fetchAll(), 'name'));
                    $reply = $list
                        ? "Please mention the business name. Available businesses: $list"
                        : "No businesses listed yet.";
                }
                break;

            // ── PRICE OF PRODUCT ───────────────────────────────────
            } elseif (
                str_contains($message, 'price') ||
                str_contains($message, 'cost') ||
                str_contains($message, 'how much')
            ) {
                $found = null;
                $stmt  = $pdo->query("SELECT name, price FROM products WHERE status='active'");
                foreach ($stmt->fetchAll() as $row) {
                    if (str_contains($message, strtolower($row['name']))) {
                        $found = true;
                        $reply = "{$row['name']} costs P{$row['price']}";
                        break;
                    }
                }
                if (!$found) {
                    $stmt2 = $pdo->query("SELECT name FROM products WHERE status='active' LIMIT 5");
                    $list  = implode(', ', array_column($stmt2->fetchAll(), 'name'));
                    $reply = $list
                        ? "Please mention a product name. Available products: $list"
                        : "No products listed yet.";
                }
                break;

            // ── LIST SPOTS ─────────────────────────────────────────
            } elseif (
                str_contains($message, 'spot') ||
                str_contains($message, 'tourist') ||
                str_contains($message, 'place') ||
                str_contains($message, 'visit')
            ) {
                $stmt = $pdo->query("SELECT name FROM tourist_spots WHERE status='active' LIMIT 5");
                $list = implode(', ', array_column($stmt->fetchAll(), 'name'));
                $reply = $list
                    ? "Here are some popular spots in Sto. Tomas: $list.\nAsk me 'address of [name]' or 'about [name]' for more details!"
                    : "No tourist spots are available right now.";
                break;

            // ── LIST PRODUCTS ──────────────────────────────────────
            } elseif (
                str_contains($message, 'product') ||
                str_contains($message, 'pasalubong') ||
                str_contains($message, 'souvenir') ||
                str_contains($message, 'buy') ||
                str_contains($message, 'item')
            ) {
                $stmt = $pdo->query("SELECT name FROM products WHERE status='active' LIMIT 5");
                $list = implode(', ', array_column($stmt->fetchAll(), 'name'));
                $reply = $list
                    ? "Here are some local products in Sto. Tomas: $list.\nAsk me 'price of [name]' or 'about [name]' for more details!"
                    : "No products listed yet.";
                break;

            // ── LIST EVENTS ────────────────────────────────────────
            } elseif (
                str_contains($message, 'event') ||
                str_contains($message, 'festival') ||
                str_contains($message, 'activity') ||
                str_contains($message, 'happening')
            ) {
                $stmt = $pdo->query("SELECT title, event_date, location FROM events WHERE status='active' ORDER BY event_date ASC LIMIT 5");
                $rows = $stmt->fetchAll();
                if ($rows) {
                    $lines = array_map(fn($e) => "{$e['title']} — {$e['event_date']} at {$e['location']}", $rows);
                    $reply = "Upcoming events in Sto. Tomas:\n" . implode("\n", $lines);
                } else {
                    $reply = "No upcoming events right now.";
                }
                break;

            // ── LIST BUSINESSES ────────────────────────────────────
            } elseif (
                str_contains($message, 'business') ||
                str_contains($message, 'shop') ||
                str_contains($message, 'store') ||
                str_contains($message, 'food') ||
                str_contains($message, 'restaurant') ||
                str_contains($message, 'eat')
            ) {
                $stmt = $pdo->query("SELECT name FROM businesses WHERE status='active' LIMIT 5");
                $list = implode(', ', array_column($stmt->fetchAll(), 'name'));
                $reply = $list
                    ? "Popular businesses in Sto. Tomas: $list.\nAsk me 'address of [name]' or 'about [name]' for more details!"
                    : "No businesses listed yet.";
                break;

            // ── GENERAL STO. TOMAS ─────────────────────────────────
            } elseif (
                str_contains($message, 'sto. tomas') ||
                str_contains($message, 'sto tomas') ||
                str_contains($message, 'batangas')
            ) {
                $reply = "Sto. Tomas, Batangas is a great place to visit! Ask me about spots, products, businesses, or events here.";
                break;

            // ── CATCH-ALL NAME SEARCH ──────────────────────────────
            } else {
                // Search spots
                $stmt = $pdo->query("SELECT name, description, address FROM tourist_spots WHERE status='active'");
                foreach ($stmt->fetchAll() as $row) {
                    if (str_contains(strtolower($row['name']), $message)) {
                        $reply = "{$row['name']}\nAddress: {$row['address']}\n{$row['description']}";
                        break 2;
                    }
                }
                // Search businesses
                $stmt = $pdo->query("SELECT name, description, address, contact FROM businesses WHERE status='active'");
                foreach ($stmt->fetchAll() as $row) {
                    if (str_contains(strtolower($row['name']), $message)) {
                        $reply = "{$row['name']}\nAddress: {$row['address']}\nContact: {$row['contact']}\n{$row['description']}";
                        break 2;
                    }
                }
                // Search products
                $stmt = $pdo->query("SELECT name, description, price FROM products WHERE status='active'");
                foreach ($stmt->fetchAll() as $row) {
                    if (str_contains(strtolower($row['name']), $message)) {
                        $reply = "{$row['name']}\nPrice: P{$row['price']}\n{$row['description']}";
                        break 2;
                    }
                }
                // Search events
                $stmt = $pdo->query("SELECT title, description, location, event_date FROM events WHERE status='active'");
                foreach ($stmt->fetchAll() as $row) {
                    if (str_contains(strtolower($row['title']), $message)) {
                        $reply = "{$row['title']}\nLocation: {$row['location']}\nDate: {$row['event_date']}\n{$row['description']}";
                        break 2;
                    }
                }
            }
        } while (false);

        echo json_encode(['success' => true, 'reply' => $reply]);
        break;

    // ============================================================
    // SUGGESTIONS
    // ============================================================

    case 'submit_suggestion':
        $name     = trim($_POST['name']     ?? '');
        $category = trim($_POST['category'] ?? '');

        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Name is required.']);
            exit;
        }
        if (!in_array($category, ['Spot', 'Business', 'Product', 'Event'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid category.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO suggestions (name, category, status)
            VALUES (?, ?, 'pending')
        ");
        $stmt->execute([$name, $category]);

        echo json_encode(['success' => true, 'message' => 'Suggestion submitted!']);
        break;

    case 'get_suggestions':
        $status = $_GET['status'] ?? 'pending';
        $stmt   = $pdo->prepare("SELECT * FROM suggestions WHERE status = ? ORDER BY created_at DESC");
        $stmt->execute([$status]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'approve_suggestion':
        if (empty($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'ID is required.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM suggestions WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_POST['id']]);
        $suggestion = $stmt->fetch();

        if (!$suggestion) {
            echo json_encode(['success' => false, 'message' => 'Suggestion not found.']);
            exit;
        }

        $table = match ($suggestion['category']) {
            'Spot'     => 'tourist_spots',
            'Business' => 'businesses',
            'Product'  => 'products',
            'Event'    => 'events',
            default    => null,
        };

        if (!$table) {
            echo json_encode(['success' => false, 'message' => 'Unknown category.']);
            exit;
        }

        if ($table === 'events') {
            $pdo->prepare("
                INSERT INTO events (title, status, created_at, updated_at)
                VALUES (?, 'active', NOW(), NOW())
            ")->execute([$suggestion['name']]);
        } else {
            $pdo->prepare("
                INSERT INTO $table (name, status, created_at, updated_at)
                VALUES (?, 'active', NOW(), NOW())
            ")->execute([$suggestion['name']]);
        }

        $pdo->prepare("UPDATE suggestions SET status = 'approved', updated_at = NOW() WHERE id = ?")
            ->execute([(int)$_POST['id']]);

        echo json_encode(['success' => true, 'message' => 'Approved and added to ' . $table . '!']);
        break;

    case 'reject_suggestion':
        if (empty($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'ID is required.']);
            exit;
        }

        $pdo->prepare("UPDATE suggestions SET status = 'rejected', updated_at = NOW() WHERE id = ?")
            ->execute([(int)$_POST['id']]);

        echo json_encode(['success' => true, 'message' => 'Suggestion rejected.']);
        break;

    // ============================================================
    // DEFAULT
    // ============================================================

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
        break;
}