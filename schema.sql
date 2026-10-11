-- ============================================================
--  MC Modpack Hub 数据库结构
--  安装向导 install.php 会自动执行本文件
-- ============================================================

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- modpacks.status：0=待审核 1=已发布 2=已驳回
CREATE TABLE IF NOT EXISTS modpacks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(150) NOT NULL UNIQUE,
  title VARCHAR(150) NOT NULL,
  tagline VARCHAR(255) DEFAULT '',
  content MEDIUMTEXT,
  cover VARCHAR(255) DEFAULT '',
  mc_version VARCHAR(30) DEFAULT '',
  mod_loader VARCHAR(40) DEFAULT '',
  author VARCHAR(100) DEFAULT '',
  author_links TEXT,
  download_url VARCHAR(500) DEFAULT '',
  source_type ENUM('original','repost') NOT NULL DEFAULT 'original',
  source_name VARCHAR(150) NOT NULL DEFAULT '',
  category_id INT UNSIGNED DEFAULT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  status TINYINT NOT NULL DEFAULT 1,
  reject_reason VARCHAR(255) DEFAULT '',
  views INT UNSIGNED NOT NULL DEFAULT 0,
  downloads INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status (status),
  KEY idx_category (category_id),
  KEY idx_user (user_id),
  CONSTRAINT fk_modpack_category FOREIGN KEY (category_id)
    REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modpack_tags (
  modpack_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (modpack_id, tag_id),
  CONSTRAINT fk_mt_modpack FOREIGN KEY (modpack_id)
    REFERENCES modpacks(id) ON DELETE CASCADE,
  CONSTRAINT fk_mt_tag FOREIGN KEY (tag_id)
    REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 整合包下载项（支持多链接 / 服务器本地文件）
CREATE TABLE IF NOT EXISTS modpack_downloads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  modpack_id INT UNSIGNED NOT NULL,
  label VARCHAR(100) NOT NULL DEFAULT '',
  url VARCHAR(500) NOT NULL DEFAULT '',
  file_path VARCHAR(500) NOT NULL DEFAULT '',
  sort INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_md_modpack FOREIGN KEY (modpack_id)
    REFERENCES modpacks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(60) PRIMARY KEY,
  svalue TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  用户系统（用户 / 评论 / 点赞 / 收藏 / 站内信）
-- ============================================================

-- role：user=普通用户 author=作者 admin=管理员（admin 拥有作者全部权利）
-- author_status：0=未申请 1=待审核 2=已通过 3=已拒绝
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  nickname VARCHAR(50) NOT NULL DEFAULT '',
  avatar VARCHAR(255) NOT NULL DEFAULT '',
  role ENUM('user','author','admin') NOT NULL DEFAULT 'user',
  status TINYINT(1) NOT NULL DEFAULT 1,
  author_status TINYINT(1) NOT NULL DEFAULT 0,
  author_reason VARCHAR(255) NOT NULL DEFAULT '',
  muted TINYINT(1) NOT NULL DEFAULT 0,
  muted_until DATETIME NULL DEFAULT NULL,
  mute_reason VARCHAR(255) NOT NULL DEFAULT '',
  ban_reason VARCHAR(255) NOT NULL DEFAULT '',
  no_review TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- parent_id：0=顶层评论，>0=回复某条评论；is_pinned：1=置顶
CREATE TABLE IF NOT EXISTS comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  modpack_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED NOT NULL DEFAULT 0,
  content VARCHAR(1000) NOT NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  reviewed TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mp (modpack_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_likes (
  user_id INT UNSIGNED NOT NULL,
  comment_id INT UNSIGNED NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, comment_id),
  KEY idx_comment (comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modpack_likes (
  user_id INT UNSIGNED NOT NULL,
  modpack_id INT UNSIGNED NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, modpack_id),
  KEY idx_mp (modpack_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorites (
  user_id INT UNSIGNED NOT NULL,
  modpack_id INT UNSIGNED NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, modpack_id),
  KEY idx_mp (modpack_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 站内信：type=reply评论回复/like评论点赞/pin评论置顶/update整合包更新/system系统
CREATE TABLE IF NOT EXISTS messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  from_user_id INT UNSIGNED NOT NULL DEFAULT 0,
  type VARCHAR(30) NOT NULL DEFAULT 'system',
  title VARCHAR(150) NOT NULL DEFAULT '',
  content VARCHAR(500) NOT NULL DEFAULT '',
  link VARCHAR(255) NOT NULL DEFAULT '',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  KEY idx_read (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS follows (
  follower_id INT UNSIGNED NOT NULL,
  followed_id INT UNSIGNED NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (follower_id, followed_id),
  KEY idx_followed (followed_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 禁言申诉：status=0待处理 1已处理(通过) 2已驳回
CREATE TABLE IF NOT EXISTS appeals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  content VARCHAR(1000) NOT NULL,
  status TINYINT(1) NOT NULL DEFAULT 0,
  reply VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  handled_at DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
