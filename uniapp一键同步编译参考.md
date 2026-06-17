# uniapp一键同步编译参考

> 仅适用于 `yoshop2.0-uniapp` 的 **H5 + 微信小程序** 同步与编译。
>
> 如果你还改了后端 PHP、商家后台或超管后台，请改看：`项目编译与部署操作清单.md`

## 一键命令

在 WSL 主目录执行：

```bash
cd /opt/yoshop/yoshop2.0-uniapp
npm run dev:all
```

## 这个命令会做什么

1. 把 WSL 源码同步到 Windows 镜像目录
2. 编译 H5，并同步到 `public`
3. 编译微信小程序产物到 `unpackage/dist/dev/mp-weixin`

## 你要看的结果

- 浏览器：`http://localhost/#`
- 微信开发者工具：
  `D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp\unpackage\dist\dev\mp-weixin`

## 如果一键脚本失败

按下面顺序手动执行：

```bash
rsync -a --delete --exclude node_modules --exclude dist --exclude unpackage --exclude .git --exclude .vite /opt/yoshop/yoshop2.0-uniapp/ /mnt/d/Program/0/home/0/yoshop1/yoshop2.0-uniapp/
npm run build:h5:sync
npm run build:mp-weixin
```

## 注意

- H5 在 WSL 里编
- 微信小程序在 Windows 镜像目录里编
- HBuilderX / 微信开发者工具如果还显示旧版，先重开项目再看
