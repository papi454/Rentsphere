<?php
/**
 * RentSphere - Bootstrap
 * Include this file at the very top of every page:
 *   require_once __DIR__ . '/../includes/bootstrap.php';
 */

require_once __DIR__ . '/../config/config.php';   // sets up secure session, constants, CSRF helpers
require_once __DIR__ . '/../config/database.php'; // Database class
require_once __DIR__ . '/functions.php';          // shared helper functions
