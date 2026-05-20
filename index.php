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
        $email    = $_POST['email']    ?? '';
        $password = $_POST['password'] ?? '';
        $type     = $_POST['type']     ?? 'user';

        if (!$email || !$password) {
            echo json_encode(['success' => false, 'message' => 'Email and password required.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = $1 LIMIT 1");
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

        $check = $pdo->prepare("SELECT id FROM users WHERE email = $1 LIMIT 1");
        $check->execute([$email]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already registered.']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES ($1, $2, $3, 'user')");
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

        $sql    = "SELECT * FROM tourist_spots WHERE (name ILIKE $1 OR description ILIKE $2 OR address ILIKE $3)";
        $params = [$search, $search, $search];
        $i = 4;

        if ($category) {
            $sql    .= " AND category = \$$i";
            $params[] = $category;
            $i++;
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
        $img  = handleImage($_POST['image'] ?? '');
        $stmt = $pdo->prepare("
            INSERT INTO tourist_spots (name, category, description, address, latitude, longitude, image, status)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
            RETURNING id
        ");
        $stmt->execute([
            $_POST['name']        ?? '',
            $_POST['category']    ?? '',
            $_POST['description'] ?? '',
            $_POST['address']     ?? '',
            dec($_POST['latitude']  ?? ''),
            dec($_POST['longitude'] ?? ''),
            $img,
            $_POST['status']      ?? 'active',
        ]);
        $row = $stmt->fetch();
        echo json_encode(['success' => true, 'id' => $row['id']]);
        break;

    case 'update_spot':
        $img  = handleImage($_POST['image'] ?? '');
        $stmt = $pdo->prepare("
            UPDATE tourist_spots
            SET name=$1, category=$2, description=$3, address=$4, latitude=$5, longitude=$6, image=$7, status=$8
            WHERE id=$9
        ");
        $stmt->execute([
            $_POST['name']        ?? '',
            $_POST['category']    ?? '',
            $_POST['description'] ?? '',
            $_POST['address']     ?? '',
            dec($_POST['latitude']  ?? ''),
            dec($_POST['longitude'] ?? ''),
            $img,
            $_POST['status']      ?? 'active',
            $_POST['id'],
        ]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_spot':
        $stmt = $pdo->prepare("DELETE FROM tourist_spots WHERE id = $1");
        $stmt->execute([$_POST['id']]);
        echo json_encode(['success' => true]);
        break;

    // ============================================================
    // BUSINESSES
    // ============================================================

    case 'get_businesses':
        $search    = '%' . ($_GET['search']   ?? '') . '%';
        $category  =        $_GET['category'] ?? '';
        $adminMode =       ($_GET['admin']    ?? '') === '1';

        $sql    = "SELECT * FROM businesses WHERE (name ILIKE $1 OR description ILIKE $2 OR address ILIKE $3)";
        $params = [$search, $search, $search];
        $i = 4;

        if ($category) {
            $sql    .= " AND category = \$$i";
            $params[] = $category;
            $i++;
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
        $img       = handleImage($_POST['image'] ?? '');
        $extraImgs = $_POST['images'] ?? '[]';
        $imgsArr   = json_decode($extraImgs, true) ?? [];
        $imgsArr   = array_map('handleImage', $imgsArr);
        $imgsJson  = json_encode(array_values(array_filter($imgsArr)));

        $stmt = $pdo->prepare("
            INSERT INTO businesses (name, category, description, address, contact, latitude, longitude, image, images, status)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
            RETURNING id
        ");
        $stmt->execute([
            $_POST['name']        ?? '',
            $_POST['category']    ?? '',
            $_POST['description'] ?? '',
            $_POST['address']     ?? '',
            $_POST['contact']     ?? '',
            dec($_POST['latitude']  ?? ''),
            dec($_POST['longitude'] ?? ''),
            $img,
            $imgsJson,
            $_POST['status']      ?? 'active',
        ]);
        $row = $stmt->fetch();
        echo json_encode(['success' => true, 'id' => $row['id']]);
        break;

    case 'update_business':
        $img       = handleImage($_POST['image'] ?? '');
        $extraImgs = $_POST['images'] ?? '[]';
        $imgsArr   = json_decode($extraImgs, true) ?? [];
        $imgsArr   = array_map('handleImage', $imgsArr);
        $imgsJson  = json_encode(array_values(array_filter($imgsArr)));

        $stmt = $pdo->prepare("
            UPDATE businesses
            SET name=$1, category=$2, description=$3, address=$4, contact=$5, latitude=$6, longitude=$7, image=$8, images=$9, status=$10
            WHERE id=$11
        ");
        $stmt->execute([
            $_POST['name']        ?? '',
            $_POST['category']    ?? '',
            $_POST['description'] ?? '',
            $_POST['address']     ?? '',
            $_POST['contact']     ?? '',
            dec($_POST['latitude']  ?? ''),
            dec($_POST['longitude'] ?? ''),
            $img,
            $imgsJson,
            $_POST['status']      ?? 'active',
            $_POST['id'],
        ]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_business':
        $stmt = $pdo->prepare("DELETE FROM businesses WHERE id = $1");
        $stmt->execute([$_POST['id']]);
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
            WHERE (p.name ILIKE $1 OR p.description ILIKE $2)
        ";
        $params = [$search, $search];
        $i = 3;

        if ($category) {
            $sql    .= " AND p.category = \$$i";
            $params[] = $category;
            $i++;
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
        $img   = handleImage($_POST['image'] ?? '');
        $bizId = trim($_POST['business_id'] ?? '');
        $stmt  = $pdo->prepare("
            INSERT INTO products (name, category, description, price, business_id, image, status)
            VALUES ($1, $2, $3, $4, $5, $6, $7)
            RETURNING id
        ");
        $stmt->execute([
            $_POST['name']        ?? '',
            $_POST['category']    ?? '',
            $_POST['description'] ?? '',
            dec($_POST['price']   ?? '') ?? 0,
            $bizId !== '' ? (int)$bizId : null,
            $img,
            $_POST['status']      ?? 'active',
        ]);
        $row = $stmt->fetch();
        echo json_encode(['success' => true, 'id' => $row['id']]);
        break;

    case 'update_product':
        $img   = handleImage($_POST['image'] ?? '');
        $bizId = trim($_POST['business_id'] ?? '');
        $stmt  = $pdo->prepare("
            UPDATE products
            SET name=$1, category=$2, description=$3, price=$4, business_id=$5, image=$6, status=$7
            WHERE id=$8
        ");
        $stmt->execute([
            $_POST['name']        ?? '',
            $_POST['category']    ?? '',
            $_POST['description'] ?? '',
            dec($_POST['price']   ?? '') ?? 0,
            $bizId !== '' ? (int)$bizId : null,
            $img,
            $_POST['status']      ?? 'active',
            $_POST['id'],
        ]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_product':
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = $1");
        $stmt->execute([$_POST['id']]);
        echo json_encode(['success' => true]);
        break;

    // ============================================================
    // EVENTS
    // ============================================================

    case 'get_events':
        $search    = '%' . ($_GET['search'] ?? '') . '%';
        $type      =        $_GET['type']   ?? '';
        $adminMode =       ($_GET['admin']  ?? '') === '1';

        $sql    = "SELECT * FROM events WHERE (title ILIKE $1 OR description ILIKE $2 OR location ILIKE $3)";
        $params = [$search, $search, $search];
        $i = 4;

        if ($type) {
            $sql    .= " AND type = \$$i";
            $params[] = $type;
            $i++;
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
        $date = trim($_POST['event_date'] ?? '');
        $time = trim($_POST['event_time'] ?? '');
        $stmt = $pdo->prepare("
            INSERT INTO events (title, type, description, location, event_date, event_time, status)
            VALUES ($1, $2, $3, $4, $5, $6, $7)
            RETURNING id
        ");
        $stmt->execute([
            $_POST['title']       ?? '',
            $_POST['type']        ?? '',
            $_POST['description'] ?? '',
            $_POST['location']    ?? '',
            $date !== '' ? $date : null,
            $time !== '' ? $time : null,
            $_POST['status']      ?? 'active',
        ]);
        $row = $stmt->fetch();
        echo json_encode(['success' => true, 'id' => $row['id']]);
        break;

    case 'update_event':
        $date = trim($_POST['event_date'] ?? '');
        $time = trim($_POST['event_time'] ?? '');
        $stmt = $pdo->prepare("
            UPDATE events
            SET title=$1, type=$2, description=$3, location=$4, event_date=$5, event_time=$6, status=$7
            WHERE id=$8
        ");
        $stmt->execute([
            $_POST['title']       ?? '',
            $_POST['type']        ?? '',
            $_POST['description'] ?? '',
            $_POST['location']    ?? '',
            $date !== '' ? $date : null,
            $time !== '' ? $time : null,
            $_POST['status']      ?? 'active',
            $_POST['id'],
        ]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_event':
        $stmt = $pdo->prepare("DELETE FROM events WHERE id = $1");
        $stmt->execute([$_POST['id']]);
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

        // PostgreSQL upsert (replaces MySQL ON DUPLICATE KEY UPDATE)
        $stmt = $pdo->prepare("
            INSERT INTO app_settings (key, value) VALUES ($1, $2)
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
            INSERT INTO app_settings (key, value) VALUES ($1, $2)
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
        $userMessage = trim($_POST['message'] ?? '');
        $context     = trim($_POST['context'] ?? '');

        if (!$userMessage) {
            echo json_encode(['success' => false, 'reply' => 'No message received.']);
            exit;
        }

        if (!$context) {
            $lines = [];

            $spots = $pdo->query("SELECT name, category, address, description FROM tourist_spots WHERE status='active'")->fetchAll();
            if ($spots) {
                $lines[] = "=== TOURIST SPOTS ===";
                foreach ($spots as $s) {
                    $lines[] = "- Name: {$s['name']}";
                    if ($s['category'])    $lines[] = "  Category: {$s['category']}";
                    if ($s['address'])     $lines[] = "  Address: {$s['address']}";
                    if ($s['description']) $lines[] = "  Description: {$s['description']}";
                }
            }

            $businesses = $pdo->query("SELECT name, category, address, contact, description FROM businesses WHERE status='active'")->fetchAll();
            if ($businesses) {
                $lines[] = ""; $lines[] = "=== BUSINESSES ===";
                foreach ($businesses as $b) {
                    $lines[] = "- Name: {$b['name']}";
                    if ($b['category'])    $lines[] = "  Category: {$b['category']}";
                    if ($b['address'])     $lines[] = "  Address: {$b['address']}";
                    if ($b['contact'])     $lines[] = "  Contact: {$b['contact']}";
                    if ($b['description']) $lines[] = "  Description: {$b['description']}";
                }
            }

            $products = $pdo->query("SELECT p.name, p.description, p.price, b.name AS seller FROM products p LEFT JOIN businesses b ON p.business_id = b.id WHERE p.status='active'")->fetchAll();
            if ($products) {
                $lines[] = ""; $lines[] = "=== LOCAL PRODUCTS ===";
                foreach ($products as $p) {
                    $lines[] = "- Product: {$p['name']}";
                    if ($p['price'])       $lines[] = "  Price: ₱{$p['price']}";
                    if ($p['seller'])      $lines[] = "  Seller: {$p['seller']}";
                    if ($p['description']) $lines[] = "  Description: {$p['description']}";
                }
            }

            $events = $pdo->query("SELECT title, type, description, location, event_date, event_time FROM events WHERE status='active' ORDER BY event_date ASC")->fetchAll();
            if ($events) {
                $lines[] = ""; $lines[] = "=== EVENTS ===";
                foreach ($events as $e) {
                    $lines[] = "- Event: {$e['title']}";
                    if ($e['type'])        $lines[] = "  Type: {$e['type']}";
                    if ($e['event_date'])  $lines[] = "  Date: {$e['event_date']}";
                    if ($e['event_time'])  $lines[] = "  Time: {$e['event_time']}";
                    if ($e['location'])    $lines[] = "  Location: {$e['location']}";
                    if ($e['description']) $lines[] = "  Description: {$e['description']}";
                }
            }

            $context = implode("\n", $lines);
        }

        $systemPrompt =
            "You are TomAsk, a friendly and helpful chatbot assistant for the TomaSIGLA app — " .
            "a local guide for Sto. Tomas, Batangas, Philippines. " .
            "Answer ONLY using the data provided below. " .
            "If the answer is not in the data, say you don't have that information yet but suggest they check the app. " .
            "Be concise, friendly, and use Filipino warmth. Use ₱ for prices.\n\n" .
            $context;

        $apiKey = getenv('OPENROUTER_API_KEY');

        $payload = json_encode([
            'model'    => 'mistralai/mistral-7b-instruct:free',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userMessage],
            ],
            'max_tokens' => 300,
        ]);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: https://tomasigla.com',
                'X-Title: TomaSIGLA',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $reply = "Sorry, I couldn't get a response right now. Please try again!";
        if ($result && $httpCode === 200) {
            $decoded = json_decode($result, true);
            $reply   = trim($decoded['choices'][0]['message']['content'] ?? $reply);
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