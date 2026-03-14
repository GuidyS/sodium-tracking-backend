<?php

require_once './config/config.php';

try {
    $db = new Connect();
    $data = json_decode(file_get_contents("php://input"), true);
    
    // 1. รับค่า email และรหัสผ่านใหม่จากหน้าบ้าน
    $email = $data['email'] ?? ''; 
    $new_password = $data['new_password'] ?? '';

    if (empty($email) || empty($new_password)) {
        throw new Exception("กรุณากรอกอีเมลและรหัสผ่านใหม่ให้ครบถ้วน");
    }

    // 2. ตรวจสอบว่ามีอีเมลนี้อยู่ในระบบหรือไม่
    $check_stmt = $db->prepare("SELECT user_id FROM users WHERE email = :email");
    $check_stmt->execute([':email' => $email]);
    if (!$check_stmt->fetch()) {
        throw new Exception("ไม่พบอีเมลนี้ในระบบ");
    }

    // 3. เข้ารหัสรหัสผ่านใหม่ก่อนบันทึก
    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);

    // 4. อัปเดตข้อมูลในตาราง users
    $sql = "UPDATE users SET password_hash = :password WHERE email = :email";
    $stmt = $db->prepare($sql);
    $result = $stmt->execute([
        ':password' => $password_hash,
        ':email' => $email
    ]);

    if ($result) {
        echo json_encode(["status" => "success", "message" => "รีเซ็ตรหัสผ่านในระบบเรียบร้อยแล้ว"]);
    } else {
        throw new Exception("ไม่สามารถอัปเดตรหัสผ่านได้");
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
