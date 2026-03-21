<?php
ob_start();
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php'; 
require_once __DIR__ . '/../vendor/autoload.php'; 

try {
    $db = new Connect(); 
    $client = new Google_Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setRedirectUri(GOOGLE_REDIRECT_URI);
    $client->addScope("email");
    $client->addScope("profile");

    if(!isset($_GET['code'])) {
        header("Location: " . filter_var($client->createAuthUrl(), FILTER_SANITIZE_URL));
        exit;
    } else {
        $client->authenticate($_GET['code']);
        $token = $client->getAccessToken();
        $client->setAccessToken($token);

        $oauth = new Google_Service_Oauth2($client);
        $userInfo = $oauth->userinfo->get();

        // ตรวจสอบ User ใน DB
        $stmt = $db->prepare("SELECT * FROM users WHERE google_id = :google_id OR email = :email");
        $stmt->execute([':google_id' => $userInfo->id, ':email' => $userInfo->email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 🌟 แก้ไขตรรกะการแยกผู้ใช้ใหม่/เก่า
        if(!$user) {
            // 1. ลอจิกสำหรับผู้ใช้ใหม่
            // เปลี่ยนจาก 'admin' เป็น 'Admin' เพื่อให้ตรงกับ React
            $role = ($userInfo->email === 'ddoubleduy@gmail.com') ? 'admin' : 'บุคคลทั่วไป';
        
            $stmt = $db->prepare("INSERT INTO users (google_id, full_name, email, user_role) VALUES (:google_id, :name, :email, :role)");
            $stmt->execute([
                ':google_id' => $userInfo->id,
                ':name' => $userInfo->name,
                ':email' => $userInfo->email,
                ':role' => $role
            ]);
            $userId = $db->lastInsertId();
            $userRole = $role;
        } else {
            // 2. ลอจิกสำหรับผู้ใช้เดิม
            $userId = $user['user_id'];
            $userRole = $user['user_role'];
        }

        // 🌟 ส่วนนี้ต้องอยู่นอก if-else เพื่อให้ทั้ง user ใหม่และเก่าทำงานได้
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = $userRole;

        $userData = urlencode(json_encode([
            "user_id" => (int)$userId,
            "full_name" => $userInfo->name,
            "email" => $userInfo->email,
            "user_role" => $userRole
        ]));

        header("Location: https://sodiumtracking.vercel.app/splash?user=" . $userData);
        exit;
    }
} catch (Exception $e) {
    header("Location: https://sodiumtracking.vercel.app/login?error=" . urlencode($e->getMessage()));
    exit;
}
