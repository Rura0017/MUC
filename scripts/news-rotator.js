"use strict";

document.querySelectorAll(".home-news").forEach(function (news) {
  const latest = news.querySelector(".home-news-latest");
  const slides = [...news.querySelectorAll(".home-news-slide")];
  const recruitment = news.querySelector(".home-news-recruitment");
  const pauseButton = news.querySelector(".home-news-pause");
  const firstNotice = news.querySelector(".newsbox ul > li");
  if (!latest || slides.length !== 2 || !recruitment || !pauseButton || !firstNotice) {
    return;
  }

  // 一覧の先頭だけを表示に使い、本文を二か所で更新する必要をなくす。
  latest.replaceChildren(...[...firstNotice.childNodes].map(function (node) {
    return node.cloneNode(true);
  }));
  firstNotice.hidden = true;
  slides[1].hidden = false;
  pauseButton.hidden = false;

  let current = 0;
  let timer;
  let paused = false;

  function showSlide(index) {
    const previous = current;
    current = index;
    news.dataset.slide = index === 0 ? "latest" : "recruitment";
    slides.forEach(function (slide, slideIndex) {
      slide.classList.remove("is-entering", "is-leaving");
      slide.setAttribute("aria-hidden", String(slideIndex !== index));
    });
    // どちらへ切り替える場合も、現在の内容は左へ出し、次の内容を右から入れる。
    if (previous !== index) {
      slides[previous].classList.add("is-leaving");
      slides[index].classList.add("is-entering");
    }
    recruitment.tabIndex = index === 1 ? 0 : -1;
  }

  function scheduleNext() {
    window.clearTimeout(timer);
    // 募集リンクを操作中の場合だけ待つ。欄へのマウス移動や開閉後のフォーカスでは止めない。
    if (paused || news.open || document.hidden || document.activeElement === recruitment) {
      return;
    }
    timer = window.setTimeout(function () {
      showSlide(1 - current);
      scheduleNext();
    }, 5000);
  }

  news.addEventListener("toggle", function () {
    // 過去のお知らせを読んでいる間は、最新のお知らせに固定する。
    if (news.open) showSlide(0);
    scheduleNext();
  });

  news.addEventListener("focusin", scheduleNext);
  news.addEventListener("focusout", function () {
    // フォーカスの移動が終わってから再開を判断する。
    queueMicrotask(scheduleNext);
  });

  pauseButton.addEventListener("click", function (event) {
    event.preventDefault();
    event.stopPropagation();
    paused = !paused;
    pauseButton.setAttribute("aria-pressed", String(paused));
    pauseButton.setAttribute("aria-label", paused ? "自動切り替えを再開" : "自動切り替えを停止");
    scheduleNext();
  });
  recruitment.addEventListener("click", function (event) {
    // 募集文を押した場合は加入方法へ進み、折りたたみを開かない。
    event.stopPropagation();
  });

  document.addEventListener("visibilitychange", scheduleNext);
  window.addEventListener("pagehide", function () { window.clearTimeout(timer); });
  window.addEventListener("pageshow", scheduleNext);
  showSlide(0);
  scheduleNext();
});
