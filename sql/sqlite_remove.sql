-- Typecho Says 插件表结构删除(SQLite)
-- 适用于 SQLite 3.x
-- 注意:此脚本会删除插件创建的全部数据表,数据不可恢复,请谨慎执行!

-- SQLite 的 DROP TABLE 会一并删除该表上所有索引(含唯一索引),无需单独 DROP INDEX
DROP TABLE IF EXISTS {{prefix}}says_attachment_meta;
DROP TABLE IF EXISTS {{prefix}}says_hashtag_meta;
DROP TABLE IF EXISTS {{prefix}}says_attachment;
DROP TABLE IF EXISTS {{prefix}}says_hashtag;
DROP TABLE IF EXISTS {{prefix}}says;