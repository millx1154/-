<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET");

$host = "localhost";
$db_name = "student_tracking";
$username = "root"; 
$password = "";     

try {
    $conn = new PDO("mysql:host=" . $host . ";dbname=" . $db_name . ";charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $exception) {
    echo json_encode(["status" => "error", "message" => "เชื่อมต่อฐานข้อมูลล้มเหลว: " . $exception->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
// ใช้ json_decode แบบ Object เพื่อให้ตรงกับโครงสร้างเดิมของคุณ ($data->action)
$data = json_decode(file_get_contents("php://input"));

if ($method == 'POST') {
    
    // 1. กรณีรับข้อมูลที่มีการระบุ action
    if (isset($data->action)) {
        
        if ($data->action == 'save_tracking') {
            try {
                $stmt = $conn->prepare("UPDATE students SET advisor_notes = :note WHERE student_id = :student_id");
                
                $full_note = "วันที่ติดตาม: " . $data->track_date . "\n" .
                             "ปัญหา: " . $data->track_detail . "\n" .
                             "แนวทาง: " . $data->track_solution;

                $stmt->execute([
                    ':note' => $full_note,
                    ':student_id' => $data->student_id
                ]);
                
                echo json_encode(["status" => "success", "message" => "บันทึกการติดตามสำเร็จ"]);
            } catch(Exception $e) {
                echo json_encode(["status" => "error", "message" => "เกิดข้อผิดพลาด: " . $e->getMessage()]);
            }
            exit();
        }

        if ($data->action == 'add_user') {
            $check = $conn->prepare("SELECT id FROM users WHERE username = :username");
            $check->execute([':username' => $data->username]);
            if ($check->fetch()) {
                echo json_encode(["status" => "error", "message" => "Username นี้มีคนใช้แล้ว"]);
                exit();
            }
            $stmt = $conn->prepare("INSERT INTO users (username, password, fullname, role) VALUES (:username, :password, :fullname, :role)");
            $stmt->execute([':username' => $data->username, ':password' => $data->password, ':fullname' => $data->fullname, ':role' => $data->role]);
            echo json_encode(["status" => "success", "message" => "เพิ่มผู้ใช้สำเร็จ"]);
            exit();
        }

        // ---------- [ส่วนที่เพิ่มใหม่] แก้ไขข้อมูลผู้ใช้งาน ----------
        if ($data->action == 'edit_user') {
            try {
                if (!empty($data->password)) {
                    // กรณีพิมพ์รหัสผ่านใหม่เข้ามา ให้อัปเดตรหัสผ่านด้วย (ใช้ Plain text แบบเดิมเพื่อให้ Login ได้)
                    $stmt = $conn->prepare("UPDATE users SET fullname = :fullname, username = :username, role = :role, password = :password WHERE id = :id");
                    $stmt->execute([
                        ':fullname' => $data->fullname,
                        ':username' => $data->username,
                        ':role' => $data->role,
                        ':password' => $data->password,
                        ':id' => $data->id
                    ]);
                } else {
                    // กรณีไม่เปลี่ยนรหัสผ่าน
                    $stmt = $conn->prepare("UPDATE users SET fullname = :fullname, username = :username, role = :role WHERE id = :id");
                    $stmt->execute([
                        ':fullname' => $data->fullname,
                        ':username' => $data->username,
                        ':role' => $data->role,
                        ':id' => $data->id
                    ]);
                }
                echo json_encode(["status" => "success", "message" => "อัปเดตข้อมูลสำเร็จ"]);
            } catch(Exception $e) {
                echo json_encode(["status" => "error", "message" => "เกิดข้อผิดพลาดในการแก้ไข: " . $e->getMessage()]);
            }
            exit();
        }

        // ---------- [ส่วนที่เพิ่มใหม่] ลบผู้ใช้งาน ----------
        if ($data->action == 'delete_user') {
            try {
                $stmt = $conn->prepare("DELETE FROM users WHERE id = :id");
                $stmt->execute([':id' => $data->id]);
                echo json_encode(["status" => "success", "message" => "ลบข้อมูลสำเร็จ"]);
            } catch(Exception $e) {
                echo json_encode(["status" => "error", "message" => "เกิดข้อผิดพลาดในการลบ: " . $e->getMessage()]);
            }
            exit();
        }
        
        if ($data->action == 'login') {
            $stmt = $conn->prepare("SELECT id, username, fullname, role FROM users WHERE username=:username AND password=:password AND role=:role");
            $stmt->execute([':username' => $data->username, ':password' => $data->password, ':role' => $data->role]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode($user ? ["status" => "success", "user" => $user] : ["status" => "error", "message" => "ข้อมูลไม่ถูกต้อง"]);
            exit();
        }
    } 
    // 2. ส่วนบันทึกกลุ่มจาก PDF (แก้ไขแล้ว)
    else if (is_array($data) || (isset($data->students) && is_array($data->students))) {
        
        $studentsList = is_array($data) ? $data : $data->students;
        
        if (!empty($studentsList)) {
            try {
                $conn->beginTransaction();
                
                // คำสั่ง SQL แบบป้องกันการบันทึกซ้ำ (ถ้ามีรหัสนศ.อยู่แล้ว จะอัปเดตข้อมูลแทน)
                $stmt = $conn->prepare("INSERT INTO students (academic_year, term, student_id, fullname, branch, gpa, status) 
                                        VALUES (:academic_year, :term, :student_id, :fullname, :branch, :gpa, :status)
                                        ON DUPLICATE KEY UPDATE 
                                        fullname = VALUES(fullname), branch = VALUES(branch), gpa = VALUES(gpa), status = VALUES(status)");
                
                $count = 0;
                foreach ($studentsList as $std) {
                    // ดักจับชื่อตัวแปร status แบบต่างๆ
                    $student_status = '';
                    if (isset($std->status)) $student_status = $std->status;
                    elseif (isset($std->status_text)) $student_status = $std->status_text;
                    elseif (isset($std->student_status)) $student_status = $std->student_status;
                    elseif (isset($std->state)) $student_status = $std->state;

                    $stmt->execute([
                        ':academic_year' => $std->academic_year ?? '2568',
                        ':term' => $std->term ?? '1',
                        ':student_id' => $std->student_id ?? $std->id ?? '',
                        ':fullname' => $std->fullname ?? $std->name ?? '',
                        ':branch' => $std->branch ?? '',
                        ':gpa' => $std->gpa ?? 0.00,
                        ':status' => $student_status
                    ]);
                    $count++;
                }
                
                $conn->commit();
                echo json_encode(["status" => "success", "message" => "บันทึกสำเร็จจำนวน $count รายการ"]);
            } catch(Exception $e) {
                $conn->rollBack();
                echo json_encode(["status" => "error", "message" => "เกิดข้อผิดพลาดในการบันทึก DB: " . $e->getMessage()]);
            }
            exit();
        } else {
            echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลนักศึกษาในรายการ"]);
            exit();
        }
    }
} 
elseif ($method == 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    if ($action == 'get_users') {
        $stmt = $conn->prepare("SELECT id, username, fullname, role FROM users ORDER BY role ASC, id DESC");
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "success", "data" => $result]);
        exit();
    } 
    else if ($action == 'get_all' || $action == '') {
        $stmt = $conn->prepare("SELECT *, fullname AS name, status AS status_text, advisor_notes AS note FROM students ORDER BY academic_year DESC, term DESC, updated_at DESC");
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "success", "data" => $result]);
        exit();
    }
}
?>