(function () {
  'use strict';

  function applyCanceledState() {
    var settings = window.drupalSettings || {};
    var isCanceledFromSettings = !!(settings.appointmentNotifications && settings.appointmentNotifications.isCanceled);
    var statusItem = document.querySelector('.node--type-appointment .field--name-field-appointment-status .field__item');
    if (!statusItem && !isCanceledFromSettings) {
      return;
    }

    var text = statusItem ? (statusItem.textContent || '').trim().toLowerCase() : '';
    var isCanceledText = (text === 'canceled' || text === 'cancelled');
    if (!isCanceledFromSettings && !isCanceledText) {
      return;
    }

    if (statusItem) {
      statusItem.classList.add('appointment-status-canceled');
    }
    var node = document.querySelector('.node--type-appointment');
    if (!node) {
      return;
    }

    node.classList.add('appointment-is-canceled');
    if (!node.querySelector('.appointment-canceled-notice')) {
      var notice = document.createElement('div');
      notice.className = 'appointment-canceled-notice alert alert-danger';
      notice.innerHTML = '<strong>Canceled Appointment</strong><br>This appointment has been canceled.';
      node.insertBefore(notice, node.firstChild);
    }
  }

  function injectCancelAction() {
    var settings = window.drupalSettings || {};
    var action = settings.appointmentNotifications && settings.appointmentNotifications.cancelAction;
    if (!action || !action.url) {
      return;
    }

    if (document.querySelector('.appointment-cancel-action a[href="' + action.url + '"]')) {
      return;
    }

    var container = document.querySelector('main .node--type-appointment .node__content')
      || document.querySelector('main .node')
      || document.querySelector('main');
    if (!container) {
      return;
    }

    var wrapper = document.createElement('div');
    wrapper.className = 'appointment-cancel-action';
    wrapper.style.margin = '0 0 1rem 0';

    var link = document.createElement('a');
    link.href = action.url;
    link.className = 'btn btn-danger text-white';
    link.textContent = action.label || 'Cancel this appointment';

    wrapper.appendChild(link);
    container.insertBefore(wrapper, container.firstChild);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      applyCanceledState();
      injectCancelAction();
    });
  }
  else {
    applyCanceledState();
    injectCancelAction();
  }
})();
