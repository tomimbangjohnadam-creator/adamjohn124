-- ============================================
-- QR Attendance System Database
-- Database: attendance_system
-- ============================================

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS attendance_system;
USE attendance_system;

-- ============================================
-- 1. ADMINS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE,
    full_name VARCHAR(100),
    role ENUM('super_admin', 'admin') DEFAULT 'admin',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default admin (password: admin123)
INSERT INTO admins (username, password, email, full_name, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@attendance.com', 'System Administrator', 'super_admin');

-- ============================================
-- 2. EMPLOYEES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    position VARCHAR(100) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    email VARCHAR(100) UNIQUE,
    phone VARCHAR(20),
    address TEXT,
    birth_date DATE DEFAULT NULL,
    age INT DEFAULT NULL,
    hire_date DATE DEFAULT NULL,
    employment_status ENUM('regular', 'probationary', 'contractual', 'part-time', 'resigned') DEFAULT 'regular',
    salary DECIMAL(12,2) DEFAULT 0.00,
    profile_image VARCHAR(255) DEFAULT NULL,
    qr_code_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_employee_id (employee_id),
    INDEX idx_email (email),
    INDEX idx_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 3. EMPLOYEE_PAYROLL_SETTINGS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS employee_payroll_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    hourly_rate DECIMAL(12,2) DEFAULT 0.00,
    daily_rate DECIMAL(12,2) DEFAULT 0.00,
    monthly_salary DECIMAL(12,2) DEFAULT 0.00,
    work_hours_per_day DECIMAL(5,2) DEFAULT 8.00,
    work_days_per_month INT DEFAULT 22,
    philhealth_rate DECIMAL(5,2) DEFAULT 2.50,
    sss_rate DECIMAL(5,2) DEFAULT 4.50,
    pagibig_rate DECIMAL(5,2) DEFAULT 2.00,
    overtime_rate DECIMAL(4,2) DEFAULT 1.25,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    UNIQUE KEY uk_employee_payroll (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 4. ATTENDANCE TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    date DATE NOT NULL,
    time_in TIME DEFAULT NULL,
    time_out TIME DEFAULT NULL,
    status ENUM('present', 'absent', 'late', 'half_day') DEFAULT 'present',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    UNIQUE KEY uk_attendance_employee_date (employee_id, date),
    INDEX idx_date (date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 5. EMPLOYEE_BREAKS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS employee_breaks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    date DATE NOT NULL,
    break_in TIME DEFAULT NULL,
    break_out TIME DEFAULT NULL,
    status ENUM('on_break', 'completed') DEFAULT 'on_break',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    INDEX idx_employee_date (employee_id, date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 6. EMPLOYEE_WORK_SCHEDULES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS employee_work_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    work_date DATE NOT NULL,
    status ENUM('present', 'absent', 'late', 'rest_day', 'holiday') DEFAULT 'present',
    hours_worked DECIMAL(6,2) DEFAULT 0.00,
    overtime_hours DECIMAL(6,2) DEFAULT 0.00,
    break_minutes INT DEFAULT 0,
    time_in TIME DEFAULT NULL,
    time_out TIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    UNIQUE KEY uk_schedule_employee_date (employee_id, work_date),
    INDEX idx_work_date (work_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 7. EMPLOYEE_REST_DAYS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS employee_rest_days (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    day_of_week VARCHAR(20) DEFAULT NULL, -- Monday, Tuesday, etc.
    custom_date DATE DEFAULT NULL,
    is_recurring TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    INDEX idx_employee (employee_id),
    INDEX idx_day_of_week (day_of_week),
    INDEX idx_custom_date (custom_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 8. PAYROLL_RECORDS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS payroll_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    payroll_month DATE NOT NULL, -- First day of the month (e.g., 2024-01-01)
    work_days INT DEFAULT 0,
    work_hours DECIMAL(6,2) DEFAULT 0.00,
    overtime_hours DECIMAL(6,2) DEFAULT 0.00,
    gross_pay DECIMAL(12,2) DEFAULT 0.00,
    philhealth_deduction DECIMAL(12,2) DEFAULT 0.00,
    sss_deduction DECIMAL(12,2) DEFAULT 0.00,
    pagibig_deduction DECIMAL(12,2) DEFAULT 0.00,
    total_deductions DECIMAL(12,2) DEFAULT 0.00,
    net_pay DECIMAL(12,2) DEFAULT 0.00,
    status ENUM('pending', 'computed', 'paid') DEFAULT 'pending',
    daily_breakdown JSON DEFAULT NULL,
    computed_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    UNIQUE KEY uk_payroll_employee_month (employee_id, payroll_month),
    INDEX idx_payroll_month (payroll_month),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 9. PAYROLL_HISTORY TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS payroll_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payroll_id INT NOT NULL,
    employee_id VARCHAR(20) NOT NULL,
    payroll_month DATE NOT NULL,
    work_days INT DEFAULT 0,
    work_hours DECIMAL(6,2) DEFAULT 0.00,
    overtime_hours DECIMAL(6,2) DEFAULT 0.00,
    gross_pay DECIMAL(12,2) DEFAULT 0.00,
    philhealth_deduction DECIMAL(12,2) DEFAULT 0.00,
    sss_deduction DECIMAL(12,2) DEFAULT 0.00,
    pagibig_deduction DECIMAL(12,2) DEFAULT 0.00,
    total_deductions DECIMAL(12,2) DEFAULT 0.00,
    net_pay DECIMAL(12,2) DEFAULT 0.00,
    payment_reference VARCHAR(100) DEFAULT NULL,
    payment_method ENUM('cash', 'bank', 'check', 'gcash') DEFAULT 'cash',
    payment_notes TEXT DEFAULT NULL,
    paid_at TIMESTAMP NOT NULL,
    daily_breakdown JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employee_id (employee_id),
    INDEX idx_payroll_month (payroll_month),
    INDEX idx_paid_at (paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 10. SCHEDULES TABLE (for class/subject schedules)
-- ============================================
CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    subject_code VARCHAR(50) DEFAULT NULL,
    subject_name VARCHAR(200) DEFAULT NULL,
    units INT DEFAULT 0,
    day VARCHAR(20) DEFAULT NULL, -- Monday, Tuesday, etc.
    time_start TIME DEFAULT NULL,
    time_end TIME DEFAULT NULL,
    room VARCHAR(100) DEFAULT NULL,
    semester VARCHAR(50) DEFAULT '1st Semester',
    school_year VARCHAR(20) DEFAULT '2024-2025',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    INDEX idx_employee (employee_id),
    INDEX idx_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Sample Data (Optional)
-- ============================================

-- Sample employees with different positions
INSERT INTO employees (employee_id, first_name, last_name, position, department, email, phone, hire_date, employment_status) VALUES
('EMP0001', 'Juan', 'Dela Cruz', 'Developer', 'IT', 'juan.delacruz@company.com', '09123456789', '2023-01-15', 'regular'),
('EMP0002', 'Maria', 'Santos', 'Manager', 'Operations', 'maria.santos@company.com', '09234567890', '2022-06-01', 'regular'),
('EMP0003', 'Jose', 'Rizal', 'Analyst', 'Finance', 'jose.rizal@company.com', '09345678901', '2023-03-20', 'regular'),
('EMP0004', 'Leonor', 'Rivera', 'Designer', 'Marketing', 'leonor.rivera@company.com', '09456789012', '2024-01-10', 'probationary'),
('EMP0005', 'Andres', 'Bonifacio', 'Tester', 'IT', 'andres.bonifacio@company.com', '09567890123', '2023-08-01', 'regular'),
('EMP0006', 'Gabriela', 'Silang', 'HR Staff', 'HR', 'gabriela.silang@company.com', '09678901234', '2023-11-15', 'regular'),
('EMP0007', 'Lapu', 'Lapu', 'Admin', 'Operations', 'lapu.lapu@company.com', '09789012345', '2022-09-01', 'regular'),
('EMP0008', 'Gregoria', 'De Jesus', 'Cashier', 'Finance', 'gregoria.dejesus@company.com', '09890123456', '2023-05-20', 'regular'),
('EMP0009', 'Apolinario', 'Mabini', 'Security', 'Operations', 'apolinario.mabini@company.com', '09901234567', '2023-07-01', 'regular'),
('EMP0010', 'Melchora', 'Aquino', 'Janitor', 'Operations', 'melchora.aquino@company.com', '09012345678', '2024-02-15', 'contractual');

-- Sample payroll settings for employees
INSERT INTO employee_payroll_settings (employee_id, hourly_rate, daily_rate, monthly_salary, work_hours_per_day, work_days_per_month, overtime_rate) VALUES
('EMP0001', 250, 2000, 44000, 8, 22, 1.25),
('EMP0002', 300, 2400, 52800, 8, 22, 1.25),
('EMP0003', 200, 1600, 35200, 8, 22, 1.25),
('EMP0004', 180, 1440, 31680, 8, 22, 1.25),
('EMP0005', 180, 1440, 31680, 8, 22, 1.25),
('EMP0006', 160, 1280, 28160, 8, 22, 1.25),
('EMP0007', 200, 1600, 35200, 8, 22, 1.25),
('EMP0008', 150, 1200, 26400, 8, 22, 1.25),
('EMP0009', 140, 1120, 24640, 8, 22, 1.25),
('EMP0010', 130, 1040, 22880, 8, 22, 1.25);

-- Sample rest days (Saturday and Sunday for all employees)
INSERT INTO employee_rest_days (employee_id, day_of_week, is_recurring) VALUES
('EMP0001', 'Saturday', 1),
('EMP0001', 'Sunday', 1),
('EMP0002', 'Saturday', 1),
('EMP0002', 'Sunday', 1),
('EMP0003', 'Saturday', 1),
('EMP0003', 'Sunday', 1),
('EMP0004', 'Saturday', 1),
('EMP0004', 'Sunday', 1),
('EMP0005', 'Saturday', 1),
('EMP0005', 'Sunday', 1),
('EMP0006', 'Saturday', 1),
('EMP0006', 'Sunday', 1),
('EMP0007', 'Saturday', 1),
('EMP0007', 'Sunday', 1),
('EMP0008', 'Saturday', 1),
('EMP0008', 'Sunday', 1),
('EMP0009', 'Saturday', 1),
('EMP0009', 'Sunday', 1),
('EMP0010', 'Saturday', 1),
('EMP0010', 'Sunday', 1);

-- Sample attendance records for today
INSERT INTO attendance (employee_id, date, time_in, time_out) VALUES
('EMP0001', CURDATE(), '08:00:00', '17:00:00'),
('EMP0002', CURDATE(), '08:15:00', '17:15:00'),
('EMP0003', CURDATE(), '08:30:00', '17:30:00'),
('EMP0004', CURDATE(), '08:00:00', NULL),
('EMP0005', CURDATE(), '08:45:00', '17:45:00');

-- Sample work schedules
INSERT INTO employee_work_schedules (employee_id, work_date, status, hours_worked, time_in, time_out) VALUES
('EMP0001', CURDATE(), 'present', 8.0, '08:00:00', '17:00:00'),
('EMP0002', CURDATE(), 'present', 8.0, '08:15:00', '17:15:00'),
('EMP0003', CURDATE(), 'present', 8.0, '08:30:00', '17:30:00'),
('EMP0004', CURDATE(), 'present', 0.0, '08:00:00', NULL),
('EMP0005', CURDATE(), 'present', 8.0, '08:45:00', '17:45:00');

-- ============================================
-- VIEWS for easier reporting
-- ============================================

-- View: Employee with payroll settings
CREATE OR REPLACE VIEW v_employee_payroll AS
SELECT 
    e.employee_id,
    e.first_name,
    e.last_name,
    e.position,
    e.department,
    e.email,
    e.phone,
    e.hire_date,
    e.employment_status,
    ps.hourly_rate,
    ps.daily_rate,
    ps.monthly_salary,
    ps.work_hours_per_day,
    ps.work_days_per_month,
    ps.philhealth_rate,
    ps.sss_rate,
    ps.pagibig_rate,
    ps.overtime_rate
FROM employees e
LEFT JOIN employee_payroll_settings ps ON e.employee_id = ps.employee_id;

-- View: Today's attendance with employee details
CREATE OR REPLACE VIEW v_today_attendance AS
SELECT 
    a.employee_id,
    e.first_name,
    e.last_name,
    e.position,
    a.date,
    a.time_in,
    a.time_out,
    TIMESTAMPDIFF(MINUTE, a.time_in, IFNULL(a.time_out, NOW())) / 60 AS hours_worked,
    CASE 
        WHEN a.time_in IS NULL THEN 'Absent'
        WHEN a.time_in IS NOT NULL AND a.time_out IS NULL THEN 'Working'
        ELSE 'Completed'
    END AS status
FROM attendance a
JOIN employees e ON a.employee_id = e.employee_id
WHERE a.date = CURDATE()
ORDER BY a.time_in DESC;

-- View: Monthly attendance summary
CREATE OR REPLACE VIEW v_monthly_attendance AS
SELECT 
    DATE_FORMAT(date, '%Y-%m') AS month,
    employee_id,
    COUNT(*) AS total_days,
    SUM(CASE WHEN time_in IS NOT NULL THEN 1 ELSE 0 END) AS present_days,
    SUM(CASE WHEN time_in IS NOT NULL AND time_out IS NULL THEN 1 ELSE 0 END) AS working_days
FROM attendance
GROUP BY DATE_FORMAT(date, '%Y-%m'), employee_id;

-- ============================================
-- STORED PROCEDURES
-- ============================================

-- Procedure: Get employee attendance for a specific month
DELIMITER //
CREATE PROCEDURE sp_get_employee_attendance(
    IN p_employee_id VARCHAR(20),
    IN p_month VARCHAR(7) -- Format: YYYY-MM
)
BEGIN
    SELECT 
        a.*,
        e.first_name,
        e.last_name,
        e.position,
        (SELECT SUM(TIMESTAMPDIFF(MINUTE, break_in, IFNULL(break_out, NOW()))) 
         FROM employee_breaks 
         WHERE employee_id = a.employee_id AND date = a.date) AS break_minutes
    FROM attendance a
    JOIN employees e ON a.employee_id = e.employee_id
    WHERE a.employee_id = p_employee_id 
      AND DATE_FORMAT(a.date, '%Y-%m') = p_month
    ORDER BY a.date DESC;
END //
DELIMITER ;

-- Procedure: Compute payroll for an employee for a specific month
DELIMITER //
CREATE PROCEDURE sp_compute_payroll(
    IN p_employee_id VARCHAR(20),
    IN p_payroll_month DATE
)
BEGIN
    DECLARE v_work_days INT DEFAULT 0;
    DECLARE v_work_hours DECIMAL(6,2) DEFAULT 0;
    DECLARE v_overtime_hours DECIMAL(6,2) DEFAULT 0;
    DECLARE v_gross_pay DECIMAL(12,2) DEFAULT 0;
    DECLARE v_philhealth DECIMAL(12,2) DEFAULT 0;
    DECLARE v_sss DECIMAL(12,2) DEFAULT 0;
    DECLARE v_pagibig DECIMAL(12,2) DEFAULT 0;
    DECLARE v_total_deductions DECIMAL(12,2) DEFAULT 0;
    DECLARE v_net_pay DECIMAL(12,2) DEFAULT 0;
    DECLARE v_hourly_rate DECIMAL(12,2) DEFAULT 0;
    DECLARE v_overtime_rate DECIMAL(4,2) DEFAULT 1.25;
    DECLARE v_philhealth_rate DECIMAL(5,2) DEFAULT 2.5;
    DECLARE v_sss_rate DECIMAL(5,2) DEFAULT 4.5;
    DECLARE v_pagibig_rate DECIMAL(5,2) DEFAULT 2.0;
    
    -- Get employee payroll settings
    SELECT 
        hourly_rate, overtime_rate, philhealth_rate, sss_rate, pagibig_rate
    INTO 
        v_hourly_rate, v_overtime_rate, v_philhealth_rate, v_sss_rate, v_pagibig_rate
    FROM employee_payroll_settings
    WHERE employee_id = p_employee_id;
    
    -- Calculate work days and hours from attendance
    SELECT 
        COUNT(*) INTO v_work_days
    FROM attendance
    WHERE employee_id = p_employee_id 
      AND DATE_FORMAT(date, '%Y-%m') = DATE_FORMAT(p_payroll_month, '%Y-%m')
      AND time_in IS NOT NULL;
    
    SELECT 
        SUM(TIMESTAMPDIFF(HOUR, time_in, IFNULL(time_out, NOW()))) INTO v_work_hours
    FROM attendance
    WHERE employee_id = p_employee_id 
      AND DATE_FORMAT(date, '%Y-%m') = DATE_FORMAT(p_payroll_month, '%Y-%m')
      AND time_in IS NOT NULL;
    
    -- Calculate overtime (hours beyond 8 hours per day)
    SELECT 
        SUM(GREATEST(TIMESTAMPDIFF(HOUR, time_in, IFNULL(time_out, NOW())) - 8, 0)) INTO v_overtime_hours
    FROM attendance
    WHERE employee_id = p_employee_id 
      AND DATE_FORMAT(date, '%Y-%m') = DATE_FORMAT(p_payroll_month, '%Y-%m')
      AND time_in IS NOT NULL
      AND TIMESTAMPDIFF(HOUR, time_in, IFNULL(time_out, NOW())) > 8;
    
    -- Calculate pay
    SET v_gross_pay = (v_work_hours * v_hourly_rate) + (v_overtime_hours * v_hourly_rate * v_overtime_rate);
    SET v_philhealth = v_gross_pay * (v_philhealth_rate / 100);
    SET v_sss = v_gross_pay * (v_sss_rate / 100);
    SET v_pagibig = v_gross_pay * (v_pagibig_rate / 100);
    SET v_total_deductions = v_philhealth + v_sss + v_pagibig;
    SET v_net_pay = v_gross_pay - v_total_deductions;
    
    -- Insert or update payroll record
    INSERT INTO payroll_records (
        employee_id, payroll_month, work_days, work_hours, overtime_hours,
        gross_pay, philhealth_deduction, sss_deduction, pagibig_deduction,
        total_deductions, net_pay, status, computed_at
    ) VALUES (
        p_employee_id, p_payroll_month, v_work_days, v_work_hours, v_overtime_hours,
        v_gross_pay, v_philhealth, v_sss, v_pagibig,
        v_total_deductions, v_net_pay, 'computed', NOW()
    ) ON DUPLICATE KEY UPDATE
        work_days = v_work_days,
        work_hours = v_work_hours,
        overtime_hours = v_overtime_hours,
        gross_pay = v_gross_pay,
        philhealth_deduction = v_philhealth,
        sss_deduction = v_sss,
        pagibig_deduction = v_pagibig,
        total_deductions = v_total_deductions,
        net_pay = v_net_pay,
        status = 'computed',
        computed_at = NOW();
    
    SELECT v_net_pay AS net_pay, v_gross_pay AS gross_pay, v_total_deductions AS total_deductions;
END //
DELIMITER ;

-- ============================================
-- TRIGGERS
-- ============================================

-- Trigger: Auto-calculate age when birth_date is inserted/updated
DELIMITER //
CREATE TRIGGER tr_employee_age_update
BEFORE UPDATE ON employees
FOR EACH ROW
BEGIN
    IF NEW.birth_date IS NOT NULL AND NEW.birth_date != OLD.birth_date THEN
        SET NEW.age = TIMESTAMPDIFF(YEAR, NEW.birth_date, CURDATE());
    END IF;
END //
DELIMITER ;

DELIMITER //
CREATE TRIGGER tr_employee_age_insert
BEFORE INSERT ON employees
FOR EACH ROW
BEGIN
    IF NEW.birth_date IS NOT NULL THEN
        SET NEW.age = TIMESTAMPDIFF(YEAR, NEW.birth_date, CURDATE());
    END IF;
END //
DELIMITER ;

-- Trigger: Auto-calculate daily_rate and monthly_salary when hourly_rate changes
DELIMITER //
CREATE TRIGGER tr_payroll_settings_update
BEFORE UPDATE ON employee_payroll_settings
FOR EACH ROW
BEGIN
    IF NEW.hourly_rate != OLD.hourly_rate OR NEW.work_hours_per_day != OLD.work_hours_per_day OR NEW.work_days_per_month != OLD.work_days_per_month THEN
        SET NEW.daily_rate = NEW.hourly_rate * NEW.work_hours_per_day;
        SET NEW.monthly_salary = NEW.daily_rate * NEW.work_days_per_month;
    END IF;
END //
DELIMITER ;

DELIMITER //
CREATE TRIGGER tr_payroll_settings_insert
BEFORE INSERT ON employee_payroll_settings
FOR EACH ROW
BEGIN
    SET NEW.daily_rate = NEW.hourly_rate * NEW.work_hours_per_day;
    SET NEW.monthly_salary = NEW.daily_rate * NEW.work_days_per_month;
END //
DELIMITER ;

-- ============================================
-- INDEXES FOR PERFORMANCE
-- ============================================

-- Additional indexes for better query performance
CREATE INDEX idx_attendance_employee_date ON attendance(employee_id, date);
CREATE INDEX idx_attendance_date_time ON attendance(date, time_in);
CREATE INDEX idx_breaks_employee_date ON employee_breaks(employee_id, date);
CREATE INDEX idx_schedules_employee_date ON employee_work_schedules(employee_id, work_date);
CREATE INDEX idx_payroll_month_status ON payroll_records(payroll_month, status);

-- ============================================
-- END OF DATABASE SCRIPT
-- ============================================

-- ============================================
-- ADDED DATABASE
-- ============================================
ALTER TABLE employees ADD COLUMN salary_type VARCHAR(20) DEFAULT 'Monthly';

-- ============================================
-- HOLIDAY DATABASE
-- ============================================
CREATE TABLE IF NOT EXISTS holidays (
    id INT PRIMARY KEY AUTO_INCREMENT,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(100) NOT NULL,
    holiday_type ENUM('regular', 'special') NOT NULL DEFAULT 'regular',
    is_recurring TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_holiday_date (holiday_date)
);

-- Add holiday_pay column to employee_payroll_settings
ALTER TABLE employee_payroll_settings 
ADD COLUMN holiday_pay_rate DECIMAL(5,2) DEFAULT 2.0 COMMENT '2.0 = double pay, 1.3 = 30% additional';

-- Add holiday pay tracking to payroll_records
ALTER TABLE payroll_records 
ADD COLUMN holiday_pay DECIMAL(10,2) DEFAULT 0,
ADD COLUMN holiday_hours DECIMAL(5,2) DEFAULT 0,
ADD COLUMN holiday_days INT DEFAULT 0;

-- Add to payroll_history as well
ALTER TABLE payroll_history 
ADD COLUMN holiday_pay DECIMAL(10,2) DEFAULT 0,
ADD COLUMN holiday_hours DECIMAL(5,2) DEFAULT 0,
ADD COLUMN holiday_days INT DEFAULT 0;

-- Add holiday type to attendance tracking
ALTER TABLE attendance 
ADD COLUMN holiday_type VARCHAR(20) DEFAULT NULL;

-- ============================================
-- OVERTIME DATABASE
-- ============================================

CREATE TABLE overtime_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) NOT NULL,
    work_date DATE NOT NULL,
    hours DECIMAL(5,2) NOT NULL,
    approved_hours DECIMAL(5,2) DEFAULT NULL,
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    requested_at DATETIME NOT NULL,
    approved_at DATETIME DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employee_date (employee_id, work_date),
    INDEX idx_status (status),
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
);
-- ============================================
-- 13th Pay Comlomns DATABASE
-- ============================================

ALTER TABLE payroll_records ADD COLUMN thirteenth_month_pay DECIMAL(12,2) DEFAULT 0;
ALTER TABLE payroll_records ADD COLUMN thirteenth_month_computed_at DATETIME DEFAULT NULL;

ALTER TABLE payroll_history ADD COLUMN thirteenth_month_pay DECIMAL(12,2) DEFAULT 0;

-- ============================================
-- POSITION DATABASE
-- ============================================
CREATE TABLE IF NOT EXISTS positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    position_name VARCHAR(100) NOT NULL UNIQUE,
    hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
    work_hours_per_day DECIMAL(5,2) DEFAULT 8,
    work_days_per_month DECIMAL(5,2) DEFAULT 22,
    philhealth_rate DECIMAL(5,2) DEFAULT 2.5,
    sss_rate DECIMAL(5,2) DEFAULT 4.5,
    pagibig_rate DECIMAL(5,2) DEFAULT 2,
    overtime_rate DECIMAL(5,2) DEFAULT 1.25,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- EMERGENCY CONTACT DATABASE
-- ============================================
ALTER TABLE employees 
ADD COLUMN emergency_contact_name VARCHAR(100) AFTER phone,
ADD COLUMN emergency_contact_phone VARCHAR(20) AFTER emergency_contact_name,
ADD COLUMN emergency_contact_relationship VARCHAR(50) AFTER emergency_contact_phone;

-- ============================================
-- Set hire_date to today for all employees who don't have one
-- ============================================
UPDATE employees SET hire_date = CURDATE() WHERE hire_date IS NULL;
-- ============================================
-- aDDITIONAL DEDUCTION
-- ============================================
CREATE TABLE IF NOT EXISTS employee_custom_deductions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) NOT NULL,
    deduction_name VARCHAR(100) NOT NULL,
    deduction_type ENUM('fixed', 'percent') DEFAULT 'fixed',
    deduction_value DECIMAL(10,2) NOT NULL,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
);

ALTER TABLE employee_work_schedules 
ADD COLUMN scheduled_time_in TIME NULL DEFAULT NULL AFTER work_date;
ALTER TABLE employee_work_schedules ADD COLUMN scheduled_time_out TIME NULL DEFAULT NULL;

ALTER TABLE payroll_records ADD COLUMN custom_deductions_breakdown TEXT NULL;
ALTER TABLE payroll_history ADD COLUMN custom_deductions_breakdown TEXT NULL;

CREATE TABLE IF NOT EXISTS overtime_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) NOT NULL,
    date DATE NOT NULL,
    overtime_hours DECIMAL(5,2) NOT NULL DEFAULT 0,
    approved_by VARCHAR(100) NOT NULL,
    approved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    status ENUM('approved','cancelled') DEFAULT 'approved',
    UNIQUE KEY uniq_ot (employee_id, date)
);

CREATE TABLE IF NOT EXISTS leave_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) NOT NULL,
    request_date DATE NOT NULL,
    request_type ENUM('day_off', 'rest_day_change') NOT NULL,
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    admin_notes TEXT,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL
);