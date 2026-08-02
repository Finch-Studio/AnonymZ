document.addEventListener('DOMContentLoaded', function () {
  // -------------------------------
  // Section handling
  // -------------------------------

  const sections = document.querySelectorAll('.hidden-section');
  sections.forEach(section => (section.style.display = 'none'));

  const defaultSection = document.getElementById('defaultSection');
  if (defaultSection) defaultSection.style.display = 'block';

  // -------------------------------
  // Menu handling
  // -------------------------------

  const navIcon = document.querySelector('.nav-icon');
  const myDropdown = document.getElementById('myDropdown');

  window.toggleMenu = function () {
    navIcon.classList.toggle('change');
    myDropdown.style.display =
      myDropdown.style.display === 'block' ? 'none' : 'block';
  };

  window.hideMenu = function () {
    navIcon.classList.remove('change');
    myDropdown.style.display = 'none';
  };

  myDropdown.addEventListener('mouseover', () => {
    myDropdown.style.display = 'block';
  });

  myDropdown.addEventListener('mouseout', () => {
    if (!navIcon.classList.contains('change')) {
      myDropdown.style.display = 'none';
    }
  });

  document.addEventListener('click', event => {
    if (!navIcon.contains(event.target) && !myDropdown.contains(event.target)) {
      hideMenu();
    }
  });

  // -------------------------------
  // Section switching
  // -------------------------------

  window.switchSection = function (sectionId) {
    sections.forEach(section => (section.style.display = 'none'));

    const target = document.getElementById(sectionId);
    if (target) target.style.display = 'block';

    hideMenu();
  };

  // -------------------------------
  // URL generation (IMPORTANT PART)
  // -------------------------------

  window.generateAnonymizedUrl = function () {
    const rawInput = document.getElementById('urlInput').value.trim();
    if (!rawInput) return;

    // Encode exactly once. redirect.php decodes exactly one layer back,
    // so pre-decoding here would strip any percent-encoding that's
    // actually part of the destination URL itself (e.g. %20 or %25).
    const anonymizedUrl =
      'https://anonymz.io/?' + encodeURIComponent(rawInput);

    const output = document.getElementById('anonymizedUrl');
    const section = document.getElementById('anonymizedUrlSection');
    const useLink = document.getElementById('useLink');

    output.value = anonymizedUrl;
    useLink.href = anonymizedUrl;
    section.style.display = 'block';
  };

  // -------------------------------
  // Keyboard support
  // -------------------------------

  window.handleKeyPress = function (event) {
    if (event.key === 'Enter') {
      generateAnonymizedUrl();
    }
  };

  // -------------------------------
  // Clipboard handling
  // -------------------------------

  window.copyToClipboard = function () {
    const input = document.getElementById('anonymizedUrl');
    if (!input.value) return;

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).catch(() => {});
    } else {
      input.focus();
      input.select();
      document.execCommand('copy');
    }
  };
});


    