-- 将 DIY 页面装修中的站内资源绝对地址转为相对路径
-- 适用于已部署旧数据的修复
-- 建议执行前先备份数据库

-- JSON 转义后的 uploads 资源
UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'https:\/\/wx.oiob.cn\/uploads\/', '\/uploads\/');

UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'http:\/\/wx.oiob.cn\/uploads\/', '\/uploads\/');

-- JSON 转义后的 assets 资源
UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'https:\/\/wx.oiob.cn\/assets\/', '\/assets\/');

UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'http:\/\/wx.oiob.cn\/assets\/', '\/assets\/');

-- JSON 转义后的 temp 资源
UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'https:\/\/wx.oiob.cn\/temp\/', '\/temp\/');

UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'http:\/\/wx.oiob.cn\/temp\/', '\/temp\/');

-- 非转义形式，兼容少量非 JSON 存储或手工写入的数据
UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'https://wx.oiob.cn/uploads/', '/uploads/');

UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'http://wx.oiob.cn/uploads/', '/uploads/');

UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'https://wx.oiob.cn/assets/', '/assets/');

UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'http://wx.oiob.cn/assets/', '/assets/');

UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'https://wx.oiob.cn/temp/', '/temp/');

UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'http://wx.oiob.cn/temp/', '/temp/');

-- 兜底处理：直接去掉旧域名前缀，兼容不同转义格式
UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'https:\\/\\/wx.oiob.cn', '');

UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'http:\\/\\/wx.oiob.cn', '');

UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'https://wx.oiob.cn', '');

UPDATE `yoshop_page`
SET `page_data` = REPLACE(`page_data`, 'http://wx.oiob.cn', '');

-- 执行后建议检查是否仍有残留
-- SELECT page_id FROM yoshop_page WHERE page_data LIKE '%wx.oiob.cn%';
