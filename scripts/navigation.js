"use strict";

document.querySelectorAll(".site-navigation").forEach(function (menu) {
  const toggle = menu.querySelector("summary");
  const mobile = window.matchMedia("(max-width: 760px)");

  function syncNavigation() {
    const active = document.activeElement;
    menu.open = !mobile.matches;

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
      menu.open = false;
      toggle.focus();
    }
  });

  document.addEventListener("click", function (event) {
    if (mobile.matches && menu.open && !menu.contains(event.target)) {
      menu.open = false;
    }
  });

  menu.addEventListener("focusout", function (event) {
    if (mobile.matches && event.relatedTarget && !menu.contains(event.relatedTarget)) {
      menu.open = false;
    }
  });
});
