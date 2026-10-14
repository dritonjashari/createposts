<?php
// ---------------------------------------------------------------------------
// Per-server settings: the only thing to fill in on a new install.
// `npm run build` (repository root) copies this file to config.local.php the
// first time; config.local.php is git-ignored, so `git pull` never touches it.
//
// On Namecheap/cPanel, create the database and user under
// cPanel > MySQL Databases (add the user to the database with All
// Privileges). Both names get your cPanel username as a prefix, e.g.
// cpuser_createposts. The tables are created automatically on the first
// request; no SQL import is needed.
// ---------------------------------------------------------------------------

const DB_HOST = 'localhost';
const DB_NAME = 'createposts';
const DB_USER = 'root';
const DB_PASS = '';

// Optional: any default in config.php can be overridden here by defining it,
// e.g. a fallback Publer key for accounts that haven't saved their own:
// const PUBLER_API_KEY = '';
