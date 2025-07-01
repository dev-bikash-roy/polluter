document.addEventListener("DOMContentLoaded", function() {
  // 1) Grab the location input
  const input = document.querySelector("input[name='location']");
  if (!input) return;

  // 2) Create & style the suggestions container
  const container = document.createElement("div");
  Object.assign(container.style, {
    position:   "absolute",
    top:        "100%",
    left:       "0",
    right:      "0",
    background: "#fff",
    border:     "1px solid #ccc",
    maxHeight:  "200px",
    overflowY:  "auto",
    zIndex:     1000,
    display:    "none",
  });
  input.parentNode.style.position = "relative";
  input.parentNode.appendChild(container);

  // 3) Helper functions
  function hide() {
    container.style.display = "none";
    container.innerHTML = "";
  }
  function show(items) {
    container.innerHTML = "";
    items.forEach(item => {
      const div = document.createElement("div");
      div.textContent = item.label;
      Object.assign(div.style, {
        padding: "8px",
        cursor:  "pointer",
      });
      div.addEventListener("mouseenter", () => div.style.background = "#f0f0f0");
      div.addEventListener("mouseleave", () => div.style.background = "");
      div.addEventListener("click", () => {
        input.value       = item.label;
        input.dataset.lat = item.lat;
        input.dataset.lng = item.lng;
        // Set hidden fields
        document.getElementById('lat').value = item.lat;
        document.getElementById('lng').value = item.lng;
        hide();
      });
      container.appendChild(div);
    });
    container.style.display = items.length ? "block" : "none";
  }

  // 4) Listen for typing
  input.addEventListener("input", function() {
    // Clear previous coords
    delete input.dataset.lat;
    delete input.dataset.lng;
    document.getElementById('lat').value = '';
    document.getElementById('lng').value = '';

    const q = input.value.trim();
    if (q.length < 1) {
      hide();
      return;
    }

    // Use REST API for city autocomplete
    const apiUrl = `${window.location.origin}/wp-json/cra/v1/cities?q=${encodeURIComponent(q)}`;

    fetch(apiUrl, {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
    })
    .then(response => {
      if (!response.ok) throw new Error('Network response was not ok');
      return response.json();
    })
    .then(data => {
      // Data is an array of city objects
      const list = Array.isArray(data) ? data : (data.data || []);
      const suggestions = list.map(c => ({
        label: `${c.name}, ${c.country}`,
        lat:   c.lat,
        lng:   c.lng
      }));
      show(suggestions);
    })
    .catch(err => {
      console.error("City lookup error:", err);
      hide();
    });
  });

  // 5) Hide on outside click
  document.addEventListener("click", function(e) {
    if (!container.contains(e.target) && e.target !== input) {
      hide();
    }
  });

  // 6) Ensure hidden fields exist
  let latFld = document.getElementById('lat');
  let lngFld = document.getElementById('lng');
  if (!latFld) {
    latFld = document.createElement("input");
    latFld.type = "hidden";
    latFld.name = "lat";
    latFld.id = "lat";
    input.form.appendChild(latFld);
  }
  if (!lngFld) {
    lngFld = document.createElement("input");
    lngFld.type = "hidden";
    lngFld.name = "lng";
    lngFld.id = "lng";
    input.form.appendChild(lngFld);
  }

  // 7) On form submit, require lat/lng
  const form = input.closest("form");
  if (form) {
    form.addEventListener("submit", function(e) {
      if (!input.dataset.lat || !input.dataset.lng) {
        e.preventDefault();
        alert("Please select a city from the dropdown before searching.");
        return false;
      }
      latFld.value = input.dataset.lat;
      lngFld.value = input.dataset.lng;
    });
  }

  // 8) Load More Facilities AJAX Pagination
  jQuery(document).on('click', '#load-more-facilities', function() {
    var button = $(this);
    // Always set nextPage to 2 for 'Load More' (first page is 1, second is all remaining)
    var nextPage = 2;
    var perPage = 15;
    var total = parseInt(button.data('total-facilities'));

    // Get search params from global JS var or hidden fields
    var lat = (typeof facilitySearchData !== 'undefined') ? facilitySearchData.searchLat : $('#lat').val();
    var lng = (typeof facilitySearchData !== 'undefined') ? facilitySearchData.searchLng : $('#lng').val();
    var radius = (typeof facilitySearchData !== 'undefined') ? facilitySearchData.searchRadius : 30;
    var nonce = (typeof facilitySearchData !== 'undefined') ? facilitySearchData.nonce : '';

    button.prop('disabled', true).text('Loading...');

    $.post(facilitySearchData.ajaxurl, {
      action: 'load_facilities_ajax',
      nonce: nonce,
      paged: nextPage,
      lat: lat,
      lng: lng,
      radius: radius
    }, function(response) {
      if (response.success) {
        // Append new HTML to the results list
        $('#facility-results-list').append(response.data.html);
        // Remove the button after loading all
        button.remove();
      } else {
        button.text('Error. Try again.').prop('disabled', false);
        if (response.data) {
          console.log('AJAX Error Data:', response.data);
        }
      }
    });
  });
});
