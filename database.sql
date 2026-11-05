CREATE DATABASE IF NOT EXISTS campus_smart_loan;
USE campus_smart_loan;

-- 1. Users & Students Combined Table (SSO Credentials + Loan Account Profile)
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(15),
    grade VARCHAR(20),
    role ENUM('STUDENT', 'VENDOR', 'ADMIN') DEFAULT 'STUDENT',
    credit_limit DECIMAL(10,2) DEFAULT 5000.00,
    current_balance DECIMAL(10,2) DEFAULT 0.00,
    account_status ENUM('ACTIVE', 'HELD', 'SUSPENDED') DEFAULT 'ACTIVE',
    pass VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Facilities Table
CREATE TABLE IF NOT EXISTS facilities (
    facility_id INT AUTO_INCREMENT PRIMARY KEY,
    facility_name VARCHAR(100) NOT NULL,
    category ENUM('CAFETERIA', 'LAUNDRY', 'PRINTING', 'STORE') NOT NULL
);

-- 3. Transactions Ledger Table
CREATE TABLE IF NOT EXISTS transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    facility_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('APPROVED', 'DECLINED') DEFAULT 'APPROVED',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (facility_id) REFERENCES facilities(facility_id) ON DELETE CASCADE
);

-- 4. Semester Invoices Table
CREATE TABLE IF NOT EXISTS semester_invoices (
    invoice_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    semester_code VARCHAR(20) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_status ENUM('UNPAID', 'PAID', 'OVERDUE') DEFAULT 'UNPAID',
    cms_hold_applied TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Default Seed Data (Default password for all accounts: admin123)
INSERT INTO users (student_id, full_name, email, phone, grade, role, credit_limit, current_balance, pass) VALUES
('ADM001', 'System Administrator', 'admin@strathmore.edu', '0700000000', 'Staff', 'ADMIN', 0.00, 0.00, '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1g.Xw0R5UqO/kY3J3k.1f6m9m5q6i2y'),
('100001', 'Misawo Nathaniel', 'misawo@strathmore.edu', '0711111111', 'Year 3', 'STUDENT', 5000.00, 0.00, '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1g.Xw0R5UqO/kY3J3k.1f6m9m5q6i2y'),
('100002', 'Kennedy Wayne', 'kennedy@strathmore.edu', '0722222222', 'Year 2', 'STUDENT', 5000.00, 1200.00, '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1g.Xw0R5UqO/kY3J3k.1f6m9m5q6i2y'),
('VEND01', 'Cafeteria Operator', 'cafeteria@strathmore.edu', '0733333333', 'Staff', 'VENDOR', 0.00, 0.00, '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1g.Xw0R5UqO/kY3J3k.1f6m9m5q6i2y')
ON DUPLICATE KEY UPDATE user_id=user_id;

INSERT INTO facilities (facility_name, category) VALUES
('Central Cafeteria', 'CAFETERIA'),
('Library Printing Hub', 'PRINTING'),
('Campus Book Store', 'STORE')
ON DUPLICATE KEY UPDATE facility_id=facility_id;