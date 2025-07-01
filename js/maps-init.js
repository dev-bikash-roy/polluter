let map;
let autocomplete;
let markers = [];

// Main initialization function that will be called by Google Maps
function initializeMap() {
    initializeAutocomplete();
    initializeMapElement();
}

function initializeAutocomplete() {
    const input = document.getElementById('location-input');
    if (input) {
        autocomplete = new google.maps.places.Autocomplete(input);

        // Set options after creation to avoid the error
        autocomplete.setOptions({
            types: ['(cities)'],
            componentRestrictions: { country: 'us' }
        });

        autocomplete.addListener('place_changed', function() {
            const place = autocomplete.getPlace();
            if (place.geometry) {
                const lat = place.geometry.location.lat();
                const lng = place.geometry.location.lng();
                
                // Update hidden fields
                document.getElementById('lat').value = lat;
                document.getElementById('lng').value = lng;
                
                // If we have a map, update it
                if (map) {
                    map.setCenter({ lat, lng });
                    map.setZoom(12);
                }
            }
        });
    }
}

function initializeMapElement() {
    const mapElement = document.getElementById('map');
    if (mapElement) {
        // Get coordinates from data attributes
        const lat = parseFloat(mapElement.dataset.lat) || 0;
        const lng = parseFloat(mapElement.dataset.lng) || 0;
        
        // Initialize the map
        map = new google.maps.Map(mapElement, {
            center: { lat: lat, lng: lng },
            zoom: 12
        });

        // Add markers if they exist in the global scope
        if (window.facilityMarkers && window.facilityMarkers.length > 0) {
            const bounds = new google.maps.LatLngBounds();
            
            window.facilityMarkers.forEach(facility => {
                const position = {
                    lat: parseFloat(facility.lat),
                    lng: parseFloat(facility.lng)
                };
                
                const marker = new google.maps.Marker({
                    position,
                    map,
                    title: facility.name,
                    icon: {
                        url: facility.type === 'golf' 
                            ? 'https://maps.google.com/mapfiles/ms/icons/green-dot.png'
                            : 'https://maps.google.com/mapfiles/ms/icons/red-dot.png'
                    }
                });
                
                bounds.extend(position);
                markers.push(marker);
            });
            
            // Only fit bounds if we have markers
            if (markers.length > 0) {
                map.fitBounds(bounds);
                
                // If we only have one marker, zoom out a bit
                if (markers.length === 1) {
                    map.setZoom(14);
                }
            }
        }
    }
}

// Function to get current location
function getCurrentLocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                
                // Update hidden fields
                document.getElementById('lat').value = lat;
                document.getElementById('lng').value = lng;
                
                // Use Geocoder to get address
                const geocoder = new google.maps.Geocoder();
                geocoder.geocode(
                    { location: { lat: lat, lng: lng } },
                    function(results, status) {
                        if (status === 'OK' && results[0]) {
                            document.getElementById('location-input').value = results[0].formatted_address;
                        }
                    }
                );
            },
            function(error) {
                console.error('Error getting location:', error);
                alert('Unable to get your location. Please enter it manually.');
            }
        );
    } else {
        alert('Geolocation is not supported by your browser. Please enter your location manually.');
    }
}

// Make getCurrentLocation available globally
window.getCurrentLocation = getCurrentLocation;