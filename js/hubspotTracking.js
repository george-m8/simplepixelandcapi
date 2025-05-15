function sendHubspotLeadEvent() {
  // 1) Trigger the client-side Pixel 'Lead' event
  if (typeof fbq === 'function') {
    fbq('track', 'Lead');
  }

  // 2) Fire an AJAX request to WordPress to do a server-side CAPI 'Lead' event
  fetch(simplePixelData.ajaxUrl + '?action=send_lead_capi_event', {
    method: 'POST'
  })
    .then(res => res.json())
    .then(data => {
      console.log('Lead CAPI response:', data);
    })
    .catch(err => console.error('CAPI Lead event error:', err));
}

// Expose the function globally so external code (like HubSpot forms) can call it
window.sendHubspotLeadEvent = sendHubspotLeadEvent;