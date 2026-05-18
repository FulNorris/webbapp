<div class="live-map-component">
    <div class="live-map-toolbar">
        <div>
            <h2>Livekarta</h2>
            <p><?php echo e(count($locations)); ?> aktiva positioner</p>
        </div>
        <div class="live-map-driver-strip">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $drivers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $driver): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                <span class="driver-pill <?php echo e(($driver['visibility'] ?? 'offline') === 'online' ? 'is-online' : ''); ?>" data-driver-pill="<?php echo e($driver['driverId']); ?>">
                    <span></span><?php echo e($driver['driverName']); ?>

                </span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
        </div>
    </div>

    <div id="live-map-canvas" class="live-map-canvas" wire:ignore></div>

        <?php
        $__scriptKey = '1675480046-0';
        ob_start();
    ?>
        <script>
            const initialLocations = <?php echo \Illuminate\Support\Js::from(array_values($locations))->toHtml() ?>;
            const initialDrivers = <?php echo \Illuminate\Support\Js::from($drivers)->toHtml() ?>;
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
                const pill = document.querySelector(`[data-driver-pill="${CSS.escape(payload.driverId || '')}"]`);
                if (!pill) return;
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
                    upsertMarker(event.detail?.payload || event.detail, true);
                });

                $wire.on('driver-visibility-updated', (event) => {
                    updateDriver(event.detail?.payload || event.detail);
                });
            }

            bootLiveMap();
        </script>
        <?php
        $__output = ob_get_clean();

        \Livewire\store($this)->push('scripts', $__output, $__scriptKey)
    ?>
</div>
<?php /**PATH /opt/www/resources/views/livewire/live-map.blade.php ENDPATH**/ ?>