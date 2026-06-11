<div class="live-map-component" wire:poll.1s="refreshLocations">
    <div class="live-map-toolbar">
        <div>
            <h2>Livekarta</h2>
            <p>{{ count($locations) }} aktiva positioner · uppdateras varje sekund</p>
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
            const latestLocations = new Map();
            const driverStates = new Map();
            let map = null;
            let followDriverId = null;

            function markerText(payload) {
                return [
                    `<strong>${escapeHtml(payload.recipientName || payload.driverName || 'Position')}</strong>`,
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
                const driverId = String(payload.driverId);
                driverStates.set(driverId, payload.visibility || 'offline');

                let pill = document.querySelector(`[data-driver-pill="${CSS.escape(driverId)}"]`);
                if (!pill) {
                    const strip = document.querySelector('.live-map-driver-strip');
                    if (!strip) return;
                    pill = document.createElement('span');
                    pill.className = 'driver-pill';
                    pill.dataset.driverPill = driverId;
                    strip.appendChild(pill);
                }

                pill.innerHTML = `<span></span>${escapeHtml(payload.driverName || 'Förare')}`;
                pill.classList.toggle('is-online', payload.visibility === 'online');
            }

            function isDriverOnline(driverId) {
                return driverId && driverStates.get(String(driverId)) === 'online';
            }

            function shouldFollow(payload, forceFollow = false) {
                const driverId = payload?.driverId ? String(payload.driverId) : null;
                if (forceFollow) return true;
                if (!driverId) return !followDriverId && latestLocations.size <= 1;
                if (followDriverId && followDriverId === driverId) return true;
                if (!followDriverId && isDriverOnline(driverId)) {
                    followDriverId = driverId;
                    return true;
                }

                return false;
            }

            function upsertMarker(payload, forceFollow = false) {
                if (!map || (!payload?.orderId && !payload?.driverId)) return;
                const orderId = String(payload.orderId);

                if (payload.trackingEnabled === false) {
                    const existing = markers.get(orderId);
                    if (existing) {
                        map.removeLayer(existing);
                        markers.delete(orderId);
                    }
                    latestLocations.delete(orderId);
                    return;
                }

                const lat = Number(payload.lat ?? payload.latitude);
                const lng = Number(payload.lng ?? payload.longitude);
                if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

                const normalizedPayload = { ...payload, orderId, lat, lng };
                latestLocations.set(orderId, normalizedPayload);

                const position = [lat, lng];
                let marker = markers.get(orderId);
                if (!marker) {
                    marker = window.L.marker(position).addTo(map);
                    markers.set(orderId, marker);
                } else {
                    marker.setLatLng(position);
                }

                marker.bindPopup(markerText(normalizedPayload));
                if (shouldFollow(normalizedPayload, forceFollow)) {
                    map.setView(position, Math.max(map.getZoom(), 14));
                }
            }

            function removeStaleMarkers(activeOrderIds) {
                markers.forEach((marker, orderId) => {
                    if (!activeOrderIds.has(orderId)) {
                        map.removeLayer(marker);
                        markers.delete(orderId);
                        latestLocations.delete(orderId);
                    }
                });
            }

            function followOnlineDriver() {
                if (!map || !latestLocations.size) return;

                const locations = Array.from(latestLocations.values());
                const onlineLocation = locations.find((payload) => payload.driverId && isDriverOnline(payload.driverId));
                const followedLocation = followDriverId
                    ? locations.find((payload) => payload.driverId && String(payload.driverId) === followDriverId)
                    : null;
                const target = followedLocation || onlineLocation || locations[0];

                if (!target) return;
                if (target.driverId) {
                    followDriverId = String(target.driverId);
                }

                map.setView([target.lat, target.lng], Math.max(map.getZoom(), 14));
            }

            function syncLocations(locations) {
                if (!Array.isArray(locations)) return;

                const activeOrderIds = new Set();
                locations.forEach((payload) => {
                    if (payload?.orderId) {
                        activeOrderIds.add(String(payload.orderId));
                    }
                    upsertMarker(payload);
                });

                removeStaleMarkers(activeOrderIds);
                followOnlineDriver();
            }

            function syncDrivers(drivers) {
                const driverList = Array.isArray(drivers) ? drivers : Object.values(drivers || {});
                driverList.forEach(updateDriver);
            }

            function livewirePayload(event) {
                if (event?.detail && !Array.isArray(event.detail)) {
                    return event.detail;
                }
                if (Array.isArray(event?.detail)) {
                    return event.detail[0] || {};
                }

                return event || {};
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

                syncDrivers(initialDrivers);
                initialLocations.forEach((payload) => upsertMarker(payload));
                fitInitialBounds();
                followOnlineDriver();

                $wire.on('location-updated', (event) => {
                    const detail = livewirePayload(event);
                    const payload = detail?.payload || detail;
                    upsertMarker(payload, true);
                });

                $wire.on('driver-visibility-updated', (event) => {
                    const detail = livewirePayload(event);
                    const payload = detail?.payload || detail;
                    updateDriver(payload);
                    followOnlineDriver();
                });

                $wire.on('live-map-refreshed', (event) => {
                    const detail = livewirePayload(event);
                    syncDrivers(detail.drivers || []);
                    syncLocations(detail.locations || []);
                });
            }

            bootLiveMap();
        </script>
    @endscript
</div>
