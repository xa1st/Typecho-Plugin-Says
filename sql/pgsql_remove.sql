-- Typecho Says 插件表结构删除(PostgreSQL)
-- 适用于 PostgreSQL 9.5+
-- 注意:此脚本会删除插件创建的全部数据表,数据不可恢复,请谨慎执行!

-- SERIAL 列对应的序列会随表自动删除,无需单独 DROP SEQUENCE
-- =============================================
-- 先删关联表,再删主表(表间无外键约束,顺序仅为规范)
-- =============================================
DROP TABLE IF EXISTS {{prefix}}says_attachment_meta;
DROP TABLE IF EXISTS {{prefix}}says_hashtag_meta;
DROP TABLE IF EXISTS {{prefix}}says_attachment;
DROP TABLE IF EXISTS {{prefix}}says_hashtag;
DROP TABLE IF EXISTS {{prefix}}says;