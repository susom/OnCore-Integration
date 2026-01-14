import { createApp } from 'vue';
import App from './App.vue';

const boot = window.oncoreBootData || {};
if (!boot.html && window.oncoreBootHtml) {
  boot.html = window.oncoreBootHtml;
}

const page = window.oncorePage || '';

createApp(App, { page, boot }).mount('#app');
