"use strict";

document.querySelectorAll(".site-navigation").forEach(function (menu) {
  const toggle = menu.querySelector("summary");
  const mobile = window.matchMedia("(max-width: 760px)");

  // スマホでは閉じ、パソコンでは常に開いた状態に戻す。
  function closeMobileNavigation(focusToggle = false) {
    if (!mobile.matches || !menu.open) return;

    menu.open = false;
    if (focusToggle) toggle.focus();
  }

  function syncNavigation() {
    const active = document.activeElement;

    // HTMLに指定したスマホの初期状態は変えず、PCだけ常時表示にする。
    if (!mobile.matches) menu.open = true;

    if (mobile.matches && menu.contains(active) && active !== toggle) {
      toggle.focus();
    } else if (!mobile.matches && active === toggle) {
      menu.querySelector("a").focus();
    }
  }

  mobile.addEventListener("change", syncNavigation);
  syncNavigation();

  document.addEventListener("keydown", function (event) {
    if (mobile.matches && event.key === "Escape" && menu.open) {
      closeMobileNavigation(true);
    }
  });

  document.addEventListener("pointerdown", function (event) {
    if (mobile.matches && menu.open && !menu.contains(event.target)) {
      closeMobileNavigation();
    }
  });

  window.addEventListener("scroll", function () {
    closeMobileNavigation();
  }, { passive: true });

  menu.addEventListener("focusout", function (event) {
    if (mobile.matches && event.relatedTarget && !menu.contains(event.relatedTarget)) {
      closeMobileNavigation();
    }
  });
});
