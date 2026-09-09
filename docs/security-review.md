# セキュリティ修正記録（2026-09-06）

対象はリポジトリのPHP、投稿表示、アップロード、セッション、ログインと公開用設定。
本番への侵入テストやVPS/Docker構成の検証は実施していない。

| 所見 | 対応 | 確認 |
| --- | --- | --- |
| ログインPOSTにCSRF検証がなかった | トークンを発行・照合し、配列や欠落も403にする | HTTPで正常ログインと不正POSTを確認 |
| パスワード試行回数に制限がなかった | SQLiteのトランザクションでアカウント/IP別の試行枠を予約。Cookie削除でもリセットしない | IP・アカウント変更、期限、429を確認 |
| セッションのstrict mode未指定、プロキシ配下ではSecureが外れ得た | Cookieのみ、strict mode、Secure既定。明示したローカル開発だけHTTPを許可 | PHP設定とHTTPのID更新を確認 |
| セッションの期限・パスワード更新後の失効確認がなかった | 30分の無操作/8時間の絶対期限、DBの資格情報との照合 | HTTPでパスワード変更後のアクセス拒否を確認 |
| 文字列想定のPOSTへ配列を送るとTypeErrorになった | 文字列入力を検証し400、CSRFは型不正を403で拒否 | HTTPでlogin/create/deleteを確認 |
| 画像シグネチャだけで偽装ファイルを画像判定していた | 画像情報と寸法の検証、アップロード構造と実ファイルサイズの検証 | 偽装ヘッダー/SVGを拒否し、実PNGの投稿・削除を確認 |
| DBとbackupが公開ルート配下にある | Caddy/nginx拒否設定、公開領域外のDB指定、backup/storageのGit除外 | 設定ファイル作成まで。本番での適用が必要 |
| `backup/storage/muc.sqlite` がGit追跡中で、管理者レコードを1件含んでいた | ローカルのbackup/storageを削除し、backup全体をGit除外 | 2026-09-07、削除をGitHub mainへ反映（1eed1fc）。過去履歴には残る |
| 管理画面でパスワードを変更できなかった | 現在のパスワード確認と新パスワード2回入力、CSRF、試行回数制限、更新競合の防止 | 一時DBのHTTPテストで更新・不一致・旧パスワード拒否・他セッション失効を確認 |

出力のエスケープ、SQLのプレースホルダー、投稿/削除の認証・CSRF確認、
ランダムな添付名と許可拡張子は維持した。XSS用文字列、危険なリンクスキーム、
インライン画像のパストラバーサルを回帰テストへ追加した。

PHPの詳細エラーは画面へ出さず、サーバーログに記録する。
HTTPヘッダーはクリックジャッキング、MIMEの推測、base要素などを制限する。
CSPは既存のインラインスクリプト・Quill・Google Fonts・X埋め込みを壊さない範囲であり、
スクリプト全体の許可リストを強制する厳格なCSPではない。

## 制約と本番確認

- 追跡解除したDBは過去のGit履歴には残る。リポジトリやバックアップを共有・公開したことがある場合は、
  管理画面の変更機能を反映し「パスワードを変更」から更新するか、サーバーで `scripts/rotate_admin_password.php` を使って変更する。
  パスワードハッシュの値やユーザー名はこのレビューで出力していない。
  実パスワードの変更とGit履歴の書き換えは行っていない。
- Caddy/nginxの実バイナリと本番設定はこの作業環境にないため、設定の構文検証と再読み込みは未実施。
- 拒否設定の適用後、`/storage/muc.sqlite`、`/backup/storage/`、`/.git/config`、
  `/.user.ini`、`/include/db.php`、`/scripts/create_admin.php` が取得できないことを確認する。
- ランダム名の正規画像は表示され、アップロード先のPHPや未知拡張子は実行・配信されないことを確認する。
- 接続元は `REMOTE_ADDR` を使用する。任意の `X-Forwarded-For` を信用しない。
  リバースプロキシ配下ではIP枠が共有され得るので、必要なら信頼するプロキシをWebサーバー側で明示する。
- アップロードの完全なマルウェア解析や動画の再エンコードは行わない。拡張子制限と静的配信の設定は必須。
- 全脆弱性の不存在を保証するものではない。TLS・OS・PHP/FPM・Caddy・nginxの更新状況は本番側で確認する。

実装時の参考：
[OWASP Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)、
[PHP session settings](https://www.php.net/manual/en/session.security.ini.php)、
[OWASP File Upload](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html)。
