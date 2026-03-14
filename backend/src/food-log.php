<?php
require_once './config/config.php';

// --- 1. อ่านข้อมูลจาก JSON Payload ---
$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);

$db = new Connect();

// --- 2. ตรวจสอบสิทธิ์ ---
$user_id = $_SESSION['user_id'] ?? $data['user_id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "กรุณาเข้าสู่ระบบ"]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    // 1. ดึงรายการอาหารทั้งหมด
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
            $res_id = $food['restaurant_id'];

            if (!isset($structured_data[$loc_id])) {
                $structured_data[$loc_id] = [
                    "location_id" => $loc_id,
                    "location_name" => $food['location_name'],
                    "restaurants" => []
                ];
            }

            if (!isset($structured_data[$loc_id]['restaurants'][$res_id])) {
                $structured_data[$loc_id]['restaurants'][$res_id] = [
                    "restaurant_id" => $res_id,
                    "restaurant_name" => $food['restaurant_name'],
                    "foods" => []
                ];
            }
            $structured_data[$loc_id]['restaurants'][$res_id]['foods'][] = $food;
        }
        echo json_encode(["status" => "success", "data" => array_values($structured_data)]);
        exit;
    } 
    
    // 2. ดึงข้อมูลรายวัน (Daily)
    elseif ($action === 'daily') {
        $sql = "SELECT li.*, f.food_name, f.sodium_mg, f.location_id, f.restaurant_id, f.food_image, dl.log_date, li.created_at 
                FROM log_items li
                JOIN daily_logs dl ON li.log_id = dl.log_id
                JOIN foods f ON li.food_id = f.food_id
                WHERE dl.user_id = :uid 
                AND dl.log_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) 
                ORDER BY li.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => $user_id]);
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // 3. ดึงข้อมูลรายสัปดาห์ (Weekly)
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

    // 4. ดึงสถิติรายเดือน (Stats)
    elseif ($action === 'stats') {
        $sql = "SELECT DATE_FORMAT(log_date, '%M') as month, SUM(total_sodium_daily) as sodium 
                FROM daily_logs 
                WHERE user_id = :uid 
                GROUP BY month, DATE_FORMAT(log_date, '%Y-%m')
                ORDER BY DATE_FORMAT(log_date, '%Y-%m') ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => $user_id]);
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
} 

elseif ($method === 'POST') {
    $action = $_GET['action'] ?? 'save_food';

    // ✅ กรณีที่ 1: บันทึกแต้มจากแบบทดสอบ
    if ($action === 'submit_test') {
        $test_type = $data['test_type'] ?? ''; 
        $current_date = date('Y-m-d');
        
        $is_valid_date = false;
        if ($test_type === 'pre' && ($current_date >= '2026-03-13' && $current_date <= '2026-03-16')) $is_valid_date = true;
        if ($test_type === 'post' && ($current_date >= '2026-03-20' && $current_date <= '2026-03-31')) $is_valid_date = true;

        if ($is_valid_date) {
            $field = ($test_type === 'pre') ? 'pretest_done' : 'posttest_done';
            $stmt = $db->prepare("UPDATE users SET $field = 1, total_points = total_points + 1 WHERE user_id = :uid AND $field = 0");
            $stmt->execute([':uid' => $user_id]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(["status" => "success", "message" => "ได้รับ 1 แต้มเรียบร้อยแล้ว"]);
            } else {
                echo json_encode(["status" => "error", "message" => "คุณเคยรับแต้มส่วนนี้ไปแล้ว"]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "ไม่อยู่ในช่วงเวลาที่กำหนด"]);
        }
        exit;
    }

    // ✅ กรณีที่ 2: บันทึกรายการอาหาร (แก้ไขส่วนอัปเดตยอดโซเดียม)
    elseif ($action === 'save_food') {
        $selected_foods = $data['foods'] ?? [];
        $meal_type = $data['meal_type'] ?? 'breakfast';
        $log_date = date('Y-m-d');

        try {
            $db->beginTransaction();
            
            // 1. สร้างหรือดึง Log ID ของวันนี้
            $stmt = $db->prepare("INSERT INTO daily_logs (user_id, log_date) VALUES (:uid, :date) ON DUPLICATE KEY UPDATE log_id=LAST_INSERT_ID(log_id)");
            $stmt->execute([':uid' => $user_id, ':date' => $log_date]);
            $log_id = $db->lastInsertId();

            $total_added_sodium = 0; // ตัวแปรเก็บยอดโซเดียมที่เพิ่มในครั้งนี้

            // 2. บันทึกรายการอาหารลง log_items
            foreach ($selected_foods as $food) {
                $stmt = $db->prepare("INSERT INTO log_items (log_id, food_id, quantity, meal_type) VALUES (:lid, :fid, 1, :mtype)");
                $stmt->execute([':lid' => $log_id, ':fid' => $food['food_id'], ':mtype' => $meal_type]);
                $total_added_sodium += $food['sodium_mg']; // สะสมค่าโซเดียม
            }

            // 🌟 3. อัปเดตยอดรวมโซเดียมสะสมใน daily_logs (นี่คือจุดที่หายไป!)
            $stmt = $db->prepare("UPDATE daily_logs SET total_sodium_daily = total_sodium_daily + :sodium WHERE log_id = :lid");
            $stmt->execute([':sodium' => $total_added_sodium, ':lid' => $log_id]);

            // 4. ให้แต้มทุก 3 รายการ
            $stmt = $db->prepare("SELECT COUNT(*) FROM log_items li JOIN daily_logs dl ON li.log_id = dl.log_id WHERE dl.user_id = :uid");
            $stmt->execute([':uid' => $user_id]);
            $total_items = $stmt->fetchColumn();

            if ($total_items > 0 && $total_items % 3 === 0) {
                $stmt = $db->prepare("UPDATE users SET total_points = total_points + 1 WHERE user_id = :uid");
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
}
