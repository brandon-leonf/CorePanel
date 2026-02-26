document.addEventListener('click', function (event) {
  var confirmTarget = event.target.closest('[data-confirm]');
  if (confirmTarget) {
    var message = confirmTarget.getAttribute('data-confirm') || 'Are you sure?';
    if (!window.confirm(message)) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
  }

  var printTarget = event.target.closest('[data-print-window]');
  if (printTarget) {
    event.preventDefault();
    window.print();
  }
});

function formatUsPhone(value) {
  var digits = value.replace(/\D/g, '').slice(0, 10);
  if (digits.length === 0) return '';
  if (digits.length <= 3) return digits.length === 3 ? '(' + digits + ') ' : '(' + digits;
  if (digits.length <= 6) return '(' + digits.slice(0, 3) + ') ' + digits.slice(3);
  return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + ' ' + digits.slice(6);
}

document.addEventListener('DOMContentLoaded', function () {
  var phoneInputs = document.querySelectorAll('input[data-phone-format="us"]');
  phoneInputs.forEach(function (input) {
    input.value = formatUsPhone(input.value);
    input.addEventListener('input', function () {
      input.value = formatUsPhone(input.value);
    });
  });

  if (document.body && document.body.dataset.autoprint === '1') {
    window.print();
  }
});
