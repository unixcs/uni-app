// ** 本文件是config.js的默认数据 (请勿修改本文件中的内容)
// ** 如需修改配置请移步到根目录的config.js文件
export default {

  // 系统名称
  name: "商城系统2.0",

  /**
   * 后端api地址 (必填; 斜杠/结尾; 请确保能访问)
   * 例如: https://www.你的域名.com/index.php?s=/api/
   */
  apiUrl: "./index.php?s=/api/",

  /**
   * 是否启用商城设置缓存
   * 将减少用户端重复请求; 正式运营时请设为true, 开启后商城设置同步前端需10分钟缓存
   */
  enabledSettingCache: true,

  /**
   * 是否显示H5调试登录入口
   */
  debugH5Login: false

}
