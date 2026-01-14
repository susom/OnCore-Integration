<template>
  <component :is="pageComponent" :boot="boot" />
</template>

<script>
import FieldMapPage from './pages/FieldMapPage.vue';
import SyncDiffPage from './pages/SyncDiffPage.vue';
import HtmlPage from './pages/HtmlPage.vue';

const pageMap = {
  field_map: FieldMapPage,
  sync_diff: SyncDiffPage,
};

export default {
  name: 'OnCoreApp',
  props: {
    page: {
      type: String,
      default: '',
    },
    boot: {
      type: Object,
      default: () => ({}),
    },
  },
  computed: {
    pageComponent() {
      return pageMap[this.page] || HtmlPage;
    },
  },
};
</script>
