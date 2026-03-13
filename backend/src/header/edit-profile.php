<?php

require_once './config/config.php';

$db = new Connect();

// ตรวจสอบ Session (ต้อง Login ก่อน)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "กรุณาเข้าสู่ระบบ"]);
    exit;
}

$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // --- ดึงข้อมูลโปรไฟล์ ---
    $stmt = $db->prepare("SELECT full_name, email, gender, age, weight_kg, height_cm, user_role FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo json_encode(["status" => "success", "data" => $user]);
    } else {
        echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลผู้ใช้"]);
    }
} 

elseif ($method === 'POST') {
    // --- อัปเดตข้อมูลโปรไฟล์ ---
    $data = json_decode(file_get_contents("php://input"), true);
    
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
            ':full_name' => $data['full_name'],
            ':email' => $data['email'],
            ':gender' => $data['gender'],
            ':age' => $data['age'],
            ':weight' => $data['weight_kg'],
            ':height' => $data['height_cm'],
            ':id' => $user_id
        ]);
        echo json_encode(["status" => "success", "message" => "อัปเดตข้อมูลสำเร็จ"]);
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}