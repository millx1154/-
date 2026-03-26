import { sql } from '@vercel/postgres';

export default async function handler(req, res) {
    // 1. ตั้งค่า Header (CORS) อนุญาตให้รับส่งข้อมูล
    res.setHeader('Access-Control-Allow-Credentials', true);
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET,OPTIONS,PATCH,DELETE,POST,PUT');
    res.setHeader('Access-Control-Allow-Headers', 'X-CSRF-Token, X-Requested-With, Accept, Accept-Version, Content-Length, Content-MD5, Content-Type, Date, X-Api-Version');

    if (req.method === 'OPTIONS') {
        res.status(200).end();
        return;
    }

    try {
        // ==========================================
        // ส่วนที่ 2: ดึงข้อมูล (GET) ไปแสดงหน้า Dashboard/Table
        // ==========================================
        if (req.method === 'GET') {
            const { action } = req.query;

            if (action === 'get_users') {
                const { rows } = await sql`SELECT user_id AS id, username, fullname, role FROM users ORDER BY role ASC, user_id DESC`;
                return res.status(200).json({ status: 'success', data: rows });
            } 
            else if (action === 'get_all' || !action) {
                const { rows } = await sql`
                    SELECT a.*, a.fullname AS name, a.status_text AS status,
                           (SELECT track_detail FROM tracking_logs t WHERE t.student_id = a.student_id ORDER BY t.track_date DESC LIMIT 1) AS note
                    FROM academic_records a 
                    ORDER BY a.academic_year DESC, a.term DESC, a.record_id DESC
                `;
                return res.status(200).json({ status: 'success', data: rows });
            }
        }

        // ==========================================
        // ส่วนที่ 3: รับข้อมูลจากหน้าเว็บ (POST) เพื่อบันทึกลงฐานข้อมูล
        // ==========================================
        if (req.method === 'POST') {
            const data = req.body;

            // --- 3.1 ฟังก์ชันทั่วไป (มี action ส่งมา) ---
            if (data.action) {
                if (data.action === 'save_tracking') {
                    await sql`INSERT INTO tracking_logs (student_id, track_date, track_detail, track_solution) VALUES (${data.student_id}, ${data.track_date}, ${data.track_detail}, ${data.track_solution})`;
                    return res.status(200).json({ status: 'success', message: 'บันทึกการติดตามสำเร็จ' });
                }
                
                if (data.action === 'add_user') {
                    const check = await sql`SELECT user_id FROM users WHERE username = ${data.username}`;
                    if (check.rows.length > 0) return res.status(400).json({ status: 'error', message: 'Username นี้มีคนใช้แล้ว' });
                    
                    await sql`INSERT INTO users (username, password, fullname, role) VALUES (${data.username}, ${data.password}, ${data.fullname}, ${data.role})`;
                    return res.status(200).json({ status: 'success', message: 'เพิ่มผู้ใช้สำเร็จ' });
                }

                if (data.action === 'edit_user') {
                    if (data.password) {
                        await sql`UPDATE users SET fullname = ${data.fullname}, username = ${data.username}, role = ${data.role}, password = ${data.password} WHERE user_id = ${data.id}`;
                    } else {
                        await sql`UPDATE users SET fullname = ${data.fullname}, username = ${data.username}, role = ${data.role} WHERE user_id = ${data.id}`;
                    }
                    return res.status(200).json({ status: 'success', message: 'อัปเดตข้อมูลสำเร็จ' });
                }

                if (data.action === 'delete_user') {
                    await sql`DELETE FROM users WHERE user_id = ${data.id}`;
                    return res.status(200).json({ status: 'success', message: 'ลบข้อมูลสำเร็จ' });
                }
               
                if (data.action === 'delete_student') {
                    await sql`DELETE FROM academic_records WHERE record_id = ${data.id}`;
                    return res.status(200).json({ status: 'success', message: 'ลบประวัตินักศึกษาสำเร็จ' });
                }

                if (data.action === 'login') {
                    const { rows } = await sql`SELECT user_id AS id, username, fullname, role FROM users WHERE username=${data.username} AND password=${data.password} AND role=${data.role}`;
                    if (rows.length > 0) return res.status(200).json({ status: 'success', user: rows[0] });
                    return res.status(200).json({ status: 'error', message: 'ข้อมูลไม่ถูกต้อง' });
                }
            } 
            
            // --- 3.2 ฟังก์ชันรับข้อมูลสแกน PDF (ส่งมาเป็น Array) ---
            else if (Array.isArray(data) || (data.students && Array.isArray(data.students))) {
                const studentsList = Array.isArray(data) ? data : data.students;
                if (studentsList.length === 0) return res.status(400).json({ status: 'error', message: 'ไม่พบข้อมูล' });

                let count = 0;
                for (let std of studentsList) {
                    let student_status = std.status || std.status_text || (std.statusObj ? std.statusObj.text : '');
                    
                    await sql`
                        INSERT INTO academic_records (academic_year, term, student_id, fullname, branch, gpa, status_text) 
                        VALUES (${std.academic_year || '2568'}, ${std.term || '1'}, ${std.student_id || std.id}, ${std.fullname || std.name}, ${std.branch || ''}, ${std.gpa || 0}, ${student_status})
                        ON CONFLICT (student_id) DO UPDATE SET 
                            fullname = EXCLUDED.fullname, 
                            branch = EXCLUDED.branch, 
                            gpa = EXCLUDED.gpa, 
                            status_text = EXCLUDED.status_text
                    `;
                    count++;
                }
                return res.status(200).json({ status: 'success', message: `บันทึกข้อมูลสำเร็จ ${count} รายการ` });
            }
        }
        return res.status(405).json({ message: 'Method Not Allowed' });
    } catch (error) {
        console.error("API Error:", error);
        return res.status(500).json({ status: 'error', message: 'เซิร์ฟเวอร์ขัดข้อง: ' + error.message });
    }
}