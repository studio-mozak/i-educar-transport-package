(function (window, document) {
  var BRAZIL_CENTER = [-14.235, -51.925];
  var BRAZIL_ZOOM = 4;
  var CENTER_ZOOM = 13;
  var VIEWPORT_GAP = 16;
  var MIN_BLOCK_WIDTH = 200;
  var VIEW_PADDING = [24, 24];
  var LAYOUT_SCROLL_CONSUMER_CLASS = 'ps__child--consume';

  function unscrolledLeft(node) {
    var left = node.getBoundingClientRect().left;

    for (var parent = node.parentElement; parent; parent = parent.parentElement) {
      left += parent.scrollLeft || 0;
    }

    return left;
  }

  function clipsHorizontally(node) {
    return window.getComputedStyle(node).overflowX !== 'visible';
  }

  function visibleRight(node) {
    var right = document.documentElement.clientWidth;

    for (var parent = node.parentElement; parent && parent !== document.documentElement && parent !== document.body; parent = parent.parentElement) {
      if (clipsHorizontally(parent)) {
        right = Math.min(right, unscrolledLeft(parent) + parent.clientWidth);
      }
    }

    return right;
  }

  function fitBlockToViewport(block) {
    if (!block) {
      return;
    }

    block.style.maxWidth = '';

    var left = unscrolledLeft(block);
    var available = Math.max(visibleRight(block) - left - VIEWPORT_GAP, MIN_BLOCK_WIDTH);

    if (block.getBoundingClientRect().width > available) {
      block.style.maxWidth = available + 'px';
    }
  }

  function keepTouchGesturesFromScrollingLayout(container) {
    function releaseWhenNoFingerIsLeft(event) {
      if (event.touches.length === 0) {
        container.classList.remove(LAYOUT_SCROLL_CONSUMER_CLASS);
      }
    }

    container.addEventListener('touchstart', function () {
      container.classList.add(LAYOUT_SCROLL_CONSUMER_CLASS);
    }, { passive: true });

    document.addEventListener('touchend', releaseWhenNoFingerIsLeft, { passive: true });
    document.addEventListener('touchcancel', releaseWhenNoFingerIsLeft, { passive: true });
  }

  function create(elementId) {
    var container = document.getElementById(elementId);

    if (typeof window.L === 'undefined' || !container) {
      return null;
    }

    keepTouchGesturesFromScrollingLayout(container);

    var instance = window.L.map(elementId, { scrollWheelZoom: false });

    instance.attributionControl.setPrefix(false);

    window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(instance);

    return instance;
  }

  function keepSized(map, block) {
    function onResize() {
      fitBlockToViewport(block);

      if (map) {
        map.invalidateSize();
      }
    }

    window.addEventListener('load', onResize);
    window.addEventListener('resize', onResize);
  }

  function showDefaultView(map, view, boundsMaxZoom) {
    if (!map) {
      return;
    }

    if (view && view.bounds) {
      map.fitBounds(view.bounds, { padding: VIEW_PADDING, maxZoom: boundsMaxZoom });
    } else if (view && view.center) {
      map.setView(view.center, CENTER_ZOOM);
    } else {
      map.setView(BRAZIL_CENTER, BRAZIL_ZOOM);
    }
  }

  window.transportMap = {
    create: create,
    fitBlockToViewport: fitBlockToViewport,
    keepSized: keepSized,
    showDefaultView: showDefaultView,
    viewPadding: VIEW_PADDING
  };
})(window, document);
