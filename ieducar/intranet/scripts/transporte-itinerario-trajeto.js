(function ($, config, transportMap) {
  if (!config || !transportMap || !document.getElementById('route_path_map')) {
    return;
  }

  $(function () {
    var OUTBOUND = 'I';
    var RETURN = 'V';
    var STOP_CODE = /^\s*(\d+)\s*-/;
    var TABLE_FIELDS = 'input[id^="ref_cod_ponto_transporte_escolar"], select[id^="tipo"]';
    var STOPS_TABLE_ID = 'pontos';
    var VIEW_MAX_ZOOM = 14;
    var STOPS_MAX_ZOOM = 16;

    var state = {
      reference: config.reference,
      current: null,
      calculating: false,
      blocked: false
    };

    var block = document.querySelector('.route-path');

    transportMap.fitBlockToViewport(block);

    var map = transportMap.create('route_path_map');
    var layers = map ? L.layerGroup().addTo(map) : null;

    function element(id) {
      return document.getElementById(id);
    }

    function setVisible(id, visible) {
      var node = element(id);

      if (node) {
        node.hidden = !visible;
      }
    }

    function hasCoordinates(stop) {
      return stop.latitude !== null && stop.longitude !== null;
    }

    function directionClass(stop) {
      return 'route-path-' + (stop.direction === RETURN ? 'return' : 'outbound');
    }

    function markerIcon(stop) {
      return L.divIcon({
        className: '',
        html: '<div class="transport-marker route-path-marker ' + directionClass(stop) + '">' + stop.number + '</div>',
        iconSize: [24, 24],
        iconAnchor: [12, 12]
      });
    }

    function groupIcon(group) {
      var segments = group.map(function (stop) {
        return '<span class="route-path-marker-segment ' + directionClass(stop) + '">' + stop.number + '</span>';
      });

      return L.divIcon({
        className: '',
        html: '<div class="transport-marker route-path-marker-group">' + segments.join('') + '</div>',
        iconSize: [0, 0],
        iconAnchor: [0, 0]
      });
    }

    function groupTitle(group) {
      var names = [];
      var numbers = group.map(function (stop) {
        if (names.indexOf(stop.name) === -1) {
          names.push(stop.name);
        }

        return config.messages.directions[stop.direction === RETURN ? RETURN : OUTBOUND] + ' ' + stop.number;
      });

      return names.join(' / ') + ': ' + numbers.join(', ');
    }

    function groupsByPosition(stops) {
      var groups = [];
      var indexes = {};

      stops.filter(hasCoordinates).forEach(function (stop) {
        var key = stop.latitude + ',' + stop.longitude;

        if (!(key in indexes)) {
          indexes[key] = groups.length;
          groups.push([]);
        }

        groups[indexes[key]].push(stop);
      });

      return groups;
    }

    function addMarker(group) {
      var position = [parseFloat(group[0].latitude), parseFloat(group[0].longitude)];

      if (group.length === 1) {
        L.marker(position, { icon: markerIcon(group[0]), title: group[0].number + ' - ' + group[0].name, keyboard: false }).addTo(layers);

        return position;
      }

      var title = groupTitle(group);
      var marker = L.marker(position, { icon: groupIcon(group), title: title, keyboard: false });

      marker.on('add', function () {
        marker.getElement().setAttribute('aria-label', title);
      });
      marker.addTo(layers);

      return position;
    }

    function lineCoordinates(geometry) {
      if (!geometry || !geometry.coordinates) {
        return [];
      }

      return geometry.coordinates.map(function (coordinate) {
        return [coordinate[1], coordinate[0]];
      });
    }

    function draw(stops, outbound, inbound) {
      if (!map) {
        return;
      }

      map.invalidateSize();
      layers.clearLayers();

      var points = [];

      [[outbound, OUTBOUND], [inbound, RETURN]].forEach(function (line) {
        var coordinates = lineCoordinates(line[0]);

        if (coordinates.length > 1) {
          L.polyline(coordinates, { color: config.colors[line[1]], weight: 4, opacity: 0.9 }).addTo(layers);
          points = points.concat(coordinates);
        }
      });

      groupsByPosition(stops).forEach(function (group) {
        points.push(addMarker(group));
      });

      if (points.length) {
        map.fitBounds(L.latLngBounds(points), { padding: transportMap.viewPadding, maxZoom: STOPS_MAX_ZOOM });
      } else {
        transportMap.showDefaultView(map, config.view, VIEW_MAX_ZOOM);
      }
    }

    draw(config.stops, config.outbound, config.return);
    transportMap.keepSized(map, block);

    if (!config.editable) {
      return;
    }

    function tableSequence() {
      var codes = $('input[id^="ref_cod_ponto_transporte_escolar"]');
      var sequence = [];

      codes.each(function () {
        var match = STOP_CODE.exec(this.value || '');

        if (!match) {
          return;
        }

        var index = this.id.substring(this.id.indexOf('['));
        var direction = $(element('tipo' + index)).val() === RETURN ? RETURN : OUTBOUND;

        sequence.push(match[1] + ':' + direction);
      });

      return sequence.join(',');
    }

    function button() {
      return element('route_path_calculate');
    }

    function setButton(enabled, label) {
      var node = button();

      if (!node) {
        return;
      }

      node.disabled = !enabled;
      node.className = enabled ? 'botaolistagem' : 'botaolistagemdisabled';
      node.textContent = label || config.messages.calculate;
    }

    function refreshButton() {
      if (state.calculating) {
        return;
      }

      var isEmpty = tableSequence() === '';

      setVisible('route_path_empty', isEmpty);
      setButton(!isEmpty && !state.blocked);
    }

    function onTableChange() {
      window.setTimeout(function () {
        var sequence = tableSequence();

        if (sequence === state.current) {
          return;
        }

        state.current = sequence;
        state.blocked = false;
        setVisible('route_path_missing', false);
        setVisible('route_path_failure', false);

        if (state.reference !== null) {
          setVisible('route_path_stale', sequence !== state.reference);
        }

        refreshButton();
      }, 0);
    }

    function formatDistance(km) {
      return km === null || km === undefined ? '' : Number(km).toFixed(2).replace('.', ',');
    }

    function lineStringText(geometry) {
      if (!geometry || !geometry.coordinates) {
        return '';
      }

      var coordinates = geometry.coordinates.map(function (coordinate) {
        return '[' + Number(coordinate[0]) + ',' + Number(coordinate[1]) + ']';
      });

      return '{"type":"LineString","coordinates":[' + coordinates.join(',') + ']}';
    }

    function setField(id, value) {
      var node = element(id);

      if (node) {
        node.value = value;
      }
    }

    function showMissing(missing) {
      var list = element('route_path_missing_list');

      if (list) {
        list.innerHTML = '';

        missing.forEach(function (stop, index) {
          var link = document.createElement('a');

          link.href = config.stopUrl + stop.stop;
          link.textContent = stop.stop + ' - ' + stop.name;

          if (index > 0) {
            list.appendChild(document.createTextNode(', '));
          }

          list.appendChild(link);
        });
      }

      state.blocked = true;
      setVisible('route_path_missing', true);
    }

    function showCalculated(result, sequence) {
      draw(result.stops, result.outbound.geometry, result.return.geometry);

      setField('km_ida', formatDistance(result.outbound.km));
      setField('km_volta', formatDistance(result.return.km));
      setField('km_pav', formatDistance(result.km_paved));
      setField('km_npav', formatDistance(result.km_unpaved));
      setField('trajeto_ida', lineStringText(result.outbound.geometry));
      setField('trajeto_volta', lineStringText(result.return.geometry));
      setField('trajeto_hash', result.hash);

      var calculatedAt = element('route_path_calculated_at');

      if (calculatedAt) {
        calculatedAt.textContent = config.messages.lastCalculation + result.calculated_at;
        calculatedAt.hidden = false;
      }

      state.reference = sequence;
      setVisible('route_path_not_calculated', false);
      setVisible('route_path_stale', false);
    }

    function showMissingKey() {
      var node = button();

      if (node) {
        node.parentNode.removeChild(node);
      }

      if (element('route_path_missing_key')) {
        setVisible('route_path_missing_key', true);
      } else {
        setVisible('route_path_failure', true);
      }
    }

    function handleResult(result, sequence) {
      if (result.status === 'calculated') {
        showCalculated(result, sequence);
      } else if (result.status === 'missing_coordinates') {
        showMissing(result.missing || []);
      } else if (result.status === 'missing_key') {
        showMissingKey();
      } else if (result.status !== 'empty') {
        setVisible('route_path_failure', true);
      }
    }

    function observeStopsTable() {
      var table = element(STOPS_TABLE_ID);

      if (!table || typeof MutationObserver === 'undefined') {
        return;
      }

      new MutationObserver(onTableChange).observe(table, { childList: true, subtree: true });
    }

    function calculate() {
      var sequence = tableSequence();

      if (sequence === '' || state.calculating || state.blocked) {
        refreshButton();

        return;
      }

      state.calculating = true;
      setVisible('route_path_failure', false);
      setVisible('route_path_missing', false);
      setButton(false, config.messages.calculating);

      $.ajax({
        url: config.endpoint,
        dataType: 'json',
        data: { oper: 'get', resource: 'trajeto', id: config.route, pontos: sequence }
      }).done(function (response) {
        if (response && !response.any_error_msg && response.status) {
          handleResult(response, sequence);
        } else {
          setVisible('route_path_failure', true);
        }
      }).fail(function () {
        setVisible('route_path_failure', true);
      }).always(function () {
        state.calculating = false;
        refreshButton();
      });
    }

    $(document).on('change keyup blur autocompleteselect', TABLE_FIELDS, onTableChange);
    observeStopsTable();
    $(button()).on('click', calculate);

    state.current = tableSequence();
    state.blocked = element('route_path_missing') !== null && !element('route_path_missing').hidden;
    refreshButton();
  });
})(window.$j || window.jQuery, window.routePathMap, window.transportMap);
