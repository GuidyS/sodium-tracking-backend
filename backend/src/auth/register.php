<?php
require_once './config/config.php';
$data = json_decode(file_get_contents("php://input"), true);

// รับค่าจาก Axios
$email = $data['email'] ?? '';
$password = $data['password'] ?? '';
$full_name = $data['full_name'] ?? '';
$gender = $data['gender'] ?? '';
$age = $data['age'] ?? null;
$weight_kg = $data['weight_kg'] ?? null;
$height_cm = $data['height_cm'] ?? null;
$role = $data['user_role'] ?? 'บุคคลทั่วไป';

if(empty($email) || empty($password) || empty($full_name)) {
    echo json_encode(["status" => "error", "message" => "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน"]);
    exit;
}

$db = new Connect();
$password_hash = password_hash($password, PASSWORD_DEFAULT);

// เตรียม SQL ให้ตรงกับ Table 'users'
$sql = "INSERT INTO users (full_name, email, password_hash, gender, age, weight_kg, height_cm, user_role) 
        VALUES (:name, :email, :pw, :gender, :age, :weight, :height, :role)";

$stmt = $db->prepare($sql);

try {
    $stmt->execute([
        ':name' => $full_name,
        ':email' => $email,
        ':pw' => $password_hash,
        ':gender' => $gender,
        ':age' => $age,
        ':weight' => $weight_kg,
        ':height' => $height_cm,
        ':role' => $role
    ]);
    
    echo json_encode(["status" => "success", "message" => "ลงทะเบียนสำเร็จ!"]);
} catch (PDOException $e) {
    http_response_code(400);
    if ($e->getCode() == 23000) {
        echo json_encode(["status" => "error", "message" => "Email นี้ถูกใช้งานไปแล้ว"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Error: " . $e->getMessage()]);
    }
}