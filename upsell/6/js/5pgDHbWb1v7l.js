const upsellPaths = [
    '1/index.html',   // Upsell 1
    '2/index.html',   // Upsell 2
    '3/index.html',   // Upsell 3
    '4/index.html',   // Upsell 4
    '5/index.html',   // Upsell 5
    '6/index.html',   // Upsell 6
  ];

  const finalDestination = 'https://www.google.com/';

  function getCookie(name) {
    const cookieString = document.cookie;
    const cookies = cookieString.split('; ');

    for (const cookie of cookies) {
      const [cookieName, cookieValue] = cookie.split('=');
      if (cookieName === name) {
        return cookieValue;
      }
    }

    return null;
  }

  function setCookie(name, value, days = 30) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    const expires = `expires=${date.toUTCString()}`;
    document.cookie = `${name}=${value};${expires};path=/`;
  }

  function markUpsellAsVisited(upsellNumber) {
    if (upsellNumber >= 1 && upsellNumber <= upsellPaths.length) {
      setCookie(`visited_upsell_${upsellNumber}`, 'true', 30);
    }
  }

  function hasVisitedUpsell(upsellNumber) {
    return getCookie(`visited_upsell_${upsellNumber}`) === 'true';
  }

  function getNextUpsellIndex() {
    for (let i = 0; i < upsellPaths.length; i++) {
      if (!hasVisitedUpsell(i + 1)) {
        return i;
      }
    }
    return -1;
  }

  function redirectToNextUpsell() {
    const qs = window.location.search || ''; // preserva UTMs/parâmetros ao avançar
    const nextUpsellIndex = getNextUpsellIndex();

    if (nextUpsellIndex >= 0) {
      window.location.href = upsellPaths[nextUpsellIndex] + qs;
    } else {
      const sep = finalDestination.indexOf('?') >= 0 ? '&' : '?';
      window.location.href = finalDestination + (qs ? sep + qs.slice(1) : '');
    }
  }

  function initUpsell(currentUpsellNumber) {
    markUpsellAsVisited(currentUpsellNumber);
    console.log(`Upsell ${currentUpsellNumber} carregado e marcado como visitado.`);
  }

  window.redirectToNextUpsell = redirectToNextUpsell;
  window.initUpsell = initUpsell;
