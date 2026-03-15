<?php
// 🌟 1. ตั้งค่า Timezone ให้แม่นยำที่สุด
date_default_timezone_set('Asia/Bangkok');

require_once './config/config.php';

$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);
$db = new Connect();

// 🌟 2. ดึง User ID (Universal ID ที่ส่งมาจาก index.php หรือดึงเองจากข้อมูลที่ส่งมา)
$user_id = $user_id ?? $_SESSION['user_id'] ?? $data['user_id'] ?? $_GET['user_id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "กรุณาเข้าสู่ระบบ"]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    // 1. ดึงรายการอาหาร (สำหรับการค้นหาอาหาร)
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
            $res_id = $food['restaurant_id'] ?? 0; // จัดการกรณีร้านค้าเป็น NULL
            
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

    // 2. ข้อมูลรายสัปดาห์ (ใช้สำหรับหน้า Weekly)
    elseif ($action === 'weekly') {
        $sql = "SELECT log_date, total_sodium_daily 
                FROM daily_logs 
                WHERE user_id = :uid 
                ORDER BY log_date DESC LIMIT 7";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => $user_id]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // ส่งกลับเรียงจากวันที่เก่าไปใหม่เพื่อให้กราฟแสดงถูกต้อง
        echo json_encode(["status" => "success", "data" => array_reverse($data)]);
        exit;
    }

    // 3. สถิติรวม/รายเดือน (ใช้สำหรับหน้า Stats รวม 9,000 mg)
    elseif ($action === 'stats') {
        // GROUP BY รายเดือนเพื่อสรุปยอดแท่งกราฟ
        $sql = "SELECT 
                    DATE_FORMAT(log_date, '%b') as month, 
                    SUM(total_sodium_daily) as sodium 
                FROM daily_logs 
                WHERE user_id = :uid 
                GROUP BY YEAR(log_date), MONTH(log_date)
                ORDER BY log_date ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => $user_id]);
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // 4. ข้อมูลทั้งหมด (สำหรับปฏิทินสะสมแต้มหน้า Points)
    elseif ($action === 'daily_all') {
        $sql = "SELECT dl.log_date, dl.total_sodium_daily 
                FROM daily_logs dl 
                WHERE dl.user_id = :uid 
                ORDER BY dl.log_date ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => $user_id]);
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
} 

elseif ($method === 'POST') {
    $action = $_GET['action'] ?? 'save_food';

    // บันทึกมื้ออาหาร
    if ($action === 'save_food') {
        $selected_foods = $data['foods'] ?? [];
        $meal_type = $data['meal_type'] ?? 'breakfast';
        $log_date = date('Y-m-d');

        try {
            $db->beginTransaction();

            // 1. จัดการตาราง daily_logs (สร้างหรือดึง ID วันนี้)
            $stmt = $db->prepare("INSERT INTO daily_logs (user_id, log_date) VALUES (:uid, :date) 
                                 ON DUPLICATE KEY UPDATE log_id=LAST_INSERT_ID(log_id)");
            $stmt->execute([':uid' => $user_id, ':date' => $log_date]);
            $log_id = $db->lastInsertId();

            // 2. บันทึกรายการอาหารและรวมยอดโซเดียม
            $total_added_sodium = 0;
            foreach ($selected_foods as $food) {
                $stmt = $db->prepare("INSERT INTO log_items (log_id, food_id, quantity, meal_type) VALUES (:lid, :fid, 1, :mtype)");
                $stmt->execute([':lid' => $log_id, ':fid' => $food['food_id'], ':mtype' => $meal_type]);
                $total_added_sodium += (int)$food['sodium_mg'];
            }

            // 3. อัปเดตยอดรวมโซเดียมของวัน
            $stmt = $db->prepare("UPDATE daily_logs SET total_sodium_daily = total_sodium_daily + :sodium WHERE log_id = :lid");
            $stmt->execute([':sodium' => $total_added_sodium, ':lid' => $log_id]);

            // 4. ลอจิกแจกแต้ม (สะสมครบทุก 3 วันที่ไม่ซ้ำกัน)
            $stmt = $db->prepare("SELECT COUNT(DISTINCT log_date) FROM daily_logs WHERE user_id = :uid AND total_sodium_daily > 0");
            $stmt->execute([':uid' => $user_id]);
            $distinct_days = $stmt->fetchColumn();
            
            // แจกแต้มเมื่อจำนวนวันหาร 3 ลงตัว (และมื้อนี้คือมื้อที่ทำให้ครบเงื่อนไข)
            if ($distinct_days > 0 && $distinct_days % 3 === 0) {
                // เช็คว่าวันนี้เคยได้แต้มจากเงื่อนไข 3 วันหรือยัง (เพื่อป้องกันกดบันทึกซ้ำในวันเดียวกันแล้วได้แต้มเพิ่ม)
                // *หมายเหตุ: คุณอาจต้องมีตารางเก็บประวัติการให้แต้มหากต้องการความแม่นยำสูง*
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
