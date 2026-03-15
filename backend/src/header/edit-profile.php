<?php
// ถ้าเรียกผ่าน index.php จะมีคลาส Connect อยู่แล้ว แต่ดักไว้เผื่อเรียกตรงๆ
if (!class_exists('Connect')) {
    require_once './config/config.php';
}

$db = new Connect();

// 🌟 1. รับข้อมูลที่ React ส่งมาทาง Body (POST)
$rawData = file_get_contents("php://input");
$inputData = json_decode($rawData, true) ?: [];

// 🌟 2. ดึง user_id แบบครอบคลุม (แก้ปัญหา Session หลุดบนมือถือ)
$user_id = $_SESSION['user_id'] ?? $_GET['user_id'] ?? $inputData['user_id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "กรุณาเข้าสู่ระบบ (Session Expired)"]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // --- ดึงข้อมูลโปรไฟล์ ---
    // 🛠️ แก้ไข: เพิ่ม google_id เข้าไปในคำสั่ง SELECT ด้วย
    $stmt = $db->prepare("SELECT full_name, email, gender, age, weight_kg, height_cm, user_role, google_id FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user['is_google'] = !empty($user['google_id']);
        unset($user['google_id']);
        
        // 🌟🌟🌟 แก้ไขหลัก: เพิ่มบรรทัดนี้ เพื่อส่งข้อมูลกลับไปให้ React 🌟🌟🌟
        echo json_encode(["status" => "success", "data" => $user]);
    } else {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลผู้ใช้"]);
    }
} 
elseif ($method === 'POST') {
    // --- อัปเดตข้อมูลโปรไฟล์ ---
    $sql = "UPDATE users SET 
            full_name = :full_name, 
            email = :email, 
            gender = :gender, 
            age = :age, 
            weight_kg = :weight, 
            height_cm = :height 
            WHERE user_id = :id";
            
    $stmt = $db->prepare($sql);
    try {
        $stmt->execute([
            ':full_name' => $inputData['full_name'] ?? '',
            ':email'     => $inputData['email'] ?? '',
            ':gender'    => $inputData['gender'] ?? '',
            ':age'       => $inputData['age'] ?? 0,
            ':weight'    => $inputData['weight_kg'] ?? 0,
            ':height'    => $inputData['height_cm'] ?? 0,
            ':id'        => $user_id
        ]);
        echo json_encode(["status" => "success", "message" => "อัปเดตข้อมูลสำเร็จ"]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database Error: " . $e->getMessage()]);
    }
}
?>
