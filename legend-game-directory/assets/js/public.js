(function () {
  'use strict';
  function serialize(form) {
    var data = {};
    new FormData(form).forEach(function (value, key) {
      var isArray = key.slice(-2) === '[]';
      var name = isArray ? key.slice(0, -2) : key;
      if (isArray || Object.prototype.hasOwnProperty.call(data, name)) {
        if (!Array.isArray(data[name])) data[name] = name in data ? [data[name]] : [];
        data[name].push(value);
      } else {
        data[name] = value;
      }
    });
    return data;
  }
  function submitForm(form) {
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      var status = form.querySelector('.lgd-form-status');
      var data = serialize(form);
      status.textContent = 'Sending…';
      try {
        var response = await fetch(form.dataset.endpoint, {
          method: 'POST', credentials: 'same-origin',
          headers: {'Content-Type': 'application/json', 'X-WP-Nonce': window.LGD ? LGD.nonce : ''},
          body: JSON.stringify(data)
        });
        var result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Request failed.');
        status.textContent = result.message || 'Thank you.';
        form.reset();
      } catch (error) { status.textContent = error.message; }
    });
  }
  document.querySelectorAll('.lgd-review-form,.lgd-report-form,.lgd-ajax-form').forEach(submitForm);

  var choices = Array.from(document.querySelectorAll('.lgd-compare-choice'));
  if (!choices.length) return;
  var selected = JSON.parse(sessionStorage.getItem('lgdCompare') || '[]').map(String).slice(0, 4);
  var button = document.createElement('a');
  button.className = 'lgd-compare-float'; button.hidden = true; document.body.appendChild(button);
  function update() {
    choices.forEach(function (choice) { choice.checked = selected.indexOf(choice.value) !== -1; });
    button.hidden = selected.length < 2;
    button.textContent = 'Compare ' + selected.length + ' games';
    var url = new URL((window.LGD && LGD.compareUrl) || window.location.href, window.location.origin);
    url.searchParams.set('games', selected.join(',')); button.href = url.toString();
    sessionStorage.setItem('lgdCompare', JSON.stringify(selected));
  }
  choices.forEach(function (choice) {
    choice.addEventListener('change', function () {
      if (choice.checked && selected.indexOf(choice.value) === -1) {
        if (selected.length >= 4) { choice.checked = false; window.alert('You can compare up to four games.'); return; }
        selected.push(choice.value);
      } else if (!choice.checked) selected = selected.filter(function (id) { return id !== choice.value; });
      update();
    });
  });
  update();
}());
