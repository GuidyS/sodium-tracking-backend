<?php
// 1. ตั้งค่า Header สำหรับ CORS และ JSON
header("Access-Control-Allow-Origin: https://sodiumtracking.vercel.app");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning");
header('Content-Type: application/json; charset=utf-8');

// จัดการ Preflight Request (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 2. ตั้งค่า Cookie ให้รองรับ Cross-site (Vercel -> Railway)
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'secure' => true,      
    'httponly' => true,    
    'samesite' => 'None'   
]);

session_start();

// 3. โหลด Config และ Database
if (file_exists('./config/config.php')) {
    require_once './config/config.php';
} else {
    echo json_encode(["status" => "error", "message" => "Config file missing on server"]);
    exit;
}

// 🌟 อ่านข้อมูลจาก JSON Body (สำหรับแผนสำรองกรณี Session หลุด)
$rawData = file_get_contents("php://input");
$inputData = json_decode($rawData, true);

$page = isset($_GET['page']) ? $_GET['page'] : '';

switch ($page) {

    case 'me':
        // 🌟 เช็คสิทธิ์: ดูจาก Session ก่อน ถ้าไม่มีให้ดูจาก user_id ที่ส่งแนบมาใน GET หรือ JSON
        $user_id = $_SESSION['user_id'] ?? $_GET['user_id'] ?? $inputData['user_id'] ?? null;

        if ($user_id) {
            $db = new Connect();
            // ดึงข้อมูล User พร้อมสถานะ pretest_done
            $stmt = $db->prepare("SELECT user_id, full_name, email, user_role, pretest_done, posttest_done, total_points FROM users WHERE user_id = :id");
            $stmt->execute([':id' => $user_id]);
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

    case 'profile':
        $db = new Connect();
        $user_id = $_SESSION['user_id'] ?? $_GET['user_id'] ?? $inputData['user_id'] ?? null;
        
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "Unauthorized"]);
            exit;
        }

        $stmt = $db->prepare("SELECT total_points, pretest_done, posttest_done FROM users WHERE user_id = :uid");
        $stmt->execute([':uid' => $user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(["status" => "success", "data" => $user_data]);
        exit;
        
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

    case 'edit-profile':
        require_once 'header/edit-profile.php';
        break;

    case 'reset-password':
        require_once 'header/reset-password.php';
        break;

    case 'change-password':
        require_once 'header/change-password.php';
        break;

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
