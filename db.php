<?php
// ============================================================
// TomaSIGLA — db.php (Supabase / PostgreSQL)
// ============================================================

$host     = 'db.tpvharfxnubwjyguppcq.supabase.co';
$port     = '5432';
$dbname   = 'postgres';
$user     = 'postgres';
$password = 'oddokuro21!';  // paste the password you reset

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}