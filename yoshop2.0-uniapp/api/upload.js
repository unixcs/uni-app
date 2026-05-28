import request from '@/utils/request'

// api地址
const api = {
  image: 'upload/image'
}

// 图片上传
export const image = (files, checkLogin = true) => {
  // 文件上传大小, 2M
  const maxSize = 1024 * 1024 * 2
  // 执行上传
  return new Promise((resolve, reject) => {
    request.urlFileUpload({ name: 'file', files, maxSize, data: { test: 123, checkLogin: Number(checkLogin) } })
      .then(result => resolve(result.map(item => item.data.fileInfo.file_id), result))
      .catch(reject)
  })
}