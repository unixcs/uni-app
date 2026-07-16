# 移除商家后台版权信息

## Goal

商家登录后的所有页面不再显示或携带“Copyright © 2024 萤火商城V2.0 / YIOVO.COM / https://www.yiovo.com”版权声明。

## Background

- `yoshop2.0-store/src/layouts/BasicLayout.vue:27-29,44,49-53` 在所有登录后页面挂载 `@/components/GlobalFooter`。
- `yoshop2.0-store/src/components/GlobalFooter/index.vue:1-45` 通过编码字符串解码出指定版权文字、链接名和 URL。
- 该包装组件只被 `BasicLayout.vue` 使用；登录布局 `UserLayout.vue` 中的示例版权已注释，和本问题无关。
- 仓库规范禁止手改 `dist/` 与 `yoshop2.0/public/store` 生成产物；新发布包由源代码重新构建。

## Requirements

- R1：从 `BasicLayout.vue` 删除全局页脚 slot、组件 import 和注册。
- R2：删除不再使用的 `src/components/GlobalFooter/index.vue`，确保编码常量和链接源也不存在，而非仅通过 CSS 隐藏。
- R3：不修改 ProLayout 通用页脚实现、不删除第三方 LICENSE、不手改构建产物。

## Acceptance Criteria

- [ ] AC1：登录后任意路由均不渲染指定版权文字和链接。
- [ ] AC2：`yoshop2.0-store/src` 中不存在指定明文、URL、三段编码常量或对包装组件的引用。
- [ ] AC3：商家后台定向 lint 和生产模式构建成功。
- [ ] AC4：新构建产物中不存在 `萤火商城V2.0`、`YIOVO.COM`、`www.yiovo.com` 或原编码常量。
- [ ] AC5：页面主体、右上角账号区和路由渲染不受影响。

## Out of Scope

- 删除源文件头、第三方依赖许可证或安装器版权。
- 修改小程序或平台总后台品牌内容。
