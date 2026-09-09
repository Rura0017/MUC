"use strict";

// 投稿の冒頭だけを読み込み、カード全体から個別の投稿へ進めるようにする。
(async function loadPostPreviews() {
  const track = document.getElementById("recent-post-previews");
  const status = document.getElementById("recent-posts-status");
  if (!track || !status) return;

  // 古いHTMLが残っていてもサンプルを表示せず、取得した投稿だけを使う。
  track.replaceChildren();
  track.hidden = true;
  delete track.dataset.samplePosts;
  status.hidden = false;
  status.textContent = "投稿を読み込んでいます…";
  track.setAttribute("aria-busy", "true");
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 10000);

  try {
    const response = await fetch("./api/latest_post.php?previews=1", {
      headers: { Accept: "application/json" },
      cache: "no-store",
      signal: controller.signal
    });
    if (!response.ok) throw new Error("投稿を取得できませんでした。");

    const data = await response.json();
    if (!Array.isArray(data.previews)) {
      throw new Error("投稿データの形式が正しくありません。");
    }

    const cards = data.previews.slice(0, 3).map((post) => {
      if (!post || !Number.isSafeInteger(post.id) || post.id <= 0
          || typeof post.title !== "string"
          || typeof post.excerpt !== "string"
          || typeof post.created_at !== "string") {
        throw new Error("投稿データの形式が正しくありません。");
      }

      const card = document.createElement("li");
      card.className = "home-post-card";
      const link = document.createElement("a");
      link.className = "home-post-link";
      link.href = `./pages/post.php?id=${post.id}`;

      const date = document.createElement("time");
      date.textContent = post.created_at.slice(0, 10);
      if (typeof post.created_at_iso === "string"
          && !Number.isNaN(Date.parse(post.created_at_iso))) {
        date.dateTime = post.created_at_iso;
      }

      const title = document.createElement("h3");
      title.id = `post-preview-title-${post.id}`;
      title.textContent = post.title;
      link.setAttribute("aria-labelledby", title.id);

      const excerpt = document.createElement("p");
      excerpt.className = "home-post-excerpt";
      excerpt.textContent = post.excerpt || "投稿を開いて内容をご覧ください。";

      const more = document.createElement("span");
      more.className = "home-post-more";
      more.textContent = "続きを読む →";
      more.setAttribute("aria-hidden", "true");

      // 投稿の文字列はHTMLとして解釈せず、そのまま文字として表示する。
      link.append(date, title, excerpt, more);
      card.append(link);
      return card;
    });

    if (cards.length > 0) {
      track.replaceChildren(...cards);
      track.hidden = false;
      status.hidden = true;
    } else {
      status.textContent = "投稿はまだありません。";
    }
  } catch (error) {
    status.textContent = "投稿を読み込めませんでした。上の「一覧を見る」からご確認ください。";
    console.error(error);
  } finally {
    window.clearTimeout(timeout);
    track.setAttribute("aria-busy", "false");
  }
})();
