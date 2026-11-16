(function ($, config, transportMap) {
  var FIELDS = ['latitude', 'longitude'];
  var COORDINATE = /^-?\d+([.,]\d+)?$/;
  var TYPING_DELAY = 500;
  var SUBMIT_BINDING_DELAY = 1000;
  var REDIRECT_DELAY = 500;
  var SAVE_LABEL = 'Gravar';
  var DETAIL_URL = '/intranet/transporte_ponto_det.php?cod_ponto=';
  var SAME_POSITION = 0.0000005;

  var state = {
    map: null,
    marker: null,
    ready: false,
    loaded: false,
    hadCoordinate: false,
    typingTimer: null
  };

  function input(field) {
    return $('#' + field);
  }

  function parseCoordinate(value, limit) {
    var text = $.trim(value || '');

    if (text === '') {
      return { empty: true };
    }

    if (!COORDINATE.test(text)) {
      return { error: 'Format' };
    }

    var number = Number(parseFloat(text.replace(',', '.')).toFixed(config.decimals));

    if (Math.abs(number) > limit) {
      return { error: 'Range' };
    }

    return { value: number };
  }

  function readCoordinates() {
    var parsed = {};
    var result = { empty: false, position: null, errors: {} };

    FIELDS.forEach(function (field) {
      parsed[field] = parseCoordinate(input(field).val(), config.limits[field]);
    });

    if (parsed.latitude.empty && parsed.longitude.empty) {
      result.empty = true;

      return result;
    }

    FIELDS.forEach(function (field) {
      if (parsed[field].empty) {
        result.errors[field] = config.messages.together;
      } else if (parsed[field].error) {
        result.errors[field] = config.messages[field + parsed[field].error];
      }
    });

    if (!result.errors.latitude && !result.errors.longitude) {
      result.position = [parsed.latitude.value, parsed.longitude.value];
    }

    return result;
  }

  function setError(field, message) {
    var id = field + '_error';
    var node = $('#' + id);

    if (!message) {
      node.remove();
      input(field).removeAttr('aria-invalid').removeAttr('aria-describedby');

      return;
    }

    if (!node.length) {
      node = $('<span class="form-error transport-coordinate-error"></span>').attr('id', id);
      input(field).after(node);
    }

    node.text(message);
    input(field).attr('aria-invalid', 'true').attr('aria-describedby', id);
  }

  function setNotice(kind) {
    $('#stop_location_missing').prop('hidden', kind !== 'missing');
    $('#stop_location_removal').prop('hidden', kind !== 'removal');
  }

  function announce(latitude, longitude) {
    $('#stop_location_status').text(config.messages.marked.replace('{latitude}', latitude).replace('{longitude}', longitude));
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
      title: config.messages.markerTitle
    }).addTo(state.map);

    state.marker.on('dragend', function () {
      fillFromMap(state.marker.getLatLng());
    });
  }

  function removeMarker() {
    if (state.map && state.marker) {
      state.map.removeLayer(state.marker);
      state.marker = null;
    }
  }

  function isMarkerAt(position) {
    if (!state.marker) {
      return false;
    }

    var current = state.marker.getLatLng();

    return Math.abs(current.lat - position[0]) < SAME_POSITION && Math.abs(current.lng - position[1]) < SAME_POSITION;
  }

  function centerOn(position) {
    var zoom = state.map.getZoom();

    state.map.setView(position, zoom === undefined || zoom < config.zoom.boundsMax ? config.zoom.street : zoom);
  }

  function fillFromMap(latlng) {
    var wrapped = latlng.wrap();
    var latitude = wrapped.lat.toFixed(config.decimals);
    var longitude = wrapped.lng.toFixed(config.decimals);

    input('latitude').val(latitude);
    input('longitude').val(longitude);
    placeMarker(wrapped);

    FIELDS.forEach(function (field) {
      setError(field, null);
    });

    setNotice(null);
    announce(latitude, longitude);
  }

  function applyTyped(leftField) {
    window.clearTimeout(state.typingTimer);

    var coordinates = readCoordinates();

    FIELDS.forEach(function (field) {
      if (!coordinates.errors[field]) {
        setError(field, null);
      } else if (field === leftField) {
        setError(field, coordinates.errors[field]);
      }
    });

    if (coordinates.empty) {
      removeMarker();
      setNotice(state.hadCoordinate ? 'removal' : 'missing');

      return;
    }

    if (coordinates.position && state.map && !isMarkerAt(coordinates.position)) {
      placeMarker(coordinates.position);
      centerOn(coordinates.position);
    }

    if (coordinates.position) {
      setNotice(null);
    }
  }

  function showLoadedLocation() {
    var coordinates = readCoordinates();

    state.hadCoordinate = coordinates.position !== null;

    if (!state.ready) {
      return;
    }

    if (state.map) {
      state.map.invalidateSize();
    }

    if (coordinates.position) {
      placeMarker(coordinates.position);

      if (state.map) {
        state.map.setView(coordinates.position, config.zoom.street);
      }

      setNotice(null);

      return;
    }

    transportMap.showDefaultView(state.map, config.view, config.zoom.boundsMax);
    setNotice('missing');
  }

  function submitForm() {
    var coordinates = readCoordinates();
    var firstInvalid = null;

    FIELDS.forEach(function (field) {
      setError(field, coordinates.errors[field] || null);

      if (coordinates.errors[field] && firstInvalid === null) {
        firstInvalid = field;
      }
    });

    if (firstInvalid !== null) {
      $submitButton.removeAttr('disabled').val(SAVE_LABEL);
      input(firstInvalid).focus();

      return;
    }

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
        fillFromMap(event.latlng);
      });
    }

    state.ready = true;

    if (state.loaded || resource.isNew()) {
      showLoadedLocation();
    }

    FIELDS.forEach(function (field) {
      input(field).on('input', function () {
        window.clearTimeout(state.typingTimer);
        state.typingTimer = window.setTimeout(function () {
          applyTyped(null);
        }, TYPING_DELAY);
      }).on('blur', function () {
        applyTyped(field);
      });
    });

    window.setTimeout(function () {
      $submitButton.removeAttr('onclick');
      $submitButton.unbind('click');
      $submitButton.click(submitForm);
    }, SUBMIT_BINDING_DELAY);
  });
})(window.$j || window.jQuery, window.stopLocationMap, window.transportMap);
