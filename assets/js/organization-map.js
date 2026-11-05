(() => {
    const root = document.getElementById('location-picker');
    if (!root) return;
    const form = root.closest('form');
    const lat = document.getElementById('latitude');
    const lng = document.getElementById('longitude');
    const radius = document.getElementById('radius_meters');
    const status = document.getElementById('location-status');
    const summary = document.getElementById('location-summary');
    const locate = document.getElementById('use-current-location');
    const center = document.getElementById('use-map-center');
    const canvas = document.getElementById('location-map');
    let selected = lat.value !== '' && lng.value !== '' && Number.isFinite(Number(lat.value)) && Number.isFinite(Number(lng.value));
    let map, marker, circle, request = 0;
    const meters = () => radius.validity.valid && radius.value !== '' ? Number(radius.value) : null;
    const describe = () => {
        summary.textContent = selected ? `พิกัดที่เลือก: ${lat.value}, ${lng.value} · รัศมี ${meters() ?? '—'} เมตร` : 'ยังไม่ได้เลือกพิกัด';
    };
    const select = (latitude, longitude, fit = false) => {
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || Math.abs(latitude) > 90) return;
        longitude = ((longitude + 180) % 360 + 360) % 360 - 180;
        lat.value = latitude.toFixed(7);
        lng.value = longitude.toFixed(7);
        selected = true;
        const point = [Number(lat.value), Number(lng.value)];
        if (map) {
            if (!marker) {
                marker = L.marker(point, {draggable: true, title: 'พิกัดลงเวลา', alt: 'หมุดพิกัดลงเวลา'}).addTo(map);
                marker.on('dragend', () => { request++; locate.disabled = false; const p = marker.getLatLng(); select(p.lat, p.lng); status.textContent = 'ย้ายหมุดแล้ว กดบันทึกเพื่อยืนยัน'; });
                circle = L.circle(point, {radius: meters() ?? 100, color: '#7c3aed', fillOpacity: 0.18, interactive: false}).addTo(map);
            } else { marker.setLatLng(point); circle.setLatLng(point); }
            if (meters() !== null) circle.setRadius(meters());
            if (fit) map.fitBounds(circle.getBounds(), {padding: [30, 30], maxZoom: 18});
        }
        describe();
    };
    // Register before the page's saving indicator; a missing point must never lock the form.
    form.addEventListener('submit', event => {
        if (selected) return;
        event.preventDefault(); event.stopImmediatePropagation();
        status.textContent = 'กรุณาเลือกพิกัดจากแผนที่หรือใช้ตำแหน่งปัจจุบันก่อนบันทึก';
        locate.focus();
    }, true);
    if (window.L) {
        map = L.map(canvas, {scrollWheelZoom: false}).setView(selected ? [Number(lat.value), Number(lng.value)] : [13.7563, 100.5018], selected ? 17 : 6);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'}).addTo(map)
            .on('tileerror', () => { status.textContent = 'โหลดพื้นแผนที่ไม่ได้ กรุณาตรวจอินเทอร์เน็ต หรือใช้ตำแหน่งปัจจุบัน'; });
        map.on('click', e => { request++; locate.disabled = false; select(e.latlng.lat, e.latlng.lng); status.textContent = 'เลือกพิกัดแล้ว กดบันทึกเพื่อยืนยัน'; });
        center.addEventListener('click', () => { request++; locate.disabled = false; const p = map.getCenter(); select(p.lat, p.lng); status.textContent = 'เลือกจุดกึ่งกลางแผนที่แล้ว'; });
        if (selected) select(Number(lat.value), Number(lng.value), true);
    } else {
        canvas.textContent = 'เปิดแผนที่ไม่ได้ กรุณาโหลดหน้าใหม่ หรือใช้ตำแหน่งปัจจุบัน';
        center.disabled = true;
    }
    radius.addEventListener('input', () => {
        if (circle && meters() !== null) { circle.setRadius(meters()); map.fitBounds(circle.getBounds(), {padding: [30, 30], maxZoom: 18}); }
        describe();
    });
    locate.addEventListener('click', () => {
        if (!navigator.geolocation) { status.textContent = 'เบราว์เซอร์นี้ไม่รองรับตำแหน่งปัจจุบัน กรุณาเลือกจากแผนที่'; return; }
        const ticket = ++request;
        locate.disabled = true; status.textContent = 'กำลังค้นหาตำแหน่ง กรุณาอนุญาตการเข้าถึงตำแหน่ง';
        navigator.geolocation.getCurrentPosition(position => {
            if (ticket !== request) return;
            locate.disabled = false;
            select(position.coords.latitude, position.coords.longitude, true);
            status.textContent = `พบตำแหน่งแล้ว (ความคลาดเคลื่อนประมาณ ${Math.round(position.coords.accuracy)} เมตร) โปรดตรวจหมุดและรัศมีก่อนบันทึก`;
        }, error => {
            if (ticket !== request) return;
            locate.disabled = false;
            status.textContent = error.code === 1 ? 'ไม่ได้รับอนุญาตให้ใช้ตำแหน่ง กรุณาเปิดสิทธิ์ในเบราว์เซอร์หรือเลือกจากแผนที่' : 'หาตำแหน่งไม่สำเร็จ กรุณาลองใหม่หรือเลือกจากแผนที่';
        }, {enableHighAccuracy: true, timeout: 15000, maximumAge: 0});
    });
    describe();
})();
