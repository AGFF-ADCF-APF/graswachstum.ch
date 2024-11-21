((function(global) {
  const DATA = JSON.parse(document.documentElement.dataset.appearance || 'null');
  const matchMedia = window.matchMedia('(prefers-color-scheme: dark)');

  try {
    // Chrome & Firefox
    matchMedia.addEventListener('change', (event) => {
      if (typhoonRetrieve().appearance === 'system') {
        typhoonSetTheme(event.matches ? 'dark' : 'light', true);
      }
    });
  } catch (fallback) {
    try {
      // Safari
      matchMedia.addListener((event) => {
        if (typhoonRetrieve().appearance === 'system') {
          typhoonSetTheme(event.matches ? 'dark' : 'light', true);
        }
      });
    } catch (error) {
      console.error(error);
    }
  }

  const typhoonStorage = {
    set(value) {
      const data = JSON.stringify(value);

      if (DATA.type === 'cookie') {
        Cookies.set('typhoon-appearance', data, { expires: 365 });
      } else {
        const STORAGE = DATA.store ? 'localStorage' : 'sessionStorage';
        global[STORAGE].setItem('typhoon-appearance', data);
      }
    },
    get() {
      if (DATA.type === 'cookie') {
        return JSON.parse(Cookies.get('typhoon-appearance') || 'null');
      } else {
        const STORAGE = DATA.store ? 'localStorage' : 'sessionStorage';
        return JSON.parse(global[STORAGE].getItem('typhoon-appearance') || 'null');
      }
    },
  }

  global.typhoonStore = function(data) {
    const theme = data.appearance === 'system' ? typhoonGetTheme() : data.appearance;
    const config = Object.assign({}, typhoonRetrieve(), data, { theme: theme });

    typhoonStorage.set(config);
    typhoonSetTheme(theme, false);
  }

  global.typhoonRetrieve = function() {
    const systemTheme = typhoonGetTheme();
    const storage = typhoonStorage.get();

    if (storage && storage.appearance === 'system') {
      storage.theme = systemTheme;
    }

    return storage || {
      theme: DATA.appearance === 'system' ? systemTheme : DATA.appearance,
      appearance: DATA.appearance || 'system'
    };
  }

  global.typhoonSetTheme = function(theme, store) {
    const event = new CustomEvent('typhoon-theme', {
      detail: {
        theme: theme,
        appearance: typhoonRetrieve().appearance
      }
    });

    if (store) {
      typhoonStore({ theme: theme || typhoonGetTheme() });
    }

    if (DATA.type === 'cookie') {
      window.dispatchEvent(event);

      setTimeout(() => {
        document.documentElement.classList.remove('light', 'dark');
        document.documentElement.classList.add(theme);
      }, 0);
    } else {
      window.dispatchEvent(event);
    }
  }

  global.typhoonGetTheme = function() {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

})(window));
