<div class="live-map-component">
    <div class="live-map-toolbar">
        <div>
            <h2>Livekarta</h2>
            <p>{{ count($locations) }} aktiva positioner</p>
        </div>
        <div class="live-map-driver-strip">
            @foreach ($drivers as $driver)
                <span class="driver-pill {{ ($driver['visibility'] ?? 'offline') === 'online' ? 'is-online' : '' }}" data-driver-pill="{{ $driver['driverId'] }}">
                    <span></span>{{ $driver['driverName'] }}
                </span>
            @endforeach
        </div>
    </div>

    <div id="live-map-canvas" class="live-map-canvas" wire:ignore></div>

    @script
        <script>
            const initialLocations = @js(array_values($locations));
            const initialDrivers = @js($drivers);
            const markers = new Map();
            let map = null;

            function markerText(payload) {
                return [
                    `<strong>${escapeHtml(payload.recipientName || 'Leverans')}</strong>`,
                    escapeHtml(payload.deliveryAddress || ''),
                    payload.driverName ? `Förare: ${escapeHtml(payload.driverName)}` : '',
                    payload.updatedAt ? `Uppdaterad: ${escapeHtml(formatTime(payload.updatedAt))}` : '',
                ].filter(Boolean).join('<br>');
            }

            function escapeHtml(value) {
                return String(value).replace(/[&<>"']/g, (char) => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;',
                }[char]));
            }

            function formatTime(value) {
                const date = new Date(value);
                if (Number.isNaN(date.getTime())) return value;
                return new Intl.DateTimeFormat('sv-SE', { dateStyle: 'short', timeStyle: 'short' }).format(date);
            }

            function updateDriver(payload) {
                if (!payload?.driverId) return;
                let pill = document.querySelector(`[data-driver-pill="${CSS.escape(payload.driverId)}"]`);
                if (!pill) {
                    const strip = document.querySelector('.live-map-driver-strip');
                    if (!strip) return;
                    pill = document.createElement('span');
                    pill.className = 'driver-pill';
                    pill.dataset.driverPill = payload.driverId;
                    pill.innerHTML = `<span></span>${escapeHtml(payload.driverName || 'Förare')}`;
                    strip.appendChild(pill);
                }
                pill.classList.toggle('is-online', payload.visibility === 'online');
            }

            function upsertMarker(payload, shouldFit = false) {
                if (!map || !payload?.orderId) return;
                if (payload.trackingEnabled === false) {
                    const existing = markers.get(payload.orderId);
                    if (existing) {
                        map.removeLayer(existing);
                        markers.delete(payload.orderId);
                    }
                    return;
                }

                const lat = Number(payload.lat ?? payload.latitude);
                const lng = Number(payload.lng ?? payload.longitude);
                if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

                const position = [lat, lng];
                let marker = markers.get(payload.orderId);
                if (!marker) {
                    marker = window.L.marker(position).addTo(map);
                    markers.set(payload.orderId, marker);
                } else {
                    marker.setLatLng(position);
                }

                marker.bindPopup(markerText(payload));
                if (shouldFit) {
                    map.setView(position, Math.max(map.getZoom(), 14));
                }
            }

            function fitInitialBounds() {
                const markerList = Array.from(markers.values());
                if (!markerList.length) {
                    map.setView([59.3293, 18.0686], 10);
                    return;
                }

                const group = window.L.featureGroup(markerList);
                map.fitBounds(group.getBounds().pad(0.2), { maxZoom: 15 });
            }

            function bootLiveMap() {
                if (!window.L) {
                    window.setTimeout(bootLiveMap, 50);
                    return;
                }

                const canvas = document.getElementById('live-map-canvas');
                if (!canvas || map) return;

                map = window.L.map(canvas, {
                    zoomControl: true,
                    attributionControl: true,
                });

                window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap',
                }).addTo(map);

                Object.values(initialDrivers).forEach(updateDriver);
                initialLocations.forEach((payload) => upsertMarker(payload));
                fitInitialBounds();

                $wire.on('location-updated', (event) => {
                    const payload = event?.detail?.payload || event?.payload || event?.detail || event;
                    upsertMarker(payload, true);
                });

                $wire.on('driver-visibility-updated', (event) => {
                    const payload = event?.detail?.payload || event?.payload || event?.detail || event;
                    updateDriver(payload);
                });
            }

            bootLiveMap();
        </script>
    @endscript
</div>
