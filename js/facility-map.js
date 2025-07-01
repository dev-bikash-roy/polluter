document.addEventListener("DOMContentLoaded", () => {
  if (
    typeof cleanyact10MapData === "undefined" ||
    !Array.isArray(cleanyact10MapData.facilityData)
  ) {
    console.error("Map data missing or invalid");
    return;
  }

  // Red marker SVG as data URL
  const redMarkerSVG = `
    <svg xmlns='http://www.w3.org/2000/svg' width='32' height='41' viewBox='0 0 32 41'>
      <g>
        <ellipse cx='16' cy='16' rx='14' ry='14' fill='#e11d48'/>
        <path d='M16 41c-1.1 0-2-.9-2-2V28h4v11c0 1.1-.9 2-2 2z' fill='#e11d48'/>
        <ellipse cx='16' cy='16' rx='6' ry='6' fill='#fff'/>
      </g>
    </svg>
  `;
  const redMarkerUrl = "data:image/svg+xml;base64," + btoa(redMarkerSVG);

  // Simple slugify: remove accents, lowercase, replace spaces/non-alphanum with hyphens
  function slugify(text) {
    return text
      .toString()
      .normalize("NFD")                      // split accented chars
      .replace(/[\u0300-\u036f]/g, "")       // remove accents
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, "-")           // replace non-alphanumeric with hyphen
      .replace(/^-+|-+$/g, "");              // trim hyphens from ends
  }

  const facilityData = cleanyact10MapData.facilityData;
  const map = new maplibregl.Map({
    container: "facility-map",
    style: "https://tiles.basemaps.cartocdn.com/gl/voyager-gl-style/style.json",
    center: [-98.5795, 39.8283],
    zoom: 4,
  });

  map.on("load", () => {
    map.addControl(new maplibregl.NavigationControl());
    const bounds = new maplibregl.LngLatBounds();

    // Add this mapping for human-friendly type labels
    const typeLabels = {
      golf: "Golf Course",
      golf_course: "Golf Course",
      industrial: "Industrial Facility",
      airport: "Airport",
      // add more as needed
    };

    function guessTypeLabel(fac) {
      const name = (fac.name || "").toLowerCase();
      if (name.includes("golf")) return "Golf Course";
      if (name.includes("airport")) return "Airport";
      if (name.includes("industrial") || name.includes("factory") || name.includes("plant")) return "Industrial Facility";
      return "Facility";
    }

    facilityData.forEach(fac => {
      const lat = parseFloat(fac.lat),
            lng = parseFloat(fac.lng);

      if (isNaN(lat) || isNaN(lng)) return;

      // Build SEO-friendly URL from slug
      const facilityUrl = `/facilities/${slugify(fac.slug || fac.name)}/`;

      const popupHtml = `
        <div style="
          min-width:220px;
          max-width:300px;
          background:#fff;
          border-radius:12px;
          box-shadow:0 2px 8px rgba(0,0,0,0.08);
          overflow:hidden;
          font-family:sans-serif;
        ">
          ${fac.image ? `
            <img src="${fac.image}" alt="${fac.name}" style="
              width:100%;
              height:120px;
              object-fit:cover;
            ">
          ` : ""}
          <div style="padding:16px;">
            <h4 style="margin:0 0 8px;font-size:1.1em;color:#222;">${fac.name}</h4>
            <span style="
              display:inline-block;
              padding:2px 8px;
              font-size:0.85em;
              border-radius:12px;
              background:#fee2e2;
              color:#991b1b;
              margin-bottom:8px;
            ">${typeLabels[fac.type] || guessTypeLabel(fac)}</span>
            <p style="margin:0 0 12px;font-size:0.95em;color:#444;">${fac.address}</p>
            <a href="${facilityUrl}" target="_blank" rel="noopener" style="
              display:block;
              text-align:center;
              padding:10px 0;
              background:#2563eb;
              color:#fff;
              text-decoration:none;
              border-radius:6px;
              font-weight:600;
            ">View Full Details</a>
          </div>
        </div>
      `;

      // Use custom red marker
      new maplibregl.Marker({
        element: (() => {
          const el = document.createElement('img');
          el.src = redMarkerUrl;
          el.style.width = '32px';
          el.style.height = '41px';
          el.style.transform = 'translate(-16px, -41px)';
          return el;
        })()
      })
        .setLngLat([lng, lat])
        .setPopup(new maplibregl.Popup({ offset: 25 }).setHTML(popupHtml))
        .addTo(map);

      bounds.extend([lng, lat]);
    });

    if (!bounds.isEmpty()) {
      map.fitBounds(bounds, { padding: 50 });
    }
  });

  map.on("error", e => console.error("MapLibre GL Error:", e.error));
});
