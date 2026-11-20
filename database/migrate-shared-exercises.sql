ALTER TABLE users
  ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE user_media
  ADD COLUMN is_shared TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS shared_exercise_categories (
  id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(50) NOT NULL,
  tone VARCHAR(10) CHARACTER SET ascii NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY shared_categories_creator_idx (created_by),
  CONSTRAINT shared_categories_creator_fk
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shared_exercises (
  id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(100) NOT NULL,
  explanation VARCHAR(2000) NOT NULL,
  minutes SMALLINT UNSIGNED NOT NULL,
  video_url VARCHAR(2048) NOT NULL DEFAULT '',
  video_media_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  animation_url VARCHAR(2048) NOT NULL DEFAULT '',
  animation_media_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY shared_exercises_category_idx (category_id),
  KEY shared_exercises_creator_idx (created_by),
  CONSTRAINT shared_exercises_creator_fk
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT shared_exercises_video_media_fk
    FOREIGN KEY (video_media_id) REFERENCES user_media(id) ON DELETE SET NULL,
  CONSTRAINT shared_exercises_animation_media_fk
    FOREIGN KEY (animation_media_id) REFERENCES user_media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shared_exercise_migrations (
  migration_key VARCHAR(64) CHARACTER SET ascii NOT NULL,
  completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shared_exercise_tombstones (
  item_type VARCHAR(16) CHARACTER SET ascii NOT NULL,
  id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  deleted_by BIGINT UNSIGNED DEFAULT NULL,
  deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (item_type, id),
  CONSTRAINT shared_exercise_tombstones_user_fk
    FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
