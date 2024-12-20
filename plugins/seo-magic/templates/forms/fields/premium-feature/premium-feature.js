const GRAV_FEATURE_ENABLER = 'https://getgrav.org/premium/features';

document.addEventListener('DOMContentLoaded', function() {
  const features = document.querySelectorAll('[data-premium-feature]');
  features.forEach(function(feature) {
    return GRAV_FEATURE_REQUEST({ feature: feature });
  });
});

window.addEventListener('click', function (event) {
  const isRegister = event.target.closest('[data-premium-feature-register]');
  const isUnregister = event.target.closest('[data-premium-feature-unregister]');
  if (isRegister || isUnregister) {
    event.preventDefault();

    const feature = event.target.closest('[data-premium-feature]');
    const data = JSON.parse(atob(feature.dataset.premiumFeature || 'e30='));
    const field = feature.querySelector('input[type="text"]');

    data.site = field.value;
    return GRAV_FEATURE_REQUEST({ feature: feature, data: data, action: isRegister ? 'register' : 'unregister' });
  }
});

const GRAV_FEATURE_REQUEST= async function({ feature, data = null, action = 'get' }) {
  const body = data || JSON.parse(atob(feature.dataset.premiumFeature || 'e30='));
  const inputField = feature.querySelector('input[type="text"]')
  const errorElement = feature.querySelector('.premium-feature-error');
  const icons = feature.querySelectorAll('.pf-icons');
  const registerButton = feature.querySelector('[data-premium-feature-register]');
  const registeredMessage = feature.querySelector('[data-premium-feature-message="registered"]');
  const unregisterButton = feature.querySelector('[data-premium-feature-unregister]');
  const unregisteredMessage = feature.querySelector('[data-premium-feature-message="unregistered"]');

  errorElement.innerHTML = '';
  errorElement.classList.add('hidden');

  icons.forEach(function(icon) {
    icon.classList.add('hidden');
  });

  try {
    const response = await GRAV_FEATURE_FETCH(body, action);

    if (!response.ok) {
      feature.querySelector('.pf-error').classList.remove('hidden');
      errorElement.innerHTML = `An error has occured: ${response.status}`;
      errorElement.classList.remove('hidden');
    }

    const output = await response.json();

    if (output.registered) {
      if (response.ok) {
        feature.querySelector('.pf-registered').classList.remove('hidden');
        registeredMessage.querySelector('strong').innerText = output.site || body.origin;
      }
      unregisteredMessage.classList.add('hidden');
      registerButton.classList.add('hidden');
      registerButton.setAttribute('disabled', 'disabled');
      registeredMessage.classList.remove('hidden');
      unregisterButton.classList.remove('hidden');
      unregisterButton.removeAttribute('disabled');
      inputField.value = output.site;
    } else {
      if (response.ok) {
        feature.querySelector('.pf-unregistered').classList.remove('hidden');
        registeredMessage.querySelector('strong').innerText = output.site || body.origin;
      }
      registeredMessage.classList.add('hidden');
      unregisterButton.classList.add('hidden');
      unregisterButton.setAttribute('disabled', 'disabled');
      unregisteredMessage.classList.remove('hidden');
      registerButton.classList.remove('hidden');
      registerButton.removeAttribute('disabled');
      inputField.value = '';
    }
  } catch (error) {
    feature.querySelector('.pf-error').classList.remove('hidden');
    errorElement.innerHTML = `An error has occured: ${error}`;
    errorElement.classList.remove('hidden');
  }
}

const GRAV_FEATURE_FETCH = async function(data, action = 'get') {
  return await fetch(GRAV_FEATURE_ENABLER, {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json'
    },
    mode: 'cors',
    referrerPolicy: 'same-origin',
    body: JSON.stringify({
      feature: data.feature,
      site: data.site || data.origin,
      license: data.license,
      origin: data.origin,
      action: action
    }),
  });
}
