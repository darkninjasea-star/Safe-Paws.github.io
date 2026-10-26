(function () {
  var themeKey = 'safe-paws-theme';
  var preference = 'light';

  function storedPreference() {
    try {
      var stored = window.localStorage.getItem(themeKey);
      return stored === 'dark' ? 'dark' : 'light';
    } catch (error) {
      console.warn('Safe PAWS could not read the saved color theme.', error);
      return 'light';
    }
  }

  function nextPreference(value) {
    return value === 'dark' ? 'light' : 'dark';
  }

  function applyTheme(value) {
    preference = value;
    document.documentElement.dataset.theme = value;
    document.documentElement.style.colorScheme = value;
  }

  applyTheme(storedPreference());

  function savePreference(value) {
    try {
      window.localStorage.setItem(themeKey, value);
    } catch (error) {
      console.warn('Safe PAWS could not save the color theme preference.', error);
    }
  }

  function setupThemeControl() {
    var button = document.querySelector('[data-theme-toggle]');
    if (!button) return;

    var label = button.querySelector('[data-theme-label]');

    function updateLabel() {
      var next = nextPreference(preference);
      var themeName = preference.charAt(0).toUpperCase() + preference.slice(1) + ' Mode';
      var nextName = next.charAt(0).toUpperCase() + next.slice(1);
      if (label) label.textContent = themeName;
      button.setAttribute('aria-label', 'Switch to ' + nextName.toLowerCase() + ' mode');
      button.title = 'Current theme: ' + themeName + ' | switch to ' + nextName + ' mode';
    }

    button.addEventListener('click', function () {
      var next = nextPreference(preference);
      applyTheme(next);
      savePreference(next);
      updateLabel();
    });

    updateLabel();
  }

  function setupNavigation() {
    var toggle = document.querySelector('.nav-toggle');
    var links = document.querySelector('.nav-links');
    if (!toggle || !links) return;

    function closeNavigation() {
      links.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function () {
      var open = links.classList.toggle('open');
      toggle.setAttribute('aria-expanded', String(open));
    });

    links.addEventListener('click', function (event) {
      if (event.target.closest('a')) closeNavigation();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeNavigation();
    });
  }

  function setupConfirmations() {
    document.querySelectorAll('[data-confirm]').forEach(function (element) {
      if (element.tagName === 'FORM') {
        element.addEventListener('submit', function (event) {
          if (!window.confirm(element.dataset.confirm)) event.preventDefault();
        });
      } else {
        element.addEventListener('click', function (event) {
          if (!window.confirm(element.dataset.confirm)) event.preventDefault();
        });
      }
    });
  }

  function setupFormFeedback() {
    document.querySelectorAll('form').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (event.defaultPrevented || !form.checkValidity()) return;
        if (form.dataset.submitting === 'true') {
          event.preventDefault();
          return;
        }

        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        var submitter = event.submitter || document.activeElement;
        if (submitter && form.contains(submitter) && /^(BUTTON|INPUT)$/.test(submitter.tagName)) {
          submitter.classList.add('is-loading');
        }
      });
    });

    document.querySelectorAll('input[type="file"][accept*="image"]').forEach(function (input) {
      var field = input.closest('.field');
      if (!field) return;

      var preview = document.createElement('div');
      preview.className = 'image-preview';
      preview.hidden = true;
      preview.innerHTML = '<img alt=""><span role="status"></span>';
      input.insertAdjacentElement('afterend', preview);
      var image = preview.querySelector('img');
      var message = preview.querySelector('[role="status"]');
      var previewUrl = null;

      input.addEventListener('change', function () {
        if (previewUrl) window.URL.revokeObjectURL(previewUrl);
        previewUrl = null;
        var file = input.files && input.files[0];
        if (!file || !file.type || file.type.indexOf('image/') !== 0) {
          preview.hidden = true;
          image.removeAttribute('src');
          message.textContent = '';
          return;
        }
        previewUrl = window.URL.createObjectURL(file);
        image.src = previewUrl;
        message.textContent = file.name;
        preview.hidden = false;
      });
    });
  }

  function setupMotionAndImages() {
    var siteBase = document.body.getAttribute('data-base-url') || '/';
    var fallbackImage = siteBase + 'assets/images/paw-placeholder.svg';
    document.querySelectorAll('img').forEach(function (image) {
      image.addEventListener('error', function () {
        if (image.dataset.fallbackAttempted === 'true') return;
        image.dataset.fallbackAttempted = 'true';
        image.src = fallbackImage;
      });
      if (!image.closest('.hero-art, .detail') && !image.classList.contains('brand-mark')) {
        image.loading = 'lazy';
      }
    });

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var revealTargets = document.querySelectorAll(
      '.hero-copy, .hero-art, .content-section, .stats > .stat, .card, .panel, .community-callout, .hero-strip, .detail > div, .filters, .table-wrap, .form-card'
    );
    if (reducedMotion || !('IntersectionObserver' in window) || !revealTargets.length) {
      revealTargets.forEach(function (target) {
        target.classList.add('is-visible');
      });
      return;
    }

    document.documentElement.classList.add('has-reveal');
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -32px 0px' });

    revealTargets.forEach(function (target, index) {
      target.dataset.reveal = '';
      target.style.setProperty('--reveal-delay', Math.min(index % 4, 3) * 55 + 'ms');
      observer.observe(target);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    setupThemeControl();
    setupNavigation();
    setupConfirmations();
    setupFormFeedback();
    setupMotionAndImages();
  });
})();
