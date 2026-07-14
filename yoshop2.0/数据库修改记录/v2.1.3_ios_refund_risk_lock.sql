## iOS App Store 退款审核与不可逆服务冻结
## 执行顺序：先执行本增量 SQL，再发布包含风险守卫的后端，最后 dry-run/apply 回填。

ALTER TABLE `yoshop_order`
  ADD COLUMN `ios_refund_risk_status` tinyint(3) unsigned NOT NULL DEFAULT '0'
    COMMENT 'iOS退款风险(0无 10服务冻结 20已退款)' AFTER `trade_id`,
  ADD COLUMN `ios_refund_risk_source` varchar(32) NOT NULL DEFAULT ''
    COMMENT '首次风险来源' AFTER `ios_refund_risk_status`,
  ADD COLUMN `ios_refund_risk_time` int(11) unsigned NOT NULL DEFAULT '0'
    COMMENT '首次风险时间' AFTER `ios_refund_risk_source`,
  ADD KEY `ios_refund_risk_status` (`ios_refund_risk_status`);

CREATE TABLE `yoshop_payment_ios_refund_inquiry` (
  `inquiry_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '问询事件ID',
  `order_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '订单ID，无法绑定时为0',
  `order_refund_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '本次决策关联退款单ID',
  `trade_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '交易ID，无法绑定时为0',
  `store_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '商城ID',
  `user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '用户ID',
  `pay_order_id` varchar(50) NOT NULL DEFAULT '' COMMENT '上游支付订单号',
  `fingerprint` char(64) NOT NULL DEFAULT '' COMMENT '规范化请求SHA-256',
  `migration_key` varchar(100) DEFAULT NULL COMMENT '历史迁移幂等键',
  `binding_status` varchar(40) NOT NULL DEFAULT '' COMMENT '交易订单绑定结果',
  `request_reason` varchar(1000) NOT NULL DEFAULT '' COMMENT '用户退款原因',
  `request_payload` text COMMENT '认证问询原始请求',
  `service_stage` varchar(20) NOT NULL DEFAULT 'UNKNOWN' COMMENT '服务状态快照',
  `order_status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '订单状态快照',
  `delivery_status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '服务开始状态快照',
  `receipt_status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '服务完成状态快照',
  `audit_status` tinyint(3) DEFAULT NULL COMMENT '商家审核状态快照',
  `result_code` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '给Apple的建议(0退款 1拒绝)',
  `result_info` varchar(255) NOT NULL DEFAULT '' COMMENT '建议说明',
  `evidence` varchar(1000) NOT NULL DEFAULT '' COMMENT '建议证据',
  `response_ms` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '响应耗时毫秒',
  `received_at` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '收到问询时间',
  `create_time` int(11) unsigned NOT NULL DEFAULT '0',
  `update_time` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`inquiry_id`),
  UNIQUE KEY `migration_key` (`migration_key`),
  KEY `order_received` (`order_id`,`received_at`,`inquiry_id`),
  KEY `trade_id` (`trade_id`),
  KEY `fingerprint` (`fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Apple iOS退款问询历史';
