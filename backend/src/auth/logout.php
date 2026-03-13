<?php
require_once './config/config.php';
session_start();
session_unset();
session_destroy(); // ทำลาย Session ทั้งหมดบนเซิร์ฟเวอร์
echo json_encode(["status" => "success", "message" => "ออกจากระบบสำเร็จ"]);
exit;