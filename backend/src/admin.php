<?php

session_start(); // มั่นใจว่ามี session_start เพื่อเช็คบทบาท
require_once './config/config.php';
$db = new Connect();

$user_role = $_SESSION['user_role'] ?? '';

// 🌟 ตรวจสอบว่าถ้าไม่ใช่ Admin ให้เด้งออกทันที
if ($user_role !== 'admin') {
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

        if ($table === 'foods') {
            $id_col = 'food_id'; $img_col = 'food_image'; $folder = 'foods';
        } elseif ($table === 'herbs' || $table === 'medicines') {
            $id_col = ($table === 'herbs') ? 'herb_id' : 'med_id';
            $img_col = 'image_path'; $folder = 'med-herb'; //
        } else {
            $id_col = ($table === 'users') ? 'user_id' : $table . '_id';
            $img_col = null;
        }

        if ($img_col) {
            $stmt_file = $db->prepare("SELECT $img_col FROM $table WHERE $id_col = ?");
            $stmt_file->execute([$id]);
            $file_name = $stmt_file->fetchColumn();
            if ($file_name && $file_name !== 'default-food.png') {
                $file_path = "../frontend/public/$folder/" . $file_name; // ✨ ลบรูปจากพาร์ทใหม่
                if (file_exists($file_path)) @unlink($file_path);
            }
        }

        $stmt = $db->prepare("DELETE FROM $table WHERE $id_col = ?");
        $stmt->execute([$id]);
        echo json_encode(["status" => "success", "message" => "ลบข้อมูลและไฟล์เรียบร้อย"]);
    }
}
