/*
 * YouTube Captions Auditor (YTCA)
 * JavaScript
 *
 */

// Wait for new channel <tr> to be added to report
// Then, check for data-status attribute
// If present, copy that to div#status and remove the attribute from <tr>
// Original function source:
// http://ryanmorr.com/using-mutation-observers-to-watch-for-element-availability/

// Initialize status
var statusDiv = document.getElementById('status');

(function(win) {
  'use strict';

  var listeners = [],
  doc = win.document,
  MutationObserver = win.MutationObserver || win.WebKitMutationObserver,
  observer;

  function ready(selector, fn) {
    // Store the selector and callback to be monitored
    listeners.push({
      selector: selector,
      fn: fn
    });
    if (!observer) {
      // Watch for changes in the document
      observer = new MutationObserver(check);
      observer.observe(doc.documentElement, {
        childList: true,
        subtree: true
      });
    }
    // Check if the element is currently in the DOM
    check();
  }

  function check() {
    // Check the DOM for elements matching a stored selector
    for (var i = 0, len = listeners.length, listener, elements; i < len; i++) {
      listener = listeners[i];
      // Query for elements matching the specified selector
      elements = doc.querySelectorAll(listener.selector);
      for (var j = 0, jLen = elements.length, element; j < jLen; j++) {
        element = elements[j];
        // Make sure the callback isn't invoked with the
        // same element more than once
        if (!element.ready) {
          element.ready = true;
          // Invoke the callback with the element
          listener.fn.call(element, element);
        }
      }
    }
  }

  // Expose `ready`
  win.ready = ready;

})(this);

ready('tr', function(element) {
  if (element.hasAttribute('data-status')) {
    var status = element.getAttribute('data-status');
    statusDiv.innerHTML = status;
    if (status === 'Analysis complete.') {
      // hide status after 2 seconds
      setTimeout(function() {
        statusDiv.style.display = 'none';
      },2000)
    }
    else {
      statusDiv.style.display = 'block';
    }
    element.removeAttribute('data-status');
  }
});

