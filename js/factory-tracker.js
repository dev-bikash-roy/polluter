
// Ensure Leaflet is globally available
if (typeof L === 'undefined') {
  console.error("Leaflet not loaded. Please ensure leaflet.js is enqueued.");
}

(function($){
    function loadGoogle(cb){
        if(window.google && google.maps) return cb();
        const s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key=AIzaSyBRDfa3Y0_54KPj_QSol4WkWPJmpa90S3c&libraries=places';
        s.async = true;
        s.defer = true;
        s.onload = cb;
        document.body.appendChild(s);
    }

    function initAutocomplete(){
        var input = document.getElementById('factory-location');
        if(!input) return;
        var autocomplete = new google.maps.places.Autocomplete(input, {
            componentRestrictions: {country:'us'},
            types: ['(cities)']
        });
        autocomplete.addListener('place_changed', function(){
            var place = autocomplete.getPlace();
            if(place.geometry){
                document.getElementById('factory-lat').value = place.geometry.location.lat();
                document.getElementById('factory-lng').value = place.geometry.location.lng();
            }
        });
    }

    function renderMap(){
        const center = window.factoryCenter || [0, 0];
        const map = new google.maps.Map(document.getElementById('ft-map'), {
            center: { lat: center[0], lng: center[1] },
            zoom: 9,
            styles: [
                {
                    featureType: "poi",
                    elementType: "labels",
                    stylers: [{ visibility: "off" }]
                }
            ]
        });

        const bounds = new google.maps.LatLngBounds();
        const infowindow = new google.maps.InfoWindow();
        
        if (!window.factoryData || window.factoryData.length === 0) {
            // Center on searched location if no results
            bounds.extend(new google.maps.LatLng(center[0], center[1]));
        } else {
            window.factoryData.forEach(f => {
                const position = { 
                    lat: parseFloat(f.lat), 
                    lng: parseFloat(f.lng) 
                };

                // Extend bounds
                bounds.extend(new google.maps.LatLng(position.lat, position.lng));

                const icon = {
                    path: google.maps.SymbolPath.CIRCLE,
                    fillColor: f.type === 'golf' ? '#34D399' : '#EF4444',
                    fillOpacity: 0.7,
                    strokeWeight: 1,
                    strokeColor: '#fff',
                    scale: 10
                };

                const marker = new google.maps.Marker({
                    map,
                    position,
                    icon,
                    title: f.name,
                    animation: google.maps.Animation.DROP
                });

                marker.addListener('click', () => {
                    let content;
                    if (f.type === 'golf') {
                        content = `
                            <div class="p-4 max-w-sm">
                                <h3 class="font-bold text-lg mb-2">${f.name}</h3>
                                <p class="mb-2">${f.address}</p>
                                <p class="mb-1"><strong>Distance:</strong> ${f.distance}</p>
                                <p class="mb-1"><strong>Water Usage:</strong> ${f.water_usage}</p>
                                <p><strong>Pesticide Use:</strong> ${f.pesticide_use}</p>
                            </div>
                        `;
                    } else {
                        content = `
                            <div class="p-4 max-w-sm">
                                <h3 class="font-bold text-lg mb-2">${f.name}</h3>
                                <p class="mb-2">${f.address}</p>
                                <p class="mb-1"><strong>Distance:</strong> ${f.distance}</p>
                                <p class="mb-1"><strong>Industry:</strong> ${f.industry}</p>
                                <p class="mb-1"><strong>Emissions:</strong> ${f.emissions}</p>
                                <p><strong>Violations:</strong> ${f.violations}</p>
                            </div>
                        `;
                    }
                    infowindow.setContent(content);
                    infowindow.open(map, marker);
                });
            });
        }

        // Fit map to bounds with padding
        map.fitBounds(bounds, 50);

        // Add legend
        const legend = document.createElement('div');
        legend.className = 'bg-white p-2 rounded shadow-lg';
        legend.style.margin = '10px';
        legend.innerHTML = `
            <div class="text-sm">
                <div class="flex items-center mb-1">
                    <span class="inline-block w-3 h-3 rounded-full bg-red-500 mr-2"></span>
                    <span>Polluting Facilities</span>
                </div>
                <div class="flex items-center">
                    <span class="inline-block w-3 h-3 rounded-full bg-green-500 mr-2"></span>
                    <span>Golf Courses</span>
                </div>
            </div>
        `;
        map.controls[google.maps.ControlPosition.TOP_RIGHT].push(legend);
    }

    $(document).ready(() => {
        if ($('#ft-map').length) {
            loadGoogle(renderMap);
        } else {
            loadGoogle(initAutocomplete);
        }
    });
})(jQuery);


document.addEventListener("DOMContentLoaded", function () {
  const mapDiv = document.getElementById("facility-results-map");
  if (!mapDiv) return;

  const params = new URLSearchParams(window.location.search);
  const lat = parseFloat(params.get("lat") || "0");
  const lng = parseFloat(params.get("lng") || "0");

  if (!lat || !lng) return;

  const map = L.map("facility-results-map").setView([lat, lng], 11);
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: '&copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a>'
  }).addTo(map);

  L.marker([lat, lng]).addTo(map).bindPopup("Search center").openPopup();
});


document.addEventListener("DOMContentLoaded", function () {
  function getUrlParams() {
    const params = new URLSearchParams(window.location.search);
    return {
      location: params.get("location") || "",
      lat: params.get("lat") || "",
      lng: params.get("lng") || "",
      radius: params.get("radius") || "",
    };
  }

  function loadFacilities(page = 1) {
    const params = getUrlParams();

    const data = new URLSearchParams();
    data.append("action", "load_facilities_ajax");
    data.append("paged", page);
    data.append("lat", params.lat);
    data.append("lng", params.lng);
    data.append("radius", params.radius);

    fetch(facility_ajax.ajax_url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: data.toString(),
    })
    .then((res) => res.text())
    .then((html) => {
      const wrapper = document.getElementById("facility-results-wrapper");
      if (wrapper) wrapper.innerHTML = html;
      attachPaginationEvents();
    });
  }

  function attachPaginationEvents() {
    document.querySelectorAll(".facility-page-link").forEach((el) => {
      el.addEventListener("click", function (e) {
        e.preventDefault();
        const page = parseInt(this.dataset.page);
        loadFacilities(page);
      });
    });
  }

  if (document.getElementById("facility-results-wrapper")) {
    loadFacilities(1);
  }
});
