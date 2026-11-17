(function ($, config, transportMap) {
  var COORDINATE = /^-?\d+([.,]\d+)?$/;
  var SUBMIT_BINDING_DELAY = 1000;
  var REDIRECT_DELAY = 500;
  var SAVE_LABEL = 'Gravar';
  var DETAIL_URL = '/intranet/transporte_ponto_det.php?cod_ponto=';
  var POSTAL_CODE_URL = '/api/postal-code/';
  var SEARCH_TRIGGER_FIELDS = '#address, #number, #neighborhood';
  var ADDRESS_FIELDS = '#address, #number, #neighborhood, #city_city';
  var CITY_LIST_EVENTS = 'autocompleteresponse autocompletechange';
  var NOTICE = 'route-path-alert route-path-alert-notice';
  var NEUTRAL = 'route-path-alert route-path-alert-neutral';
  var NOTE = 'route-path-note';
  var UNAVAILABLE = { status: 'unavailable' };

  var messages = config.messages;
  var statuses = config.search.statuses;

  var state = {
    map: null,
    marker: null,
    ready: false,
    loaded: false,
    hadCoordinate: false,
    automatic: false,
    adjustable: false,
    searching: false,
    request: 0,
    lastQuery: null,
    noticeKey: null
  };

  function element(id) {
    return document.getElementById(id);
  }

  function input(field) {
    return $('#' + field);
  }

  function fieldText(selector) {
    return $.trim($(selector).val() || '');
  }

  function parseCoordinate(value, limit) {
    var text = $.trim(value || '');

    if (!COORDINATE.test(text)) {
      return null;
    }

    var number = Number(parseFloat(text.replace(',', '.')).toFixed(config.decimals));

    return Math.abs(number) > limit ? null : number;
  }

  function savedPosition() {
    var latitude = parseCoordinate(input('latitude').val(), config.limits.latitude);
    var longitude = parseCoordinate(input('longitude').val(), config.limits.longitude);

    return latitude === null || longitude === null ? null : [latitude, longitude];
  }

  function sentence(first, second) {
    return first + ' ' + second;
  }

  function appendTemplate(node, template, address) {
    template.split(messages.addressPlaceholder).forEach(function (part, index) {
      if (index > 0) {
        var strong = document.createElement('strong');

        strong.textContent = address || '';
        node.appendChild(strong);
      }

      if (part) {
        node.appendChild(document.createTextNode(part));
      }
    });
  }

  function setNotice(className, template, address) {
    var region = element('stop_location_notice');
    var key = template ? className + '|' + template + '|' + (address || '') : null;

    if (!region || key === state.noticeKey) {
      return;
    }

    state.noticeKey = key;

    while (region.firstChild) {
      region.removeChild(region.firstChild);
    }

    if (!template) {
      return;
    }

    var node = document.createElement(className === NOTE ? 'p' : 'div');

    node.className = className;
    appendTemplate(node, template, address);
    region.appendChild(node);
  }

  function setStatus(text) {
    $('#stop_location_status').text(text);
  }

  function announce(latitude, longitude) {
    setStatus(messages.marked.replace('{latitude}', latitude).replace('{longitude}', longitude));
  }

  function markerIcon() {
    return L.divIcon({
      className: 'transport-marker stop-location-marker',
      html: '',
      iconSize: [22, 22],
      iconAnchor: [11, 11]
    });
  }

  function placeMarker(position) {
    if (!state.map) {
      return;
    }

    if (state.marker) {
      state.marker.setLatLng(position);

      return;
    }

    state.marker = L.marker(position, {
      icon: markerIcon(),
      draggable: true,
      keyboard: true,
      title: messages.markerTitle
    }).addTo(state.map);

    state.marker.on('dragend', function () {
      adjustByHand(state.marker.getLatLng());
    });
  }

  function clearLocation() {
    if (state.map && state.marker) {
      state.map.removeLayer(state.marker);
    }

    state.marker = null;
    input('latitude').val('');
    input('longitude').val('');
    setStatus('');
  }

  function fillCoordinates(latitude, longitude) {
    var latitudeText = Number(latitude).toFixed(config.decimals);
    var longitudeText = Number(longitude).toFixed(config.decimals);

    input('latitude').val(latitudeText);
    input('longitude').val(longitudeText);
    placeMarker([Number(latitudeText), Number(longitudeText)]);

    return [latitudeText, longitudeText];
  }

  function cityId() {
    return fieldText('#city_city') === '' ? '' : fieldText('#city_id');
  }

  function hasAddress() {
    var hasPlace = fieldText('#address') !== '' || fieldText('#neighborhood') !== '';

    return hasPlace && cityId() !== '';
  }

  function addressQuery() {
    return {
      address: fieldText('#address'),
      number: fieldText('#number'),
      neighborhood: fieldText('#neighborhood'),
      city_id: cityId()
    };
  }

  function locateButton() {
    return element('stop_location_locate');
  }

  function refreshActions() {
    var locate = locateButton();
    var hint = element('stop_location_locate_hint');
    var remove = element('stop_location_remove');
    var showLocate = locate !== null && !state.automatic;

    if (locate) {
      locate.hidden = !showLocate;
      hint.hidden = !showLocate;

      if (!transportMap.isButtonBusy(locate)) {
        locate.disabled = !hasAddress();
        hint.textContent = hasAddress() ? messages.locateHint : messages.locateIncompleteHint;
      }
    }

    remove.hidden = state.marker === null;
    element('stop_location_actions').hidden = !showLocate && remove.hidden;
  }

  function discardSearch() {
    var wasSearching = state.searching;

    state.request += 1;
    state.searching = false;

    return wasSearching;
  }

  function showFoundPosition(result, byButton) {
    var template = result.status === statuses.address ? messages.addressFound : messages.streetFound;

    setNotice(NOTICE, byButton ? sentence(template, messages.previousKept) : template, result.label);

    var coordinates = fillCoordinates(result.latitude, result.longitude);

    if (state.map) {
      state.map.setView([Number(coordinates[0]), Number(coordinates[1])], config.zoom.street);
    }

    announce(coordinates[0], coordinates[1]);
  }

  function showRegion(result) {
    if (!state.map) {
      return;
    }

    if (result.bounds) {
      state.map.fitBounds(result.bounds, { padding: transportMap.viewPadding });

      return;
    }

    var zoom = result.status === statuses.neighbourhood ? config.zoom.neighbourhood : config.zoom.city;

    state.map.setView([Number(result.latitude), Number(result.longitude)], zoom);
  }

  function showResult(result, byButton) {
    var isRegion = result.status === statuses.neighbourhood || result.status === statuses.city;

    state.adjustable = byButton || isRegion || result.status === statuses.address || result.status === statuses.street;

    if (result.status === statuses.address || result.status === statuses.street) {
      showFoundPosition(result, byButton);
    } else if (isRegion) {
      var regionTemplate = result.status === statuses.neighbourhood ? messages.neighbourhoodFound : messages.cityFound;

      setNotice(NOTICE, sentence(regionTemplate, byButton ? messages.markerKept : messages.regionHint), result.label);

      if (!byButton) {
        clearLocation();
        showRegion(result);
      }
    } else if (result.status === statuses.notFound) {
      setNotice(NOTICE, sentence(messages.notFound, byButton ? messages.markerKept : messages.notFoundHint));
    } else {
      setNotice(NEUTRAL, sentence(messages.unavailable, byButton ? messages.markerKept : messages.unavailableHint));
    }

    refreshActions();
  }

  function search(byButton) {
    var query = addressQuery();
    var queryKey = JSON.stringify(query);

    if (!byButton && (!config.search.enabled || !state.automatic || !hasAddress() || queryKey === state.lastQuery)) {
      return;
    }

    var locate = locateButton();
    var request = state.request + 1;

    state.request = request;
    state.lastQuery = queryKey;
    state.searching = true;
    setNotice(NOTE, messages.searching);

    if (byButton) {
      transportMap.setButtonBusy(locate, true, messages.locating);
    }

    $.ajax({
      url: config.search.endpoint,
      dataType: 'json',
      data: $.extend({ oper: 'get', resource: config.search.resource }, query)
    }).done(function (response) {
      if (request === state.request) {
        showResult(response && !response.any_error_msg && response.status ? response : UNAVAILABLE, byButton);
      }
    }).fail(function () {
      if (request === state.request) {
        showResult(UNAVAILABLE, byButton);
      }
    }).always(function () {
      if (request === state.request) {
        state.searching = false;
      }

      if (byButton) {
        transportMap.setButtonBusy(locate, false, messages.locate);
        refreshActions();
      }
    });
  }

  function adjustByHand(latlng) {
    var wasSearching = discardSearch();
    var wrapped = latlng.wrap();
    var showAdjusted = state.adjustable || wasSearching;

    state.automatic = false;
    state.adjustable = showAdjusted;

    var coordinates = fillCoordinates(wrapped.lat, wrapped.lng);

    if (showAdjusted) {
      setNotice(NEUTRAL, messages.adjusted);
    } else {
      setNotice(null);
    }

    announce(coordinates[0], coordinates[1]);
    refreshActions();
  }

  function removeLocation() {
    discardSearch();
    state.automatic = false;
    state.adjustable = false;
    clearLocation();

    if (state.hadCoordinate) {
      setNotice(NOTICE, messages.removal);
    } else {
      setNotice(NEUTRAL, messages.missing);
    }

    refreshActions();

    var locate = locateButton();

    if (locate && !locate.hidden && !locate.disabled) {
      locate.focus();
    } else {
      element('stop_location_map').focus();
    }
  }

  function locateByButton() {
    var locate = locateButton();

    if (transportMap.isButtonBusy(locate)) {
      return;
    }

    if (!hasAddress()) {
      refreshActions();

      return;
    }

    search(true);
  }

  function showLoadedLocation() {
    var position = savedPosition();

    state.hadCoordinate = position !== null;
    state.automatic = !state.hadCoordinate;

    if (!state.ready) {
      return;
    }

    if (state.map) {
      state.map.invalidateSize();
    }

    if (position) {
      placeMarker(position);

      if (state.map) {
        state.map.setView(position, config.zoom.street);
      }

      setNotice(null);
    } else {
      transportMap.showDefaultView(state.map, config.view, config.zoom.boundsMax);
      setNotice(NEUTRAL, messages.missing);
    }

    refreshActions();
  }

  function submitForm() {
    if ($('#cep_').val()) {
      if (!validateEndereco()) {
        return;
      }
    }

    submitFormExterno();
  }

  function redirectToDetail(dataResponse) {
    if (!dataResponse.any_error_msg) {
      window.setTimeout(function () {
        document.location = DETAIL_URL + resource.id();
      }, REDIRECT_DELAY);
    } else {
      $submitButton.removeAttr('disabled').val(SAVE_LABEL);
    }
  }

  resourceOptions.handlePost = redirectToDetail;
  resourceOptions.handlePut = redirectToDetail;

  resourceOptions.handleGet = function (dataResponse) {
    handleMessages(dataResponse.msgs);

    $deleteButton.removeAttr('disabled').show();

    $('#id').val(dataResponse.id);
    $('#desc').val(dataResponse.desc);

    $('#postal_code').val(dataResponse.cep);

    $('#latitude').val(dataResponse.latitude);
    $('#longitude').val(dataResponse.longitude);

    if ($('#postal_code').val()) {
      $('#address').val(dataResponse.logradouro);
      $('#number').val(dataResponse.numero);
      $('#complement').val(dataResponse.complemento);
      $('#neighborhood').val(dataResponse.bairro);
      $('#city_id').val(dataResponse.idmun);
      $('#city_city').val(dataResponse.idmun + ' - ' + dataResponse.municipio + ' (' + dataResponse.sigla_uf + ')');
    }

    $('#postal_code').trigger('change');

    state.loaded = true;
    showLoadedLocation();
  };

  $(function () {
    var block = document.querySelector('.stop-location');

    transportMap.fitBlockToViewport(block);
    state.map = transportMap.create('stop_location_map');
    transportMap.keepSized(state.map, block);

    if (state.map) {
      transportMap.showDefaultView(state.map, config.view, config.zoom.boundsMax);
      state.map.on('click', function (event) {
        adjustByHand(event.latlng);
      });
    }

    state.ready = true;

    if (state.loaded || resource.isNew()) {
      showLoadedLocation();
    }

    $(SEARCH_TRIGGER_FIELDS).on('blur', function () {
      search(false);
    });

    $('#city_id').on('change', function () {
      refreshActions();
      search(false);
    });

    $(document).ajaxSuccess(function (event, xhr, settings) {
      if (settings && typeof settings.url === 'string' && settings.url.indexOf(POSTAL_CODE_URL) === 0) {
        refreshActions();
        search(false);
      }
    });

    $(ADDRESS_FIELDS).on('input change keyup', refreshActions);
    $('#city_city').on(CITY_LIST_EVENTS, refreshActions);
    $('#stop_location_locate').on('click', locateByButton);
    $('#stop_location_remove').on('click', removeLocation);

    window.setTimeout(function () {
      $submitButton.removeAttr('onclick');
      $submitButton.unbind('click');
      $submitButton.click(submitForm);
    }, SUBMIT_BINDING_DELAY);
  });
})(window.$j || window.jQuery, window.stopLocationMap, window.transportMap);
