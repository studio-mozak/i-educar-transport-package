

// autocomplete disciplina fields

var handleSelect = function(event, ui){
  $j(event.target).val(ui.item.label);
  return false;
};

var search = function(request, response) {
  var searchPath = '/module/Api/Ponto?oper=get&resource=ponto-search';
  var params     = { query : request.term };

  $j.get(searchPath, params, function(dataResponse) {
    simpleSearch.handleSearch(dataResponse, response);
  });
};

var searchV = function(request, response) {
  var searchPath = '/module/Api/Veiculo?oper=get&resource=veiculo-search';
  var params     = { query : request.term };

  $j.get(searchPath, params, function(dataResponse) {
    simpleSearch.handleSearch(dataResponse, response);
  });
};

function setAutoComplete() {
  $j.each($j('input[id^="ref_cod_ponto_transporte_escolar"]'), function(index, field) {

    $j(field).autocomplete({
      source    : search,
      select    : handleSelect,
      minLength : 1,
      autoFocus : true
    });

  });
  $j.each($j('input[id^="ref_cod_veiculo"]'), function(index, field) {

    $j(field).autocomplete({
      source    : searchV,
      select    : handleSelect,
      minLength : 1,
      autoFocus : true
    });

  });
}

setAutoComplete();

var stopTimeFieldSelector = 'input[name^="hora["]';
var stopTimePattern = /^([0-1]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/;
var stopTimeErrorMessage = 'Hora inválida. Use HH:MM, ex.: 06:30.';

var horizontalBoxSpace = function(field) {
  var style = window.getComputedStyle(field);

  return ['paddingLeft', 'paddingRight', 'borderLeftWidth', 'borderRightWidth'].reduce(function(total, property) {
    return total + (parseFloat(style[property]) || 0);
  }, 0);
};

var digitCount = function(text) {
  return text.replace(/\D/g, '').length;
};

var caretAfterDigits = function(value, digits) {
  var caret = 0;

  while (caret < value.length && digitCount(value.slice(0, caret)) < digits) {
    caret++;
  }

  return caret;
};

var isDeletion = function(event) {
  return /^delete/.test((event.originalEvent || {}).inputType);
};

var reapplyStopTimeMask = function(event) {
  var digitsBeforeCaret = digitCount(this.value.slice(0, this.selectionStart));

  $j(this).trigger('keyup.mask');

  if (isDeletion(event)) {
    return;
  }

  var caret = caretAfterDigits(this.value, digitsBeforeCaret);

  this.setSelectionRange(caret, caret);
};

var isValidStopTime = function(value) {
  return value === '' || stopTimePattern.test(value);
};

var stopTimeErrorId = function(field) {
  return field.id + '_error';
};

var lastInputWasPointer = false;

$j(document)
  .on('pointerdown', function() { lastInputWasPointer = true; })
  .on('keydown', function() { lastInputWasPointer = false; });

var markStopTimeInvalid = function(field) {
  $j(field).addClass('stop-time-invalid').attr('aria-invalid', 'true');
};

var showStopTimeMessage = function(field) {
  if (!document.getElementById(stopTimeErrorId(field))) {
    $j('<span>', { id: stopTimeErrorId(field), 'class': 'stop-time-error', text: stopTimeErrorMessage }).insertAfter(field);
  }

  field.setAttribute('aria-describedby', stopTimeErrorId(field));
};

var showStopTimeError = function(field) {
  markStopTimeInvalid(field);
  showStopTimeMessage(field);
};

var showStopTimeMessageIfStillInvalid = function(field) {
  if (field.isConnected && document.activeElement !== field && !isValidStopTime(field.value)) {
    showStopTimeMessage(field);
  }
};

var showStopTimeMessageAfterPointer = function(field) {
  $j(document).on('pointerup.stopTimeMessage click.stopTimeMessage', function() {
    $j(document).off('.stopTimeMessage');
    setTimeout(function() { showStopTimeMessageIfStillInvalid(field); });
  });
};

var hideStopTimeError = function(field) {
  $j(document.getElementById(stopTimeErrorId(field))).remove();
  $j(field).removeClass('stop-time-invalid').removeAttr('aria-invalid aria-describedby');
};

var validateStopTime = function() {
  if (isValidStopTime(this.value)) {
    hideStopTimeError(this);
  } else if (lastInputWasPointer) {
    markStopTimeInvalid(this);
    showStopTimeMessageAfterPointer(this);
  } else {
    showStopTimeError(this);
  }
};

var hideStopTimeErrorWhenValid = function() {
  if (isValidStopTime(this.value)) {
    hideStopTimeError(this);
  }
};

var maskStopTimeField = function(index, field) {
  field.removeAttribute('onkeypress');
  field.setAttribute('inputmode', 'decimal');
  field.style.minWidth = 'calc(5ch + ' + horizontalBoxSpace(field) + 'px)';
  $j(field).removeAttr('maxlength').mask('00:00').off('.stopTime')
    .on('input.stopTime', reapplyStopTimeMask)
    .on('input.stopTime', hideStopTimeErrorWhenValid)
    .on('blur.stopTime', validateStopTime);

  if (!isValidStopTime(field.value)) {
    showStopTimeError(field);
  }
};

var maskStopTimeFields = function() {
  $j(stopTimeFieldSelector).each(maskStopTimeField);
};

maskStopTimeFields();

document.onclick = function(event) {
  var targetElement = event.target;
  if ( targetElement.value == " Cancelar " ) {

    var cod_rota = $j('#cod_rota').val();
    location.href="transporte_rota_det.php?cod_rota="+cod_rota;
  } else if(targetElement.value == "Excluir todos"){
    var cod_rota = $j('#cod_rota').val();
    if(confirm('Este procedimento irá excluir todos os pontos do itinerário. Tem certeza que deseja continuar?')){
      location.href="transporte_itinerario_del.php?cod_rota="+cod_rota;
    }
  }
};

var submitForm = function(event) {
  // Esse formUtils.submit() chama o Editar();
  // Mais à frente bolar uma validação aqui
  /*  var $frequenciaField = $j('#frequencia');
      var frequencia       = $frequenciaField.val();

      if (frequencia.indexOf(',') > -1)
          frequencia = frequencia.replace('.', '').replace(',', '.');

    if (validatesIfNumericValueIsInRange(frequencia, $frequenciaField, 0, 100))*/
  formUtils.submit();
}


// bind events

var $addPontosButton = $j('#btn_add_tab_add_1');

$addPontosButton.click(function(){
  setAutoComplete();
  maskStopTimeFields();
});


// submit button

var $submitButton = $j('#btn_enviar');

$submitButton.removeAttr('onclick');
$submitButton.click(submitForm);


