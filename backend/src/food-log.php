<?php
// 🌟 1. ตั้งค่า Timezone ให้แม่นยำที่สุด
date_default_timezone_set('Asia/Bangkok');

require_once './config/config.php';

$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);
$db = new Connect();

// 🌟 2. ดึง User ID ( Universal ID ที่ไหลมาจาก index.php)
$user_id = $user_id ?? $_SESSION['user_id'] ?? $data['user_id'] ?? $_GET['user_id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "กรุณาเข้าสู่ระบบ"]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    // 1. ดึงรายการอาหารทั้งหมด (สำหรับหน้าค้นหา)
    if ($action === 'list') {
        $sql = "SELECT f.*, l.location_name, r.restaurant_name 
                FROM foods f
                JOIN locations l ON f.location_id = l.location_id
                LEFT JOIN restaurants r ON f.restaurant_id = r.restaurant_id 
                ORDER BY l.location_id ASC, r.restaurant_id ASC";
        $stmt = $db->query($sql);
        $all_foods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $structured_data = [];
        foreach ($all_foods as $food) {
            $loc_id = $food['location_id'];
            $res_id = $food['restaurant_id'] ?? 0;
            
            if (!isset($structured_data[$loc_id])) {
                $structured_data[$loc_id] = ["location_id" => $loc_id, "location_name" => $food['location_name'], "restaurants" => []];
            }
            if (!isset($structured_data[$loc_id]['restaurants'][$res_id])) {
                $structured_data[$loc_id]['restaurants'][$res_id] = ["restaurant_id" => $res_id, "restaurant_name" => $food['restaurant_name'] ?? 'ทั่วไป', "foods" => []];
            }
            $structured_data[$loc_id]['restaurants'][$res_id]['foods'][] = $food;
        }
        echo json_encode(["status" => "success", "data" => array_values($structured_data)]);
        exit;
    } 

    // 🌟 2. ดึงข้อมูลรายวัน (แก้ไข: เพิ่มส่วนนี้กลับเข้ามาเพื่อให้หน้าหลัก/หน้าวันไม่ว่าง)
    elseif ($action === 'daily') {
$sql = "SELECT li.*, f.*, dl.log_date, li.created_at, li.meal_type
            FROM log_items li
            JOIN daily_logs dl ON li.log_id = dl.log_id
            JOIN foods f ON li.food_id = f.food_id
            WHERE dl.user_id = :uid 
            ORDER BY dl.log_date DESC, li.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => $user_id]);
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // 3. ข้อมูลรายสัปดาห์ (สำหรับหน้า Weekly)
    elseif ($action === 'weekly') {
        $sql = "SELECT log_date, total_sodium_daily 
                FROM daily_logs 
                WHERE user_id = :uid 
                ORDER BY log_date DESC LIMIT 7";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => $user_id]);
        echo json_encode(["status" => "success", "data" => array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC))]);
        exit;
    }

    // 4. สถิติรวม (สำหรับหน้า Stats)
    elseif ($action === 'stats') {
        // 🌟 แก้ไข SQL ให้รองรับ MySQL Strict Mode บน Railway
        $sql = "SELECT 
                    DATE_FORMAT(log_date, '%b') as month, 
                    SUM(total_sodium_daily) as sodium 
                FROM daily_logs 
                WHERE user_id = :uid 
                GROUP BY YEAR(log_date), MONTH(log_date), DATE_FORMAT(log_date, '%b')
                ORDER BY YEAR(log_date) ASC, MONTH(log_date) ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => $user_id]);
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    // 5. ข้อมูลทั้งหมด (สำหรับปฏิทินหน้า Points)
    elseif ($action === 'daily_all') {
        $sql = "SELECT log_date, total_sodium_daily 
                FROM daily_logs 
                WHERE user_id = :uid 
                ORDER BY log_date ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => $user_id]);
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
} 

elseif ($method === 'POST') {
    $action = $_GET['action'] ?? 'save_food';

    if ($action === 'save_food') {
        $selected_foods = $data['foods'] ?? [];
        $meal_type = $data['meal_type'] ?? 'breakfast';
        $log_date = date('Y-m-d');

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("INSERT INTO daily_logs (user_id, log_date) VALUES (:uid, :date) 
                                 ON DUPLICATE KEY UPDATE log_id=LAST_INSERT_ID(log_id)");
            $stmt->execute([':uid' => $user_id, ':date' => $log_date]);
            $log_id = $db->lastInsertId();

            $total_added_sodium = 0;
            foreach ($selected_foods as $food) {
                $stmt = $db->prepare("INSERT INTO log_items (log_id, food_id, quantity, meal_type) VALUES (:lid, :fid, 1, :mtype)");
                $stmt->execute([':lid' => $log_id, ':fid' => $food['food_id'], ':mtype' => $meal_type]);
                $total_added_sodium += (int)$food['sodium_mg'];
            }

            $stmt = $db->prepare("UPDATE daily_logs SET total_sodium_daily = total_sodium_daily + :sodium WHERE log_id = :lid");
            $stmt->execute([':sodium' => $total_added_sodium, ':lid' => $log_id]);

            // ลอจิกแต้มสะสม
            $stmt = $db->prepare("SELECT COUNT(DISTINCT log_date) FROM daily_logs WHERE user_id = :uid AND total_sodium_daily > 0");
            $stmt->execute([':uid' => $user_id]);
            $distinct_days = $stmt->fetchColumn();
            
            if ($distinct_days > 0 && $distinct_days % 3 === 0) {
                $stmt = $db->prepare("UPDATE users SET total_points = total_points + 1, last_point_date = NOW() WHERE user_id = :uid");
                $stmt->execute([':uid' => $user_id]);
            }

            $db->commit();
            echo json_encode(["status" => "success", "message" => "บันทึกเรียบร้อย"]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit;
    }

    elseif ($action === 'submit_test') {
        $test_type = $data['test_type'] ?? ''; 
        $score = $data['score'] ?? 0;
        $results = $data['results'] ?? [];
        
        $col_done  = ($test_type === 'post') ? 'posttest_done' : 'pretest_done';
        $col_score = ($test_type === 'post') ? 'posttest_score' : 'pretest_score';

        // เช็คประเภทแบบทดสอบ
        if ($test_type !== 'pre' && $test_type !== 'post') {
            echo json_encode(["status" => "error", "message" => "ประเภทแบบทดสอบไม่ถูกต้อง"]);
            exit;
        }

        // เช็คเงื่อนไขวันเวลา (สำหรับ Post-test)
        if ($test_type === 'post') {
            $today = date('Y-m-d H:i:s');
            $start = "2026-03-18 00:00:00";
            $end   = "2026-03-31 23:59:59";
            if ($today < $start || $today > $end) {
                echo json_encode(["status" => "error", "message" => "ไม่อยู่ในกำหนดเวลาทำ Post-test"]);
                exit;
            }
        }

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT $col_done FROM users WHERE user_id = :uid");
            $stmt->execute([':uid' => $user_id]);
            $done_status = $stmt->fetchColumn();

            if ($done_status == 0) {
                // 1. อัปเดตคะแนนรวมในตาราง users
                $stmt = $db->prepare("UPDATE users SET $col_done = 1, $col_score = :score, total_points = total_points + 1, last_point_date = NOW(), updated_at = NOW() WHERE user_id = :uid");
                $stmt->execute([':score' => $score, ':uid' => $user_id]);
    
                // 2. บันทึกรายละเอียดรายข้อลงตาราง test_responses
                if (!empty($results)) {
                    // เตรียมคำสั่ง SQL ล่วงหน้าเพื่อประสิทธิภาพ
                    $stmtRes = $db->prepare("INSERT INTO test_responses (user_id, test_type, question_number, is_correct) VALUES (?, ?, ?, ?)");
                    foreach ($results as $res) {
                        // $res['q'] คือข้อที่, $res['correct'] คือ 1 หรือ 0
                        $stmtRes->execute([$user_id, $test_type, $res['q'], $res['correct']]);
                    }
                }
                
                $db->commit();
                echo json_encode(["status" => "success", "message" => "บันทึกสำเร็จ"]);
            } else {
                echo json_encode(["status" => "error", "message" => "คุณเคยทำแบบทดสอบนี้ไปแล้ว"]);
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(["status" => "error", "message" => "DB Error: " . $e->getMessage()]);
        }
        exit;
    }
} // ปิดบล็อก POST ตรงนี้

?>
