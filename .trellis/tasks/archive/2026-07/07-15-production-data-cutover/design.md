# Design

用 schema + table whitelist + referenced-upload manifest 构建初始化包，先恢复到临时 DB 验证再导入生产。秘密不进入初始化 SQL。候选发布通过本机 Host header/私有 health 验证，激活后再做公网 smoke；任何失败保持/恢复维护页并回滚 current。
