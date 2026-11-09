'use strict';
document.querySelectorAll('.geo-picker').forEach(box => {
  const lat = box.querySelector('[name="latitude"]');
  const lng = box.querySelector('[name="longitude"]');
  const url = box.querySelector('[name="google_maps_url"]');
  const message = box.querySelector('[data-geo-status]');
  let map, marker;
  const setPoint = (a, b) => {
    if (!Number.isFinite(a) || !Number.isFinite(b) || Math.abs(a) > 90 || Math.abs(b) > 180) return;
    lat.value = a.toFixed(7); lng.value = b.toFixed(7);
    url.value = 'https://www.google.com/maps/search/?api=1&query=' + lat.value + ',' + lng.value;
    if (map) { if (marker) marker.remove(); marker = L.circleMarker([a,b], {radius:9}).addTo(map); map.panTo([a,b]); }
    message.textContent = 'Координатите се избрани. Зачувајте го обрасецот за да се евидентираат.';
  };
  [lat,lng].forEach(input => input.addEventListener('change', () => {
    if (lat.value.trim() !== '' && lng.value.trim() !== '') setPoint(Number(lat.value), Number(lng.value));
  }));
  box.querySelector('[data-geo-locate]').addEventListener('click', () => {
    if (!navigator.geolocation) { message.textContent = 'Уредот не поддржува локација.'; return; }
    message.textContent = 'Се бара локацијата…';
    navigator.geolocation.getCurrentPosition(p => setPoint(p.coords.latitude,p.coords.longitude), () => {
      message.textContent = 'Локацијата не е добиена. Дозволете пристап до локацијата или изберете точка на мапата.';
    }, {enableHighAccuracy:true,timeout:15000,maximumAge:60000});
  });
  box.querySelector('[data-geo-map]').addEventListener('click', () => {
    const el=box.querySelector('.geo-map');el.hidden=false;
    if (!window.L) { message.textContent='Мапата не се вчита. Можете да ги внесете координатите.';return; }
    if (!map) {
      map=L.map(el).setView([42.6629,21.1655],12);
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19,attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'}).addTo(map);
      map.on('click',e=>setPoint(e.latlng.lat,e.latlng.lng));
      if(lat.value!==''&&lng.value!=='')setPoint(Number(lat.value),Number(lng.value));
    }
    map.invalidateSize(); message.textContent='Кликнете на мапата за да изберете точка. Координатите се чуваат во формат Google Maps.';
  });
  box.querySelector('[data-geo-clear]').addEventListener('click',()=>{lat.value='';lng.value='';url.value='';if(marker)marker.remove();message.textContent='Локацијата е исчистена.';});
});
