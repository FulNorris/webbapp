# Dead code report

## Död kod identifierad

- Ingen funktionskod raderas utan säkert anropsbevis i denna passering.

## Dubblerad kod som behålls temporärt

- `/api/orders` och `/api/deliveries` har dubblerade route handlers i `server/server.js`.
- Dessa behålls som kompatibilitetsyta men bör refactoras till delade handlers i nästa pass.

## Konsoliderad kod

- People-sökning konsolideras till typad search-funktion och fältspecifik frontend-autocomplete.

## CSS-risker

- `src/style.css` är monolitisk och innehåller flera layoutsektioner.
- Leaflet tile-skydd finns men förstärks med `.tracking-map-frame`.
