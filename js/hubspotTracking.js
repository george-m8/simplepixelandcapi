function sendHubspotLeadEvent() {
  // Build a dedupe ID
  const eventId = 'fb_' + Date.now() + '_' + Math.random().toString(36).substr(2,9);

  // Trigger the client-side Pixel 'Lead' with eventID
  if (typeof fbq === 'function') {
    fbq(
      'track',
      'Lead',
      {}, // custom_data (empty here)
      { eventID: eventId }
    );
  }

  // Fire WP-AJAX with the same event_id

  fetch(simplePixelData.ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action:   'send_lead_capi_event',
      event_id: eventId
    })
  })
  .then(res => res.json())
  .then(data => {
    if (simplePixelData.debug) {
      console.log('Lead CAPI response:', data);
    }
  })
  .catch(err => {
    if (simplePixelData.debug) {
      console.error('CAPI Lead event error:', err);
    }
  });
}

// Expose the function globally so external code (like HubSpot forms) can call it
window.sendHubspotLeadEvent = sendHubspotLeadEvent;