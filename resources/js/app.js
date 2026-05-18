import '../css/app.css';
import 'primeicons/primeicons.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import PrimeVue from 'primevue/config';
import Aura from '@primeuix/themes/aura';

import Button from 'primevue/button';
import Card from 'primevue/card';
import Checkbox from 'primevue/checkbox';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
import Divider from 'primevue/divider';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Password from 'primevue/password';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Tab from 'primevue/tab';
import TabList from 'primevue/tablist';
import TabPanel from 'primevue/tabpanel';
import TabPanels from 'primevue/tabpanels';
import Tabs from 'primevue/tabs';
import Textarea from 'primevue/textarea';
import Toolbar from 'primevue/toolbar';

const pages = import.meta.glob('./Pages/**/*.vue');

createInertiaApp({
  title: (title) => (title ? `${title} - Stuckbema` : 'Stuckbema'),
  resolve: (name) => pages[`./Pages/${name}.vue`](),
  setup({ el, App, props, plugin }) {
    const app = createApp({ render: () => h(App, props) });

    app.use(plugin);
    app.use(PrimeVue, {
      theme: {
        preset: Aura,
        options: {
          darkModeSelector: false,
        },
      },
    });

    app.component('Button', Button);
    app.component('Card', Card);
    app.component('Checkbox', Checkbox);
    app.component('Column', Column);
    app.component('DataTable', DataTable);
    app.component('DatePicker', DatePicker);
    app.component('Dialog', Dialog);
    app.component('Divider', Divider);
    app.component('InputText', InputText);
    app.component('Message', Message);
    app.component('Password', Password);
    app.component('Select', Select);
    app.component('Tag', Tag);
    app.component('Tab', Tab);
    app.component('TabList', TabList);
    app.component('TabPanel', TabPanel);
    app.component('TabPanels', TabPanels);
    app.component('Tabs', Tabs);
    app.component('Textarea', Textarea);
    app.component('Toolbar', Toolbar);

    app.mount(el);
  },
  progress: {
    color: '#2563eb',
  },
});
