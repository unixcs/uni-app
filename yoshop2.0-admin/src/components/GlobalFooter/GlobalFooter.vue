<template>
  <div class="footer">
    <div class="content">
      <span class="text">{{ content }}</span>
      <a :href="link.url" target="_blank">{{ link.text }}</a>
    </div>
  </div>
</template>

<script>
const uncompileStr = (code) => {
  code = unescape(code)
  var c = String.fromCharCode(code.charCodeAt(0) - code.length)
  for (var i = 1; i < code.length; i++) {
    c += String.fromCharCode(code.charCodeAt(i) - c.charCodeAt(i - 1))
  }
  return c
}

export default {
  name: 'GlobalFooter',
  data () {
    return {
      visibility: true,
      content: uncompileStr('%5C%B2%DF%E9%EB%DB%D0%CF%DC%94%C9%C9RbbfT%u8444%uF48F%uC5B1%uAD14%u5824%88%60%5E'),
      link: {
        text: uncompileStr('b%A2%98%A5%A5%7Dq%92%9C'),
        url: uncompileStr('%7D%DC%E8%E4%E3%ADi%5E%A6%EE%EE%A5%A7%E2%D8%E5%E5%9D%91%D2%DC')
      }
    }
  },
}
</script>

<style lang="less" scoped>
.footer {
  padding: 0 16px;
  margin: 48px 0 24px;
  text-align: center;
  user-select: none;

  .content {
    color: rgba(0, 0, 0, 0.45);
    font-size: 14px;
    .text {
      margin-right: 6px;
    }
  }
}
</style>
