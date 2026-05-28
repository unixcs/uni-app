import request from '@/utils/request'

// api地址
const api = {
  login: 'passport/login',
  loginMpWx: 'passport/loginMpWx',
  loginMpWxMobile: 'passport/loginMpWxMobile',
  isPersonalMpweixin: 'passport/isPersonalMpweixin',
}

// 用户登录 (手机号+验证码)
export function login(data) {
  return request.post(api.login, data)
}

// 微信小程序快捷登录 (获取微信用户基本信息)
export function loginMpWx(data, option) {
  return request.post(api.loginMpWx, data, option)
}

// 是否需要填写昵称头像 (微信小程序端)
export function isPersonalMpweixin(data, option) {
  return request.post(api.isPersonalMpweixin, data, option)
}

// 微信小程序快捷登录 (授权手机号)
export function loginMpWxMobile(data, option) {
  return request.post(api.loginMpWxMobile, data, option)
}
