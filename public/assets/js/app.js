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

  var promoteModal = document.querySelector('[data-promote-modal]');
  if (promoteModal) {
    var modalPanel = promoteModal.querySelector('[data-promote-modal-panel]');
    var openButtons = document.querySelectorAll('[data-open-promote-modal]');
    var closeButtons = promoteModal.querySelectorAll('[data-close-promote-modal]');
    var idInput = promoteModal.querySelector('[data-promote-user-id]');
    var targetText = promoteModal.querySelector('[data-promote-modal-target]');
    var firstInput = promoteModal.querySelector('[data-promote-first-input]');
    var phraseInput = promoteModal.querySelector('input[name="confirm_phrase"]');
    var passwordInput = promoteModal.querySelector('input[name="confirm_password"]');
    var totpInput = promoteModal.querySelector('input[name="confirm_totp"]');

    function closePromoteModal() {
      if (phraseInput) phraseInput.value = '';
      if (passwordInput) passwordInput.value = '';
      if (totpInput) totpInput.value = '';
      promoteModal.hidden = true;
    }

    openButtons.forEach(function (button) {
      button.addEventListener('click', function (event) {
        event.preventDefault();
        if (!idInput || !targetText) {
          return;
        }

        var targetId = button.getAttribute('data-target-user-id') || '';
        var targetName = button.getAttribute('data-target-user-name') || 'user';
        var targetEmail = button.getAttribute('data-target-user-email') || '';
        idInput.value = targetId;
        targetText.textContent = targetEmail !== ''
          ? 'Target: ' + targetName + ' (' + targetEmail + ')'
          : 'Target: ' + targetName;

        promoteModal.hidden = false;
        if (firstInput) {
          window.setTimeout(function () {
            firstInput.focus();
          }, 0);
        }
      });
    });

    closeButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        closePromoteModal();
      });
    });

    promoteModal.addEventListener('click', function (event) {
      if (event.target === promoteModal) {
        closePromoteModal();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !promoteModal.hidden) {
        closePromoteModal();
      }
    });

    if (modalPanel) {
      modalPanel.addEventListener('click', function (event) {
        event.stopPropagation();
      });
    }
  }
});
