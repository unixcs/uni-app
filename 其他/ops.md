
❯ ❯ '/opt/yoshop/虚拟支付当前阻力说明_20260703.m                                
      d' 小程序二次开发接入米大师虚拟支付，接入流                               
      程受阻；依据微信官方虚拟支付开发文档                                      
      和参考这份md文件 从第一型原理出发，帮我找出问题                           
  ---
  第一性原理分析：微信虚拟支付接入受阻的根因

  一、重新建立事实基线

  我从官方文档、代码、证据文件出发，按因果关系重建完整链路。

  官方文档确认的关键事实

  ┌────────────────────────────────────────────────────────────┬────────────┐
  │                            事实                            │    来源    │
  ├────────────────────────────────────────────────────────────┼────────────┤
  │                                                            │ 微信官方文 │
  │ query_order 返回 status=6 = "订单已经关闭（不可再使用）"   │ 档 -       │
  │                                                            │ 查询订单   │
  │                                                            │ API        │
  ├────────────────────────────────────────────────────────────┼────────────┤
  │                                                            │ 官方 API   │
  │ env=1（沙箱）只能配合开发版/体验版使用，正式版小程序不允许 │ 文档       │
  │ 沙箱支付                                                   │ -15011     │
  │                                                            │ 错误码     │
  ├────────────────────────────────────────────────────────────┼────────────┤
  │ iOS 不支持沙箱虚拟支付，仅 Android 支持 env=1              │ 官方 API   │
  │                                                            │ 文档       │
  ├────────────────────────────────────────────────────────────┼────────────┤
  │ DevTools miniprogram-automator 会拦截                      │ automator  │
  │ wx.requestVirtualPayment 调用                              │ 设计机制   │
  ├────────────────────────────────────────────────────────────┼────────────┤
  │ 沙箱 0.01 元测试仅 Android 有效，iOS 最低 1 元             │ 官方文档 + │
  │                                                            │  社区实践  │
  └────────────────────────────────────────────────────────────┴────────────┘

  证据文件中提取的关键数据

  Trade 10111 (out_trade_no=331399720368045620):
  WeChat 远端: errcode=0 (查到订单), status=6 (已关闭)
  create_time → update_time: 15 秒内关闭
  env_type=2, wx_order_id=VPO260703114411044192404
  本地: trade_state=10 (待支付), notify_times=0

  Trade 10116 (out_trade_no=331404686426410604):
  WeChat 远端: errcode=0 (查到订单), status=6 (已关闭)
  create_time → update_time: 18 秒内关闭
  env_type=2, wx_order_id=VPO260703120355007680579
  本地: trade_state=10 (待支付), notify_times=0
  session_key 刚刷新过，结果相同

  Trade 10317 (out_trade_no=331414359873826236):
  仅后端生成参数，客户端未实际调用 wx.requestVirtualPayment
  WeChat 远端: errcode=268490002 (数据不存在) — 符合预期

  ---
  二、从第一性原理推导根因

  核心发现：status=6 不是"资金待结算"，而是"订单已关闭"

  之前文档对 status=6 的理解有误。微信官方文档明确写着：status=6 = 
  "订单已经关闭（不可再使用）"。这不是支付成功的中间态，而是终态——订单被关闭了。

  根因链条重建

  Android 手机扫码 → 小程序启动 → checkout/submit 建单 ✅
      → cashier/orderPay 生成虚拟支付参数 ✅ (signData/paySig/signature 正确)
      → wx.requestVirtualPayment() 被调用 ✅
      → WeChat 远端创建订单记录 ✅ (errcode=0 可查到)
      → 订单在 15-18 秒内被关闭 → status=6 ❌
      → 用户手机显示"系统繁忙，请稍后再试" ❌
      → 后端从未收到支付回调 (notify_times=0) ❌

  订单在 WeChat 远端被"创建后立即关闭"，说明 WeChat 
  接受了请求、创建了记录、但随后拒绝了实际支付。
  这排除了签名错误（签名错误会直接返回 -15005/-15006，不会创建订单）。

  最可能的根因（按概率排序）

  🔴 根因 #1（概率最高）：DevTools Automator 会话污染

  这是最被低估的方向。证据链：

  1. run-wechat-virtual-sandbox-e2e.cjs 第 226 行通过 cli auto 启动 DevTools 的
  automator 模式
  2. Automator 模式的设计目的就是拦截 wx.requestVirtualPayment 
  调用，捕获参数供测试脚本验证，阻止真实支付
  3. 脚本结束后调用 restoreManualDevtoolsSession()
  清理，但如果清理不彻底，DevTools 残留的 automator
  状态会持续影响后续的二维码扫码测试
  4. 即使 Android 真机扫码，小程序运行时仍然通过 DevTools
  的预览通道连接，automator 拦截仍然生效

  这完美解释了两层现象：
  - 完全拦截：automator capture only 错误，不创建 trade（最近一次的现象）
  - 部分拦截：WeChat 创建了订单记录但 automator 阻止了支付确认，订单被关闭为
  status=6（trade 10111, 10116 的现象）

  为什么"系统繁忙，请稍后再试"而不是具体的错误码？ 因为 automator
  在框架层面拦截，支付流程被中断，WeChat 客户端只能显示通用错误。

  🟡 根因 #2：开发版 vs 预览版的构建类型问题

  当前编译输出位于 unpackage/dist/dev/mp-weixin，通过 DevTools 的 cli open + QR
  码预览方式让手机扫码。但微信虚拟支付沙箱要求：
  - env=1 只能用于开发版或体验版
  - 通过 DevTools QR
  码扫码打开的是"开发版预览"，其运行时环境与正式上传的"预览版"不同
  - 可能 DevTools 的预览通道本身就对虚拟支付有限制

  建议验证：通过 DevTools 的"上传"功能将代码上传为体验版，然后用体验版 QR 码在
  Android 上测试，而不是用 DevTools 的实时预览 QR 码。

  🟡 根因 #3：沙箱商品/测试账号的微信后台配置

  env=1 沙箱环境下：
  - product_id=vip0 必须在微信虚拟支付后台以开发版道具的身份创建和发布
  - 如果 vip0 只发布了正式版（env=0），沙箱查询会找不到道具
  - Android 上的测试微信账号必须在 offer_id 的沙箱测试账号白名单中

  🟢 已排除的方向：
  - 签名计算错误：如果 signData/paySig/signature 错误，WeChat
  不会创建订单记录（errcode 不会是 0）
  - session_key 过期：trade 10116 在 session_key 刚刷新后创建，结果相同
  - 普通微信支付串路：已确认 platform=wechat_virtual, provider=virtual
  - iOS 沙箱限制：已切回 Android

  ---
  三、具体行动建议（按优先级）

  优先级 1：彻底清除 Automator 状态

  # 在 Windows 侧执行 (通过 /mnt/c/Windows/System32/cmd.exe)
  # 1. 强制关闭所有 DevTools 和微信相关进程
  taskkill /IM wechatdevtools.exe /F /T
  taskkill /IM WeChatAppEx.exe /F /T
  taskkill /IM wechatwebdevtools.exe /F /T

  # 2. 清理 DevTools 缓存
  cd D:\Program\soft\wechattools
  cli.bat cache --clean session --project
  "D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp\unpackage\dist\dev\mp-weixin"
  cli.bat cache --clean storage --project "..."
  cli.bat cache --clean compile --project "..."
  cli.bat cache --clean network --project "..."

  # 3. 以纯手动模式重新打开（不要用 auto 模式）
  cli.bat open --project
  "D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp\unpackage\dist\dev\mp-weixin"

  关键：在手动打开的 DevTools 中，确认没有 automator 相关的进程或状态，然后在
  DevTools 中点击"预览"生成 QR 码，用 Android 手机扫码测试。

  优先级 2：改用体验版测试（而非 DevTools 实时预览）

  如果清除 automator 后仍然 status=6，则需要：
  1. 在 DevTools 中点击"上传"，将代码上传为体验版
  2. 在微信公众平台小程序管理后台设置为体验版
  3. 用体验版 QR 码在 Android 上扫码测试
  4. 这样可以完全脱离 DevTools 的运行环境

  优先级 3：检查微信后台沙箱配置

  1. 登录微信虚拟支付管理后台
  2. 确认 product_id=vip0 已作为开发版道具创建（而非仅正式版）
  3. 确认 offer_id=1450568898 已绑定 app_id=wxbf57e6f2ba72b712
  4. 确认测试微信账号在白名单中
  5. 确认沙箱 app_key 与代码中配置一致

  ---
  四、一句话总结

  当前问题的根因不是代码错误，而是 DevTools automator 模式对 
  wx.requestVirtualPayment 的拦截导致 WeChat 
  远端订单被创建后立即关闭（status=6）。 现有的故障诊断把 automator capture only
  当作"噪声"处理，但它恰恰是指向根因的关键信号。后台代码、签名逻辑、配置
  readiness 都是正确的——证据是 WeChat 远端确实创建了订单（errcode=0）。

  验证方法：完全退出 DevTools，上传体验版，用纯净的 Android
  微信扫码测试。如果体验版能成功支付，就确认了根因是 DevTools 预览通道 +
  automator 残留。