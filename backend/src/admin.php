<?php
// admin.php
require_once './config/config.php';
$db = new Connect();
$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);
$method = $_SERVER['REQUEST_METHOD'];
$table = $_GET['table'] ?? ''; // foods, herbs, medicines, etc.
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? null;

// 🌟 ระบบตรวจสอบสิทธิ์ (ถ้ามีคอลัมน์ role ในตาราง users)
// if ($_SESSION['role'] !== 'admin') { exit(json_encode(["status"=>"error"])); }

if ($method === 'GET') {
    if (empty($table)) {
        echo json_encode(["status" => "error", "message" => "กรุณาระบุชื่อตาราง"]);
        exit;
    }

    $allowed_tables = ['foods', 'herbs', 'medicines', 'locations', 'users', 'restaurants']; // เพิ่ม restaurants เข้าไปด้วย
    if (!in_array($table, $allowed_tables)) {
        echo json_encode(["status" => "error", "message" => "ชื่อตารางไม่ถูกต้อง"]);
        exit;
    }

    if ($table === 'foods') {
        $location_id = $_GET['location_id'] ?? null;
        $restaurant_id = $_GET['restaurant_id'] ?? null;

        // ใช้ INNER JOIN เพื่อดึงชื่อสถานที่และชื่อร้านออกมาด้วย
        $sql = "SELECT f.*, l.location_name, r.restaurant_name 
                FROM foods f
                LEFT JOIN locations l ON f.location_id = l.location_id
                LEFT JOIN restaurants r ON f.restaurant_id = r.restaurant_id
                WHERE 1=1";
        
        $params = [];
        if ($location_id) {
            $sql .= " AND f.location_id = :loc";
            $params[':loc'] = $location_id;
        }
        if ($restaurant_id) {
            $sql .= " AND f.restaurant_id = :res";
            $params[':res'] = $restaurant_id;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    } else {
        // สำหรับตารางอื่นๆ ดึงข้อมูลทั้งหมดปกติ
        $stmt = $db->prepare("SELECT * FROM $table");
        $stmt->execute();
    }

    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["status" => "success", "count" => count($result), "data" => $result]);
}


function uploadImage($file, $folder) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = uniqid() . '.' . $ext;
    
    // ✨ เปลี่ยนพาร์ทให้ถอยออกไปหาโฟลเดอร์ frontend/public
    $targetDir = "../frontend/public/" . $folder . "/"; 
    $targetPath = $targetDir . $fileName;
    
    // สร้างโฟลเดอร์อัตโนมัติหากยังไม่มี
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return $fileName;
    }
    return null;
}

// รับข้อมูล JSON (ใช้สำหรับ Update/Create ข้อมูลที่ไม่ใช่ไฟล์)
$rawData = file_get_contents("php://input");
$jsonData = json_decode($rawData, true);

// หากเป็น multipart/form-data (มีการส่งไฟล์) ให้ใช้ $_POST แทน
$data = (!empty($_POST)) ? $_POST : $jsonData;

if ($method === 'POST') {
    // 1. ✨ CREATE ACTION
    if ($action === 'create') {
        if ($table === 'foods') {
            $img = uploadImage($_FILES['food_image'] ?? null, 'foods');
            $sql = "INSERT INTO foods (food_name, sodium_mg, location_id, has_restaurant, restaurant_id, description, food_image) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$data['food_name'], $data['sodium_mg'], $data['location_id'], $data['has_restaurant'], $data['restaurant_id'] ?? 0, $data['description'] ?? '', $img ?? 'default-food.png']);
        } 
        elseif ($table === 'herbs' || $table === 'medicines') {
            $folder = ($table === 'herbs') ? 'herbs' : 'medicines';
            $img = uploadImage($_FILES['image_path'] ?? null, $folder);

            $content_data = [
                "detail" => $data['detail'] ?? '',
                "warning" => $data['warning'] ?? ''
            ];
            $json_content = json_encode($content_data, JSON_UNESCAPED_UNICODE);

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
                // 1. 🔍 ไปดึงชื่อไฟล์รูปเก่าจาก DB มาดูก่อน
                $stmt_old = $db->prepare("SELECT food_image FROM foods WHERE food_id = ?");
                $stmt_old->execute([$target_id]);
                $old_file_name = $stmt_old->fetchColumn();

                // 2. 🗑️ ถ้ามีไฟล์เก่าอยู่จริง และไม่ใช่รูป default ให้ลบทิ้งจากโฟลเดอร์
                if ($old_file_name && $old_file_name !== 'default-food.png') {
                    $old_path = "./uploads/foods/" . $old_file_name;
                    if (file_exists($old_path)) {
                        @unlink($old_path); // คำสั่งลบไฟล์
                    }
                }

                $img_sql = ", food_image = ?";
                $params[] = $new_img;
            }
            
            $params[] = $target_id;
            $stmt = $db->prepare("UPDATE foods SET food_name = ?, sodium_mg = ?, location_id = ?, restaurant_id = ?, description = ? $img_sql WHERE food_id = ?");
            $stmt->execute($params);
        }

        // ✨ ส่วนที่เพิ่มใหม่: รองรับ herbs และ medicines
        elseif ($table === 'herbs' || $table === 'medicines') {
            $id_col = ($table === 'herbs') ? 'herb_id' : 'med_id';
            $folder = $table; // จะได้ path เป็น ./uploads/herbs/ หรือ ./uploads/medicines/ ตามโครงสร้างโฟลเดอร์คุณ

            $content_data = [
                "detail" => $data['detail'] ?? '',
                "warning" => $data['warning'] ?? ''
            ];
            $json_content = json_encode($content_data, JSON_UNESCAPED_UNICODE);
            
            $img_sql = "";
            $params = [$data['title'], $json_content];
            
            $new_img = uploadImage($_FILES['image_path'] ?? null, $folder);
            if ($new_img) {
                // 🔍 1. ค้นหาชื่อไฟล์ภาพปัจจุบันใน DB
                $stmt_old = $db->prepare("SELECT image_path FROM $table WHERE $id_col = ?");
                $stmt_old->execute([$target_id]);
                $old_file = $stmt_old->fetchColumn();

                // 🗑️ 2. ลบไฟล์ภาพเก่าออกจากโฟลเดอร์ถ้ามีอยู่จริง
                if ($old_file) {
                    $old_path = "./uploads/$folder/" . $old_file;
                    if (file_exists($old_path)) {
                        @unlink($old_path);
                    }
                }

                $img_sql = ", image_path = ?";
                $params[] = $new_img;
            }
            
            $params[] = $target_id;
            $stmt = $db->prepare("UPDATE $table SET title = ?, content = ? $img_sql WHERE $id_col = ?");
            $stmt->execute($params);
        }

        elseif ($table === 'users') {
            $sql = "UPDATE users SET full_name = ?, email = ?, total_points = ?, pretest_score = ?, posttest_score = ? WHERE user_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$data['full_name'], $data['email'], $data['total_points'], $data['pretest_score'], $data['posttest_score'], $target_id]);
        }
        echo json_encode(["status" => "success", "message" => "อัปเดตข้อมูลตาราง $table สำเร็จ"]);
    }

// 3. 🗑️ DELETE ACTION (แบบลบรูปภาพด้วย)
    elseif ($action === 'delete') {
        if (!$id) exit(json_encode(["status" => "error", "message" => "Missing ID"]));

        // --- 1. เตรียมข้อมูลชื่อคอลัมน์และโฟลเดอร์ตามตาราง ---
        if ($table === 'foods') {
            $id_col = 'food_id';
            $img_col = 'food_image';
            $folder = 'foods';
        } elseif ($table === 'herbs' || $table === 'medicines') {
            $id_col = ($table === 'herbs') ? 'herb_id' : 'med_id';
            $img_col = 'image_path';
            $folder = $table; // 'herbs' หรือ 'medicines'
        } elseif ($table === 'users') {
            $id_col = 'user_id';
            $img_col = null; // ตาราง users ไม่มีรูปภาพใน schema นี้
        } else {
            $id_col = $table . '_id';
            $img_col = null;
        }

        // --- 2. ค้นหาชื่อไฟล์รูปก่อนลบข้อมูลจาก DB ---
        if ($img_col) {
            $stmt_file = $db->prepare("SELECT $img_col FROM $table WHERE $id_col = ?");
            $stmt_file->execute([$id]);
            $file_name = $stmt_file->fetchColumn();

            // --- 3. สั่งลบไฟล์จริงออกจากโฟลเดอร์ ---
            if ($file_name && $file_name !== 'default-food.png') {
                $file_path = "./uploads/$folder/" . $file_name;
                if (file_exists($file_path)) {
                    @unlink($file_path); // ลบไฟล์รูปภาพ
                }
            }
        }

        // --- 4. ลบข้อมูลออกจาก Database ---
        $stmt = $db->prepare("DELETE FROM $table WHERE $id_col = ?");
        $stmt->execute([$id]);

        echo json_encode([
            "status" => "success", 
            "message" => "ลบข้อมูลจากตาราง $table และไฟล์รูปภาพเรียบร้อยแล้ว"
        ]);
    }
}
