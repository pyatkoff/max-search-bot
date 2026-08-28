<?php
/**
 * Canonical Manager Workspace entrypoint.
 *
 * Do not redirect explicit /index.php requests here. Some production web-server
 * configurations expose DirectoryIndex as /index.php and a PHP redirect back to
 * /manager/ can be canonicalized by the server to /index.php again, creating a
 * loop. The browser URL is normalized client-side with history.replaceState.
 */
require __DIR__.'/workspace-v2.php';
