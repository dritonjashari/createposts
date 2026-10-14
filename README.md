# CreatePosts

Publish posts to WordPress sites and schedule social posts through Publer.

```
createposts/            <- this folder is the website (public_html, a subdomain folder, or a subfolder)
├── backend/            PHP + MySQL JSON API (api/), background workers, lib/
├── frontend/           React + Vite app (src/), built into frontend/dist by `npm run build`
├── index.php           serves the built app for every page
├── .htaccess           routes /backend/* to PHP, everything else to the app
└── scripts/build.mjs   what `npm run build` runs
```

The app works from any URL path: the domain root (`https://example.com/`) or a subfolder (`https://example.com/createposts/`), with no settings to change.

---

## Install (Namecheap / cPanel shared hosting)

Requirements: PHP 8+, MySQL/MariaDB, and Node.js 20.19+ or 22.12+ for the build.
On cPanel, Node.js comes from **Setup Node.js App**: create an app with a recent Node version, then run the `source .../activate` command it shows in your terminal before `npm`.

1. **Create the database.** Go to cPanel > **MySQL Databases** and create a database and a user, then add the user to the database with **All Privileges**.

2. **Get the code** (cPanel > **Terminal**, or SSH), into the folder that should serve the app:
   ```
   cd ~/public_html
   git init
   git remote add origin https://github.com/dritonjashari/createposts.git
   git fetch origin
   git checkout -t origin/main
   ```
   This works even though `public_html` already contains cPanel's own `cgi-bin` and `.well-known` folders. If git refuses because a file such as `index.html` or `.htaccess` "would be overwritten", move that old file away and run `git checkout -t origin/main` again.
   For a subfolder install, use `git clone https://github.com/dritonjashari/createposts.git ~/public_html/createposts` instead.

3. **Build:**
   ```
   npm run build
   ```
   The first run also creates `backend/config.local.php`.

4. **Fill in the database login** in `backend/config.local.php` (`DB_NAME`, `DB_USER`, `DB_PASS`).

5. Open the site and register. The first account becomes the admin, and the database tables are created automatically.

### Updating
```
git pull
npm run build
```
`backend/config.local.php` and `backend/uploads/` are not in git, so a pull never touches them.

### Optional: cron for the background workers
Queued jobs start immediately, and if one gets stuck, the app restarts it while someone has it open. To also process jobs while nobody has the app open, add both jobs in cPanel > **Cron Jobs** with "Once Per Minute":
```
/usr/local/bin/php /home/<cpanel-user>/public_html/backend/publish_worker.php >/dev/null 2>&1
/usr/local/bin/php /home/<cpanel-user>/public_html/backend/schedule_worker.php >/dev/null 2>&1
```

---

## Local development (XAMPP)

1. Clone into `C:\xampp\htdocs\createposts`, then start Apache and MySQL.
2. Run `npm run build` and fill in `backend/config.local.php`, the same as on the server. The built app is at http://localhost/createposts/.
3. For live reloading while you edit the frontend, run `npm run dev` and open http://localhost:5173.
   It talks to the XAMPP backend at the URL in `frontend/.env.development`.
