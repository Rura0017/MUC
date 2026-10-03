"use strict";

// 紹介欄が画面に入った時点から、三つの文言を順に上へスライドさせる。
{
  const panels = [...document.querySelectorAll(".home-catchcopy-description")];
  const visiblePanels = new Set();

  function updatePlayback() {
    panels.forEach(function (panel) {
      panel.classList.toggle("is-playing", visiblePanels.has(panel) && !document.hidden);
    });
  }

  panels.forEach(function (panel) { panel.classList.add("is-animated"); });

  if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) visiblePanels.add(entry.target);
        else visiblePanels.delete(entry.target);
      });
      updatePlayback();
    }, { threshold: 0.25 });
    panels.forEach(function (panel) { observer.observe(panel); });
  } else {
    panels.forEach(function (panel) { visiblePanels.add(panel); });
    updatePlayback();
  }

  document.addEventListener("visibilitychange", updatePlayback);
}
