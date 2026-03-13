<?php
require_once './config/config.php'; 

try {
    $db = new Connect();
    $data = json_decode(file_get_contents('php://input'), true); 

    $email = isset($data['email']) ? trim($data['email']) : ''; 
    $password = isset($data['password']) ? trim($data['password']) : '';

    if (empty($email) || empty($password)) {
        throw new Exception("กรุณากรอกข้อมูลให้ครบถ้วน");
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        // ตรวจสอบว่ามีชื่อหรือบทบาทหรือยัง
        $is_complete = !empty($user['full_name']) && !empty($user['user_role']);
        // บันทึก Session
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];

        echo json_encode([
            "status" => "success",
            "user" => [
                "user_id" => (int)$user['user_id'],
                "full_name" => $user['full_name'],
                "email" => $user['email'],
                "user_role" => $user['user_role'],
                "pretest_done" => (int)$user['pretest_done']
            ]
        ]);
    } else {
        throw new Exception("อีเมลหรือรหัสผ่านไม่ถูกต้อง");
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}