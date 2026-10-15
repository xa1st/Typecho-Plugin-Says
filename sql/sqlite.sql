-- Typecho Says 插件表结构（SQLite）
-- 适用于 SQLite 3.x

-- says 说说主表
CREATE TABLE IF NOT EXISTS {{prefix}}says (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL DEFAULT 0,
    content TEXT NOT NULL,
    agent TEXT DEFAULT NULL,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    uuid VARCHAR(36) NOT NULL,
    ip VARCHAR(50) DEFAULT NULL,
    source VARCHAR(255) DEFAULT NULL,
    position VARCHAR(255) DEFAULT NULL,
);
CREATE UNIQUE INDEX IF NOT EXISTS {{prefix}}says_uuid ON {{prefix}}says (uuid);
CREATE INDEX IF NOT EXISTS {{prefix}}says_status ON {{prefix}}says (status);
CREATE INDEX IF NOT EXISTS {{prefix}}says_created_at ON {{prefix}}says (created_at);

-- says_hashtag 表（标签表）
CREATE TABLE IF NOT EXISTS {{prefix}}says_hashtag (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name varchar(64) NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    color varchar(7) DEFAULT NULL,
    icon varchar(64) DEFAULT NULL,
    description text DEFAULT NULL,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX IF NOT EXISTS {{prefix}}says_hashtag_name ON {{prefix}}says_hashtag (name);
CREATE INDEX IF NOT EXISTS {{prefix}}says_hashtag_created_at ON {{prefix}}says_hashtag (created_at);

-- says_hashtag_meta 表（说说和标签的关联表）
CREATE TABLE IF NOT EXISTS {{prefix}}says_hashtag_meta (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    say_id INTEGER NOT NULL,
    hashtag_id INTEGER NOT NULL,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX IF NOT EXISTS {{prefix}}says_hashtag_meta_say_hashtag ON {{prefix}}says_hashtag_meta (say_id, hashtag_id);
CREATE INDEX IF NOT EXISTS {{prefix}}says_hashtag_meta_hashtag_id ON {{prefix}}says_hashtag_meta (hashtag_id);
CREATE INDEX IF NOT EXISTS {{prefix}}says_hashtag_meta_created_at ON {{prefix}}says_hashtag_meta (created_at);

-- says_attachment 表(附件表)
CREATE TABLE IF NOT EXISTS {{prefix}}says_attachment (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name varchar(255) NOT NULL,
    storage varchar(32) NOT NULL,
    num INTEGER DEFAULT NULL,
    url text NOT NULL,
    size INTEGER DEFAULT NULL,
    mime varchar(128) DEFAULT NULL,
    md5 varchar(32) DEFAULT NULL,
    remark text DEFAULT NULL,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS {{prefix}}says_attachment_created_at ON {{prefix}}says_attachment (created_at);

-- says_attachment_meta 表(说说和附件的关联表)
CREATE TABLE IF NOT EXISTS {{prefix}}says_attachment_meta (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    say_id INTEGER NOT NULL,
    attachment_id INTEGER NOT NULL,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX IF NOT EXISTS {{prefix}}says_attachment_meta_say_attachment ON {{prefix}}says_attachment_meta (say_id, attachment_id);
CREATE INDEX IF NOT EXISTS {{prefix}}says_attachment_meta_attachment_id ON {{prefix}}says_attachment_meta (attachment_id);