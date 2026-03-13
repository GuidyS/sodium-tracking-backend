<?php

    date_default_timezone_set('Asia/Bangkok');

    class Connect extends PDO {
        // แก้ไข 1: เติม __ ให้ construct() ทั้งสองจุด
        public function __construct() {
            
            // แก้ไข 2: ใช้ getenv() เพื่อดึงค่าจาก Railway ถ้าไม่มีให้ใช้ค่าในเครื่อง
            $host = getenv('MYSQLHOST') ?: 'localhost'; 
            $port = getenv('MYSQLPORT') ?: '3306';
            $dbname = getenv('MYSQLDATABASE') ?: 'railway'; // ปกติ Railway ตั้งชื่อฐานข้อมูลว่า railway
            $username = getenv('MYSQLUSER') ?: 'root'; 
            $password = getenv('MYSQLPASSWORD') ?: ''; // ใส่รหัสผ่าน local ของคุณไว้ตรงนี้
            

            // นำตัวแปรมาต่อกัน
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

            try {
                // ส่งค่าเข้าไปเชื่อมต่อ
                parent::__construct($dsn, $username, $password);

                // ตั้งค่า Error Mode
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

                $this->exec("SET time_zone = '+07:00'");
                $this->exec("set names utf8mb4");

            } catch (PDOException $e) {
                // ถ้าเชื่อมต่อไม่ได้ ให้แสดง Error ออกมาดู
                echo "Connection failed: " . $e->getMessage();
                exit;
            }
        }
    }

    // แก้ไข 3: ซ่อน Secret และปรับให้รองรับ URL ของระบบจริง
    define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
    define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');

    $redirectUri = getenv('GOOGLE_REDIRECT_URI') ?: 'http://localhost:8888/index.php?page=google-callback';
    define('GOOGLE_REDIRECT_URI', $redirectUri);

?>
