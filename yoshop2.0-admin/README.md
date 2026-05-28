

项目简述
----

基于 [Ant Design Pro](https://pro.ant.design/) 实现的 [萤火商城V2.0-admin端]

环境和依赖
----

- node — v18.16.0
- npm — v9.6.5
- yarn — v1.22.19

- [ant-design-vue](https://github.com/vueComponent/ant-design-vue) - Ant Design Of Vue 实现


项目运行和发布
----

- 修改配置文件
```
1. 打开文件：/public/config.js 
2. 修改服务端API信息
```

- 安装依赖
```
yarn config set ignore-engines true
yarn install
```

- 开发模式运行
```
yarn serve
```

- 编译项目
```
yarn build
```

- 发布项目
```
将编译完成的所有文件，复制替换到商城后端项目的/public/admin目录下
```

## 浏览器兼容

Modern browsers and IE10.

| <img src="https://raw.githubusercontent.com/alrra/browser-logos/master/src/edge/edge_48x48.png" alt="IE / Edge" width="24px" height="24px" /></br>IE / Edge | <img src="https://raw.githubusercontent.com/alrra/browser-logos/master/src/firefox/firefox_48x48.png" alt="Firefox" width="24px" height="24px" /></br>Firefox | <img src="https://raw.githubusercontent.com/alrra/browser-logos/master/src/chrome/chrome_48x48.png" alt="Chrome" width="24px" height="24px" /></br>Chrome | <img src="https://raw.githubusercontent.com/alrra/browser-logos/master/src/safari/safari_48x48.png" alt="Safari" width="24px" height="24px" /></br>Safari | <img src="https://raw.githubusercontent.com/alrra/browser-logos/master/src/opera/opera_48x48.png" alt="Opera" width="24px" height="24px" /></br>Opera |
| --- | --- | --- | --- | --- |
| IE10, Edge | last 2 versions | last 2 versions | last 2 versions | last 2 versions |
