<?php

require_once './config/config.php';

try {
    $db = new Connect();

    // ดึงข้อมูลยาโดยรวมคอลัมน์ med_category ที่เพิ่มใหม่
    $stmt_med = $db->query("SELECT * FROM medicines");
    $medicines = $stmt_med->fetchAll(PDO::FETCH_ASSOC);

    // ดึงข้อมูลสมุนไพร
    $stmt_herb = $db->query("SELECT * FROM herbs");
    $herbs = $stmt_herb->fetchAll(PDO::FETCH_ASSOC);

    // ถอดรหัส JSON content ของทั้งยาและสมุนไพร
    foreach ($medicines as &$m) {
        $content = json_decode($m['content'], true);
        // สร้าง Key กลาง (detail, warning) โดยเช็คทั้งไทยและอังกฤษ
        $m['display_detail'] = $content['detail'] ?? $content['ข้อบ่งใช้หลัก'] ?? '';
        $m['display_warning'] = $content['warning'] ?? $content['อาการเตือนที่ควรหยุดยา'] ?? '';
        $m['content'] = $content; // เก็บของเดิมไว้เผื่อหน้า User ยังเรียกใช้
    }
    foreach ($herbs as &$h) {
        $h['content'] = json_decode($h['content'], true);
    }

    echo json_encode([
        "status" => "success",
        "data" => [
            "medicines" => $medicines, // ส่งข้อมูลยาที่เป็น array รวมประเภท
            "herbs" => $herbs
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
