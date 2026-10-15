-- Typecho Says 插件表结构（PostgreSQL）
-- 适用于 PostgreSQL 9.5+

-- =============================================
-- 1. says 表（主表）
-- =============================================
CREATE TABLE IF NOT EXISTS {{prefix}}says (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL DEFAULT 0,
    content TEXT NOT NULL,
    agent TEXT DEFAULT NULL,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    status SMALLINT NOT NULL DEFAULT 1,
    uuid VARCHAR(36) NOT NULL,
    ip VARCHAR(50) DEFAULT NULL,
    source VARCHAR(255) DEFAULT NULL,
    position VARCHAR(255) DEFAULT NULL,
    CONSTRAINT says_uuid_unique UNIQUE (uuid),
    CONSTRAINT says_status_created_at_idx CHECK (status BETWEEN 0 AND 1)
);

CREATE INDEX IF NOT EXISTS {{prefix}}idx_says_status ON {{prefix}}says (status);
CREATE INDEX IF NOT EXISTS {{prefix}}idx_says_created_at ON {{prefix}}says (created_at);
CREATE INDEX IF NOT EXISTS {{prefix}}idx_says_uuid ON {{prefix}}says (uuid);

-- =============================================
-- 2. says_hashtag 表（标签表）
-- =============================================
CREATE TABLE IF NOT EXISTS {{prefix}}says_hashtag (
    id SERIAL PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    color VARCHAR(7) DEFAULT NULL,
    icon VARCHAR(64) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT says_hashtag_name_unique UNIQUE (name)
);

CREATE INDEX IF NOT EXISTS {{prefix}}idx_says_hashtag_name ON {{prefix}}says_hashtag (name);
CREATE INDEX IF NOT EXISTS {{prefix}}idx_says_hashtag_created_at ON {{prefix}}says_hashtag (created_at);

-- =============================================
-- 3. says_hashtag_meta 表（说说和标签的关联表）
-- =============================================
CREATE TABLE IF NOT EXISTS {{prefix}}says_hashtag_meta (
    id SERIAL PRIMARY KEY,
    say_id INTEGER NOT NULL,
    hashtag_id INTEGER NOT NULL,
    created_at INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT says_hashtag_meta_say_hashtag_unique UNIQUE (say_id, hashtag_id),
    CONSTRAINT says_hashtag_meta_hashtag_id_idx CHECK (hashtag_id > 0)
);

CREATE INDEX IF NOT EXISTS {{prefix}}idx_says_hashtag_meta_hashtag_id ON {{prefix}}says_hashtag_meta (hashtag_id);
CREATE INDEX IF NOT EXISTS {{prefix}}idx_says_hashtag_meta_created_at ON {{prefix}}says_hashtag_meta (created_at);

-- =============================================
-- 4. says_attachment 表(附件表)
-- =============================================
CREATE TABLE IF NOT EXISTS {{prefix}}says_attachment (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    storage VARCHAR(32) NOT NULL,
    num INTEGER DEFAULT NULL,
    url TEXT NOT NULL,
    size INTEGER DEFAULT NULL,
    mime VARCHAR(128) DEFAULT NULL,
    md5 VARCHAR(32) DEFAULT NULL,
    remark TEXT DEFAULT NULL,
    created_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS {{prefix}}idx_says_attachment_created_at ON {{prefix}}says_attachment (created_at);

-- 5. says_attachment_meta 表(说说和附件的关联表)
-- =============================================
CREATE TABLE IF NOT EXISTS {{prefix}}says_attachment_meta (
    id SERIAL PRIMARY KEY,
    say_id INTEGER NOT NULL,
    attachment_id INTEGER NOT NULL,
    created_at INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT says_attachment_meta_say_attachment_unique UNIQUE (say_id, attachment_id)
);

CREATE INDEX IF NOT EXISTS {{prefix}}idx_says_attachment_meta_attachment_id ON {{prefix}}says_attachment_meta (attachment_id);
CREATE INDEX IF NOT EXISTS {{prefix}}idx_says_attachment_meta_created_at ON {{prefix}}says_attachment_meta (created_at);
