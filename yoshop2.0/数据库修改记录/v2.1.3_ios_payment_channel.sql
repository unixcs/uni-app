## iOS / 非 iOS 支付渠道分类投影
## 执行顺序：先加字段，再发布后端写入逻辑，最后运行分批回填命令。

ALTER TABLE `yoshop_payment_trade`
  ADD COLUMN `channel_class` tinyint(3) unsigned NOT NULL DEFAULT '0'
    COMMENT '支付渠道分类(0待确认 10非iOS 20iOS Apple)'
    AFTER `platform`;
