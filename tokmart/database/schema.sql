-- ============================================================
-- Tokmart - MySQL schema
-- Converted from the original single-file SQLite implementation.
-- Charset utf8mb4 is required: the app stores Arabic text and emoji.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    email VARCHAR(191) NOT NULL,
    password VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    isAdmin TINYINT(1) NOT NULL DEFAULT 0,
    adminType VARCHAR(20) NOT NULL DEFAULT 'user',
    avatar_path VARCHAR(255) NULL,
    registeredAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastActivity DATETIME NULL,
    google_id VARCHAR(191) NULL,
    isVerified TINYINT(1) NOT NULL DEFAULT 0,
    verification_code VARCHAR(20) NULL,
    verification_expires DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_phone (phone),
    UNIQUE KEY uq_users_google_id (google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id VARCHAR(64) NOT NULL,
    name VARCHAR(191) NOT NULL,
    nameEn VARCHAR(191) NOT NULL,
    icon VARCHAR(100) NOT NULL DEFAULT 'fa-solid fa-tag',
    icon_image_path VARCHAR(255) NULL,
    banner_image_path VARCHAR(255) NULL,
    sortOrder INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    nameEn VARCHAR(255) NOT NULL,
    `desc` TEXT NULL,
    descEn TEXT NULL,
    category VARCHAR(64) NULL,
    brand VARCHAR(191) NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    oldPrice DECIMAL(12,2) NULL,
    image_path VARCHAR(255) NULL,
    images TEXT NULL,
    deliveryTime VARCHAR(100) NOT NULL DEFAULT 'خلال 24 ساعة',
    rating DECIMAL(2,1) NOT NULL DEFAULT 4.5,
    ratingCount INT UNSIGNED NOT NULL DEFAULT 0,
    isNew TINYINT(1) NOT NULL DEFAULT 0,
    isBestSeller TINYINT(1) NOT NULL DEFAULT 0,
    isFlashDeal TINYINT(1) NOT NULL DEFAULT 0,
    isFeatured TINYINT(1) NOT NULL DEFAULT 0,
    orderCount INT UNSIGNED NOT NULL DEFAULT 0,
    icon VARCHAR(100) NOT NULL DEFAULT 'fa-solid fa-box',
    discount VARCHAR(20) NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_products_category (category),
    CONSTRAINT fk_products_category FOREIGN KEY (category) REFERENCES categories(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id VARCHAR(64) NOT NULL,
    name VARCHAR(191) NOT NULL,
    nameEn VARCHAR(191) NOT NULL,
    icon VARCHAR(100) NOT NULL DEFAULT 'fa-solid fa-credit-card',
    image_path VARCHAR(255) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    isRechargeOnly TINYINT(1) NOT NULL DEFAULT 0,
    accountNumber VARCHAR(191) NULL,
    beneficiary VARCHAR(191) NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) NOT NULL,
    value TEXT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    orderId VARCHAR(32) NOT NULL,
    userId INT UNSIGNED NOT NULL,
    address VARCHAR(500) NULL,
    phone VARCHAR(20) NULL,
    payment VARCHAR(30) NULL,
    items LONGTEXT NULL,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    proofImage VARCHAR(255) NULL,
    transferImage VARCHAR(255) NULL,
    transferAmount DECIMAL(12,2) NOT NULL DEFAULT 0,
    accountNumber VARCHAR(191) NULL,
    beneficiary VARCHAR(191) NULL,
    refunded TINYINT(1) NOT NULL DEFAULT 0,
    date DATE NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_orderId (orderId),
    KEY idx_orders_userId (userId),
    KEY idx_orders_status (status),
    CONSTRAINT fk_orders_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    userId INT UNSIGNED NOT NULL,
    title VARCHAR(255) NULL,
    message TEXT NULL,
    type VARCHAR(30) NOT NULL DEFAULT 'info',
    isRead TINYINT(1) NOT NULL DEFAULT 0,
    isPushSent TINYINT(1) NOT NULL DEFAULT 0,
    link VARCHAR(255) NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user (userId, isRead)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chats (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    userId INT UNSIGNED NOT NULL,
    adminId INT UNSIGNED NOT NULL DEFAULT 1,
    message TEXT NULL,
    messageType VARCHAR(20) NOT NULL DEFAULT 'text',
    fileData LONGTEXT NULL,
    sender ENUM('user','admin') NOT NULL,
    isRead TINYINT(1) NOT NULL DEFAULT 0,
    readAt DATETIME NULL,
    isArchived TINYINT(1) NOT NULL DEFAULT 0,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_chats_user (userId),
    KEY idx_chats_admin (adminId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balance_recharges (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    userId INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    paymentMethod VARCHAR(64) NOT NULL,
    receiptImage VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    notes VARCHAR(500) NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_recharges_user (userId),
    CONSTRAINT fk_recharges_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(191) NOT NULL,
    code VARCHAR(20) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_password_resets_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
