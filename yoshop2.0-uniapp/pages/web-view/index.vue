<template>
  <web-view :src="url"></web-view>
</template>

<script>
  export default {
    data() {
      return {
        url: ''
      }
    },
    onLoad({ src }) {
      this.url = decodeURIComponent(src)
      console.log('web-view', src, this.url)
    },

    methods: {

    }
  }
</script>

<style lang="scss" scoped>

</style>