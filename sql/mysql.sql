-- Typecho Says 插件表结构（MySQL）
-- 适用于 MySQL 5.7+ / MariaDB

-- =============================================
-- says 表（主表）
-- =============================================
CREATE TABLE IF NOT EXISTS `{{prefix}}says` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL DEFAULT 0,
    `content` text NOT NULL,
    `agent` text DEFAULT NULL,
    `created_at` int(11) NOT NULL DEFAULT 0,
    `updated_at` int(11) NOT NULL DEFAULT 0,
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `uuid` varchar(36) NOT NULL,
    `ip` varchar(50) DEFAULT NULL,
    `source` varchar(255) DEFAULT NULL,
    `position` varchar(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY `status` (`status`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- says_hashtag 表（标签表）
-- =============================================
CREATE TABLE IF NOT EXISTS `{{prefix}}says_hashtag` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(64) NOT NULL COMMENT '标签名称',
    `count` int(11) NOT NULL DEFAULT 0 COMMENT '使用次数',
    `color` varchar(7) DEFAULT NULL COMMENT '标签颜色（#开头的十六进制颜色）',
    `icon` varchar(64) DEFAULT NULL COMMENT '图标/emoji',
    `description` text DEFAULT NULL COMMENT '标签描述',
    `created_at` int(11) NOT NULL DEFAULT 0,
    `updated_at` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `name` (`name`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- says_hashtag_meta 表（说说和标签的关联表）
-- =============================================
CREATE TABLE IF NOT EXISTS `{{prefix}}says_hashtag_meta` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `say_id` int(11) NOT NULL COMMENT '关联 says 表',
    `hashtag_id` int(11) NOT NULL COMMENT '关联 says_hashtag 表',
    `created_at` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `say_hashtag` (`say_id`, `hashtag_id`),
    KEY `hashtag_id` (`hashtag_id`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- says_attachment 表（附件表）
-- =============================================
CREATE TABLE IF NOT EXISTS `{{prefix}}says_attachment` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL COMMENT '文件名称',
    `num` int(11) DEFAULT NULL COMMENT '使用次数',
    `url` text NOT NULL COMMENT '附件访问地址',
    `thumb` text DEFAULT NULL COMMENT '缩略图地址(无缩图时与url一致)',
    `large` text DEFAULT NULL COMMENT '灯箱大图地址(宽未超上限时与url一致)',
    `size` int(11) DEFAULT NULL COMMENT '文件大小(字节)',
    `mime` varchar(128) DEFAULT NULL COMMENT 'MIME类型',
    `md5` varchar(32) DEFAULT NULL COMMENT '文件 MD5',
    `storage` varchar(32) NOT NULL COMMENT '存储方式:local/backblaze/upyun',
    `remark` text DEFAULT NULL COMMENT '其它信息',
    `created_at` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- says_attachment_meta 表（说说和附件的关联表）
-- =============================================
CREATE TABLE IF NOT EXISTS `{{prefix}}says_attachment_meta` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `say_id` int(11) NOT NULL COMMENT '关联 says 表',
    `attachment_id` int(11) NOT NULL COMMENT '关联 says_attachment 表',
    `created_at` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `say_attachment` (`say_id`, `attachment_id`),
    KEY `attachment_id` (`attachment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
