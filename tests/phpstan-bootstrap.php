<?php
/**
 * Constants for static analysis.
 *
 * @package easyQ
 */

namespace EasyQ;

const VERSION     = '0.1.0';
const PLUGIN_FILE = __DIR__ . '/../easyq.php';
const PLUGIN_DIR  = __DIR__ . '/..';

// Defined by WordPress at runtime.
defined( 'COOKIEPATH' ) || define( 'COOKIEPATH', '/' );
