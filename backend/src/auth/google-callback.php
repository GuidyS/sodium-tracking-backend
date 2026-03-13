<?php
require_once './config/config.php';
require_once 'vendor/autoload.php';

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

        // ✅ เพิ่มบรรทัดนี้ เพื่อส่งผู้ใช้กลับไปที่ React พร้อมข้อมูล
        header("Location: http://localhost:5174/splash?user=" . $userData);
        exit;
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>