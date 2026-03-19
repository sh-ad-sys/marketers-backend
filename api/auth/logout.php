<?php
/**
 * PlotConnect - Logout API
 */

require_once dirname(__DIR__, 2) . '/php/config.php';

// Destroy session
session_destroy();
jsonResponse(true, 'Logged out successfully');
