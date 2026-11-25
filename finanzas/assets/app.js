document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
    return;
  }

  if (!window.confirm(form.dataset.confirm)) {
    event.preventDefault();
  }
});

document.querySelectorAll('[data-household-scope]').forEach((scopeSelect) => {
  const form = scopeSelect.form;
  const householdField = form.querySelector('[data-household-field]');
  const householdSelect = form.querySelector('[data-household-select]');
  if (!householdField || !householdSelect) {
    return;
  }

  const updateHouseholdField = () => {
    const householdSelected = scopeSelect.value === 'household';
    householdField.hidden = !householdSelected;
    householdSelect.disabled = !householdSelected;
    householdSelect.required = householdSelected;
    if (!householdSelected) {
      householdSelect.value = '';
    }
  };

  scopeSelect.addEventListener('change', updateHouseholdField);
  updateHouseholdField();
});

document.querySelectorAll('[data-liability-kind]').forEach((kindSelect) => {
  const form = kindSelect.form;
  const cardFields = form.querySelectorAll('[data-credit-card-field]');
  const updateCardFields = () => {
    const isCard = kindSelect.value === 'credit_card';
    cardFields.forEach((field) => {
      field.hidden = !isCard;
      const input = field.querySelector('[data-credit-card-required]');
      if (input) {
        input.disabled = !isCard;
        input.required = isCard;
      }
    });
  };

  kindSelect.addEventListener('change', updateCardFields);
  updateCardFields();
});

document.querySelectorAll('[data-payment-liability]').forEach((liabilitySelect) => {
  const interestField = liabilitySelect.form.querySelector('[data-loan-interest-field]');
  const interestInput = interestField?.querySelector('input');
  if (!interestField || !interestInput) {
    return;
  }

  const updateInterestField = () => {
    const selectedOption = liabilitySelect.selectedOptions[0];
    const isCard = selectedOption?.dataset.kind === 'credit_card';
    interestField.hidden = isCard;
    interestInput.disabled = isCard;
    if (isCard) {
      interestInput.value = '0.00';
    }
  };

  liabilitySelect.addEventListener('change', updateInterestField);
  updateInterestField();
});

document.querySelectorAll('[data-recurring-type]').forEach((typeSelect) => {
  const categorySelect = typeSelect.form.querySelector('[data-recurring-category]');
  if (!categorySelect) {
    return;
  }

  const updateCategories = () => {
    for (const option of categorySelect.options) {
      if (!option.value) {
        continue;
      }
      const matches = option.dataset.kind === typeSelect.value;
      option.hidden = !matches;
      option.disabled = !matches;
      if (!matches && option.selected) {
        categorySelect.value = '';
      }
    }
  };

  typeSelect.addEventListener('change', updateCategories);
  updateCategories();
});

document.querySelectorAll('[data-print-report]').forEach((button) => {
  button.addEventListener('click', () => window.print());
});
