CREATE DATABASE IF NOT EXISTS ice_block_advertisement_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ice_block_advertisement_system;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(254) NOT NULL UNIQUE,
  phone VARCHAR(30) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer', 'producer', 'admin') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role_active (role, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS producers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  business_name VARCHAR(150) NOT NULL,
  address TEXT NOT NULL,
  location VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  operating_hours VARCHAR(120) NOT NULL DEFAULT '',
  status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_producers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS administrators (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_administrators_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  producer_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  ice_block_size VARCHAR(100) NOT NULL,
  price DECIMAL(12, 2) NOT NULL,
  quantity VARCHAR(100) NOT NULL DEFAULT '',
  status ENUM('available', 'unavailable') NOT NULL DEFAULT 'available',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_producer FOREIGN KEY (producer_id) REFERENCES producers(id) ON DELETE CASCADE,
  INDEX idx_products_producer (producer_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS advertisements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  producer_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  description TEXT NOT NULL,
  image_path VARCHAR(255) NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(254) NOT NULL,
  location VARCHAR(150) NOT NULL,
  operating_hours VARCHAR(120) NOT NULL DEFAULT '',
  status ENUM('pending', 'active', 'inactive') NOT NULL DEFAULT 'pending',
  view_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_advertisements_producer FOREIGN KEY (producer_id) REFERENCES producers(id) ON DELETE CASCADE,
  CONSTRAINT fk_advertisements_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  INDEX idx_advertisements_status_created (status, created_at),
  INDEX idx_advertisements_producer (producer_id),
  INDEX idx_advertisements_location (location)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS enquiries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  advertisement_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  producer_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  quantity VARCHAR(100) NOT NULL,
  message VARCHAR(1000) NOT NULL DEFAULT '',
  producer_response VARCHAR(1000) NOT NULL DEFAULT '',
  status ENUM('pending', 'responded', 'closed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_enquiries_ad FOREIGN KEY (advertisement_id) REFERENCES advertisements(id) ON DELETE CASCADE,
  CONSTRAINT fk_enquiries_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_enquiries_producer FOREIGN KEY (producer_id) REFERENCES producers(id) ON DELETE CASCADE,
  CONSTRAINT fk_enquiries_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_enquiries_producer_status (producer_id, status, created_at),
  INDEX idx_enquiries_customer (customer_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  details VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activity_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_activity_created (created_at),
  INDEX idx_activity_actor (actor_id)
) ENGINE=InnoDB;
