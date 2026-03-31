-- PlotConnect Database Schema
-- Run this SQL to create the database and tables

-- Create database
CREATE DATABASE IF NOT EXISTS plotconnect;
USE plotconnect;

-- Admins table
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Marketers table (property managers/salespeople)
CREATE TABLE IF NOT EXISTS marketers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    password VARCHAR(255) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Properties table
CREATE TABLE IF NOT EXISTS properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    marketer_id INT NOT NULL,
    owner_name VARCHAR(100),
    owner_email VARCHAR(100),
    phone VARCHAR(20),
    phone_number VARCHAR(20),
    phone_number_1 VARCHAR(20),
    phone_number_2 VARCHAR(20),
    whatsapp_phone VARCHAR(20),
    property_name VARCHAR(200),
    property_location VARCHAR(200),
    property_type VARCHAR(50),
    booking_type VARCHAR(50),
    package_selected VARCHAR(100),
    map_link TEXT,
    images_json LONGTEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
    payment_amount DECIMAL(10,2) DEFAULT NULL,
    payment_phone VARCHAR(20) DEFAULT NULL,
    checkout_request_id VARCHAR(120) DEFAULT NULL,
    merchant_request_id VARCHAR(120) DEFAULT NULL,
    payment_reference VARCHAR(120) DEFAULT NULL,
    mpesa_receipt_number VARCHAR(120) DEFAULT NULL,
    payment_result_desc TEXT DEFAULT NULL,
    payment_requested_at DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (marketer_id) REFERENCES marketers(id) ON DELETE CASCADE
);

-- Property Rooms table (room types and pricing)
CREATE TABLE IF NOT EXISTS property_rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    room_type VARCHAR(100),
    room_size VARCHAR(50),
    price DECIMAL(10,2) DEFAULT 0,
    availability VARCHAR(50),
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
);

-- Insert default admin user (username: admin, password: admin123)
-- Hash: password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO admins (username, password, full_name, email) 
VALUES ('admin', '$2y$10$YMjPJqCpNjXHBYJCSeKK7OVK7p6lFqKPJqKGmJ3L3kH8X8QK5W0G', 'System Admin', 'admin@plotconnect.com')
ON DUPLICATE KEY UPDATE username = username;

-- Insert demo marketer (name: John Doe, phone: 0712345678, password: admin123)
INSERT INTO marketers (name, phone, email, password) 
VALUES ('John Doe', '0712345678', 'john@demo.com', '$2y$10$YMjPJqCpNjXHBYJCSeKK7OVK7p6lFqKPJqKGmJ3L3kH8X8QK5W0G')
ON DUPLICATE KEY UPDATE phone = phone;
