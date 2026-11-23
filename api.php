<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Admin-Key");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

const ADMIN_KEY = "miawpaw_admin_2026";

$host = "127.0.0.1";
$db   = "miawpaw_db";
$user = "root";
$pass = "";
$charset = "utf8mb4";

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Koneksi MySQL gagal: " . $e->getMessage()
    ]);
    exit;
}

function readJsonBody(): array {
    $raw = file_get_contents("php://input");
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function requireAdminKey(array $input): void {
    $headerKey = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
    $bodyKey = $input['admin_key'] ?? '';
    $provided = $headerKey !== '' ? $headerKey : $bodyKey;
    if (!hash_equals(ADMIN_KEY, (string)$provided)) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "Akses ditolak: khusus admin"]);
        exit;
    }
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'ping') {
    echo json_encode(["ok" => true, "message" => "API & MySQL terhubung"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'pawmate_list') {
    try {
        $stmt = $pdo->query("
            SELECT id, customer_name, nickname, instagram, drink_name AS drinkName, personality, note, created_at
            FROM pawmate_entries
            ORDER BY created_at DESC
            LIMIT 200
        ");
        echo json_encode(["ok" => true, "data" => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "Gagal membaca pawmate_entries: " . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'orders_list') {
    try {
        $stmt = $pdo->query("
            SELECT id, customer_name, phone, instagram, fulfillment, payment, delivery_address, notes, items_json, total_price, join_pawmate, created_at
            FROM orders
            ORDER BY created_at DESC
            LIMIT 200
        ");
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $decodedItems = json_decode($r['items_json'] ?? '[]', true);
            $r['items'] = is_array($decodedItems) ? $decodedItems : [];
        }
        echo json_encode(["ok" => true, "data" => $rows]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "Gagal membaca orders: " . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'checkout') {
    $input = readJsonBody();
    $order = $input['order'] ?? null;
    $pawmateEntries = $input['pawmate_entries'] ?? [];

    if (!is_array($order) || empty(trim($order['customer_name'] ?? ''))) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Nama pemesan wajib diisi"]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $orderId = 'ord_' . bin2hex(random_bytes(8));
        $customerName = mb_substr(trim($order['customer_name']), 0, 150);
        $phone = mb_substr(trim($order['phone'] ?? '-') ?: '-', 0, 50);
        $instagram = mb_substr(trim($order['instagram'] ?? '-') ?: '-', 0, 100);
        $fulfillment = in_array($order['fulfillment'] ?? '', ['Pickup', 'Delivery'], true) ? $order['fulfillment'] : 'Pickup';
        $payment = in_array($order['payment'] ?? '', ['QRIS', 'Bank transfer', 'Cash'], true) ? $order['payment'] : 'QRIS';
        $deliveryAddress = mb_substr(trim($order['delivery_address'] ?? ''), 0, 500);

        $baseNotes = trim($order['notes'] ?? '') ?: 'Tidak ada catatan';
        $notes = ($fulfillment === 'Delivery' && $deliveryAddress !== '')
            ? mb_substr("[Lokasi: $deliveryAddress] " . $baseNotes, 0, 1000)
            : mb_substr($baseNotes, 0, 1000);

        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $totalPrice = max(0, (int)($order['total_price'] ?? 0));
        $joinPawmate = !empty($order['join_pawmate']) ? 1 : 0;

        $stmtOrder = $pdo->prepare("
            INSERT INTO orders (
                id, customer_name, phone, instagram, fulfillment, payment, delivery_address, notes, items_json, total_price, join_pawmate
            ) VALUES (
                :id, :customer_name, :phone, :instagram, :fulfillment, :payment, :delivery_address, :notes, :items_json, :total_price, :join_pawmate
            )
        ");

        $stmtOrder->execute([
            ':id' => $orderId,
            ':customer_name' => $customerName,
            ':phone' => $phone,
            ':instagram' => $instagram,
            ':fulfillment' => $fulfillment,
            ':payment' => $payment,
            ':delivery_address' => $fulfillment === 'Delivery' && $deliveryAddress !== '' ? $deliveryAddress : null,
            ':notes' => $notes,
            ':items_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
            ':total_price' => $totalPrice,
            ':join_pawmate' => $joinPawmate,
        ]);

        $allowedPersonalities = ['matcha', 'taro', 'strawberry', 'chocolate'];
        $insertedPawmateIds = [];

        if ($joinPawmate && is_array($pawmateEntries) && count($pawmateEntries) > 0) {
            $stmtPm = $pdo->prepare("
                INSERT INTO pawmate_entries (
                    id, customer_name, nickname, instagram, drink_name, personality, note
                ) VALUES (
                    :id, :customer_name, :nickname, :instagram, :drink_name, :personality, :note
                )
            ");

            foreach ($pawmateEntries as $idx => $entry) {
                $ig = mb_substr(trim($entry['instagram'] ?? ''), 0, 100);
                $drink = mb_substr(trim($entry['drink_name'] ?? ''), 0, 100);
                if ($ig === '' || $drink === '') continue;

                $pmId = 'pm_' . bin2hex(random_bytes(6)) . '_' . $idx;
                $nick = mb_substr(trim($entry['nickname'] ?? ltrim($ig, '@')), 0, 100);
                $rawPers = trim($entry['personality'] ?? 'matcha');
                $pers = in_array($rawPers, $allowedPersonalities, true) ? $rawPers : 'matcha';
                $note = mb_substr(trim($entry['note'] ?? "Pesanan dari $customerName untuk $drink"), 0, 1000);

                $stmtPm->execute([
                    ':id' => $pmId,
                    ':customer_name' => $customerName,
                    ':nickname' => $nick,
                    ':instagram' => $ig,
                    ':drink_name' => $drink,
                    ':personality' => $pers,
                    ':note' => $note,
                ]);
                $insertedPawmateIds[] = $pmId;
            }
        }

        $pdo->commit();
        echo json_encode([
            "ok" => true,
            "order_id" => $orderId,
            "pawmate_ids" => $insertedPawmateIds
        ]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "Gagal menyimpan ke MySQL: " . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_order') {
    $input = readJsonBody();
    requireAdminKey($input);
    $id = trim($input['id'] ?? '');
    if ($id === '') {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "ID order wajib ada"]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE orders
            SET customer_name = :customer_name,
                phone = :phone,
                instagram = :instagram,
                fulfillment = :fulfillment,
                payment = :payment,
                delivery_address = :delivery_address,
                notes = :notes,
                total_price = :total_price
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':customer_name' => trim($input['customer_name'] ?? '-'),
            ':phone' => trim($input['phone'] ?? '-'),
            ':instagram' => trim($input['instagram'] ?? '-'),
            ':fulfillment' => trim($input['fulfillment'] ?? 'Pickup'),
            ':payment' => trim($input['payment'] ?? 'QRIS'),
            ':delivery_address' => trim($input['delivery_address'] ?? '') ?: null,
            ':notes' => trim($input['notes'] ?? '-'),
            ':total_price' => max(0, (int)($input['total_price'] ?? 0)),
        ]);
        echo json_encode(["ok" => true, "affected" => $stmt->rowCount()]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "Gagal update order: " . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_order') {
    $input = readJsonBody();
    requireAdminKey($input);
    $id = trim($input['id'] ?? '');
    if ($id === '') {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "ID order wajib ada"]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM orders WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(["ok" => true, "affected" => $stmt->rowCount()]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "Gagal hapus order: " . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_pawmate') {
    $input = readJsonBody();
    requireAdminKey($input);
    $id = trim($input['id'] ?? '');
    if ($id === '') {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "ID pawmate wajib ada"]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE pawmate_entries
            SET customer_name = :customer_name,
                nickname = :nickname,
                instagram = :instagram,
                drink_name = :drink_name,
                personality = :personality,
                note = :note
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':customer_name' => trim($input['customer_name'] ?? '-'),
            ':nickname' => trim($input['nickname'] ?? '-'),
            ':instagram' => trim($input['instagram'] ?? '-'),
            ':drink_name' => trim($input['drink_name'] ?? 'Matcha Meow'),
            ':personality' => trim($input['personality'] ?? 'matcha'),
            ':note' => trim($input['note'] ?? ''),
        ]);
        echo json_encode(["ok" => true, "affected" => $stmt->rowCount()]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "Gagal update pawmate: " . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_pawmate') {
    $input = readJsonBody();
    requireAdminKey($input);
    $id = trim($input['id'] ?? '');
    if ($id === '') {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "ID pawmate wajib ada"]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM pawmate_entries WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(["ok" => true, "affected" => $stmt->rowCount()]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "Gagal hapus pawmate: " . $e->getMessage()]);
        exit;
    }
}

http_response_code(404);
echo json_encode(["ok" => false, "error" => "Action tidak dikenal: " . $action]);