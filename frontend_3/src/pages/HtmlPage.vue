<template>
  <div class="oncore-app" v-html="boot.html" />
</template>

<script>
export default {
  name: 'HtmlPage',
  props: {
    boot: {
      type: Object,
      default: () => ({ html: '' }),
    },
  },
};
</script>
