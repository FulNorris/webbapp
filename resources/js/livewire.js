import '../css/app.css';
import 'leaflet/dist/leaflet.css';

import Echo from 'laravel-echo';
import L from 'leaflet';
import Pusher from 'pusher-js';

import marker2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({
  iconRetinaUrl: marker2x,
  iconUrl: markerIcon,
  shadowUrl: markerShadow,
});

window.L = L;
window.Pusher = Pusher;
const reverbScheme = import.meta.env.VITE_REVERB_SCHEME || window.location.protocol.replace(':', '');
const reverbPort = Number(import.meta.env.VITE_REVERB_PORT || (reverbScheme === 'https' ? 443 : 80));

window.Echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY || 'local-key',
  wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
  wsPort: reverbPort,
  wssPort: reverbPort,
  forceTLS: reverbScheme === 'https',
  enabledTransports: ['ws', 'wss'],
});
