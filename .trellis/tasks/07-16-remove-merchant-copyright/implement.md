# Implementation Plan — 移除商家后台版权

1. 从 `BasicLayout.vue` 删除 `footerRender` slot、`GlobalFooter` import 和组件注册。
2. 删除仅由该布局使用的 `src/components/GlobalFooter/index.vue`。
3. 搜索源代码确认指定明文、URL、编码常量和包装组件引用均为零。
4. 运行商家后台定向 lint 和完整 build；只检查 `dist`，不提交或复制生成产物。
5. 对构建产物重复字符串扫描，并检查布局主体回归。
