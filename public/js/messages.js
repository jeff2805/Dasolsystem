/*
 * Visit Dasol — messaging.
 *
 * A FILE OF ITS OWN, loaded only where messaging is, so nothing here
 * can reach a page that worked before it existed. No framework, no
 * build step, same as the rest of the project's scripts.
 *
 * It does three small things and nothing clever:
 *   - scrolls a freshly opened thread to the newest message
 *   - says how many pictures are attached
 *   - keeps the header's unread badge roughly current
 */
(function () {
  'use strict';

  /*
   * A conversation is read from the bottom. Jumping the THREAD to its
   * end — not the page — so the reply box and the page heading stay
   * where they are. Scrolling the window instead would throw somebody
   * who arrived from the inbox straight past the header.
   */
  function showNewest() {
    document.querySelectorAll('[data-thread]').forEach(function (thread) {
      thread.scrollTop = thread.scrollHeight;
    });
  }

  /*
   * How many pictures are on the message, in words.
   *
   * A file input shows "3 files" in some browsers and nothing at all
   * in others, which leaves somebody unsure whether the picture they
   * picked actually attached.
   */
  function countAttachments() {
    var input = document.getElementById('message-attachments');
    var readout = document.querySelector('[data-attachment-count]');

    if (!input || !readout) {
      return;
    }

    input.addEventListener('change', function () {
      var n = input.files ? input.files.length : 0;

      readout.textContent = n === 0
        ? ''
        : (n === 1 ? '1 picture attached' : n + ' pictures attached');
    });
  }

  /*
   * The header badge.
   *
   * POLLING, BECAUSE THERE IS NO BROADCASTER. Reverb is not installed
   * here and BROADCAST_CONNECTION is "log", so there is nothing to
   * subscribe to. This asks the server for one number every half
   * minute — the endpoint returns a count and nothing else, no names
   * and no previews, so a response that is fetched this often carries
   * no fragment of anybody's conversation.
   *
   * Thirty seconds is slow enough to be cheap and quick enough that a
   * reply does not sit unnoticed. When Reverb is installed, replace
   * the interval with a private-channel subscription and delete this.
   */
  function pollUnread() {
    var badge = document.querySelector('[data-unread-count]');

    if (!badge) {
      return;
    }

    var url = badge.dataset.unreadCount;

    if (!url || typeof window.fetch !== 'function') {
      return;
    }

    function paint(n) {
      badge.textContent = n > 99 ? '99+' : String(n);
      badge.hidden = n < 1;

      var label = badge.closest('[data-unread-label]');

      if (label) {
        label.setAttribute(
          'aria-label',
          n < 1 ? 'Messages' : 'Messages, ' + n + ' unread'
        );
      }
    }

    function ask() {
      // Nothing to report to while the tab is in the background, and
      // no reason to wake the server for it either.
      if (document.hidden) {
        return;
      }

      window.fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        return response.json();
      }).then(function (data) {
        paint(parseInt(data.unread, 10) || 0);
      }).catch(function () {
        /*
         * Left exactly as it was. A badge that blanks itself the
         * moment the network hiccups would read as "no messages",
         * which is a worse answer than a slightly stale number.
         */
      });
    }

    window.setInterval(ask, 30000);

    // And once on returning to the tab, so coming back is current.
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        ask();
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    showNewest();
    countAttachments();
    pollUnread();
  });
})();
