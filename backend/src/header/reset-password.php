<?php

require_once './config/config.php';

try {
    $db = new Connect();
    $data = json_decode(file_get_contents("php://input"), true);
    
    // รับค่าจาก Frontend
    $current_password = $data['currentPassword'] ?? '';
    $new_password = $data['newPassword'] ?? '';
    $user_id = $_SESSION['user_id'] ?? null; // ใช้ ID จาก Session

    if (!$user_id) {
        throw new Exception("กรุณาเข้าสู่ระบบก่อนทำรายการ");
    }

    if (empty($current_password) || empty($new_password)) {
        throw new Exception("กรุณากรอกข้อมูลให้ครบถ้วน");
    }

    // 1. ตรวจสอบรหัสผ่านเดิมก่อน
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current_password, $user['password_hash'])) {
        throw new Exception("รหัสผ่านปัจจุบันไม่ถูกต้อง");
    }

    // 2. เข้ารหัสรหัสผ่านใหม่
    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

    // 3. อัปเดตข้อมูล
    $sql = "UPDATE users SET password_hash = :password WHERE user_id = :id";
    $stmt = $db->prepare($sql);
    $result = $stmt->execute([
        ':password' => $new_password_hash,
        ':id' => $user_id
    ]);

    if ($result) {
        echo json_encode(["status" => "success", "message" => "เปลี่ยนรหัสผ่านเรียบร้อยแล้ว"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
