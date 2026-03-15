<?php

ob_start();

error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/config.php'; // ถอยออกไป 1 ชั้นเพื่อหา config
require_once __DIR__ . '/../vendor/autoload.php'; // ถอยออกไป 1 ชั้นเพื่อหา vendor

try {
    $db = new Connect(); // ใช้ class Connect เหมือนใน login.php
    $client = new Google_Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setRedirectUri(GOOGLE_REDIRECT_URI);
    $client->addScope("email");
    $client->addScope("profile");

    if(!isset($_GET['code'])) {
        // เพื่อให้ระบบวิ่งไปหา Google ทุกครั้งที่กดปุ่ม
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

        if(!$user) {
            // สำหรับผู้ใช้ใหม่
            $stmt = $db->prepare("INSERT INTO users (google_id, full_name, email, user_role) VALUES (:google_id, :name, :email, 'บุคคลทั่วไป')");
            $stmt->execute([
                ':google_id' => $userInfo->id,
                ':name' => $userInfo->name,
                ':email' => $userInfo->email
            ]);
            $userId = $db->lastInsertId();
            $userRole = 'บุคคลทั่วไป';
        } else {
            // สำหรับผู้ใช้เดิม
            $userId = $user['user_id'];
            $userRole = $user['user_role'];
        }

        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = $userRole;

        $userData = urlencode(json_encode([
            "user_id" => $userId,
            "full_name" => $userInfo->name,
            "email" => $userInfo->email,
            "user_role" => $userRole
        ]));

        // ✅ เปลี่ยนจาก localhost เป็น Vercel URL
        header("Location: https://sodiumtracking.vercel.app/splash?user=" . $userData);
        exit;
    }
} catch (Exception $e) {
    // ใน production แนะนำให้ส่งกลับไปหน้า login พร้อม error message
    header("Location: https://sodiumtracking.vercel.app/login?error=" . urlencode($e->getMessage()));
    exit;
}
?>
