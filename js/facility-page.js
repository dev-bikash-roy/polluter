// Ensure Leaflet is globally available
if (typeof L === 'undefined') {
  console.error("Leaflet not loaded. Please ensure leaflet.js is enqueued.");
}

jQuery(document).ready(function($) {
    // Initialize Swiper if gallery exists
    if (document.querySelector('.swiper-container')) {
        new Swiper('.swiper-container', {
            loop: true,
            pagination: {
                el: '.swiper-pagination',
                clickable: true
            },
            navigation: {
                nextEl: '.swiper-button-next',
                prevEl: '.swiper-button-prev'
            }
        });
    }

    // Flag Modal Functionality
    document.addEventListener('DOMContentLoaded', function() {
        const flagModal = document.getElementById('flag-modal');
        const showFlagModalBtn = document.getElementById('show-flag-modal');
        const closeModalBtns = document.querySelectorAll('.close-modal');
        const flagForm = document.getElementById('flag-facility-form');

        // Show modal
        if (showFlagModalBtn) {
            showFlagModalBtn.addEventListener('click', function() {
                flagModal.style.display = 'block';
                document.body.style.overflow = 'hidden'; // Prevent scrolling
            });
        }

        // Close modal
        closeModalBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                flagModal.style.display = 'none';
                document.body.style.overflow = 'auto'; // Re-enable scrolling
            });
        });

        // Close on outside click
        flagModal.addEventListener('click', function(e) {
            if (e.target === flagModal) {
                flagModal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        });

        // Handle flag form submission
        if (flagForm) {
            flagForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const facilityId = this.dataset.facilityId;
                const reason = document.getElementById('flag-reason').value;
                
                const data = new FormData();
                data.append('action', 'flag_facility');
                data.append('facility_id', facilityId);
                data.append('reason', reason);
                data.append('nonce', factoryTracker.nonce);

                fetch(factoryTracker.ajaxurl, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show success message
                        alert('Thank you for your report. Our team will review this information.');
                        flagModal.style.display = 'none';
                        document.body.style.overflow = 'auto';
                        flagForm.reset();
                    } else {
                        alert('Error submitting report. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error submitting report. Please try again.');
                });
            });
        }

        // Handle facility updates (admin only)
        const updateForm = document.getElementById('update-facility-form');
        if (updateForm) {
            updateForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const facilityId = this.dataset.facilityId;
                const revenue = document.getElementById('facility-revenue').value;
                const employees = document.getElementById('facility-employees').value;
                
                const data = new FormData();
                data.append('action', 'update_facility');
                data.append('facility_id', facilityId);
                data.append('revenue', revenue);
                data.append('employees', employees);
                data.append('nonce', factoryTracker.nonce);

                fetch(factoryTracker.ajaxurl, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Facility information updated successfully.');
                    } else {
                        alert('Error updating facility information. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error updating facility information. Please try again.');
                });
            });
        }
    });
}); 

let facilityMap = null;

function initializeFacilityMap() {
  const mapDiv = document.getElementById("facility-map");
  if (!mapDiv) return;

  if (facilityMap !== null) {
    facilityMap.remove();
    facilityMap = null;
  }

  // Example static coordinates (can be improved to dynamic if data available)
  const lat = parseFloat(mapDiv.getAttribute("data-lat")) || 40.7128;
  const lng = parseFloat(mapDiv.getAttribute("data-lng")) || -74.006;

  facilityMap = L.map("facility-map").setView([lat, lng], 12);
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: '&copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a>'
  }).addTo(facilityMap);

  L.marker([lat, lng]).addTo(facilityMap).bindPopup("Facility location").openPopup();
}

document.addEventListener("DOMContentLoaded", function () {
  initializeFacilityMap();
});


document.addEventListener("DOMContentLoaded", function () {
  const aqiDiv = document.getElementById("aqi-status");
  if (!aqiDiv) return;

  const token = "29007ffc767e4905f5fcb4c8fd4d62e79a1a7174";

  fetch("https://api.waqi.info/feed/here/?token=" + token)
    .then((res) => res.json())
    .then((data) => {
      if (data.status !== "ok") {
        aqiDiv.innerHTML = "Unable to fetch air quality data.";
        return;
      }

      const d = data.data;
      const pollutants = d.iaqi || {};
      const details = [];

      details.push(`<strong>AQI:</strong> ${d.aqi}`);
      if (pollutants.pm25) details.push(`<strong>PM2.5:</strong> ${pollutants.pm25.v}`);
      if (pollutants.pm10) details.push(`<strong>PM10:</strong> ${pollutants.pm10.v}`);
      if (pollutants.o3) details.push(`<strong>Ozone (O₃):</strong> ${pollutants.o3.v}`);
      if (pollutants.no2) details.push(`<strong>Nitrogen Dioxide (NO₂):</strong> ${pollutants.no2.v}`);
      if (pollutants.so2) details.push(`<strong>Sulfur Dioxide (SO₂):</strong> ${pollutants.so2.v}`);
      if (pollutants.co) details.push(`<strong>Carbon Monoxide (CO):</strong> ${pollutants.co.v}`);
      if (pollutants.t) details.push(`<strong>Temperature:</strong> ${pollutants.t.v} °C`);
      if (pollutants.h) details.push(`<strong>Humidity:</strong> ${pollutants.h.v} %`);

      aqiDiv.innerHTML = "<ul style='list-style: disc; padding-left: 20px;'>" + details.map(d => `<li>${d}</li>`).join("") + "</ul>";
    })
    .catch(() => {
      aqiDiv.innerHTML = "Error retrieving AQI data.";
    });
});


document.addEventListener("DOMContentLoaded", function () {
  if (typeof L === 'undefined') {
    console.error("Leaflet not loaded. Please ensure leaflet.js is enqueued.");
    return;
  }

  const mapDiv = document.getElementById("facility-map");
  if (!mapDiv) return;

  // Get coordinates from data attributes
  const lat = parseFloat(mapDiv.dataset.lat);
  const lng = parseFloat(mapDiv.dataset.lng);
  const name = mapDiv.dataset.name || 'Facility location';

  if (isNaN(lat) || isNaN(lng)) {
    console.error("Invalid facility coordinates");
    return;
  }

  // Initialize map
  const map = L.map("facility-map").setView([lat, lng], 15);

  // Add OpenStreetMap tile layer
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: '&copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a>'
  }).addTo(map);

  // Add marker for the facility
  const marker = L.marker([lat, lng], {
    icon: L.divIcon({
      className: 'facility-marker',
      html: '<div class="marker-pin bg-blue-500"></div>',
      iconSize: [30, 30],
      iconAnchor: [15, 30]
    })
  })
  .addTo(map)
  .bindPopup(`
    <div class="p-2">
      <h3 class="font-bold text-lg mb-2">${name}</h3>
      <p class="text-sm">Facility location</p>
    </div>
  `)
  .openPopup();

  // Add a circle to show the facility's radius (if available)
  const radius = parseFloat(mapDiv.dataset.radius);
  if (!isNaN(radius)) {
    L.circle([lat, lng], {
      radius: radius * 1609.34, // Convert miles to meters
      color: '#3B82F6',
      fillColor: '#93C5FD',
      fillOpacity: 0.2,
      weight: 2
    }).addTo(map);
  }
});


document.addEventListener("DOMContentLoaded", function () {
  const aqiDiv = document.getElementById("aqi-status");
  if (!aqiDiv) return;

  const token = "29007ffc767e4905f5fcb4c8fd4d62e79a1a7174";

  fetch("https://api.waqi.info/feed/here/?token=" + token)
    .then((res) => res.json())
    .then((data) => {
      if (data.status !== "ok") {
        aqiDiv.innerHTML = "Unable to fetch air quality data.";
        return;
      }

      const d = data.data;
      const pollutants = d.iaqi || {};
      const details = [];

      details.push(`<strong>AQI:</strong> ${d.aqi}`);
      if (pollutants.pm25) details.push(`<strong>PM2.5:</strong> ${pollutants.pm25.v}`);
      if (pollutants.pm10) details.push(`<strong>PM10:</strong> ${pollutants.pm10.v}`);
      if (pollutants.o3) details.push(`<strong>Ozone (O₃):</strong> ${pollutants.o3.v}`);
      if (pollutants.no2) details.push(`<strong>Nitrogen Dioxide (NO₂):</strong> ${pollutants.no2.v}`);
      if (pollutants.so2) details.push(`<strong>Sulfur Dioxide (SO₂):</strong> ${pollutants.so2.v}`);
      if (pollutants.co) details.push(`<strong>Carbon Monoxide (CO):</strong> ${pollutants.co.v}`);
      if (pollutants.t) details.push(`<strong>Temperature:</strong> ${pollutants.t.v} °C`);
      if (pollutants.h) details.push(`<strong>Humidity:</strong> ${pollutants.h.v} %`);

      aqiDiv.innerHTML = "<ul style='list-style: disc; padding-left: 20px;'>" + details.map(d => `<li>${d}</li>`).join("") + "</ul>";
    })
    .catch(() => {
      aqiDiv.innerHTML = "Error retrieving AQI data.";
    });
});

