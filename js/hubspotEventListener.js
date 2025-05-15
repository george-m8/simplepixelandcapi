;(function(){
  var debug = window.simplePixelData && simplePixelData.debug;

  if ( debug ) {
    console.log('[SimplePixel] HubSpot event listener script loaded');
  }

  // onFormSubmitted
  window.addEventListener('message', function(event) {
    if (
      event.data &&
      event.data.type === 'hsFormCallback' &&
      event.data.eventName === 'onFormSubmitted'
    ) {
      debug && console.log('[SimplePixel] HubSpot form submitted');
      if ( typeof sendHubspotLeadEvent === 'function' ) {
        sendHubspotLeadEvent();
        debug && console.log('[SimplePixel] sendHubspotLeadEvent() called');
      }
    }
  });

  // meetingBookSucceeded
  window.addEventListener('message', function(event) {
    if (
      event.data &&
      event.data.meetingBookSucceeded
    ) {
      debug && console.log('[SimplePixel] HubSpot meeting booked');
      if ( typeof sendHubspotLeadEvent === 'function' ) {
        sendHubspotLeadEvent();
        debug && console.log('[SimplePixel] sendHubspotLeadEvent() called');
      }
    }
  });
})();