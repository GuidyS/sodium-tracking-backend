<?php
    session_start();

    // ตั้งค่า CORS (ต้องอยู่บรรทัดแรกๆ ก่อน logic อื่น)
    header("Access-Control-Allow-Origin: http://localhost:5174");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning");

    // --- เพิ่มส่วนนี้เข้าไป ---
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        http_response_code(200);
        exit();
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