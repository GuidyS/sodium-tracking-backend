<?php

require_once './config/config.php';
$db = new Connect();

$user_id = $_SESSION['user_id'] ?? $_GET['user_id'] ?? null;
$user_role = $_SESSION['user_role'] ?? '';

if (!$user_role && $user_id) {
    // ถ้า Session หลุด ให้ไปเช็คใน DB จริงๆ อีกครั้ง
    $stmt = $db->prepare("SELECT user_role FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_role = $stmt->fetchColumn();
}

// 🌟 ตรวจสอบว่าถ้าไม่ใช่ Admin ให้เด้งออกทันที
if ($user_role !== 'Admin') {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Access Denied: Admin only"]);
    exit;
}

// รับข้อมูลจาก Frontend
$rawData = file_get_contents("php://input");
$jsonData = json_decode($rawData, true);
$method = $_SERVER['REQUEST_METHOD'];
$table = $_GET['table'] ?? ''; 
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? null;

// หากมีการส่งไฟล์ (multipart/form-data) ให้ใช้ $_POST แทน JSON
$data = (!empty($_POST)) ? $_POST : $jsonData;

/**
 * ฟังก์ชันจัดการอัปโหลดรูปภาพไปยัง public ของ Frontend
 */
function uploadImage($file, $folder) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = uniqid() . '.' . $ext;
    
    // ✨ ชี้ไปที่โฟลเดอร์ public ของ frontend
    $targetDir = "../frontend/public/" . $folder . "/"; 
    $targetPath = $targetDir . $fileName;
    
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return $fileName;
    }
    return null;
}

// --- [GET METHOD] ดึงข้อมูล ---
if ($method === 'GET') {

    // 📊 1. สรุปภาพรวมสถิติ (Action: summary)
    if ($action === 'summary') {
        $stmt = $db->prepare("SELECT COUNT(*) as total_users, AVG(pretest_score) as avg_pretest, AVG(posttest_score) as avg_posttest FROM users");
        $stmt->execute();
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
        $stmtGender = $db->prepare("SELECT 
        CASE 
            WHEN gender = 'ชาย' THEN 'ชาย'
            WHEN gender = 'หญิง' THEN 'หญิง'
            WHEN gender IN ('อื่นๆ', 'Other') THEN 'อื่นๆ'
            ELSE 'ไม่ระบุ' 
        END as name, 
        COUNT(*) as value 
        FROM users 
        GROUP BY name");
        
        $stmtGender->execute();
        $genderData = $stmtGender->fetchAll(PDO::FETCH_ASSOC);
    
        // 🌟 เพิ่ม: ดึงข้อมูลช่วงอายุ (เพื่อให้กราฟ Pie Chart แสดงผล)
        $stmtAge = $db->prepare("SELECT 
            CASE 
                WHEN age < 20 THEN 'ต่ำกว่า 20'
                WHEN age BETWEEN 20 AND 22 THEN '20-22 ปี'
                ELSE '23 ปีขึ้นไป'
            END as name, COUNT(*) as value FROM users GROUP BY name");
        $stmtAge->execute();
        $ageData = $stmtAge->fetchAll(PDO::FETCH_ASSOC);
    
        // 📊 2. สถิติปริมาณโซเดียม (เฉลี่ยรายวันของทุกคนในช่วง 30 วันล่าสุด)
        $stmtSodium = $db->prepare("SELECT log_date as date, AVG(total_sodium_daily) as avg_sodium 
                                    FROM daily_logs 
                                    GROUP BY log_date 
                                    ORDER BY log_date ASC LIMIT 30");
        $stmtSodium->execute();
        $sodiumTrend = $stmtSodium->fetchAll(PDO::FETCH_ASSOC);
    
        // 📉 3. คะแนนเปรียบเทียบ % ถูก-ผิด (ภาพรวม Pre vs Post)
        $stmtCompare = $db->prepare("SELECT 
            test_type,
            AVG(is_correct) * 100 as correct_percent,
            (1 - AVG(is_correct)) * 100 as incorrect_percent
            FROM test_responses GROUP BY test_type");
        $stmtCompare->execute();
        $overallCompare = $stmtCompare->fetchAll(PDO::FETCH_ASSOC);
    
        // 🥧 4. แบบ Pre-test รายข้อ (ถูก/ผิด เป็น %)
        $pretestPieData = [];
        for ($i = 1; $i <= 8; $i++) {
            $stmtPie = $db->prepare("SELECT 
                SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct,
                SUM(CASE WHEN is_correct = 0 THEN 1 ELSE 0 END) as incorrect
                FROM test_responses WHERE test_type = 'pre' AND question_number = ?");
            $stmtPie->execute([$i]);
            $row = $stmtPie->fetch(PDO::FETCH_ASSOC);
            $pretestPieData["q$i"] = [
                ["name" => "ถูก", "value" => (int)$row['correct']],
                ["name" => "ผิด", "value" => (int)$row['incorrect']]
            ];
        }
    
        echo json_encode([
            "status" => "success",
            "data" => [
                "total_users" => (int)$summary['total_users'],
                "avg_pretest" => round($summary['avg_pretest'], 2),
                "avg_posttest" => round($summary['avg_posttest'], 2),
                "gender_data" => $genderData,
                "age_data" => $ageData,
                "sodium_trend" => $sodiumTrend,
                "overall_compare" => $overallCompare,
                "pretest_pie_data" => $pretestPieData
            ]
        ]);
        exit;
    }

    // 🔍 2. ค้นหาผู้ใช้ (Action: search_user)
    if ($action === 'search_user') {
        $q = "%" . ($_GET['q'] ?? '') . "%";
        $stmt = $db->prepare("SELECT user_id, full_name, email, total_points FROM users WHERE full_name LIKE ? OR email LIKE ? OR user_id LIKE ? LIMIT 10");
        $stmt->execute([$q, $q, $q]);
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // 📈 3. รายละเอียดผู้ใช้รายคน (Action: user_detail)
    if ($action === 'user_detail') {
        $userId = $_GET['user_id'];
        
        // ดึงคะแนน Pre/Post จริงของผู้ใช้คนนี้
        $stmtUser = $db->prepare("SELECT pretest_score, posttest_score FROM users WHERE user_id = ?");
        $stmtUser->execute([$userId]);
        $u = $stmtUser->fetch(PDO::FETCH_ASSOC);
    
        $stmtSodium = $db->prepare("SELECT log_date as day, total_sodium as sodium FROM daily_logs WHERE user_id = ? ORDER BY log_date ASC");
        $stmtSodium->execute([$userId]);
        
        echo json_encode([
            "status" => "success",
            "data" => [
                "sodium_data" => $stmtSodium->fetchAll(PDO::FETCH_ASSOC),
                "score_data" => [
                    ["name" => "Pre-test", "score" => (int)$u['pretest_score'], "fill" => "hsl(25, 90%, 65%)"],
                    ["name" => "Post-test", "score" => (int)$u['posttest_score'], "fill" => "hsl(155, 55%, 45%)"]
                ]
            ]
        ]);
        exit;
    }

    if (empty($table)) exit(json_encode(["status" => "error", "message" => "กรุณาระบุชื่อตาราง"]));

    $allowed_tables = ['foods', 'herbs', 'medicines', 'locations', 'users', 'restaurants'];
    if (!in_array($table, $allowed_tables)) exit(json_encode(["status" => "error", "message" => "ชื่อตารางไม่ถูกต้อง"]));

    if ($table === 'foods') {
        $location_id = $_GET['location_id'] ?? null;
        $restaurant_id = $_GET['restaurant_id'] ?? null;

        $sql = "SELECT f.*, l.location_name, r.restaurant_name 
                FROM foods f
                LEFT JOIN locations l ON f.location_id = l.location_id
                LEFT JOIN restaurants r ON f.restaurant_id = r.restaurant_id
                WHERE 1=1";
        
        $params = [];
        if ($location_id) { $sql .= " AND f.location_id = :loc"; $params[':loc'] = $location_id; }
        if ($restaurant_id) { $sql .= " AND f.restaurant_id = :res"; $params[':res'] = $restaurant_id; }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    } else {
        $stmt = $db->prepare("SELECT * FROM $table");
        $stmt->execute();
    }

    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["status" => "success", "count" => count($result), "data" => $result]);
}

// --- [POST METHOD] Create, Update, Delete ---
elseif ($method === 'POST') {

    if ($action === 'delete_points') {
        $target_user_id = $data['user_id'];
        $stmt = $db->prepare("UPDATE users SET total_points = 0 WHERE user_id = ?");
        $stmt->execute([$target_user_id]);
        echo json_encode(["status" => "success", "message" => "รีเซ็ตแต้มสำเร็จ"]);
        exit;
    }
    
    // 1. ✨ CREATE ACTION
    if ($action === 'create') {
        if ($table === 'foods') {
            $img = uploadImage($_FILES['food_image'] ?? null, 'foods');
            $sql = "INSERT INTO foods (food_name, sodium_mg, location_id, has_restaurant, restaurant_id, description, food_image) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$data['food_name'], $data['sodium_mg'], $data['location_id'], $data['has_restaurant'], $data['restaurant_id'] ?? 0, $data['description'] ?? '', $img ?? 'default-food.png']);
        } 
        elseif ($table === 'herbs' || $table === 'medicines') {
            $img = uploadImage($_FILES['image_path'] ?? null, 'med-herb'); // รวมไว้ในโฟลเดอร์ med-herb
            $json_content = json_encode(["detail" => $data['detail'] ?? '', "warning" => $data['warning'] ?? ''], JSON_UNESCAPED_UNICODE);

            $sql = "INSERT INTO $table (title, content, image_path) VALUES (?, ?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$data['title'], $json_content, $img]);
        }
        elseif ($table === 'locations') {
            $stmt = $db->prepare("INSERT INTO locations (location_name) VALUES (?)");
            $stmt->execute([$data['location_name']]);
        }
        echo json_encode(["status" => "success", "message" => "เพิ่มข้อมูลสำเร็จ"]);
    }

    // 2. 📝 UPDATE ACTION
    elseif ($action === 'update') {
        $target_id = $data['id'] ?? $id;
        if (!$target_id) exit(json_encode(["status" => "error", "message" => "กรุณาใส่ ID"]));

        if ($table === 'foods') {
            $img_sql = "";
            $params = [$data['food_name'], $data['sodium_mg'], $data['location_id'], $data['restaurant_id'], $data['description']];
            $new_img = uploadImage($_FILES['food_image'] ?? null, 'foods');
            
            if ($new_img) {
                $stmt_old = $db->prepare("SELECT food_image FROM foods WHERE food_id = ?");
                $stmt_old->execute([$target_id]);
                $old_file = $stmt_old->fetchColumn();
                // ลบรูปเก่าออกจากพาร์ทใหม่
                if ($old_file && $old_file !== 'default-food.png') {
                    $old_path = "../frontend/public/foods/" . $old_file;
                    if (file_exists($old_path)) @unlink($old_path);
                }
                $img_sql = ", food_image = ?";
                $params[] = $new_img;
            }
            $params[] = $target_id;
            $stmt = $db->prepare("UPDATE foods SET food_name = ?, sodium_mg = ?, location_id = ?, restaurant_id = ?, description = ? $img_sql WHERE food_id = ?");
            $stmt->execute($params);
        }
        elseif ($table === 'herbs' || $table === 'medicines') {
            $id_col = ($table === 'herbs') ? 'herb_id' : 'med_id';
            $json_content = json_encode(["detail" => $data['detail'] ?? '', "warning" => $data['warning'] ?? ''], JSON_UNESCAPED_UNICODE);
            
            $img_sql = "";
            $params = [$data['title'], $json_content];
            $new_img = uploadImage($_FILES['image_path'] ?? null, 'med-herb');

            if ($new_img) {
                $stmt_old = $db->prepare("SELECT image_path FROM $table WHERE $id_col = ?");
                $stmt_old->execute([$target_id]);
                $old_file = $stmt_old->fetchColumn();
                if ($old_file) {
                    $old_path = "../frontend/public/med-herb/" . $old_file; // ✨ ปรับพาร์ทลบรูป
                    if (file_exists($old_path)) @unlink($old_path);
                }
                $img_sql = ", image_path = ?";
                $params[] = $new_img;
            }
            $params[] = $target_id;
            $stmt = $db->prepare("UPDATE $table SET title = ?, content = ? $img_sql WHERE $id_col = ?");
            $stmt->execute($params);
        }
        elseif ($table === 'users') {
            $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ?, total_points = ?, pretest_score = ?, posttest_score = ? WHERE user_id = ?");
            $stmt->execute([$data['full_name'], $data['email'], $data['total_points'], $data['pretest_score'], $data['posttest_score'], $target_id]);
        }
        echo json_encode(["status" => "success", "message" => "อัปเดตข้อมูลสำเร็จ"]);
    }

    // 3. 🗑️ DELETE ACTION
    elseif ($action === 'delete') {
        if (!$id) exit(json_encode(["status" => "error", "message" => "Missing ID"]));
    
        try {
            $db->beginTransaction(); // 🌟 เริ่ม Transaction เพื่อความปลอดภัย
            $id_col = '';
            $img_col = null;
            $folder = '';
            $msg = "ลบข้อมูลเรียบร้อย";
    
            if ($table === 'locations') {
                // ลอจิกข้อ 1: ลบอาหารที่ผูกกับสถานที่นี้ก่อน
                $stmt1 = $db->prepare("DELETE FROM foods WHERE location_id = ?");
                $stmt1->execute([$id]);
                $id_col = 'location_id';
            } 
            elseif ($table === 'users') {
                // ลอจิกข้อ 2: ลบ Logs เมื่อลบ User
                $stmt1 = $db->prepare("DELETE li FROM log_items li JOIN daily_logs dl ON li.log_id = dl.log_id WHERE dl.user_id = ?");
                $stmt1->execute([$id]);
                $stmt2 = $db->prepare("DELETE FROM daily_logs WHERE user_id = ?");
                $stmt2->execute([$id]);
                $id_col = 'user_id';
                $msg = "ลบผู้ใช้และประวัติทั้งหมดเรียบร้อย";
            } 
            elseif ($table === 'foods') {
                $id_col = 'food_id'; $img_col = 'food_image'; $folder = 'foods';
            } 
            elseif ($table === 'herbs' || $table === 'medicines') {
                $id_col = ($table === 'herbs') ? 'herb_id' : 'med_id';
                $img_col = 'image_path'; $folder = 'med-herb';
            } 
            else {
                $id_col = $table . '_id';
            }
    
            // 🖼️ จัดการลบไฟล์รูปภาพ (ถ้ามี)
            if ($img_col) {
                $stmt_file = $db->prepare("SELECT $img_col FROM $table WHERE $id_col = ?");
                $stmt_file->execute([$id]);
                $file_name = $stmt_file->fetchColumn();
                if ($file_name && $file_name !== 'default-food.png') {
                    $file_path = "../frontend/public/$folder/" . $file_name;
                    if (file_exists($file_path)) @unlink($file_path);
                }
            }
    
            // 🗑️ ลบข้อมูลหลักในตารางนั้นๆ
            $stmt = $db->prepare("DELETE FROM $table WHERE $id_col = ?");
            $stmt->execute([$id]);
    
            $db->commit(); // ✅ ยืนยันการลบทั้งหมด
            echo json_encode(["status" => "success", "message" => $msg]);
    
        } catch (Exception $e) {
            $db->rollBack(); // ❌ ยกเลิกถ้าเกิด Error
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit;
    }
}
