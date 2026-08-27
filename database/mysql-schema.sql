-- Apex Business Consultancy MySQL 8+ schema.
-- Import this file into a dedicated database using utf8mb4.
CREATE DATABASE IF NOT EXISTS apex_business CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE apex_business;

CREATE TABLE roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  status ENUM('active','inactive','pending') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL UNIQUE,
  slug VARCHAR(180) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  storage_name VARCHAR(255) NOT NULL UNIQUE,
  original_name VARCHAR(255) NOT NULL,
  public_url VARCHAR(500) NOT NULL,
  alt_text VARCHAR(255) NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_media_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX media_created_idx (created_at)
) ENGINE=InnoDB;

CREATE TABLE posts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  description TEXT NULL,
  content LONGTEXT NOT NULL,
  status ENUM('draft','published','scheduled','archived') NOT NULL DEFAULT 'draft',
  featured_media_id BIGINT UNSIGNED NULL,
  image_url VARCHAR(500) NULL,
  image_alt VARCHAR(255) NULL,
  image_caption VARCHAR(255) NULL,
  author_id BIGINT UNSIGNED NULL,
  tags VARCHAR(255) NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  allow_comments TINYINT(1) NOT NULL DEFAULT 1,
  published_at DATETIME NULL,
  seo_title VARCHAR(255) NULL,
  seo_description VARCHAR(320) NULL,
  focus_keyword VARCHAR(120) NULL,
  canonical_url VARCHAR(500) NULL,
  og_title VARCHAR(255) NULL,
  og_description VARCHAR(320) NULL,
  og_image_id BIGINT UNSIGNED NULL,
  robots_index TINYINT(1) NOT NULL DEFAULT 1,
  robots_follow TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_posts_media FOREIGN KEY (featured_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_posts_og_media FOREIGN KEY (og_image_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX posts_status_date_idx (status, published_at),
  INDEX posts_featured_idx (is_featured),
  FULLTEXT KEY posts_search_idx (title, description, content)
) ENGINE=InnoDB;

CREATE TABLE post_categories (
  post_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, category_id),
  CONSTRAINT fk_post_categories_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_post_categories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  content LONGTEXT NOT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  featured_media_id BIGINT UNSIGNED NULL,
  seo_title VARCHAR(255) NULL,
  seo_description VARCHAR(320) NULL,
  canonical_url VARCHAR(500) NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pages_media FOREIGN KEY (featured_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_pages_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX pages_status_idx (status)
) ENGINE=InnoDB;

CREATE TABLE menus (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  location ENUM('header','footer') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE menu_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(160) NOT NULL,
  href VARCHAR(500) NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  visible TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_menu_items_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
  INDEX menu_items_order_idx (menu_id, position)
) ENGINE=InnoDB;

CREATE TABLE settings (
  setting_key VARCHAR(190) PRIMARY KEY,
  setting_value LONGTEXT NOT NULL,
  value_type ENUM('string','integer','boolean','json') NOT NULL DEFAULT 'string',
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(190) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  status ENUM('success','failure') NOT NULL DEFAULT 'success',
  metadata JSON NULL,
  ip_address VARBINARY(16) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX activity_created_idx (created_at),
  INDEX activity_entity_idx (entity_type, entity_id)
) ENGINE=InnoDB;

INSERT INTO roles (name, description) VALUES
  ('Super Admin', 'Full system access'), ('Admin', 'Content and settings access'), ('Editor', 'Posts and pages'), ('Author', 'Own posts'), ('User', 'Profile only')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO permissions (name, description) VALUES
  ('dashboard.view', 'View dashboard'), ('posts.manage', 'Manage posts'), ('categories.manage', 'Manage categories'), ('pages.manage', 'Manage pages'), ('media.manage', 'Manage media'), ('menus.manage', 'Manage menus'), ('settings.manage', 'Manage settings'), ('users.manage', 'Manage users'), ('roles.manage', 'Manage roles'), ('logs.view', 'View activity logs')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.name = 'Super Admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r INNER JOIN permissions p ON p.name IN ('dashboard.view', 'posts.manage', 'categories.manage', 'pages.manage', 'media.manage', 'menus.manage', 'settings.manage', 'logs.view') WHERE r.name = 'Admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r INNER JOIN permissions p ON p.name IN ('dashboard.view', 'posts.manage', 'categories.manage', 'pages.manage', 'media.manage', 'logs.view') WHERE r.name = 'Editor';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r INNER JOIN permissions p ON p.name IN ('dashboard.view', 'posts.manage') WHERE r.name = 'Author';
