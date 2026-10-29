# Random Journey

เว็บแนะนำและสุ่มสถานที่ท่องเที่ยว รันบนเซิร์ฟเวอร์ Angsila (PHP 8.3 + MySQL 8.0)

URL: `https://angsila.informatics.buu.ac.th/~68160064/RandomJourney/`

## โครงสร้างโฟลเดอร์

โฟลเดอร์นี้ = `public_html/RandomJourney/` บนเซิร์ฟเวอร์ (โครงสร้างตรงกันทุกไฟล์)

```
RandomJourney/
├── index.php              หน้าแรก
├── register.php           สมัครสมาชิก
├── login.php              เข้าสู่ระบบ
├── logout.php             ออกจากระบบ (POST เท่านั้น)
├── profile.php            แก้ข้อมูลส่วนตัว / เปลี่ยนรหัสผ่าน
├── country.php            หน้าประเทศ (?c=jp): วีซ่าตามพาสปอร์ต ความเสี่ยง ค่าครองชีพ เบอร์ฉุกเฉิน
├── city.php               หน้าเมือง (?id=): แผนที่สถานที่ทั้งหมด อากาศ
├── place.php              หน้าสถานที่ (?id=): ราคา ระยะทาง เส้นทาง คำเตือน สถานที่ใกล้เคียง รีวิว
├── recommend.php          ระบบแนะนำ: ให้คะแนนความเหมาะสมแบบถ่วงน้ำหนักตามความสำคัญที่ผู้ใช้เลือก + เหตุผล
├── random.php             ระบบสุ่ม: ตัวกรองเป็นขอบเขต สุ่ม 1 ที่ หรือทั้งวัน (ดู/กิน/ช้อป/นอน) ล็อกประเทศ/เมืองแล้วสุ่มใหม่
├── review.php             บันทึก/ลบรีวิว, กด Helpful, Report (POST)
├── favorite.php           บันทึก/ลบสถานที่ใน Saved places (POST)
├── admin/                 Admin Panel (เฉพาะ role = admin)
│   ├── index.php          ภาพรวม + รายการข้อมูลที่ยังขาด
│   ├── list.php           ตารางข้อมูล (?e=country|city|place|safety|risk|visa)
│   ├── edit.php           ฟอร์มเพิ่ม/แก้ไข
│   ├── delete.php         ลบ (POST + CSRF)
│   ├── users.php          จัดการผู้ใช้: ตั้ง/ถอด admin, ระงับบัญชี
│   └── reports.php        รีวิวที่ถูก report: ซ่อน / เก็บไว้ / กู้คืน / ลบ
├── assets/
│   ├── css/style.css
│   ├── js/app.js
│   ├── js/map.js          สร้างแผนที่จาก JSON ในหน้า (ไม่มี inline script เพราะ CSP)
│   └── vendor/leaflet/    Leaflet 1.9.4 (เก็บในเครื่อง ไม่พึ่ง CDN)
├── includes/              โค้ดที่หน้าเว็บเรียกใช้ (เปิดตรงจาก browser = 403)
│   ├── index.php          กันการแสดงรายชื่อไฟล์ในโฟลเดอร์
│   ├── bootstrap.php      โหลด config, DB, session — ทุกหน้า require ไฟล์นี้ก่อน
│   ├── helpers.php        e(), url(), redirect(), flash() ฯลฯ
│   ├── db.php             การเชื่อมต่อ PDO
│   ├── security.php       security headers, session, CSRF
│   ├── auth.php           สมัคร / login / ล็อกเมื่อใส่รหัสผิด / สิทธิ์ admin
│   ├── form.php           ตัวช่วยแสดง error ในฟอร์ม
│   ├── admin.php          นิยามข้อมูลแต่ละประเภทของ Admin Panel (ตาราง, คอลัมน์, ฟิลด์ฟอร์ม)
│   ├── services.php       อัตราแลกเปลี่ยน (open.er-api.com / Frankfurter), อากาศ (Open-Meteo), ระยะทาง (Haversine) + cache ใน rj_api_cache
│   ├── catalog.php        query สำหรับหน้าประเทศ/เมือง/สถานที่ + map_block()
│   ├── weather_panel.php  กล่องพยากรณ์อากาศ
│   ├── discover.php       ตรรกะร่วมของระบบแนะนำและระบบสุ่ม (ตัวกรอง, การให้คะแนน)
│   ├── filter_form.php    ฟอร์มตัวกรองที่ใช้ร่วมกัน 2 หน้า
│   ├── reviews.php        รีวิว, ค่าเฉลี่ยดาว, report, saved places
│   ├── header.php
│   └── footer.php
├── private/
│   ├── index.php          กันการแสดงรายชื่อไฟล์ในโฟลเดอร์
│   ├── config.example.php ต้นแบบไฟล์ config
│   └── config.php         รหัส DB จริง (เปิดตรง = 403) — ห้าม commit ขึ้น git
└── database/              ใช้ import ผ่าน phpMyAdmin เท่านั้น ไม่ต้องอัปโหลดขึ้นเซิร์ฟเวอร์
    ├── schema.sql         สร้างตาราง rj_* ทั้งหมด (รันซ้ำ = ลบข้อมูลทิ้ง!)
    ├── seed_countries.sql ประเทศทั้งโลก 249 ประเทศ (รันซ้ำได้)
    └── seed_destinations.sql  5 ประเทศ 11 เมือง 97 สถานที่ ความเสี่ยง วีซ่า (รันซ้ำได้ ไม่สร้างข้อมูลซ้ำ แต่จะเขียนทับข้อความของประเทศ เมือง และวีซ่าที่แก้ใน Admin)
tools/                     สคริปต์ Python สร้าง seed_destinations.sql (ไม่ต้องอัปโหลด)
```

**ลำดับ import ใน phpMyAdmin:** `schema.sql` → `seed_countries.sql` → `seed_destinations.sql`

ราคา เวลาเปิดปิด และกฎวีซ่าใน `seed_destinations.sql` เป็นค่าประมาณ ณ ต.ค. 2026 — ตรวจกับแหล่งทางการก่อนนำเสนอ

## ทำไมไม่มีไฟล์ .htaccess

Angsila ไม่อนุญาตคำสั่งใน `.htaccess` (ใส่แล้วทั้งเว็บขึ้น 500 Internal Server Error) จึงป้องกันด้วย PHP แทน:

- ไฟล์ใน `includes/` และ `private/config.php` ตอบ **403** ถ้าถูกเปิดตรงจาก browser
- `index.php` ในโฟลเดอร์ `includes/`, `private/`, `database/` กันไม่ให้เซิร์ฟเวอร์แสดงรายชื่อไฟล์

**ห้ามสร้างไฟล์ `.htaccess` ในโปรเจกต์นี้** และอย่าทิ้งไฟล์สำรอง (`*.bak`, `*~`, `config.php.old`) ไว้บนเซิร์ฟเวอร์ เพราะไฟล์พวกนี้ไม่ถูกรันเป็น PHP และจะถูกดาวน์โหลดได้ทั้งไฟล์

## วิธีขึ้นเซิร์ฟเวอร์

1. copy `private/config.example.php` เป็น `private/config.php` แล้วใส่รหัส DB จริงในช่อง `pass`
2. อัปโหลดโฟลเดอร์ `RandomJourney` ไปไว้ใน `public_html/` ยกเว้น `database/`, `tools/`, `README.md`, `.gitignore` (ไม่ใช่ความลับ แต่ไม่จำเป็นต้องอยู่บนเว็บ)
3. เปิด `https://angsila.informatics.buu.ac.th/~68160064/RandomJourney/` ถ้าเห็นหน้า "Where to next?" และรายชื่อ 5 ประเทศ แปลว่าเชื่อม DB สำเร็จ
4. ทดสอบว่าไฟล์ลับถูกปิดจริง: เปิด `.../RandomJourney/private/config.php` และ `.../RandomJourney/includes/` ต้องได้ **403** (หน้าว่าง) ทั้งคู่

ถ้าอยากปลอดภัยขึ้นอีกชั้น ย้าย `config.php` ไปไว้ที่ `/home/BUU/68160064/rj_private/config.php` (นอก public_html) ได้เลย โค้ดจะหาเจอเองโดยไม่ต้องแก้อะไร

## ตั้งตัวเองเป็น admin

สมัครผ่านหน้าเว็บก่อน แล้วรันใน phpMyAdmin:

```sql
UPDATE rj_users SET role = 'admin' WHERE username = 'your_username';
```

## ความปลอดภัยที่มีแล้ว

| เรื่อง | ทำอย่างไร |
|---|---|
| เก็บรหัสผ่าน | `password_hash()` แบบ **Argon2id** และ rehash อัตโนมัติถ้าค่าเปลี่ยน |
| SQL Injection | PDO prepared statements ทุก query (`EMULATE_PREPARES = false`) |
| XSS | ทุกค่าที่แสดงผ่าน `e()` + Content-Security-Policy |
| CSRF | token ในทุกฟอร์ม POST ตรวจด้วย `hash_equals()` และเปลี่ยน token ใหม่หลัง login |
| Brute force | username ผิด 5 ครั้ง หรือ IP ผิด 20 ครั้งใน 15 นาที → ล็อก (ปรับใน config) |
| เดา username | ข้อความ error เหมือนกันทุกกรณี + ใช้เวลาตรวจเท่ากัน (dummy hash) |
| Session | cookie แบบ HttpOnly / SameSite=Lax / จำกัด path เฉพาะเว็บนี้, regenerate ID หลัง login, หมดอายุเมื่อไม่ใช้งาน 2 ชม. |
| Open redirect | `?next=` รับเฉพาะ path ภายในเว็บ |
| บัญชีถูกระงับ | ตรวจ `is_active` ทุก request ถ้าถูกระงับจะ logout ทันที |
| Logout | ต้องเป็น POST + CSRF (ลิงก์จากเว็บอื่นสั่ง logout ไม่ได้) |
