# Cargo Server Script / Cargo サーバースクリプト

[English](#english) | [日本語](#japanese)

<a name="english"></a>
## English

This repository contains the server-side scripts for the **Cargo Discord Bot**. By hosting these files on your PHP-enabled web server, you can create your own file storage for the bot.

### Requirements
- **PHP 7.4** or higher
- **Write permission** for the directory where the script is placed (to create and write to the `files` folder).

### Installation Guide

1. **Download Files**
   - Download `index.php` and `.htaccess` from this repository.

2. **Upload to Server**
   - Upload both files to a public directory on your web server (e.g., `public_html/cargo/`).

3. **Configure API Key**
   - Open `index.php` in a text editor.
   - Find the line `$API_KEY = 'YOUR_SECRET_KEY';` at the top.
   - Change `'YOUR_SECRET_KEY'` to a **secure, random string** of your choice.
     ```php
     $API_KEY = 'random_string_here_12345';
     ```

4. **Connect to Bot**
   - Go to your Discord server where Cargo Bot is added.
   - Run the following command (Administrator permission required):
     ```
     /set-server url:https://your-domain.com/cargo key:YOUR_API_KEY auto_delete:True
     ```
     - `url`: The URL where you uploaded the files (folder path).
     - `key`: The API Key you set in step 3.
     - `auto_delete`: Set to `True` to automatically delete old files when space is low.

---

<a name="japanese"></a>
## 日本語

このリポジトリは **Cargo Discord Bot** 用のサーバーサイドスクリプトです。PHPが動作するWebサーバーにこれらのファイルを設置することで、Bot用のファイルストレージとして利用できます。

### 動作要件
- **PHP 7.4** 以上
- 設置ディレクトリへの**書き込み権限**（`files` フォルダの作成・書き込みのため）

### 設置手順

1. **ファイルのダウンロード**
   - このリポジトリから `index.php` と `.htaccess` をダウンロードします。

2. **サーバーへのアップロード**
   - Webサーバーの公開ディレクトリ（例: `public_html/cargo/`）に2つのファイルをアップロードします。

3. **APIキーの設定**
   - `index.php` をテキストエディタで開きます。
   - 先頭付近にある `$API_KEY = 'YOUR_SECRET_KEY';` という行を探します。
   - `'YOUR_SECRET_KEY'` の部分を、**推測されにくいランダムな文字列**に変更してください。
     ```php
     $API_KEY = 'random_string_here_12345';
     ```

4. **Botへの登録**
   - Cargo Botが導入されているDiscordサーバーを開きます。
   - 以下のコマンドを実行します（管理者権限が必要です）:
     ```
     /set-server url:https://your-domain.com/cargo key:設定したAPIキー auto_delete:True
     ```
     - `url`: ファイルを設置した場所のURL（フォルダのパス）。
     - `key`: 手順3で設定したAPIキー。
     - `auto_delete`: `True` にすると、容量不足時に古いファイルから自動削除されます。
