<?php
header("Access-Control-Allow-Origin: https://sodiumtracking.vercel.app");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// เพิ่มส่วนนี้ก่อน session_start();
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '.up.railway.app', // หรือปล่อยว่างเพื่อให้เบราว์เซอร์จัดการเอง
    'secure' => true,      // ต้องเป็น true เพราะใช้ https
    'httponly' => true,    // ป้องกัน XSS
    'samesite' => 'None'   // ยอมให้ Vercel ส่ง Cookie กลับมาหา Railway ได้
]);

// 3. เริ่ม Session และ Require ไฟล์ตามลำดับ
session_start();

// แก้ไขจุดเสี่ยง: เช็คว่ามีไฟล์ config จริงไหมก่อนดึงมาใช้
if (file_exists('./config/config.php')) {
    require_once './config/config.php';
} else {
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "Config file missing on server"]);
    exit;
}
    $page = isset($_GET['page']) ? $_GET['page'] : '';

    switch ($page) {

        case 'profile':
        require_once './config/config.php';
        $db = new Connect();
        $user_id = $_SESSION['user_id'] ?? null;
        
        if (!$user_id) {
            echo json_encode(["status" => "error", "message" => "Unauthorized"]);
            exit;
        }

        $stmt = $db->prepare("SELECT total_points, pretest_done, posttest_done FROM users WHERE user_id = :uid");
        $stmt->execute([':uid' => $user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(["status" => "success", "data" => $user_data]);
        exit;
        
        // authentication
        case 'register':
            require_once 'auth/register.php';
            break;
        case 'login':
            require_once 'auth/login.php';
            break;
        case 'google-callback':
            require_once 'auth/google-callback.php';
            break;
        case 'logout':
            require_once 'auth/logout.php';
            break;
        case 'me':
        // ✅ ตรวจสอบจาก Session ที่ PHP เซตไว้ตอน Google Callback
        if (isset($_SESSION['user_id'])) {
            require_once './config/config.php';
            $db = new Connect();
            
            $stmt = $db->prepare("SELECT user_id, full_name, email, user_role FROM users WHERE user_id = :id");
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                echo json_encode(["status" => "success", "user" => $user]);
            } else {
                echo json_encode(["status" => "error", "message" => "User not found"]);
            }
        } else {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        }
        break;

        // profile
        case 'edit-profile':
            require_once 'header/edit-profile.php';
            break;
        case 'reset-password':
            require_once 'header/reset-password.php';
            break;

        // foods/medicines-log
        case 'food-log':
            require_once 'food-log.php';
            break;
        case 'medicine-info':
            require_once 'medicine-info.php';
            break;
        
        default:
            http_response_code(404);
            echo json_encode(["error" => "API endpoint is not found!"]);
            break;
    }

?>
